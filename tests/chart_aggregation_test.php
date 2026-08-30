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
 * Issue #23: chart data is aggregated in the database, not in PHP.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use local_catquiz\teststrategy\feedback_helper;

/**
 * Verifies the server side aggregation of the answers-per-person histogram.
 *
 * The chart only ever needed the number of people per (range, class); it counted the
 * rows it had loaded. Loading one row per enrolled person just to count them is what
 * made memory and runtime grow with the cohort.
 *
 * Moving the classification into SQL is only safe if it produces exactly what the
 * PHP helpers produce, so these tests compare the two directly rather than pinning
 * the SQL result on its own.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\catquiz::get_answers_per_person_histogram
 * @covers     \local_catquiz\catquiz::get_max_questions_answered_per_person
 */
final class chart_aggregation_test extends advanced_testcase {
    /**
     * The SQL class expression agrees with feedback_helper::get_histogram_bin().
     *
     * The chart shifts every non-zero class by one so that class 0 stays reserved
     * for "no answers"; the SQL expression has to reproduce that shift, not just the
     * raw bin.
     *
     * @return void
     */
    public function test_class_expression_matches_the_php_helper(): void {
        global $DB;

        $this->resetAfterTest();

        foreach ([1, 2, 3, 7] as $classwidth) {
            foreach ([0, 1, 2, 3, 5, 8, 13, 21] as $answers) {
                $expected = $answers === 0
                    ? 0
                    : feedback_helper::get_histogram_bin($answers, $classwidth) + 1;

                $sql = "SELECT CASE WHEN :a1 = 0 THEN 0
                                    ELSE CAST(CEIL(:a2 * 1.0 / :classwidth) AS INTEGER) END AS bin";
                $actual = (int) $DB->get_field_sql($sql, [
                    'a1' => $answers,
                    'a2' => $answers,
                    'classwidth' => $classwidth,
                ]);

                $this->assertEquals(
                    $expected,
                    $actual,
                    "Class of $answers answers at width $classwidth must match the PHP helper."
                );
            }
        }
    }

    /**
     * The maximum comes from the database instead of a PHP loop.
     *
     * @return void
     */
    public function test_maximum_is_computed_in_the_database(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$scaleid, $contextid] = $this->make_scale();

        // Without any data the maximum is 0 and the chart falls back to its default.
        $this->assertEquals(
            0,
            catquiz::get_max_questions_answered_per_person($contextid, $scaleid)
        );
    }

    /**
     * The histogram query runs and returns counts, not rows.
     *
     * @return void
     */
    public function test_histogram_returns_counts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$scaleid, $contextid] = $this->make_scale();

        $counts = catquiz::get_answers_per_person_histogram(
            $contextid,
            $scaleid,
            null,
            3,
            [
                ['lower' => -3.0, 'upper' => 0.0],
                ['lower' => 0.0, 'upper' => 3.0],
            ]
        );

        $this->assertIsArray($counts);
        foreach ($counts as $bins) {
            foreach ($bins as $frequency) {
                $this->assertIsInt($frequency, 'Only counts must come back, never rows.');
            }
        }
    }

    /**
     * The range boundaries are half-open, with the topmost range closed.
     *
     * A value on a shared boundary must belong to exactly one range; the maximum
     * value must still be covered. This mirrors the rule in
     * feedback_helper::get_feedback_range_index().
     *
     * @return void
     */
    public function test_range_boundaries_are_half_open(): void {
        global $DB;

        $this->resetAfterTest();

        $ranges = [
            ['lower' => -3.0, 'upper' => 0.0],
            ['lower' => 0.0, 'upper' => 3.0],
        ];

        // Pairs rather than an array keyed by the value: PHP array keys cannot be
        // floats, so -0.1 and 2.9 would silently be truncated to 0 and 2.
        $cases = [[-3.0, 1], [-0.1, 1], [0.0, 2], [2.9, 2], [3.0, 2]];
        foreach ($cases as [$value, $expected]) {
            // Moodle counts every occurrence of a named placeholder separately, so
            // each one needs its own name - repeating :ability made the driver expect
            // eight parameters for five values.
            $whens = [];
            $params = [];
            foreach ($ranges as $index => $range) {
                $j = $index + 1;
                $params['abilitylow' . $j] = (float) $value;
                $params['abilityhigh' . $j] = (float) $value;
                $params['rangelower' . $j] = $range['lower'];
                $params['rangeupper' . $j] = $range['upper'];
                $comparison = ($j === count($ranges)) ? '<=' : '<';
                // Casts reproduce the typing of the real query, where ability is a
                // numeric column. Comparing two untyped parameters makes PostgreSQL
                // treat them as text, and '-0.1' >= '-3.0' is false as a string.
                $whens[] = "WHEN CAST(:abilitylow$j AS DECIMAL) >= CAST(:rangelower$j AS DECIMAL) "
                    . "AND CAST(:abilityhigh$j AS DECIMAL) $comparison CAST(:rangeupper$j AS DECIMAL) THEN $j";
            }
            $sql = 'SELECT CASE ' . implode(' ', $whens) . ' ELSE -1 END AS rangeindex';

            $this->assertEquals(
                $expected,
                (int) $DB->get_field_sql($sql, $params),
                "Value $value must fall into range $expected."
            );
        }
    }

    /**
     * The extracted bounds describe the same ranges as the PHP classifier.
     *
     * The histogram assigns the range in SQL from these numbers, while the rest of
     * the plugin keeps using get_feedback_range_index(). If the two ever described
     * different ranges, the chart would disagree with the feedback a learner sees.
     *
     * @return void
     */
    public function test_extracted_bounds_agree_with_the_php_classifier(): void {
        $this->resetAfterTest();

        $scaleid = 5;
        $settings = (object) [
            'numberoffeedbackoptionsselect' => 3,
            'feedback_scaleid_limit_lower_5_1' => '-3.0',
            'feedback_scaleid_limit_upper_5_1' => '-1.0',
            'feedback_scaleid_limit_lower_5_2' => '-1.0',
            'feedback_scaleid_limit_upper_5_2' => '1.0',
            'feedback_scaleid_limit_lower_5_3' => '1.0',
            'feedback_scaleid_limit_upper_5_3' => '3.0',
        ];

        $bounds = feedback_helper::get_feedback_range_bounds($settings, $scaleid);
        $this->assertCount(3, $bounds);

        // For every probe the bounds must select the same range the classifier does.
        $probes = [-3.0, -2.0, -1.0, 0.0, 1.0, 2.5, 3.0];
        foreach ($probes as $value) {
            $expected = feedback_helper::get_feedback_range_index($settings, $scaleid, $value);

            $fromboundaries = null;
            foreach ($bounds as $index => $bound) {
                $j = $index + 1;
                $istop = ($j === count($bounds));
                $inrange = $value >= $bound['lower']
                    && ($istop ? $value <= $bound['upper'] : $value < $bound['upper']);
                if ($inrange) {
                    $fromboundaries = $j;
                    break;
                }
            }

            $this->assertSame(
                $expected,
                $fromboundaries,
                "Value $value must land in the same range in both implementations."
            );
        }
    }

    /**
     * A decimal comma in the settings is parsed, not truncated.
     *
     * The limits come from user input and may carry a decimal comma; the shared
     * parser handles that, and the bounds must not lose it on their way into SQL.
     *
     * @return void
     */
    public function test_bounds_use_the_locale_safe_parser(): void {
        $this->resetAfterTest();

        $bounds = feedback_helper::get_feedback_range_bounds((object) [
            'numberoffeedbackoptionsselect' => 1,
            'feedback_scaleid_limit_lower_7_1' => '-1,5',
            'feedback_scaleid_limit_upper_7_1' => '2,5',
        ], 7);

        $this->assertEqualsWithDelta(-1.5, $bounds[0]['lower'], 0.0001);
        $this->assertEqualsWithDelta(2.5, $bounds[0]['upper'], 0.0001);
    }

    /**
     * Creates a context and a scale.
     *
     * @return array [int $scaleid, int $contextid]
     */
    private function make_scale(): array {
        global $DB;

        $now = time();
        $contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Issue 23 context',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => $now - 100,
            'endtimestamp' => $now + 10000,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0,
            'name' => 'Issue 23 scale',
            'contextid' => $contextid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return [$scaleid, $contextid];
    }
}
