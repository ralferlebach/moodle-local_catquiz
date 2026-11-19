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

namespace local_catquiz\local;

use advanced_testcase;
use cache;
use local_catquiz\catquiz_handler;
use local_catquiz\catscale;
use local_catquiz\data\dataapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Issue #146: revisioned runtime pools - a change rebuilds the pools it touches, and no others; nothing is purged at
 * the start of an attempt; the store keeps the current pool and at most the one before; memory and cache bytes.
 *
 * Fixture: two CAT contexts C1 and C2; root R1 with subscale S1, root R2; items of R1, S1 and R2 in both contexts,
 * with parameters. Four pools: (C1, R1 with subscales), (C1, R2), (C2, R1 with subscales), (C2, R2).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\local\pool_revision
 * @covers     \local_catquiz\catscale
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pool_revision::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catscale::class)]
final class pool_revision_test extends advanced_testcase {
    /** @var int[] Contexts C1, C2. */
    private array $contexts = [];

    /** @var int[] Scales R1, S1, R2. */
    private array $scales = [];

    /** @var array Question ids by scale name. */
    private array $questions = [];

    /** @var array Item ids by context and scale name. */
    private array $items = [];

    /** @var int A question category. */
    private int $category;

    /**
     * The fixture of the class comment.
     *
     * @param int $perscale Questions per scale.
     */
    private function build(int $perscale = 3): void {
        global $DB;
        $now = time();
        foreach (['C1', 'C2'] as $name) {
            $this->contexts[$name] = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
                'name' => $name, 'description' => '', 'descriptionformat' => FORMAT_HTML,
                'starttimestamp' => $now - 1000, 'endtimestamp' => $now + 100000,
                'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 0,
            ]);
        }
        $this->scales['R1'] = $this->scale('R1', 0);
        $this->scales['S1'] = $this->scale('S1', $this->scales['R1']);
        $this->scales['R2'] = $this->scale('R2', 0);
        \cache_helper::purge_all();
        $this->category = (int) $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category()->id;
        foreach (['R1', 'S1', 'R2'] as $scale) {
            $this->questions[$scale] = $this->questions_in_bulk($perscale);
            foreach ($this->contexts as $context => $contextid) {
                $this->items[$context][$scale] = $this->items_in_bulk($this->questions[$scale], $this->scales[$scale], $contextid);
            }
        }
        \cache_helper::purge_all();
    }

    /**
     * A scale.
     *
     * @param string $name
     * @param int $parentid
     * @return int
     */
    private function scale(string $name, int $parentid): int {
        global $DB;
        return (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parentid, 'name' => $name, 'contextid' => $this->contexts['C1'],
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Questions, ready in the question bank: one through the generator, the others copied in bulk.
     *
     * @param int $n
     * @return int[]
     */
    private function questions_in_bulk(int $n): array {
        global $DB;
        if ($n <= 0) {
            return [];
        }
        $first = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $this->category]);
        $ids = [(int) $first->id];
        $template = $DB->get_record('question', ['id' => $first->id]);
        for ($i = 1; $i < $n; $i++) {
            $question = clone $template;
            unset($question->id);
            $question->name = "Q $i";
            $question->stamp = make_unique_id_code();
            $questionid = (int) $DB->insert_record('question', $question);
            $entryid = (int) $DB->insert_record('question_bank_entries', (object) [
                'questioncategoryid' => $this->category, 'idnumber' => null, 'ownerid' => 2,
            ]);
            $DB->insert_record('question_versions', (object) [
                'questionbankentryid' => $entryid, 'version' => 1, 'questionid' => $questionid,
                'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
            ]);
            $ids[] = $questionid;
        }
        return $ids;
    }

    /**
     * Items of questions in a scale and context, each with parameters of that context.
     *
     * @param int[] $questionids
     * @param int $scaleid
     * @param int $contextid
     * @return int[] Item ids.
     */
    private function items_in_bulk(array $questionids, int $scaleid, int $contextid): array {
        global $DB;
        $itemids = [];
        foreach ($questionids as $i => $questionid) {
            $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
                'componentid' => $questionid, 'componentname' => 'question', 'catscaleid' => $scaleid,
                'contextid' => $contextid, 'status' => LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE,
            ]);
            $paramid = (int) $DB->insert_record('local_catquiz_itemparams', (object) [
                'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question',
                'contextid' => $contextid, 'model' => 'raschbirnbaum', 'difficulty' => ($i % 9) - 4.0,
                'discrimination' => 1.0, 'guessing' => 0, 'json' => '{}', 'status' => LOCAL_CATQUIZ_STATUS_CALCULATED,
                'usable' => 1, 'timecreated' => time(), 'timemodified' => time(),
            ]);
            $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
            $itemids[] = $itemid;
        }
        return $itemids;
    }

    /**
     * The four pools, by name.
     *
     * @return array name => [contextid, scaleid, with subscales]
     */
    private function pools(): array {
        return [
            'C1/R1' => [$this->contexts['C1'], $this->scales['R1'], true],
            'C1/R2' => [$this->contexts['C1'], $this->scales['R2'], true],
            'C2/R1' => [$this->contexts['C2'], $this->scales['R1'], true],
            'C2/R2' => [$this->contexts['C2'], $this->scales['R2'], true],
        ];
    }

    /**
     * The keys of the four pools now.
     *
     * @return string[]
     */
    private function keys(): array {
        return array_map(fn($p) => catscale::pool_key($p[0], $p[2], $p[1], []), $this->pools());
    }

    /**
     * Reads every pool once (so that each is cached), and returns them.
     *
     * @return array name => pool
     */
    private function read_all(): array {
        $pools = [];
        foreach ($this->pools() as $name => [$contextid, $scaleid, $subs]) {
            $pools[$name] = (new catscale($scaleid))->get_testitems($contextid, $subs);
        }
        return $pools;
    }

    /**
     * The pools a change touched: their keys changed. The others are read warm - without a database read - and the
     * changed ones are what a cold read gives.
     *
     * @param callable $change
     * @return string[] Names of the pools with a new key.
     */
    private function touched(callable $change): array {
        global $DB;
        $this->read_all();
        $before = $this->keys();
        $change();
        $after = $this->keys();
        $touched = array_keys(array_diff_assoc($after, $before));
        $poolcache = cache::make('local_catquiz', 'runtimepool');
        foreach ($this->pools() as $name => [$contextid, $scaleid, $subs]) {
            if (in_array($name, $touched, true)) {
                $this->assertFalse($poolcache->get($after[$name]), "$name is built anew.");
                continue;
            }
            // Not built anew: the pool is still in the cache under its key, and reading it runs no pool query.
            $this->assertNotFalse($poolcache->get($after[$name]), "$name is kept.");
            $reads = $DB->perf_get_reads();
            (new catscale($scaleid))->get_testitems($contextid, $subs);
            $this->assertLessThanOrEqual(1, $DB->perf_get_reads() - $reads, "$name: no pool query (the scale itself at most).");
        }
        $warm = $this->read_all();
        \cache_helper::purge_by_definition('local_catquiz', 'runtimepool');
        \cache_helper::purge_by_definition('local_catquiz', pool_revision::CACHE);
        $this->assertEquals($this->read_all(), $warm, 'Every pool read after the change is the cold read.');
        return $touched;
    }

    /**
     * Each kind of change rebuilds exactly the pools it touches (AK4).
     */
    public function test_a_change_rebuilds_only_the_pools_it_touches(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();

        $this->assertSame(['C1/R1'], $this->touched(function () {
            $list = \local_catquiz\local\model\model_item_param_list::load_from_db(
                $this->contexts['C1'],
                'raschbirnbaum',
                [$this->scales['R1']]
            );
            $param = $list[$this->questions['R1'][0]];
            $param->set_parameters(['difficulty' => 2.5, 'discrimination' => 1.0]);
            $list->save_to_db($this->contexts['C1'], [$this->scales['R1']]);
        }), 'Parameters of R1 in C1.');

        $this->assertSame(['C2/R2'], $this->touched(function () use ($DB) {
            $record = $DB->get_record('local_catquiz_itemparams', ['itemid' => $this->items['C2']['R2'][1]]);
            $record->difficulty = -1.25;
            \local_catquiz\local\model\model_item_param::from_record($record)->save();
        }), 'One parameter of R2 in C2.');

        $this->assertSame(['C1/R1', 'C2/R1'], $this->touched(function () {
            $question = $this->getDataGenerator()->get_plugin_generator('core_question')
                ->create_question('truefalse', null, ['category' => $this->category]);
            catscale::add_or_update_testitem_to_scale($this->scales['S1'], (int) $question->id);
        }), 'A new item of the subscale S1: the pools of its root R1, in both contexts.');

        $this->assertSame(['C1/R2', 'C2/R2'], $this->touched(fn() => dataapi::update_catscale((object) [
            'id' => $this->scales['R2'], 'name' => 'R2 renamed', 'parentid' => 0,
        ])), 'The scale R2.');

        $this->assertSame(['C1/R2', 'C2/R2'], $this->touched(function () use ($DB) {
            $question = $DB->get_record('question', ['id' => $this->questions['R2'][2]]);
            $question->category = $this->category;
            $DB->set_field(
                'question_versions',
                'status',
                \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN,
                ['questionid' => $question->id]
            );
            \core\event\question_updated::create_from_question_instance($question, \context_system::instance())->trigger();
        }), 'A question of R2 hidden in the question bank.');

        $this->assertSame(['C1/R1', 'C1/R2'], $this->touched(
            fn() => pool_revision::context_changed($this->contexts['C1'])
        ), 'Everything of C1.');

        $this->assertSame([], $this->touched(function () {
            $other = $this->getDataGenerator()->get_plugin_generator('core_question')
                ->create_question('truefalse', null, ['category' => $this->category]);
            \core\event\question_updated::create_from_question_instance($other, \context_system::instance())->trigger();
        }), 'A question that is no item.');

        // The subscale S1 moves from R1 to R2: both roots, in both contexts; its items with it.
        $this->assertSame(['C1/R1', 'C1/R2', 'C2/R1', 'C2/R2'], $this->touched(fn() => dataapi::update_catscale((object) [
            'id' => $this->scales['S1'], 'parentid' => $this->scales['R2'],
        ])), 'S1 moved to R2.');
        $pools = $this->read_all();
        $this->assertArrayHasKey($this->questions['S1'][1], $pools['C1/R2']);
        $this->assertArrayNotHasKey($this->questions['S1'][1], $pools['C1/R1']);

        // The events alone, as other code triggers them: every pool.
        $this->assertSame(
            ['C1/R1', 'C1/R2', 'C2/R1', 'C2/R2'],
            $this->touched(fn() => \cache_helper::purge_by_event('changesintestitems')),
            'The event: all.'
        );
    }

    /**
     * The store keeps the current pool and at most the one before, however often the pool changes (AK6: memory).
     */
    public function test_the_store_keeps_the_current_pool_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $cache = cache::make('local_catquiz', 'runtimepool');
        $scale = new catscale($this->scales['R1']);
        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $scale->get_testitems($this->contexts['C1'], true);
            $keys[] = catscale::pool_key($this->contexts['C1'], true, $this->scales['R1'], []);
            pool_revision::params_changed($this->contexts['C1'], [$this->scales['R1']]);
        }
        $this->assertCount(10, array_unique($keys), 'Ten revisions, ten keys.');
        foreach (array_slice($keys, 0, 9) as $i => $key) {
            $this->assertFalse($cache->get($key), "The pool of revision $i was dropped when the next was built.");
        }
        $this->assertNotFalse($cache->get($keys[9]));
    }

    /**
     * The start of an attempt changes no revision and drops no pool (issue #146: no purge at the start).
     */
    public function test_an_attempt_start_leaves_every_pool(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $this->read_all();
        $keys = $this->keys();
        $tokens = pool_revision::cache()->get_many(['g', 'c' . $this->contexts['C1'], 'r' . $this->scales['R1']]);
        catquiz_handler::prepare_attempt_caches(4601);
        catquiz_handler::prepare_attempt_caches(4602);
        $this->assertSame($keys, $this->keys());
        $this->assertSame($tokens, pool_revision::cache()->get_many(['g', 'c' . $this->contexts['C1'], 'r' . $this->scales['R1']]));
        $reads = $DB->perf_get_reads();
        foreach ($this->pools() as [$contextid, $scaleid, $subs]) {
            $key = catscale::pool_key($contextid, $subs, $scaleid, []);
            $this->assertNotFalse(cache::make('local_catquiz', 'runtimepool')->get($key));
        }
        $this->assertSame(0, $DB->perf_get_reads() - $reads);
    }

    /**
     * A revision that falls out of its cache comes back as a new one: never an older pool (the revisions are tokens).
     */
    public function test_a_lost_revision_never_serves_an_older_pool(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $scale = new catscale($this->scales['R1']);
        $scale->get_testitems($this->contexts['C1'], true);
        $first = catscale::pool_key($this->contexts['C1'], true, $this->scales['R1'], []);
        pool_revision::cache()->delete('r' . $this->scales['R1']);
        $second = catscale::pool_key($this->contexts['C1'], true, $this->scales['R1'], []);
        $this->assertNotSame($first, $second);
        pool_revision::cache()->delete('r' . $this->scales['R1']);
        $this->assertNotContains(catscale::pool_key($this->contexts['C1'], true, $this->scales['R1'], []), [$first, $second]);
    }

    /**
     * Pool sizes 100, 500 and 1,000: the cache bytes grow with the items, by a bounded amount per item; reading a
     * warm pool costs memory of the order of the pool, and the session holds no pool (AK6).
     *
     * @return array
     */
    public static function pool_sizes(): array {
        return ['100 items' => [100], '500 items' => [500], '1000 items' => [1000]];
    }

    /**
     * The bytes of a pool by its size.
     *
     * @dataProvider pool_sizes
     * @param int $n
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pool_sizes')]
    public function test_the_bytes_of_a_pool(int $n): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build(0);
        $this->questions['big'] = $this->questions_in_bulk($n);
        $this->items_in_bulk($this->questions['big'], $this->scales['R2'], $this->contexts['C1']);
        $scale = new catscale($this->scales['R2']);
        $pool = $scale->get_testitems($this->contexts['C1'], false);
        $this->assertCount($n, $pool);
        $cached = cache::make('local_catquiz', 'runtimepool')->get(
            catscale::pool_key($this->contexts['C1'], false, $this->scales['R2'], [])
        );
        $bytes = strlen(serialize($cached));
        $this->assertLessThan(2500 * $n, $bytes, 'At most 2.5 KB per item in the cache.');
        $this->assertGreaterThan(100 * $n, $bytes);

        gc_collect_cycles();
        $before = memory_get_usage();
        $warm = $scale->get_testitems($this->contexts['C1'], false);
        $grown = memory_get_usage() - $before;
        $this->assertCount($n, $warm);
        $this->assertLessThan(4 * $bytes + 1048576, $grown, 'A warm read: of the order of the pool, no copies beyond.');
        unset($warm);

        // The session holds the statistics of the person, not the pool.
        $session = serialize(catscale::user_question_stats((int) $GLOBALS['USER']->id));
        $this->assertLessThan(200, strlen($session));
    }

    /**
     * Questions handed out in an attempt - 10, 50, 100, 150: the session grows by a pair of numbers per question, the
     * shared pool not at all (AK5, AK6).
     */
    public function test_the_session_by_questions_handed_out(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build(0);
        $this->questions['big'] = $this->questions_in_bulk(150);
        $this->items_in_bulk($this->questions['big'], $this->scales['R2'], $this->contexts['C1']);
        $scale = new catscale($this->scales['R2']);
        $pool = $scale->get_testitems($this->contexts['C1'], false);
        $key = catscale::pool_key($this->contexts['C1'], false, $this->scales['R2'], []);
        $poolbytes = strlen(serialize(cache::make('local_catquiz', 'runtimepool')->get($key)));
        $sizes = [];
        $handed = 0;
        foreach ([10, 50, 100, 150] as $n) {
            for (; $handed < $n; $handed++) {
                $question = $pool[$this->questions['big'][$handed]];
                $question->userlastattempttime = 1700000000 + $handed;
                catscale::update_testitem($this->contexts['C1'], $question, $this->scales['R2']);
            }
            $sizes[$n] = strlen(serialize(catscale::user_question_stats((int) $GLOBALS['USER']->id)));
            $this->assertSame($poolbytes, strlen(serialize(cache::make('local_catquiz', 'runtimepool')->get($key))));
        }
        $perquestion = ($sizes[150] - $sizes[10]) / 140;
        $this->assertLessThan(80, $perquestion, 'A question handed out: a pair of numbers. ' . json_encode($sizes));
        $this->assertLessThan($poolbytes / 10, $sizes[150], 'Far below the pool.');
    }
}
