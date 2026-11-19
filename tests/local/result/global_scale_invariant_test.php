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

namespace local_catquiz\local\result;

use local_catquiz\local\attempt\attempt_finalizer;
use local_catquiz\teststrategy\progress;

/**
 * Issue #174: the global scale is part of the result of every attempt that estimated it.
 *
 * The feedback projection (personabilities_abilities) holds the scales the feedback shows. A strategy that reports
 * one subscale leaves the global scale out of it, and an attempt whose scales all failed a check may show none. The
 * result was built from that projection: the global scale got no result row, and an attempt with estimates but an
 * empty projection got no result at all. The result is now built from the measurement state of the attempt.
 *
 * Hierarchy of the fixtures: global scale 10 - subscale 11 - sub-subscale 12, and subscale 13 under the global
 * scale. Every productive answer counts for its scale and every ancestor, once.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\local\result\attempt_result_validator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_result_validator::class)]
final class global_scale_invariant_test extends \advanced_testcase {
    /** @var int The global scale. */
    private const ROOT = 10;

    /** @var array Parent of each scale. */
    private const PARENT = [10 => 0, 11 => 10, 12 => 11, 13 => 10];

    /** @var int Next question id of the fixtures. */
    private static int $question = 970000;

    /**
     * Files a finished attempt: activity attempt, CAT attempt with its stored JSON, and the progress.
     *
     * @param array $answers scaleid => list of fractions of the productive answers to items of that scale.
     * @param array $abilities scaleid => the estimate of the attempt.
     * @param array $json The stored JSON (projection, se, primaryscale).
     * @param array $settings min (test), minpersubscale, semax.
     * @param int $pilots Pilot answers, on items of the first scale.
     * @return array [adaptive attempt id, CAT attempt id]
     */
    private function attempt(array $answers, array $abilities, array $json, array $settings = [], int $pilots = 0): array {
        global $DB;
        $now = time();
        $count = array_sum(array_map('count', $answers)) + $pilots;
        $hostid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => 2, 'uniqueid' => 0, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => $count, 'difficultysum' => 0,
            'standarderror' => 0.3, 'measure' => 0, 'timefinished' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $catid = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => 2, 'scaleid' => self::ROOT, 'contextid' => 9, 'attemptid' => $hostid,
            'component' => 'mod_adaptivequiz', 'status' => 0, 'number_of_testitems_used' => $count,
            'endtime' => null, 'json' => json_encode($json), 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setAdminUser();
        $progress = progress::load($hostid, 'mod_adaptivequiz', 9, (object) [
            'catquiz_catscales' => self::ROOT,
            'maxquestionsgroup' => ['catquiz_minquestions' => $settings['min'] ?? 0, 'catquiz_maxquestions' => 25],
            'maxquestionsscalegroup' => ['catquiz_minquestionspersubscale' => $settings['minpersubscale'] ?? 0],
            'catquiz_standarderrorgroup' => ['catquiz_standarderror_min' => 0.0,
                'catquiz_standarderror_max' => $settings['semax'] ?? 1.0],
        ]);
        $played = [];
        $byscale = [];
        $responses = [];
        $file = function (int $scaleid, float $fraction, bool $pilot) use (&$played, &$byscale, &$responses): void {
            $q = (object) ['id' => ++self::$question, 'catscaleid' => $scaleid, 'is_pilot' => $pilot,
                'fisherinformation' => []];
            $played[$q->id] = $q;
            // The item counts for its scale and every ancestor.
            for ($s = $scaleid; $s !== 0; $s = self::PARENT[$s]) {
                $byscale[$s][$q->id] = $q;
            }
            $responses[$q->id] = ['questionid' => $q->id, 'fraction' => $fraction];
        };
        foreach ($answers as $scaleid => $fractions) {
            foreach ($fractions as $fraction) {
                $file((int) $scaleid, (float) $fraction, false);
            }
        }
        for ($i = 0; $i < $pilots; $i++) {
            $file((int) array_key_first($answers), 1.0, true);
        }
        $state = ['responses' => $responses, 'playedquestions' => $played, 'playedquestionsbyscale' => $byscale,
            'lastquestion' => end($played) ?: null];
        foreach ($state as $name => $value) {
            $property = new \ReflectionProperty($progress, $name);
            $property->setAccessible(true);
            $property->setValue($progress, $value);
        }
        foreach ($abilities as $scaleid => $ability) {
            $progress->set_ability((float) $ability, (int) $scaleid);
        }
        $progress->save();
        return [$hostid, $catid];
    }

    /**
     * Mixed answers: n items, the first one right, the rest alternating.
     *
     * @param int $n
     * @return array
     */
    private static function mixed(int $n): array {
        return array_map(fn($i) => $i % 2 === 0 ? 1.0 : 0.0, range(0, $n - 1));
    }

    /**
     * The abilities and standard errors of the four scales.
     *
     * @return array [abilities, se]
     */
    private static function estimates(): array {
        return [
            [10 => 0.37, 11 => 0.21, 12 => -0.44, 13 => 0.93],
            [10 => 0.31, 11 => 0.42, 12 => 0.55, 13 => 0.58],
        ];
    }

    /**
     * The feedback projections of the production cases.
     *
     * @return array
     */
    public static function projections(): array {
        return [
            // LOWESTSUB on LTI: one subscale reported, the global scale not in the projection (13 answers).
            'lowest skill gap, 13 questions' => [
                [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]], 12, [12 => 5, 13 => 8],
            ],
            // RELSUBS on LTI: the relevant subscales, no global scale (17 answers).
            'relevant subscales, 17 questions' => [
                [11 => ['value' => 0.21, 'toreport' => true, 'primary' => true], 13 => ['value' => 0.93, 'toreport' => true]],
                11, [11 => 9, 13 => 8],
            ],
            // No stored result although the attempt holds estimates: an empty projection ...
            'empty projection' => [[], null, [12 => 5, 13 => 8]],
            // ... a projection of hidden scales only ...
            'only hidden scales shown' => [
                [13 => ['value' => 0.93, 'toreport' => false, 'hidden' => true]], null, [12 => 5, 13 => 8],
            ],
            // ... a projection whose primary scale failed a check ...
            'primary scale excluded' => [
                [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true, 'excluded' => true,
                    'error' => ['se' => ['semaxdefined' => 0.5, 'securrent' => 0.55]]]],
                12, [12 => 5, 13 => 8],
            ],
        ];
    }

    /**
     * The global scale gets its own result row, with its own estimate, N and SE, whatever the projection holds.
     *
     * @dataProvider projections
     * @param array $projection
     * @param int|null $primary
     * @param array $answers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('projections')]
    public function test_the_global_scale_is_part_of_every_result(array $projection, ?int $primary, array $answers): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$abilities, $se] = self::estimates();
        $answers = array_map(fn($n) => self::mixed($n), $answers);
        $json = ['personabilities_abilities' => $projection, 'customscalefeedback_abilities' => [], 'se' => $se];
        if ($primary !== null) {
            $json['primaryscale'] = ['id' => $primary];
        }
        [$hostid, $catid] = $this->attempt($answers, $abilities, $json);
        $total = array_sum(array_map('count', $answers));

        $result = attempt_result_validator::validate($hostid);
        $root = $result->get_scale_result(self::ROOT);
        $this->assertNotNull($root, 'The global scale is part of the result.');
        $this->assertSame(0.37, $root->score, 'Its own estimate - not the value of the primary scale.');
        $this->assertSame($total, $root->n, 'Every productive answer counts for the global scale, once.');
        $this->assertSame(0.31, $root->standarderror);
        $this->assertTrue($root->measuredincurrentattempt);
        $this->assertTrue($root->statisticallyvalid, 'Mixed answers, SE below the maximum.');
        $this->assertSame($primary === self::ROOT, $root->primary);

        $this->assertTrue(attempt_finalizer::finalize($hostid, time(), 'reason'));
        $rows = $DB->get_records('local_catquiz_personparams', ['attemptid' => $catid], 'catscaleid');
        $byscale = [];
        foreach ($rows as $row) {
            $byscale[(int) $row->catscaleid] = $row;
        }
        // Every scale with a productive answer - on itself or a subscale - has its row; one without has none.
        $measured = [];
        foreach (array_keys($answers) as $scaleid) {
            for ($s = (int) $scaleid; $s !== 0; $s = self::PARENT[$s]) {
                $measured[$s] = true;
            }
        }
        foreach ([10, 11, 12, 13] as $scaleid) {
            if (isset($measured[$scaleid])) {
                $this->assertArrayHasKey($scaleid, $byscale, "A result row for scale $scaleid.");
                $this->assertSame('current', $byscale[$scaleid]->resultsource);
            } else {
                $this->assertArrayNotHasKey($scaleid, $byscale, "No row for scale $scaleid: not measured.");
            }
        }
        $this->assertEqualsWithDelta(0.37, (float) $byscale[self::ROOT]->ability, 1e-9);
        $this->assertSame($total, (int) $byscale[self::ROOT]->n);
        $this->assertEqualsWithDelta(0.31, (float) $byscale[self::ROOT]->standarderror, 1e-9);
        $this->assertFalse(
            $DB->record_exists('local_catquiz_personparams', ['attemptid' => $catid, 'resultsource' => 'prior']),
            'Nothing carried over for a scale the attempt measured.'
        );
        // A valid measurement of the global scale is its admissible value (#164), reported or not.
        $this->assertSame(
            0.37,
            round((float) personparam_repository::get_latest_valid(2, 9, self::ROOT)->ability, 2)
        );
    }

    /**
     * The checks still apply to the global scale: invalid stays invalid - but stays.
     */
    public function test_an_invalid_global_scale_keeps_its_row_and_its_reasons(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$abilities, $se] = self::estimates();
        $se[self::ROOT] = 1.6;
        $json = ['personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 12]];
        [$hostid, $catid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, $json, ['semax' => 1.0]);

        $root = attempt_result_validator::validate($hostid)->get_scale_result(self::ROOT);
        $this->assertNotNull($root);
        $this->assertFalse($root->statisticallyvalid);
        $this->assertContains(scale_result::REASON_SE_MAX, $root->rejectionreasons);

        attempt_finalizer::finalize($hostid, time(), 'reason');
        $row = $DB->get_record('local_catquiz_personparams', ['attemptid' => $catid, 'catscaleid' => self::ROOT]);
        $this->assertNotFalse($row, 'The invalid global scale keeps its result row.');
        $this->assertSame(0, (int) $row->isvalid);
        $this->assertStringContainsString(scale_result::REASON_SE_MAX, (string) $row->validationstatus);
        $this->assertNull(personparam_repository::get_latest_valid(2, 9, self::ROOT), 'And it is no admissible value.');
    }

    /**
     * The edge cases of the measurement state.
     *
     * @return array
     */
    public static function edges(): array {
        return [
            // An ability of exactly 0.0 is an estimate, not a missing value.
            'ability 0.0' => [[10 => 0.0], [12 => self::mixed(5), 13 => self::mixed(8)], [], 0, null, true, 13],
            // Pilot answers do not count for N.
            'pilots' => [[], [12 => self::mixed(5), 13 => self::mixed(8)], [], 3, null, true, 13],
            // All wrong on the global scale: the fraction rule.
            'fraction 0' => [[], [12 => [0.0, 0.0, 0.0], 13 => [0.0, 0.0, 0.0]], [], 0,
                scale_result::REASON_FRACTION_ALL_INCORRECT, false, 6],
            // All right on the global scale.
            'fraction 1' => [[], [12 => [1.0, 1.0, 1.0], 13 => [1.0, 1.0, 1.0]], [], 0,
                scale_result::REASON_FRACTION_ALL_CORRECT, false, 6],
            // Below the minimum of the test (an early, technical stop).
            'below the minimum' => [[], [12 => self::mixed(2), 13 => self::mixed(2)], ['min' => 12], 0,
                scale_result::REASON_MIN_QUESTIONS, false, 4],
            // The minimum per subscale does not apply to the global scale.
            'minimum per subscale' => [[], [12 => self::mixed(2), 13 => self::mixed(2)], ['minpersubscale' => 3], 0,
                null, true, 4],
        ];
    }

    /**
     * The global scale through the edge cases.
     *
     * @dataProvider edges
     * @param array $override Abilities to override.
     * @param array $answers
     * @param array $settings
     * @param int $pilots
     * @param string|null $reason The expected reason, null for none of the statistical ones.
     * @param bool $valid Statistically valid.
     * @param int $n The expected N of the global scale.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('edges')]
    public function test_the_global_scale_at_the_edges(
        array $override,
        array $answers,
        array $settings,
        int $pilots,
        ?string $reason,
        bool $valid,
        int $n
    ): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        $abilities = $override + $abilities;
        $json = ['personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 12]];
        [$hostid] = $this->attempt($answers, $abilities, $json, $settings, $pilots);

        $root = attempt_result_validator::validate($hostid)->get_scale_result(self::ROOT);
        $this->assertNotNull($root);
        $this->assertSame((float) $abilities[self::ROOT], $root->score);
        $this->assertSame($n, $root->n);
        $this->assertSame($valid, $root->statisticallyvalid);
        if ($reason !== null) {
            $this->assertContains($reason, $root->rejectionreasons);
        }
        $this->assertNotContains(scale_result::REASON_N_MIN, $root->rejectionreasons);
    }

    /**
     * A projection that holds the global scale keeps the decisions of the strategy - nothing is added twice.
     */
    public function test_a_projection_with_the_global_scale_is_kept(): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        $projection = [10 => ['value' => 0.37, 'toreport' => true, 'primary' => true],
            12 => ['value' => -0.44, 'toreport' => true, 'hidden' => true]];
        [$hostid] = $this->attempt(
            [12 => self::mixed(5), 13 => self::mixed(8)],
            $abilities,
            ['personabilities_abilities' => $projection, 'se' => $se, 'primaryscale' => ['id' => 10]]
        );

        $result = attempt_result_validator::validate($hostid);
        $this->assertTrue($result->get_scale_result(10)->primary);
        $this->assertTrue($result->get_scale_result(10)->valid);
        $this->assertTrue($result->get_scale_result(10)->reportable);
        $this->assertContains(scale_result::REASON_HIDDEN, $result->get_scale_result(12)->rejectionreasons);
        // The scales the projection left out are there, not reported.
        $this->assertFalse($result->get_scale_result(11)->reportable);
        $this->assertFalse($result->get_scale_result(13)->reportable);
        $this->assertCount(4, $result->get_scale_results());
    }

    /**
     * The fallback of the stored projections: an empty personabilities_abilities falls back to the projection of the
     * custom scale feedback, and a legacy snapshot with scalar entries is no projection - the measurement state fills
     * in, nothing is lost.
     */
    public function test_the_fallback_of_the_projections(): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        [$hostid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, [
            'personabilities_abilities' => [],
            'customscalefeedback_abilities' => [13 => ['value' => 0.93, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 13],
        ]);
        $result = attempt_result_validator::validate($hostid);
        $this->assertTrue($result->get_scale_result(13)->primary, 'The custom scale feedback decides the primary scale.');
        $this->assertTrue($result->get_scale_result(13)->reportable);
        $this->assertNotNull($result->get_scale_result(self::ROOT));

        [$hostid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, [
            'personabilities_abilities' => [10 => 0.37, 12 => -0.44],
            'se' => $se,
        ]);
        $result = attempt_result_validator::validate($hostid);
        $this->assertCount(4, $result->get_scale_results(), 'Scalar legacy entries: every estimated scale.');
        $this->assertSame(0.37, $result->get_scale_result(self::ROOT)->score);
    }

    /**
     * The global scale answered but without an estimate - missing, or not finite: a clear diagnosis, no value made up
     * (issue #174, re-audit 18.11.2026). Its row holds the reason and no ability, and is nobody's admissible value.
     *
     * @return array
     */
    public static function missing_estimates(): array {
        return [
            'not in the estimates' => [null],
            'not finite' => [INF],
            'not a number' => [NAN],
        ];
    }

    /**
     * The diagnosis of a global scale without an estimate.
     *
     * @dataProvider missing_estimates
     * @param float|null $estimate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_estimates')]
    public function test_a_global_scale_without_an_estimate_is_diagnosed(?float $estimate): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$abilities, $se] = self::estimates();
        unset($abilities[self::ROOT]);
        if ($estimate !== null) {
            $abilities[self::ROOT] = $estimate;
        }
        $json = ['personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 12]];
        [$hostid, $catid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, $json);
        // A non-finite estimate is not written, and does not break the progress (its JSON could not be encoded).
        $stored = json_decode((string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catid]), true);
        $this->assertIsArray($stored, 'The progress is kept.');
        $this->assertArrayNotHasKey(self::ROOT, $stored['abilities']);
        $this->assertSame(-0.44, $stored['abilities'][12]);

        $root = attempt_result_validator::validate($hostid)->get_scale_result(self::ROOT);
        $this->assertNotNull($root, 'The global scale is in the result, with its diagnosis.');
        $this->assertNull($root->score, 'No value is made up.');
        $this->assertSame(13, $root->n);
        $this->assertFalse($root->statisticallyvalid);
        $this->assertFalse($root->valid);
        $this->assertContains(scale_result::REASON_GLOBAL_NOT_ESTIMATED, $root->rejectionreasons);

        attempt_finalizer::finalize($hostid, time(), 'reason');
        $row = $DB->get_record('local_catquiz_personparams', ['attemptid' => $catid, 'catscaleid' => self::ROOT], '*', MUST_EXIST);
        $this->assertNull($row->ability);
        $this->assertSame(0, (int) $row->isvalid);
        $this->assertStringContainsString(scale_result::REASON_GLOBAL_NOT_ESTIMATED, (string) $row->validationstatus);
        $this->assertNull(personparam_repository::get_latest_valid(2, 9, self::ROOT), 'No admissible value.');
        // The subscale reported keeps its result.
        $this->assertTrue($DB->record_exists('local_catquiz_personparams', ['attemptid' => $catid, 'catscaleid' => 12]));
    }

    /**
     * Without an answer there is nothing to diagnose: no row for the global scale (issue #174).
     */
    public function test_a_global_scale_without_answers_has_no_row(): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        [$hostid] = $this->attempt([], [], ['personabilities_abilities' => [], 'se' => $se]);
        $this->assertNull(attempt_result_validator::validate($hostid)->get_scale_result(self::ROOT));
    }

    /**
     * An incomplete map of standard errors: a scale without one has an infinite standard error - above any maximum -,
     * the others keep theirs (issue #174).
     */
    public function test_an_incomplete_map_of_standard_errors(): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        unset($se[13], $se[11]);
        $json = ['personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 12]];
        [$hostid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, $json);
        $result = attempt_result_validator::validate($hostid);
        $this->assertTrue($result->get_scale_result(self::ROOT)->statisticallyvalid, 'The global scale has its SE.');
        $this->assertSame(0.31, $result->get_scale_result(self::ROOT)->standarderror);
        foreach ([11, 13] as $scaleid) {
            $this->assertContains(scale_result::REASON_SE_MAX, $result->get_scale_result($scaleid)->rejectionreasons, "$scaleid");
            $this->assertNull($result->get_scale_result($scaleid)->standarderror, "$scaleid: no SE made up.");
        }
        // Without a map at all: every added scale above the maximum, nothing invented.
        [$hostid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, [
            'personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'primaryscale' => ['id' => 12],
        ]);
        $root = attempt_result_validator::validate($hostid)->get_scale_result(self::ROOT);
        $this->assertContains(scale_result::REASON_SE_MAX, $root->rejectionreasons);
        $this->assertSame(0.37, $root->score);
    }

    /**
     * N of the global scale counts every question once - also a question that is an item of two of its subscales
     * (issue #174: N_root without duplicates).
     */
    public function test_n_of_the_global_scale_counts_each_question_once(): void {
        $this->resetAfterTest();
        [$abilities, $se] = self::estimates();
        $json = ['personabilities_abilities' => [12 => ['value' => -0.44, 'toreport' => true, 'primary' => true]],
            'se' => $se, 'primaryscale' => ['id' => 12]];
        [$hostid, $catid] = $this->attempt([12 => self::mixed(5), 13 => self::mixed(8)], $abilities, $json);
        // The first question of scale 12 is an item of scale 13 as well.
        $progress = \local_catquiz\teststrategy\progress::load_for_reading($catid);
        $property = new \ReflectionProperty($progress, 'playedquestionsbyscale');
        $property->setAccessible(true);
        $byscale = $property->getValue($progress);
        $first = reset($byscale[12]);
        $byscale[13][$first->id] = $first;
        $byscale[self::ROOT][$first->id] = $first;
        $property->setValue($progress, $byscale);
        $this->assertSame(13, $progress->get_num_answered_productive_questions(self::ROOT));
        $this->assertSame(9, $progress->get_num_answered_productive_questions(13));
    }

    /**
     * Without a progress there is no measurement state: the projection alone, as before (retention).
     */
    public function test_without_progress_the_projection_stays_alone(): void {
        $this->resetAfterTest();
        $projection = [12 => ['value' => -0.44, 'toreport' => true]];
        $this->assertSame($projection, attempt_result_validator::complete_measurement_state($projection, null, [], 10));
    }
}
