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
 * Class debuginfo.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\feedbackgenerator;

use context_system;
use local_catquiz\local\access\context_resolver;
use local_catquiz\local\attempt\attempt_statistics;
use local_catquiz\teststrategy\feedbackgenerator;
use local_catquiz\teststrategy\info;

/**
 * Returns debug info.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debuginfo extends feedbackgenerator {
    /**
     * Default string for missing data
     *
     * @var string NA
     */
    const NA = 'NA';

    /**
     * Temporarily holds the data of the next row
     *
     * When full, will be added as a CSV styled row.
     *
     * @var array
     */
    private array $row = [];

    /**
     * Holds the column values
     *
     * @var array
     */
    private array $columns = [];

    /**
     * The data for the next row
     *
     * This contains the pool of data that can be used to build the next row.
     *
     * @var array
     */
    private array $rowdata = [];

    /**
     * Get student feedback.
     *
     * @param array $data
     *
     * @return array
     *
     */
    public function get_studentfeedback(array $data): array {
        return [];
    }

    /**
     * Get teacher feedback.
     *
     * This renders a link with the href attribute set to data:text/csv and the
     * data contained within the link.
     *
     * @param array $data
     *
     * @return array
     *
     */
    protected function get_teacherfeedback(array $data): array {
        global $OUTPUT, $DB;

        // The export tab is always available to users with the feedback
        // permission. The raw debug exports below are added only when debug
        // storage is enabled for the plugin.
        $debugenabled = (bool) get_config('local_catquiz', 'store_debug_info');

        $csvstring = "";
        $texts = self::texts_of_the_usage($data['debuginfo'] ?? []);
        $names = self::names_of_the_rows($data['debuginfo'] ?? []);

        foreach (($data['debuginfo'] ?? []) as $row) {
            $newrow = $this->convert((array) $row, $texts, $names);
            $csvstring .= $this
                ->set_row_data($newrow)
                ->add_column_value('questionsattempted')
                ->add_column_value('timestamp')
                ->add_column_value('personabilities')
                ->add_column_value('activescales')
                ->add_column_value('lastmiddleware')
                ->add_column_value('lastresponse')
                ->add_column_value('state')
                ->add_column_value('rightanswer')
                ->add_column_value('responsesummary')
                ->add_column_value('originalfraction')
                ->add_column_value('fraction')
                ->add_column_value('questionattemptid')
                ->add_column_value('invaliditemparams')
                ->as_csv_string();
        }
        $heading = implode(';', $this->columns) . PHP_EOL;
        $csvstring = $heading . $csvstring;

        $attemptid = $data['attemptid'];

        // Resolve the context of this attempt through the central
        // resolver instead of querying the context table by hand with a hardcoded
        // context level, which silently produced no context when the attempt had
        // no course or the course context row was not found.
        $contextid = context_resolver::for_attempt($attemptid, $this->get_component())->id;

        $descriptionheading = get_string('debuginfo_desc_title', 'local_catquiz', $this->get_progress()->get_attemptid());
        $description = get_string('debuginfo_desc', 'local_catquiz');
        // Decision of 2026-10-14: the figures of the attempt for teachers, above the exports. With pilot
        // questions switched on, a second column counts the pilot items, which are not scored.
        $pilotids = array_keys($this->get_progress()->get_played_pilot_questions());
        $figures = attempt_statistics::for_attempt((int) $attemptid, $pilotids);
        $haspilots = !empty($this->get_progress()->get_quiz_settings()->catquiz_includepilotquestions)
            || $figures->pilots > 0;
        $statistics = [];
        foreach (
            [
                'right' => 'numberofanswerscorrect',
                'wrong' => 'numberofanswersincorrect',
                'partial' => 'numberofanswerspartlycorrect',
                'unanswered' => 'numberofanswersunanswered',
            ] as $key => $label
        ) {
            $statistics[] = [
                'label' => get_string($label, 'local_catquiz'),
                'value' => $figures->$key,
                'pilotvalue' => $figures->pilot[$key],
            ];
        }
        $statistics[] = [
            'label' => get_string('statistics_duration', 'local_catquiz'),
            'value' => attempt_statistics::format_seconds($figures->duration),
            'pilotvalue' => '',
        ];
        $statistics[] = [
            'label' => get_string('statistics_timeperitem', 'local_catquiz', $figures->itemcount),
            'value' => $figures->itemsd === null
                ? attempt_statistics::format_seconds($figures->itemmean)
                : attempt_statistics::format_seconds($figures->itemmean) . ' ± '
                    . attempt_statistics::format_seconds($figures->itemsd),
            'pilotvalue' => '',
        ];

        $feedback = $OUTPUT->render_from_template(
            'local_catquiz/feedback/exportattempt',
            [
                'statistics' => $statistics,
                'haspilots' => $haspilots,
                'data' => rawurlencode($csvstring),
                'cid' => $contextid,
                'attemptid' => $attemptid,
                'description' => $description,
                'debuginfo_raw' => rawurlencode(nl2br(str_replace(" ", "&nbsp;", var_export($data, true)))),
                // Issue #32: full addresses instead of a root to glue paths to.
                'exportcsvurl' => (new \moodle_url(
                    '/local/catquiz/export_attempt_csv.php',
                    ['attemptid' => $attemptid]
                ))->out(false),
                'exportpdfurl' => (new \moodle_url(
                    '/local/catquiz/export_debuginfo_pdf.php',
                    ['attemptid' => $attemptid, 'cid' => $contextid]
                ))->out(false),
                'isteacher' => true,
                'debugenabled' => $debugenabled,
                'iscatmanager' => $debugenabled && has_capability(
                    'local/catquiz:canmanage',
                    context_system::instance()
                ),
            ]
        );

        if (empty($feedback)) {
            return [];
        } else {
            return [
                'heading' => $this->get_heading(),
                'content' => $feedback,
            ];
        }
    }

    /**
     * Converts from a possible old format to a new one.
     *
     * @param array $row The current row
     * @param array $texts Right and given answer by question attempt id (texts_of_the_usage()).
     * @param array $names Names of scales and labels of items the rows name by id (names_of_the_rows()).
     * @return array
     */
    private function convert(array $row, array $texts = [], array $names = ['scales' => [], 'labels' => []]): array {
        $updated = $row;
        if (is_array($row['lastresponse'] ?? null) && array_key_exists('fraction', $row['lastresponse'])) {
            $updated['lastresponse'] = $row['lastresponse']['fraction'];
        }
        // Issue #177 (D): a newer row names scales and items by id; the names are those of today, read for the export.
        $scalename = fn($scaleid) => (string) ($names['scales'][(int) $scaleid] ?? $scaleid);
        if (is_array($row['personabilities'] ?? null)) {
            $parts = [];
            foreach ($row['personabilities'] as $scaleid => $ability) {
                $parts[] = $scalename($scaleid) . ': ' . $ability;
            }
            $updated['personabilities'] = '"' . implode(', ', $parts) . '"';
        }
        if (is_array($row['activescales'] ?? null)) {
            $updated['activescales'] = '"' . implode(', ', array_map($scalename, $row['activescales'])) . '"';
        }
        if (is_array($row['invaliditemparams'] ?? null)) {
            $warnings = [];
            foreach ($row['invaliditemparams'] as $warning) {
                $warning = (array) $warning;
                $warning['label'] = $names['labels'][(int) ($warning['itemid'] ?? 0)] ?? '';
                $warnings[] = $warning;
            }
            $updated['invaliditemparams'] = self::format_invalid_itemparams($warnings);
        }
        // Issue #177 (D): a row stored before keeps its texts; a newer one gets them from the question usage.
        $qaid = is_numeric($row['questionattemptid'] ?? null) ? (int) $row['questionattemptid'] : null;
        foreach (['rightanswer', 'responsesummary'] as $field) {
            if (!array_key_exists($field, $row) && $qaid !== null && isset($texts[$qaid])) {
                $value = $texts[$qaid]->$field;
                $updated[$field] = $value === null ? self::NA : trim((string) $value);
            }
        }
        return $updated;
    }

    /**
     * The names of the scales and the labels of the items the rows name by id (issue #177, D) - two queries for the
     * whole export, the names of today.
     *
     * @param array $rows
     * @return array 'scales' => [scale id => name], 'labels' => [question id => label]
     */
    private static function names_of_the_rows(array $rows): array {
        global $DB;
        $scales = [];
        $items = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            foreach (array_keys(is_array($row['personabilities'] ?? null) ? $row['personabilities'] : []) as $scaleid) {
                $scales[(int) $scaleid] = true;
            }
            foreach (is_array($row['activescales'] ?? null) ? $row['activescales'] : [] as $scaleid) {
                $scales[(int) $scaleid] = true;
            }
            foreach (is_array($row['invaliditemparams'] ?? null) ? $row['invaliditemparams'] : [] as $warning) {
                $items[(int) (((array) $warning)['itemid'] ?? 0)] = true;
            }
        }
        $names = ['scales' => [], 'labels' => []];
        if ($scales) {
            $records = $DB->get_records_list('local_catquiz_catscales', 'id', array_keys($scales), '', 'id, name');
            $names['scales'] = array_map(fn($scale) => (string) $scale->name, $records);
        }
        unset($items[0]);
        if ($items) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($items));
            $names['labels'] = $DB->get_records_sql_menu(
                "SELECT qv.questionid, qbe.idnumber
                   FROM {question_versions} qv
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  WHERE qv.questionid $insql",
                $params
            );
        }
        return $names;
    }

    /**
     * The fields of a question that are numbers, identities or codes - what a debug row keeps of it (issue #177, D):
     * no label, no name, no text of the question bank.
     *
     * @param array $question
     * @return array
     */
    private static function numbers_of(array $question): array {
        $kept = [];
        foreach ($question as $key => $value) {
            if ($key === 'model' || $key === 'qtype') {
                $kept[$key] = $value;
            } else if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $kept[$key] = $value;
            } else if (is_string($value) && is_numeric($value)) {
                $kept[$key] = $value;
            } else if (is_array($value) || is_object($value)) {
                $numbers = array_filter((array) $value, fn($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)));
                if (count($numbers) === count((array) $value)) {
                    $kept[$key] = $numbers;
                }
            }
        }
        return $kept;
    }

    /**
     * The right and the given answer of the question attempts the rows name and do not hold themselves (issue #177,
     * D) - one query for the whole export.
     *
     * @param array $rows
     * @return array Question attempt id => record with rightanswer and responsesummary.
     */
    private static function texts_of_the_usage(array $rows): array {
        global $DB;
        $ids = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!array_key_exists('responsesummary', $row) && is_numeric($row['questionattemptid'] ?? null)) {
                $ids[(int) $row['questionattemptid']] = true;
            }
        }
        if (!$ids) {
            return [];
        }
        $texts = [];
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            $texts += $DB->get_records_list('question_attempts', 'id', $chunk, '', 'id, rightanswer, responsesummary');
        }
        return $texts;
    }

    /**
     * Formats the collected "unusable item parameters" warnings for the debug output.
     *
     * pilotquestions_loader collects a warning whenever an item had to be demoted to
     * a pilot item because its stored parameters violate its model contract (for
     * example a 2PL item with discrimination 0, which is mathematically mute and
     * would freeze the ability estimate). Surfacing item id, model and the concrete
     * reason here makes such an item traceable from the attempt debug export/PDF.
     *
     * @param array $warnings
     *
     * @return string
     */
    private static function format_invalid_itemparams(array $warnings): string {
        if ($warnings === []) {
            return self::NA;
        }

        $lines = [];
        foreach ($warnings as $warning) {
            $lines[] = sprintf(
                '%s (%s / model "%s"): %s',
                (string) ($warning['itemid'] ?? '?'),
                (string) ($warning['label'] ?? ''),
                (string) ($warning['model'] ?? ''),
                (string) ($warning['reason'] ?? '')
            );
        }

        return '"' . implode('; ', $lines) . '"';
    }

    /**
     * Sets the data for the current row
     *
     * Used by add_column_value()
     *
     * @param array $row
     * @return self
     */
    private function set_row_data(array $row): self {
        $this->rowdata = $row;
        $this->row = [];
        return $this;
    }

    /**
     * Adds a value to the current row
     *
     * @param string $key
     * @return $this
     */
    private function add_column_value(string $key): self {
        if (!in_array($key, $this->columns)) {
            $this->columns[] = $key;
        }
        $this->row[] = $this->rowdata[$key] ?? self::NA;
        return $this;
    }

    /**
     * Implodes the current row by semicolon
     *
     * @return string
     */
    private function as_csv_string(): string {
        return implode(';', $this->row) . PHP_EOL;
    }

    /**
     * Get heading.
     *
     * @return string
     *
     */
    public function get_heading(): string {
        return get_string('statisticsexport', 'local_catquiz');
    }

    /**
     * The export tab for authorised users; it marks an invalid result, it does not need a valid one. (issue #120)
     *
     * @return string
     */
    public function get_result_dependency(): string {
        return self::DIAGNOSTIC;
    }

    /**
     * Teacher feedback only (issue #85).
     *
     * @return string[]
     */
    public function get_audiences(): array {
        return [self::AUDIENCE_TEACHER];
    }

    /**
     * Get generatorname.
     *
     * @return string
     *
     */
    public function get_generatorname(): string {
        return 'debuginfo';
    }

    /**
     * Recorded with every answer: one entry per answer, which cannot be rebuilt afterwards (issue #168).
     *
     * @return bool
     */
    public function records_every_answer(): bool {
        return true;
    }

    /**
     * Get required context keys.
     *
     * @return array
     *
     */
    public function get_required_context_keys(): array {
        // The export of the attempt needs nothing but the attempt (issue #120). The rows of the debug
        // storage are an addition when it is switched on; requiring them hid the export tab - also for
        // invalid attempts, the ones most in need of it - wherever debug storage was off.
        return [];
    }

    /**
     * Get question details.
     *
     * @param array $context
     *
     * @return mixed
     *
     */
    private function get_question_details(array $context) {
        return array_map(fn ($q) => (array) $q, $context['questions']);
    }

    /**
     * Load data.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param array $existingdata
     * @param array $newdata
     *
     * @return array|null
     *
     */
    public function load_data(int $adaptiveattemptid, array $existingdata, array $newdata): ?array {

        // Note: This has to be redone as well.
        if (!get_config('local_catquiz', 'store_debug_info')) {
            return null;
        }

        $teststrategy = $this->get_progress()->get_quiz_settings()->catquiz_selectteststrategy;

        $teststrategies = info::return_available_strategies();
        $teststrategy = array_filter(
            $teststrategies,
            fn ($t) => $t->id == $teststrategy
        );
        $reflect = new \ReflectionClass($teststrategy[array_key_first($teststrategy)]);

        // Issue #177: the rows of a running attempt are events of its progress - the column is written once, at the end
        // (catquiz::save_attempt_to_db()), not with every answer. An attempt that was running during the update brings
        // the rows of the column along, once.
        $progress = $this->get_progress();
        $debuginfo = $progress->get_debug_rows();
        if (!$debuginfo && !empty($existingdata['debuginfo'])) {
            foreach ((array) $existingdata['debuginfo'] as $earlier) {
                $progress->add_debug_row((array) $earlier);
            }
            $debuginfo = $progress->get_debug_rows();
        }
        $teststrategy = get_string($reflect->getShortName(), 'local_catquiz');

        // Issue #177 (D): scales by id, with the ability - the names are read when the rows are exported (convert()).
        $personabilities = [];
        foreach ($newdata['person_ability'] as $catscaleid => $pp) {
            $personabilities[$catscaleid] = $pp;
        }

        $questions = [];
        $questionsperscale = [];

        $activescales = array_values(array_map('intval', $progress->get_active_scales()));
        $lastresponse = $progress->get_last_response();
        $row = [
            'pluginversion' => get_config('local_catquiz')->version ?? self::NA,
            'attemptid' => $adaptiveattemptid,
            'questionsattempted' => count($this->get_progress()->get_playedquestions()),
            'timestamp' => time(),
            'personabilities' => $personabilities,
            'questions' => $questions,
            'activescales' => $activescales,
            // On the first question of an attempt these keys do not exist,
            // and catquiz.php removes lastquestion from the attempt data outright. On
            // a normal instance (array) null is simply [], but with DEBUG_DEVELOPER
            // Moodle turns the notice into an exception. It is caught in
            // return_next_testitem() and reported as "couldn't define the first
            // question", pointing at the configuration instead of at this diagnostic
            // helper - so debug information was unobtainable exactly where it is
            // wanted. The neighbouring fields have always been guarded.
            'lastquestion' => self::numbers_of((array) ($newdata['lastquestion'] ?? [])),
            'lastmiddleware' => $newdata['lastmiddleware'] ?? self::NA,
            'lastresponse' => isset($lastresponse) ? $lastresponse['fraction'] : self::NA,
            'numquestionsperscale' => '"'
                . implode(", ", array_map(fn ($entry) => $entry['name'] . ": " . $entry['num'], $questionsperscale)) . '"',
            'state' => isset($lastresponse['state']) ? $lastresponse['state'] : self::NA,
            // Issue #177 (D): the right and the given answer are texts of the question usage; they are read from there
            // by the question attempt id when the export is built (get_teacherfeedback()), not stored with every answer.
            'originalfraction' => isset($lastresponse['originalfraction']) ? $lastresponse['originalfraction'] : self::NA,
            'fraction' => isset($lastresponse['fraction']) ? $lastresponse['fraction'] : self::NA,
            'questionattemptid' => isset($lastresponse['questionattemptid']) ? $lastresponse['questionattemptid'] : self::NA,
            // Issue #177 (D): the item, its model and the reason; its label is read when the rows are exported.
            'invaliditemparams' => array_values(array_map(
                fn($warning) => array_diff_key((array) $warning, ['label' => true]),
                (array) ($newdata['invaliditemparams'] ?? [])
            )),
        ];
        $progress->add_debug_row($row);
        $debuginfo[] = $row;

        return ['debuginfo' => $debuginfo];
    }

    /**
     * Overwrite the inherited method to allow access only to CAT managers.
     *
     * @return bool
     */
    protected function has_teacherfeedbackpermission(): bool {
        // The debug output exposes internals of the estimation, so a CAT
        // manager always sees it. In addition a teacher of the course the attempt
        // belongs to may see it - checked in the attempt's own context, never in
        // the system context.
        //
        // This method previously contained the course based check behind an
        // unconditional return, so it was dead code that never ran (and would have
        // fatalled on the private $attemptid property if it had).
        if (has_capability('local/catquiz:canmanage', context_system::instance())) {
            return true;
        }

        return has_capability('local/catquiz:view_users_feedback', $this->get_attempt_context());
    }

    /**
     * Overwrite the inherited method to allow access only to CAT managers.
     *
     * @return bool
     */
    protected function has_catmanagerpermission(): bool {
        return has_capability(
            'local/catquiz:canmanage',
            context_system::instance()
        );
    }
}
