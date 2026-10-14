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

use advanced_testcase;
use local_catquiz\local\result\scale_result;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/tests/fixtures/measured_progress.php');

/**
 * A finalised attempt judged again by today's rules, on request only (issue #141).
 *
 * The production case: 3 of at least 12 questions answered, finalised as valid before the minimum
 * was enforced.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_revalidator::class)]
final class attempt_revalidator_test extends advanced_testcase {
    /**
     * An attempt finalised as valid under the old rules.
     *
     * @param int $answers Answered productive items.
     * @param bool $withprogress Whether the answers are still stored.
     * @param bool $finalised
     * @return int adaptivequiz_attempt.id
     */
    private function attempt(int $answers, bool $withprogress = true, bool $finalised = true): int {
        global $DB;
        $this->setAdminUser();
        $now = time();
        $adaptiveattemptid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => 2, 'uniqueid' => 4344 + $answers, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => $answers, 'difficultysum' => 0,
            'standarderror' => 0.3, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
            'resultvalid' => 1, 'resultstatus' => 'valid',
        ]);
        $catattemptid = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => 2, 'attemptid' => $adaptiveattemptid, 'component' => 'mod_adaptivequiz', 'contextid' => 9,
            'status' => 0, 'endtime' => $finalised ? $now : null, 'timecreated' => $now, 'timemodified' => $now,
            'json' => json_encode([
                'personabilities_abilities' => [5 => ['value' => 3.9, 'toreport' => true]],
                'se' => [5 => 0.9],
                'primaryscale' => ['id' => 5],
            ]),
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => $catattemptid, 'userid' => 2, 'contextid' => 9, 'catscaleid' => 5, 'ability' => 3.9,
            'standarderror' => 0.9, 'n' => $answers, 'fraction' => 0.5, 'isprimary' => 1, 'isvalid' => 1,
            'resultsource' => 'current', 'validationstatus' => '', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        if ($withprogress) {
            local_catquiz_measured_progress(
                $adaptiveattemptid,
                [5 => $answers],
                9,
                ['maxquestionsgroup' => ['catquiz_minquestions' => 12, 'catquiz_maxquestions' => 25]]
            );
        }
        return $adaptiveattemptid;
    }

    /**
     * The stored verdict: of the activity and of the primary scale.
     *
     * @param int $adaptiveattemptid
     * @return array
     */
    private function stored(int $adaptiveattemptid): array {
        global $DB;
        $catattemptid = $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $adaptiveattemptid]);
        $row = $DB->get_record('local_catquiz_personparams', ['attemptid' => $catattemptid, 'catscaleid' => 5]);
        return [
            (int) $DB->get_field('adaptivequiz_attempt', 'resultvalid', ['id' => $adaptiveattemptid]),
            (int) $row->isvalid,
            (string) $row->validationstatus,
        ];
    }

    /**
     * Dry run reports, execute stores, a second run finds nothing to change.
     */
    public function test_production_case_is_judged_again(): void {
        $this->resetAfterTest();
        $short = $this->attempt(3);

        $dry = attempt_revalidator::revalidate($short, false);
        $this->assertTrue($dry->reconstructable);
        $this->assertTrue($dry->before);
        $this->assertFalse($dry->after);
        $this->assertTrue($dry->changed);
        $this->assertStringContainsString(scale_result::REASON_MIN_QUESTIONS, $dry->reasons);
        $this->assertSame([1, 1, ''], $this->stored($short), 'A dry run stores nothing.');

        attempt_revalidator::revalidate($short, true);
        [$activity, $scale, $status] = $this->stored($short);
        $this->assertSame(0, $activity);
        $this->assertSame(0, $scale);
        $this->assertStringContainsString(scale_result::REASON_MIN_QUESTIONS, $status);

        $again = attempt_revalidator::revalidate($short, true);
        $this->assertFalse($again->changed, 'Safe to repeat.');
    }

    /**
     * An attempt that meets today's rules keeps its verdict.
     */
    public function test_valid_attempt_stays_valid(): void {
        $this->resetAfterTest();
        $full = $this->attempt(12);

        $report = attempt_revalidator::revalidate($full, true);

        $this->assertTrue($report->after);
        $this->assertFalse($report->changed);
        $this->assertSame(1, $this->stored($full)[0]);
    }

    /**
     * A result written again without a recorded range takes today's range, marked as taken later;
     * a recorded range stays (issue #137).
     */
    public function test_revalidation_marks_a_range_taken_later(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->import_record('local_catquiz_catscales', (object) [
            'id' => 5, 'name' => 'Root', 'parentid' => 0, 'minscalevalue' => -4, 'maxscalevalue' => 4,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $older = $this->attempt(12);
        $catattemptid = $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $older]);

        attempt_revalidator::revalidate($older, true);

        $row = $DB->get_record('local_catquiz_personparams', ['attemptid' => $catattemptid, 'catscaleid' => 5]);
        $this->assertEqualsWithDelta(-4.0, (float) $row->rangelower, 1e-9);
        $this->assertEqualsWithDelta(4.0, (float) $row->rangeupper, 1e-9);
        $this->assertSame(1, (int) $row->rangebackfilled);

        // Recorded now, it stays as it is when the attempt is judged again after a change of the scale.
        $DB->set_field('local_catquiz_catscales', 'maxscalevalue', 2, ['id' => 5]);
        \cache::make('local_catquiz', 'catscales')->purge();
        attempt_revalidator::revalidate($older, true);
        $row = $DB->get_record('local_catquiz_personparams', ['attemptid' => $catattemptid, 'catscaleid' => 5]);
        $this->assertEqualsWithDelta(4.0, (float) $row->rangeupper, 1e-9);
        $this->assertSame(1, (int) $row->rangebackfilled);
    }

    /**
     * Without the answers or without finalisation nothing is judged and nothing is changed.
     */
    public function test_attempts_that_cannot_be_reconstructed_are_left_alone(): void {
        $this->resetAfterTest();

        $noprogress = $this->attempt(3, false);
        $report = attempt_revalidator::revalidate($noprogress, true);
        $this->assertFalse($report->reconstructable);
        $this->assertSame(attempt_revalidator::NO_PROGRESS, $report->reason);
        $this->assertSame([1, 1, ''], $this->stored($noprogress));

        $running = $this->attempt(4, true, false);
        $report = attempt_revalidator::revalidate($running, true);
        $this->assertSame(attempt_revalidator::NOT_FINALISED, $report->reason);
        $this->assertSame([1, 1, ''], $this->stored($running));
    }
}
