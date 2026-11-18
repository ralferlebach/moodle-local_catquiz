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

namespace local_catquiz\local\attempt;

use local_catquiz\catquiz;
use moodle_exception;
use stdClass;

/**
 * What the debug export shows for one attempt, read-only (issue #173).
 *
 * Two id spaces meet here. The caller hands in the attempt of the component (adaptivequiz_attempt.id);
 * the CATquiz tables file the progress under the CAT attempt (local_catquiz_attempts.id). The export
 * resolves the one into the other exactly once and checks that every row it shows belongs to the same
 * person, component and activity. If anything does not fit it shows nothing and fails closed.
 *
 * The export writes nothing: no progress is loaded through the engine (which may save), no event is
 * triggered and no timestamp is touched.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_debug_export {
    /** @var string The progress of the CAT attempt is there. */
    public const PROGRESS_PRESENT = 'present';

    /** @var string There is no progress for the CAT attempt: never written, or removed by the retention. */
    public const PROGRESS_ABSENT = 'absent';

    /** @var string[] The columns of local_catquiz_attempts shown as the state in the database. */
    public const LIFECYCLE_FIELDS = [
        'id', 'attemptid', 'component', 'instanceid', 'userid', 'courseid', 'contextid', 'scaleid', 'teststrategy',
        'status', 'total_number_of_testitems', 'number_of_testitems_used', 'personability_before_attempt',
        'personability_after_attempt', 'starttime', 'endtime', 'timecreated', 'timemodified',
    ];

    /**
     * Collects the export of an attempt of the component.
     *
     * @param int $hostattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component The component of the attempt.
     * @return stdClass hostattemptid, catattemptid, lifecycle (DB fields), snapshot (stored JSON), snapshottime,
     *      debuginfo, progressstate, progress, progresstime, foreignprogressrows, administrations.
     * @throws moodle_exception When the attempt is not there or a row does not belong to it.
     */
    public static function collect(int $hostattemptid, string $component = 'mod_adaptivequiz'): stdClass {
        global $DB;

        $hostattempt = $DB->get_record('adaptivequiz_attempt', ['id' => $hostattemptid], 'id, instance, userid, uniqueid');
        $catattemptid = catquiz::get_cat_attempt_id($hostattemptid, $component);
        if (!$hostattempt || $catattemptid === null) {
            throw new moodle_exception('debugexport:notfound', 'local_catquiz');
        }
        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catattemptid], '*', MUST_EXIST);

        // The CAT attempt must be the one of this attempt: same person, same activity, same component.
        $components = catquiz::component_names($component);
        if (
            (int) $catattempt->attemptid !== $hostattemptid
            || (int) $catattempt->userid !== (int) $hostattempt->userid
            || !in_array((string) $catattempt->component, $components, true)
            || (!empty($catattempt->instanceid) && (int) $catattempt->instanceid !== (int) $hostattempt->instance)
        ) {
            throw new moodle_exception('debugexport:inconsistent', 'local_catquiz');
        }

        // The progress is filed under the CAT attempt (one row per number, unique index). A row with that
        // number that belongs to another person or component is not this attempt's - typically progress from
        // before local_catquiz #101, filed under the number of an activity attempt. It is never shown, only
        // counted.
        $progress = $DB->get_record('local_catquiz_progress', ['attemptid' => $catattemptid]) ?: null;
        $foreign = 0;
        if ($progress !== null && progress_integrity::check($progress, $catattempt) !== null) {
            $progress = null;
            $foreign = 1;
        }

        $lifecycle = [];
        foreach (self::LIFECYCLE_FIELDS as $field) {
            $lifecycle[$field] = $catattempt->$field ?? null;
        }

        return (object) [
            'hostattemptid' => $hostattemptid,
            'catattemptid' => $catattemptid,
            'lifecycle' => $lifecycle,
            'snapshot' => self::decode($catattempt->json ?? null),
            'snapshottime' => (int) $catattempt->timemodified,
            'debuginfo' => self::decode($catattempt->debug_info ?? null),
            'progressstate' => $progress ? self::PROGRESS_PRESENT : self::PROGRESS_ABSENT,
            'progress' => $progress ? self::decode($progress->json) : null,
            'progresstime' => $progress ? (int) $progress->timemodified : null,
            'foreignprogressrows' => $foreign,
            'administrations' => array_values(administration_history::for_attempt($hostattemptid)),
        ];
    }

    /**
     * The pages of the export as HTML. Every stored value is escaped: the export shows data, never markup.
     *
     * @param stdClass $data What collect() returned.
     * @return array<string, string> Page title => HTML.
     */
    public static function pages(stdClass $data): array {
        $a = (object) ['host' => $data->hostattemptid, 'cat' => $data->catattemptid];
        $pages = [];

        $rows = '';
        foreach ($data->lifecycle as $field => $value) {
            $rows .= '<tr><td>' . s($field) . '</td><td>' . ($value === null ? '-' : s((string) $value)) . '</td></tr>';
        }
        $pages[get_string('debugexport:lifecycle', 'local_catquiz', $a)] =
            '<p>' . s(get_string('debugexport:ids', 'local_catquiz', $a)) . '</p>'
            . '<table border="1" cellpadding="2">' . $rows . '</table>';

        $pages[get_string('debugexport:debuginfo', 'local_catquiz', $a)] = self::dump($data->debuginfo);

        if ($data->progressstate === self::PROGRESS_PRESENT) {
            $progresshtml = '<p>' . s(get_string('debugexport:progresstime', 'local_catquiz', userdate($data->progresstime)))
                . '</p>' . self::dump($data->progress);
        } else {
            $progresshtml = '<p>' . s(get_string('debugexport:progressabsent', 'local_catquiz')) . '</p>';
        }
        if ($data->foreignprogressrows > 0) {
            $progresshtml .= '<p>' . s(get_string('debugexport:foreignprogress', 'local_catquiz', $data->foreignprogressrows))
                . '</p>';
        }
        $pages[get_string('debugexport:progress', 'local_catquiz', $a)] = $progresshtml;

        $pages[get_string('debugexport:snapshot', 'local_catquiz', $a)] =
            '<p>' . s(get_string('debugexport:snapshotnote', 'local_catquiz', userdate($data->snapshottime))) . '</p>'
            . self::dump($data->snapshot);

        // Issue #125: every administration, one row per slot, a repeated question marked with the slot it
        // first appeared in.
        $rows = '';
        foreach ($data->administrations as $entry) {
            $rows .= '<tr><td>' . (int) $entry->slot . '</td><td>' . (int) $entry->questionid . '</td><td>'
                . (int) $entry->questionattemptid . '</td><td>' . s((string) $entry->state) . '</td><td>'
                . ($entry->fraction === null ? '-' : format_float($entry->fraction, 2)) . '</td><td>'
                . ($entry->technicalduplicate ? 'TECHNICAL DUPLICATE of slot ' . (int) $entry->duplicateofslot : '')
                . '</td></tr>';
        }
        $pages[get_string('debugexport:administrations', 'local_catquiz', $a)] =
            '<table border="1" cellpadding="2"><tr><th>Slot</th><th>Question</th><th>Question attempt</th>'
            . '<th>State</th><th>Fraction</th><th>Note</th></tr>' . $rows . '</table>';

        return $pages;
    }

    /**
     * The PDF of the export (issue #173): the pages of pages(), nothing else.
     *
     * export_debuginfo_pdf.php sends what this returns; a test reads the same document. Without compression the
     * text of the document is readable in the bytes - for the test, never for the download.
     *
     * @param stdClass $data What collect() returned.
     * @param bool $compress False only for a test that reads the document.
     * @param string|null $font A core font for a test that reads the text (core fonts keep it as written).
     * @return string The PDF document.
     */
    public static function pdf(stdClass $data, bool $compress = true, ?string $font = null): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->setCompression($compress);
        if ($font !== null) {
            $pdf->SetFont($font, '', 10);
        }
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        foreach (self::pages($data) as $title => $html) {
            $pdf->AddPage('P', 'A4');
            $pdf->writeHTML('<h1>' . s($title) . '</h1>' . $html);
        }
        return $pdf->Output('', 'S');
    }

    /**
     * Decodes a stored JSON column; null for an empty or broken one.
     *
     * @param string|null $json
     * @return mixed
     */
    private static function decode(?string $json) {
        if ($json === null || $json === '') {
            return null;
        }
        return json_decode($json, true);
    }

    /**
     * A decoded value as escaped text.
     *
     * @param mixed $value
     * @return string
     */
    private static function dump($value): string {
        if ($value === null) {
            return '<p>-</p>';
        }
        return '<pre>' . s(var_export($value, true)) . '</pre>';
    }
}
