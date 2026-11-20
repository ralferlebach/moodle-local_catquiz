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
 * Debug_Info PDF export
 *
 * @package    local_catquiz
 * @copyright  2024 Wunderbyte GmbH
 * @author     Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\local\attempt\attempt_debug_export;

require_once(__DIR__ . '/../../config.php');
// The function send_file() lives in filelib.php, which a page does not load by itself (found in the browser, re-audit
// 19.11.2026).
require_once($CFG->libdir . '/filelib.php');

// The attempt of the activity (adaptivequiz_attempt.id), not the CAT attempt.
$attemptid = required_param('attemptid', PARAM_INT);

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/catquiz/export_debuginfo_pdf.php', ['attemptid' => $attemptid]));

// Ends with a page that says why there is no export.
$refuse = function (string $message) use ($OUTPUT): void {
    echo $OUTPUT->header();
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    exit;
};

// Permission first: nothing is read before it is checked (issue #173).
if (!has_capability('local/catquiz:canmanage', $context)) {
    $refuse(get_string('nopermissions', 'error', get_capability_string('local/catquiz:canmanage')));
}

// Read-only; refuses when the CAT attempt or its progress does not belong to this attempt.
try {
    $data = attempt_debug_export::collect($attemptid);
} catch (moodle_exception $e) {
    $refuse($e->getMessage());
}

// Issue #173: the document is built where it is tested (attempt_debug_export::pdf()).
send_file(
    attempt_debug_export::pdf($data),
    clean_filename(date('Ymd Hi-') . "export_debuginfo_attempt_$attemptid.pdf"),
    0,
    0,
    true,
    true,
    'application/pdf'
);
