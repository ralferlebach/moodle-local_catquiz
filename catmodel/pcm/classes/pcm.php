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
 * Class pcm.
 *
 * @package    catmodel_pcm
 * @copyright  2024 Wunderbyte GmbH <georg.maisser@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catmodel_pcm;

use Exception;
use local_catquiz\catcalc;
use local_catquiz\local\model\model_item_param;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_multiparam;
use local_catquiz\local\model\model_person_param_list;
use local_catquiz\local\model\model_raschmodel;
use stdClass;

/**
 * Class pcm of catmodels.
 *
 * @package    catmodel_pcm
 * @copyright  2023 Wunderbyte GmbH <georg.maisser@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pcm extends model_multiparam {
    /**
     * {@inheritDoc}
     *
     * @param array $parameters
     * @return float
     */
    public static function get_difficulty(array $parameters): float {
        return self::calculate_mean_difficulty($parameters);
    }

    /**
     * Returns the item parameter array from a database record.
     *
     * @param stdClass $record
     * @return array
     */
    public static function get_parameters_from_record(stdClass $record): array {

        $intercepts = json_decode($record->json, true)['intercepts'];

        return [
            'intercepts' => $intercepts,
            'difficulty' => round(self::calculate_mean_difficulty(['intercepts' => $intercepts]), self::PRECISION),
        ];
    }

    /**
     * Serialise the polytomous parameters into the record so they survive persistence.
     *
     * The reverse of get_parameters_from_record(): the 'intercepts' map is stored in the
     * record JSON, and the scalar difficulty column receives the mean difficulty.
     *
     * @param stdClass $record the record to enrich
     * @param array $parameters the item parameters
     *
     * @return stdClass
     *
     */
    public static function add_parameters_to_record(stdClass $record, array $parameters): stdClass {
        $record->json = json_encode(['intercepts' => $parameters['intercepts']]);
        $record->difficulty = $record->difficulty ?? self::calculate_mean_difficulty($parameters);
        return $record;
    }

    /**
     * Returns the name of this model.
     *
     * @return string
     */
    public function get_model_name(): string {
        return 'pcm';
    }

    // Definitions and Dimensions.

    /**
     * Goes modified to mathcat.php.
     *
     * @param array $ip
     *
     * @return array
     */
    public static function convert_ip_to_vector(array $ip): array {
        // Estimate only the M free step intercepts; the baseline category (lowest
        // fraction) is fixed at 0 and excluded from the parameter vector.
        $intercepts = self::sort_fractions($ip['intercepts']);
        return array_values(array_slice($intercepts, 1, null, true));
    }

    /**
     * Convert vector to item param
     *
     * @param array $vector
     * @param mixed $fractions
     *
     * @return array
     */
    public static function convert_vector_to_ip(array $vector, $fractions): array {
        // Re-add the fixed baseline (first fraction => 0), then the free intercepts.
        $intercepts = [(string) $fractions[0] => 0.0];
        foreach (array_slice($fractions, 1) as $i => $frac) {
            $intercepts[(string) $frac] = $vector[$i];
        }
        return ['intercepts' => $intercepts];
    }

    /**
     * Defines names if item parameter list
     *
     * The parameters have the following structure.
     * [
     *   'difficultiy': [fraction 1: 0, fraction 2: intercept 1, ..., fraction k+1: intercept k-1],
     *   'discrimination': discrimination
     * ]
     * @return array of string
     */

    /**
     * Get parameter names
     *
     * This will have the following structure.
     * [
     *   'difficultiy': [fraction1: 0, fraction2: intercept 1, ..., fraction k: difficulty k-1],
     *   'discrimination': discrimination
     * ]
     *
     * @return array
     */
    public static function get_parameter_names(): array {
        return ['intercepts', 'difficulty'];
    }

    /**
     * Definition of the number of model parameters
     *
     * @return int
     */
    /**
     * This model has a data-dependent number of parameters.
     *
     * @return bool
     *
     */
    public static function is_polytomous(): bool {
        return true;
    }

    /**
     * Data-driven start item parameters (empirical thresholds + fallback).
     *
     * @param array $itemresponse array of model_item_response
     *
     * @return array
     *
     */
    public static function get_start_ip(array $itemresponse): array {
        return [
            'intercepts' => self::empirical_start_thresholds($itemresponse),
        ];
    }

    /**
     * LORS objective value: n * sum_k R_k^2 over the free boundaries.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param array $ors observed odds ratios keyed by the free fractions
     * @param float $n number of observations
     *
     * @return float
     *
     */
    public static function lors_residuals(array $pp, array $ip, array $ors, float $n = 1): float {
        return self::compute_lors($pp, $ip, $ors, $n, 'intercepts', false)['residuals'];
    }

    /**
     * First derivative of the LORS objective w.r.t. the item parameters.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param array $ors observed odds ratios keyed by the free fractions
     * @param float $n number of observations
     *
     * @return array
     *
     */
    public static function lors_1st_derivative_ip(array $pp, array $ip, array $ors, float $n = 1): array {
        return self::compute_lors($pp, $ip, $ors, $n, 'intercepts', false)['jacobian'];
    }

    /**
     * Second derivative of the LORS objective w.r.t. the item parameters.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param array $ors observed odds ratios keyed by the free fractions
     * @param float $n number of observations
     *
     * @return array
     *
     */
    public static function lors_2nd_derivative_ip(array $pp, array $ip, array $ors, float $n = 1): array {
        return self::compute_lors($pp, $ip, $ors, $n, 'intercepts', false)['hessian'];
    }

    /**
     * LMS objective: n (frac - mu)^2 with the expected score mu = sum_k frac_k P_k.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     * @param float $n number of observations
     *
     * @return float
     *
     */
    public static function least_mean_squares(array $pp, array $ip, float $frac, float $n): float {
        $m = self::pcm_prob_moments($pp, $ip);
        return $n * ($frac - $m['mu']) ** 2;
    }

    /**
     * First derivative of the LMS objective w.r.t. the item parameters.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     * @param float $n number of observations
     *
     * @return array
     *
     */
    public static function least_mean_squares_1st_derivative_ip(array $pp, array $ip, float $frac, float $n): array {
        $m = self::pcm_prob_moments($pp, $ip);
        $kmax = $m['kmax'];
        $dmu = self::pcm_mu_gradient($m);

        $result = [];
        for ($j = 1; $j <= $kmax; $j++) {
            $result[] = 2 * $n * ($m['mu'] - $frac) * $dmu[$j];
        }
        return $result;
    }

    /**
     * Second derivative of the LMS objective w.r.t. the item parameters.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     * @param float $n number of observations
     *
     * @return array
     *
     */
    public static function least_mean_squares_2nd_derivative_ip(array $pp, array $ip, float $frac, float $n): array {
        $m = self::pcm_prob_moments($pp, $ip);
        $kmax = $m['kmax'];
        $dmu = self::pcm_mu_gradient($m);
        $ddmu = self::pcm_mu_hessian($m);

        $result = [];
        for ($j = 1; $j <= $kmax; $j++) {
            $row = [];
            for ($l = 1; $l <= $kmax; $l++) {
                $row[] = 2 * $n * ($dmu[$j] * $dmu[$l] + ($m['mu'] - $frac) * $ddmu[$j][$l]);
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Category probabilities, tails, fraction values and expected score for PCM.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     *
     * @return array
     *
     */
    private static function pcm_prob_moments(array $pp, array $ip): array {
        $ability = $pp['ability'];
        $a = self::sanitize_fractions($ip['intercepts']);
        $fractions = self::get_fractions($a);
        $kmax = count($fractions) - 1;

        $cumulative = 0.0;
        $weights = [];
        $fr = [];
        for ($k = 0; $k <= $kmax; $k++) {
            if ($k > 0) {
                $cumulative += $a[$fractions[$k]];
            }
            $weights[$k] = exp($k * $ability - $cumulative);
            $fr[$k] = (float) $fractions[$k];
        }
        $z = array_sum($weights);

        $p = [];
        $mu = 0.0;
        for ($k = 0; $k <= $kmax; $k++) {
            $p[$k] = $weights[$k] / $z;
            $mu += $fr[$k] * $p[$k];
        }

        $t = [];
        for ($j = 0; $j <= $kmax; $j++) {
            $sum = 0.0;
            for ($k = $j; $k <= $kmax; $k++) {
                $sum += $p[$k];
            }
            $t[$j] = $sum;
        }

        return ['p' => $p, 'fr' => $fr, 't' => $t, 'mu' => $mu, 'kmax' => $kmax];
    }

    /**
     * Gradient of the expected score mu w.r.t. the free intercepts.
     *
     * @param array $m output of pcm_prob_moments
     *
     * @return array indexed by boundary j = 1..kmax
     *
     */
    private static function pcm_mu_gradient(array $m): array {
        $dmu = [];
        for ($j = 1; $j <= $m['kmax']; $j++) {
            $sum = 0.0;
            for ($k = 0; $k <= $m['kmax']; $k++) {
                $sum += $m['fr'][$k] * $m['p'][$k] * ($m['t'][$j] - (($k >= $j) ? 1.0 : 0.0));
            }
            $dmu[$j] = $sum;
        }
        return $dmu;
    }

    /**
     * Hessian of the expected score mu w.r.t. the free intercepts.
     *
     * @param array $m output of pcm_prob_moments
     *
     * @return array indexed by boundaries j, l = 1..kmax
     *
     */
    private static function pcm_mu_hessian(array $m): array {
        $ddmu = [];
        for ($j = 1; $j <= $m['kmax']; $j++) {
            $ddmu[$j] = [];
            for ($l = 1; $l <= $m['kmax']; $l++) {
                $sum = 0.0;
                for ($k = 0; $k <= $m['kmax']; $k++) {
                    $ij = ($k >= $j) ? 1.0 : 0.0;
                    $il = ($k >= $l) ? 1.0 : 0.0;
                    $ddpk = $m['p'][$k] * (
                        ($m['t'][$l] - $il) * ($m['t'][$j] - $ij)
                        + ($m['t'][$j] * $m['t'][$l] - $m['t'][max($j, $l)])
                    );
                    $sum += $m['fr'][$k] * $ddpk;
                }
                $ddmu[$j][$l] = $sum;
            }
        }
        return $ddmu;
    }

    /**
     * A fixed model dimension is undefined for polytomous models; use the
     * data-driven get_model_dim_from_ip($ip) instead.
     *
     * @return int
     *
     */
    public static function get_model_dim(): int {
        // The number of parameters of a polytomous model depends on the number of
        // response categories in the data, so a fixed dimensionality is undefined.
        // Callers must use the data-driven get_model_dim_from_ip($ip) instead.
        throw new \coding_exception(
            'get_model_dim() is data-driven for polytomous models; use get_model_dim_from_ip($ip).'
        );
    }

    /**
     * Get item parameters.
     *
     * @return model_item_param_list
     */
    public static function get_item_parameters(): model_item_param_list {
        // TODO implement.
        return new model_item_param_list();
    }

    /**
     * Get person abilities.
     *
     * @return model_person_param_list
     */
    public static function get_person_abilities(): model_person_param_list {
        // TODO implement.
        return new model_person_param_list();
    }

    /**
     * Estimate item parameters
     *
     * @param mixed $itemresponse
     * @param ?model_item_param $startvalue
     *
     * @return array
     *
     */
    public function calculate_params($itemresponse, ?model_item_param $startvalue = null): array {
        return catcalc::estimate_item_params($itemresponse, $this, $startvalue);
    }

    /**
     * Calculate the mean difficulty
     *
     * @param array $ip
     *
     * @return float
     *
     */
    public static function calculate_mean_difficulty(array $ip): float {
        $ip['intercepts'] = self::sanitize_fractions($ip['intercepts']);
        $fractions = self::get_fractions($ip['intercepts']);
        $kmax = count($fractions) - 1;

        return ($ip['intercepts'][$fractions[1]] + $ip['intercepts'][$fractions[$kmax]]) / 2;
    }

    // Calculate the Likelihood.

    /**
     * Calculates the Likelihood for a given the person ability parameter
     *
     * @param array $pp - person ability parameter
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $frac - answer fraction (0 ... 1.0)
     * @return float
     */
    public static function likelihood(array $pp, array $ip, float $frac): float {
        $ability = $pp['ability'];

        $a = self::sanitize_fractions($ip['intercepts']);

        $fractions = self::get_fractions($a);
        $kmax = count($fractions) - 1;

        // Calculation the denominator of the formulae.
        $denominator = 0;
        $intercepts = 0;
        for ($k = 0; $k <= $kmax; $k++) {
            $intercepts += ($k == 0) ? (0) : $a[$fractions[$k]];
            $denominator += exp($k * $ability - $intercepts);
        }

        // Calculation the probability.
        $kfrac = self::get_key_by_fractions($frac, $a);
        $intercepts = 0;
        for ($k = 0; $k <= $kfrac; $k++) {
            $intercepts += ($k == 0) ? (0) : ($a[$fractions[$k]]);
        }
        return exp($kfrac * $ability - $intercepts) / $denominator;
    }

    // Calculate the LOG Likelihood and its derivatives.

    /**
     * Calculates the LOG Likelihood for a given the person ability parameter
     *
     * @param array $pp - person ability parameter
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $frac - answer fraction (0 ... 1.0)
     * @return float - log likelihood
     */
    public static function log_likelihood(array $pp, array $ip, float $frac): float {
        return log(self::likelihood($pp, $ip, $frac));
    }

    /**
     * Calculates the 1st derivative of the LOG Likelihood with respect to the item parameters
     *
     * @param array $pp - person ability parameter
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $frac - answer fraction (0 ... 1.0)
     * @return float - 1st derivative of log likelihood with respect to $pp
     */
    public static function log_likelihood_p(array $pp, array $ip, float $frac): float {
        $ability = $pp['ability'];

        $a = self::sanitize_fractions($ip['intercepts']);

        $fractions = self::get_fractions($a);
        $kmax = count($fractions) - 1;

        // Calculation the denominator of the formulae.
        $denominator = 0;
        $firstderivative = 0;
        $intercepts = 0;
        for ($k = 0; $k <= $kmax; $k++) {
            $intercepts += ($k == 0) ? (0) : ($a[$fractions[$k]]);
            $denominator += exp($k * $ability - $intercepts);
            $firstderivative += $k * exp($k * $ability - $intercepts);
        }

        $k = self::get_key_by_fractions($frac, $a);
        return $k - $firstderivative / $denominator;
    }

    /**
     * Calculates the 2nd derivative of the LOG Likelihood with respect to the person ability parameter
     *
     * @param array $pp - person ability parameter
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $frac - answer fraction (0 ... 1.0)
     * @return float - 2nd derivative of log likelihood with respect to $pp
     */
    public static function log_likelihood_p_p(array $pp, array $ip, float $frac): float {
        $ability = $pp['ability'];

        $a = self::sanitize_fractions($ip['intercepts']);

        $fractions = self::get_fractions($a);
        $kmax = count($fractions) - 1;

        // Calculation the denominator of the formulae.
        $denominator = 0;
        $firstderivative = 0;
        $secondderivative = 0;
        $intercepts = 0;
        for ($k = 0; $k <= $kmax; $k++) {
            $intercepts += ($k == 0) ? (0) : ($a[$fractions[$k]]);
            $denominator += exp($k * $ability - $intercepts);
            $firstderivative += $k * exp($k * $ability - $intercepts);
            $secondderivative += $k ** 2 * exp($k * $ability - $intercepts);
        }

        return $firstderivative ** 2 / $denominator ** 2 - $secondderivative / $denominator;
    }

    /**
     * Calculates the 1st derivative of the LOG Likelihood with respect to the item parameters
     *
     * @param array $pp - person ability parameter ('ability')
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $k - answer category (0 or 1.0)
     * @return array - jacobian vector
     */
    public static function get_log_jacobian(array $pp, array $ip, float $k): array {
        // PCM item-parameter score via tail probabilities.
        // With category probabilities P_0..P_M and tail probabilities
        // T_j = sum_{m>=j} P_m, the derivative of the log likelihood w.r.t. the
        // step intercept delta_j is  d/ddelta_j log L = T_j - [r >= j], where r is
        // the observed category. The baseline category (index 0) has no free
        // intercept, so its entry is zero (aligned with the parameter codec).
        [$t, $r, $kmax] = self::pcm_tails($pp, $ip, $k);

        // Free parameters delta_1..delta_M (0-based), baseline excluded.
        $result = [];
        for ($p = 0; $p < $kmax; $p++) {
            $j = $p + 1;
            $indicator = ($r >= $j) ? 1.0 : 0.0;
            $result[$p] = $t[$j] - $indicator;
        }
        return $result;
    }

    /**
     * Category and tail probabilities used by the PCM derivatives.
     *
     * @param array $pp person ability parameter
     * @param array $ip item parameters
     * @param float $frac observed response fraction
     *
     * @return array [tailprobabilities, observedcategory, kmax]
     *
     */
    private static function pcm_tails(array $pp, array $ip, float $frac): array {
        $ability = $pp['ability'];
        $a = self::sanitize_fractions($ip['intercepts']);
        $fractions = self::get_fractions($a);
        $kmax = count($fractions) - 1;

        // Unnormalised category weights exp(k*theta - D_k), D_k cumulative intercepts.
        $cumulative = 0.0;
        $weights = [];
        for ($cat = 0; $cat <= $kmax; $cat++) {
            if ($cat > 0) {
                $cumulative += $a[$fractions[$cat]];
            }
            $weights[$cat] = exp($cat * $ability - $cumulative);
        }
        $z = array_sum($weights);

        $p = [];
        for ($cat = 0; $cat <= $kmax; $cat++) {
            $p[$cat] = $weights[$cat] / $z;
        }

        // Tail probabilities T_j = sum_{cat>=j} P_cat.
        $t = [];
        for ($j = 0; $j <= $kmax; $j++) {
            $sum = 0.0;
            for ($cat = $j; $cat <= $kmax; $cat++) {
                $sum += $p[$cat];
            }
            $t[$j] = $sum;
        }

        $r = self::get_key_by_fractions($frac, $a);
        return [$t, $r, $kmax];
    }

    /**
     * Calculates the 2nd derivative of the LOG Likelihood with respect to the item parameters
     *
     * @param array $pp - person ability parameter ('ability')
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @param float $itemresponse - answer category (0 or 1.0)
     *
     * @return array - hessian matrx
     */
    public static function get_log_hessian(array $pp, array $ip, float $itemresponse): array {
        // PCM item-parameter curvature: H_{j,l} = T_j T_l - T_max(j,l). The baseline
        // category (index 0) has no free intercept, so its row and column are zero.
        [$t, , $kmax] = self::pcm_tails($pp, $ip, $itemresponse);

        // Free parameters delta_1..delta_M (0-based), baseline excluded.
        $result = [];
        for ($pi = 0; $pi < $kmax; $pi++) {
            $result[$pi] = [];
            for ($pj = 0; $pj < $kmax; $pj++) {
                $i = $pi + 1;
                $j = $pj + 1;
                $result[$pi][$pj] = $t[$i] * $t[$j] - $t[max($i, $j)];
            }
        }
        return $result;
    }


    /**
     * Calculate Item and Category-Information.
     *
     * @param array $pp
     * @param array $ip
     *
     * @return float
     *
     */


    /**
     * Return the fisher information
     *
     * @param array $pp
     * @param array $ip
     *
     * @return float
     * TOOO: renam fisher_info into item_information, until than this acts as an alias.
     */
    public function fisher_info(array $pp, array $ip): float {
        return self::item_information($pp, $ip);
    }

    /**
     * Return category information
     *
     * @param array $pp
     * @param array $ip
     * @param float $frac
     *
     * @return float
     */
    public static function category_information(array $pp, array $ip, float $frac): float {
        return -(self::log_likelihood_p_p($pp, $ip, $frac));
    }

    /**
     * Return item information
     *
     * @param array $pp
     * @param array $ip
     *
     * @return float
     */
    public static function item_information(array $pp, array $ip): float {
        $iif = self::category_information($pp, $ip, 0.0) * self::likelihood($pp, $ip, 0.0);
        // Ralf hab ich von $ip['difficulty'] geändert.
        foreach ($ip['intercepts'] as $f => $val) {
            $iif += self::category_information($pp, $ip, $f) * self::likelihood($pp, $ip, $f);
        }
        return $iif;
    }

    // Implements handling of the Trusted Regions (TR) approach.

    /**
     * Implements a Filter Function for trusted regions in the item parameter estimation
     *
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @return array - chunked item parameter
     */
    public static function restrict_to_trusted_region(array $ip): array {
        // Clamp each free threshold to a sensible range; keep discrimination
        // positive. The baseline entry stays 0 (re-inserted by the codec).
        $min = -5.0;
        $max = 5.0;
        foreach ($ip['intercepts'] as $fraction => $value) {
            $ip['intercepts'][$fraction] = max($min, min($max, $value));
        }
        return $ip;
    }

    /**
     * Calculates the 1st derivative trusted regions for item parameters
     *
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     * @return array - 1st derivative of TR function with respect to $ip
     */
    public static function get_log_tr_jacobian($ip): array {
        // Set values for difficulty parameter.

        // TODO: @DAVID: Diese Werte sollten dynamisch berechnet werden können.
        $am = 0; // Mean of difficulty.
        $as = 2; // Standard derivation of difficulty.

        // Placement of the discriminatory parameter.
        $bp = floatval(get_config('catmodel_raschbirnbaumb', 'trusted_region_placement_b'));
        // Slope of the discriminatory parameter.
        $bs = floatval(get_config('catmodel_raschbirnbaumb', 'trusted_region_slope_b'));

        return [
        ($am - $ip['difficulty']) / ($as ** 2), // Calculates d/da.
        -($bs * exp($bs * $ip['discrimination'])) / (exp($bs * $bp) + exp($bs * $ip['discrimination'])), // Calculates d/db.
        ];
    }

    /**
     * Calculates the 2nd derivative trusted regions for item parameters
     *
     * @param array $ip - item parameters ('difficulty', 'discrimination')
     *
     * @return array - 2nd derivative of TR function with respect to $ip
     */
    public static function get_log_tr_hessian(array $ip): array {
        // Set values for difficulty parameter.

        // TODO: @DAVID: Diese Werte sollten dynamisch berechnet werden können.
        $am = 0; // Mean of difficulty.
        $as = 2; // Standard derivation of difficulty.

        // Placement of the discriminatory parameter.
        $bp = floatval(get_config('catmodel_raschbirnbaumb', 'trusted_region_placement_b'));
        // Slope of the discriminatory parameter.
        $bs = floatval(get_config('catmodel_raschbirnbaumb', 'trusted_region_slope_b'));

        return [
            [
                -1 / ($as ** 2), // Calculates d²/da².
                0, // Calculates d/da d/db.
            ],
            [
                0, // Calculates d/da d/db.
                -($bs ** 2 * exp($bs * ($bp + $ip['discrimination']))) /
                    (exp($bs * $bp) + exp($bs * $ip['discrimination'])) ** 2, // Calculates d²/db².
            ],
        ];
    }

    /**
     * Retrieve the name of the multiple parameter.
     *
     * This method returns the string 'intercepts', which is used as
     * the name of the multiple parameter in this context.
     *
     * @return string The name of the multiple parameter.
     */
    protected function get_multi_param_name(): string {
        return 'intercepts';
    }

    /**
     * Get default params
     *
     * @return array
     */
    public function get_default_params(): array {
        return [
            'discrimination' => 0.0,
            'intercepts' => [
                '0.00' => 0.0,
                '0.50' => 0.5,
                '1.00' => 1.0,
            ],
        ];
    }

    /**
     * Adds a new combination of itemparams
     *
     * @param array $existingparams
     * @param \stdClass $new
     * @return array
     */
    public function add_new_param(array $existingparams, stdClass $new): array {
        $num = count($existingparams['intercepts']) + 1;
        $difficultyprop = sprintf('difficulty_%d', $num);
        $fractionprop = sprintf('fraction_%d', $num);
        $newintercepts = $existingparams['intercepts'] + [$new->$fractionprop => $new->$difficultyprop];
        $newparams['intercepts'] = $newintercepts;
        $newparams['difficulty'] = self::calculate_mean_difficulty($newparams);
        return $newparams;
    }

    /**
     * Drops the itemparams at the given index
     *
     * @param array $existingparams
     * @param int $index
     * @return array
     */
    public function drop_param_at(array $existingparams, int $index): array {
        $counter = 0;
        $newintercepts = array_filter(
            $existingparams['intercepts'],
            function ($v) use (&$counter, $index) {
                $match = $counter == $index;
                $counter++;
                return !$match;
            }
        );
        $newparams['intercepts'] = $newintercepts;
        $newparams['difficulty'] = self::calculate_mean_difficulty($newparams);
        return $newparams;
    }
}
