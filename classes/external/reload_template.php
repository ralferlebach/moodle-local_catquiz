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
 * This class contains a list of webservice functions related to the catquiz Module by Wunderbyte.
 *
 * @package    local_catquiz
 * @copyright  2023 Wunderbyte GmbH
 * @author     Georg Maißer, Magdalena Holczik
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_catquiz\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use local_catquiz\execute_method_from_webservice;
use moodle_exception;


/**
 * External Service for local wunderbyte_table to (re)load data.
 *
 * @package   local_catquiz
 * @copyright 2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reload_template extends external_api {
    /**
     * Render classes this endpoint may construct.
     *
     * Kept here rather than derived from the request: a class name that arrives from
     * the client is an instruction, not data. Adding an entry is a deliberate act and
     * shows up in review.
     *
     * @var string[]
     */
    const ALLOWED_RENDER_CLASSES = [
        \local_catquiz\output\catscalemanager\questions\cards\datacard::class,
    ];
    /**
     * Describes the parameters this webservice.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'data'  => new external_value(PARAM_RAW, 'Data package as json.', VALUE_REQUIRED),
            ]);
    }

    /**
     * Execute this webservice.
     *
     * @param string $data
     *
     * @return boolean external_function_parameters
     *
     */
    public static function execute(
        string $data
    ) {

        global $PAGE;

        $context = context_system::instance();
        $PAGE->set_context($context);

        // Review finding: this endpoint acted without checking anything. Being logged
        // in is not authorisation - the same gate as on the pages offering it.
        self::validate_context($context);
        require_capability('local/catquiz:manage_catscales', $context);
        $dataobject = json_decode($data);

        // Make sure, the element triggering the reload includes all necessary data.
        $admethodname = $dataobject->admethodname;
        $adparams = $dataobject->adparams;
        $resultsuccess = execute_method_from_webservice::execute_method($admethodname, $adparams);

        // Get data for template.
        $tdparamsstring = $dataobject->tdparams;
        $paramsarray = explode(",", $tdparamsstring);

        // Security: the render class used to be taken straight from the request, so
        // the client decided which autoloadable PHP class the server constructed -
        // with client-controlled constructor arguments. The capability check narrows
        // who can do that; it does not make the dispatch safe.
        //
        // The permitted classes are now fixed here. Exactly one template sends this
        // value today, so the list is short by nature rather than by omission; a new
        // render target has to be added deliberately.
        $classlocation = (string) ($dataobject->classlocation ?? '');
        if (!in_array($classlocation, self::ALLOWED_RENDER_CLASSES, true)) {
            throw new moodle_exception('invalidrenderclass', 'local_catquiz', '', $classlocation);
        }

        // The parameters arrive as a comma-separated string and are spread into a
        // typed constructor. A malformed list produced a raw TypeError travelling out
        // of the web service; the endpoint reports a failure instead, which is what
        // its own result structure is for.
        try {
            $renderclass = new $classlocation(...$paramsarray);
        } catch (\Throwable $e) {
            return [
                'success' => 0,
                'message' => get_string('invalidrenderparams', 'local_catquiz'),
                'data' => '',
            ];
        }
        // To be able to render the data from the class, make sure the class implements the renderable interface.
        $datafortemplate = $renderclass->export_for_template();
        $templatedatajson = json_encode($datafortemplate);

        if ($resultsuccess) {
            $result = [
                'success' => 1,
                'message' => get_string($admethodname . "_message", 'local_catquiz'),
                'data' => $templatedatajson,
            ];
        } else {
            $result = [
                'success' => 0,
                'message' => get_string('functiondoesntexist', 'local_wunderbyte_table'),
            ];
        }
        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_INT, '1 is success, 0 isn\'t'),
            'message' => new external_value(PARAM_RAW, 'Message to be displayed', VALUE_OPTIONAL, ''),
            'data' => new external_value(PARAM_RAW, 'Data for the template to be rendered', VALUE_OPTIONAL, null),
            ]);
    }
}
