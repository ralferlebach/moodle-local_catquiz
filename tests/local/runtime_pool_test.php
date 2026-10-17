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
use cache_helper;
use local_catquiz\catquiz_handler;
use local_catquiz\catscale;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The runtime item pool apart from the state of the attempts (issue #146).
 *
 * The pool was kept, hydrated, in the session cache of the attempts, written back whole for every
 * question, and every attempt start purged that cache - with the progress and the feedback of any other
 * attempt open in the session. Now the pool is in an application cache of its own, the same for
 * everybody; what depends on the person is added from a small table in the session; the state of an
 * attempt has keys of its own.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_cache::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(catscale::class, 'get_testitems')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catscale::class, 'update_testitem')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catscale::class, 'pool_key')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catscale::class, 'user_question_stats')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catquiz_handler::class, 'prepare_attempt_caches')]
final class runtime_pool_test extends advanced_testcase {
    /** @var int A CAT context that covers now. */
    private int $contextid;

    /** @var int The scale of the pool. */
    private int $scaleid;

    /** @var \stdClass[] The questions of the pool. */
    private array $questions = [];

    /**
     * A scale with three questions in a CAT context.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Pool context', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => time() - 1000, 'endtimestamp' => time() + 10000,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
        $this->scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 'Pool scale', 'contextid' => $this->contextid,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->questions[] = $this->item();
        }
    }

    /**
     * A true/false question in the scale.
     *
     * @return \stdClass
     */
    private function item(): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $generator->create_question('truefalse', null, ['category' => $generator->create_question_category()->id]);
        $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => $question->id, 'componentname' => 'question', 'catscaleid' => $this->scaleid,
            'contextid' => $this->contextid, 'status' => LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE,
        ]);
        return $question;
    }

    /**
     * The pool as an attempt gets it.
     *
     * @return array
     */
    private function pool(): array {
        return (new catscale($this->scaleid))->get_testitems($this->contextid, false);
    }

    /**
     * The current user is shown a question - step 0 of a question attempt, as the activity writes it.
     *
     * @param \stdClass $question
     */
    private function show(\stdClass $question): void {
        $quba = question_engine::make_questions_usage_by_activity('local_catquiz', \context_system::instance());
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(\question_bank::load_question($question->id));
        $quba->start_question($slot);
        question_engine::save_questions_usage_by_activity($quba);
    }

    /**
     * The pool is the same for everybody: the statistics of one person are not in it for another, and
     * it is read from the database once.
     */
    public function test_the_pool_is_shared_without_the_statistics_of_a_person(): void {
        global $DB;
        $viewer = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);
        $this->show($this->questions[0]);

        $pool = $this->pool();
        $this->assertCount(3, $pool);
        $this->assertSame(1, $pool[$this->questions[0]->id]->userattempts);
        $this->assertGreaterThan(0, $pool[$this->questions[0]->id]->userlastattempttime);
        $this->assertNull($pool[$this->questions[1]->id]->userlastattempttime);

        $cached = cache::make('local_catquiz', 'runtimepool')->get(catscale::pool_key($this->contextid, false, $this->scaleid, []));
        $this->assertNotFalse($cached, 'The pool is in its own cache.');
        foreach ($cached as $row) {
            $this->assertNull($row->userlastattempttime, 'Nothing of a person in the shared pool.');
        }

        // A warm call of the same person, for comparison: the pool and the statistics are cached.
        $queries = $DB->perf_get_reads();
        $this->pool();
        $warm = $DB->perf_get_reads() - $queries;

        // The reads of the statistics of the other person alone (a recordset: several reads on some databases).
        $queries = $DB->perf_get_reads();
        \local_catquiz\catquiz::get_user_question_stats((int) $other->id);
        $statistics = $DB->perf_get_reads() - $queries;

        $this->setUser($other);
        $queries = $DB->perf_get_reads();
        $pool = $this->pool();
        $this->assertNull($pool[$this->questions[0]->id]->userlastattempttime, 'Not what another person saw.');
        $this->assertSame($warm + $statistics, $DB->perf_get_reads() - $queries, 'Only the statistics of the person are read.');
    }

    /**
     * A question handed out changes the statistics of the person, not the pool; the pool is not written
     * again, and nothing of it is in the session.
     */
    public function test_a_question_handed_out_writes_only_the_statistics(): void {
        $this->pool();
        $poolcache = cache::make('local_catquiz', 'runtimepool');
        $key = catscale::pool_key($this->contextid, false, $this->scaleid, []);
        $before = serialize($poolcache->get($key));

        $question = $this->pool()[$this->questions[1]->id];
        $question->userlastattempttime = 1234567;
        $question->lasttimeplayedpenaltyfactor = 0.5;
        catscale::update_testitem($this->contextid, $question, $this->scaleid);

        $this->assertSame($before, serialize($poolcache->get($key)), 'The pool is not written again.');
        $pool = $this->pool();
        $this->assertSame(1234567, $pool[$this->questions[1]->id]->userlastattempttime);
        $this->assertSame(1, $pool[$this->questions[1]->id]->userattempts);
        $this->assertObjectNotHasProperty('lasttimeplayedpenaltyfactor', $pool[$this->questions[1]->id]);

        $session = serialize(attempt_cache::cache()->get(
            sprintf('userquestionstats_%d_0', (int) $GLOBALS['USER']->id)
        ));
        $this->assertLessThan(200, strlen($session), 'A question is a pair of numbers in the session.');
    }

    /**
     * Starting an attempt leaves every other attempt of the session as it is: its progress, its
     * feedback, its values; and the pool.
     */
    public function test_starting_an_attempt_keeps_the_other_attempts(): void {
        global $USER;
        catquiz_handler::prepare_attempt_caches(100);
        $cache = attempt_cache::cache();
        $cache->set('progress_cat_555', 'progress of attempt 100');
        $cache->set('feedbackdata_100', ['numplayed' => 3, 'data' => []]);
        attempt_cache::set('abilitybeforeattempt', 100, 0.75);
        $this->pool();
        $this->show($this->questions[2]);
        $generation = catscale::user_question_stats((int) $USER->id);

        catquiz_handler::prepare_attempt_caches(200);

        $this->assertSame('progress of attempt 100', $cache->get('progress_cat_555'));
        $this->assertSame(['numplayed' => 3, 'data' => []], $cache->get('feedbackdata_100'));
        $this->assertSame(0.75, attempt_cache::get('abilitybeforeattempt', 100));
        $this->assertNotFalse(attempt_cache::get('starttime', 200));
        $this->assertFalse(attempt_cache::cache()->get(attempt_cache::key('abilitybeforeattempt', 200)));
        $this->assertNotFalse(
            cache::make('local_catquiz', 'runtimepool')->get(catscale::pool_key($this->contextid, false, $this->scaleid, [])),
            'The pool stays.'
        );
        // The statistics of the person are read anew: what was shown since is in them.
        $this->assertArrayNotHasKey((int) $this->questions[2]->id, $generation);
        $this->assertArrayHasKey((int) $this->questions[2]->id, catscale::user_question_stats((int) $USER->id));
    }

    /**
     * A session from before the upgrade is cleared at the first attempt start - its pools are not read
     * any more and would stay to its end; later starts clear nothing.
     */
    public function test_the_old_session_layout_is_cleared_once(): void {
        $cache = attempt_cache::cache();
        $cache->set('testitems_1_1_7_0', array_fill(0, 50, 'an old pool'));

        catquiz_handler::prepare_attempt_caches(300);
        $this->assertFalse($cache->get('testitems_1_1_7_0'));

        $cache->set('progress_cat_9', 'running');
        catquiz_handler::prepare_attempt_caches(301);
        $this->assertSame('running', $cache->get('progress_cat_9'));
    }

    /**
     * The values of an attempt: its own; the session-wide ones of the old layout only when it has none.
     */
    public function test_values_of_an_attempt_are_its_own(): void {
        $cache = attempt_cache::cache();
        $cache->set('totalnumberoftestitems', 99);
        attempt_cache::set('totalnumberoftestitems', 1, 10);
        attempt_cache::set('totalnumberoftestitems', 2, 20);

        $this->assertSame(10, attempt_cache::get('totalnumberoftestitems', 1));
        $this->assertSame(20, attempt_cache::get('totalnumberoftestitems', 2));
        $this->assertSame(99, attempt_cache::get('totalnumberoftestitems', 3), 'Running during the upgrade.');
    }

    /**
     * Items, their parameters and the scales invalidate the pool; the key holds context, scale and the
     * selected subscales, in any order.
     */
    public function test_changes_invalidate_the_pool(): void {
        $key = catscale::pool_key($this->contextid, false, $this->scaleid, []);
        $poolcache = cache::make('local_catquiz', 'runtimepool');
        foreach (['changesintestitems', 'changesinitemparams', 'changesincatscales'] as $event) {
            $this->pool();
            $this->assertNotFalse($poolcache->get($key));
            cache_helper::purge_by_event($event);
            $this->assertFalse($poolcache->get($key), $event);
        }

        $this->assertSame(
            catscale::pool_key(1, true, 2, [5, 3]),
            catscale::pool_key(1, true, 2, [3, 5])
        );
        $this->assertNotSame(catscale::pool_key(1, true, 2, [3]), catscale::pool_key(1, true, 2, [5]));
        $this->assertNotSame(catscale::pool_key(1, true, 2, []), catscale::pool_key(2, true, 2, []));
        $this->assertNotSame(catscale::pool_key(1, true, 2, []), catscale::pool_key(1, false, 2, []));

        // A new item, added the way the manager adds it, is in the next pool.
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'truefalse',
            null,
            ['category' => $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category()->id]
        );
        $this->pool();
        catscale::add_or_update_testitem_to_scale($this->scaleid, (int) $question->id);
        $this->assertArrayHasKey((int) $question->id, $this->pool());
    }

    /**
     * Cold and warm: the same pool.
     */
    public function test_cold_and_warm_pools_are_the_same(): void {
        $this->show($this->questions[1]);
        $warm = $this->pool();
        $this->assertEquals($warm, $this->pool());
        cache::make('local_catquiz', 'runtimepool')->purge();
        attempt_cache::cache()->purge();
        $this->assertEquals($warm, $this->pool());
    }
}
