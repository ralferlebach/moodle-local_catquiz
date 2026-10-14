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
use local_catquiz\local\model\model_item_param;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_item_response;
use local_catquiz\local\model\model_person_param;
use local_catquiz\local\model\model_raschmodel;
use local_catquiz\teststrategy\preselect_task\updatepersonability;

/**
 * The trusted-region prior with a spread of zero, near zero or no number at all (issue #116).
 *
 * When every past person parameter is the same, their standard deviation is 0 and the prior's
 * derivatives divided by zero. The prior is needed wherever the estimate leaves the trusted region, so
 * its spread is forced away from zero in one place for every model: max(sd, 10^-PRECISION).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\local\model\model_raschmodel::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\catcalc::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\updatepersonability::class)]
final class trusted_region_sd_test extends advanced_testcase {
    /**
     * The smallest spread the prior is built from.
     *
     * @return float
     */
    private static function minimum(): float {
        return 10 ** -model_raschmodel::PRECISION;
    }

    /**
     * Spreads that are raised to the minimum.
     *
     * @return array
     */
    public static function raised_sd_provider(): array {
        return [
            'zero' => [0.0],
            'near zero' => [1e-15],
            'just below the minimum' => [0.00099],
            'negative' => [-1.0],
            'negative zero' => [-0.0],
            'minus infinite' => [-INF],
            'not a number' => [NAN],
            'so small its square underflows' => [1e-200],
        ];
    }

    /**
     * Below the minimum the prior is built from the minimum: finite derivatives, never an error.
     *
     * @param float $sd
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('raised_sd_provider')]
    public function test_spread_is_raised_to_the_minimum(float $sd): void {
        $minimum = self::minimum();
        $this->assertSame($minimum, model_raschmodel::sanitize_trusted_region_sd($sd));
        $this->assertSame((0.2 - 1.5) / ($minimum ** 2), model_raschmodel::get_ability_tr_jacobian(['ability' => 1.5], 0.2, $sd));
        $this->assertSame(-1 / ($minimum ** 2), model_raschmodel::get_ability_tr_hessian(['ability' => 1.5], 0.2, $sd));
    }

    /**
     * A spread at or above the minimum is not touched: the regular prior stays exactly as it was.
     */
    public function test_usable_spread_is_unchanged(): void {
        foreach ([self::minimum(), 0.01, 0.5, 1.0, 3.0] as $sd) {
            $this->assertSame($sd, model_raschmodel::sanitize_trusted_region_sd($sd));
            $this->assertSame(
                (0.2 - 1.5) / ($sd ** 2),
                model_raschmodel::get_ability_tr_jacobian(['ability' => 1.5], 0.2, $sd),
                "sd $sd"
            );
            $this->assertSame(-1 / ($sd ** 2), model_raschmodel::get_ability_tr_hessian(['ability' => 1.5], 0.2, $sd));
        }
    }

    /**
     * An infinite spread stays: a prior without curvature, and no error.
     */
    public function test_infinite_spread_is_a_flat_prior(): void {
        $this->assertSame(INF, model_raschmodel::sanitize_trusted_region_sd(INF));
        $this->assertEqualsWithDelta(0.0, model_raschmodel::get_ability_tr_jacobian(['ability' => 1.5], 0.2, INF), 0.0);
        $this->assertEqualsWithDelta(0.0, model_raschmodel::get_ability_tr_hessian(['ability' => 1.5], 0.2, INF), 0.0);
    }

    /**
     * The trusted region uses the same spread: mean +/- factor * minimum, not mean +/- 0 or NaN.
     */
    public function test_trusted_region_uses_the_raised_spread(): void {
        foreach ([0.0, NAN, -1.0] as $sd) {
            $pp = model_raschmodel::restrict_to_trusted_region_pp(['ability' => 4.0], -10.0, 10.0, 3, 0.2, $sd, true);
            $this->assertEqualsWithDelta(0.2 + 3 * self::minimum(), $pp['ability'], 1e-12);
        }
        $pp = model_raschmodel::restrict_to_trusted_region_pp(['ability' => 4.0], -10.0, 10.0, 3, 0.2, 1.0, true);
        $this->assertEqualsWithDelta(3.2, $pp['ability'], 1e-12);
    }

    /**
     * Responses that push the estimate out of the trusted region, so the prior is asked for.
     *
     * @param float $fraction 1.0 for all correct.
     * @param int $n
     * @return array [responses, items]
     */
    private function sequence(float $fraction, int $n = 8): array {
        $person = new model_person_param('p', 1);
        $items = new model_item_param_list();
        $responses = [];
        for ($i = 0; $i < $n; $i++) {
            $item = new model_item_param('it' . $i, 'raschbirnbaum');
            $item->set_parameters(['difficulty' => -2.0 + 4.0 * $i / ($n - 1), 'discrimination' => 1.3]);
            $items->add($item);
            $responses['it' . $i] = new model_item_response('it' . $i, $fraction, $person);
        }
        return [$responses, $items];
    }

    /**
     * The whole estimation stays finite, inside the range, and terminates - for every spread raised.
     *
     * All correct and all wrong drive the estimate to the edge, where the prior is added; with sd 0
     * that used to be a DivisionByZeroError. The result is the estimate with the minimum spread, and
     * the raise is reported.
     *
     * @param float $sd
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('raised_sd_provider')]
    public function test_estimation_stays_finite_with_a_raised_spread(float $sd): void {
        $this->resetAfterTest();
        foreach ([1.0, 0.0] as $fraction) {
            [$responses, $items] = $this->sequence($fraction);
            $ability = catcalc::estimate_person_ability($responses, $items, 0.0, 0.2, $sd, -10.0, 10.0, true);
            $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);

            $this->assertTrue(is_finite($ability), "fraction $fraction");
            $this->assertGreaterThanOrEqual(-10.0, $ability);
            $this->assertLessThanOrEqual(10.0, $ability);
            $this->assertSame(
                $ability,
                catcalc::estimate_person_ability($responses, $items, 0.0, 0.2, self::minimum(), -10.0, 10.0, true),
                'The same as the estimate with the minimum spread.'
            );
            $this->assertDebuggingNotCalled();
        }
    }

    /**
     * Slightly different abilities - the control group - are estimated as before, without a notice.
     */
    public function test_usable_spread_estimates_without_notice(): void {
        $this->resetAfterTest();
        [$responses, $items] = $this->sequence(1.0);

        $ability = catcalc::estimate_person_ability($responses, $items, 0.0, 0.2, 0.8, -10.0, 10.0, false);

        $this->assertDebuggingNotCalled();
        $this->assertTrue(is_finite($ability));
        $this->assertGreaterThan(0.2, $ability, 'All correct lies above the mean.');
    }

    /**
     * Past abilities as the estimation of a new attempt sees them.
     *
     * @param array $abilities
     * @return float The spread the estimation would use.
     */
    private function past_spread(array $abilities): float {
        $task = new class ($abilities) extends updatepersonability {
            /** @var array Past abilities. */
            private array $past;

            /**
             * Constructor.
             *
             * @param array $past
             */
            public function __construct(array $past) {
                $this->past = $past;
            }

            /**
             * The past person parameters.
             *
             * @return array
             */
            protected function get_existing_personparams(): array {
                return array_map(fn($a) => (object) ['ability' => $a], $this->past);
            }
        };
        $task->set_context(['catscaleid' => 7]);
        $method = new \ReflectionMethod(updatepersonability::class, 'calculate_sd_from_past_attempts');
        $method->setAccessible(true);
        return $method->invoke($task);
    }

    /**
     * Identical past abilities: below 50 the default spread, from 50 on a spread of 0 - reported, and
     * raised to the minimum by the estimator.
     */
    public function test_identical_past_abilities(): void {
        $this->resetAfterTest();

        foreach ([1, 2, 49] as $n) {
            $this->assertSame(1.0, $this->past_spread(array_fill(0, $n, 0.2)), "N $n");
        }
        $this->assertDebuggingNotCalled();

        foreach ([50, 51, 500] as $n) {
            $sd = $this->past_spread(array_fill(0, $n, 0.2));
            $this->assertEqualsWithDelta(0.0, $sd, 1e-12, "N $n");
            $this->assertSame(self::minimum(), model_raschmodel::sanitize_trusted_region_sd($sd), "N $n: raised.");
            $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        }
    }

    /**
     * What is not a number is left out before mean and spread are taken; too few left is the default.
     */
    public function test_past_abilities_that_are_no_number_are_left_out(): void {
        $this->resetAfterTest();

        // 60 entries, but only 40 numbers: too few for an empirical spread.
        $abilities = array_merge(array_fill(0, 40, 0.5), array_fill(0, 10, NAN), array_fill(0, 10, INF));
        $this->assertSame(1.0, $this->past_spread($abilities));

        // 60 numbers spread around 0: a regular spread.
        $abilities = [];
        for ($i = 0; $i < 60; $i++) {
            $abilities[] = ($i % 2 ? 1 : -1) * 0.5;
        }
        $abilities[] = NAN;
        $this->assertEqualsWithDelta(0.5, $this->past_spread($abilities), 1e-12);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Only the shared helper decides: no model has its own guard against a spread of zero.
     */
    public function test_no_model_has_its_own_spread_guard(): void {
        global $CFG;

        $hits = [];
        foreach (glob($CFG->dirroot . '/local/catquiz/catmodel/*/classes/*.php') as $file) {
            $source = file_get_contents($file);
            if (preg_match('/function get_ability_tr_(jacobian|hessian)|sd\s*==\s*0/', $source)) {
                $hits[] = basename($file);
            }
        }
        $this->assertSame([], $hits);
    }
}
