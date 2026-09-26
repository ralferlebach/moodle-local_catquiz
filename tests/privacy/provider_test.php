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

namespace local_catquiz\privacy;

use context_course;
use context_module;
use core_privacy\local\request\userlist;
use core_privacy\tests\provider_testcase;

/**
 * Tests of the Privacy API implementation.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Records an attempt of a user in a course.
     *
     * @param int $courseid The course the attempt belongs to.
     * @param int $userid The user who took it.
     */
    private function record_attempt(int $courseid, int $userid): void {
        global $DB;

        static $sequence = 0;
        $sequence++;
        $now = time();

        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $userid,
            'scaleid' => 1,
            'contextid' => 1,
            'courseid' => $courseid,
            'attemptid' => $sequence,
            'component' => 'mod_adaptivequiz',
            'instanceid' => 1,
            'teststrategy' => 4,
            'status' => 1,
            'json' => '{}',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Only the users with an attempt in this course are named, and each of them once.
     *
     * Three defects used to make this wrong at the same time: the prepared course parameter never
     * reached the SQL, get_records_sql() collapsed several attempts of one user into a single row,
     * and the loop read a property the Moodle user object does not have.
     */
    public function test_users_of_a_course_are_named_once_each(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $othercourse = $generator->create_course();

        $one = $generator->create_user();
        $two = $generator->create_user();
        $elsewhere = $generator->create_user();

        // One user with two attempts, one with a single one, one in another course entirely.
        $this->record_attempt((int) $course->id, (int) $one->id);
        $this->record_attempt((int) $course->id, (int) $one->id);
        $this->record_attempt((int) $course->id, (int) $two->id);
        $this->record_attempt((int) $othercourse->id, (int) $elsewhere->id);

        $userlist = new userlist(context_course::instance($course->id), 'local_catquiz');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $one->id, (int) $two->id],
            $userlist->get_userids(),
            'The users of this course are not named correctly.'
        );
    }

    /**
     * A course without attempts names nobody.
     */
    public function test_course_without_attempts_names_nobody(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        $userlist = new userlist(context_course::instance($course->id), 'local_catquiz');
        provider::get_users_in_context($userlist);

        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * A module context is declined instead of being read as a course.
     *
     * The instanceid of a module context is a course module id, not a course id. Passing it on as
     * a course filter is a category error that stays invisible until the two numbers happen to
     * collide - which is what this test arranges on purpose: an attempt filed under the course id
     * that equals the course module id of an unrelated activity. Without the guard the provider
     * names a user who has nothing to do with that activity.
     */
    public function test_module_context_is_not_read_as_a_course(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module('page', ['course' => $course->id]);
        $stranger = $generator->create_user();

        // An attempt in a course whose id equals the course module id of the activity above.
        $this->record_attempt((int) $activity->cmid, (int) $stranger->id);

        $userlist = new userlist(context_module::instance($activity->cmid), 'local_catquiz');
        provider::get_users_in_context($userlist);

        $this->assertEmpty(
            $userlist->get_userids(),
            'A module context was read as a course, naming a user of an unrelated course.'
        );
    }
}
