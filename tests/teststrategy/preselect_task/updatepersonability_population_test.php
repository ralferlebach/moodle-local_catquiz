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
 * @covers \local_catquiz\teststrategy\preselect_task\updatepersonability
 */
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
}
