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

/**
 * CATquiz tells the host whether a test is ready for an attempt.
 *
 * The 3.0 host judged a CATquiz activity by its own item bank rules and never found it ready -
 * every Behat scenario on the migration branch stopped at 'Item bank is not configured properly'.
 * The host now asks the CAT model, and the answer comes from here.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\catquiz_handler
 */
final class catquiz_ready_for_attempt_test extends advanced_testcase {
    /**
     * Files a CAT scale, optionally below another one.
     *
     * @param int $parentid Id of the parent scale, 0 for a root.
     * @return int
     */
    private function scale(int $parentid = 0): int {
        global $DB;

        return (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parentid, 'name' => 'scale ' . random_string(4), 'contextid' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Files the settings of a CATquiz test for an activity instance.
     *
     * @param int $instanceid Id of the activity instance.
     * @param int $catscaleid The CAT scale of the test.
     */
    private function test_settings(int $instanceid, int $catscaleid): void {
        global $DB;

        $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => $instanceid, 'component' => 'mod_adaptivequiz', 'catscaleid' => $catscaleid,
            'contextid' => 1, 'courseid' => 1, 'name' => 'test', 'json' => '{}', 'status' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Without settings there is nothing to run.
     */
    public function test_no_settings(): void {
        $this->resetAfterTest();

        $this->assertFalse(catquiz_handler::is_ready_for_attempt('mod_adaptivequiz', 41001));
    }

    /**
     * A scale without items is not ready.
     */
    public function test_a_scale_without_items(): void {
        $this->resetAfterTest();
        $this->test_settings(41002, $this->scale());

        $this->assertFalse(catquiz_handler::is_ready_for_attempt('mod_adaptivequiz', 41002));
    }

    /**
     * An item in a scale below the test's scale is enough.
     */
    public function test_an_item_in_a_subscale(): void {
        global $DB;

        $this->resetAfterTest();
        $root = $this->scale();
        $child = $this->scale($root);
        $this->test_settings(41003, $root);
        $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => 1, 'componentname' => 'question', 'catscaleid' => $child,
            'contextid' => 1, 'status' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertTrue(catquiz_handler::is_ready_for_attempt('mod_adaptivequiz', 41003));
    }
}
