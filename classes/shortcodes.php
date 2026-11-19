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
 * Shortcodes for local_catquiz
 *
 * @package local_catquiz
 * @subpackage db
 * @since Moodle 3.11
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use Exception;
use context_course;
use dml_missing_record_exception;
use local_catquiz\data\dataapi;
use local_catquiz\local\access\context_resolver;
use local_catquiz\local\access\feedback_access;
use local_catquiz\local\statistics\attempt_population;
use local_catquiz\local\statistics\id_list;
use local_catquiz\output\catquizstatistics;
use local_catquiz\output\catscalemanager\quizattempts\quizattemptsdisplay;
use local_catquiz\teststrategy\feedback_helper;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Deals with local_shortcodes regarding catquiz.
 */
class shortcodes {
    /**
     * Prints out list of catquiz attempts.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function allquizattempts($shortcode, $args, $content, $env, $next) {
        // The attempts of the whole site, with names and abilities: for CAT managers only, as in the CAT manager.
        if (!has_capability('local/catquiz:manage_catscales', \context_system::instance())) {
            return '';
        }
        return (new quizattemptsdisplay())->render_table();
    }

    /**
     * Displays feedback of attempts
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function catquizfeedback($shortcode, $args, $content, $env, $next) {
        global $OUTPUT, $COURSE, $USER, $DB, $CFG;

        $context = context_course::instance($COURSE->id);
        $output = feedback_helper::get_feedback_data($args, $context, $USER, $COURSE, $DB, $CFG);

        if (isset($output['error'])) {
            return $output['error'];
        }

        return $OUTPUT->render_from_template('local_catquiz/feedback/collapsablefeedback', $output);
    }

    /**
     * Prints out list of catquiz attempts.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function catscalesoverview($shortcode, $args, $content, $env, $next) {
        global $OUTPUT;

        $catscaleandchildren = dataapi::get_catscale_and_children(0, true, [], true);
        foreach ($catscaleandchildren as $index => $catscale) {
            if ($catscale['depth'] > 0) {
                $catscaleandchildren[$index]['padding'] = $catscale['depth'] * 30;
                $catscaleandchildren[$index]['ischild'] = 1;
            }
        }
        $data = [
            'itemtree' => $catscaleandchildren,
        ];

        return $OUTPUT->render_from_template('local_catquiz/catscaleshortcodes/catscaleshortcodetable', $data);
    }

    /**
     * Prints catquiz statistics.
     *
     * Issue #175: testid (course module ids), courseid/course and globalscale take one ID or a comma-separated
     * list. Within an argument the IDs are alternatives, between arguments they narrow the scope. Root scales are
     * never mixed: every root scale of the scope gets its own block with its own charts and export.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function catquizstatistics($shortcode, $args, $content, $env, $next) {
        global $DB, $OUTPUT;

        $args = is_array($args) ? $args : [];
        try {
            $groups = self::populate_arguments($args);
            $selection = self::attempt_selection($args);
        } catch (\Exception $e) {
            return $OUTPUT->render_from_template(
                'local_catquiz/catscaleshortcodes/catscalestatistics',
                ['error' => $e->getMessage()]
            );
        }
        // The statistics show the results of other people: the rule of the statistics export applies (#17). Issue
        // #175: every test and every course of every block - one without the right shows nothing at all.
        foreach ($groups as $group) {
            foreach (context_resolver::for_statistics_scope($group['courseids'], $group['testids']) as $context) {
                if (!feedback_access::can_view_other_users($context)) {
                    return '';
                }
            }
        }
        // A scale that does not exist is named - to those who may see the statistics, after the check above.
        $scaleids = array_values(array_unique(array_column($groups, 'scaleid')));
        $known = $DB->get_records_list('local_catquiz_catscales', 'id', $scaleids, '', 'id');
        foreach ($scaleids as $scaleid) {
            if (!isset($known[$scaleid])) {
                return $OUTPUT->render_from_template(
                    'local_catquiz/catscaleshortcodes/catscalestatistics',
                    ['error' => get_string('catquizstatistics_unknownscale', 'local_catquiz', $scaleid)]
                );
            }
        }
        $endtime = isset($args['endtime']) ? (int) $args['endtime'] : null;
        $starttime = isset($args['starttime']) ? (int) $args['starttime'] : null;

        $html = '';
        foreach ($groups as $group) {
            $html .= self::render_statistics_group($group, $starttime, $endtime, $selection);
        }
        return $html;
    }

    /**
     * The statistics of one root scale of the scope (issue #175: one block per root scale).
     *
     * @param array $group One entry of populate_arguments().
     * @param int|null $starttime
     * @param int|null $endtime
     * @param string $selection
     * @return string
     */
    private static function render_statistics_group(array $group, ?int $starttime, ?int $endtime, string $selection): string {
        global $CFG, $OUTPUT;

        $courseids = $group['courseids'];
        $testids = $group['testids'];
        $globalscale = $group['scaleid'];
        try {
            $heading = self::get_heading($courseids, $globalscale, $testids, $starttime, $endtime);
        } catch (\Exception $e) {
            return $OUTPUT->render_from_template(
                'local_catquiz/catscaleshortcodes/catscalestatistics',
                ['error' => $e->getMessage()]
            );
        }

        try {
            $catquizstatistics = new catquizstatistics($courseids, $testids, $globalscale, $endtime, $starttime, $selection);
        } catch (dml_missing_record_exception $e) {
            return $OUTPUT->render_from_template(
                'local_catquiz/catscaleshortcodes/catscalestatistics',
                ['error' => 'Can not find a test with the given testid']
            );
        } catch (\Exception $e) {
            return $OUTPUT->render_from_template(
                'local_catquiz/catscaleshortcodes/catscalestatistics',
                ['error' => $e->getMessage()]
            );
        } catch (\Throwable $t) {
            $errormsg = 'Catquizstatistics can not be displayed due to an internal error';
            if ($CFG->debug > 0) {
                $errormsg = sprintf(
                    'Catquizstatistics can not be displayed due to an internal error: "%s" at %s:%d',
                    $t->getMessage(),
                    $t->getFile(),
                    $t->getLine()
                );
            }
            return $OUTPUT->render_from_template(
                'local_catquiz/catscaleshortcodes/catscalestatistics',
                ['error' => $errormsg]
            );
        }

        // The scope of the block: the charts loaded later and the export read exactly this (issue #175).
        $scope = [
            'courseid' => (int) id_list::single($courseids),
            'testid' => (int) id_list::single($testids),
            'courseids' => $courseids ?? [],
            'testids' => $testids ?? [],
            'scaleid' => (int) $globalscale,
            'starttime' => (int) $starttime,
            'endtime' => (int) $endtime,
            'attempts' => $selection,
        ];
        $data = [
            'heading' => $heading,
            'exportbutton' => $catquizstatistics->render_export_button() ?: false,
            // An ID of the block for themes: the same scope gives the same ID.
            'shortcodeid' => 'cqs-' . substr(sha1(json_encode($scope)), 0, 12),
        ];
        // Issue #85: with lazy loading, the page carries one empty region per chart; each chart is built
        // when its tab is shown, through local_catquiz_render_statistics_chart - one chart that fails
        // leaves the others standing. Without it, every chart is built with the page, as before.
        $lazy = (bool) get_config('local_catquiz', 'lazyfeedbacktabs');
        $scopejson = json_encode($scope);
        foreach (catquizstatistics::CHARTS as $chart => $templatekey) {
            $data[$templatekey] = $lazy
                ? ['lazy' => true, 'chartname' => $chart, 'scope' => $scopejson]
                : self::render_statistics_chart($catquizstatistics, $chart);
        }

        return $OUTPUT->render_from_template('local_catquiz/catscaleshortcodes/catscalestatistics', $data);
    }

    /**
     * One chart of the statistics, ready for the template; a chart that fails says so and leaves the rest.
     *
     * @param catquizstatistics $statistics
     * @param string $chart One of catquizstatistics::CHARTS.
     * @return array
     */
    public static function render_statistics_chart(catquizstatistics $statistics, string $chart): array {
        global $CFG;
        try {
            $data = $statistics->render_chart($chart);
        } catch (\Throwable $t) {
            // Issue #85: one chart that cannot be built does not take the page with it.
            $data = [
                'charttitle' => '',
                'chart' => get_string('statisticschartloadfailed', 'local_catquiz')
                    . ($CFG->debug > 0 ? ' ' . s($t->getMessage()) : ''),
            ];
        }
        $data['chartname'] = $chart;
        return $data;
    }

    /**
     * The blocks of the statistics shortcode: one per root scale of the scope (issues #88, #175).
     *
     * Arguments, each one ID or a comma-separated list (id_list):
     * - testid: course module ids (cmid) of CAT tests - never adaptivequiz.id. Every cmid has to be a CAT test.
     * - courseid, or course: courses. With tests, every test has to lie in one of them; without tests, the tests of
     *   the scale in these courses count (without either, the current course; scope="all" drops the course).
     * - globalscale: scales. With tests, the root scales of the tests, every test covered; without tests, the
     *   scales of the blocks. Without globalscale, the root scales of the tests or courses, one block each.
     *
     * @param array $args
     * @return array[] Blocks: ['scaleid' => int, 'courseids' => int[]|null, 'testids' => int[]|null (adaptivequiz.id),
     *                 'cmids' => int[]|null].
     * @throws Exception For an invalid list, an unknown test or scale, or arguments that contradict each other.
     */
    private static function populate_arguments(array $args): array {
        global $COURSE, $DB;

        $cmids = id_list::parse($args['testid'] ?? null, 'testid');
        $courseids = id_list::parse_alias($args, ['courseid', 'course']);
        $scales = id_list::parse($args['globalscale'] ?? null, 'globalscale');

        if ($cmids) {
            $tests = self::tests_of_cmids($cmids);
            $byroot = [];
            foreach ($tests as $cmid => $test) {
                // Issue #88: any course given has to be the course of the test - now: one of the courses.
                if ($courseids && !in_array((int) $test->courseid, $courseids, true)) {
                    throw new Exception(get_string('catquizstatistics_scale_course_conflict', 'local_catquiz'));
                }
                $byroot[(int) $test->catscaleid][$cmid] = $test;
            }
            if ($scales) {
                // The scales given are the scales of the tests: each names the scale of some test, every test is named.
                foreach ($scales as $scaleid) {
                    if (!isset($byroot[$scaleid])) {
                        throw new Exception(get_string('catquizstatistics_scale_testid_conflict', 'local_catquiz'));
                    }
                }
                if (array_diff(array_keys($byroot), $scales)) {
                    throw new Exception(get_string('catquizstatistics_scale_testid_conflict', 'local_catquiz'));
                }
                $order = $scales;
            } else {
                $order = array_keys($byroot);
            }
            $groups = [];
            foreach ($order as $root) {
                $grouptests = $byroot[$root];
                $groups[] = [
                    'scaleid' => (int) $root,
                    'courseids' => id_list::normalise(array_map(fn($t) => (int) $t->courseid, array_values($grouptests))),
                    'testids' => id_list::normalise(array_map(fn($t) => (int) $t->instance, array_values($grouptests))),
                    'cmids' => array_map('intval', array_keys($grouptests)),
                ];
            }
            return $groups;
        }

        $currentcourseid = 0;
        if (isset($COURSE) && !empty($COURSE->id) && $COURSE->id > 1) {
            $currentcourseid = (int) $COURSE->id;
        }
        $scopecourses = $courseids ?: ($currentcourseid ? [$currentcourseid] : []);
        if (!$scales) {
            if (!$scopecourses) {
                throw new Exception(get_string('catquizstatistics_askforparams', 'local_catquiz'));
            }
            $scales = self::get_global_scales($scopecourses);
        }

        // Issue #88: "$args['scope'] ?? null != 'all'" bound as "?? (null != 'all')" - scope="all" kept
        // the course, and only a missing scope compared at all.
        $filtercourses = ($scopecourses && ($args['scope'] ?? '') !== 'all') ? id_list::normalise($scopecourses) : null;
        return array_map(fn($scaleid) => [
            'scaleid' => (int) $scaleid,
            'courseids' => $filtercourses,
            'testids' => null,
            'cmids' => null,
        ], $scales);
    }

    /**
     * The CAT tests of course module ids, in one query (issue #175).
     *
     * @param int[] $cmids
     * @return \stdClass[] By cmid, in the order given: instance, courseid, catscaleid, json.
     * @throws Exception For a cmid that is no CAT test: unknown, another module, or without CAT settings.
     */
    private static function tests_of_cmids(array $cmids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'scm');
        $params['modname'] = 'adaptivequiz';
        $rows = $DB->get_recordset_sql(
            "SELECT cm.id AS cmid, cm.instance, cm.course, t.id AS testrecordid, t.courseid, t.catscaleid, t.json
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {local_catquiz_tests} t ON t.componentid = cm.instance
              WHERE cm.id $insql
           ORDER BY cm.id, t.id",
            $params
        );
        $found = [];
        $duplicate = [];
        foreach ($rows as $row) {
            $cmid = (int) $row->cmid;
            if (isset($found[$cmid])) {
                $duplicate[$cmid] = true;
                continue;
            }
            $row->courseid = (int) ($row->courseid ?: $row->course);
            $found[$cmid] = $row;
        }
        $rows->close();
        $tests = [];
        foreach ($cmids as $cmid) {
            if (!isset($found[$cmid]) || isset($duplicate[$cmid]) || empty($found[$cmid]->catscaleid)) {
                throw new Exception(get_string('catquizstatistics_testid_unknown', 'local_catquiz', $cmid));
            }
            $tests[$cmid] = $found[$cmid];
        }
        return $tests;
    }

    /**
     * The attempts the statistics count: all, or the first or last completed attempt of each person (issue #88).
     *
     * Shortcode argument attempts="first" (or "last", "all"; the default is all).
     *
     * @param array $args
     * @return string attempt_population::ALL, FIRST or LAST.
     * @throws Exception For any other value.
     */
    private static function attempt_selection(array $args): string {
        $selection = strtolower(trim((string) ($args['attempts'] ?? attempt_population::ALL)));
        $allowed = [attempt_population::ALL, attempt_population::FIRST, attempt_population::LAST];
        if (!in_array($selection, $allowed, true)) {
            throw new Exception(get_string('catquizstatistics_invalidattempts', 'local_catquiz', implode(', ', $allowed)));
        }
        return $selection;
    }

    /**
     * The root scales of the tests in the courses (issue #175: every one, not the first).
     *
     * @param int[] $courseids
     * @return int[] Ascending.
     * @throws Exception Without a CAT test in the courses.
     */
    private static function get_global_scales(array $courseids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'sgc');
        $scales = array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT catscaleid
               FROM {local_catquiz_tests}
              WHERE courseid $insql AND catscaleid IS NOT NULL AND catscaleid > 0
           ORDER BY catscaleid",
            $params
        ));
        if (!$scales) {
            throw new Exception(get_string('catquizstatistics_nodataforcourse', 'local_catquiz'));
        }
        return $scales;
    }

    /**
     * Returns an array with strings for the shortcode heading
     *
     * @param int[]|null $courseids One course or several (issue #175).
     * @param ?int $scaleid
     * @param int[]|null $testids adaptivequiz.id, one or several.
     * @param ?int $starttime
     * @param ?int $endtime
     * @return array
     */
    private static function get_heading(?array $courseids, ?int $scaleid, ?array $testids, ?int $starttime, ?int $endtime) {
        $courseid = id_list::single($courseids);
        $testid = id_list::single($testids);
        $test = null;
        if ($starttime && $endtime) {
            $start = userdate($starttime, get_string('strftimedatetime', 'core_langconfig'));
            $end = userdate($endtime, get_string('strftimedatetime', 'core_langconfig'));
            $timerangeaddition = get_string(
                'catquizstatistics_timerange_both',
                'local_catquiz',
                (object) ['starttime' => $start, 'endtime' => $end]
            );
        } else if ($starttime) {
            $start = userdate($starttime, get_string('strftimedatetime', 'core_langconfig'));
            $timerangeaddition = get_string('catquizstatistics_timerange_start', 'local_catquiz', (object) ['starttime' => $start]);
        } else if ($endtime) {
            $end = userdate($endtime, get_string('strftimedatetime', 'core_langconfig'));
            $timerangeaddition = get_string('catquizstatistics_timerange_end', 'local_catquiz', (object) ['endtime' => $end]);
        } else {
            $timerangeaddition = "";
        }
        if (($testids && count($testids) > 1) || ($courseids && count($courseids) > 1)) {
            // Issue #175: several tests or courses.
            return self::get_heading_of_sets($courseids, (int) $scaleid, $testids, $timerangeaddition);
        }
        if ($testid) {
            $test = catquiz::get_test_by_component_id($testid);
        }
        if ($test) {
            $testname = json_decode($test->json)->name;
            [$course, $cm] = get_course_and_cm_from_instance($test->componentid, 'adaptivequiz');
            $testurl = new moodle_url(
                '/mod/adaptivequiz/view.php',
                ['id' => $cm->id]
            );
            $link = sprintf('<a href="%s">%s</a>', $testurl->out(), $testname);

            $scale = catscale::return_catscale_object($test->catscaleid);
            $h1 = get_string('catquizstatistics_h1_single', 'local_catquiz', $testname);
            $h2 = get_string('catquizstatistics_h2_single', 'local_catquiz', ['link' => $link, 'scale' => $scale->name]);
            $h2 .= ' ' . $timerangeaddition;
            return [
                'title' => $h1,
                'description' => $h2,
            ];
        }

        // If there is no testid given, we need at least a scale id.
        if (!$scaleid) {
            return;
        }

        $scale = catscale::return_catscale_object($scaleid);

        if ($courseid) {
            $tests = catquiz::get_tests_for_scale($courseid, $scaleid);
            $course = get_course($courseid);
            $linkedcourses = array_map(function ($test) {
                $testname = json_decode($test->json)->name;
                [$course, $cm] = get_course_and_cm_from_instance($test->componentid, 'adaptivequiz');
                $testurl = new moodle_url(
                    '/mod/adaptivequiz/view.php',
                    ['id' => $cm->id]
                );
                $link = feedback_helper::add_quotes(sprintf('<a href="%s">%s</a>', $testurl->out(), $testname));
                return $link;
            }, $tests);
            $h1 = get_string('catquizstatistics_h1_scale', 'local_catquiz', (object) [
                'scalename' => $scale->name,
                'coursename' => $course->fullname,
            ]);
            if (count($linkedcourses) > 1) {
                $h2 = get_string('catquizstatistics_h2_scale', 'local_catquiz', (object) [
                    'linkedcourses' => implode(', ', $linkedcourses),
                    'scale' => $scale->name,
                    'coursename' => $course->fullname,
                ]);
            } else {
                $link = reset($linkedcourses);
                $h2 = get_string('catquizstatistics_h2_single', 'local_catquiz', (object) [
                    'link' => $link,
                    'scale' => $scale->name,
                ]);
            }
            return [
                'title' => $h1,
                'description' => sprintf('%s %s', $h2, $timerangeaddition),
            ];
        }

        // This is the global case.
        $h1 = get_string('catquizstatistics_h1_global', 'local_catquiz', $scale->name);
        $h2 = get_string('catquizstatistics_h2_global', 'local_catquiz', $scale->name);
        $h2 .= ' ' . $timerangeaddition;

        return [
            'title' => $h1,
            'description' => $h2,
        ];
    }

    /**
     * The heading of a scope with several tests or courses (issue #175): the tests linked, the courses named.
     *
     * Two queries whatever the number of tests: the tests, their course modules, and the names of the courses.
     *
     * @param int[]|null $courseids
     * @param int $scaleid
     * @param int[]|null $testids adaptivequiz.id
     * @param string $timerangeaddition
     * @return array
     */
    private static function get_heading_of_sets(
        ?array $courseids,
        int $scaleid,
        ?array $testids,
        string $timerangeaddition
    ): array {
        global $DB;

        $scale = catscale::return_catscale_object($scaleid);
        $scalename = format_string($scale->name);
        if ($testids) {
            $tests = $DB->get_records_list('local_catquiz_tests', 'componentid', $testids, 'componentid');
        } else {
            [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'shc');
            $params['root'] = attempt_population::root_of($scaleid);
            $tests = $DB->get_records_select(
                'local_catquiz_tests',
                "courseid $insql AND catscaleid = :root",
                $params,
                'courseid, componentid'
            );
        }
        $instances = array_values(array_unique(array_map(fn($t) => (int) $t->componentid, $tests)));
        $cmids = [];
        if ($instances) {
            [$insql, $params] = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED, 'shi');
            $params['modname'] = 'adaptivequiz';
            $cmids = $DB->get_records_sql_menu(
                "SELECT cm.instance, cm.id
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  WHERE cm.instance $insql",
                $params
            );
        }
        $links = [];
        $coursesoftests = [];
        foreach ($tests as $test) {
            $name = format_string((string) (json_decode((string) $test->json)->name ?? $test->name ?? ''));
            $coursesoftests[(int) $test->courseid] = (int) $test->courseid;
            $cmid = $cmids[(int) $test->componentid] ?? null;
            $link = $cmid ? \html_writer::link(new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cmid]), $name) : s($name);
            $links[] = feedback_helper::add_quotes($link);
        }
        $courses = $DB->get_records_list('course', 'id', $courseids ?: array_values($coursesoftests), 'id', 'id, fullname');
        $coursenames = implode(', ', array_map(fn($c) => format_string($c->fullname), $courses));
        $a = (object) [
            'scale' => $scalename,
            'scalename' => $scalename,
            'count' => count($tests),
            'links' => implode(', ', $links),
            'linkedcourses' => implode(', ', $links),
            'coursenames' => $coursenames,
        ];
        if ($testids) {
            return [
                'title' => get_string('catquizstatistics_h1_tests', 'local_catquiz', $a),
                'description' => trim(get_string('catquizstatistics_h2_tests', 'local_catquiz', $a) . ' ' . $timerangeaddition),
            ];
        }
        return [
            'title' => get_string('catquizstatistics_h1_scale_courses', 'local_catquiz', $a),
            'description' => trim(get_string('catquizstatistics_h2_scale_courses', 'local_catquiz', $a) . ' ' . $timerangeaddition),
        ];
    }
}
