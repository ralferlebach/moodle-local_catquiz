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
 * Class catscalequestions_table.
 *
 * @package local_catquiz
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

use html_writer;
use local_catquiz\catscale;
use local_wunderbyte_table\wunderbyte_table;
use context_system;
use local_catquiz\catquiz;
use local_catquiz\local\model\model_item_param;
use local_wunderbyte_table\output\table;
use moodle_url;

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 *
 * @package local_catquiz
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catscalequestions_table extends wunderbyte_table {

    /** @var integer $catscaleid */
    private $catscaleid = 0;

    /** @var integer */
    private $contextid = 0;

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     * @param int $catscaleid
     * @param int $contextid
     */
    public function __construct(string $uniqueid, int $catscaleid = 0, int $contextid = 0) {

        $this->catscaleid = $catscaleid;

        if (empty($contextid)) {
            $contextid = catquiz::get_default_context_id();
        }
        $this->contextid = $contextid;

        parent::__construct($uniqueid);
    }

    /**
     * Overrides the output for this column.
     * @param object $values
     */
    public function col_userid($values) {
        return $values->id;
    }

    /**
     * Overrides the output for action column.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_action($values) {
        global $OUTPUT;

        $url = new moodle_url('manage_catscales.php', [
            'id' => $values->id,
            'contextid' => $this->contextid,
            'scaleid' => $values->catscaleid ?? 0,
            'component' => $values->component ?? "",
        ], 'lcq_questions');

        $data['showactionbuttons'][] = [
            'class' => 'btn btn-plain btn-smaller',
            'iclass' => empty($values->testitemstatus) ? 'fa fa-eye' : 'fa fa-eye-slash',
            'arialabel' => empty($values->testitemstatus) ? 'eye icon' : 'eye icon slashed',
            'title' => get_string('eyeicontitle', 'local_catquiz'),
            'href' => '#',
            'id' => $values->id,
            'methodname' => 'togglestatus', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'testitemstatus' => !empty($values->testitemstatus) ? $values->testitemstatus : "",
                'catscaleid' => $values->catscaleid ?? $this->catscaleid,
                'titlestring' => 'toggleactivity', // Will be shown in modal title.
                'bodystring' => 'confirmactivitychange', // Will be shown in modal body.
                'component' => 'local_catquiz',
                'labelcolumn' => 'name',
            ],
        ];

        $data['showactionbuttons'][] = [
                'class' => 'btn btn-plain btn-smaller',
                'iclass' => 'fa fa-cog',
                'arialabel' => 'cogwheel',
                'title' => get_string('cogwheeltitle', 'local_catquiz'),
                'href' => $url->out(false),
                'methodname' => '',
                'nomodal' => true,
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'id' => 'id',
                ],
            ];
        $data['showactionbuttons'][] = [
            'class' => 'btn btn-plain btn-smaller',
            'iclass' => 'fa fa-trash',
            'arialabel' => 'trash bin',
            'title' => get_string('trashbintitle', 'local_catquiz'),
            'id' => $values->id,
            'href' => '#',
            'methodname' => 'deletequestionfromscale',
            'nomodal' => false,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'questionid' => $values->id,
                'id' => $values->id,
                'catscaleid' => $values->catscaleid ?? $this->catscaleid,
                'titlestring' => 'deletedatatitle', // Will be shown in modal title.
                'bodystring' => 'confirmdeletion', // Will be shown in modal body in case elements are selected.
                'component' => 'local_catquiz',
                'labelcolumn' => 'name', // Verify value of record that will be deleted.
            ],
        ];
        $data['showactionbuttons'][] = [
            'class' => 'btn btn-plain btn-smaller',
            'iclass' => 'fa fa-edit',
            'arialabel' => 'edit pen',
            'title' => get_string('edititemparams', 'local_catquiz'),
            'id' => $values->id,
            'href' => '#',
            'formname' => 'local_catquiz\form\change_itemparams',
            'nomodal' => false,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'questionid' => $values->id,
                'id' => $values->id,
                'catscaleid' => $values->catscaleid ?? $this->catscaleid,
                'titlestring' => 'deletedatatitle', // Will be shown in modal title.
                'bodystring' => 'confirmdeletion', // Will be shown in modal body in case elements are selected.
                'component' => 'local_catquiz',
                'model' => $values->model,
                'componentid' => $values->componentid,
                'contextid' => $this->contextid,
                'componentname' => $values->component,
            ],
        ];
            table::transform_actionbuttons_array($data['showactionbuttons']);
            return $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', $data);
    }


    /**
     * Overrides the output for action column.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_view($values) {

        global $OUTPUT;

        $url = new moodle_url('manage_catscales.php', [
            'id' => $values->id,
            'contextid' => $this->contextid,
            'scaleid' => $values->catscaleid ?? $this->catscaleid,
            'component' => $values->component ?? "",
        ], 'questions');

        $data['showactionbuttons'][] = [
            'label' => get_string('view', 'core'), // Name of your action button.
            'class' => 'btn btn-plain btn-smaller',
            'iclass' => 'fa fa-edit',
            'href' => $url->out(false),
            'id' => $values->id,
            'methodname' => '', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->id,
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data['showactionbuttons']);

        return $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', $data);
    }

    /**
     * Return value for lastattempttime column.
     *
     * @param \stdClass $values
     * @return string
     */
    public function col_lastattempttime($values) {

        if (intval($values->lastattempttime) === 0) {
            return get_string('notyetcalculated', 'local_catquiz');
        }
        return userdate($values->lastattempttime);
    }

    /**
     * Return symbols for status column.
     *
     * @param \stdClass $values
     * @return string
     */
    public function col_status($values) {

        if ($this->is_downloading()) {
            return !empty($values->status) ? $values->status : STATUS_NOT_CALCULATED;
        }
        $bootstrapclass = "";
        $status = $values->status ?? STATUS_NOT_CALCULATED;

        switch ($status) {
            case STATUS_CONFIRMED_MANUALLY:
                $bootstrapclass = STATUS_CONFIRMED_MANUALLY_COLOR_CLASS;
                break;
            case STATUS_CALCULATED:
                $bootstrapclass = STATUS_CALCULATED_COLOR_CLASS;
                break;
            case STATUS_NOT_CALCULATED:
                $bootstrapclass = STATUS_NOT_CALCULATED_COLOR_CLASS;
                break;
            case STATUS_EXCLUDED_MANUALLY:
                $bootstrapclass = STATUS_EXCLUDED_MANUALLY_COLOR_CLASS;
                break;
            case STATUS_UPDATED_MANUALLY:
                $bootstrapclass = STATUS_UPDATED_MANUALLY_COLOR_CLASS;
                break;
        }

        $labelstring = "itemstatus_" . $status;

        return html_writer::tag('i', "", [
            "class" => "fa fa-circle $bootstrapclass",
            "aria-label" => get_string($labelstring, 'local_catquiz'),
            "title" => get_string($labelstring, 'local_catquiz'),
        ]);
    }

    /**
     * Return strings for column type.
     *
     * @param \stdClass $values
     * @return string
     */
    public function col_qtype($values) {

        if (!empty($values->qtype)) {
            return get_string('pluginname', 'qtype_' . $values->qtype);
        }

        return "problem with $values->id, no qtype";
    }


    /**
     * Overrides the output for this column.
     * @param object $values
     */
    public function col_name($values) {

        global $OUTPUT;

        $context = context_system::instance();

        $questiontext = question_rewrite_question_urls(
            $values->questiontext,
            'pluginfile.php',
            $context->id,
            'question',
            'questiontext',
            [],
            $values->id);

        $fulltext = format_text($questiontext);
        $questiontext = strip_tags($fulltext);
        $shorttext = substr($questiontext, 0, 30);
        $shorttext .= strlen($shorttext) < strlen($questiontext) ? '...' : '';

        $data = [
            'shorttext' => $shorttext,
            'fulltext' => $fulltext,
            'id' => $values->id,
        ];

        return $OUTPUT->render_from_template('local_catquiz/modals/modal_questionpreview', $data);
    }

    /**
     * Function to delete selected question.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function deletequestionfromscale(int $id, string $data) {

        $jsonobject = json_decode($data);

        $catscaleid = $jsonobject->catscaleid;
        $questionid = $jsonobject->questionid;

        catscale::remove_testitem_from_scale($catscaleid, $questionid);

        return [
           'success' => 1,
           'message' => get_string('success'),
        ];
    }

    /**
     * Toggle status to set item active / inactive.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function togglestatus(int $id, string $data) {

        $jsonobject = json_decode($data);

        $catscaleid = $jsonobject->catscaleid;
        $status = empty($jsonobject->testitemstatus) ? TESTITEM_STATUS_INACTIVE : TESTITEM_STATUS_ACTIVE;

        catscale::add_or_update_testitem_to_scale((int)$catscaleid, $id, $status);

        return [
            'success' => 1,
            'message' => get_string('success'),
        ];
    }
    /**
     * Return string of parentscale like "childscale|parentscale|grandparentscale".
     *
     * @param \stdClass $values
     * @return string
     */
    public function col_parentscalenames($values) {

        $ancestors = catscale::get_ancestors($values->catscaleid, 2);
        return implode('|', $ancestors);
    }

    /**
     * Overrides the output for this column.
     * @param object $values
     *
     * @return string
     */
    public function col_idnumber($values) {
        return html_writer::tag('span', $values->idnumber, ['class' => 'badge badge-primary']);
    }

    /**
     * Overrides the output for this column.
     * @param object $values
     */
    public function col_questiontext($values) {

        global $OUTPUT;

        // phpcs:disable
        // try {
        // $question = question_bank::load_question($values->id);
        // } catch (Exception $e) {
        // return $values->questiontext;
        // }
        // phpcs:enable

        $context = context_system::instance();

        $questiontext = question_rewrite_question_urls(
            $values->questiontext,
            'pluginfile.php',
            $context->id,
            'question',
            'questiontext',
            [],
            $values->id);

        $fulltext = format_text($questiontext);
        $questiontext = strip_tags($fulltext);
        $shorttext = substr($questiontext, 0, 30);
        $shorttext .= strlen($shorttext) < strlen($questiontext) ? '...' : '';

        $data = [
            'shorttext' => $shorttext,
            'fulltext' => $fulltext,
            'id' => $values->id,
        ];

        return $OUTPUT->render_from_template('local_catquiz/modals/modal_questionpreview', $data);
    }


    /**
     * Overrides the output for questioncontextattempts column.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_questioncontextattempts($values) {
        return $values->questioncontextattempts;
    }

        /**
         * Function to handle the action buttons.
         * @param int $testitemid
         * @param string $data
         * @return array
         */
    public function addtestitem(int $testitemid, string $data) {

        $jsonobject = json_decode($data);

        $catscaleid = $jsonobject->catscaleid;

        if ($catscaleid == -1) {
            return [
                'success' => 0,
                'message' => get_string('noscaleselected', 'local_catquiz'),
            ];
        }

        if ($testitemid == -1) {
            $idarray = $jsonobject->checkedids;
        } else if ($testitemid > 0) {
            $idarray = [$testitemid];
        }

        foreach ($idarray as $id) {
            $result[] = catscale::add_or_update_testitem_to_scale($catscaleid, $id);
        }
        $failed = array_filter($result, fn($r) => $r->isErr());

        // All items were added successfully.
        if (empty($failed)) {
            return [
                'success' => 1,
                'message' => get_string('success'),
            ];
        }

        // If a single item could not be added, show a specific error message.
        if (count($idarray) === 1 && count($failed) === 1) {
            return [
                'success' => 0,
                'message' => $failed[0]->getErrorMessage(),
            ];
        }

        // Multiple items could not be added.
        $numadded = count($result) - count($failed);
        $failedids = array_map(fn($f) => $f->unwrap(), $failed);
        return [
            'success' => 0,
            'message' => get_string(
                'failedtoaddmultipleitems',
                'local_catquiz',
                [
                    'numadded' => $numadded,
                    'numfailed' => count($failed),
                    'failedids' => implode(',', $failedids),
                ]
            ),
        ];
    }

}
