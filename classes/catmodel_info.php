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
* Entities Class to display list of entity records.
*
* @package local_catquiz
* @author Georg Maißer
* @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
* @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_catquiz;

use coding_exception;
use core_plugin_manager;
use local_catquiz\data\catquiz_base;
use local_catquiz\local\model\model_calc;
use local_catquiz\local\model\model_item_param;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_model;
use local_catquiz\local\model\model_person_ability_estimator_demo;
use local_catquiz\local\model\model_person_param;
use local_catquiz\local\model\model_person_param_list;
use local_catquiz\local\model\model_responses;
use local_catquiz\local\model\model_strategy;

/**
 * This class
 */
class catmodel_info {


    /**
     * Undocumented function
     *
     * @param integer $catcontext
     * @param integer $testitemid
     * @param string $component
     * @param string $model
     * @return array
     */
    public function get_item_parameters(int $catcontext = 0):array {
        $returnarray = [];

        // Retrieve all the responses in the given context.
        list($itemparamsbymodel, $personparamsbymodel) = $this->get_context_parameters($catcontext);
        foreach ($itemparamsbymodel as $model => $itemparams) {
            $returnarray[$model] = [
                'itemparams' => $itemparams,
                'personparams' => $personparamsbymodel
            ];
        }

        return $returnarray;
    }

    /**
     * Returns classes of installed models, indexed by the model name
     * 
     * @return array<string>
     */
    private static function get_installed_models(): array {
        $pm = core_plugin_manager::instance();
        $models = [];
        foreach($pm->get_plugins_of_type('catmodel') as $name => $info) {
                $classname = sprintf('catmodel_%s\%s', $name, $name);
                if (!class_exists($classname)) {
                    continue;
                }
                $models[$name] = $classname;
        }
        return $models;
    }

    /**
     * Returns an array of model instances indexed by their name.
     *
     * @return array<model_model>
     */
    private static function create_installed_models($response): array {
        /**
         * @var array<model_model>
         */
        $instances = [];

        foreach (self::get_installed_models() as $name => $classname) {
            $modelclass = new $classname($response, $name); // The constructure takes our array of responses.
            $instances[$name] = $modelclass;
        }
        return $instances;
    }

    /**
     * 
     * @param int $contextid 
     * @param bool $calculate 
     * @return array<model_item_param_list, model_person_param_list>
     */
    public function get_context_parameters( int $contextid = 0, bool $calculate = false) {
        if ($calculate) {
            $response = catcontext::create_response_from_db($contextid);
            $models = self::create_installed_models($response);
            $ability_estimator = new model_person_ability_estimator_demo($response);
            $strategy = new model_strategy($response, $models, $ability_estimator, $contextid);
            list( $item_difficulties, $person_abilities) = $strategy->run_estimation();
            //foreach ($models as $name => $model) {
            //    $estimated_item_difficulties = $model->estimate_item_params();
            //    $estimated_item_difficulties->save_to_db($contextid, $name);
            //    $estimated_person_abilities->save_to_db($contextid, $name);
            //    $item_difficulties[$name] = $estimated_item_difficulties;
            //    $person_abilities[$name] = $estimated_person_abilities;
            //}
            return [$item_difficulties, $person_abilities];
        }

        $item_difficulties = [];
        $person_abilities = [];
        $models = $this->get_installed_models();
        foreach ($models as $name => $model) {
                list($item_difficulties, $person_abilities) = $this->get_estimated_parameters_from_db($contextid, $name);
                $estimated_item_difficulties[$name] = $item_difficulties;
                $estimated_person_abilities[$name] = $person_abilities;
        }
        $person_abilities = reset($estimated_person_abilities); // TODO: there should be only one list per context
        return [$estimated_item_difficulties, $person_abilities];
    }

    /**
     * Recalculates the value for the given contextid, saves them to the DB and returns the new values.
     * @param int $contextid 
     * @return array<array<model_item_param_list>, array<model_person_param_list>>
     */
    private function update_context($contextid)
    {
        $item_difficulties = [];
        $person_abilities = [];
        $response = catcontext::create_response_from_db($contextid);
        $models = self::create_installed_models($response);
        foreach ($models as $name => $model) {
            list($estimated_item_difficulties, $estimated_person_abilities) = $model->run_estimation();
            $estimated_item_difficulties->save_to_db($contextid, $name);
            $estimated_person_abilities->save_to_db($contextid, $name);
            $item_difficulties[$name] = $estimated_item_difficulties;
            $person_abilities[$name] = $estimated_person_abilities;
        }

        return [$item_difficulties, $person_abilities];
    }

    /**
     * Recalculates the CAT model params for all contexts and saves the updated params to the DB.
     * 
     * It returns the new values in the following format:
     * [
     *    '1' => [ // CAT context id
     *              [
     *                  model1, // model_item_param_list
     *                  model2, // model_item_param_list
     *              ],
     *              [
     *                  model1, // model_person_param_list
     *                  model2, // model_person_param_list
     *              ]
     *    ],
     *    '2' => [ ... ]
     * ] 
     * @return array<<array<array<model_item_param_list>>, array<array<model_person_param_list>>>
     */
    public function recalculate_for_all_contexts() {
        global $DB;
        // Get all contexts
        $result = $DB->get_records_sql(
            'SELECT * FROM {local_catquiz_catcontext} cc;',
            []
        );

        // Filter contexts: only trigger calculation for active contexts
        $now = time();
        $filtered = array_filter($result, function($obj) use ($now) {
            return $obj->starttimestamp <= $now && $obj->endtimestamp >= $now;
        });

        $updated_params = [];
        foreach ($filtered as $obj) {
            $updated_params[$obj->id] = $this->update_context($obj->id);
        }

        return $updated_params;
    }

    private function get_estimated_parameters_from_db(int $contextid, string $model) {
        global $DB;

        $item_rows = $DB->get_records('local_catquiz_itemparams',
            [
                'contextid' => $contextid,
                'model' => $model,
            ],
            'difficulty ASC'
        );
        $item_difficulties = new model_item_param_list();
        foreach ($item_rows as $r) {
            $i = new model_item_param($r->componentid, 'ZZZ');
            $i->set_difficulty($r->difficulty);
            $item_difficulties->add($i);
        }

        $person_abilities = model_person_param_list::load_from_db($contextid);
        return [$item_difficulties, $person_abilities];
    }
};
