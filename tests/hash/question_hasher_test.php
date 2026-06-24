<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the question_hasher class.
 *
 * @package    local_catquiz
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\hash;

use advanced_testcase;
use moodle_exception;

/**
 * Tests for question_hasher.
 *
 * @package    local_catquiz
 * @covers     \local_catquiz\hash\question_hasher
 */
final class question_hasher_test extends advanced_testcase {
    /** @var object question created for tests */
    private object $question;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $generator->create_question_category();
        $this->question = $generator->create_question('shortanswer', null, ['category' => $cat->id]);
    }

    public function test_generate_hash_returns_sha256_hex_string(): void {
        $hash = question_hasher::generate_hash($this->question->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_generate_hash_is_deterministic(): void {
        $hash1 = question_hasher::generate_hash($this->question->id);
        $hash2 = question_hasher::generate_hash($this->question->id);
        $this->assertSame($hash1, $hash2);
    }

    public function test_generate_hash_stores_record_in_qhashmap(): void {
        global $DB;
        $hash = question_hasher::generate_hash($this->question->id);
        $record = $DB->get_record('local_catquiz_qhashmap', ['questionid' => $this->question->id]);
        $this->assertNotFalse($record);
        $this->assertSame($hash, $record->questionhash);
    }

    public function test_generate_hash_updates_existing_record_not_duplicates(): void {
        global $DB;
        question_hasher::generate_hash($this->question->id);
        question_hasher::generate_hash($this->question->id);
        $count = $DB->count_records('local_catquiz_qhashmap', ['questionid' => $this->question->id]);
        $this->assertSame(1, $count);
    }

    public function test_generate_hash_different_questions_produce_different_hashes(): void {
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $generator->create_question_category();
        $q2 = $generator->create_question('shortanswer', null, [
            'category' => $cat->id,
            'name' => 'Different question name',
            'questiontext' => ['text' => 'Completely different question text', 'format' => FORMAT_HTML],
        ]);
        $hash1 = question_hasher::generate_hash($this->question->id);
        $hash2 = question_hasher::generate_hash($q2->id);
        $this->assertNotSame($hash1, $hash2);
    }

    public function test_get_questionid_from_hash_returns_correct_id(): void {
        $hash = question_hasher::generate_hash($this->question->id);
        $result = question_hasher::get_questionid_from_hash($hash);
        // DB drivers may return numeric strings; assertEquals covers int/string equality.
        $this->assertEquals($this->question->id, $result);
    }

    public function test_get_questionid_from_hash_returns_null_for_unknown_hash(): void {
        $result = question_hasher::get_questionid_from_hash('0000000000000000000000000000000000000000000000000000000000000000');
        $this->assertNull($result);
    }

    public function test_verify_hash_returns_true_for_consistent_question(): void {
        question_hasher::generate_hash($this->question->id);
        $this->assertTrue(question_hasher::verify_hash($this->question->id));
    }

    public function test_verify_hash_returns_true_when_no_hash_stored(): void {
        $this->assertTrue(question_hasher::verify_hash($this->question->id));
    }

    public function test_generate_hash_throws_moodle_exception_for_nonexistent_question(): void {
        $this->expectException(moodle_exception::class);
        question_hasher::generate_hash(PHP_INT_MAX);
    }

    public function test_get_hash_data_returns_json_with_required_fields(): void {
        $data = question_hasher::get_hash_data($this->question->id);
        $decoded = json_decode($data, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('questiontext', $decoded);
        $this->assertArrayHasKey('answers', $decoded);
        $this->assertArrayHasKey('defaultmark', $decoded);
    }
}
