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

namespace local_catquiz\local\statistics;

use advanced_testcase;
use local_catquiz\catquiz;
use local_catquiz\external\render_statistics_chart;
use local_catquiz\local\access\context_resolver;
use local_catquiz\output\catquizstatistics;
use local_catquiz\shortcodes;
use local_catquiz\teststrategy\feedback_helper;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Issue #175: comma-separated ID lists in the statistics and feedback shortcodes, the chart endpoint and the export.
 *
 * Fixture (golden): two courses A and B with three CAT activities each - A1, A2, B1, B2 on the root scale R1; A3, B3
 * on the root scale R2. Pages before and between the activities make every course module id differ from the
 * instance id. Four people with one to three attempts, end times e1 < ... < e9:
 *
 *   #  person test  scale value valid
 *   1  U1     A1    R1    1.0   yes
 *   2  U2     A2    R1   -1.0   yes
 *   3  U1     B1    R1    2.0   yes
 *   4  U3     B1    R1    0.0   yes
 *   5  U2     A1    R1    0.5   yes
 *   6  U2     B2    R1    1.5   no
 *   7  U4     B2    R1    3.0   yes
 *   8  U4     A3    R2    0.7   yes
 *   9  U1     B3    R2   -0.4   yes
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\local\statistics\id_list
 * @covers     \local_catquiz\shortcodes
 * @covers     \local_catquiz\local\statistics\attempt_population
 * @covers     \local_catquiz\output\catquizstatistics
 * @covers     \local_catquiz\external\render_statistics_chart
 * @covers     \local_catquiz\local\access\context_resolver
 * @covers     \local_catquiz\teststrategy\feedback_helper
 * @covers     \local_catquiz\catquiz
 */
final class shortcode_id_lists_test extends advanced_testcase {
    /** @var int CAT context. */
    private int $contextid;

    /** @var int[] Root scales R1, R2. */
    private array $roots = [];

    /** @var \stdClass[] Courses A, B. */
    private array $courses = [];

    /** @var int[] adaptivequiz.id by key A1..B3. */
    private array $quiz = [];

    /** @var int[] Course module id by key A1..B3. */
    private array $cm = [];

    /** @var int[] Users U1..U4 and teachers by name. */
    private array $users = [];

    /** @var int[] local_catquiz_attempts.id by number 1..9. */
    private array $attempts = [];

    /** @var int[] Host attempt id (adaptivequiz_attempt.id) by number 1..9. */
    private array $hostattempts = [];

    /** @var int[] End times by number 1..9. */
    private array $end = [];

    /**
     * Builds the fixture of the class comment.
     *
     * @param bool $seed With the nine attempts.
     */
    private function build(bool $seed = true): void {
        global $DB;
        $now = time();
        $this->contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Issue 175', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => 0, 'endtimestamp' => $now + 10000, 'timecreated' => $now, 'timemodified' => $now,
            'usermodified' => 0,
        ]);
        foreach (['R1', 'R2'] as $name) {
            $this->roots[$name] = (int) $DB->insert_record('local_catquiz_catscales', (object) [
                'parentid' => 0, 'name' => $name, 'contextid' => $this->contextid,
                'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        \cache_helper::purge_all();

        $generator = $this->getDataGenerator();
        foreach (['A', 'B'] as $key) {
            $this->courses[$key] = $generator->create_course(['fullname' => "Course $key", 'shortname' => "C175$key"]);
        }
        foreach (['A1' => 'R1', 'A2' => 'R1', 'A3' => 'R2', 'B1' => 'R1', 'B2' => 'R1', 'B3' => 'R2'] as $key => $root) {
            // A page before every activity: the course module ids run ahead of the instance ids.
            $generator->create_module('page', ['course' => $this->courses[$key[0]]->id]);
            $this->add_test($key, $this->roots[$root]);
        }
        foreach (['U1', 'U2', 'U3', 'U4'] as $name) {
            $user = $generator->create_user(['firstname' => $name, 'lastname' => 'Person']);
            $this->users[$name] = (int) $user->id;
            foreach ($this->courses as $course) {
                $generator->enrol_user($user->id, $course->id, 'student');
            }
        }
        foreach (['TA' => ['A'], 'TB' => ['B'], 'TAB' => ['A', 'B']] as $name => $in) {
            $teacher = $generator->create_user(['firstname' => $name, 'lastname' => 'Teacher']);
            $this->users[$name] = (int) $teacher->id;
            foreach ($in as $coursekey) {
                $generator->enrol_user($teacher->id, $this->courses[$coursekey]->id, 'editingteacher');
            }
        }
        eligible_population::reset();
        context_resolver::reset_cache();
        set_config(\local_catquiz\local\result\result_provenance::CONFIG, 1, 'local_catquiz');
        if ($seed) {
            $base = make_timestamp(2026, 6, 1, 12, 0, 0);
            $rows = [
                1 => ['U1', 'A1', 'R1', 1.0, true],
                2 => ['U2', 'A2', 'R1', -1.0, true],
                3 => ['U1', 'B1', 'R1', 2.0, true],
                4 => ['U3', 'B1', 'R1', 0.0, true],
                5 => ['U2', 'A1', 'R1', 0.5, true],
                6 => ['U2', 'B2', 'R1', 1.5, false],
                7 => ['U4', 'B2', 'R1', 3.0, true],
                8 => ['U4', 'A3', 'R2', 0.7, true],
                9 => ['U1', 'B3', 'R2', -0.4, true],
            ];
            foreach ($rows as $number => [$person, $test, $root, $ability, $valid]) {
                $this->end[$number] = $base + $number * HOURSECS;
                $this->attempt($number, $person, $test, $this->roots[$root], $ability, $valid);
            }
        }
    }

    /**
     * One CAT activity with its settings.
     *
     * @param string $key A1..B3
     * @param int $root
     */
    private function add_test(string $key, int $root): void {
        global $DB;
        $course = $this->courses[$key[0]];
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'name' => "Test $key", 'highestlevel' => 10, 'lowestlevel' => 1,
            'standarderror' => 14, 'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $this->quiz[$key] = (int) $quiz->id;
        $this->cm[$key] = (int) $quiz->cmid;
        $settings = [
            'catquiz_catscales' => $root, 'catquiz_selectteststrategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC,
            'numberoffeedbackoptionsselect' => 2, 'name' => "Test $key",
        ];
        foreach ([[-5, 0], [0, 5]] as $i => [$lower, $upper]) {
            $j = $i + 1;
            $settings["feedback_scaleid_limit_lower_{$root}_{$j}"] = $lower;
            $settings["feedback_scaleid_limit_upper_{$root}_{$j}"] = $upper;
            $settings["wb_colourpicker_{$root}_{$j}"] = $j === 1 ? '3' : '6';
            $settings["feedbacklegend_scaleid_{$root}_{$j}"] = "Range $j";
        }
        $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => $quiz->id, 'component' => 'mod_adaptivequiz', 'catscaleid' => $root,
            'courseid' => $course->id, 'name' => "Test $key", 'status' => 1, 'json' => json_encode($settings),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * A completed attempt as the finalisation leaves it.
     *
     * @param int $number
     * @param string $person
     * @param string $test
     * @param int $root
     * @param float $ability
     * @param bool $valid
     */
    private function attempt(int $number, string $person, string $test, int $root, float $ability, bool $valid): void {
        global $DB;
        $userid = $this->users[$person];
        $endtime = $this->end[$number];
        // The attempt of the activity, as the host keeps it.
        $this->hostattempts[$number] = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->quiz[$test], 'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 10, 'difficultysum' => 0, 'standarderror' => 0.3,
            'measure' => $ability, 'timecreated' => $endtime - 600, 'timemodified' => $endtime,
        ]);
        $name = array_search($root, $this->roots, true);
        $json = [
            'attemptid' => $this->hostattempts[$number], 'contextid' => $this->contextid,
            'courseid' => (int) $this->courses[$test[0]]->id, 'userid' => $userid, 'component' => 'mod_adaptivequiz',
            'testid' => $this->quiz[$test], 'starttime' => $endtime - 600, 'endtime' => $endtime,
            'personabilities' => [$root => $ability], 'se' => [$root => 0.3], 'nbyscale' => [$root => 10],
            'personabilities_abilities' => [$root => ['value' => $ability, 'primary' => true, 'toreport' => true]],
            'customscalefeedback_abilities' => [
                $root => ['value' => $ability, 'toreport' => true, 'primary' => true, 'name' => $name],
            ],
            'catscales' => [$root => ['id' => $root, 'name' => $name, 'parentid' => 0, 'contextid' => $this->contextid]],
            'primaryscale' => ['id' => $root, 'name' => $name],
            'catscaleid' => $root,
            'teststrategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC,
            'studentfeedback' => [], 'teacherfeedback' => [],
        ];
        $this->attempts[$number] = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $userid, 'scaleid' => $root, 'contextid' => $this->contextid,
            'courseid' => $this->courses[$test[0]]->id, 'attemptid' => $this->hostattempts[$number],
            'component' => 'mod_adaptivequiz', 'instanceid' => $this->quiz[$test],
            'teststrategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC, 'status' => LOCAL_CATQUIZ_ATTEMPT_OK,
            'starttime' => $endtime - 600, 'endtime' => $endtime, 'personability_after_attempt' => $ability,
            'json' => json_encode($json), 'debug_info' => '', 'timecreated' => $endtime, 'timemodified' => $endtime,
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'userid' => $userid, 'catscaleid' => $root, 'contextid' => $this->contextid,
            'attemptid' => $this->attempts[$number], 'ability' => $ability, 'isvalid' => $valid ? 1 : 0,
            'resultsource' => 'current', 'standarderror' => 0.3, 'n' => 10, 'isprimary' => 1, 'status' => 0,
            'timecreated' => $endtime, 'timemodified' => $endtime,
        ]);
    }

    /**
     * The numbers (1..9) of the attempts of a population, in its order.
     *
     * @param attempt_population $population
     * @return int[]
     */
    private function numbers(attempt_population $population): array {
        $byid = array_flip($this->attempts);
        return array_map(fn($a) => $byid[(int) $a->id], $population->attempts());
    }

    /**
     * IDs of a list of keys.
     *
     * @param array $map
     * @param string[] $keys
     * @return int[]
     */
    private static function ids(array $map, array $keys): array {
        return array_map(fn($key) => (int) $map[$key], $keys);
    }

    /**
     * populate_arguments() of the shortcode.
     *
     * @param array $args
     * @return array
     */
    private static function blocks(array $args): array {
        $method = new \ReflectionMethod(shortcodes::class, 'populate_arguments');
        $method->setAccessible(true);
        return $method->invoke(null, $args);
    }

    /**
     * The lists the parser accepts, and what they read as (DoD1).
     *
     * @return array
     */
    public static function valid_lists(): array {
        return [
            'one ID' => ['12', [12]],
            'one ID as int' => [12, [12]],
            'two IDs' => ['12,13', [12, 13]],
            'three IDs, order kept' => ['13,12,1865', [13, 12, 1865]],
            'spaces around' => [' 12 , 13 ,14 ', [12, 13, 14]],
            'tab and non-breaking space' => ["12,\t13,\u{00A0}14", [12, 13, 14]],
            'duplicates once' => ['12,13,12,13', [12, 13]],
            'array' => [[12, '13', 12], [12, 13]],
            'not given' => [null, []],
            'empty' => ['', []],
            'zero alone (no list, as before)' => ['0', []],
            'zero as int' => [0, []],
            'eighteen digits' => ['999999999999999999', [999999999999999999]],
            'leading zero' => ['012', [12]],
        ];
    }

    /**
     * Every valid list reads as its set, in the order of first appearance.
     *
     * @dataProvider valid_lists
     * @param mixed $raw
     * @param int[] $expected
     */
    public function test_valid_lists(mixed $raw, array $expected): void {
        $this->assertSame($expected, id_list::parse($raw, 'testid'));
    }

    /**
     * The lists the parser refuses, and the entry it names (DoD1, AK3: nothing is cut to a number).
     *
     * @return array
     */
    public static function invalid_lists(): array {
        return [
            'letters after digits' => ['12a', '12a'],
            'negative' => ['-3', '-3'],
            'plus sign' => ['+5', '+5'],
            'decimal' => ['1.5', '1.5'],
            'hexadecimal' => ['0x10', '0x10'],
            'exponent' => ['1e3', '1e3'],
            'empty entry' => ['12,,13', ''],
            'trailing comma' => ['12,', ''],
            'leading comma' => [',12', ''],
            'zero in a list' => ['0,5', '0'],
            'semicolon' => ['12;13', '12;13'],
            'injection' => ['1) OR (1=1', '1) OR (1=1'],
            'nineteen digits' => ['9999999999999999999', '9999999999999999999'],
            'space inside' => ['12 13', '12 13'],
            'array with text' => [[12, 'x'], 'x'],
            'float' => [1.5, '1.5'],
            'argument without a value' => [true, ''],
        ];
    }

    /**
     * An invalid entry is refused with the argument and the entry named.
     *
     * @dataProvider invalid_lists
     * @param mixed $raw
     * @param string $token
     */
    public function test_invalid_lists(mixed $raw, string $token): void {
        try {
            id_list::parse($raw, 'courseid');
            $this->fail('Refused: ' . json_encode($raw));
        } catch (\moodle_exception $e) {
            $this->assertSame('shortcodeidlistinvalid', $e->errorcode);
            $this->assertStringContainsString('courseid', $e->getMessage());
            $this->assertStringContainsString($token, $e->getMessage());
        }
    }

    /**
     * The limit: 100 IDs pass, 101 do not; repeated IDs count once (DoD5).
     */
    public function test_the_limit_of_a_list(): void {
        $hundred = implode(',', range(1, 100));
        $this->assertCount(100, id_list::parse($hundred, 'testid'));
        $this->assertCount(100, id_list::parse($hundred . ',1,2,3', 'testid'), 'Repeated IDs count once.');
        try {
            id_list::parse($hundred . ',101', 'testid');
            $this->fail('101 IDs.');
        } catch (\moodle_exception $e) {
            $this->assertSame('shortcodeidlisttoolong', $e->errorcode);
        }
    }

    /**
     * course and courseid: the same set in any order is no conflict; different sets are (DoD1: alias conflicts).
     */
    public function test_aliases(): void {
        $this->assertSame([3, 4], id_list::parse_alias(['courseid' => '3,4', 'course' => '4,3'], ['courseid', 'course']));
        $this->assertSame([4], id_list::parse_alias(['course' => '4'], ['courseid', 'course']));
        $this->assertSame([], id_list::parse_alias([], ['courseid', 'course']));
        $this->expectException(\moodle_exception::class);
        id_list::parse_alias(['courseid' => '3', 'course' => '3,4'], ['courseid', 'course']);
    }

    /**
     * testid stays the course module id: a list of cmids gives the instances, one block per root scale (AK1, AK5).
     */
    public function test_testid_is_the_course_module_id(): void {
        $this->resetAfterTest();
        $this->build(false);
        foreach ($this->cm as $key => $cmid) {
            $this->assertNotSame($this->quiz[$key], $cmid, "$key: course module id and instance id differ");
        }

        $blocks = self::blocks(['testid' => implode(',', self::ids($this->cm, ['A1', 'B3', 'B1']))]);
        $this->assertCount(2, $blocks, 'Two root scales: two blocks, never mixed.');
        $this->assertSame($this->roots['R1'], $blocks[0]['scaleid']);
        $this->assertSame(self::ids($this->quiz, ['A1', 'B1']), $blocks[0]['testids']);
        $this->assertSame(self::ids($this->cm, ['A1', 'B1']), $blocks[0]['cmids']);
        $this->assertSame([(int) $this->courses['A']->id, (int) $this->courses['B']->id], $blocks[0]['courseids']);
        $this->assertSame($this->roots['R2'], $blocks[1]['scaleid']);
        $this->assertSame(self::ids($this->quiz, ['B3']), $blocks[1]['testids']);
        $this->assertSame([(int) $this->courses['B']->id], $blocks[1]['courseids']);

        // One cmid: as before - the test, its course, its scale.
        $one = self::blocks(['testid' => (string) $this->cm['A2']]);
        $this->assertSame([[
            'scaleid' => $this->roots['R1'],
            'courseids' => [(int) $this->courses['A']->id],
            'testids' => [$this->quiz['A2']],
            'cmids' => [$this->cm['A2']],
        ]], $one);

        // An instance id given as testid is read as a course module id: the course module of that number - a page,
        // another test, or nothing - never the test whose instance it is.
        foreach ($this->quiz as $key => $instance) {
            try {
                $taken = self::blocks(['testid' => (string) $instance])[0]['testids'];
            } catch (\Exception $e) {
                $this->assertSame(get_string('catquizstatistics_testid_unknown', 'local_catquiz', $instance), $e->getMessage());
                $taken = null;
            }
            $this->assertNotSame([$instance], $taken, "$key: the instance id is read as a course module id.");
        }
    }

    /**
     * Arguments that contradict each other or name nothing are refused, by name (DoD1, AK3).
     */
    public function test_contradicting_and_unknown_arguments(): void {
        global $DB, $COURSE, $PAGE;
        $this->resetAfterTest();
        $this->build(false);
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $page = (int) $DB->get_field_sql(
            "SELECT MIN(cm.id) FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE m.name = 'page'"
        );
        $cases = [
            'test outside the course' => [
                ['testid' => $this->cm['A1'], 'courseid' => $b],
                'catquizstatistics_scale_course_conflict',
            ],
            'one of the tests outside the courses' => [
                ['testid' => "{$this->cm['A1']},{$this->cm['B1']}", 'courseid' => (string) $a],
                'catquizstatistics_scale_course_conflict',
            ],
            'scale of another test' => [['testid' => $this->cm['A1'], 'globalscale' => $this->roots['R2']],
                'catquizstatistics_scale_testid_conflict'],
            'a test without its scale named' => [
                ['testid' => "{$this->cm['A1']},{$this->cm['A3']}", 'globalscale' => (string) $this->roots['R1']],
                'catquizstatistics_scale_testid_conflict',
            ],
            'unknown course module' => [['testid' => '99999999'], 'catquizstatistics_testid_unknown'],
            'another module' => [['testid' => (string) $page], 'catquizstatistics_testid_unknown'],
        ];
        foreach ($cases as $name => [$args, $string]) {
            try {
                self::blocks($args);
                $this->fail("$name: refused.");
            } catch (\Exception $e) {
                $param = $string === 'catquizstatistics_testid_unknown' ? (explode(',', (string) $args['testid'])[0])
                    : ($string === 'catquizstatistics_unknownscale' ? '99999999' : null);
                $this->assertSame(get_string($string, 'local_catquiz', $param), $e->getMessage(), $name);
            }
        }
        $invalid = [['testid' => '12a'], ['courseid' => '1,,2'], ['globalscale' => '-1'], ['course' => '3', 'courseid' => '4']];
        foreach ($invalid as $args) {
            try {
                self::blocks($args);
                $this->fail('Refused: ' . json_encode($args));
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['shortcodeidlistinvalid', 'shortcodealiasconflict']);
            }
        }

        // An unknown scale: named to those who may see the statistics, nothing to the others.
        $PAGE->set_url('/');
        $COURSE = get_course($a);
        $this->setUser($this->users['TA']);
        $this->assertStringContainsString(
            s(get_string('catquizstatistics_unknownscale', 'local_catquiz', 99999999)),
            shortcodes::catquizstatistics('catquizstatistics', ['globalscale' => '99999999', 'courseid' => $a], null, null, null)
        );
        $this->setUser($this->users['U1']);
        $this->assertSame(
            '',
            shortcodes::catquizstatistics('catquizstatistics', ['globalscale' => '99999999', 'courseid' => $a], null, null, null)
        );

        // The scales named, in the order named; every test covered.
        $blocks = self::blocks([
            'testid' => "{$this->cm['A1']},{$this->cm['A3']}",
            'globalscale' => "{$this->roots['R2']},{$this->roots['R1']}",
        ]);
        $this->assertSame([$this->roots['R2'], $this->roots['R1']], array_column($blocks, 'scaleid'));
    }

    /**
     * Without tests: the courses of the list, one block per root scale of their tests; scope="all" drops the courses.
     */
    public function test_courses_without_tests(): void {
        $this->resetAfterTest();
        $this->build(false);
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $blocks = self::blocks(['courseid' => "$b,$a"]);
        $this->assertSame([$this->roots['R1'], $this->roots['R2']], array_column($blocks, 'scaleid'));
        $this->assertSame([[$a, $b], [$a, $b]], array_column($blocks, 'courseids'));
        $this->assertSame([null, null], array_column($blocks, 'testids'));
        $this->assertSame(
            [[$a, $b]],
            array_column(self::blocks(['course' => "$a,$b", 'globalscale' => $this->roots['R2']]), 'courseids')
        );
        $this->assertSame([null], array_column(self::blocks(['courseid' => "$a,$b", 'globalscale' => $this->roots['R1'],
            'scope' => 'all']), 'courseids'));
    }

    /**
     * Golden: the populations of sets - OR within a set, AND between sets, first and last over the set (DoD2, AK4, AK5).
     */
    public function test_golden_populations(): void {
        $this->resetAfterTest();
        $this->build();
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $r1 = $this->roots['R1'];
        $ab = [$a, $b];
        $tests = self::ids($this->quiz, ['A1', 'B1']);
        $cases = [
            'tests A1,B1, all' => [null, $tests, attempt_population::ALL, null, null, [1, 3, 4, 5]],
            'tests A1,B1, first over the set' => [null, $tests, attempt_population::FIRST, null, null, [1, 4, 5]],
            'tests A1,B1, last over the set' => [null, $tests, attempt_population::LAST, null, null, [3, 4, 5]],
            'courses A,B, all' => [$ab, null, attempt_population::ALL, null, null, [1, 2, 3, 4, 5, 6, 7]],
            'courses A,B, first' => [$ab, null, attempt_population::FIRST, null, null, [1, 2, 4, 7]],
            'courses A,B, last' => [$ab, null, attempt_population::LAST, null, null, [3, 4, 6, 7]],
            'courses A,B and all their tests: nothing twice' => [$ab, self::ids($this->quiz, ['A1', 'A2', 'B1', 'B2']),
                attempt_population::ALL, null, null, [1, 2, 3, 4, 5, 6, 7]],
            'course A and tests A1,B1: the intersection' => [[$a], $tests, attempt_population::ALL, null, null, [1, 5]],
            'courses A,B, period e2..e5' => [$ab, null, attempt_population::ALL, 2, 5, [2, 3, 4, 5]],
            'courses A,B, first, then the period' => [$ab, null, attempt_population::FIRST, 2, 5, [2, 4]],
            'course B alone' => [[$b], null, attempt_population::ALL, null, null, [3, 4, 6, 7]],
        ];
        foreach ($cases as $name => [$courses, $testids, $selection, $from, $to, $expected]) {
            $population = new attempt_population(
                $r1,
                $courses,
                $testids,
                null,
                $from ? $this->end[$from] : null,
                $to ? $this->end[$to] : null,
                $selection
            );
            $this->assertSame($expected, $this->numbers($population), $name);
        }
        // The set is the union of its parts: test by test, the same attempts - none twice, none lost.
        $parts = [];
        foreach (['A1', 'A2', 'B1', 'B2'] as $key) {
            $parts = array_merge($parts, $this->numbers(new attempt_population($r1, null, $this->quiz[$key])));
        }
        sort($parts);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $parts);
        // R2 apart: the attempts of the other root scale never join R1.
        $this->assertSame([8, 9], $this->numbers(new attempt_population($this->roots['R2'], $ab)));
    }

    /**
     * Golden: the charts of a set and the CSV export count exactly the population (DoD2, AK5, AK8).
     */
    public function test_golden_charts_and_export(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $r1 = $this->roots['R1'];
        $to = $this->end[9] + 1;

        $all = new catquizstatistics([$a, $b], null, $r1, $to);
        $profile = $all->get_chart_data(catquizstatistics::CHART_ABILITYPROFILE)['population'];
        $this->assertSame(7, $profile['attempts']);
        $this->assertSame(4, $profile['persons']);
        $this->assertEquals(
            [$this->users['U1'] => 2.0, $this->users['U2'] => 0.5, $this->users['U3'] => 0.0, $this->users['U4'] => 3.0],
            $profile['values']
        );
        $perperson = $all->get_chart_data(catquizstatistics::CHART_ATTEMPTSPERPERSON)['population'];
        $this->assertSame(4, $perperson['persons']);
        $this->assertSame(7, $perperson['attempts']);

        $first = new catquizstatistics([$a, $b], null, $r1, $to, null, attempt_population::FIRST);
        $this->assertSame(4, $first->get_chart_data(catquizstatistics::CHART_ABILITYPROFILE)['population']['attempts']);
        $this->assertSame(
            self::ids($this->hostattempts, [7, 4, 2, 1]),
            array_map(fn($r) => (int) $r->attemptid, $first->get_export_data()),
            'The export: the attempts of the charts, first attempts only.'
        );
        $tests = new catquizstatistics(null, self::ids($this->quiz, ['A1', 'B1']), $r1, $to);
        $this->assertSame(
            self::ids($this->hostattempts, [5, 4, 3, 1]),
            array_map(fn($r) => (int) $r->attemptid, $tests->get_export_data())
        );
        // The export link carries the scope: lists and the selection.
        $href = html_entity_decode((string) preg_replace('/.*href="([^"]+)".*/s', '$1', $first->render_export_button()));
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame("$a,$b", $query['courseid']);
        $this->assertSame('first', $query['attempts']);
        $this->assertSame((string) $r1, $query['scaleid']);
        $href = html_entity_decode((string) preg_replace('/.*href="([^"]+)".*/s', '$1', $tests->render_export_button()));
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame(implode(',', self::ids($this->quiz, ['A1', 'B1'])), $query['testid']);

        // The request of the export reads what the link wrote - and refuses what is no list.
        $_GET = ['cid' => (string) $a, 'courseid' => "$a,$b", 'attempts' => 'first'];
        $this->assertSame([[$a, $b], null, null, null, 'first'], catquizstatistics::export_scope_from_request());
        $_GET = ['cid' => (string) $b];
        $this->assertSame([[$b], null, null, null, 'all'], catquizstatistics::export_scope_from_request());
        foreach ([['courseid' => '1a'], ['testid' => '2,,3'], ['attempts' => 'best']] as $bad) {
            $_GET = ['cid' => (string) $a] + $bad;
            try {
                catquizstatistics::export_scope_from_request();
                $this->fail('Refused: ' . json_encode($bad));
            } catch (\moodle_exception $e) {
                $this->addToAssertionCount(1);
            }
        }
        $_GET = [];
    }

    /**
     * Several tests whose settings lack a legend: the charts of the set are built, the settings compared without a
     * warning (issue #175: before, the comparison of several tests read settings that were not there and the chart was
     * not built).
     */
    public function test_charts_of_tests_without_a_legend(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        foreach ($DB->get_records('local_catquiz_tests') as $test) {
            $settings = json_decode($test->json, true);
            foreach (array_keys($settings) as $key) {
                if (str_starts_with($key, 'feedbacklegend_')) {
                    unset($settings[$key]);
                }
            }
            $DB->set_field('local_catquiz_tests', 'json', json_encode($settings), ['id' => $test->id]);
        }
        $statistics = new catquizstatistics(
            [(int) $this->courses['A']->id, (int) $this->courses['B']->id],
            null,
            $this->roots['R1'],
            $this->end[9] + 1
        );
        // A warning is an error here, as in the browser with debugging on.
        set_error_handler(function (int $errno, string $message): bool {
            throw new \ErrorException($message, 0, $errno);
        }, E_WARNING | E_NOTICE);
        try {
            foreach (array_keys(catquizstatistics::CHARTS) as $chart) {
                $data = shortcodes::render_statistics_chart($statistics, $chart);
                $this->assertStringNotContainsString(
                    get_string('statisticschartloadfailed', 'local_catquiz'),
                    (string) $data['chart'],
                    $chart
                );
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The export lists people by name: in separate groups, the rows of a course are those of the viewer's groups
     * there - per course of the set, not one rule for all (AK7).
     */
    public function test_export_applies_the_groups_of_each_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build();
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $b]);
        $DB->set_field('course', 'groupmodeforce', 1, ['id' => $b]);
        rebuild_course_cache($b, true);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a, 'teacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $b, 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $b]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->users['U1']]);
        $this->setUser($teacher);

        $statistics = new catquizstatistics([$a, $b], null, $this->roots['R1'], $this->end[9] + 1);
        // Course A: every row (1, 2, 5); course B: U1's row only (3).
        $this->assertSame(
            self::ids($this->hostattempts, [5, 3, 2, 1]),
            array_map(fn($r) => (int) $r->attemptid, $statistics->get_export_data())
        );
    }

    /**
     * Rights fail closed: one test or course of the set without the right refuses the shortcode, the chart endpoint
     * and the export - no data of the other parts either (DoD3, AK7).
     */
    public function test_rights_fail_closed(): void {
        global $COURSE, $PAGE;
        $this->resetAfterTest();
        $this->build();
        $PAGE->set_url('/');
        $COURSE = get_course($this->courses['A']->id);
        set_config('lazyfeedbacktabs', 1, 'local_catquiz');
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $r1 = $this->roots['R1'];
        $both = ['testid' => "{$this->cm['A1']},{$this->cm['B1']}"];

        $this->setUser($this->users['TA']);
        $this->assertSame('', shortcodes::catquizstatistics('catquizstatistics', $both, null, null, null));
        $this->assertSame('', shortcodes::catquizstatistics('catquizstatistics', ['courseid' => "$a,$b"], null, null, null));
        $one = ['testid' => $this->cm['A1']];
        $this->assertNotSame('', shortcodes::catquizstatistics('catquizstatistics', $one, null, null, null));
        $this->assertFalse((new catquizstatistics([$a, $b], null, $r1))->can_view_other_users());
        $this->assertSame([], (new catquizstatistics([$a, $b], null, $r1))->get_export_data());
        $scope = ['scaleid' => $r1, 'testids' => self::ids($this->quiz, ['A1', 'B1'])];
        try {
            render_statistics_chart::execute($scope, catquizstatistics::CHART_ABILITYPROFILE);
            $this->fail('A test of course B.');
        } catch (\required_capability_exception | \require_login_exception $e) {
            $this->addToAssertionCount(1);
        }
        try {
            render_statistics_chart::execute(['scaleid' => $r1, 'courseids' => [$a, $b]], catquizstatistics::CHART_ABILITYPROFILE);
            $this->fail('Course B.');
        } catch (\required_capability_exception | \require_login_exception $e) {
            $this->addToAssertionCount(1);
        }

        // A student: nothing, not even of their own courses.
        $this->setUser($this->users['U1']);
        $this->assertSame('', shortcodes::catquizstatistics('catquizstatistics', $both, null, null, null));

        // A teacher of both: both.
        $this->setUser($this->users['TAB']);
        $html = shortcodes::catquizstatistics('catquizstatistics', $both, null, null, null);
        $this->assertStringContainsString('data-region="local_catquiz-statistics"', $html);
        $result = render_statistics_chart::execute($scope, catquizstatistics::CHART_ABILITYPROFILE);
        $this->assertStringContainsString('data-chartname="abilityprofile"', $result['html']);
    }

    /**
     * The chart endpoint takes the scope from the browser: tests of another scale, tests outside the courses and
     * unknown tests are refused (DoD3, AK7).
     */
    public function test_the_endpoint_checks_the_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $a = (int) $this->courses['A']->id;
        $r1 = $this->roots['R1'];
        $cases = [
            'a test of another root scale' => [['scaleid' => $r1, 'testids' => self::ids($this->quiz, ['A1', 'A3'])], 'scaleid'],
            'a test outside the courses' => [['scaleid' => $r1, 'courseids' => [$a],
                'testids' => self::ids($this->quiz, ['A1', 'B1'])], 'courseid'],
            'an unknown test' => [['scaleid' => $r1, 'testids' => [$this->quiz['A1'], 99999999]], 'testid'],
            'too many' => [['scaleid' => $r1, 'courseids' => range(1, 101)], ''],
        ];
        foreach ($cases as $name => [$scope, $param]) {
            try {
                render_statistics_chart::execute($scope, catquizstatistics::CHART_ABILITYPROFILE);
                $this->fail($name);
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString($param, $e->getMessage(), $name);
            }
        }
    }

    /**
     * The page, the charts loaded later and a second shortcode on the same page: the same scope, carried as lists;
     * every block has its own ids (AK8).
     */
    public function test_lazy_charts_carry_the_scope(): void {
        global $COURSE, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build();
        $PAGE->set_url('/');
        $COURSE = get_course($this->courses['A']->id);
        set_config('lazyfeedbacktabs', 1, 'local_catquiz');
        $args = ['testid' => "{$this->cm['A1']}, {$this->cm['B1']},{$this->cm['B3']}", 'attempts' => 'last'];

        $html = shortcodes::catquizstatistics('catquizstatistics', $args, null, null, null)
            . shortcodes::catquizstatistics('catquizstatistics', $args, null, null, null);
        preg_match_all('/data-scope="([^"]+)"/', $html, $matches);
        $scopes = array_values(array_unique(array_map(fn($s) => html_entity_decode($s), $matches[1])));
        $this->assertCount(2, $scopes, 'Two root scales: two scopes, the same for both shortcodes.');
        $r1scope = json_decode($scopes[0], true);
        $this->assertSame(self::ids($this->quiz, ['A1', 'B1']), $r1scope['testids']);
        $this->assertSame('last', $r1scope['attempts']);
        $this->assertSame($this->roots['R1'], $r1scope['scaleid']);
        $this->assertSame(self::ids($this->quiz, ['B3']), json_decode($scopes[1], true)['testids']);
        // Unique ids: every tab pane id once.
        preg_match_all('/id="(local-catquiz-statistics-[^"]+-overview)"/', $html, $ids);
        $this->assertCount(4, $ids[1]);
        $this->assertCount(4, array_unique($ids[1]));

        // The endpoint builds the chart of the scope it is given - the population of the page.
        $result = render_statistics_chart::execute($r1scope, catquizstatistics::CHART_ABILITYPROFILE);
        $this->assertStringContainsString('data-chartname="abilityprofile"', $result['html']);
        $direct = new catquizstatistics(null, $r1scope['testids'], $r1scope['scaleid'], null, null, 'last');
        $this->assertSame(3, $direct->get_chart_data(catquizstatistics::CHART_ABILITYPROFILE)['population']['attempts']);
    }

    /**
     * The feedback shortcode: lists of courses, tests (adaptivequiz.id) and attempts; numberofattempts does not cut a
     * list of attempts; others only where the viewer may see them (AK6, AK7).
     */
    public function test_feedback_lists(): void {
        global $DB, $CFG, $COURSE;
        $this->resetAfterTest();
        $this->build();
        set_config('lazyfeedbacktabs', 1, 'local_catquiz');
        $context = \context_course::instance(SITEID);
        $COURSE = get_course(SITEID);
        $a = (int) $this->courses['A']->id;
        $b = (int) $this->courses['B']->id;
        $listed = function (array $args) use ($context, &$COURSE, $DB, $CFG): array {
            global $USER;
            $output = feedback_helper::get_feedback_data($args, $context, $USER, $COURSE, $DB, $CFG);
            if (isset($output['error'])) {
                return ['error' => $output['error']];
            }
            $byhost = array_flip($this->hostattempts);
            $numbers = array_map(fn($entry) => $byhost[(int) $entry['attemptid']], $output['attempt']);
            sort($numbers);
            return $numbers;
        };

        $this->setAdminUser();
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $listed(['courseid' => "$a,$b", 'numberofattempts' => 0]));
        $this->assertSame([1, 3, 4, 5], $listed([
            'instanceid' => implode(',', self::ids($this->quiz, ['A1', 'B1'])), 'numberofattempts' => 0,
        ]));
        $this->assertSame([1, 5], $listed([
            'courseid' => (string) $a, 'instanceid' => implode(',', self::ids($this->quiz, ['A1', 'B1'])),
            'numberofattempts' => 0,
        ]));
        // Three attempts asked for by id: all three, whatever numberofattempts says.
        $this->assertSame([2, 4, 9], $listed([
            'attemptid' => implode(',', self::ids($this->hostattempts, [9, 2, 4])), 'numberofattempts' => 1,
        ]));
        // The number of attempts still cuts a list of courses: the newest one.
        $this->assertSame([9], $listed(['courseid' => "$a,$b", 'numberofattempts' => 1]));
        $error = $listed(['instanceid' => '1,x']);
        $this->assertArrayHasKey('error', $error);
        $this->assertStringContainsString('instanceid', $error['error']);

        // A student: their own attempts only, in every course of the list.
        $this->setUser($this->users['U2']);
        $this->assertSame([2, 5, 6], $listed(['courseid' => "$a,$b", 'numberofattempts' => 0]));
        // A teacher of course A: everybody in A, nobody in B (no attempt of their own).
        $this->setUser($this->users['TA']);
        $this->assertSame([1, 2, 5, 8], $listed(['courseid' => "$a,$b", 'numberofattempts' => 0]));
        $this->assertSame([1, 5], $listed([
            'instanceid' => implode(',', self::ids($this->quiz, ['A1', 'B1'])), 'numberofattempts' => 0,
        ]));
        $this->assertSame([2], $listed([
            'courseid' => "$a,$b", 'attemptid' => implode(',', self::ids($this->hostattempts, [2, 3, 4])),
            'numberofattempts' => 0,
        ]), 'An attempt of course B is not listed for a teacher of course A, even by its id.');
        $this->assertSame(['error' => get_string('attemptfeedbacknotyetavailable', 'local_catquiz')], $listed([
            'attemptid' => implode(',', self::ids($this->hostattempts, [2, 3, 4])), 'numberofattempts' => 0,
        ]), 'Without a course: the page of the site - their own attempts only, and they have none.');
    }

    /**
     * Performance and security with 1, 3, 25 and 100 tests: the same number of queries whatever the number of IDs,
     * the IDs as parameters, never in the SQL (DoD5, AK9).
     */
    public function test_matrix_of_list_sizes(): void {
        global $DB, $COURSE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->build(false);
        $course = $this->courses['A'];
        $COURSE = $course;
        $r1 = $this->roots['R1'];
        $keys = [];
        // M1..M100 in course A, all on R1.
        $this->courses['M'] = $course;
        for ($i = 1; $i <= 100; $i++) {
            $this->add_test("M$i", $r1);
            $keys[] = "M$i";
        }
        set_config('lazyfeedbacktabs', 1, 'local_catquiz');
        $queries = [];
        foreach ([1, 3, 25, 100] as $n) {
            $chosen = array_slice($keys, 0, $n);
            $cmids = implode(',', self::ids($this->cm, $chosen));
            $instances = self::ids($this->quiz, $chosen);
            context_resolver::reset_cache();
            \accesslib_clear_all_caches_for_unit_testing();

            $before = $DB->perf_get_queries();
            $blocks = self::blocks(['testid' => $cmids]);
            $queries['arguments'][$n] = $DB->perf_get_queries() - $before;
            $this->assertSame($instances, $blocks[0]['testids']);

            \accesslib_clear_all_caches_for_unit_testing();
            $before = $DB->perf_get_queries();
            context_resolver::for_statistics_scope([(int) $course->id], $instances);
            $queries['contexts'][$n] = $DB->perf_get_queries() - $before;

            $population = new attempt_population($r1, [(int) $course->id], $instances);
            $before = $DB->perf_get_queries();
            $population->attempts();
            $queries['population'][$n] = $DB->perf_get_queries() - $before;
            [$sql, $params] = $population->where('a', 'mx');
            foreach ($instances as $id) {
                $this->assertContains($id, $params, 'Every ID is a parameter.');
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b(' . implode('|', $instances) . ')\b/',
                preg_replace('/:[a-z0-9_]+/', ':p', $sql),
                'No ID is written into the SQL.'
            );

            $before = $DB->perf_get_queries();
            feedback_helper::get_feedback_data(
                ['instanceid' => implode(',', $instances), 'numberofattempts' => 0],
                \context_course::instance($course->id),
                $GLOBALS['USER'],
                $course,
                $DB,
                $GLOBALS['CFG']
            );
            $queries['feedback'][$n] = $DB->perf_get_queries() - $before;
        }
        foreach ($queries as $what => $byn) {
            $this->assertSame($byn[1], $byn[100], "$what: no query per ID - " . json_encode($byn));
            $this->assertSame($byn[1], $byn[25], "$what: " . json_encode($byn));
        }
    }
}
