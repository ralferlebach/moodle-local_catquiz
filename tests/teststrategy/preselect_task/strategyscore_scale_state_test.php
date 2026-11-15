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
 * Tests for the scale values of a selection step (issues #27 and #161).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\teststrategy\progress;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The scale values of a selection step are computed once per scale, and the selection stays what it was
 * (issues #27 and #161).
 *
 * strategyscore::run() computed the test information of a scale - item parameters of the scale and its subscales
 * read from the cache, filtered by the played questions, summed up - again for every candidate and each of its
 * scales. With a pool of 2,420 items a step took 60 s, in the load test up to 82 s. The values depend on the scale
 * only; they are now computed once per scale and step, and the best candidate is kept instead of sorting all.
 *
 * The tests compare every score and the selected question with the previous code, which is kept below as the
 * reference (legacy_strategyscore_run), for the four strategies built on strategyscore and randomly drawn states;
 * and count the reads of the item parameters, which grew with the number of candidates before.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\strategyscore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\fisherinformation::class)]
final class strategyscore_scale_state_test extends advanced_testcase {
    /** @var int CAT context of the items. */
    private const CONTEXTID = 4711;

    /** @var int[] Scale ids: root, two children, a grandchild under the first child. */
    private array $scales = [];

    /** @var array Questions of the pool: id => [scaleid, difficulty]. */
    private array $pool = [];

    /**
     * A scale tree and a pool of items with active parameters in one context.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $scale = fn(int $parent, string $name) => (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parent, 'name' => $name, 'label' => $name, 'contextid' => self::CONTEXTID,
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        $root = $scale(0, 'root');
        $a = $scale($root, 'a');
        $b = $scale($root, 'b');
        $a1 = $scale($a, 'a1');
        $this->scales = [$root, $a, $b, $a1];

        mt_srand(161);
        for ($i = 0; $i < 60; $i++) {
            $questionid = 9000 + $i;
            $scaleid = $this->scales[$i % 4];
            $difficulty = mt_rand(-300, 300) / 100;
            $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
                'componentname' => 'question', 'componentid' => $questionid, 'catscaleid' => $scaleid,
                'contextid' => self::CONTEXTID, 'lastupdated' => 0, 'status' => 0,
            ]);
            $paramid = (int) $DB->insert_record('local_catquiz_itemparams', (object) [
                'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question',
                'contextid' => self::CONTEXTID, 'model' => 'raschbirnbaum', 'difficulty' => $difficulty,
                'discrimination' => mt_rand(50, 250) / 100, 'guessing' => 0, 'json' => '{}', 'status' => 4,
                'timecreated' => 0, 'timemodified' => 0,
            ]);
            $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
            $this->pool[$questionid] = [$scaleid, $difficulty];
        }
        \cache_helper::purge_all();
    }

    /**
     * The four strategies built on strategyscore, each with the previous run() as reference.
     *
     * @return array
     */
    public static function strategies(): array {
        return [
            'HIGHESTSUB' => ['strength'],
            'LOWESTSUB' => ['deficit'],
            'ALLSUBS' => ['allsubs'],
            'RELSUBS' => ['relevant'],
        ];
    }

    /**
     * A task of the strategy, new or as before issue #27.
     *
     * @param string $strategy
     * @param bool $legacy
     * @return strategyscore
     */
    private static function task(string $strategy, bool $legacy): strategyscore {
        if ($legacy) {
            return match ($strategy) {
                'strength' => new legacy_strategystrengthscore(),
                'deficit' => new legacy_strategydeficitscore(),
                'allsubs' => new legacy_inferallsubscalesscore(),
                'relevant' => new legacy_strategyrelevantscore(),
            };
        }
        return match ($strategy) {
            'strength' => new strategystrengthscore(),
            'deficit' => new strategydeficitscore(),
            'allsubs' => new inferallsubscalesscore(),
            'relevant' => new strategyrelevantscore(),
        };
    }

    /**
     * A selection step in a randomly drawn state: played questions with answers, abilities, active scales.
     *
     * @param int $seed
     * @return array The context of the step; the candidates are fresh objects on every call with the same seed.
     */
    private function context(int $seed): array {
        mt_srand($seed);
        [$root, $a, $b, $a1] = $this->scales;
        $ids = array_keys($this->pool);
        $played = [];
        $responses = [];
        $byscale = array_fill_keys($this->scales, []);
        $parents = [$a => $root, $b => $root, $a1 => $a];
        foreach ($ids as $id) {
            if (mt_rand(0, 99) < 20) {
                [$scaleid, $difficulty] = $this->pool[$id];
                $question = (object) ['id' => $id, 'catscaleid' => $scaleid, 'difficulty' => $difficulty];
                $played[$id] = $question;
                $responses[$id] = ['fraction' => (float) mt_rand(0, 1)];
                for ($s = $scaleid; $s; $s = $parents[$s] ?? 0) {
                    $byscale[$s][$id] = $question;
                }
            }
        }
        $abilities = [];
        foreach ($this->scales as $scaleid) {
            // Now and then a scale without an ability yet: it is left out of the score.
            if ($scaleid === $root || mt_rand(0, 9) > 1) {
                $abilities[$scaleid] = mt_rand(-250, 250) / 100;
            }
        }
        $active = array_values(array_filter($this->scales, fn($s) => $s === $root || mt_rand(0, 9) > 2));

        $questions = [];
        foreach ($ids as $id) {
            if (isset($played[$id])) {
                continue;
            }
            [$scaleid, $difficulty] = $this->pool[$id];
            $information = [];
            foreach ($this->scales as $s) {
                $information[$s] = mt_rand(0, 1000) / 1000;
            }
            $questions[$id] = (object) [
                'id' => $id, 'catscaleid' => $scaleid, 'difficulty' => $difficulty,
                'fisherinformation' => $information,
                'lasttimeplayedpenaltyfactor' => [0, 0.5, 1][mt_rand(0, 2)],
            ];
        }
        if ($seed % 2 === 0) {
            // Every second candidate a twin of the one before: the best score is reached twice, exactly, and the
            // order decides by the id.
            $previous = null;
            foreach ($questions as $id => $question) {
                if ($previous && ($id - 9000) % 2 === 1) {
                    $question->catscaleid = $previous->catscaleid;
                    $question->difficulty = $previous->difficulty;
                    $question->fisherinformation = $previous->fisherinformation;
                    $question->lasttimeplayedpenaltyfactor = $previous->lasttimeplayedpenaltyfactor;
                }
                $previous = $question;
            }
        }
        $minattempts = mt_rand(0, 4);
        // The double last: PHPUnit draws from mt_rand() when it creates the class of the first double, and the
        // state must not depend on whether one was made before.
        $progress = $this->createMock(progress::class);
        $progress->method('get_user_responses')->willReturn($responses);
        $progress->method('get_playedquestions')->willReturnCallback(
            fn(bool $byscalearg = false, ?int $scaleid = null) => $byscalearg ? ($byscale[$scaleid] ?? []) : $played
        );
        $progress->method('get_active_scales')->willReturn($active);
        $progress->method('is_active_scale')->willReturnCallback(fn(int $s) => in_array($s, $active));
        $progress->method('get_abilities')->willReturn($abilities);

        return [
            'contextid' => self::CONTEXTID,
            'catscaleid' => $root,
            'min_attempts_per_scale' => $minattempts,
            'questions' => $questions,
            'progress' => $progress,
        ];
    }

    /**
     * Every score and the selected question are bit for bit those of the previous code.
     *
     * @param string $strategy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_scores_and_selection_are_unchanged(string $strategy): void {
        $ties = 0;
        for ($seed = 1; $seed <= 40; $seed++) {
            $before = $this->context($seed);
            $after = $this->context($seed);
            $legacy = self::task($strategy, true)->run($before)->unwrap();
            $now = self::task($strategy, false)->run($after)->unwrap();

            $this->assertSame(array_keys($before['questions']), array_keys($after['questions']));
            foreach ($before['questions'] as $id => $question) {
                $this->assertSame(
                    property_exists($question, 'score') ? $question->score : 'none',
                    property_exists($after['questions'][$id], 'score') ? $after['questions'][$id]->score : 'none',
                    "seed $seed, question $id: score"
                );
            }
            $this->assertSame($legacy ? $legacy->id : null, $now ? $now->id : null, "seed $seed: selected question");

            $scores = array_filter(array_map(
                fn($q) => property_exists($q, 'score') ? $q->score : null,
                $after['questions']
            ), fn($s) => $s !== null);
            if ($scores && count(array_keys($scores, max($scores), true)) > 1) {
                $ties++;
            }
        }
        // Every second state has exact ties at the top; they are decided by the id as before.
        $this->assertGreaterThan(0, $ties, 'The states include ties for the best score.');
    }

    /**
     * The information annotated per scale is the one get_fisherinformation() gives (issue #27: parameters and model
     * are read once per item now, not once per item and scale).
     */
    public function test_fisherinformation_per_scale_is_unchanged(): void {
        global $DB;
        mt_srand(27);
        $abilities = [];
        foreach ($this->scales as $scaleid) {
            $abilities[$scaleid] = mt_rand(-400, 400) / 100;
        }
        // An integer ability as well: get_fisherinformation() takes it as float.
        $abilities[$this->scales[1]] = 1;
        $progress = $this->createMock(progress::class);
        $progress->method('get_abilities')->willReturn($abilities);
        $task = new fisherinformation();
        $rows = $DB->get_records('local_catquiz_itemparams', ['contextid' => self::CONTEXTID]);
        $this->assertCount(60, $rows);
        foreach ($rows as $row) {
            $item = clone $row;
            $item->id = $row->componentid;
            $context = [
                'installed_models' => ['raschbirnbaum' => true],
                'progress' => $progress,
                'original_questions' => [$item->id => (object) ['id' => $item->id]],
            ];
            $task->annotate($item, $context);
            foreach ($abilities as $scaleid => $ability) {
                $expected = $task->get_fisherinformation(clone $row, $ability);
                $this->assertSame($expected, $item->fisherinformation[$scaleid]);
                $this->assertSame($expected, $context['original_questions'][$item->id]->fisherinformation[$scaleid]);
            }
        }
    }

    /**
     * The item parameters are read once per scale and step, not once per candidate and scale.
     *
     * Counted at the cache: every model_item_param_list::get() is one get there. The previous code is run as well,
     * so the test shows it can tell the two apart.
     *
     * @param string $strategy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
    public function test_item_parameters_are_read_once_per_scale(string $strategy): void {
        global $CFG;
        $CFG->perfdebug = 15;
        \cache_factory::reset();

        $reads = function (bool $legacy) use ($strategy): int {
            \cache_helper::purge_all();
            $before = self::cache_reads();
            $context = $this->context(7);
            self::task($strategy, $legacy)->run($context);
            return self::cache_reads() - $before;
        };
        $now = $reads(false);
        $legacy = $reads(true);

        $this->assertGreaterThan(0, $now, 'The item parameters are read at all.');
        $this->assertLessThanOrEqual(count($this->scales), $now, 'At most one read per scale.');
        $this->assertGreaterThan(count($this->scales) * 5, $legacy, 'The previous code read them per candidate.');
    }

    /**
     * Reads of the item parameter cache so far: one per get(), whichever store answered it.
     *
     * @return int
     */
    private static function cache_reads(): int {
        $stats = \cache_helper::get_stats();
        $reads = 0;
        foreach ($stats as $definition => $data) {
            if ($definition !== 'local_catquiz/catquiz_item_params') {
                continue;
            }
            foreach ($data['stores'] ?? [] as $name => $store) {
                // A miss of the static acceleration is followed by a read of the store: counted there.
                $reads += (int) ($store['hits'] ?? 0)
                    + ($name === \core_cache\store::STATIC_ACCEL ? 0 : (int) ($store['misses'] ?? 0));
            }
        }
        return $reads;
    }
}

/**
 * strategyscore::run() as it was before issue #27, unchanged, as the reference of the tests above.
 *
 * @package    local_catquiz
 * @copyright  2024 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait legacy_strategyscore_run {
    /**
     * Run preselect task.
     *
     * @param array $context
     * @return \local_catquiz\local\result
     */
    public function run(array &$context): \local_catquiz\local\result {
        $this->context = $context;
        $this->progress = $context['progress'];
        $userresponses = $this->progress->get_user_responses();
        $scalefractions = [];
        $scalecount = [];

        foreach ($this->get_scales() as $scaleid) {
            $played = $this->progress->get_playedquestions(true, $scaleid);
            $scalecount[$scaleid] = count($played);
            if ($scalecount[$scaleid] === 0) {
                $scalefractions[$scaleid] = 0.5;
            } else {
                $scalefractions[$scaleid] = array_sum(
                    array_map(
                        fn ($q) => $userresponses[$q->id]['fraction'],
                        $played
                    )
                ) / $scalecount[$scaleid];
            }
        }

        foreach ($context['questions'] as $question) {
            $affectedscales = [$question->catscaleid, ...\local_catquiz\catscale::get_ancestors($question->catscaleid)];
            $affectedscales = array_reverse($affectedscales);

            foreach ($affectedscales as $scaleid) {
                if (
                    $this->ignore_scale($scaleid)
                    || is_null($scaleability = $this->get_scale_ability($scaleid))
                ) {
                    continue;
                }

                $scaleitems = \local_catquiz\local\model\model_item_param_list::get(
                    $context['contextid'],
                    null,
                    [$scaleid, ...\local_catquiz\catscale::get_subscale_ids($scaleid)]
                )
                    ->filter_by_componentids(array_keys($this->progress->get_playedquestions()));
                $standarderrorplayed = \local_catquiz\catscale::get_standarderror($scaleability, $scaleitems, INF);
                $testinfo = $standarderrorplayed === INF ? 0 : 1 / $standarderrorplayed ** 2;
                $question->processterm = max(0.1, $testinfo) / max(1, $scalecount[$scaleid]);
                $abilitydifference = ($scaleability - $this->progress->get_abilities()[$context['catscaleid']]);
                $question->scaleterm = $this->get_question_scaleterm($testinfo, $abilitydifference);
                $question->itemterm = $this->get_question_itemterm(
                    $testinfo,
                    $scalefractions[$scaleid],
                    $question->difficulty,
                    $scaleability,
                    $scalecount[$scaleid],
                    $context['min_attempts_per_scale']
                );

                $score = $this->get_score($question, $scaleid);
                if (! property_exists($question, 'score') || $score > $question->score) {
                    $question->score = $score;
                }
            }
        }

        $remainingquestions = array_filter($context['questions'], fn ($q) => property_exists($q, 'score'));
        uasort($remainingquestions, function ($q1, $q2) {
            if (! ($q2->score === $q1->score)) {
                return $q2->score <=> $q1->score;
            }
            return $q1->id <=> $q2->id;
        });

        $selectedquestion = reset($remainingquestions);

        return \local_catquiz\local\result::ok($selectedquestion);
    }
}

/**
 * strategystrengthscore with run() as before issue #27. A named class each: phpmd (pdepend) takes anonymous classes of one file
 * for one class and stops on the trait used four times.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_strategystrengthscore extends strategystrengthscore {
    use legacy_strategyscore_run;
}

/**
 * strategydeficitscore with run() as before issue #27. A named class each: phpmd (pdepend) takes anonymous classes of one file
 * for one class and stops on the trait used four times.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_strategydeficitscore extends strategydeficitscore {
    use legacy_strategyscore_run;
}

/**
 * inferallsubscalesscore with run() as before issue #27. A named class each: phpmd (pdepend) takes anonymous classes of one file
 * for one class and stops on the trait used four times.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_inferallsubscalesscore extends inferallsubscalesscore {
    use legacy_strategyscore_run;
}

/**
 * strategyrelevantscore with run() as before issue #27. A named class each: phpmd (pdepend) takes anonymous classes of one file
 * for one class and stops on the trait used four times.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_strategyrelevantscore extends strategyrelevantscore {
    use legacy_strategyscore_run;
}
