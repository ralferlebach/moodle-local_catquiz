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

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\teststrategy\progress;

/**
 * Mean and spread of the past abilities come from one population, read once per run (issue #167).
 *
 * calculate_mean_from_past_attempts() and calculate_sd_from_past_attempts() each read the person parameters of the
 * context and scale. Now the finite abilities are read once per run and both figures are taken from them; the
 * figures themselves, the threshold of 50 and the defaults are unchanged.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\updatepersonability::class)]
final class updatepersonability_population_test extends advanced_testcase {
    /**
     * A task whose past person parameters are given, counting how often they are read.
     *
     * @param array $abilities
     * @return updatepersonability
     */
    private static function task(array $abilities): updatepersonability {
        $task = new class ($abilities) extends updatepersonability {
            /** @var array Past abilities. */
            private array $past;
            /** @var int Reads of the past person parameters. */
            public int $reads = 0;

            /**
             * Constructor.
             *
             * @param array $past
             */
            public function __construct(array $past) {
                $this->past = $past;
            }

            /**
             * The past person parameters, counted.
             *
             * @return array
             */
            protected function get_existing_personparams(): array {
                $this->reads++;
                return array_map(fn($a) => (object) ['ability' => $a], $this->past);
            }
        };
        $task->set_context(['catscaleid' => 7]);
        return $task;
    }

    /**
     * Calls a private method.
     *
     * @param updatepersonability $task
     * @param string $method
     * @return mixed
     */
    private static function call(updatepersonability $task, string $method) {
        $m = new \ReflectionMethod(updatepersonability::class, $method);
        $m->setAccessible(true);
        return $m->invoke($task);
    }

    /**
     * One read for mean and spread; the figures as computed by hand, at and around the threshold of 50
     * (AK1, AK2, AK3).
     */
    public function test_one_read_same_figures(): void {
        $this->resetAfterTest();
        foreach ([0, 1, 49, 50, 51, 120] as $n) {
            $abilities = [];
            for ($i = 0; $i < $n; $i++) {
                $abilities[] = sin($i) * 1.7 + 0.25;
            }
            // Entries that are no number are left out before counting.
            $task = self::task(array_merge($abilities, [NAN, INF, -INF]));
            $mean = self::call($task, 'calculate_mean_from_past_attempts');
            $sd = self::call($task, 'calculate_sd_from_past_attempts');
            $this->assertSame(1, $task->reads, "N $n: one read");

            if ($n < 50) {
                $this->assertNull($mean, "N $n");
                $this->assertSame(1.0, $sd, "N $n");
                continue;
            }
            $expectedmean = array_sum($abilities) / $n;
            $sum = 0.0;
            foreach ($abilities as $a) {
                $sum += ($a - $expectedmean) ** 2;
            }
            $this->assertSame($expectedmean, $mean, "N $n: mean");
            $this->assertSame(sqrt($sum / $n), $sd, "N $n: spread");
        }
    }

    /**
     * The next run reads again: a person parameter stored in between counts there.
     */
    public function test_next_run_reads_again(): void {
        $this->resetAfterTest();
        $task = self::task(array_fill(0, 60, 0.1));
        self::call($task, 'calculate_sd_from_past_attempts');
        $this->assertSame(1, $task->reads);

        $progress = $this->createMock(progress::class);
        $progress->method('get_ignore_last_response')->willReturn(true);
        $context = ['progress' => $progress, 'catscaleid' => 7];
        $task->run($context);
        self::call($task, 'calculate_sd_from_past_attempts');
        $this->assertSame(2, $task->reads);
    }

    /**
     * Every past ability the same: the mean is that value, the spread 0 as computed - the estimator raises it to its
     * minimum and the fallback is an event without any person's value (issue #167, re-audit 15.11.2026).
     */
    public function test_all_equal(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        $task = self::task(array_fill(0, 50, 0.25));

        $this->assertSame(0.25, self::call($task, 'calculate_mean_from_past_attempts'));
        $this->assertSame(0.0, self::call($task, 'calculate_sd_from_past_attempts'));

        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \local_catquiz\event\numeric_fallback_applied);
        $this->assertCount(1, $events, 'The missing spread is reported once.');
        $this->assertSame(1, $task->reads);
    }

    /**
     * Abilities at the ends of what the engine stores (+-800, the saturation bound) and in between: finite figures,
     * as computed by hand.
     */
    public function test_extreme_values(): void {
        $this->resetAfterTest();
        $abilities = [];
        for ($i = 0; $i < 51; $i++) {
            $abilities[] = $i % 2 ? 800.0 : -800.0;
        }
        $abilities[] = 0.125;
        $task = self::task($abilities);

        $mean = self::call($task, 'calculate_mean_from_past_attempts');
        $sd = self::call($task, 'calculate_sd_from_past_attempts');

        $expectedmean = array_sum($abilities) / count($abilities);
        $sum = 0.0;
        foreach ($abilities as $a) {
            $sum += ($a - $expectedmean) ** 2;
        }
        $this->assertTrue(is_finite($mean) && is_finite($sd));
        $this->assertSame($expectedmean, $mean);
        $this->assertSame(sqrt($sum / count($abilities)), $sd);
    }

    /**
     * The population of one step is one snapshot of the database (issue #167, re-audit 18.11.2026): a result written
     * between mean and spread - a parallel attempt ending - does not enter the spread of this step; the next step reads
     * it. Intermediate estimates of running attempts are no part of it (#164), at 49, 50 and 51 people.
     */
    public function test_one_snapshot_per_step_from_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $contextid = 4711;
        $scaleid = 7;
        $file = function (int $userid, float $ability, string $status = '') use ($DB, $contextid, $scaleid): void {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'userid' => $userid, 'catscaleid' => $scaleid, 'contextid' => $contextid, 'attemptid' => 1000 + $userid,
                'ability' => $ability, 'isvalid' => $status === '' ? 1 : 0, 'resultsource' => 'current',
                'validationstatus' => $status === '' ? null : $status, 'standarderror' => 0.3, 'n' => 10,
                'status' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        };
        $task = new updatepersonability();
        $task->set_context(['contextid' => $contextid, 'catscaleid' => $scaleid]);
        foreach ([49 => null, 50 => true, 51 => true] as $n => $enough) {
            $DB->delete_records('local_catquiz_personparams', ['contextid' => $contextid]);
            for ($i = 1; $i <= $n; $i++) {
                $file($i, ($i % 7) * 0.25 - 0.5);
            }
            // A running attempt's intermediate estimate: never part of the population.
            $file(900, 3.0, \local_catquiz\local\result\personparam_repository::PROVISIONAL);
            $reset = new \ReflectionProperty(updatepersonability::class, 'pastabilities');
            $reset->setAccessible(true);
            $reset->setValue($task, null);
            $mean = self::call($task, 'calculate_mean_from_past_attempts');
            if ($enough === null) {
                $this->assertNull($mean, "$n people: below the threshold.");
                continue;
            }
            $this->assertNotNull($mean, "$n people.");
            $population = $reset->getValue($task);
            $this->assertCount($n, $population, "$n people: one value each, no intermediate estimate.");

            // A parallel attempt ends between the two figures of this step: not in this step.
            $file(901, 100.0);
            $sd = self::call($task, 'calculate_sd_from_past_attempts');
            $this->assertSame($population, $reset->getValue($task), 'The same snapshot for mean and spread.');
            $sum = 0.0;
            foreach ($population as $a) {
                $sum += ($a - $mean) ** 2;
            }
            $this->assertSame(sqrt($sum / $n), $sd, "$n people: the spread of the snapshot.");

            // The next step reads anew and finds it.
            $reset->setValue($task, null);
            $this->assertCount($n + 1, (array) (function () use ($task, $reset) {
                self::call($task, 'calculate_mean_from_past_attempts');
                return $reset->getValue($task);
            })());
        }
    }
}
