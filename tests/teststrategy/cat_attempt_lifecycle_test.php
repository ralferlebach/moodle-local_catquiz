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

namespace local_catquiz\teststrategy;

use advanced_testcase;
use local_catquiz\catquiz;
use local_catquiz\local\attempt\attempt_finalizer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The CAT attempt exists from the first progress access on (issue #101).
 *
 * It used to be written only when the result page was shown. Since issue #95 files progress under
 * the CAT attempt, that meant: no CAT attempt during the test, so no progress in the database -
 * only in the cache of the person taking the test. A teacher opening the result read nothing, and
 * any cache flush lost the progress. The Behat run of 27 September failed on exactly that.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\progress
 */
final class cat_attempt_lifecycle_test extends advanced_testcase {
    /**
     * Starting a test files a running CAT attempt, and the progress reaches the database at once.
     */
    public function test_progress_is_persisted_from_the_start(): void {
        global $DB;

        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $progress = progress::load(93001, 'mod_adaptivequiz', 9, (object) []);
        $progress->save();

        $cat = $DB->get_record('local_catquiz_attempts', ['attemptid' => 93001, 'component' => 'mod_adaptivequiz']);
        $this->assertNotFalse($cat, 'Starting a test must file its CAT attempt.');
        $this->assertEquals(LOCAL_CATQUIZ_ATTEMPT_RUNNING, (int) $cat->status);
        $this->assertNull($cat->scaleid, 'A running attempt carries no scale - statistics must not see it.');
        $this->assertTrue(
            $DB->record_exists('local_catquiz_progress', ['attemptid' => $cat->id]),
            'The progress must be in the database while the test runs, not only in the cache.'
        );
    }

    /**
     * A teacher reads the progress of a test that is still running, after the cache has gone.
     */
    public function test_a_teacher_reads_a_running_test(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $this->setUser($student);
        $progress = progress::load(93002, 'mod_adaptivequiz', 9, (object) []);
        $progress->save();

        $this->setUser($teacher);
        \cache::make('local_catquiz', 'adaptivequizattempt')->purge();

        $read = progress::load(93002, 'mod_adaptivequiz', 9);
        $this->assertEquals($progress->get_id(), $read->get_id());
    }

    /**
     * A reader never creates an attempt.
     */
    public function test_reading_does_not_start_an_attempt(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            progress::load(93003, 'mod_adaptivequiz', 9);
        } catch (\TypeError $e) {
            // What a reader gets for an attempt that never existed is a separate matter; what
            // counts here is that it did not file one.
            unset($e);
        }

        $this->assertFalse($DB->record_exists('local_catquiz_attempts', ['attemptid' => 93003]));
    }

    /**
     * The finalizer leaves a running attempt alone - otherwise the idempotency guard would lock it.
     */
    public function test_the_finalizer_leaves_a_running_attempt_alone(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        progress::load(93004, 'mod_adaptivequiz', 9, (object) [])->save();

        $this->assertFalse(attempt_finalizer::finalize(93004, time(), 'reason'));
        $this->assertNull(
            $DB->get_field('local_catquiz_attempts', 'endtime', ['attemptid' => 93004]),
            'A running attempt must not be given an end time.'
        );
    }

    /**
     * After the result page has saved the attempt, the next access still finds it.
     *
     * save_attempt_to_db() stores the bare module name - 'adaptivequiz' - where the start of the
     * test wrote 'mod_adaptivequiz'. The lookup compared with one spelling only, so the next access
     * missed its own CAT attempt and reported a collision with 'another component'. That debugging
     * message failed 15 Behat scenarios.
     */
    public function test_the_attempt_is_found_after_the_result_page_saved_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $progress = progress::load(93006, 'mod_adaptivequiz', 9, (object) []);
        $progress->save();

        // What the result page does to the row: it writes the bare module name.
        $DB->set_field('local_catquiz_attempts', 'component', 'adaptivequiz', ['attemptid' => 93006]);
        \cache::make('local_catquiz', 'adaptivequizattempt')->purge();

        $again = progress::load(93006, 'mod_adaptivequiz', 9, (object) []);

        $this->assertEquals($progress->get_id(), $again->get_id(), 'The test lost its own progress.');
        $this->assertEquals(1, $DB->count_records('local_catquiz_attempts', ['attemptid' => 93006]));
    }

    /**
     * A running attempt does not appear among the attempts that are listed.
     */
    public function test_a_running_attempt_is_not_listed(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        progress::load(93005, 'mod_adaptivequiz', 9, (object) [])->save();

        // The method get_attempts() returns a recordset, which is an object whether or not it holds rows.
        $rows = catquiz::get_attempts((int) $student->id);
        $listed = iterator_to_array($rows, false);
        $rows->close();

        $this->assertSame([], $listed, 'A running attempt must not be listed.');
    }
}
