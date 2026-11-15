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

use advanced_testcase;
use local_catquiz\teststrategy\context\loader\personability_loader;
use local_catquiz\teststrategy\progress;

/**
 * The carry-over values of all scales come from one query, with the rule of get_latest_valid() (issue #165).
 *
 * personability_loader asked personparam_repository::get_latest_valid() once per scale: 80 scales, 80 queries.
 * get_latest_valid_by_scale() returns the same row per scale - the highest id among the valid rows of user,
 * context and scale - in one query. Which rows count as valid is unchanged (issue #164 decides that separately).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\result\personparam_repository
 * @covers \local_catquiz\teststrategy\context\loader\personability_loader
 */
final class personparam_latest_batch_test extends advanced_testcase {
    /** @var int User of the attempt. */
    private const USERID = 41;

    /** @var int CAT context. */
    private const CONTEXTID = 1650;

    /**
     * Person parameters for 80 scales: several valid and invalid rows per scale, rows of another user and another
     * context, scales without a valid row.
     *
     * @return int[] Scale ids.
     */
    private function rows(): array {
        global $DB;
        mt_srand(165);
        $scales = range(3001, 3080);
        foreach ($scales as $scaleid) {
            $versions = mt_rand(0, 5);
            for ($v = 0; $v < $versions; $v++) {
                foreach (
                    [[self::USERID, self::CONTEXTID], [self::USERID + 1, self::CONTEXTID],
                        [self::USERID, self::CONTEXTID + 1]] as [$userid, $contextid]
                ) {
                    $DB->insert_record('local_catquiz_personparams', (object) [
                        'userid' => $userid, 'contextid' => $contextid, 'catscaleid' => $scaleid,
                        'attemptid' => mt_rand(1, 500), 'ability' => mt_rand(-300, 300) / 100,
                        'isvalid' => mt_rand(0, 2) ? 1 : 0, 'timecreated' => 0, 'timemodified' => 0,
                    ]);
                }
            }
        }
        return $scales;
    }

    /**
     * For 1, 10 and 80 scales: the row of get_latest_valid() for each scale, and one query (AK1, AK2, DoD1).
     */
    public function test_same_rows_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $scales = $this->rows();
        foreach ([1, 10, 80] as $n) {
            $subset = array_slice($scales, 0, $n);
            $reads = $DB->perf_get_reads();
            $batch = personparam_repository::get_latest_valid_by_scale(self::USERID, self::CONTEXTID, $subset);
            $this->assertSame(1, $DB->perf_get_reads() - $reads, "$n scales: one query");

            $withrow = 0;
            foreach ($subset as $scaleid) {
                $single = personparam_repository::get_latest_valid(self::USERID, self::CONTEXTID, $scaleid);
                $this->assertEquals($single, $batch[$scaleid] ?? null, "scale $scaleid");
                $withrow += $single ? 1 : 0;
            }
            $this->assertCount($withrow, $batch);
        }
        $this->assertSame([], personparam_repository::get_latest_valid_by_scale(self::USERID, self::CONTEXTID, []));
    }

    /**
     * The loader's start values for 80 scales: as with one query per scale before, and the queries no longer grow
     * with the number of scales (AK1, AK4, DoD2).
     */
    public function test_loader_start_values(): void {
        global $DB;
        $this->resetAfterTest();
        $scales = $this->rows();
        foreach ([1, 10, 80] as $n) {
            $root = $scales[0];
            $subscales = array_slice($scales, 1, $n - 1);
            $progress = $this->createMock(progress::class);
            $progress->method('get_selected_subscales')->willReturn($subscales);
            $context = [
                'catscaleid' => $root, 'includesubscales' => true, 'contextid' => self::CONTEXTID,
                'userid' => self::USERID, 'progress' => $progress,
            ];
            $loader = new personability_loader();
            $property = new \ReflectionProperty($loader, 'progress');
            $property->setAccessible(true);
            $property->setValue($loader, $progress);
            $method = new \ReflectionMethod($loader, 'load_saved_personparams');
            $method->setAccessible(true);

            $reads = $DB->perf_get_reads();
            $abilities = $method->invokeArgs($loader, [&$context]);
            $queries[$n] = $DB->perf_get_reads() - $reads;

            // The start values as the loop with one query per scale gave them.
            $all = \local_catquiz\catquiz::get_person_abilities(self::CONTEXTID, [$root, ...$subscales], [self::USERID]) ?: [];
            $last = [];
            foreach ($all as $pp) {
                $last[$pp->catscaleid] = $pp;
            }
            foreach ([$root, ...$subscales] as $scaleid) {
                $single = personparam_repository::get_latest_valid(self::USERID, self::CONTEXTID, $scaleid);
                $expected = $single ? (float) $single->ability
                    : (!empty($last[$scaleid]) ? floatval($last[$scaleid]->ability) : personability_loader::get_default_ability());
                if (abs($expected) == LOCAL_CATQUIZ_PERSONABILITY_MAX) {
                    $expected = personability_loader::DEFAULT_ABILITY;
                }
                $this->assertSame($expected, $abilities[$scaleid], "$n scales, scale $scaleid");
            }
        }
        $this->assertSame($queries[1], $queries[80], 'The queries do not grow with the scales.');
    }
}
