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
 * Class for catcalc functions;
 *
 * @package local_catquiz
 * @author Daniel Pasterk
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_model;
use local_catquiz\local\model\model_strategy;
use local_catquiz\mathcat;

class catcalc {

    static function estimate_initial_item_difficulties($item_list) {

        $item_difficulties = array();
        $item_ids = array_keys($item_list);

        foreach ($item_ids as $id) {

            $item_fractions = $item_list[$id];
            $num_passed = 0;
            $num_failed = 0;

            foreach ($item_fractions as $fraction) {
                if ($fraction == 1) {
                    $num_passed += 1;
                } else {
                    $num_failed += 1;
                }
            }

            $p = $num_passed / ($num_failed + $num_passed);
            //$item_difficulty = -log($num_passed / $num_failed);
            $item_difficulty = -log($p / (1 - $p + 0.00001)); //TODO: numerical stability check
            $item_difficulties[$id] = $item_difficulty;

        }
        return $item_difficulties;
    }

    static function estimate_person_ability($demo_person_response, model_item_param_list $items): float {
       $all_models = model_strategy::get_installed_models();

        $likelihood = fn($x) => 1;
        $loglikelihood = fn($x) => 0;
        $loglikelihood_1st_derivative = fn($x) => 0;
        $loglikelihood_2nd_derivative = fn($x) => 0;

        foreach ($demo_person_response as $qid => $qresponse) {
            $item = $items[$qid];
            // The item parameter for this response was filtered out
            if ($item === null) {
                continue;
            }
            $item_params = $item->get_params_array();

            /**
             * @var catcalc_ability_estimator
             */
            $model = $all_models[$item->get_model_name()];
            if (!in_array(catcalc_ability_estimator::class, class_implements($model))) {
                throw new \Exception(sprintf("The given model %s can not be used with the catcalc class", $item->get_model_name()));
            }

            $likelihood_part = fn ($x) => $model::likelihood($x, $item_params, $qresponse['fraction']);
            $loglikelihood_part = fn ($x) => $model::log_likelihood($x, $item_params, $qresponse['fraction']);
            $loglikelihood_1st_derivative_part = fn ($x) => $model::log_likelihood_p($x, $item_params, $qresponse['fraction']);
            $loglikelihood_2nd_derivative_part = fn ($x) => $model::log_likelihood_p_p($x, $item_params, $qresponse['fraction']);

            $likelihood = fn ($x) => $likelihood($x) * $likelihood_part($x);
            $loglikelihood = fn ($x) => $loglikelihood($x) + $loglikelihood_part($x);
            $loglikelihood_1st_derivative = fn ($x) => $loglikelihood_1st_derivative($x) + $loglikelihood_1st_derivative_part($x);
            $loglikelihood_2nd_derivative = fn ($x) => $loglikelihood_2nd_derivative($x) + $loglikelihood_2nd_derivative_part($x);
        }

        $retval = mathcat::newtonraphson_stable(
            $loglikelihood_1st_derivative,
            $loglikelihood_2nd_derivative,
            0,
            0.001,
            1500
        );

        return $retval;
    }

    /**
     * @param array $item_response
     * @param model_model $model
     * @return array<float>
     */
    static function estimate_item_params(array $item_response, model_model $model) {
        if (! $model instanceof catcalc_item_estimator) {
            throw new \InvalidArgumentException("Model does not implement the catcalc_item_estimator interface");
        }

        // compose likelihood matrices based on actual result

        $model_dim = $model::get_model_dim();

        // Vector that contains the first derivatives for each parameter as functions
        // [Df/Da, Df,/Db, Df,Dc]
        $jacobian = [];
        // Matrix that contains the second derivatives
        // [
        //  [Df/Daa, Df/Dab, Df/Dac]
        //  [Df/Dba, Df/Dbb, Df/Dbc]
        //  [Df/Dca, Df/Dcb, Df/Dcc]
        // ]
        $hessian = [];
        for ($i = 0; $i <= $model_dim - 2; $i++) {
            $jacobian[$i] = fn($x) => 0;
            $hessian[$i] = [];
            for ($j = 0; $j <= $model_dim - 2; $j++) {
                $hessian[$i][$j] = fn($x) => 0;
            }
        }

        foreach ($item_response as $r) {
            $jacobian_part = $model::get_log_jacobian($r->get_ability(), $r->get_response());
            $hessian_part = $model::get_log_hessian($r->get_ability(), $r->get_response());

            for ($i=0; $i <= $model_dim-2; $i++){
                $jacobian[$i] = fn($x) => $jacobian[$i]($x) + $jacobian_part[$i]($x);

                for ($j=0; $j <= $model_dim-2; $j++) {
                    $hessian[$i][$j] = fn($x) => $hessian[$i][$j]($x) + $hessian_part[$i][$j]($x);
                }
            }
        }

        // Defines the starting point
        $start_arr = ['difficulty' => 0.5, 'discrimination' => 0.5, 'guessing' => 0.5];
        $z_0 = array_slice($start_arr, 0, $model_dim-1);

        return mathcat::newton_raphson_multi_stable($jacobian,$hessian,$z_0, 0.001, 50);
    }
}
