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

namespace local_catquiz\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once($CFG->dirroot . "/local/catquiz/lib.php");

use context;
use context_system;
use core_form\dynamic_form;
use local_catquiz\local\model\model_item_param;
use moodle_url;
use stdClass;

/**
 * Dynamic form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @package   local_catquiz
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_model_override_selector extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        global $CFG, $DB, $PAGE;

        $mform = $this->_form;
        $data = (object) $this->_ajaxformdata;

        if (!isset($data->models) || !isset($data->itemparams)) {
            return; //TODO should we throw an error?
        }

        $options = [
            model_item_param::STATUS_NOT_SET => get_string('statusnotset', 'local_catquiz'),
            model_item_param::STATUS_SET_BY_STRATEGY => get_string('statussetautomatically', 'local_catquiz'),
            model_item_param::STATUS_SET_MANUALLY => get_string('statussetmanually', 'local_catquiz'),
            model_item_param::STATUS_NOT_CALCULATED => get_string('statusnotcalculated', 'local_catquiz'),
        ];
        foreach (array_keys($data->models) as $model) {
            $group = [];
            $id = sprintf('override_%s', $model);
            $select = $mform->createElement('select', $id, $model, $options, ['multiple' => false]);
            if (array_key_exists($model, $data->itemparams)) {
                $modelparams = $data->itemparams[$model];
                $model_status = $modelparams->status;
                $model_difficulty = $modelparams->difficulty;
            } else { // Set default data if there are no calculated data for the given model
                $model_status = model_item_param::STATUS_NOT_CALCULATED;
                $model_difficulty = '-';
            }
            $select->setSelected(intval($model_status));
            $difficulty_text = sprintf(
                '<div>%s: %s</div>',
                get_string('itemdifficulty', 'local_catquiz'),
                $model_difficulty
            );
            $difficulty = $mform->createElement('html', $difficulty_text);
            $group[] = $select;
            $group[] = $difficulty;
            $mform->addGroup($group, $id, $model);
        }

        $mform->disable_form_change_checker();
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/catquiz:manage_catscales', $this->get_context_for_dynamic_submission());
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * @return object
     */
    public function process_dynamic_submission(): object {
        $data = $this->get_data();

        return $data;
    }

    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['cmid']));
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object) $this->_ajaxformdata;

        $this->set_data($data);
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        return context_system::instance();
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {

        // We don't need it, as we only use it in modal.
        return new moodle_url('/');
    }

    /**
     * Validate form.
     *
     * @param stdClass $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = array();

        return $errors;
    }
}
