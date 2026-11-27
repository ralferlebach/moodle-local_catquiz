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
use local_catquiz\local\attempt\expiry_policy;
use local_catquiz\local\status;
use local_catquiz\task\cancel_expired_attempts;

/**
 * The expiry policy in the database and the task that applies it (#178): the policy of the lab, both spellings of the
 * component, the default of the site not set, the lock of a request, an attempt completed meanwhile, the log.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_catquiz\task\cancel_expired_attempts
 * @covers \local_catquiz\local\attempt\expiry_policy
 */
final class cancel_expired_attempts_policy_test extends advanced_testcase {
    /** @var \stdClass */
    private \stdClass $quiz;

    /** @var \stdClass */
    private \stdClass $user;

    /**
     * An activity with the CAT model and a student.
     *
     * @param array|null $settings settings of the test, null: no row
     * @param string $component spelling of the component of the settings row
     */
    private function activity(?array $settings, string $component = 'mod_adaptivequiz'): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_HTML],
        ]);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $this->quiz->id]);
        $this->user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        if ($settings !== null) {
            $this->settings_row($settings, $component);
        }
    }

    /**
     * A settings row of the test of the activity.
     *
     * @param array $settings
     * @param string $component
     * @return int
     */
    private function settings_row(array $settings, string $component): int {
        global $DB;
        return (int) $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => $this->quiz->id, 'component' => $component, 'json' => json_encode((object) $settings),
            'contextid' => 1, 'status' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * An open attempt started some time ago, with its CAT attempt.
     *
     * @param int $age seconds since the start
     * @return int adaptivequiz_attempt.id
     */
    private function open_attempt(int $age): int {
        global $DB;
        static $unique = 9100;
        $created = time() - $age;
        $id = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->quiz->id, 'userid' => $this->user->id, 'uniqueid' => $unique++,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 1500,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0, 'timecreated' => $created, 'timemodified' => time() - 30,
        ]);
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $this->user->id, 'attemptid' => $id, 'component' => 'mod_adaptivequiz', 'contextid' => 1,
            'status' => 2, 'timecreated' => $created, 'timemodified' => $created,
        ]);
        return $id;
    }

    /**
     * Runs the task and returns its log.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new cancel_expired_attempts())->execute();
        return (string) ob_get_clean();
    }

    /**
     * State and engine status of an attempt.
     *
     * @param int $id
     * @return array
     */
    private function state(int $id): array {
        global $DB;
        return [
            $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $id]),
            (int) $DB->get_field('local_catquiz_attempts', 'status', ['attemptid' => $id]),
        ];
    }

    /**
     * Run 103 (CLARA, 16./17.11.2026): a lab test with the time limit switched off ran into the default of the site and
     * was closed after a day. With the policy "none" set by the lab it stays open; a regular test next to it keeps the
     * default. The lab reads the policy back. Removing the policy restores the default.
     */
    public function test_the_policy_of_the_lab_keeps_a_long_run_open(): void {
        $this->resetAfterTest();
        set_config('maximum_attempt_duration_hours', 24, 'local_catquiz');
        $this->activity(['catquiz_includetimelimit' => '0']);
        $this->assertSame(1, expiry_policy::set_for_activity((int) $this->quiz->id, expiry_policy::NONE));
        $this->assertSame(
            ['seconds' => null, 'source' => expiry_policy::SOURCE_TEST_NONE, 'note' => ''],
            expiry_policy::for_activity((int) $this->quiz->id)->describe()
        );
        $long = $this->open_attempt(30 * HOURSECS);
        $lab = $this->quiz;
        $this->activity(['catquiz_includetimelimit' => '0']);
        $regular = $this->open_attempt(30 * HOURSECS);

        $log = $this->run_task();
        $this->assertSame('inprogress', $this->state($long)[0], 'The long run of the lab stays open.');
        $this->assertSame(['complete', status::to_int(status::CLOSED_BY_TIMELIMIT)], $this->state($regular));
        $this->assertMatchesRegularExpression(
            '/attempt ' . $regular . ' \(activity ' . $this->quiz->id . '\): closed - time over; limit 86400 s \(site-default/',
            $log
        );
        $this->assertStringContainsString('unlimited 1', $log);

        // The lab removes its policy: the default applies again.
        expiry_policy::set_for_activity((int) $lab->id, null);
        $this->run_task();
        $this->assertSame('complete', $this->state($long)[0]);
    }

    /**
     * The settings of both spellings of the component are read; the newest row wins; the lab writes all rows.
     */
    public function test_both_spellings_of_the_component(): void {
        $this->resetAfterTest();
        $this->activity(['catquiz_expirypolicy' => 600], 'adaptivequiz');
        $this->assertSame(600, expiry_policy::for_activity((int) $this->quiz->id)->seconds);
        $this->settings_row(['catquiz_expirypolicy' => 1200], 'mod_adaptivequiz');
        $this->assertSame(1200, expiry_policy::for_activity((int) $this->quiz->id)->seconds, 'The newest row wins.');
        $this->assertSame(2, expiry_policy::set_for_activity((int) $this->quiz->id, 3600));
        $this->assertSame(3600, expiry_policy::for_activity((int) $this->quiz->id)->seconds);
        $this->expectException(\coding_exception::class);
        expiry_policy::set_for_activity((int) $this->quiz->id, 0);
    }

    /**
     * The default of the site not set is 24 hours. RED before: intval(false) made it 0, no limit at all.
     */
    public function test_the_default_of_the_site_not_set_is_a_day(): void {
        $this->resetAfterTest();
        unset_config('maximum_attempt_duration_hours', 'local_catquiz');
        $this->activity(null);
        $young = $this->open_attempt(23 * HOURSECS);
        $old = $this->open_attempt(25 * HOURSECS);
        $this->run_task();
        $this->assertSame('inprogress', $this->state($young)[0]);
        $this->assertSame('complete', $this->state($old)[0]);
    }

    /**
     * An attempt in a request - the lock of its item administration is held, a last answer is processed - is left for
     * the next run; then it is closed. RED before: the task closed it under the running request.
     */
    public function test_an_attempt_in_a_request_is_left_for_the_next_run(): void {
        global $CFG;
        $this->resetAfterTest();
        // Locks of the files: a second lock of the same resource in this process is refused, as for another request.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $this->activity(['catquiz_expirypolicy' => 3600]);
        $attempt = $this->open_attempt(2 * HOURSECS);
        $lock = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $this->quiz->id . '_' . $this->user->id, 0);
        $this->assertNotFalse($lock);
        try {
            $log = $this->run_task();
        } finally {
            $lock->release();
        }
        $this->assertSame('inprogress', $this->state($attempt)[0]);
        $this->assertMatchesRegularExpression('/attempt ' . $attempt . ' .*: locked - /', $log);
        $this->assertStringContainsString('locked 1', $log);

        $this->run_task();
        $this->assertSame(['complete', status::to_int(status::CLOSED_BY_TIMELIMIT)], $this->state($attempt));
    }

    /**
     * An attempt removed after the task read it - a reset, the deletion of the attempt or of the activity - is not
     * touched: no error, nothing written for it (host #27, lab #178).
     */
    public function test_an_attempt_removed_meanwhile_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(['catquiz_expirypolicy' => 3600]);
        $attempt = $this->open_attempt(2 * HOURSECS);
        $read = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt]);
        $DB->delete_records('adaptivequiz_attempt', ['id' => $attempt]);

        ob_start();
        $task = new cancel_expired_attempts();
        (new \ReflectionProperty($task, 'currenttime'))->setValue($task, time());
        $decision = $task->decide($read, 'closed by the time limit');
        $log = (string) ob_get_clean();
        $this->assertSame('ended', $decision);
        $this->assertMatchesRegularExpression('/attempt ' . $attempt . ' .*: ended - completed or removed/', $log);
        $this->assertSame(2, (int) $DB->get_field('local_catquiz_attempts', 'status', ['attemptid' => $attempt]));
    }

    /**
     * An attempt completed by its own request after the task read it keeps its end: no second completion, no status of
     * the time limit. RED before: the engine status was overwritten with "closed by the time limit".
     */
    public function test_an_attempt_completed_meanwhile_keeps_its_end(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(['catquiz_expirypolicy' => 3600]);
        $attempt = $this->open_attempt(2 * HOURSECS);
        $read = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt]);
        // The request of the attempt ends it regularly, before the task gets to it.
        $DB->update_record('adaptivequiz_attempt', (object) ['id' => $attempt, 'attemptstate' => 'complete',
            'attemptstopcriteria' => 'regular', 'timefinished' => time()]);
        $precision = status::to_int(status::STOP_TARGET_PRECISION);
        $DB->set_field('local_catquiz_attempts', 'status', $precision, ['attemptid' => $attempt]);

        ob_start();
        $task = new cancel_expired_attempts();
        (new \ReflectionProperty($task, 'currenttime'))->setValue($task, time());
        $decision = $task->decide($read, 'closed by the time limit');
        $log = (string) ob_get_clean();

        $this->assertSame('ended', $decision);
        $this->assertSame(['complete', status::to_int(status::STOP_TARGET_PRECISION)], $this->state($attempt));
        $this->assertSame('regular', $DB->get_field('adaptivequiz_attempt', 'attemptstopcriteria', ['id' => $attempt]));
        $this->assertMatchesRegularExpression('/attempt ' . $attempt . ' .*: ended - /', $log);

        // The same between the second reading and the completion (a completion of the host that takes no lock): the
        // completion says it did nothing, and the status stays.
        $complete = new \ReflectionMethod($task, 'complete');
        $this->assertFalse($complete->invoke($task, $read, 'closed by the time limit'));
        $this->assertSame(['complete', status::to_int(status::STOP_TARGET_PRECISION)], $this->state($attempt));
        $this->assertStringNotContainsString('user', $log, 'The log names no user.');
    }
}
