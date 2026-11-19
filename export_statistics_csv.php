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
 * Attempts CSV export
 *
 * Based on https://gist.github.com/kralo/293dbc07b9b318eabe43.
 *
 * @package    local_catquiz
 * @copyright  2024 Wunderbyte GmbH
 * @author     David Szkiba
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\local\access\context_resolver;
use local_catquiz\local\access\feedback_access;
use local_catquiz\output\catquizstatistics;

require_once(__DIR__ . '/../../config.php');

require_login();

// Issue #175: courseid and testid are lists - one ID or several - read strictly; the selection of first or last
// attempts comes along. A list that is no list of IDs is refused, nothing is cut to a number.
try {
    [$courseids, $testids, $starttime, $endtime, $selection] = catquizstatistics::export_scope_from_request();
} catch (moodle_exception $e) {
    $PAGE->set_context(context_system::instance());
    die(s($e->getMessage()));
}

// Issue #18: resolve the contexts these statistics actually belong to (the quiz
// module of every test of the scope, otherwise every course) and apply the
// same access rule the rendered statistics page uses, so that the export can never
// hand out more than the page shows. Issue #175: one test or course without the right refuses the export.
$scopecontexts = context_resolver::for_statistics_scope($courseids, $testids);
$PAGE->set_context(count($scopecontexts) === 1 ? reset($scopecontexts) : context_system::instance());
foreach ($scopecontexts as $exportcontext) {
    if (!feedback_access::can_view_other_users($exportcontext)) {
        die(get_string('error:permissionforcsvdownload', 'local_catquiz', 'local/catquiz:view_users_feedback'));
    }
}

require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->libdir . '/csvlib.class.php');

// Issue #141: without a scale the export takes the one of the test or course, if there is exactly one.
$scaleid = catquizstatistics::resolve_scale_id(optional_param('scaleid', 0, PARAM_INT), $courseids, $testids);
$catquizstatistics = new catquizstatistics($courseids, $testids, $scaleid, $endtime, $starttime, $selection);
$courseid = local_catquiz\local\statistics\id_list::to_string($courseids);
$testid = local_catquiz\local\statistics\id_list::to_string($testids);
if (count($courseids ?? []) > 5) {
    $courseid = count($courseids) . 'courses';
}
if (count($testids ?? []) > 5) {
    $testid = count($testids) . 'tests';
}

$filename = date("Ymd Hi-") . "export_attempts_scale_$scaleid";
if ($courseid !== '') {
    $filename .= "_course_" . str_replace(',', '-', $courseid);
}
if ($testid !== '') {
    $filename .= "_test_" . str_replace(',', '-', $testid);
}
if ($starttime && $starttime != 0) {
    $filename .= "_from_" . date("Ymd Hi", $starttime);
}
if ($endtime && $endtime != 0) {
    $filename .= "_till_" . date("Ymd Hi", $endtime);
}

$downloadfilename = clean_filename($filename);
$csvexport = new csv_export_writer('semicolon');
$csvexport->set_filename($downloadfilename);

$exporttitle = [
// phpcs:disable
// -- [x] UserID,
// -- [x] UserName,
// -- [x] UserE-Mail,
// -- [x] Startzeit,
// -- [x] Endzeit,
// -- [X] Strategie,
// -- [X] Status Testresult (Maximalanzahl Fragen erreicht, keine Fragen, SE unterschritten, Zeit überschritten etc.),
// -- [x] Anz. Fragen gesamt (auch nicht-gewertete und Pilotierungsfragen),
// -- [/] Ergebnis-Range,
// -- [/] PP global,
// -- [ ] SE global,
// -- [/] N global,
// -- [/] frac global,
// -- [/] Ergebnis-Skala (je Strategie),
// -- [/] PP Ergebnisskala,
// -- [/] SE Ergebnisskala,
// -- [/] N Ergebnisskala,
// -- [/] frac Ergebnisskala,
// -- [?] JSON aller detektierten Ergebnisse
    get_string('csvexportheader:userid', 'local_catquiz'),
    get_string('username', ''),
    get_string('firstname', ''),
    get_string('lastname', ''),
    get_string('email', 'moodle'),
    get_string('csvexportheader:testid', 'local_catquiz'),
    get_string('csvexportheader:attemptid', 'local_catquiz'),
    get_string('csvexportheader:attemptstart', 'local_catquiz'),
    get_string('csvexportheader:attemptend', 'local_catquiz'),
    get_string('csvexportheader:attemptduration', 'local_catquiz'),
    get_string('csvexportheader:attemptstatus', 'local_catquiz'),
    get_string('csvexportheader:teststrategy', 'local_catquiz'),
    get_string('csvexportheader:attemptquestionno', 'local_catquiz'),
    // 'Ergebnis-Range',
    get_string('csvexportheader:resultscaleglobal', 'local_catquiz'),
    get_string('csvexportheader:resultppglobal', 'local_catquiz'),
    get_string('csvexportheader:resultseglobal', 'local_catquiz'),
    // 'N global',
    # 'frac global',
    get_string('csvexportheader:resultscaledetail', 'local_catquiz'),
    get_string('csvexportheader:resultppdetail', 'local_catquiz'),
    get_string('csvexportheader:resultsedetail', 'local_catquiz'),
    // 'N Ergebnisskala',
    // 'frac Ergebnisskala',
// phpcs:enable
];

$csvexport->add_data($exporttitle);

$decsep = get_string('decsep', 'langconfig');

foreach ($catquizstatistics->get_export_data() as $row) {
    // phpcs:disable
    $csvexport->add_data(
        [
            $row->userid,
            $row->username,
            $row->firstname,
            $row->lastname,
            $row->email,
            $row->testid,
            $row->attemptid,
            $row->starttime,
            $row->endtime,
            $row->timediff,
            $row->status,
            $row->teststrategy,
            $row->number_of_testitems_used,
            $row->globalname,
            str_replace('.', $decsep, (string) $row->globalpp),
            str_replace('.', $decsep, (string) round((float) $row->globalse, 2)),
            /*
            $row->globaln,
            $row->globalf,
            */
            $row->primaryname,
            str_replace('.', $decsep, (string) $row->primarypp),
            str_replace('.', $decsep, (string) round((float) $row->primaryse, 2)),
            /*
            $row->primaryn,
            $row->primaryf,

            $row->allresults,
            */
        ]
    );
    // phpcs:enable
}

$csvexport->download_file();
