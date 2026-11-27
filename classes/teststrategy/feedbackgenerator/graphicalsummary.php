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
 * Class graphicalsummary.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\feedbackgenerator;

use local_catquiz\local\attempt\history_completion;
use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use local_catquiz\catscale;
use local_catquiz\teststrategy\feedback_helper;
use local_catquiz\teststrategy\feedbackgenerator;
use local_catquiz\teststrategy\feedbacksettings;
use local_catquiz\teststrategy\info;

/**
 * Compare the ability of this attempt to the average abilities of other students that took this test.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class graphicalsummary extends feedbackgenerator {
    /**
     * Get student feedback.
     *
     * @param array $feedbackdata
     *
     * @return array
     *
     */
    public function get_studentfeedback(array $feedbackdata): array {
        global $OUTPUT;

        $rows = array_values((array) ($feedbackdata['graphicalsummary_data'] ?? []));
        $otherscales = $feedbackdata['graphicalsummary_otherscales'] ?? [];
        // Issue #159 (re-audit of 22.11.2026): the history of a finished attempt can lack rows of answers it had, or be
        // missing - old data. It is completed for the display, read only (history_completion).
        $catattemptid = $this->get_progress()->get_catattemptid();
        if ($catattemptid) {
            [$rows, $otherscales] = history_completion::complete($rows, $otherscales, (int) $catattemptid);
        }
        if ($rows) {
            $pilots = $this->pilot_flags($rows);
            [$chartrows, $otherscales] = self::without_pilots($rows, $pilots, $otherscales);
            $primaryscaleid = null;
            if (array_key_exists('graphicalsummary_primaryscale', $feedbackdata)) {
                $primaryscaleid = array_key_first($feedbackdata['graphicalsummary_primaryscale']);
            }
            if ($chartrows) {
                $chart = $this->render_chart($chartrows, $primaryscaleid, $otherscales);
            }
            $showquestions = boolval(
                $this
                    ->get_progress()
                    ->get_quiz_settings()
                    ->catquiz_showquestion ?? false
            );
            $table = $this->render_table($rows, $showquestions, $pilots, $this->has_teacherfeedbackpermission());
        }
        $globalscale = catscale::return_catscale_object($this->get_progress()->get_quiz_settings()->catquiz_catscales);
        $globalscalename = $globalscale->name;

        $data['chart'] = $chart ?? "";
        $data['table'] = $table ?? "";
        $data['description'] = get_string(
            'graphicalsummary_description',
            'local_catquiz',
            $globalscalename
        );
        // If this is a deficit strategy, display more info.
        $additionalinfo = false;
        if (
            array_key_exists('graphicalsummary_primaryscale', $feedbackdata)
            && isset($feedbackdata['primaryscale']->name)
        ) {
            $primaryscale = reset($feedbackdata['graphicalsummary_primaryscale']);
            $quoteddeficitscale = feedback_helper::add_quotes($feedbackdata['primaryscale']->name);
            if (
                $primaryscale
                && array_key_exists('primarybecause', $primaryscale)
                && $primaryscale['primarybecause'] == 'lowestskill'
            ) {
                $additionalinfo = get_string('graphicalsummary_description_lowest', 'local_catquiz', $quoteddeficitscale);
            }
        }
        $data['additional_info'] = $additionalinfo;

        $feedback = $OUTPUT->render_from_template(
            'local_catquiz/feedback/graphicalsummary',
            $data
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
     * Get teacher feedback.
     *
     * @param array $feedbackdata
     *
     * @return array
     *
     */
    protected function get_teacherfeedback(array $feedbackdata): array {
        return [];
    }

    /**
     * For specific feedbackdata defined in generators.
     *
     * @param array $feedbackdata
     */
    public function apply_settings_to_feedbackdata(array $feedbackdata) {

        // Exclude feedbackkeys from feedbackdata.
        $feedbackdata = $this->feedbacksettings->hide_defined_elements($feedbackdata, $this->get_generatorname());
        return $feedbackdata;
    }

    /**
     * Get heading.
     *
     * @return string
     *
     */
    public function get_heading(): string {
        return get_string('quizgraphicalsummary', 'local_catquiz');
    }

    /**
     * The quiz history: the questions of the attempt one by one; no result needed. (issue #120)
     *
     * @return string
     */
    public function get_result_dependency(): string {
        return self::DEPENDS_ON_ATTEMPT;
    }

    /**
     * Get generatorname.
     *
     * @return string
     *
     */
    public function get_generatorname(): string {
        return 'graphicalsummary';
    }

    /**
     * Get required context keys.
     *
     * @return array
     *
     */
    public function get_required_context_keys(): array {
        return [
            'graphicalsummary_data',
            'teststrategyname',
            'personabilities',
        ];
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
        $progress = $this->get_progress();

        // If we already have all the data, just return them instead of adding
        // the last response again.
        $playedquestions = $progress->get_playedquestions();
        $rows = $existingdata['graphicalsummary_data'] ?? [];
        if (array_key_exists('graphicalsummary_data', $existingdata) && count($rows) === count($playedquestions)) {
            return $existingdata;
        }

        /* Issue #168 (decision of 21.11.2026): the history is no longer written into the feedback data with every
           answer - the whole data were written again with each answer, and the history was most of it. The progress
           records the state after each answer (progress::record_answer_state()); the rows are built here, when the
           attempt ends or a running attempt is looked at. Rows stored before - an attempt that was running during
           the update - stay as they are; a question gets its row once. */
        $responses = $progress->get_responses();
        $otherscales = $existingdata['graphicalsummary_otherscales'] ?? [];
        $have = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $have[(string) $row['id']] = true;
            }
        }
        $added = false;
        // Issue #177 (D): the rows hold no titles, labels or names of scales - render_table() reads them, for all rows at
        // once, when the table is shown.
        foreach ($progress->get_answer_history() as $entry) {
            $qid = (string) $entry['q'];
            if (isset($have[$qid]) || empty($playedquestions[$qid]) || !isset($responses[$qid])) {
                continue;
            }
            $rows[] = $this->row_of($playedquestions[$qid], (array) $responses[$qid], $entry['a'], $existingdata);
            foreach ((array) $entry['s'] as $scaleid => $value) {
                $otherscales[$scaleid][] = $value;
            }
            $have[$qid] = true;
            $added = true;
        }
        if (!$added && !array_key_exists('graphicalsummary_data', $existingdata)) {
            return null;
        }

        $abilitieslist = $this->select_scales_for_report($newdata, $this->feedbacksettings, $existingdata['teststrategy']);
        $primaryscale = array_filter($abilitieslist, fn ($a) => array_key_exists('primary', $a) && $a['primary'] === true);

        $teststrategyname = get_string(
            'teststrategy',
            'local_catquiz',
            info::get_teststrategy($existingdata['teststrategy'])
            ->get_description()
        );

        return [
            'graphicalsummary_data' => $rows,
            'teststrategyname' => $teststrategyname,
            'personabilities' => $progress->get_abilities(true),
            'graphicalsummary_primaryscale' => $primaryscale,
            'graphicalsummary_otherscales' => $otherscales,
            'primaryscale' => $this->get_primary_scale($existingdata, $newdata),
        ];
    }

    /**
     * The row of the history for an answered question - as it was written with the answer up to issue #168.
     *
     * @param \stdClass $question The played question.
     * @param array $response Its response in the progress.
     * @param float|null $abilityafter The ability of the root scale after the answer (rounded).
     * @param array $existingdata
     * @return array
     */
    private function row_of(\stdClass $question, array $response, ?float $abilityafter, array $existingdata): array {
        $new = [];
        $new['id'] = $question->id;
        // Issue #177 (D): no label, title or name of the scale in the stored row - texts of the question bank and of the
        // scales, read when the table is shown (render_table()), as they are then. Rows stored before keep theirs.
        $new['lastresponse'] = round($response['fraction'], self::PRECISION);
        // A pilot item is shown to teachers only and kept out of the chart (decision of 2026-10-14).
        $new['pilot'] = !empty($question->is_pilot);
        // Store the real QUBA slot and question attempt id so the
        // "show question" modal fetches exactly this question attempt instead of
        // reconstructing the slot from the table row index (which is wrong after
        // reloads, duplicate slots or missing rows). Both are absent for legacy attempts.
        // Issue #177 (D): the given answer is not stored with the row - it is a text of the question usage, read from
        // there when the table is shown (render_table()).
        $new['slot'] = $response['slot'] ?? null;
        $new['questionattemptid'] = $response['questionattemptid'] ?? null;
        // Issue #177 (C): a number, whichever way the played question was read (the question bank or an event).
        $new['difficulty'] = is_numeric($question->difficulty ?? null) ? (float) $question->difficulty : null;
        $new['questionscale'] = $question->catscaleid;
        if (
            property_exists($question, 'fisherinformation')
            && is_float($question->fisherinformation)
        ) {
            $new['fisherinformation'] = sprintf('%.2f', $question->fisherinformation);
        } else {
            $new['fisherinformation'] = $question->is_pilot
                ? null
                : $this->get_rounded_or_null((array) $question->fisherinformation, $existingdata['catscaleid']);
        }
        $new['personability_after'] = $abilityafter;
        return $new;
    }

    /**
     * Whether each row of the quiz history is a pilot item.
     *
     * Rows stored since 2026-10-14 carry the flag; for older rows the played pilot items of the
     * progress tell.
     *
     * @param array $rows
     * @return bool[] By row index.
     */
    private function pilot_flags(array $rows): array {
        $pilotids = null;
        $flags = [];
        foreach ($rows as $index => $row) {
            if (array_key_exists('pilot', $row)) {
                $flags[$index] = (bool) $row['pilot'];
                continue;
            }
            if ($pilotids === null) {
                $pilotids = array_map('intval', array_keys($this->get_progress()->get_played_pilot_questions()));
            }
            $flags[$index] = isset($row['id']) && in_array((int) $row['id'], $pilotids, true);
        }
        return $flags;
    }

    /**
     * The rows and the series of the other scales without the pilot items, for the chart.
     *
     * A series of another scale has one value per row from the row where the scale first appeared:
     * it is aligned with the end of the rows.
     *
     * @param array $rows
     * @param bool[] $pilots By row index.
     * @param array $otherscales Values by scale id.
     * @return array [rows, otherscales]
     */
    public static function without_pilots(array $rows, array $pilots, array $otherscales): array {
        $keep = [];
        foreach (array_values($rows) as $index => $row) {
            if (empty($pilots[$index])) {
                $keep[] = $index;
            }
        }
        $chartrows = array_map(fn($index) => $rows[$index], $keep);
        $series = [];
        foreach ($otherscales as $scaleid => $values) {
            $values = array_values($values);
            $offset = count($rows) - count($values);
            $series[$scaleid] = array_values(array_filter(
                array_map(fn($index) => $index - $offset >= 0 ? ($values[$index - $offset] ?? null) : false, $keep),
                fn($value) => $value !== false
            ));
        }
        return [$chartrows, $series];
    }

    /**
     * Render the moodle charts.
     *
     * @param array $data
     * @param ?int $primaryscaleid
     * @param array $otherscales
     *
     * @return string
     */
    private function render_chart(array $data, ?int $primaryscaleid, array $otherscales) {
        global $OUTPUT;

        $chart = new \core\chart_line();
        $chart->set_smooth(true); // Calling set_smooth() passing true as parameter, will display smooth lines.

        /* Issue #152: rows stored since personability_after existed carry the ability in the global scale, older rows
           the ability in the tested scale as personability. They are different values: each has its own series, and a
           row without it is a gap - nothing is carried over from the other. Before, the format of the first row
           decided for all, and the branch of the older format ended in a TypeError. */
        $abilitiesafter = array_map(fn($round) => $round['personability_after'] ?? null, $data);
        $abilities = array_map(
            fn($round) => array_key_exists('personability_after', $round) ? null : ($round['personability'] ?? null),
            $data
        );
        $hasvalue = fn(array $values): bool => (bool) array_filter($values, fn($value) => $value !== null);
        $series = 0;
        if ($hasvalue($abilitiesafter)) {
            $chart->add_series(new \core\chart_series(get_string('abilityinglobalscale', 'local_catquiz'), $abilitiesafter));
            $series++;
        }
        if ($hasvalue($abilities)) {
            $chart->add_series(new \core\chart_series(get_string('abilityintestedscale', 'local_catquiz'), $abilities));
            $series++;
        }

        $globalscaleid = $this->get_progress()->get_quiz_settings()->catquiz_catscales;
        $addprimary = $primaryscaleid
            && array_key_exists($primaryscaleid, $otherscales)
            && $primaryscaleid != $globalscaleid;
        if ($addprimary) {
            // Fill the missing values from the start with null values.
            $primaryvalues = array_pad($otherscales[$primaryscaleid], -count($data), null);
            $primarychart = new \core\chart_series(
                catscale::return_catscale_object($primaryscaleid)->name,
                $primaryvalues
            );
            $chart->add_series($primarychart);
            $series++;
        }
        if (!$series) {
            // No ability in any row: no chart, the table says what was asked.
            return '';
        }

        // One label per row (issue #152: the labels counted $abilitiesafter, which the older format never set).
        $chart->set_labels(range(1, count($data)));

        return html_writer::tag('div', $OUTPUT->render_chart($chart, false), ['dir' => 'ltr']);
    }

    /**
     * Render a table with data that do not fit in the chart
     *
     * Pilot items are shown to teachers only: without a number - the numbers follow the chart, which
     * leaves them out -, with a grey verdict and without an ability, since they do not change it.
     *
     * @param array $data The feedback data
     * @param bool $viewquestion Adds links and modal to display questions.
     * @param bool[] $pilots Whether each row is a pilot item, by row index.
     * @param bool $showpilots Whether pilot items are shown (teachers).
     * @return ?string If all required data are present, the rendered HTML table.
     */
    private function render_table($data, $viewquestion = true, array $pilots = [], bool $showpilots = false): ?string {
        if (!$data || ! array_key_exists('id', $data[0])) {
            return null;
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable catquiz-graphicalsummary-table';

        // The correctness indicator is only shown when the test is configured to
        // reveal whether the given answer was right ("Indikator zur Korrektheit
        // der gegebenen Antwort anzeigen").
        $showresponse = (bool) (
            $this->get_progress()->get_quiz_settings()
                ->catquiz_questionfeedbacksettings
                ->catquiz_showquestionresponse ?? false
        );

        $table->colclasses = [
            'catquiz-col-number',
        ];
        if ($showresponse) {
            $table->colclasses[] = 'catquiz-col-correctness';
        }
        $table->colclasses = array_merge($table->colclasses, [
            'catquiz-col-question',
            'catquiz-col-scale',
            'catquiz-col-ability',
        ]);
        if ($viewquestion) {
            $table->colclasses[] = 'catquiz-col-action';
        }
        $table->head = [
            get_string('feedback_table_questionnumber', 'local_catquiz'),
        ];
        if ($showresponse) {
            $table->head[] = get_string('feedback_table_correctness', 'local_catquiz');
        }
        $table->head = array_merge($table->head, [
            get_string('question'),
            get_string('catscale', 'local_catquiz'),
            get_string('personability', 'local_catquiz'),
        ]);

        if ($viewquestion) {
            $table->head[] = get_string('showquestion', 'local_catquiz');
        }

        $tabledata = [];
        $filtercontext = \context_system::instance();
        // Resolve legacy rows (stored before the slot/question attempt
        // id were persisted) against the real question usage instead of guessing
        // the slot from the row index. Built once for the whole table.
        $slotmap = $this->build_slot_map_from_quba();
        // Issue #177 (D): the given answers by question attempt, from the same usage.
        $summaries = [];
        foreach ($slotmap as $occurrencesofquestion) {
            foreach ($occurrencesofquestion as $entry) {
                $summaries[$entry['questionattemptid']] = $entry['responsesummary'];
            }
        }
        // Issue #177 (D): the texts a row does not hold - the name of the question version its slot used, the label of
        // the item, the name of the scale - for all rows at once.
        $versionnames = [];
        foreach ($slotmap as $occurrencesofquestion) {
            foreach ($occurrencesofquestion as $entry) {
                $versionnames[(int) $entry['questionattemptid']] = $entry['name'];
            }
        }
        [$labels, $titles, $scalenames] = self::texts_of_the_rows($data);
        $occurrences = [];
        $number = 0;
        foreach ($data as $index => $values) {
            $ispilot = !empty($pilots[$index]);
            if ($ispilot && !$showpilots) {
                continue;
            }
            $slot = $values['slot'] ?? null;
            $questionattemptid = $values['questionattemptid'] ?? null;
            $resolvedtitle = $values['questiontitle']
                ?? (is_numeric($questionattemptid) ? ($versionnames[(int) $questionattemptid] ?? null) : null);
            $questionid = isset($values['id']) ? (int) $values['id'] : null;
            if ($questionid !== null && ($slot === null || $questionattemptid === null)) {
                $occurrence = $occurrences[$questionid] ?? 0;
                if (isset($slotmap[$questionid][$occurrence])) {
                    $resolved = $slotmap[$questionid][$occurrence];
                    $slot = $slot ?? $resolved['slot'];
                    $questionattemptid = $questionattemptid ?? $resolved['questionattemptid'];
                    $resolvedtitle = $resolvedtitle ?? $resolved['name'];
                }
            }
            if ($questionid !== null) {
                $occurrences[$questionid] = ($occurrences[$questionid] ?? 0) + 1;
            }
            // Only if the usage could not resolve the row at all (e.g. the usage
            // has been purged) do we fall back to the row index / zero.
            $slot = $slot ?? ($index + 1);
            $questionattemptid = $questionattemptid ?? 0;
            // Correctness indicator: icon plus a screen-reader accessible text, so
            // the verdict is not conveyed by colour/shape alone. The given answer
            // stays available as a tooltip; the response summary can contain
            // TeX/STACK markup, so it goes through format_text with the active
            // filters (MathJax). format_text also cleans the HTML.
            $responsestring = get_string('feedback_table_answerincorrect', 'local_catquiz');
            $iconclass = 'fa-solid fa-circle-xmark text-danger';
            if ($values['lastresponse'] == 1) {
                $responsestring = get_string('feedback_table_answercorrect', 'local_catquiz');
                $iconclass = 'fa-solid fa-circle-check text-success';
            } else if ($values['lastresponse'] > 0) {
                $responsestring = get_string('feedback_table_answerpartlycorrect', 'local_catquiz');
                $iconclass = 'fa-solid fa-triangle-exclamation text-warning';
            }
            if ($ispilot) {
                // Grey: the verdict is shown, but it does not count.
                $iconclass = preg_replace('/\btext-(success|danger|warning)\b/', 'text-secondary', $iconclass);
                $responsestring .= ' (' . get_string('feedback_table_pilot', 'local_catquiz') . ')';
            }

            $correctnesscell = null;
            if ($showresponse) {
                $icon = html_writer::tag('i', '', [
                    'class' => $iconclass . ' catquiz-response-icon',
                    'aria-hidden' => 'true',
                    'title' => $responsestring,
                ]);
                $correctnesshtml = $icon . html_writer::tag(
                    'span',
                    $responsestring,
                    ['class' => 'visually-hidden catquiz-response-verdict']
                );

                // A row stored before #177 may hold the given answer; else it is the one of the question usage.
                $responsesummary = $values['responsesummary'] ?? ($summaries[(int) $questionattemptid] ?? null);
                if ($responsesummary !== null && $responsesummary !== '') {
                    $answerhtml = format_text(
                        $responsesummary,
                        FORMAT_HTML,
                        ['filter' => true, 'context' => $filtercontext]
                    );
                    $correctnesshtml .= html_writer::tag(
                        'span',
                        get_string('feedback_table_givenanswer', 'local_catquiz') . ' ',
                        ['class' => 'visually-hidden catquiz-response-answerlabel']
                    ) . html_writer::tag(
                        'span',
                        $answerhtml,
                        ['class' => 'visually-hidden catquiz-responsesummary']
                    );
                }

                $correctnesscell = new html_table_cell($correctnesshtml);
                $correctnesscell->attributes = ['class' => 'catquiz-col-correctness text-center'];
            }

            // Question column: the real Moodle question title as primary text and
            // the technical CAT item label as secondary information. Legacy rows
            // without a stored title fall back to the label.
            $itemlabel = (string) ($values['questionname'] ?? ($labels[(int) ($values['id'] ?? 0)] ?? ''));
            $title = (string) ($resolvedtitle ?? ($titles[(int) ($values['id'] ?? 0)] ?? $itemlabel));
            $questionhtml = html_writer::tag('span', s($title), ['class' => 'catquiz-question-title']);
            if ($itemlabel !== '' && $itemlabel !== $title) {
                $questionhtml .= html_writer::empty_tag('br')
                    . html_writer::tag('span', s($itemlabel), ['class' => 'catquiz-question-label text-muted']);
            }
            $dataattributes = [
                'data-name' => $title,
                'data-attemptid' => $this->get_progress()->get_attemptid(),
                'data-slot' => $slot,
                'data-questionattemptid' => $questionattemptid,
            ];
            $questioncell = $viewquestion
                ? html_writer::tag('span', $questionhtml, ['class' => 'clickable'] + $dataattributes)
                : $questionhtml;
            $numbercell = new html_table_cell($ispilot
                ? html_writer::tag('span', get_string('feedback_table_pilot', 'local_catquiz'), ['class' => 'visually-hidden'])
                : (string) ++$number);
            // Rows of the older format carry the ability as personability (issue #152).
            $ability = $values['personability_after'] ?? $values['personability'] ?? null;
            // A row completed for the display (issue #159): the ability after the answer was not stored.
            $notrecorded = html_writer::tag(
                'span',
                get_string('feedback_table_notrecorded', 'local_catquiz'),
                ['class' => 'text-muted']
            );
            $newrow = [
                $numbercell,
            ];
            if ($correctnesscell !== null) {
                $newrow[] = $correctnesscell;
            }
            $newrow = array_merge($newrow, [
                $questioncell,
                $values['questionscale_name'] ?? ($scalenames[(int) ($values['questionscale'] ?? 0)] ?? ''),
                $ispilot ? '' : ($ability !== null ? sprintf('%.2f', $ability) : (!empty($values['source']) ? $notrecorded : '')),
            ]);

            if ($viewquestion) {
                // Real interactive button (not a bare icon) so it works inside a
                // form and is reachable for assistive technology.
                $button = html_writer::tag(
                    'button',
                    html_writer::tag('i', '', ['class' => 'fa fa-search', 'aria-hidden' => 'true']),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-link p-0 questionbutton clickable',
                        'aria-label' => get_string('showquestion', 'local_catquiz'),
                        'title' => get_string('showquestion', 'local_catquiz'),
                    ] + $dataattributes
                );
                $searchcol = new html_table_cell($button);
                $searchcol->attributes = ['class' => 'questionbutton catquiz-col-action'];
                $newrow[] = $searchcol;
            }
            $row = new html_table_row($newrow);
            if ($ispilot) {
                $row->attributes['class'] = 'catquiz-pilot-row text-muted';
            }
            $tabledata[] = $row;
        }
        $table->data = $tabledata;
        return html_writer::div(
            html_writer::table($table),
            'table-responsive catquiz-graphicalsummary-table-wrapper'
        );
    }

    /**
     * The texts the rows of the history do not hold themselves (issue #177, D): the label of each item (the idnumber of
     * its question bank entry), the title of each question and the name of each scale - three queries for the table.
     *
     * @param array $rows
     * @return array [question id => label, question id => title, scale id => name]
     */
    private static function texts_of_the_rows(array $rows): array {
        global $DB;
        $questionids = [];
        $scaleids = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!empty($row['id']) && (!isset($row['questionname']) || !isset($row['questiontitle']))) {
                $questionids[(int) $row['id']] = true;
            }
            if (!isset($row['questionscale_name']) && !empty($row['questionscale'])) {
                $scaleids[(int) $row['questionscale']] = true;
            }
        }
        $labels = [];
        $titles = [];
        foreach (array_chunk(array_keys($questionids), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $labels += $DB->get_records_sql_menu(
                "SELECT qv.questionid, qbe.idnumber
                   FROM {question_versions} qv
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  WHERE qv.questionid $insql",
                $params
            );
            $titles += self::question_titles($chunk);
        }
        $scalenames = [];
        if ($scaleids) {
            $scalenames = $DB->get_records_list('local_catquiz_catscales', 'id', array_keys($scaleids), '', 'id, name');
            $scalenames = array_map(fn($scale) => (string) $scale->name, $scalenames);
        }
        return [$labels, $titles, $scalenames];
    }

    /**
     * The titles of the given questions, in one query; a question that is gone is left out (its row keeps the label).
     *
     * @param int[] $questionids
     * @return array Question id => name.
     */
    private static function question_titles(array $questionids): array {
        global $DB;
        $titles = [];
        foreach (array_chunk(array_values(array_unique($questionids)), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $titles += $DB->get_records_select_menu('question', "id $insql", $params, '', 'id, name');
        }
        return $titles;
    }

    /**
     * Builds a map from question id to its occurrences in this attempt's question
     * usage, each carrying the real slot, question attempt id and title. Legacy
     * graphical-summary rows stored before the slot and question attempt id were
     * persisted are resolved through this map by question id and occurrence,
     * rather than by guessing the slot from the table row index. Loaded once per
     * table, so no N+1 usage/DB lookups are issued per row.
     *
     * @return array<int, array<int, array{slot: int, questionattemptid: int, name: string, responsesummary: ?string}>>
     */
    private function build_slot_map_from_quba(): array {
        global $DB;
        $map = [];
        try {
            $attemptid = $this->get_progress()->get_attemptid();
            $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], 'uniqueid', IGNORE_MISSING);
            if (!$attempt) {
                return $map;
            }
            return self::slot_map_of_usage((int) $attempt->uniqueid);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The slots of a question usage by question, with the question attempt and the name of the question version.
     *
     * Critical-friend re-audit of 23.11.2026: one query. Loading the usage loaded the definition of every question
     * (625 answers, cold caches: some 3.700 queries for the result page).
     *
     * @param int $usageid
     * Issue #177 (D): with the response summary the usage keeps for each question attempt - the text the history
     * shows, read from the usage instead of a copy in the progress.
     *
     * @return array<int, array<int, array{slot: int, questionattemptid: int, name: string, responsesummary: ?string}>>
     */
    public static function slot_map_of_usage(int $usageid): array {
        global $DB;
        $map = [];
        $records = $DB->get_recordset_sql(
            'SELECT qa.id, qa.slot, qa.questionid, q.name, qa.responsesummary
               FROM {question_attempts} qa
               JOIN {question} q ON q.id = qa.questionid
              WHERE qa.questionusageid = :usage
           ORDER BY qa.slot',
            ['usage' => $usageid]
        );
        foreach ($records as $record) {
            $map[(int) $record->questionid][] = [
                'slot' => (int) $record->slot,
                'questionattemptid' => (int) $record->id,
                'name' => (string) $record->name,
                'responsesummary' => $record->responsesummary === null ? null : (string) $record->responsesummary,
            ];
        }
        $records->close();
        return $map;
    }
}
