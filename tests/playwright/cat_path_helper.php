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
 * What the browser cannot see, for tests/cat-attempt-path.spec.ts (re-audit 19.11.2026, Playwright P0).
 *
 *   --state --cmid=N --username=U   JSON: the last attempt of the person at the activity - state, stop code, slots,
 *                                   distinct questions, questions answered, result status and validity, grade,
 *                                   completion state, CAT attempts and finalisation, completion events
 *   --break-items                   the item parameters of the site disappear (kept in the data directory): the
 *                                   next selection fails - a real technical error, as in the adapter's replay
 *   --restore-items                 puts them back
 *
 * Not for a production site.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

[$options] = cli_get_params(['state' => false, 'cmid' => 0, 'username' => '', 'break-items' => false,
    'restore-items' => false]);
$keep = $CFG->dataroot . '/catpath_itemparams.json';

if ($options['break-items']) {
    if (!file_exists($keep)) {
        file_put_contents($keep, json_encode(array_values($DB->get_records('local_catquiz_itemparams'))));
    }
    $DB->delete_records('local_catquiz_itemparams');
    purge_all_caches();
    echo json_encode(['broken' => true]) . "\n";
    exit(0);
}
if ($options['restore-items']) {
    if (file_exists($keep)) {
        foreach (json_decode(file_get_contents($keep)) as $row) {
            if (!$DB->record_exists('local_catquiz_itemparams', ['id' => $row->id])) {
                $DB->insert_record_raw('local_catquiz_itemparams', $row, false, false, true);
            }
        }
        unlink($keep);
    }
    purge_all_caches();
    echo json_encode(['restored' => true]) . "\n";
    exit(0);
}
if ($options['state']) {
    $cm = get_coursemodule_from_id('adaptivequiz', (int) $options['cmid'], 0, false, MUST_EXIST);
    $user = $DB->get_record('user', ['username' => $options['username']], '*', MUST_EXIST);
    $attempts = $DB->get_records('adaptivequiz_attempt', ['instance' => $cm->instance, 'userid' => $user->id], 'id DESC');
    $attempt = reset($attempts);
    $state = ['attempts' => count($attempts), 'cmid' => (int) $cm->id, 'userid' => (int) $user->id];
    if ($attempt) {
        $slots = $DB->get_records('question_attempts', ['questionusageid' => $attempt->uniqueid], 'slot', 'id, slot, questionid');
        $questions = array_map('intval', array_column($slots, 'questionid'));
        $cats = $DB->get_records('local_catquiz_attempts', ['attemptid' => $attempt->id]);
        $cat = reset($cats);
        $events = $cat && $DB->get_manager()->table_exists('logstore_standard_log')
            ? $DB->count_records('logstore_standard_log', ['eventname' => '\\local_catquiz\\event\\attempt_completed',
                'objectid' => $cat->id]) : null;
        $grades = grade_get_grades($cm->course, 'mod', 'adaptivequiz', $cm->instance, $user->id);
        $grade = null;
        if (!empty($grades->items[0]->grades[$user->id])) {
            $grade = $grades->items[0]->grades[$user->id]->grade;
        }
        $course = get_course($cm->course);
        $completion = (new completion_info($course))->get_data(
            get_fast_modinfo($course, $user->id)->get_cm($cm->id),
            false,
            $user->id
        );
        $state += [
            'attemptid' => (int) $attempt->id,
            'state' => $attempt->attemptstate,
            'stopcode' => $attempt->stopcode ?? null,
            'resultvalid' => isset($attempt->resultvalid) ? (int) $attempt->resultvalid : null,
            'resultstatus' => $attempt->resultstatus ?? null,
            'questionsattempted' => (int) $attempt->questionsattempted,
            'slots' => count($questions),
            'distinct' => count(array_unique($questions)),
            'catattempts' => count($cats),
            'catuserid' => $cat ? (int) $cat->userid : null,
            'finalised' => $cat ? !empty($cat->endtime) : false,
            'completionevents' => $events,
            'grade' => $grade === null ? null : (float) $grade,
            'completion' => (int) $completion->completionstate,
        ];
    }
    echo json_encode($state) . "\n";
    exit(0);
}
cli_error('Nothing to do: --state, --break-items or --restore-items.');
