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
 * CAT management: manual recalculation triggers and run status (issue #43).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\catquiz;
use local_catquiz\local\calculation\calculation_mode;
use local_catquiz\local\calculation\calculation_request;
use local_catquiz\local\calculation\calculation_service;
use local_catquiz\local\calculation\calculation_trigger;

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/catquiz/manage_calculation.php'));
$PAGE->set_title(get_string('calculationmanagement', 'local_catquiz'));
$PAGE->set_heading(get_string('calculationmanagement', 'local_catquiz'));
$PAGE->set_pagelayout('admin');

require_capability('local/catquiz:recalculate', $context);

$scaleid = optional_param('scaleid', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);
$service = new calculation_service();

// Handle a trigger action.
if ($scaleid > 0 && calculation_mode::is_valid($mode) && confirm_sesskey()) {
    // The disruptive mode needs the stricter capability and an explicit confirm.
    if ($mode === calculation_mode::DISRUPTIVE_RECALCULATION) {
        require_capability('local/catquiz:disruptiverecalculate', $context);
    }
    $contextid = (int) $DB->get_field('local_catquiz_catscales', 'contextid', ['id' => $scaleid]);

    if ($mode === calculation_mode::DISRUPTIVE_RECALCULATION && !$confirm) {
        // Show a confirmation before queueing a disruptive run.
        echo $OUTPUT->header();
        $continueurl = new moodle_url('/local/catquiz/manage_calculation.php', [
            'scaleid' => $scaleid, 'mode' => $mode, 'confirm' => 1, 'sesskey' => sesskey(),
        ]);
        $cancelurl = new moodle_url('/local/catquiz/manage_calculation.php');
        echo $OUTPUT->confirm(get_string('disruptiveconfirm', 'local_catquiz'), $continueurl, $cancelurl);
        echo $OUTPUT->footer();
        exit;
    }

    if ($contextid > 0) {
        $request = new calculation_request(
            $scaleid,
            $contextid,
            $mode,
            calculation_trigger::MANUAL,
            $USER->id
        );
        $service->queue($request);
        redirect(
            new moodle_url('/local/catquiz/manage_calculation.php'),
            get_string('calculationqueued', 'local_catquiz'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('calculationmanagement', 'local_catquiz'));
echo html_writer::tag('p', get_string('calculationmanagement_help', 'local_catquiz'));

$candisruptive = has_capability('local/catquiz:disruptiverecalculate', $context);

$table = new html_table();
$table->head = [
    get_string('catscale', 'local_catquiz'),
    get_string('contextid', 'local_catquiz'),
    get_string('lastcalculation', 'local_catquiz'),
    get_string('actions'),
];

foreach (catquiz::get_all_scales_for_active_contexts() as $scale) {
    $summary = calculation_service::get_last_summary((int) $scale->id);
    $status = $summary ? $summary->to_console_line() : get_string('none');

    $incrementalurl = new moodle_url('/local/catquiz/manage_calculation.php', [
        'scaleid' => $scale->id, 'mode' => calculation_mode::INCREMENTAL_RECALCULATION, 'sesskey' => sesskey(),
    ]);
    $actions = $OUTPUT->single_button($incrementalurl, get_string('startrecalculation', 'local_catquiz'), 'get');

    if ($candisruptive) {
        $disruptiveurl = new moodle_url('/local/catquiz/manage_calculation.php', [
            'scaleid' => $scale->id, 'mode' => calculation_mode::DISRUPTIVE_RECALCULATION, 'sesskey' => sesskey(),
        ]);
        $actions .= $OUTPUT->single_button($disruptiveurl, get_string('startdisruptive', 'local_catquiz'), 'get');
    }

    $table->data[] = [
        format_string($scale->name),
        $scale->contextid,
        $status,
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
