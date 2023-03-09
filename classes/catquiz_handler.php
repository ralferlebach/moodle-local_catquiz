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
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use core_plugin_manager;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Class catquiz
 *
 * @author Georg Maißer
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catquiz_handler {

    /** @var int If this is selected, the catquiz engine is deactivated. */
    public const DEACTIVATED_MODEL = 0;
    /** @var int Rasch model to select next question to be displayed to user */
    public const RASCH_MODEL = 1;
    /** @var int Artificial intelligence model to select next question to be displayed to user */
    public const AI_MODEL = 2;

    /**
     * Constructor for catquiz.
     */
    public function __construct() {

    }

    /**
     * Create the form fields relevant to this plugin.
     *
     * @param MoodleQuickForm $mform
     * @return array
     */
    public static function instance_form_definition(MoodleQuickForm $mform) {

        $elements = [];

        $pm = core_plugin_manager::instance();
        $models = $pm->get_plugins_of_type('catmodel');
        $modelarray = [];
        foreach ($models as $model) {
            $modelarray[$model->name] = $model->displayname;
        }

        // Add a special header for catquiz.
        $elements[] = $mform->addElement('header', 'catquiz_header',
                get_string('catquizsettings', 'local_catquiz'));

        // Choose a model for this instance.
        $elements[] = $mform->addElement('select', 'catquiz_model_select',
                get_string('selectmodel', 'local_catquiz'), $modelarray);

        // Question categories or tags to use for this quiz.

        $catscales = \local_catquiz\data\dataapi::get_all_catscales();
        $options = array(
            'multiple' => true,
            'noselectionstring' => get_string('allareas', 'search'),
        );

        $select = [];
        foreach ($catscales as $catscale) {
            $select[$catscale->id] = $catscale->name;
        }
        $elements[] = $mform->addElement('autocomplete', 'catquiz_catcatscales', get_string('catcatscales', 'local_catquiz'), $select, $options);
        $mform->addHelpButton('catquiz_catcatscales', 'catcatscales', 'local_catquiz');

        $elements[] = $mform->addElement('text', 'catquiz_passinglevel', get_string('passinglevel', 'local_catquiz'));
        $mform->addHelpButton('catquiz_passinglevel', 'passinglevel', 'local_catquiz');
        $mform->setType('catquiz_passinglevel', PARAM_INT);

        // Is it a time paced test?
        $elements[] = $mform->addElement('advcheckbox', 'catquiz_timepacedtest',
                get_string('timepacedtest', 'local_catquiz'), null, null, [0, 1]);

        $elements[] = $mform->addElement('text', 'catquiz_maxtimeperitem', get_string('maxtimeperitem', 'local_catquiz'));
        // $mform->addHelpButton('catquiz_maxtimeperitem', 'maxtimeperitem', 'local_catquiz');
        $mform->setType('catquiz_maxtimeperitem', PARAM_INT);
        $mform->hideIf('catquiz_maxtimeperitem', 'catquiz_timepacedtest', 'neq', 1);

        $elements[] = $mform->addElement('text', 'catquiz_mintimeperitem', get_string('mintimeperitem', 'local_catquiz'));
        // $mform->addHelpButton('catquiz_mintimeperitem', 'mintimeperitem', 'local_catquiz');
        $mform->setType('catquiz_mintimeperitem', PARAM_INT);
        $mform->hideIf('catquiz_mintimeperitem', 'catquiz_timepacedtest', 'neq', 1);

        $timeoutoptions = [
            1 => get_string('timeoutfinishwithresult', 'local_catquiz'),
            2 => get_string('timeoutabortresult', 'local_catquiz'),
            3 => get_string('timeoutabortnoresult', 'local_catquiz'),
        ];
         // Choose a model for this instance.
         $elements[] =  $mform->addElement('select', 'catquiz_actontimeout',
            get_string('actontimeout', 'local_catquiz'), $timeoutoptions);
        $mform->hideIf('catquiz_actontimeout', 'catquiz_timepacedtest', 'neq', 1);

        return $elements;
    }

    /**
     * Set the data relvant to this plugin.
     *
     * @param stdClass $data
     * @return void
     */
    public static function instance_form_before_set_data(stdClass &$data) {
        global $DB;

        // Todo: We might rather use data_preprocessing.
    }

    public static function data_preprocessing(array &$formdefaultvalues) {

        global $DB;

        if (!isset($formdefaultvalues['instance'])) {
            return;
        }
        $componentid = $formdefaultvalues['instance'];

        // We can hardcode this at this moment.
        $component = 'mod_adaptivequiz';

        // Create stdClass with all the values.
        $cattest = (object)[
            'componentid' => $componentid,
            'component' => $component,
        ];

        // Pass on the values as stdClas.
        $test = new testenvironment($cattest);
        $test->apply_jsonsaved_values($formdefaultvalues);

        // Todo: Read json and set all the values.

    }

    /**
     * Undocumented function
     *
     * @param stdClass $data
     * @return void
     */
    public static function instance_form_definition_after_data(stdClass &$data) {

    }

    /**
     * Validate the submitted fields relevant to this plugin.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public static function instance_form_validation(array $data, array $files) {

        $errors = [];

        // Todo: Make a real validation of necessary fields.

        return $errors;
    }

    /**
     * Save submitted data relevant to this plugin.
     *
     * @param stdClass $data
     * @param int $instanceid
     * @param string $componentname
     * @return void
     */
    public static function instance_form_save(stdClass &$data, int $instanceid, string $componentname) {
        global $DB;

        $componentid = $data->instance;

        // We can hardcode this at this moment.
        $component = 'mod_adaptivequiz';

        if (!$catquiz = $DB->get_record('local_catquiz_tests',
            ['component' => $component, 'componentid' => $componentid])) {
            return;
        }
    }

    /**
     * Delete settings related to
     *
     * @param int $id
     * @param string $componentname
     * @return void
     */
    public static function delete_settings(string $componentname, int $id) {
        global $DB;
        $DB->delete_records('local_catquiz', ['componentname' => $componentname, 'componentid' => $id]);
    }

    /**
     * Check if this instance is setup to actually use catquiz.
     * When called by an external plugin, this must specify its signature.
     * Like "mod_adaptivequiz" and use its own id (not cmid, but instance id).
     *
     * @param integer $quizid
     * @param string $component
     * @return bool
     */
    public static function use_catquiz(int $quizid, string $component) {

        // TODO: Implement fuctionality.

        return true;
    }
}
