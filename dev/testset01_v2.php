<?php
use local_catquiz\catcontext;
use local_catquiz\local\model\model_item_param;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_person_param;
use local_catquiz\local\model\model_person_param_list;
use local_catquiz\local\model\model_responses;
use local_catquiz\local\model\model_strategy;

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
 *  Code for validation of developing process;
 *
 * @package local_catquiz
 * @author Daniel Pasterk
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
//use \local_catquiz;

$PAGE->set_url(new moodle_url('/local/catquiz/workspace.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('frontpage');
$url_front = new moodle_url('/workspace.php');
$url_plugin = new moodle_url('workspace.php');

echo $OUTPUT->header();

$demo_person_abilities = [-1.46, 2.17, 0.06, -0.13, 0.82, -0.83, -0.85, 3.14, -0.81, 1.7, 1.34, 1.45, -1.45, -1.93, 2.01, -0.86, 2.19, 0.78, 0.36, -0.06, -2.84, -0.31, -1.56, 1.49, 0.67, 0.59, -1.6, 1.0, -1.2, 1.82, 0.72, 0.54, -1.04, 1.25, 1.47, 0.11, -1.6, -1.56, -1.02, -1.54, 0.4, -0.1, 1.39, 1.24, -0.08, 1.48, -0.47, 0.72, -0.33, -0.49, 0.17, -0.26, 1.48, 0.97, 0.53, -3.59, -2.45, -1.12, -2.28, -0.62, 0.89, -0.59, 1.67, -1.45, -0.73, -2.25, -0.33, 1.86, -0.79, -0.87, 2.87, 0.1, 0.59, -0.41, 0.4, -1.04, -0.87, -0.64, 1.46, -0.59, -0.21, -1.8, -1.02, -1.87, -2.33, -3.34, -0.7, 1.24, 0.07, 1.4, 0.42, -0.26, 2.56, 1.21, 2.46, -3.47, -1.22, 0.65, 1.09, 0.66, 0.14, -2.27, 1.77, -3.61, -1.37, 0.8, -0.26, 0.48, 0.86, 0.86, -1.67, 1.3, -1.26, 0.35, 2.31, -1.78, 1.8, -0.84, -0.76, 1.7, -1.3, -1.82, 0.89, -0.36, -1.36, -0.23, 0.68, 0.86, -1.08, -0.73, 0.26, -0.8, -0.7, 1.98, 1.26, -1.44, -1.29, -2.2, 0.68, 0.05, -0.62, 0.84, 2.49, 0.69, -0.44, -0.32, 1.95, 0.98, -1.29, 0.92, -0.13, 2.31, -2.46, -1.27, 0.24, -1.0, 0.2, 1.57, 0.77, 0.52, 1.07, -0.17, -0.96, 0.01, -1.24, 1.64, 0.57, 0.07, -0.11, 1.46, 0.03, -1.03, 0.14, 0.98, 1.2, -1.84, 1.54, 3.05, 1.78, 0.05, 0.34, -0.16, -0.38, 2.51, 0.46, 0.92, -0.89, -1.53, 1.54, -1.9, -1.11, 0.04, -3.29, -0.94, 0.4, -1.35, -0.07, 0.84, -2.18, -1.82, -2.42, -0.76, 1.42, -0.63, 0.55, -1.48, -1.51, 0.51, -1.89, -2.53, -1.64, -1.99, 0.21, -1.4, -1.66, 0.71, 2.16, 1.55, 0.51, -2.41, 1.89, -1.7, -0.33, -0.63, -0.05, -1.9, 0.5, 2.54, 0.22, -0.41, 0.4, -1.68, 0.83, 0.55, -0.8, -0.04, 1.88, 2.48, 0.39, 0.76, -0.28, -1.06, -0.74, -1.38, -0.33, 0.27, -0.79, 0.66, 0.27, 0.1, -4.67, -1.16, -1.92, 1.17, -0.45, -1.86, -0.14, 1.16, -0.87, 0.07, 1.0, -0.6, -1.27, -1.38, -3.22, -0.18, 0.07, -0.48, 1.79, -2.98, 0.73, 0.02, -0.34, 1.5, -1.29, -1.84, 0.85, -0.22, -3.37, 0.67, -2.0, -1.22, 1.52, -3.64, 1.59, 1.86, -2.57, -2.26, 0.84, 0.86, -2.07, 2.0, 0.17, 0.61, 0.88, -0.37, -0.81, 0.16, -0.01, 0.3, -1.43, -0.11, 1.7, 0.61, 1.88, 1.79, 0.82, 1.75, 2.05, 1.54, -1.69, -0.91, 1.16, -1.96, 1.73, 3.68, -0.02, 1.08, 3.86, -0.89, 2.23, 2.29, 0.55, -0.52, -1.1, -1.85, -1.22, -1.03, -0.53, 0.82, 3.27, -0.87, 0.17, 1.93, -1.94, 0.56, -0.8, -0.17, 0.44, -1.1, 1.32, -0.17, 1.69, -1.23, -0.96, -0.19, -1.28, -1.12, 1.09, -0.06, 0.55, -0.31, -0.53, -0.13, -0.09, -1.13, 0.04, 2.54, 0.27, 0.97, 0.79, 0.1, -2.01, 0.16, -0.13, 0.77, 3.34, 0.42, 3.12, 0.27, 0.95, 0.58, 0.06, -0.35, -0.57, 0.2, 1.26, 0.15, 1.02, 2.34, 2.84, 0.73, 0.51, -2.75, -0.6, 1.54, 2.99, 0.37, -0.34, -1.09, -0.4, 0.93, -3.87, -1.21, 0.21, -3.14, 0.1, 1.96, 0.65, -0.24, -0.57, -2.03, 0.29, -1.18, 1.56, -0.26, -2.83, -1.96, -0.28, 0.88, -0.2, 0.58, 1.53, 0.07, 1.79, 3.38, 2.47, 3.67, -0.74, -0.04, -0.55, 1.17, 2.46, 0.39, 3.4, -0.05, -0.59, -1.99, -1.17, 2.36, 0.14, 0.31, 1.42, 0.77, 2.66, -4.73, -2.31, -1.42, -2.66, -0.02, -1.58, -0.78, 1.43, -0.85, 0.77, 1.44, -0.2, 1.41, 0.83, -2.67, -0.72, 0.59, -0.14, -1.8, 1.53, 1.65, 2.08, 1.88, -0.76, 2.93, 2.03, 0.7, 0.9, 1.7, 0.53, 1.83, -0.09, -0.35, 1.32, -0.52, 1.01, -0.93, -1.08, 3.95, 0.57, 0.07, 0.07, -1.12, -0.49, 0.71, -2.64, 0.42, -1.22, 0.48, 1.16, -0.9, 1.9, -1.16, -0.19, 0.12, 0.42, -0.76, 0.05, 0.34, -0.87, -1.23, 0.5, -0.02, -1.96, 2.46, -0.65, 0.37, -0.05, 1.45, 0.04, -1.62, -3.41, 1.57, -0.06, -0.7, -0.68, 0.47, 1.01, 0.07, 2.24, -2.47, -1.04, -2.39, -2.06, 0.53, -2.64, 0.35, -1.79, 2.2, 0.09, 1.48, 1.29, -1.93, 1.6, 0.35, 0.87, -0.91, -0.46, 1.06, 1.88, 0.74, 1.38, 0.42, -1.07, -0.74, -0.85, 0.44, 2.78, -1.03, 1.99, -1.16, -1.14, 1.58, 0.04, -1.52, 0.97, -1.05, -0.04, 0.46, -1.11, 1.13, -1.89, 2.08, 1.19, 1.71, -0.13, -0.56, -1.54, 0.85, -1.42, 1.18, 0.49, -0.88, 0.14, -3.31, 1.31, -2.07, 1.44, -2.07, -0.62, -2.46, 0.62, 0.67, -0.96, 0.04, -1.33, 0.51, -0.07, -3.69, 0.66, -0.85, -1.73, -1.42, -0.18, -0.37, 0.35, -3.3, 2.99, 1.82, -0.36, 1.25, 2.59, -0.56, -0.91, -0.39, -0.55, 2.5, 1.38, 1.21, 0.8, -2.88, -0.27, -0.24, 2.3, -0.85, 0.37, -0.65, -0.44, -3.01, 2.24, 1.14, 1.51, 0.07, -2.28, 0.2, 1.37, -0.46, -0.61, 3.43, 0.72, -2.21, -1.98, 0.86, 0.74, 0.59, -2.41, 0.2, -3.03, -1.98, -3.2, 0.2, -2.02, 0.99, 0.53, -0.42, -0.6, -1.23, -0.31, 0.97, 2.8, 0.47, -0.94, -0.39, -0.36, -0.44, 2.33, -5.77, -2.33, 2.56, -0.36, 0.96, 0.7, 2.24, -0.53, 0.22, -2.07, 0.77, -0.01, -3.45, -0.8, 0.11, -1.05, 0.9, -0.8, 1.0, 0.88, -2.94, -0.1, -1.55, -0.36, -0.39, 2.01, -0.81, -0.16, -3.31, -1.34, 0.82, -0.31, -0.68, -1.65, 0.48, -0.04, 1.07, -1.11, 0.64, -1.09, 0.82, 0.77, -1.73, -0.13, -3.21, 0.6, 1.46, 0.48, 0.43, -0.78, 1.72, 0.08, -3.4, -0.05, 0.21, 1.77, -1.89, -0.27, 0.65, 2.18, 0.99, 1.87, -2.26, 0.23, -0.05, -0.6, 0.72, -1.94, -1.85, 2.95, 2.51, 0.86, 0.0, 1.12, -1.89, 0.24, -0.17, -1.24, 0.11, -1.02, -1.71, -2.08, 0.21, -0.15, 1.15, -0.34, -0.49, -0.3, -1.74, -1.02, -1.36, 0.18, -0.59, 2.93, 0.32, -0.38, 0.32, -0.4, 0.18, 1.0, -0.19, 0.02, -2.83, -2.57, 2.95, 0.15, -2.15, -0.61, -1.21, 0.38, -1.07, -0.51, 1.73, -2.25, 1.58, 0.3, 0.96, -1.46, -2.66, -1.63, 0.28, 0.06, -0.84, 3.06, 3.56, -0.64, -1.16, 2.62, -0.31, -2.81, -0.38, -1.79, -0.91, 0.0, 0.23, 0.49, 2.24, -0.72, -0.75, -0.29, 0.88, 2.07, -0.48, 0.85, 0.13, 1.68, 1.03, 1.13, 1.28, -0.36, -1.73, 0.68, -3.0, -3.53, 0.35, 0.39, -2.17, 0.66, -2.22, -1.39, -0.32, -2.18, -0.6, -1.04, -1.8, -1.6, 1.05, 0.66, 2.08, -1.34, 1.68, 3.06, 1.18, 3.32, 0.1, 0.65, 2.06, -1.01, 2.27, 0.85, -1.34, 0.34, -2.09, -0.33, -0.8, -0.4, 1.92, 0.71, 1.19, -2.05, -2.77, 1.19, -0.24, -0.43, -0.56, -1.02, 3.44, 0.04, -0.12, 0.88, -1.76, -2.79, 0.01, 3.44, -1.45, -0.95, -3.42, 0.08, 1.33, -1.6, -2.26, -1.31, -2.58, -1.8, 0.66, 1.01, -1.35, -1.16, -0.94, -1.36, 3.11, -1.85, -0.92, -0.77, 1.14, 0.02, -0.69, 2.03, 1.52, -1.69, 1.02, 0.13, 0.89, 0.54, 0.11, 0.65, 2.42, 0.99, -3.11, -1.51, 0.06, 1.0, -0.58, -3.24, -0.59, -0.44, -1.0, 0.47, -2.01, -0.68, -0.69, -0.33, -1.07, -1.19, 0.93, 2.05, 1.53, -0.84, 0.44, 0.04, 0.42, 2.11, -2.57, -1.67, -2.77, -0.07, 1.1, 1.06, -1.03, -0.02, -1.08, -1.37, 1.4, -1.82, -0.99, -0.8, 1.52, 0.55, -0.56, -0.5, 0.42, -1.22, -0.19, 0.28, -0.79, 1.43, -1.76, -2.08, -1.09, -1.15, 4.1, -1.55, 0.59, -1.63, -0.37, 1.77, 2.29, 0.51, 0.69, 1.5, 2.2, -1.04, -0.15, -0.12, -1.42, -2.3, -0.83, -0.89, -3.13, 2.83, -0.33, 0.89, 0.94, 4.48, 1.61, -1.99, 2.85, -1.67, 0.36, -0.98, 0.52, 0.09, 0.63, -0.09, 0.84, 0.1, -1.91, 0.58, 3.15, -2.88, -2.88, 1.1, 0.16, -1.39, 0.83, 0.02, -2.31, -0.17, 2.05, 0.98, 1.99, -0.76, -0.2, -1.97, -2.25, -0.09, 0.33, -0.88, 0.8];
$demo_item_difficulties = [1.53, 2.74, -0.74, 0.89, 1.23, 0.68, -0.13, 0.33, 0.21, -1.31] ;
$demo_item_discriminations = [0.5, 1.3, 3.8, -2, -0.25, 1.4, 3.0, -2.0, 0.4, -0.5];
$demo_item_guessing = [0.5, 0.2, 0.4, 0.2, 0.1, 0.8, 0.2, 0.23, 0.1, 0];


$demo_persons = local_catquiz\synthcat::generate_persons($demo_person_abilities);
$demo_items = local_catquiz\synthcat::generate_test_items_multi([$demo_item_discriminations, $demo_item_difficulties, $demo_item_guessing]);

$response = new model_responses();
$raschbb = new \catmodel_raschbirnbaumc\raschbirnbaumc($response,"RaschBB_3PL");

$demo_response = local_catquiz\synthcat::generate_response_multi($demo_persons,$demo_items,$raschbb);


echo "pause";

//generate_test_items


// generate items and persons based on constant parameter


//$demo_items = local_catquiz\synthcat::generate_test_items($demo_item_difficulties);




//
//
//$something = \local_catquiz\mytestclass::testtest();
//
//$response = new model_responses();
//
////$myraschbirnbaum = \catmodel_raschbirnbaumb\raschbirnbaumb::get_stuff();
//$myraschbirnbaum = new \catmodel_raschbirnbaumb\raschbirnbaumb($response,"RaschBB_2PL");


//$testfn = $myraschbirnbaum->get_log_jacobian(0.3);




//
//
//$a = \local_catquiz\catcalc::estimate_item_params_new();
//



//
//$stuff = $myraschbirnbaum->get_stuff();
//




// estimate parameter



//
////
////
$responses = model_responses::create_from_array($demo_response);

$rasch_pl1 = new \catmodel_raschbirnbauma\raschbirnbauma($responses,"RaschBB_1PL");




// brauchen datenstrukturen für den Algo:


// responses

//$strategie = new model_strategy($responses, ['max_iterations' => 4, 'model_override' => 'raschbirnbauma']);

$initial_person_abilities = $responses->get_initial_person_abilities();


$item_response = $responses->get_item_response($initial_person_abilities);




$synth_item_response = \local_catquiz\synthcat::get_item_response2(80,120,0.2);


$x = \local_catquiz\catcalc::estimate_item_params($synth_item_response,$rasch_pl1);



//$my_item_response =


//$max_iterations = 1;
//$strategy = new model_strategy($responses, ['max_iterations' => 4, 'model_override' => 'raschbirnbauma']);
//list($item_difficulties, $person_abilities) = $strategy->run_estimation();

echo "finished";

echo $OUTPUT->footer();



//validation:
// $raschbb->get_log_jacobian(0.2)[2]([0.5,0.5,0.5])