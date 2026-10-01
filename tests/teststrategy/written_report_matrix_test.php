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

namespace local_catquiz\teststrategy;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Which scales each strategy reports in writing (issue #117).
 *
 * The decision runs through several layers: the reporting setting of the quiz, the strategy's
 * toreport/primary/hidden/excluded, the counts of the attempt, the validator and the one written
 * predicate feedback_helper::is_displayable(). This drives the same chain the feedback generator
 * runs, for each of the six strategies, on one set of scales:
 *
 *  100 root; 101 and 102 valid; 103 reporting switched off; 104 below the minimum N;
 *  105 standard error above the maximum; 106 not measured in this attempt.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_helper::class)]
final class written_report_matrix_test extends advanced_testcase {
    /** @var array Ability per scale; distinct, so lowest and highest are unambiguous. */
    private const ABILITY = [100 => 0.2, 101 => -1.0, 102 => 1.5, 103 => -2.0, 104 => -3.0, 105 => 3.0, 106 => 2.5];

    /** @var array Standard error per scale. */
    private const SE = [100 => 0.2, 101 => 0.3, 102 => 0.3, 103 => 0.3, 104 => 0.3, 105 => 0.9, 106 => 0.3];

    /** @var array Productive answered items per scale in this attempt. */
    private const N = [101 => 3, 102 => 3, 103 => 3, 104 => 1, 105 => 3, 106 => 0];

    /**
     * @var array Items shown but not answered. Counted as played, 104 and 106 would reach the
     *      minimum N of 2; counted as answered (the authoritative N), they do not.
     */
    private const SHOWNONLY = [104 => 1, 106 => 2];

    /**
     * Runs the chain of the feedback generator for one strategy and returns the scales reported in writing.
     *
     * @param int $strategyid
     * @return int[]
     */
    private function written_scales(int $strategyid): array {
        $this->setAdminUser();

        // The progress of an attempt that answered these items, each scale its own questions; the
        // root holds all of them.
        $progress = progress::load(97001, 'mod_adaptivequiz', 9, (object) []);
        $byscale = [];
        $responses = [];
        $played = [];
        $qid = 5000;
        foreach (self::N as $scaleid => $n) {
            $shown = $n + (self::SHOWNONLY[$scaleid] ?? 0);
            for ($i = 0; $i < $shown; $i++) {
                $q = (object) ['id' => ++$qid, 'catscaleid' => $scaleid, 'is_pilot' => false, 'fisherinformation' => []];
                $byscale[$scaleid][$q->id] = $q;
                $byscale[100][$q->id] = $q;
                $played[$q->id] = $q;
                if ($i < $n) {
                    $responses[$q->id] = ['questionid' => $q->id, 'fraction' => 1.0];
                }
            }
        }
        $state = ['responses' => $responses, 'playedquestions' => $played, 'playedquestionsbyscale' => $byscale];
        foreach ($state as $name => $value) {
            $property = new \ReflectionProperty($progress, $name);
            $property->setAccessible(true);
            $property->setValue($progress, $value);
        }
        $progress->save();

        $personabilities = [];
        foreach (self::ABILITY as $scaleid => $value) {
            $personabilities[$scaleid] = ['value' => $value];
        }
        $quizsettings = new \stdClass();
        foreach (array_keys(self::ABILITY) as $scaleid) {
            $quizsettings->{'catquiz_scalereportcheckbox_' . $scaleid} = $scaleid === 103 ? 0 : 1;
        }

        $settings = new feedbacksettings($strategyid);
        $settings->nminscale = 2;
        $settings->nmintest = 1;
        $settings->semax = 0.5;
        $settings->fraction = 0.6;
        $feedbackdata = [
            'attemptid' => 97001,
            'contextid' => 9,
            'catscaleid' => 100,
            'se' => self::SE,
            'progress' => $progress,
        ];

        // The order of feedbackgenerator::select_scales_for_report(): reporting setting, then strategy.
        $personabilities = $settings->filter_excluded_scales($personabilities, $quizsettings);
        $personabilities = \local_catquiz\teststrategy\info::get_teststrategy($strategyid)
            ->select_scales_for_report($settings, $personabilities, $feedbackdata, 100);

        $result = feedback_helper::build_attempt_result($personabilities, $feedbackdata);
        $written = array_values(array_filter(
            array_keys(self::ABILITY),
            fn($scaleid) => feedback_helper::is_displayable($result, $scaleid)
        ));
        sort($written);

        return $written;
    }

    /**
     * The strategies whose written report the issue fixes.
     *
     * @return array
     */
    public static function strategies(): array {
        return [
            'fastest: root only' => [LOCAL_CATQUIZ_STRATEGY_FASTEST, [100]],
            'relevant subscales: every valid one' => [LOCAL_CATQUIZ_STRATEGY_RELSUBS, [100, 101, 102]],
            'all subscales: every valid one' => [LOCAL_CATQUIZ_STRATEGY_ALLSUBS, [100, 101, 102]],
            'lowest skill gap: the selected one' => [LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, [101]],
            'greatest strength: the selected one' => [LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB, [102]],
        ];
    }

    /**
     * Each strategy reports exactly the scales the issue's matrix names.
     *
     * Two valid subscales at once (relevant, all); a scale with reporting off, one below the minimum
     * N, one above the maximum standard error and one not measured never appear; a non-primary scale
     * with toreport does; lowest and highest stay with their one selected scale.
     *
     * @dataProvider strategies
     * @param int $strategyid
     * @param int[] $expected
     */
    public function test_each_strategy_reports_what_it_should(int $strategyid, array $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, $this->written_scales($strategyid));
    }

    /**
     * Classical CAT: what is settled.
     *
     * It applies neither the minimum N nor the maximum standard error; whether it should is open.
     * Settled is: valid scales are reported, a scale with reporting off and a scale not measured in
     * this attempt are not.
     */
    public function test_classical_cat_reports_valid_scales_only_if_measured_and_enabled(): void {
        $this->resetAfterTest();

        $written = $this->written_scales(LOCAL_CATQUIZ_STRATEGY_CLASSIC);

        foreach ([100, 101, 102] as $scaleid) {
            $this->assertContains($scaleid, $written);
        }
        $this->assertNotContains(103, $written, 'Reporting switched off, yet reported.');
        $this->assertNotContains(106, $written, 'Not measured in this attempt, yet reported.');
    }
}
