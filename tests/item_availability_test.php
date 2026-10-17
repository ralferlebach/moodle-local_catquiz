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

namespace local_catquiz;

use advanced_testcase;
use cache;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Which questions the runtime pool of a test contains (issue #82).
 *
 * Two levels hide a question: the question bank (a hidden or draft version, for every use) and the CAT
 * manager (an item deactivated in one scale). The pool holds a question only when both allow it; the
 * manager table keeps listing all of them.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversMethod(catscale::class, 'get_testitems')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catquiz::class, 'return_sql_for_catscalequestions')]
final class item_availability_test extends advanced_testcase {
    /** @var int Running number. */
    private int $seq = 0;

    /** @var int CAT context. */
    private int $contextid;

    /**
     * A CAT context.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Availability context', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => time() - 100, 'endtimestamp' => time() + 10000,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
    }

    /**
     * A scale.
     *
     * @param int $parentid
     * @return int
     */
    private function scale(int $parentid = 0): int {
        global $DB;
        return (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parentid, 'name' => 'Availability scale ' . (++$this->seq), 'contextid' => $this->contextid,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * A question with a version of the given status.
     *
     * @param string $versionstatus ready, hidden or draft
     * @return int question id
     */
    private function question(string $versionstatus): int {
        global $DB;
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category();
        $questionid = (int) $DB->insert_record('question', (object) [
            'name' => 'Availability item ' . (++$this->seq), 'questiontext' => 'body', 'questiontextformat' => FORMAT_HTML,
            'qtype' => 'truefalse', 'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 2, 'modifiedby' => 2,
        ]);
        $entryid = (int) $DB->insert_record('question_bank_entries', (object) [
            'questioncategoryid' => $category->id, 'idnumber' => 'avail' . $this->seq, 'ownerid' => 2,
        ]);
        $DB->insert_record('question_versions', (object) [
            'questionbankentryid' => $entryid, 'version' => 1, 'questionid' => $questionid, 'status' => $versionstatus,
        ]);
        return $questionid;
    }

    /**
     * Assigns a question to a scale.
     *
     * @param int $questionid
     * @param int $scaleid
     * @param int $status LOCAL_CATQUIZ_TESTITEM_STATUS_*
     */
    private function item(int $questionid, int $scaleid, int $status): void {
        global $DB;
        $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => $questionid, 'componentname' => 'question', 'catscaleid' => $scaleid,
            'contextid' => $this->contextid, 'status' => $status,
        ]);
    }

    /**
     * The question ids of the runtime pool of a scale.
     *
     * @param int $scaleid
     * @return int[]
     */
    private function pool(int $scaleid): array {
        cache::make('local_catquiz', 'runtimepool')->purge();
        $ids = array_map(fn($row) => (int) $row->id, (new catscale($scaleid))->get_testitems($this->contextid));
        sort($ids);
        return $ids;
    }

    /**
     * Global x local: only a ready version that is active in its scale reaches the pool; a draft does not either.
     */
    public function test_global_and_local_visibility(): void {
        $scale = $this->scale();
        $matrix = [];
        foreach (['ready', 'hidden'] as $versionstatus) {
            foreach ([LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE, LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE] as $itemstatus) {
                $questionid = $this->question($versionstatus);
                $this->item($questionid, $scale, $itemstatus);
                $matrix[$versionstatus . '/' . $itemstatus] = $questionid;
            }
        }
        $draft = $this->question('draft');
        $this->item($draft, $scale, LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE);

        $this->assertSame([$matrix['ready/' . LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE]], $this->pool($scale));
    }

    /**
     * Deactivated in scale A, the question is still played in scale B.
     */
    public function test_deactivated_in_one_scale_only(): void {
        $root = $this->scale();
        $a = $this->scale($root);
        $b = $this->scale($root);
        $questionid = $this->question('ready');
        $this->item($questionid, $a, LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE);
        $this->item($questionid, $b, LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE);

        $this->assertSame([], $this->pool($a));
        $this->assertSame([$questionid], $this->pool($b));
    }

    /**
     * The manager still lists every question, with both states to show.
     */
    public function test_manager_lists_all(): void {
        global $DB;
        $scale = $this->scale();
        $this->item($this->question('hidden'), $scale, LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE);
        $this->item($this->question('ready'), $scale, LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE);

        [$select, $from, $where, , $params] = catquiz::return_sql_for_catscalequestions([$scale], $this->contextid);
        $this->assertCount(2, $DB->get_records_sql("SELECT $select FROM $from WHERE $where", $params));
    }
}
