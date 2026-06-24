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
 * Tests for the remote-related methods of the catquiz class.
 *
 * These methods are scheduled to move to catquizcentralhub_host and
 * catquizcentralhub_client during the connectivity_hub split. The tests here
 * lock down their behaviour so the migration can be verified.
 *
 * @package    local_catquiz
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use local_catquiz\catquiz;
use stdClass;

/**
 * Tests for the remote catquiz methods that will migrate to catquizcentralhub subplugins.
 *
 * @package    local_catquiz
 * @covers     \local_catquiz\catquiz::count_unprocessed_remote_responses
 * @covers     \local_catquiz\catquiz::mark_remote_responses_processed
 * @covers     \local_catquiz\catquiz::get_remote_responses
 * @covers     \local_catquiz\catquiz::get_last_synced_context_id
 * @covers     \local_catquiz\catquiz::save_sync_event
 * @covers     \local_catquiz\catquiz::get_intermediate_context_ids
 */
final class catquiz_remote_test extends advanced_testcase {
    /** @var catquiz */
    private catquiz $repo;

    /** @var int admin user ID */
    private int $adminid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $this->adminid = $USER->id;
        $this->repo = new catquiz();
    }

    public function test_get_last_synced_context_id_returns_null_when_no_events(): void {
        $result = $this->repo->get_last_synced_context_id(999);
        $this->assertNull($result);
    }

    public function test_save_and_get_last_synced_context_id(): void {
        $this->repo->save_sync_event($this->make_sync_event(42, 1));
        $result = $this->repo->get_last_synced_context_id(1);
        $this->assertSame(42, $result);
    }

    public function test_get_last_synced_context_id_returns_most_recently_inserted(): void {
        $this->repo->save_sync_event($this->make_sync_event(10, 1));
        $this->repo->save_sync_event($this->make_sync_event(20, 1));
        $this->repo->save_sync_event($this->make_sync_event(15, 1));
        // ORDER BY id DESC LIMIT 1 → last inserted is contextid 15.
        $result = $this->repo->get_last_synced_context_id(1);
        $this->assertSame(15, $result);
    }

    public function test_get_last_synced_context_id_is_scale_specific(): void {
        $this->repo->save_sync_event($this->make_sync_event(100, 1));
        $this->repo->save_sync_event($this->make_sync_event(200, 2));
        $this->assertSame(100, $this->repo->get_last_synced_context_id(1));
        $this->assertSame(200, $this->repo->get_last_synced_context_id(2));
    }

    public function test_get_intermediate_context_ids_returns_matching_range(): void {
        $this->repo->save_sync_event($this->make_sync_event(10, 1));
        $this->repo->save_sync_event($this->make_sync_event(20, 1));
        $this->repo->save_sync_event($this->make_sync_event(30, 1));
        // DB may return strings; cast for type-safe comparison.
        $result = array_map('intval', catquiz::get_intermediate_context_ids(1, 10, 25));
        $this->assertContains(10, $result);
        $this->assertContains(20, $result);
        $this->assertNotContains(30, $result);
    }

    public function test_get_intermediate_context_ids_is_inclusive(): void {
        $this->repo->save_sync_event($this->make_sync_event(10, 1));
        $this->repo->save_sync_event($this->make_sync_event(20, 1));
        $result = array_map('intval', catquiz::get_intermediate_context_ids(1, 10, 20));
        $this->assertContains(10, $result);
        $this->assertContains(20, $result);
    }

    public function test_get_intermediate_context_ids_excludes_other_scales(): void {
        $this->repo->save_sync_event($this->make_sync_event(10, 1));
        $this->repo->save_sync_event($this->make_sync_event(15, 2)); // Different scale.
        $result = array_map('intval', catquiz::get_intermediate_context_ids(1, 5, 20));
        $this->assertContains(10, $result);
        $this->assertNotContains(15, $result);
    }

    public function test_get_intermediate_context_ids_returns_empty_for_no_match(): void {
        $this->repo->save_sync_event($this->make_sync_event(100, 1));
        $result = catquiz::get_intermediate_context_ids(1, 1, 50);
        $this->assertEmpty($result);
    }

    public function test_count_unprocessed_remote_responses_returns_zero_when_empty(): void {
        $count = $this->repo->count_unprocessed_remote_responses([1], 1);
        $this->assertSame(0, $count);
    }

    public function test_count_unprocessed_remote_responses_counts_correctly(): void {
        ['scaleid' => $scaleid, 'contextid' => $contextid] = $this->setup_remote_data();
        $count = $this->repo->count_unprocessed_remote_responses([$scaleid], $contextid);
        $this->assertSame(1, $count);
    }

    public function test_count_unprocessed_excludes_processed_responses(): void {
        global $DB;
        ['scaleid' => $scaleid, 'contextid' => $contextid, 'hash' => $hash] = $this->setup_remote_data();
        // Manually mark as processed.
        $DB->execute(
            "UPDATE {local_catquiz_rresponses} SET timeprocessed = :now WHERE questionhash = :hash",
            ['now' => time(), 'hash' => $hash]
        );
        $count = $this->repo->count_unprocessed_remote_responses([$scaleid], $contextid);
        $this->assertSame(0, $count);
    }

    public function test_mark_remote_responses_processed_sets_timeprocessed(): void {
        global $DB;
        ['scaleid' => $scaleid, 'contextid' => $contextid, 'hash' => $hash] = $this->setup_remote_data();
        $this->repo->mark_remote_responses_processed([$scaleid], $contextid);
        $record = $DB->get_record('local_catquiz_rresponses', ['questionhash' => $hash]);
        $this->assertNotNull($record->timeprocessed);
    }

    public function test_mark_remote_responses_processed_sets_processinginfo(): void {
        global $DB;
        ['scaleid' => $scaleid, 'contextid' => $contextid, 'hash' => $hash] = $this->setup_remote_data();
        $this->repo->mark_remote_responses_processed([$scaleid], $contextid);
        $record = $DB->get_record('local_catquiz_rresponses', ['questionhash' => $hash]);
        $info = json_decode($record->processinginfo, true);
        $this->assertSame('success', $info['status']);
    }

    public function test_count_unprocessed_is_zero_after_marking_processed(): void {
        ['scaleid' => $scaleid, 'contextid' => $contextid] = $this->setup_remote_data();
        $this->repo->mark_remote_responses_processed([$scaleid], $contextid);
        $count = $this->repo->count_unprocessed_remote_responses([$scaleid], $contextid);
        $this->assertSame(0, $count);
    }

    public function test_mark_remote_responses_processed_does_not_affect_other_scales(): void {
        global $DB;
        ['scaleid' => $scaleid1, 'contextid' => $contextid1] = $this->setup_remote_data('hash1scale1');
        ['scaleid' => $scaleid2, 'contextid' => $contextid2] = $this->setup_remote_data('hash1scale2');

        $this->repo->mark_remote_responses_processed([$scaleid1], $contextid1);

        // Scale 2 responses should still be unprocessed.
        $count = $this->repo->count_unprocessed_remote_responses([$scaleid2], $contextid2);
        $this->assertSame(1, $count);
    }

    public function test_get_remote_responses_returns_correct_records(): void {
        ['scaleid' => $scaleid, 'contextid' => $contextid, 'hash' => $hash] = $this->setup_remote_data();
        $responses = $this->repo->get_remote_responses($scaleid, $contextid);
        $this->assertCount(1, $responses);
        $response = reset($responses);
        $this->assertSame($hash, $response->questionhash);
    }

    /**
     * Builds a sync event record for save_sync_event().
     *
     * @param int $contextid
     * @param int $catscaleid
     * @return stdClass
     */
    private function make_sync_event(int $contextid, int $catscaleid): stdClass {
        return (object) [
            'contextid' => $contextid,
            'catscaleid' => $catscaleid,
            'num_fetched_params' => 5,
            'userid' => $this->adminid,
        ];
    }

    /**
     * Creates a minimal set of DB records to support the remote response methods.
     *
     * @param string $hashsuffix Appended to 'testhash' to keep records distinct between calls.
     * @return array{scaleid: int, contextid: int, hash: string}
     */
    private function setup_remote_data(string $hashsuffix = 'default'): array {
        global $DB;

        // Create a catcontext (no FK dependencies except usermodified → user).
        $contextid = $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Test Context ' . $hashsuffix,
            'usermodified' => $this->adminid,
            'timecreated' => time(),
            'timemodified' => time(),
            'timecalculated' => 0,
        ]);

        // Create a catscale referencing the context.
        $scaleid = $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'Test Scale ' . $hashsuffix,
            'label' => 'testscale_' . $hashsuffix,
            'parentid' => 0,
            'contextid' => $contextid,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Create a question so the FK on qhashmap is valid.
        $qgenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $qcat = $qgenerator->create_question_category();
        $question = $qgenerator->create_question('shortanswer', null, ['category' => $qcat->id]);

        $hash = 'testhash_' . $hashsuffix . '_' . $question->id;

        // Map question → hash.
        $DB->insert_record('local_catquiz_qhashmap', (object) [
            'questionid' => $question->id,
            'questionhash' => $hash,
            'hashdata' => '{}',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Assign question to catscale in context.
        $DB->insert_record('local_catquiz_items', (object) [
            'componentname' => 'question',
            'componentid' => $question->id,
            'catscaleid' => $scaleid,
            'contextid' => $contextid,
            'status' => 0,
        ]);

        // Create an unprocessed remote response.
        $DB->insert_record('local_catquiz_rresponses', (object) [
            'questionhash' => $hash,
            'attempthash' => crc32('testhost' . $hashsuffix),
            'response' => '0.5',
            'sourceurl' => 'https://node.example.com',
            'timecreated' => time(),
        ]);

        return ['scaleid' => $scaleid, 'contextid' => $contextid, 'hash' => $hash];
    }
}
