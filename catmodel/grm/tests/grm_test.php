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
 * Tests for core_message_inbound to test Variable Envelope Return Path functionality.
 *
 * @package    catmodel_grm
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catmodel_grm;

use catmodel_rasch\rasch;
use local_catquiz\local\model\model_model;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use local_catquiz\local\model\model_responses;

/**
 * Tests for core_message_inbound to test Variable Envelope Return Path functionality.
 *
 * @package    catmodel_grm
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 *
 * @covers \catmodel_grm\grm
 */
final class grm_test extends TestCase {
    use \local_catquiz\derivative_fd_trait;

    /**
     * restrict_to_trusted_region() must return ascending thresholds and a finite likelihood.
     *
     * @return void
     */
    public function test_restrict_to_trusted_region_orders_thresholds(): void {
        // Deliberately out of order: a_2 (0.5) > a_3 (1.0) would make P_middle negative.
        $ip = ['difficulties' => ['0.0' => 0.0, '0.5' => 1.5, '1.0' => -1.5]];
        $restricted = grm::restrict_to_trusted_region($ip);

        $values = array_values($restricted['difficulties']);
        // Skip the baseline placeholder (index 0); the real thresholds must be ascending.
        for ($i = 2; $i < count($values); $i++) {
            $this->assertGreaterThan($values[$i - 1], $values[$i], 'Thresholds must be strictly ascending.');
        }

        $ll = grm::log_likelihood(['ability' => 0.0], $restricted, 0.5);
        $this->assertIsFloat($ll);
        $this->assertFalse(is_nan($ll), 'Ordered thresholds must yield a finite log-likelihood.');
    }


    /**
     * Verifies least_mean_squares_1st_derivative_ip() against the numeric gradient.
     *
     * @dataProvider lms_fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     * @param float $n number of observations
     *
     * @return void
     */
    public function test_lms_1st_derivative_numeric(array $pp, array $ip, float $frac, float $n): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac, $n) {
            return grm::least_mean_squares($pp, grm::convert_vector_to_ip($v, $fractions), $frac, $n);
        };
        $this->assert_gradient_close($this->fd_gradient($f, $x), grm::least_mean_squares_1st_derivative_ip($pp, $ip, $frac, $n));
    }

    /**
     * Verifies least_mean_squares_2nd_derivative_ip() against the numeric Hessian.
     *
     * @dataProvider lms_fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     * @param float $n number of observations
     *
     * @return void
     */
    public function test_lms_2nd_derivative_numeric(array $pp, array $ip, float $frac, float $n): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac, $n) {
            return grm::least_mean_squares($pp, grm::convert_vector_to_ip($v, $fractions), $frac, $n);
        };
        $this->assert_hessian_close($this->fd_hessian($f, $x), grm::least_mean_squares_2nd_derivative_ip($pp, $ip, $frac, $n));
    }

    /**
     * Deterministic grid for the LMS FD checks.
     *
     * @return array
     */
    public static function lms_fd_cases_provider(): array {
        $items = [
            'a' => ['difficulties' => ['0.0' => 0.0, '0.5' => -0.7, '1.0' => 0.9]],
            'b' => ['difficulties' => ['0.0' => 0.0, '0.25' => -1.2, '0.5' => -0.2, '0.75' => 0.5, '1.0' => 1.4]],
        ];
        $abilities = [-1.0, 0.3, 1.2];
        $cases = [];
        foreach ($items as $label => $ip) {
            foreach ($abilities as $ai => $ability) {
                foreach (array_keys($ip['difficulties']) as $frac) {
                    $cases[sprintf('%s-a%d-f%s', $label, $ai, $frac)] = [
                        'pp' => ['ability' => $ability], 'ip' => $ip, 'frac' => (float) $frac, 'n' => 3.0,
                    ];
                }
            }
        }
        return $cases;
    }


    /**
     * Verifies lors_1st_derivative_ip() against the numeric gradient of lors_residuals().
     *
     * @dataProvider lors_fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param array $ors observed odds ratios
     * @param float $n number of observations
     *
     * @return void
     */
    public function test_lors_1st_derivative_numeric(array $pp, array $ip, array $ors, float $n): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $ors, $n) {
            return grm::lors_residuals($pp, grm::convert_vector_to_ip($v, $fractions), $ors, $n);
        };
        $this->assert_gradient_close($this->fd_gradient($f, $x), grm::lors_1st_derivative_ip($pp, $ip, $ors, $n));
    }

    /**
     * Verifies lors_2nd_derivative_ip() against the numeric Hessian of lors_residuals().
     *
     * @dataProvider lors_fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param array $ors observed odds ratios
     * @param float $n number of observations
     *
     * @return void
     */
    public function test_lors_2nd_derivative_numeric(array $pp, array $ip, array $ors, float $n): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $ors, $n) {
            return grm::lors_residuals($pp, grm::convert_vector_to_ip($v, $fractions), $ors, $n);
        };
        $this->assert_hessian_close($this->fd_hessian($f, $x), grm::lors_2nd_derivative_ip($pp, $ip, $ors, $n));
    }

    /**
     * Deterministic (item x ability x odds ratios) grid for the LORS FD checks.
     *
     * @return array
     */
    public static function lors_fd_cases_provider(): array {
        $items = [
            'a' => ['difficulties' => ['0.0' => 0.0, '0.5' => -0.7, '1.0' => 0.9]],
            'b' => ['difficulties' => ['0.0' => 0.0, '0.25' => -1.2, '0.5' => -0.2, '0.75' => 0.5, '1.0' => 1.4]],
        ];
        $orsets = [
            'a' => ['0.5' => 1.5, '1.0' => 0.6],
            'b' => ['0.25' => 2.0, '0.5' => 1.1, '0.75' => 0.7, '1.0' => 0.4],
        ];
        $abilities = [-1.0, 0.3, 1.2];
        $cases = [];
        foreach ($items as $label => $ip) {
            foreach ($abilities as $ai => $ability) {
                $cases[sprintf('%s-a%d', $label, $ai)] = [
                    'pp' => ['ability' => $ability],
                    'ip' => $ip,
                    'ors' => $orsets[$label],
                    'n' => 1.0,
                ];
            }
        }
        return $cases;
    }


    /**
     * Verifies get_log_jacobian() against the numeric gradient of log_likelihood().
     *
     * @dataProvider fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     *
     * @return void
     */
    public function test_get_log_jacobian_numeric(array $pp, array $ip, float $frac): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac) {
            return grm::log_likelihood($pp, grm::convert_vector_to_ip($v, $fractions), $frac);
        };
        $this->assert_gradient_close($this->fd_gradient($f, $x), grm::get_log_jacobian($pp, $ip, $frac));
    }

    /**
     * Verifies get_log_hessian() against the numeric Hessian of log_likelihood().
     *
     * @dataProvider fd_cases_provider
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     *
     * @return void
     */
    public function test_get_log_hessian_numeric(array $pp, array $ip, float $frac): void {
        $fractions = array_keys($ip['difficulties']);
        $x = grm::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac) {
            return grm::log_likelihood($pp, grm::convert_vector_to_ip($v, $fractions), $frac);
        };
        $this->assert_hessian_close($this->fd_hessian($f, $x), grm::get_log_hessian($pp, $ip, $frac));
    }

    /**
     * Deterministic grid of (item, ability, response) for the FD checks.
     *
     * @return array
     */
    public static function fd_cases_provider(): array {
        $items = [
            'a' => ['difficulties' => ['0.0' => 0.0, '0.5' => -0.7, '1.0' => 0.9]],
            'b' => ['difficulties' => ['0.0' => 0.0, '0.25' => -1.2, '0.5' => -0.2, '0.75' => 0.5, '1.0' => 1.4]],
        ];
        $abilities = [-1.3, 0.0, 1.1];
        $cases = [];
        foreach ($items as $label => $ip) {
            foreach ($abilities as $ai => $ability) {
                foreach (array_keys($ip['difficulties']) as $frac) {
                    $cases[sprintf('%s-a%d-f%s', $label, $ai, $frac)] = [
                        'pp' => ['ability' => $ability], 'ip' => $ip, 'frac' => (float) $frac,
                    ];
                }
            }
        }
        return $cases;
    }


    /**
     * This test calls the get_log_jacobain function with the model and test its output with verified data.
     *
     * @dataProvider get_log_jacobian_provider
     *
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param array $expected
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws ExpectationFailedException
     *
     */
    public function test_get_log_jacobian(array $pp, float $frac, array $ip, array $expected): void {
    }

    /**
     * This test calls the get_log_jacobain function with the model and test its output with verified data.
     *
     * @dataProvider get_log_hessian_provider
     *
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param array $expected
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws ExpectationFailedException
     *
     */
    public function test_get_log_hessian(array $pp, float $frac, array $ip, array $expected): void {
    }


    /**
     * Test likelihood function.
     * @dataProvider likelihood_provider
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param float $expected
     * @return void
     */
    public function test_likelihood(array $pp, float $frac, array $ip, float $expected): void {
        $result = grm::likelihood($pp, $ip, $frac);

        // We only verify for four commas after the dot.
        $expected = (float)sprintf("%.6f", $expected);
        $result = (float)sprintf("%.6f", $result);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test log_likelihood_p function.
     * @dataProvider log_likelihood_p_provider
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param float $expected
     * @return void
     */
    public function test_log_likelihood_p(array $pp, float $frac, array $ip, float $expected): void {
        $result = grm::log_likelihood_p($pp, $ip, $frac);

        // We only verify for four commas after the dot.
        $expected = (float)sprintf("%.6f", $expected);
        $result = (float)sprintf("%.6f", $result);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test log_likelihood_p_p function.
     * @dataProvider log_likelihood_p_p_provider
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param float $expected
     * @return void
     */
    public function test_log_likelihood_p_p(array $pp, float $frac, array $ip, float $expected): void {
        $result = grm::log_likelihood_p_p($pp, $ip, $frac);

        // We only verify for four commas after the dot.
        $expected = (float)sprintf("%.6f", $expected);
        $result = (float)sprintf("%.6f", $result);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test least_mean_squares_1st_derivative_ip function.
     * @dataProvider least_mean_squares_1st_derivative_ip_provider
     * @param int $n
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param array $expected
     * @return void
     */
    public function test_least_mean_squares_1st_derivative_ip(int $n, array $pp, float $frac, array $ip, array $expected): void {
    }

    /**
     * Test least_mean_squares_1st_derivative_ip function.
     * @dataProvider least_mean_squares_2nd_derivative_ip_provider
     * @param int $n
     * @param array $pp
     * @param float $frac
     * @param array $ip
     * @param array $expected
     * @return void
     */
    public function test_least_mean_squares_2nd_derivative_ip(int $n, array $pp, float $frac, array $ip, array $expected): void {
    }

    /**
     * Provider function for least_mean_squares_1st_derivative_ip
     * @return array
     */
    public static function least_mean_squares_1st_derivative_ip_provider(): array {
        return [];
    }

    /**
     * Provider function for least_mean_squares_1st_derivative_ip
     * @return array
     */
    public static function least_mean_squares_2nd_derivative_ip_provider(): array {
        return [];
    }

    /**
     * Provider function for likelihood
     * @return array
     */
    public static function likelihood_provider(): array {
        $labels = ["testcase1", "testcase2", "testcase3"];
        $ability = [-3, -1.5, 1.5];
        $frac = [0, 0.5, 1];
        $parameter = [
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [0.377540669, 0.244918662, 0.377540669],
            [0.622459331, 0.330114796, 0.047425873],
            [0.268941421, 0.108599247, 0.622459331],
        ];

        $providedarray = [];

        foreach ($labels as $key => $label) {
            foreach ($expected[$key] as $case => $expectedvalue) {
                $providedarray[$label . "-" . $case] = ['pp' => ['ability' => $ability[$key]],
                    'frac' => $frac[$case],
                    'ip' => $parameter[$key],
                    'expected' => $expectedvalue,
                ];
            }
        }

        return $providedarray;
    }

    /**
     * Provider function for log_likelihood_p
     * @return array
     */
    public static function log_likelihood_p_provider(): array {
        $labels = ["testcase1", "testcase2", "testcase3"];
        $ability = [-3, -1.5, 1.5];
        $frac = [0, 0.5, 1];
        $parameter = [
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [-0.622459331, 0.000000000, 0.622459331],
            [-0.377540669, 0.575033458, 0.952574127],
            [-0.731058579, -0.35351791, 0.377540669],
        ];

        $providedarray = [];

        foreach ($labels as $key => $label) {
            foreach ($expected[$key] as $case => $expectedvalue) {
                $providedarray[$label . "-" . $case] = ['pp' => ['ability' => $ability[$key]],
                    'frac' => $frac[$case],
                    'ip' => $parameter[$key],
                    'expected' => $expectedvalue,
                ];
            }
        }

        return $providedarray;
    }

    /**
     * Provider function log_likelihood_p_p_provider
     * @return array
     */
    public static function log_likelihood_p_p_provider(): array {
        $labels = ["testcase1", "testcase2", "testcase3"];
        $ability = [-3, -1.5, 1.5];
        $frac = [0, 0.5, 1];
        $parameter = [
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [-0.235003712, -0.470007424, -0.235003712],
            [-0.235003712, -0.280180372, -0.045176660],
            [-0.196611933, -0.431615645, -0.235003712],
        ];

        $providedarray = [];

        foreach ($labels as $key => $label) {
            foreach ($expected[$key] as $case => $expectedvalue) {
                $providedarray[$label . "-" . $case] = ['pp' => ['ability' => $ability[$key]],
                    'frac' => $frac[$case],
                    'ip' => $parameter[$key],
                    'expected' => $expectedvalue,
                ];
            }
        }

        return $providedarray;
    }

    /**
     * Return Data for log jacobian test
     * @return array
     */
    public static function get_log_jacobian_provider(): array {
        return [];
    }

     /**
      * Return Data for log hessian test
      * @return array
      */
    public static function get_log_hessian_provider(): array {

        return [];
    }

    /**
     * Get model.
     *
     * @return grm
     */
    private function getmodel(): grm {
        return model_model::get_instance('grm');
    }
}
