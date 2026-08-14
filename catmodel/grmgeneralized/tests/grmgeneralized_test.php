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
 * @package    catmodel_grmgeneralized
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catmodel_grmgeneralized;

use catmodel_rasch\rasch;
use local_catquiz\local\model\model_model;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use local_catquiz\local\model\model_responses;

/**
 * Tests for core_message_inbound to test Variable Envelope Return Path functionality.
 *
 * @package    catmodel_grmgeneralized
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 *
 * @covers \catmodel_grmgeneralized\grmgeneralized
 */
final class grmgeneralized_test extends TestCase {
    use \local_catquiz\derivative_fd_trait;

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
        $x = grmgeneralized::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $ors, $n) {
            return grmgeneralized::lors_residuals($pp, grmgeneralized::convert_vector_to_ip($v, $fractions), $ors, $n);
        };
        $this->assert_gradient_close($this->fd_gradient($f, $x), grmgeneralized::lors_1st_derivative_ip($pp, $ip, $ors, $n));
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
        $x = grmgeneralized::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $ors, $n) {
            return grmgeneralized::lors_residuals($pp, grmgeneralized::convert_vector_to_ip($v, $fractions), $ors, $n);
        };
        $this->assert_hessian_close($this->fd_hessian($f, $x), grmgeneralized::lors_2nd_derivative_ip($pp, $ip, $ors, $n));
    }

    /**
     * Deterministic (item x ability x odds ratios) grid for the LORS FD checks.
     *
     * @return array
     */
    public static function lors_fd_cases_provider(): array {
        $items = [
            'a' => ['difficulties' => ['0.0' => 0.0, '0.5' => -0.7, '1.0' => 0.9], 'discrimination' => 1.3],
            'b' => ['difficulties' => ['0.0' => 0.0, '0.25' => -1.2, '0.5' => -0.2, '0.75' => 0.5, '1.0' => 1.4], 'discrimination' => 0.8],
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
        $x = grmgeneralized::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac) {
            return grmgeneralized::log_likelihood($pp, grmgeneralized::convert_vector_to_ip($v, $fractions), $frac);
        };
        $this->assert_gradient_close($this->fd_gradient($f, $x), grmgeneralized::get_log_jacobian($pp, $ip, $frac));
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
        $x = grmgeneralized::convert_ip_to_vector($ip);
        $f = function (array $v) use ($pp, $fractions, $frac) {
            return grmgeneralized::log_likelihood($pp, grmgeneralized::convert_vector_to_ip($v, $fractions), $frac);
        };
        $this->assert_hessian_close($this->fd_hessian($f, $x), grmgeneralized::get_log_hessian($pp, $ip, $frac));
    }

    /**
     * Deterministic grid of (item, ability, response) for the FD checks.
     *
     * @return array
     */
    public static function fd_cases_provider(): array {
        $items = [
            'a' => ['difficulties' => ['0.0' => 0.0, '0.5' => -0.7, '1.0' => 0.9], 'discrimination' => 1.3],
            'b' => ['difficulties' => ['0.0' => 0.0, '0.25' => -1.2, '0.5' => -0.2, '0.75' => 0.5, '1.0' => 1.4], 'discrimination' => 0.8],
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
        $result = grmgeneralized::likelihood($pp, $ip, $frac);

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
        $result = grmgeneralized::log_likelihood_p($pp, $ip, $frac);

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
        $result = grmgeneralized::log_likelihood_p_p($pp, $ip, $frac);

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
            ["discrimination" => 0.7,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["discrimination" => 2.0,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["discrimination" => 1.5,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [0.413382421, 0.173235158, 0.413382421],
            [0.731058579, 0.266468798, 0.002472623],
            [0.182425524, 0.138395777, 0.679178699],
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
            ["discrimination" => 0.7,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["discrimination" => 2.0,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["discrimination" => 1.5,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [-0.410632305, 0.000000000, 0.410632305],
            [-0.537882843, 1.457171911, 1.995054754],
            [-1.226361714, -0.745129763, 0.481231951],
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
            ["discrimination" => 0.7,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -3.5,
                "1.0" => -2.5,
            ]],
            ["discrimination" => 2.0,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => -1,
                "1.0" => 1.5,
            ]],
            ["discrimination" => 1.5,
            "difficulties" => [
                "0.0" => 0,
                "0.5" => 0.5,
                "1.0" => 1.0,
            ]],
        ];
        $expected = [
            [-0.118823724, -0.237647447, -0.118823724],
            [-0.786447733, -0.79631377, -0.009866037],
            [-0.335579517, -0.825843253, -0.490263736],
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
     * @return grmgeneralized
     */
    private function getmodel(): grmgeneralized {
        return model_model::get_instance('grmgeneralized');
    }
}
