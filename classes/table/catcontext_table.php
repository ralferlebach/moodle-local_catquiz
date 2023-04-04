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

namespace local_catquiz\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use cache_helper;
use local_catquiz\testenvironment;
use local_wunderbyte_table\wunderbyte_table;
use stdClass;

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 */
class catcontext_table extends wunderbyte_table {

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     * @param integer $catscaleid
     */
    public function __construct(string $uniqueid) {

        parent::__construct($uniqueid);

    }

    /**
     * Return value for timecreated column.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_timecreated(stdClass $values) {

        return userdate($values->timecreated);
    }

    /**
     * Return value for timemodified column.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_timemodified(stdClass $values) {

        return userdate($values->timemodified);
    }

    /**
     * Return value for starttimestamp column.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_starttimestamp(stdClass $values) {

        if (!empty($values->starttimestamp)) {
            return userdate($values->starttimestamp);
        }
        return get_string('notimelimit', 'local_catquiz');
    }

    /**
     * Return value for endtimestamp column.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_endtimestamp(stdClass $values) {

        if (!empty($values->endtimestamp)) {
            return userdate($values->endtimestamp);
        }
        return get_string('notimelimit', 'local_catquiz');
    }

    /**
     * Return value for endtimestamp column.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_testitems(stdClass $values) {

        // Returns the number of testitems in the realm of this context.

        return $values->testitems;
    }

    public function col_numberofquestions($values) {

        return 'xy';
    }

    /**
     * This handles the colum checkboxes.
     *
     * @param stdClass $values
     * @return void
     */
    public function col_action($values) {

        global $OUTPUT;

        $data['showactionbuttons'][] = [
            'label' => get_string('edit', 'core'), // Name of your action button.
            'class' => 'btn btn-blank',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-edit', // Add an icon before the label.
            'id' => $values->id,
            'formname' => 'local_catquiz\\form\\edit_catcontext', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [
                'id' => $values->id,
                'labelcolumn' => 'name'
                ]
        ];

        $data['showactionbuttons'][] = [
            'label' => get_string('delete', 'core'), // Name of your action button.
            'class' => 'btn btn-blank',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-edit', // Add an icon before the label.
            'id' => $values->id,
            'methodname' => 'deleteitem', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [
                [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'key' => 'id',
                    'value' => $values->id,
                ],
                [
                    'key' => 'labelcolumn',
                    'value' => 'name',
                ],
                ]
        ];

        return $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', $data);;

    }

    /**
     * Delete item.
     *
     * @param integer $id
     * @param string $data
     * @return array
     */
    public function deleteitem(int $id, string $data):array {

        if (testenvironment::delete_testenvironment($id)) {
            $success = 1;
            $message = get_string('success');
        } else {
            $success = 0;
            $message = get_string('error');
        }

        cache_helper::purge_by_event('changesintestenvironments');

        return [
            'success' => $success,
            'message' => $message,
        ];
    }
}