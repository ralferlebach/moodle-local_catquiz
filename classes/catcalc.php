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

use catmodel_raschbirnbaumb\raschbirnbaumb;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_item_response;

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

    static function estimate_initial_item_difficulty($item_response) {

        $item_responses = $item_response['responses'];
        $num_passed = 0;
        $num_failed = 0;

        foreach ($item_responses as $fraction) {
            if ($fraction == 1) {
                $num_passed += 1;
            } else {
                $num_failed += 1;
            }
        }

        $p = $num_passed / ($num_failed + $num_passed);

        //$item_difficulty = -log($num_passed / $num_failed);
        $item_difficulty = -log($p / (1 - $p + 0.00001)); //TODO: numerical stability check
        return $item_difficulty;
    }

    static function estimate_person_ability($demo_person_response, model_item_param_list $item_difficulties) {

        $likelihood_parts = [];
        $dbg_trace1 = [];
        $dbg_trace2 = [];
        $dbg_trace3 = [];

        $likelihood = function($x) {
            return 1;
        };
        $loglikelihood = function($x) {
            return 0;
        };
        $loglikelihood_1st_derivative = function($x) {
            return 0;
        };
        $loglikelihood_2nd_derivative = function($x) {
            return 0;
        };

        foreach ($demo_person_response as $qid => $qresponse) {

            //$item_difficulty = 0.5;
            $item_difficulty = $item_difficulties[$qid]->get_params_array()[0];

            if ($qresponse['fraction'] == 1) {
                $likelihood_part = function($x) use ($item_difficulty) {
                    return \catmodel_raschbirnbauma\raschmodel::likelihood($x, $item_difficulty);
                };
                $loglikelihood_part = function($x) use ($item_difficulty) {
                    return \catmodel_raschbirnbauma\raschmodel::log_likelihood($x, $item_difficulty);
                };
                $loglikelihood_1st_derivative_part = function($x) use ($item_difficulty) {
                    return \catmodel_raschbirnbauma\raschmodel::log_likelihood_1st_derivative($x, $item_difficulty);
                };
                $loglikelihood_2nd_derivative_part = function($x) use ($item_difficulty) {
                    return \catmodel_raschbirnbauma\raschmodel::log_likelihood_2nd_derivative($x, $item_difficulty);
                };

                array_push($likelihood_parts, $likelihood_part);
                array_push($dbg_trace1, $qresponse['fraction']);
                array_push($dbg_trace2, $likelihood_part(0.3));

                $likelihood = \local_catquiz\mathcat::compose_multiply($likelihood, $likelihood_part);
                $loglikelihood = \local_catquiz\mathcat::compose_plus($loglikelihood, $loglikelihood_part);
                $loglikelihood_1st_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_1st_derivative, $loglikelihood_1st_derivative_part);
                $loglikelihood_2nd_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_2nd_derivative, $loglikelihood_2nd_derivative_part);

                array_push($dbg_trace3, $likelihood(0.3));

            } else if ($qresponse['fraction'] == 0) {
                $likelihood_part = function($x) use ($item_difficulty) {
                    return \catmodel_raschbirnbauma\raschmodel::likelihood_counter($x, $item_difficulty);
                };

                $loglikelihood_part = function($x) use ($item_difficulty) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_counter($x, $item_difficulty));
                };
                $loglikelihood_1st_derivative_part = function($x) use ($item_difficulty) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_counter_1st_derivative($x, $item_difficulty));
                };
                $loglikelihood_2nd_derivative_part = function($x) use ($item_difficulty) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_counter_2nd_derivative($x, $item_difficulty));
                };

                array_push($likelihood_parts, $likelihood_part);
                array_push($dbg_trace1, $qresponse['fraction']);

                $likelihood = \local_catquiz\mathcat::compose_multiply($likelihood, $likelihood_part);

                $loglikelihood = \local_catquiz\mathcat::compose_plus($loglikelihood, $loglikelihood_part);
                $loglikelihood_1st_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_1st_derivative, $loglikelihood_1st_derivative_part);
                $loglikelihood_2nd_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_2nd_derivative, $loglikelihood_2nd_derivative_part);

                array_push($dbg_trace2, $likelihood_part(0.3));
                array_push($dbg_trace3, $likelihood(0.3));

            };
        }

        $likelihood_1st_derivative = \local_catquiz\mathcat::get_numerical_derivative2($likelihood);
        $likelihood_2nd_derivative = \local_catquiz\mathcat::get_numerical_derivative2($likelihood_1st_derivative);

        $loglike2nd_complete = \local_catquiz\mathcat::get_numerical_derivative($loglikelihood_1st_derivative);

        $num_log_like_1st_der = \local_catquiz\mathcat::get_numerical_derivative($loglikelihood);
        $num_log_like_2nd_der = \local_catquiz\mathcat::get_numerical_derivative($num_log_like_1st_der);

        $estimated_value =
                \local_catquiz\mathcat::newtonraphson($loglikelihood_1st_derivative, $loglikelihood_2nd_derivative, 0, 0.001, 1500);
        //$estimated_value2 = \local_catquiz\mathcat::newtonraphson($likelihood_1st_derivative, $likelihood_2nd_derivative,0.7,0.001,1500);
        //$estimated_value3 = \local_catquiz\mathcat::newtonraphson($num_log_like_1st_der, $num_log_like_2nd_der,0,0.001,1500);
        //$estimated_value4 = \local_catquiz\mathcat::newtonraphson_numeric($likelihood_1st_derivative,0,0.001,1500);

        return $estimated_value;
    }

    /**
     * @var array<model_item_response> $item_response
     */
    static function estimate_item_difficulty(array $item_response): float {

        #$item_response = $demo_item_response[$item_id];
        $dbg_trace4 = [];
        $loglikelihood_1st_derivative = function($x) {
            return 0;
        };
        $loglikelihood_2nd_derivative = function($x) {
            return 0;
        };

        $num_passed = 0;
        $num_failed = 0;
        foreach ($item_response as $r) {

            // compose likelihood
            $tmp_response = $r->get_response();
            $tmp_ability = $r->get_ability();

            //$tmp_ability = 0.1; // dev override

            $dbg_trace4[] = $tmp_response;

            if ($tmp_response == 1) {
                $loglikelihood_1st_derivative_part = function($x) use ($tmp_ability) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_1st_derivative_item($tmp_ability, $x));
                };
                $loglikelihood_2nd_derivative_part = function($x) use ($tmp_ability) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_2nd_derivative_item($tmp_ability, $x));
                };
                $num_passed += 1;

                $loglikelihood_1st_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_1st_derivative, $loglikelihood_1st_derivative_part);
                $loglikelihood_2nd_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_2nd_derivative, $loglikelihood_2nd_derivative_part);

            } else {
                $loglikelihood_1st_derivative_part = function($x) use ($tmp_ability) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_counter_1st_derivative_item($tmp_ability, $x));
                };
                $loglikelihood_2nd_derivative_part = function($x) use ($tmp_ability) {
                    return (\catmodel_raschbirnbauma\raschmodel::log_likelihood_counter_2nd_derivative_item($tmp_ability, $x));
                };
                $num_failed += 1;

                $loglikelihood_1st_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_1st_derivative, $loglikelihood_1st_derivative_part);
                $loglikelihood_2nd_derivative =
                        \local_catquiz\mathcat::compose_plus($loglikelihood_2nd_derivative, $loglikelihood_2nd_derivative_part);
            }
        }

        // estimate item parameter
        $loglikelihood_2nd_derivative_num = \local_catquiz\mathcat::get_numerical_derivative($loglikelihood_1st_derivative);
        $estimated_value =
                \local_catquiz\mathcat::newtonraphson($loglikelihood_1st_derivative, $loglikelihood_2nd_derivative, 0, 0.001, 1500);
        $estimated_value2 =
                \local_catquiz\mathcat::newtonraphson($loglikelihood_1st_derivative, $loglikelihood_2nd_derivative_num, 0, 0.001,
                        1500);
        return $estimated_value;
    }




    //
    //static function compose_log_likelihood(array $item_response){
    //
    //}
    //

    /**
     * @param array $item_response
     * @param model_model $model
     * @return array<float>
     */
    static function estimate_item_params(array $item_response, $model) {
        // The current function should be changed to work with all models, but
        // for now we just forward the calculation of the item difficulty for
        // the 'raschbirnbauma' model to a different function
        if ($model->get_model_name() === 'raschbirnbauma') {
            return self::estimate_item_difficulty($item_response);
        }

        // compose likelihood matrices based on actual result

        $model_dim = $model->get_model_dim();


        // empty callable structures for composition

        $loglikelihood = function($x) {
            return 0;
        };

        $jacobian = [];
        $hessian = [];
        for ($i = 0; $i <= $model_dim - 2; $i++) {
            $jacobian[$i] = function($x) {
                return 0;
            };
            $hessian[$i] = [];
            for ($j = 0; $j <= $model_dim - 2; $j++) {
                $hessian[$i][$j] = function($x) {
                    return 0;
                };
            }
        }

        $num_passed = 0;
        $num_failed = 0;
        foreach ($item_response as $r) {

            // compose likelihood
            $tmp_response = $r->get_response();
            $tmp_ability = $r->get_ability();

            if ($tmp_response == 1) { // if answer is correct
                $num_passed += 1;

                $likelihood_part = $model->get_log_likelihood($tmp_ability);
                $jacobian_part = $model->get_log_jacobian($tmp_ability);
                $hessian_part = $model->get_log_hessian($tmp_ability);

            } else {
                $num_failed += 1;

                $likelihood_part = $model->get_log_counter_likelihood($tmp_ability);
                $jacobian_part = $model->get_log_counter_jacobian($tmp_ability);
                $hessian_part = $model->get_log_counter_hessian($tmp_ability);
            }

            // chain with functions
            $loglikelihood = mathcat::compose_plus($loglikelihood, $likelihood_part);

            for ($i=0; $i <= $model_dim-2; $i++){
                $jacobian[$i] = mathcat::compose_plus($jacobian[$i], $jacobian_part[$i]);

                for ($j=0; $j <= $model_dim-2; $j++) {
                    $hessian[$i][$j] = mathcat::compose_plus($hessian[$i][$j], $hessian_part[$i][$j]);
                }
            }
        }

        // Defines the starting point
        $start_arr = [0.5, 0.5, 0.5];
        $z_0 = array_slice($start_arr, 0, $model_dim-1);

        $params = \local_catquiz\mathcat::newton_raphson_multi($jacobian,$hessian,$z_0);
        return $params;
    }
}

