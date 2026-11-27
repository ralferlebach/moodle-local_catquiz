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
 * Tests the cancel_expired_tests functionality.
 *
 * @package    local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use basic_testcase;
use local_catquiz\local\attempt\expiry_policy;
use local_catquiz\task\cancel_expired_attempts;

/**
 * The clean-up of attempts out of time, and the reading of the time limits it shares (mod_adaptivequiz #5).
 *
 * @package    local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_catquiz\task\cancel_expired_attempts
 * @covers \local_catquiz\catquiz_handler::time_limits
 * @covers \local_catquiz\local\attempt\expiry_policy
 */
final class cancel_expired_attempts_test extends basic_testcase {
    /**
     * The effective expiry policy (#178 AK01, DoD01): explicit "none", seconds of the policy, the time limit of the test
     * with its buffer and units, a limit switched off, empty or 0, no settings, the default of the site set, 0 and not
     * set, the 24 hour edge, a long run and a pause.
     *
     * @param \stdClass|null $settings settings of the test
     * @param mixed $sitehours maximum_attempt_duration_hours as get_config() returns it
     * @param int|null $seconds expected limit in seconds (null: never)
     * @param string $source expected source
     * @param array $runs run time in seconds => expired?
     * @dataProvider policies
     */
    public function test_the_effective_expiry_policy(
        ?\stdClass $settings,
        $sitehours,
        ?int $seconds,
        string $source,
        array $runs
    ): void {
        $policy = expiry_policy::from_settings($settings, $sitehours);
        $this->assertSame($seconds, $policy->seconds);
        $this->assertSame($source, $policy->source);
        $now = 2000000000;
        foreach ($runs as $run => $expired) {
            $this->assertSame($expired, $policy->expired($now - $run, $now), "run time $run s");
        }
    }

    /**
     * Cases of test_the_effective_expiry_policy().
     *
     * @return array
     */
    public static function policies(): array {
        $limit = fn($value, string $unit, $include = '1', array $more = []) => (object) ([
            'catquiz_includetimelimit' => $include,
            'catquiz_timelimitgroup' => (object) ['catquiz_maxtimeperattempt' => $value, 'catquiz_timeselect_attempt' => $unit],
        ] + $more);
        $day = 86400;
        return [
            'no settings, default not set: 24 h' => [null, false, $day, expiry_policy::SOURCE_SITE,
                [$day => false, $day + 1 => true]],
            'no settings, default empty: 24 h' => [null, '', $day, expiry_policy::SOURCE_SITE, [$day + 1 => true]],
            'no settings, default 1 h' => [null, '1', 3600, expiry_policy::SOURCE_SITE, [3600 => false, 3601 => true]],
            'no settings, default 0: none' => [null, '0', null, expiry_policy::SOURCE_SITE_NONE, [30 * $day => false]],
            'no settings, default negative: none' => [null, '-3', null, expiry_policy::SOURCE_SITE_NONE, [30 * $day => false]],
            'time limit 30 min: buffer 1.5' => [$limit(30, 'min'), '24', 2700, expiry_policy::SOURCE_TEST_LIMIT,
                [2700 => false, 2701 => true]],
            'time limit 90 s' => [$limit(90, 'sec'), '24', 135, expiry_policy::SOURCE_TEST_LIMIT, [136 => true]],
            'time limit 2 h' => [$limit(2, 'h'), '24', 10800, expiry_policy::SOURCE_TEST_LIMIT, [10800 => false, 10801 => true]],
            'time limit 24 h: longer than the default' => [$limit(24, 'h'), '1', 129600, expiry_policy::SOURCE_TEST_LIMIT,
                [$day + 1 => false, 129601 => true]],
            'time limit with a unit the form does not offer: the default' => [$limit(1, 'd'), '1', 3600,
                expiry_policy::SOURCE_SITE, [3601 => true]],
            'time limit 0: the default' => [$limit(0, 'min'), '24', $day, expiry_policy::SOURCE_SITE, [$day + 1 => true]],
            'time limit empty: the default' => [$limit('', 'min'), '24', $day, expiry_policy::SOURCE_SITE, [$day + 1 => true]],
            'time limit switched off: the default (run 103)' => [$limit(30, 'min', '0'), '24', $day, expiry_policy::SOURCE_SITE,
                [2701 => false, $day + 1 => true]],
            'policy none: never, also a long run' => [$limit(30, 'min', '0', ['catquiz_expirypolicy' => 'none']), '24', null,
                expiry_policy::SOURCE_TEST_NONE, [10 * $day => false]],
            'policy none over a time limit' => [$limit(30, 'min', '1', ['catquiz_expirypolicy' => 'none']), '24', null,
                expiry_policy::SOURCE_TEST_NONE, [$day + 1 => false]],
            'policy seconds: no buffer' => [(object) ['catquiz_expirypolicy' => 172800], '24', 172800,
                expiry_policy::SOURCE_TEST_SECONDS, [172800 => false, 172801 => true]],
            'policy seconds as text' => [(object) ['catquiz_expirypolicy' => '7200'], '24', 7200,
                expiry_policy::SOURCE_TEST_SECONDS, [7201 => true]],
            'policy 0 is no policy: the default' => [(object) ['catquiz_expirypolicy' => 0], '24', $day, expiry_policy::SOURCE_SITE,
                [$day + 1 => true]],
            'policy invalid: ignored, the time limit' => [
                $limit(30, 'min', '1', ['catquiz_expirypolicy' => 'unlimited']), '24', 2700,
                expiry_policy::SOURCE_TEST_LIMIT, [2701 => true]],
            'policy null: the default' => [(object) ['catquiz_expirypolicy' => null], '24', $day, expiry_policy::SOURCE_SITE, []],
        ];
    }

    /**
     * The time counts from the start: a pause counts, and a recent answer does not extend it (#178 DoD01).
     */
    public function test_the_run_time_counts_from_the_start(): void {
        $policy = expiry_policy::from_settings(null, '24');
        $task = new cancel_expired_attempts();
        $now = time();
        $reflection = new \ReflectionProperty($task, 'currenttime');
        $reflection->setValue($task, $now);
        $policies = new \ReflectionProperty($task, 'policies');
        $policies->setValue($task, [7 => $policy]);
        // Started 25 hours ago, answered a minute ago: expired.
        $this->assertTrue($task->exceeds_maxtime((object) ['instance' => 7, 'timecreated' => $now - 25 * 3600,
            'timemodified' => $now - 60]));
        // Started 2 hours ago, paused since: not expired.
        $this->assertFalse($task->exceeds_maxtime((object) ['instance' => 7, 'timecreated' => $now - 7200,
            'timemodified' => $now - 7100]));
    }

    /**
     * The policy describes itself for the log and the lab without data of a person.
     */
    public function test_the_policy_describes_itself(): void {
        $settings = (object) ['catquiz_expirypolicy' => 'forever'];
        $this->assertSame(
            ['seconds' => 86400, 'source' => expiry_policy::SOURCE_SITE, 'note' => 'invalid catquiz_expirypolicy ignored'],
            expiry_policy::from_settings($settings, false)->describe()
        );
        $this->assertSame(24, expiry_policy::site_hours(null));
        $this->assertSame(48, expiry_policy::site_hours(' 48 '));
    }

    /**
     * One reading of the limits: a unit, an empty value as none, and a limit switched off as none.
     */
    public function test_time_limits_are_read_the_same_everywhere(): void {
        $settings = fn(array $group, $include = null) => (object) (['catquiz_timelimitgroup' => (object) $group]
            + ($include === null ? [] : ['catquiz_includetimelimit' => $include]));
        $group = ['catquiz_maxtimeperattempt' => 30, 'catquiz_timeselect_attempt' => 'min',
            'catquiz_maxtimeperitem' => 45, 'catquiz_timeselect_item' => 'sec'];

        $this->assertSame(['attempt' => 1800, 'item' => 45], \local_catquiz\catquiz_handler::time_limits($settings($group)));
        $this->assertSame(['attempt' => 1800, 'item' => 45], \local_catquiz\catquiz_handler::time_limits($settings($group, '1')));
        $this->assertSame(['attempt' => null, 'item' => null], \local_catquiz\catquiz_handler::time_limits($settings($group, '0')));
        $this->assertSame(
            ['attempt' => null, 'item' => 45],
            \local_catquiz\catquiz_handler::time_limits($settings(['catquiz_maxtimeperattempt' => ''] + $group)),
            'An empty limit is none - it used to become an hour.'
        );
        $this->assertSame(['attempt' => null, 'item' => null], \local_catquiz\catquiz_handler::time_limits(null));
    }
}
