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

namespace local_catquiz\local\validation;

use advanced_testcase;
use cache;
use local_catquiz\catquiz_handler;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Test settings that no attempt can meet are found before a test starts (issue #60).
 *
 * The scale tree of the issue: a root K1 without items of its own, two subscales with twelve calibrated
 * items each. Minimum 10 with at most 4 per subscale cannot be met - for a strategy whose selection the
 * per-scale maximum limits; for FASTEST and CLASSIC, which it does not limit, it can.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(settings_validator::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(catquiz_handler::class, 'instance_form_validation')]
#[\PHPUnit\Framework\Attributes\CoversMethod(catquiz_handler::class, 'fetch_question_id')]
final class settings_validator_test extends advanced_testcase {
    /** @var int The root scale. */
    private int $root;

    /** @var int[] The two subscales. */
    private array $subs = [];

    /** @var int The CAT context. */
    private int $contextid;

    /**
     * The scale tree, with twelve calibrated items in each subscale and a pilot item in the first.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Validator context', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => time() - 100, 'endtimestamp' => time() + 10000,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
        $this->root = $this->scale('K1', 0);
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category();
        foreach (['K1.1', 'K1.2'] as $name) {
            $scale = $this->scale($name, $this->root);
            $this->subs[] = $scale;
            for ($i = 0; $i < 12; $i++) {
                $this->item($category->id, $scale, -2.0 + $i * 0.35);
            }
        }
        $this->item($category->id, $this->subs[0], null);
        cache::make('local_catquiz', 'runtimepool')->purge();
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
            'name' => $name, 'parentid' => $parentid, 'contextid' => $this->contextid,
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * A question in a scale - calibrated with the given difficulty, or a pilot without parameters.
     *
     * @param int $categoryid
     * @param int $scaleid
     * @param float|null $difficulty
     */
    private function item(int $categoryid, int $scaleid, ?float $difficulty): void {
        global $DB;
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $categoryid]);
        $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => $question->id, 'componentname' => 'question', 'catscaleid' => $scaleid,
            'contextid' => $this->contextid, 'status' => LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE,
        ]);
        if ($difficulty === null) {
            return;
        }
        $paramid = $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid, 'componentid' => $question->id, 'componentname' => 'question',
            'contextid' => $this->contextid, 'model' => 'raschbirnbaum', 'difficulty' => $difficulty,
            'discrimination' => 1.2, 'guessing' => 0, 'json' => '{}', 'status' => LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY,
            'usable' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
    }

    /**
     * Settings of a test on the tree.
     *
     * @param int $strategy
     * @param int $min
     * @param int $max
     * @param int $maxperscale
     * @param array $more
     * @return stdClass
     */
    private function settings(int $strategy, int $min, int $max, int $maxperscale, array $more = []): stdClass {
        $settings = (object) ($more + [
            'catquiz_catscales' => $this->root,
            'catquiz_selectteststrategy' => $strategy,
            'maxquestionsgroup' => (object) ['catquiz_minquestions' => $min, 'catquiz_maxquestions' => $max],
            'maxquestionsscalegroup' => (object) [
                'catquiz_minquestionspersubscale' => 0, 'catquiz_maxquestionspersubscale' => $maxperscale,
            ],
            'catquiz_standarderrorgroup' => (object) ['catquiz_standarderror_min' => 0.0, 'catquiz_standarderror_max' => 1.0],
        ]);
        foreach ($this->subs as $scaleid) {
            $settings->{'catquiz_subscalecheckbox_' . $scaleid} = '1';
        }
        return $settings;
    }

    /**
     * The codes of the hard errors.
     *
     * @param stdClass $settings
     * @return string[]
     */
    private function errors(stdClass $settings): array {
        return array_column(settings_validator::errors(settings_validator::validate($settings)), 'code');
    }

    /**
     * The example of the issue: minimum 10, at most 4 in each of two subscales - at most 8 questions.
     * The message names the values.
     */
    public function test_the_minimum_is_checked_against_the_per_scale_maximum(): void {
        $findings = settings_validator::errors(settings_validator::validate(
            $this->settings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 10, 20, 4)
        ));
        $this->assertSame(['minimumunreachable'], array_column($findings, 'code'));
        $this->assertSame(8, $findings[0]['a']['effective']);
        $this->assertStringContainsString('10', $findings[0]['message']);
        $this->assertStringContainsString('8', $findings[0]['message']);

        $this->assertSame([], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 8, 20, 4)), 'Exactly reachable.');
        $this->assertSame([], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 10, 20, 0)), '0 is unlimited.');
    }

    /**
     * FASTEST and CLASSIC are not cut down by a per-scale maximum (issue #135): the same settings are valid.
     */
    public function test_strategies_the_per_scale_maximum_does_not_limit(): void {
        foreach ([LOCAL_CATQUIZ_STRATEGY_FASTEST, LOCAL_CATQUIZ_STRATEGY_CLASSIC] as $strategy) {
            $this->assertSame([], $this->errors($this->settings($strategy, 10, 20, 4)), "Strategy $strategy");
        }
    }

    /**
     * The pool counts what the runtime plays: the pilot item without parameters adds nothing - 24 items,
     * not 25 - and a question in two sibling scales is one question.
     */
    public function test_the_minimum_is_checked_against_the_usable_items(): void {
        global $DB;
        $this->assertSame([], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 24, 0, 0)));
        $this->assertSame(['minimumunreachable'], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 25, 0, 0)));

        // Every item of the second subscale also in the first: still 24 different questions.
        foreach ($DB->get_records('local_catquiz_items', ['catscaleid' => $this->subs[1]]) as $item) {
            unset($item->id);
            $item->catscaleid = $this->subs[0];
            $DB->insert_record('local_catquiz_items', $item);
        }
        cache::make('local_catquiz', 'runtimepool')->purge();
        $this->assertSame(['minimumunreachable'], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 25, 0, 0)));
    }

    /**
     * No usable item at all, and a scale the strategy must cover without any: errors that also keep an attempt
     * from starting.
     */
    public function test_scales_without_usable_items(): void {
        global $DB;
        $empty = $this->scale('K1.3', $this->root);
        $settings = $this->settings(LOCAL_CATQUIZ_STRATEGY_ALLSUBS, 1, 0, 0);
        $settings->{'catquiz_subscalecheckbox_' . $empty} = '1';
        $this->assertSame(['scalewithoutitems'], $this->errors($settings));
        $this->assertSame([], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 1, 0, 0)), 'No coverage needed.');

        $DB->set_field('local_catquiz_items', 'status', LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE, []);
        cache::make('local_catquiz', 'runtimepool')->purge();
        $this->assertSame(['nousableitems'], $this->errors($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 1, 0, 0)));
    }

    /**
     * Time limits: switched on without a value is an error; a question limit as long as the attempt is a
     * warning; minimum times question time above the attempt time is no error.
     */
    public function test_time_limits(): void {
        $timed = fn(array $group, $include = 1) => settings_validator::validate($this->settings(
            LOCAL_CATQUIZ_STRATEGY_FASTEST,
            10,
            0,
            0,
            ['catquiz_includetimelimit' => $include, 'catquiz_timelimitgroup' => (object) $group]
        ));
        $codes = fn(array $findings, string $level) => array_column(
            array_filter($findings, fn($f) => $f['level'] === $level),
            'code'
        );

        $this->assertSame(['timelimitempty'], $codes($timed(['catquiz_maxtimeperattempt' => 0]), settings_validator::ERROR));
        $findings = $timed(['catquiz_maxtimeperattempt' => 1, 'catquiz_timeselect_attempt' => 'h',
            'catquiz_maxtimeperitem' => 60, 'catquiz_timeselect_item' => 'min']);
        $this->assertSame(['itemtimenotshorter'], $codes($findings, settings_validator::WARNING), 'Hours and minutes compared.');
        $findings = $timed(['catquiz_maxtimeperattempt' => 5, 'catquiz_timeselect_attempt' => 'min',
            'catquiz_maxtimeperitem' => 2, 'catquiz_timeselect_item' => 'min']);
        $this->assertSame([], $codes($findings, settings_validator::ERROR), '10 x 2 min above 5 min is no error.');
        $this->assertContains('timeperquestion', $codes($findings, settings_validator::DIAGNOSTIC));
        $this->assertSame([], $codes($timed(['catquiz_maxtimeperattempt' => 0], 0), settings_validator::ERROR));
    }

    /**
     * A target precision that is reachable gives no warning; one that the best items cannot give within the
     * questions a person can get is a warning, never an error.
     */
    public function test_target_precision(): void {
        $warnings = fn(float $semin, int $max) => array_column(array_filter(
            settings_validator::validate($this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 1, $max, 0, [
                'catquiz_standarderrorgroup' => (object) ['catquiz_standarderror_min' => $semin, 'catquiz_standarderror_max' => 2],
            ])),
            fn($f) => $f['level'] !== settings_validator::DIAGNOSTIC
        ), 'code');

        $this->assertSame([], $warnings(0.5, 20));
        $this->assertSame(['precisionunreachable'], $warnings(0.05, 20));
        $this->assertSame(['precisionunreachable'], $warnings(0.5, 2), 'Two questions are not enough.');
    }

    /**
     * The form refuses the example, on the field of the question numbers; a valid form passes.
     */
    public function test_the_form_uses_the_validator(): void {
        $data = json_decode(json_encode($this->settings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 10, 20, 4)), true);
        $data['catquiz_standarderrorgroup'] = ['catquiz_standarderror_min' => 0.1, 'catquiz_standarderror_max' => 1.0];
        $data['numberoffeedbackoptionsselect'] = 1;
        $errors = catquiz_handler::instance_form_validation($data);
        $this->assertStringContainsString('8', $errors['maxquestionsgroup'] ?? '');

        $data['maxquestionsscalegroup']['catquiz_maxquestionspersubscale'] = 6;
        $this->assertArrayNotHasKey('maxquestionsgroup', catquiz_handler::instance_form_validation($data));
    }

    /**
     * Before the first question: nothing to play keeps the attempt from starting, with the reason; another
     * contradiction is logged and the attempt goes on.
     */
    public function test_the_preflight_before_the_first_question(): void {
        global $DB;
        $preflight = new \ReflectionMethod(catquiz_handler::class, 'preflight');
        $preflight->setAccessible(true);
        $attempt = (object) ['id' => 0, 'questionsattempted' => 0];

        $this->assertNull($preflight->invoke(null, $this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 5, 0, 0), $attempt, 1));

        $sink = $this->redirectEvents();
        $this->assertNull($preflight->invoke(null, $this->settings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 10, 20, 4), $attempt, 7));
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \local_catquiz\event\settings_inconsistent
        ));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame(['minimumunreachable'], $events[0]->other['codes']);

        $DB->set_field('local_catquiz_items', 'status', LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE, []);
        cache::make('local_catquiz', 'runtimepool')->purge();
        $answer = $preflight->invoke(null, $this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 5, 0, 0), $attempt, 1);
        $this->assertSame(0, $answer[0]);
        $this->assertSame(\local_catquiz\local\status::ERROR_NO_ITEMS, $answer[2]);
        $this->assertStringContainsString('K1', $answer[1]);
    }
}
