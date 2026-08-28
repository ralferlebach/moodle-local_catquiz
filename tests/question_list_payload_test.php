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
 * Issue #20: list queries carry no question text; the preview is loaded on demand.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use context_system;
use local_catquiz\external\get_question_preview;

/**
 * Verifies that the question lists no longer transport question texts.
 *
 * The regression this guards against: every row of every page used to select,
 * format and embed the complete question text - including base64 images - into a
 * hidden modal. A single page of results could therefore carry megabytes of markup
 * that nobody looked at.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\catquiz::return_sql_for_catscalequestions
 * @covers     \local_catquiz\catquiz::return_sql_for_addcatscalequestions
 * @covers     \local_catquiz\catquiz::return_sql_for_addquestions
 * @covers     \local_catquiz\external\get_question_preview
 */
final class question_list_payload_test extends advanced_testcase {
    /**
     * None of the list queries may mention the question text.
     *
     * @return void
     */
    public function test_list_queries_do_not_select_the_question_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // The query builder resolves parent scales, so it needs a real scale row.
        $now = time();
        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0,
            'name' => 'Issue 20 scale',
            'contextid' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $queries = [
            'return_sql_for_catscalequestions' => catquiz::return_sql_for_catscalequestions([$scaleid], 1, []),
            'return_sql_for_addcatscalequestions' => catquiz::return_sql_for_addcatscalequestions($scaleid, 1),
            'return_sql_for_addquestions' => catquiz::return_sql_for_addquestions([]),
        ];

        foreach ($queries as $name => $parts) {
            [$select, $from] = $parts;
            $this->assertStringNotContainsStringIgnoringCase(
                'questiontext',
                $select . ' ' . $from,
                "$name must not select the question text (issue #20)."
            );
        }
    }

    /**
     * The rendered list rows carry no formatted question text either.
     *
     * Removing it from the SELECT is only half the fix: as long as the column
     * renderer pulls the text and embeds a hidden modal, the payload is back in the
     * DOM.
     *
     * @return void
     */
    public function test_rendered_row_contains_no_question_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $questiontext = 'SECRETQUESTIONBODY needle that must not reach the list';
        $question = (object) [
            'name' => 'Visible question name',
            'questiontext' => $questiontext,
            'questiontextformat' => FORMAT_HTML,
            'qtype' => 'truefalse',
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => 2,
            'modifiedby' => 2,
        ];
        $question->id = $DB->insert_record('question', $question);

        $table = new \local_catquiz\table\catscalequestions_table('issue20');
        $values = (object) [
            'id' => $question->id,
            'questionname' => $question->name,
        ];

        foreach (['col_name', 'col_questiontext'] as $method) {
            $rendered = $table->{$method}($values);
            $this->assertStringNotContainsString(
                'SECRETQUESTIONBODY',
                $rendered,
                "$method must not embed the question text into the row."
            );
            $this->assertStringContainsString(
                (string) $question->id,
                $rendered,
                "$method must reference the question so the preview can be loaded."
            );
        }
    }

    /**
     * The preview endpoint returns the text for one question.
     *
     * @return void
     */
    public function test_preview_endpoint_returns_the_question_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $question = (object) [
            'name' => 'Preview me',
            'questiontext' => 'SECRETQUESTIONBODY only visible on demand',
            'questiontextformat' => FORMAT_HTML,
            'qtype' => 'truefalse',
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => 2,
            'modifiedby' => 2,
        ];
        $question->id = $DB->insert_record('question', $question);

        $result = get_question_preview::execute($question->id);

        $this->assertEquals($question->id, $result['questionid']);
        $this->assertStringContainsString('SECRETQUESTIONBODY', $result['questiontext']);
        $this->assertStringContainsString('Preview me', $result['name']);
    }

    /**
     * A user without the CAT manager capability gets nothing.
     *
     * Lazy loading moves question content behind a web service; without this check
     * the endpoint would be a way to read the question bank that the pages
     * themselves do not grant.
     *
     * @return void
     */
    public function test_preview_endpoint_requires_the_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $question = (object) [
            'name' => 'Preview me',
            'questiontext' => 'SECRETQUESTIONBODY',
            'questiontextformat' => FORMAT_HTML,
            'qtype' => 'truefalse',
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => 2,
            'modifiedby' => 2,
        ];
        $question->id = $DB->insert_record('question', $question);

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        // Proves the capability is the actual gate, not an accident of setup.
        $this->assertFalse(has_capability('local/catquiz:manage_catscales', context_system::instance()));

        $this->expectException(\required_capability_exception::class);
        get_question_preview::execute($question->id);
    }
}
