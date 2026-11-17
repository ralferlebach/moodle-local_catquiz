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

namespace local_catquiz\local\operator;

use advanced_testcase;
use core_plugin_manager;
use local_catquiz\local\status;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The read-only operator checks of the re-audit of 17.11.2026 (BP-01 to BP-07) and their return contract.
 *
 * Each verdict is checked on both sides: data that must pass and data that must fail.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(operator_checks::class)]
final class operator_checks_test extends advanced_testcase {
    /** @var int Next free id for the fixed ids of the scenarios. */
    private int $nextid = 9000;

    /**
     * Files an activity attempt and its CAT attempt, optionally with progress and a final ability.
     *
     * @param int $instance
     * @param int $userid
     * @param array $opts hostid, catid, strategy, status, stopcode, resultvalid, answered, finished, progress, result
     * @return array [host id, CAT id]
     */
    private function attempt(int $instance, int $userid, array $opts = []): array {
        global $DB;
        $hostid = $opts['hostid'] ?? $this->nextid++;
        $catid = $opts['catid'] ?? $this->nextid++;
        $finished = $opts['finished'] ?? time() + 60;
        $DB->import_record('adaptivequiz_attempt', (object) [
            'id' => $hostid, 'instance' => $instance, 'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => 'complete',
            'attemptstopcriteria' => 'done', 'questionsattempted' => $opts['answered'] ?? 17, 'difficultysum' => 0,
            'standarderror' => 0.4, 'measure' => 0.2, 'timecreated' => $finished - 600, 'timemodified' => $finished,
            'timefinished' => $finished, 'stopcode' => $opts['stopcode'] ?? 'scalescomplete',
            'resultvalid' => $opts['resultvalid'] ?? 1,
        ]);
        $DB->import_record('local_catquiz_attempts', (object) [
            'id' => $catid, 'userid' => $userid, 'scaleid' => 5, 'contextid' => 9, 'courseid' => 1,
            'instanceid' => $instance, 'attemptid' => $hostid, 'component' => 'mod_adaptivequiz',
            'status' => status::to_int($opts['status'] ?? status::STOP_SCALES_COMPLETE),
            'teststrategy' => $opts['strategy'] ?? LOCAL_CATQUIZ_STRATEGY_RELSUBS, 'json' => '{}', 'debug_info' => '[]',
            'endtime' => $finished, 'timecreated' => $finished - 600, 'timemodified' => $finished,
        ]);
        if ($opts['progress'] ?? true) {
            $DB->insert_record('local_catquiz_progress', (object) [
                'userid' => $userid, 'component' => 'mod_adaptivequiz', 'attemptid' => $catid,
                'json' => json_encode($opts['progressjson'] ?? ['activescales' => [], 'droppedscales' => [5 => true],
                    'playedquestions' => [1 => ['is_pilot' => false]]]),
                'quizsettings' => '{}', 'timemodified' => $finished,
            ]);
        }
        if ($opts['result'] ?? true) {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'userid' => $userid, 'catscaleid' => 5, 'contextid' => 9, 'attemptid' => $catid, 'ability' => 0.3,
                'standarderror' => 0.4, 'status' => 0, 'isprimary' => 1, 'isvalid' => 1,
                'timecreated' => $finished, 'timemodified' => $finished,
            ]);
        }
        return [$hostid, $catid];
    }

    /**
     * Test settings of an activity with a minimum number of questions.
     *
     * @param int $instance
     * @param int $minimum
     */
    private function settings(int $instance, int $minimum): void {
        global $DB;
        $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => $instance, 'component' => 'mod_adaptivequiz', 'catscaleid' => 5, 'contextid' => 9,
            'courseid' => 1, 'json' => json_encode(['catquiz_minquestions' => $minimum]), 'status' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Every check returns one record of the contract.
     */
    public function test_every_check_follows_the_return_contract(): void {
        $this->resetAfterTest();
        operator_checks::set_system('Test');
        $keys = ['check_id', 'system', 'executed_at', 'versions', 'status', 'records', 'values', 'max_deviation', 'notes',
            'links', 'operator_confirmation'];
        foreach (
            [operator_checks::bp01(), operator_checks::bp02_snapshot(1), operator_checks::bp03(),
                operator_checks::bp04(), operator_checks::bp05(), operator_checks::bp06(), operator_checks::bp07()] as $result
        ) {
            $this->assertSame($keys, array_keys($result), $result['check_id']);
            $this->assertContains($result['status'], ['PASS', 'FAIL', 'NOT_RUN', 'INCONCLUSIVE']);
            $this->assertSame('Test', $result['system']);
            $this->assertSame(['engine', 'host', 'adapter'], array_keys($result['versions']));
            $this->assertNull($result['operator_confirmation']);
        }
        operator_checks::set_system('');
        $this->assertMatchesRegularExpression('/^site-[0-9a-f]{12}$/', operator_checks::system_label());
    }

    /**
     * Small counts are suppressed, zero and larger ones are shown.
     */
    public function test_small_cells_are_suppressed(): void {
        $this->assertSame('<5', operator_checks::cell(1, 5));
        $this->assertSame('<5', operator_checks::cell(4, 5));
        $this->assertSame(5, operator_checks::cell(5, 5));
        $this->assertSame(0, operator_checks::cell(0, 5));
    }

    /**
     * BP-01 passes for the installed trilogy and fails when the host is older than the engine requires.
     */
    public function test_bp01_checks_the_trilogy(): void {
        $this->resetAfterTest();
        $result = operator_checks::bp01();
        $this->assertSame(operator_checks::PASS, $result['status'], json_encode($result['notes']));
        $this->assertNotEmpty($result['values']['dependencies']);
        $this->assertSame(PHP_VERSION, $result['values']['php_cli']);

        set_config('version', 2000010100, 'mod_adaptivequiz');
        core_plugin_manager::reset_caches();
        $result = operator_checks::bp01();
        $this->assertSame(operator_checks::FAIL, $result['status']);
        core_plugin_manager::reset_caches();
    }

    /**
     * BP-02: a correct deletion passes; one that leaves data, touches another activity or deletes by the
     * activity attempt id fails; repeating it changes nothing.
     */
    public function test_bp02_judges_a_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $a = (int) $this->getDataGenerator()->create_user()->id;
        $b = (int) $this->getDataGenerator()->create_user()->id;
        $c = (int) $this->getDataGenerator()->create_user()->id;
        // Crossed ids: the activity attempt of A (instance 1) has the number of C's CAT attempt (instance 2).
        [$hosta, $cata] = $this->attempt(1, $a, ['hostid' => 7001, 'catid' => 8001]);
        $this->attempt(1, $b);
        [$hostc, $catc] = $this->attempt(2, $c, ['hostid' => 8101, 'catid' => 7001]);
        $before = operator_checks::bp02_snapshot(1);
        $this->assertSame(['activity_attempts' => 2, 'cat_attempts' => 2, 'progress_of_cat_attempts' => 2,
            'abilities_of_cat_attempts' => 2], $before['values']['target']);
        $this->assertSame(1, $before['values']['id_collisions']);

        // Correct: everything of instance 1 by the internal CAT ids.
        $cats = $DB->get_fieldset_select('local_catquiz_attempts', 'id', 'instanceid = 1');
        [$in, $p] = $DB->get_in_or_equal($cats);
        $DB->delete_records_select('local_catquiz_progress', "attemptid $in", $p);
        $DB->delete_records_select('local_catquiz_personparams', "attemptid $in", $p);
        $DB->delete_records_select('local_catquiz_attempts', "id $in", $p);
        $DB->delete_records('adaptivequiz_attempt', ['instance' => 1]);
        $after = operator_checks::bp02_snapshot(1, $before['values']['cat_ids']);
        $verdict = operator_checks::bp02_compare($before, $after);
        $this->assertSame(operator_checks::PASS, $verdict['status'], json_encode($verdict['notes']));
        $this->assertTrue($verdict['values']['others_unchanged']);
        // Repeated: nothing changes.
        $again = operator_checks::bp02_compare($after, operator_checks::bp02_snapshot(1, $before['values']['cat_ids']), false);
        $this->assertSame(operator_checks::PASS, $again['status'], json_encode($again['notes']));
    }

    /**
     * BP-02 fails on the deletion by the activity attempt id (callback in main): C's progress goes, A's stays.
     */
    public function test_bp02_fails_on_a_deletion_by_the_activity_attempt_id(): void {
        global $DB;
        $this->resetAfterTest();
        $a = (int) $this->getDataGenerator()->create_user()->id;
        $c = (int) $this->getDataGenerator()->create_user()->id;
        $this->attempt(1, $a, ['hostid' => 7001, 'catid' => 8001]);
        $this->attempt(2, $c, ['hostid' => 8101, 'catid' => 7001]);
        $before = operator_checks::bp02_snapshot(1);

        $DB->delete_records('local_catquiz_progress', ['attemptid' => 7001]);
        $DB->delete_records('local_catquiz_personparams', ['attemptid' => 8001]);
        $DB->delete_records('local_catquiz_attempts', ['id' => 8001]);
        $DB->delete_records('adaptivequiz_attempt', ['instance' => 1]);
        $verdict = operator_checks::bp02_compare($before, operator_checks::bp02_snapshot(1, $before['values']['cat_ids']));

        $this->assertSame(operator_checks::FAIL, $verdict['status']);
        $this->assertStringContainsString(
            'Neue verwaiste Zeilen: progress_orphan',
            implode(' ', $verdict['notes']),
            'The progress left behind is an orphan.'
        );
        $this->assertFalse($verdict['values']['others_unchanged']);
        $this->assertSame(1, $verdict['values']['target_after']['progress_of_cat_attempts']);
        $this->assertStringContainsString('progress', implode(' ', $verdict['notes']));
    }

    /**
     * BP-03: after the fix, an empty scale set after the minimum must not end as an error; small cells are
     * suppressed; 16, 17 and 25 are marked.
     */
    public function test_bp03_judges_the_attempts_after_the_fix(): void {
        $this->resetAfterTest();
        $this->settings(1, 12);
        $user = (int) $this->getDataGenerator()->create_user()->id;
        for ($i = 0; $i < 6; $i++) {
            $this->attempt(1, $user, ['answered' => 17]);
        }
        $this->attempt(1, $user, ['answered' => 16, 'strategy' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB]);
        $this->assertNotNull(operator_checks::first_upgrade_to('local_catquiz', operator_checks::FIX172_VERSION));

        $result = operator_checks::bp03(5);
        $this->assertSame(operator_checks::PASS, $result['status'], json_encode($result['notes']));
        $lengths = array_column($result['values']['lengths'], null, 'strategy');
        $this->assertSame(6, $lengths['RELSUBS']['attempts']);
        $this->assertTrue($lengths['RELSUBS']['marked']);
        $this->assertSame('<5', $lengths['LOWESTSUB']['attempts']);
        $row = array_values(array_filter($result['values']['combinations'], fn($r) => $r['strategy'] === 'RELSUBS'))[0];
        $this->assertSame(
            ['RELSUBS', 'yes', 'empty', 'stopscalescomplete', 'scalescomplete', '1'],
            [$row['strategy'], $row['minimum_reached'], $row['active_scale_set'], $row['engine_status'],
            $row['host_stopcode'],
            $row['resultvalid']]
        );

        // The pattern of #172 after the fix: FAIL.
        $this->attempt(1, $user, ['answered' => 17, 'stopcode' => 'error', 'status' => status::ERROR_GENERAL]);
        $this->assertSame(operator_checks::FAIL, operator_checks::bp03(5)['status']);
    }

    /**
     * BP-03: historical attempts are counted but not judged.
     */
    public function test_bp03_does_not_judge_history(): void {
        $this->resetAfterTest();
        $this->settings(1, 12);
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->attempt(1, $user, ['answered' => 17, 'stopcode' => 'error', 'status' => status::ERROR_GENERAL,
            'finished' => 1000]);

        $result = operator_checks::bp03(1);
        $this->assertSame(operator_checks::INCONCLUSIVE, $result['status']);
        $this->assertSame(1, $result['records']);
    }

    /**
     * BP-04 fails on active parameters from another context.
     */
    public function test_bp04_finds_mixed_contexts(): void {
        global $DB;
        $this->resetAfterTest();
        $item = $DB->insert_record('local_catquiz_items', (object) ['componentname' => 'question', 'componentid' => 1,
            'catscaleid' => 5, 'contextid' => 9, 'status' => 0, 'lastupdated' => 0]);
        $param = $DB->insert_record('local_catquiz_itemparams', (object) ['itemid' => $item, 'componentid' => 1,
            'componentname' => 'question', 'contextid' => 9, 'model' => 'rasch', 'difficulty' => 0.1, 'status' => 4,
            'usable' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        $DB->set_field('local_catquiz_items', 'activeparamid', $param, ['id' => $item]);
        $this->assertSame(operator_checks::INCONCLUSIVE, operator_checks::bp04(1)['status']);

        $DB->set_field('local_catquiz_itemparams', 'contextid', 10, ['id' => $param]);
        $result = operator_checks::bp04(1);
        $this->assertSame(operator_checks::FAIL, $result['status']);
        $this->assertSame(1, $result['values']['mixed_context_items']);
    }

    /**
     * BP-05: no traces, no verdict without SLO, a verdict with one; nearest-rank percentiles.
     */
    public function test_bp05_reports_the_hot_path(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame(operator_checks::NOT_RUN, operator_checks::bp05()['status']);
        foreach (range(1, 100) as $ms) {
            $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => 1, 'userid' => 2, 'kind' => 'answer',
                'questionnumber' => 1, 'requestms' => $ms * 10, 'dbqueries' => $ms, 'spans' => '[]', 'timecreated' => time()]);
        }
        $result = operator_checks::bp05();
        $this->assertSame(operator_checks::INCONCLUSIVE, $result['status']);
        $this->assertSame(
            ['p50' => 500.0, 'p90' => 900.0, 'p95' => 950.0, 'p99' => 990.0, 'max' => 1000.0],
            $result['values']['kinds']['answer']['request_ms']
        );
        $this->assertSame(operator_checks::PASS, operator_checks::bp05(950)['status']);
        $this->assertSame(operator_checks::FAIL, operator_checks::bp05(949)['status']);
    }

    /**
     * BP-06 fails when a finished attempt has neither progress nor a final result.
     */
    public function test_bp06_finds_unreachable_results(): void {
        $this->resetAfterTest();
        $this->assertSame(operator_checks::INCONCLUSIVE, operator_checks::bp06(1)['status']);
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->attempt(1, $user, ['progress' => false]);
        $this->assertSame(operator_checks::PASS, operator_checks::bp06(1)['status']);

        $this->attempt(1, $user, ['progress' => false, 'result' => false]);
        $result = operator_checks::bp06(1);
        $this->assertSame(operator_checks::FAIL, $result['status']);
        $this->assertSame(1, $result['values']['finished_attempts']['neither']);
    }

    /**
     * The checks write nothing.
     */
    public function test_the_checks_are_read_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->settings(1, 12);
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->attempt(1, $user);
        $before = $DB->perf_get_writes();

        operator_checks::bp01();
        operator_checks::bp02_snapshot(1);
        operator_checks::bp03();
        operator_checks::bp04();
        operator_checks::bp05();
        operator_checks::bp06();

        $this->assertSame($before, $DB->perf_get_writes());
    }
}
