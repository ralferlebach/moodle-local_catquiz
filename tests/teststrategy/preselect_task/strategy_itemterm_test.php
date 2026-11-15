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

use basic_testcase;
use local_catquiz\teststrategy\progress;

/**
 * The item term of the four subscale strategies (issue #162, decisions of 15.11.2026).
 *
 * B(i, s) = 1 / (1 + exp(2 * I_s * (0.5 - f_s) * (b_i - theta_s))), item term = B ^ n_s with n_s the productive
 * questions answered in the scale - the same in HIGHESTSUB, LOWESTSUB, ALLSUBS and RELSUBS, without n_min and
 * without a normalisation to 2B. The scale term and the other parts of the score stay as they were.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\strategystrengthscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\strategydeficitscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\inferallsubscalesscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\strategyrelevantscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\strategyscore::class)]
final class strategy_itemterm_test extends basic_testcase {
    /**
     * The four strategies built on strategyscore.
     *
     * @return array
     */
    public static function strategies(): array {
        return [
            'HIGHESTSUB' => [strategystrengthscore::class],
            'LOWESTSUB' => [strategydeficitscore::class],
            'ALLSUBS' => [inferallsubscalesscore::class],
            'RELSUBS' => [strategyrelevantscore::class],
        ];
    }

    /**
     * Calls a protected method of a task.
     *
     * @param strategyscore $task
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private static function call(strategyscore $task, string $method, array $args) {
        $m = new \ReflectionMethod($task, $method);
        return $m->invokeArgs($task, $args);
    }

    /**
     * B as the decision of 15.11.2026 defines it.
     *
     * @param float $testinfo
     * @param float $fraction
     * @param float $difficulty
     * @param float $ability
     * @return float
     */
    private static function b(float $testinfo, float $fraction, float $difficulty, float $ability): float {
        return 1 / (1 + exp($testinfo * 2 * (0.5 - $fraction) * ($difficulty - $ability)));
    }

    /**
     * Item term = B ^ n_s for every n_s and fraction, whatever n_min is (AK-01, AK-02, AK-03).
     *
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_item_term_is_b_to_the_power_of_n(string $class): void {
        $task = new $class();
        foreach ([0, 1, 2, 3, 4, 5, 10] as $n) {
            foreach ([0.0, 0.25, 0.5, 1.0] as $fraction) {
                foreach ([0, 3, 9] as $nmin) {
                    $b = self::b(2.5, $fraction, 1.2, -0.4);
                    $term = self::call($task, 'get_question_itemterm', [2.5, $fraction, 1.2, -0.4, $n, $nmin]);
                    $this->assertSame($b ** $n, $term, "n = $n, f = $fraction, n_min = $nmin");
                }
            }
        }
        $this->assertSame(1.0, self::call($task, 'get_question_itemterm', [2.5, 0.0, 1.2, -0.4, 0, 3]));
        $this->assertSame(
            self::b(2.5, 0.0, 1.2, -0.4),
            self::call($task, 'get_question_itemterm', [2.5, 0.0, 1.2, -0.4, 1, 3])
        );
    }

    /**
     * With f = 0.5, B = 0.5 and the item term is 2 ^ -n (AK-08): it shifts the priority between scales with
     * different n.
     *
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_neutral_fraction_halves_per_question(string $class): void {
        $task = new $class();
        foreach ([0, 1, 2, 5, 10] as $n) {
            $this->assertSame(0.5 ** $n, self::call($task, 'get_question_itemterm', [3.0, 0.5, 2.0, 0.0, $n, 4]));
        }
    }

    /**
     * Extreme values stay finite and within [0, 1] (no NaN, no overflow beyond the limits).
     *
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_extreme_values_stay_in_range(string $class): void {
        $task = new $class();
        foreach ([0.0, 1.0] as $fraction) {
            foreach ([-800.0, 0.0, 800.0] as $difficulty) {
                foreach ([0, 1, 50] as $n) {
                    $term = self::call($task, 'get_question_itemterm', [1.0e4, $fraction, $difficulty, 0.0, $n, 2]);
                    $this->assertFalse(is_nan((float) $term), "f = $fraction, b = $difficulty, n = $n");
                    $this->assertGreaterThanOrEqual(0.0, $term);
                    $this->assertLessThanOrEqual(1.0, $term);
                }
            }
        }
    }

    /**
     * The scale terms of HIGHESTSUB and LOWESTSUB are unchanged (AK-05).
     */
    public function test_scale_terms_are_unchanged(): void {
        foreach ([[0.0, 0.0], [1.5, 0.7], [3.0, -1.2]] as [$testinfo, $difference]) {
            $this->assertSame(
                1 / (1 + exp(-1 * $testinfo * $difference)),
                self::call(new strategystrengthscore(), 'get_question_scaleterm', [$testinfo, $difference])
            );
            $this->assertSame(
                1 / (1 + exp($testinfo * $difference)),
                self::call(new strategydeficitscore(), 'get_question_scaleterm', [$testinfo, $difference])
            );
        }
        $this->assertSame(1, self::call(new inferallsubscalesscore(), 'get_question_scaleterm', [2.0, 1.0]));
        $this->assertSame(1, self::call(new strategyrelevantscore(), 'get_question_scaleterm', [2.0, 1.0]));
    }

    /**
     * HIGHESTSUB applies the last-time-played penalty exactly once, like the other three (AK-04): P = 0, 0 < P < 1
     * and P = 1.
     *
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_penalty_is_applied_once(string $class): void {
        $task = new $class();
        foreach ([0, 0.25, 1] as $penalty) {
            $question = (object) [
                'fisherinformation' => [7 => 0.8], 'processterm' => 0.5, 'scaleterm' => 0.75, 'itemterm' => 0.4,
                'lasttimeplayedpenaltyfactor' => $penalty,
            ];
            $this->assertSame(0.8 * 0.5 * 0.75 * 0.4 * $penalty, self::call($task, 'get_score', [$question, 7]), "P = $penalty");
        }
    }

    /**
     * n_s counts the productive questions of the scale; a pilot question played there does not raise the exponent
     * (AK-02). The other terms keep counting every question played.
     */
    public function test_pilot_questions_do_not_count_for_the_exponent(): void {
        $played = [
            (object) ['id' => 1, 'catscaleid' => 5, 'is_pilot' => false],
            (object) ['id' => 2, 'catscaleid' => 5, 'is_pilot' => true],
            (object) ['id' => 3, 'catscaleid' => 5, 'is_pilot' => false],
        ];
        $task = new class extends strategyscore {
            /** @var array The n passed to the item term. */
            public array $seen = [];
            /**
             * Neutral scale term.
             *
             * @param float $testinfo
             * @param float $abilitydifference
             * @return int
             */
            protected function get_question_scaleterm(float $testinfo, float $abilitydifference) {
                return 1;
            }
            /**
             * Records the n it is called with.
             *
             * @param float $testinfo
             * @param float $fraction
             * @param mixed $difficulty
             * @param mixed $scaleability
             * @param mixed $scalecount
             * @param int $minattemptsperscale
             * @return int
             */
            protected function get_question_itemterm(
                float $testinfo,
                float $fraction,
                $difficulty,
                $scaleability,
                $scalecount,
                int $minattemptsperscale
            ) {
                $this->seen[] = $scalecount;
                return 1;
            }
            /**
             * The process term as score.
             *
             * @param \stdClass $question
             * @param int $scaleid
             * @return float
             */
            protected function get_score(\stdClass $question, int $scaleid) {
                return $question->processterm;
            }
            /**
             * A fixed scale: ability 0, test information 1.
             *
             * @param int $scaleid
             * @return array|null
             */
            protected function scale_state(int $scaleid): ?array {
                return [0.0, 1.0];
            }
        };
        $progress = $this->createMock(progress::class);
        $progress->method('get_user_responses')->willReturn([1 => ['fraction' => 1.0], 2 => ['fraction' => 0.0],
            3 => ['fraction' => 1.0]]);
        $progress->method('get_playedquestions')->willReturnCallback(
            fn(bool $byscale = false, ?int $scaleid = null) => $byscale ? ($scaleid === 5 ? $played : []) : $played
        );
        $progress->method('get_active_scales')->willReturn([5]);
        $progress->method('is_active_scale')->willReturn(true);
        $progress->method('get_abilities')->willReturn([5 => 0.0]);
        $context = [
            'contextid' => 1, 'catscaleid' => 5, 'min_attempts_per_scale' => 0, 'progress' => $progress,
            'questions' => [9 => (object) ['id' => 9, 'catscaleid' => 5, 'difficulty' => 0.0]],
        ];
        $selected = $task->run($context)->unwrap();
        $this->assertSame([2], $task->seen, 'Two productive questions, the pilot not counted.');
        // The process term still divides by every question played: max(0.1, 1.0) / 3.
        $this->assertSame(1.0 / 3, $selected->processterm);
    }
}
