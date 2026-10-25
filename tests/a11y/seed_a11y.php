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
 * Builds the fixture of the accessibility suite (issues #30, #32).
 *
 * A course with a CAT test over the scale of tests/fixtures/catquiz_resources_mc.*: two questions,
 * a classical test of two, the questions shown in the progress summary. A page with the statistics
 * shortcode twice, a page with the feedback shortcode, a few finished results of a peer for the
 * charts, and a CAT manager - a role made from the manager archetype with local/catquiz:canmanage.
 *
 * Every run creates a new participant, so each run meets a fresh attempt; course, scale, test and
 * pages are reused. Prints the values the suite needs as KEY=value lines; everything else goes to
 * STDERR.
 *
 * Usage, from anywhere:
 *     php seed_a11y.php --moodle=/path/to/moodle [--password=...] [--user=a11ycatstudent]
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// The script finds the config.php it was pointed at first, so the usual include cannot come first.

$options = getopt('', ['moodle:', 'password::', 'user::']);
if (empty($options['moodle'])) {
    fwrite(STDERR, "--moodle=/path/to/moodle is required.\n");
    exit(1);
}
$password = $options['password'] ?? 'A11y-Test-123!';
$username = $options['user'] ?? ('a11ycatstudent' . date('His'));

require($options['moodle'] . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filterlib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
// The data generators live under lib/testing and are not autoloaded outside the test runners.
require_once($CFG->libdir . '/testing/generator/lib.php');
// Moodle 5.1+ keeps vendor/ next to public/, older versions inside the code root.
foreach ([dirname($CFG->dirroot) . '/vendor/autoload.php', $CFG->dirroot . '/vendor/autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        require_once($autoload);
        break;
    }
}

\core\session\manager::set_user(get_admin());

/**
 * Sends cron output to STDERR: STDOUT of this script is the environment of the suite.
 *
 * @param string $string
 * @param string $eol
 */
function local_catquiz_a11y_mtrace(string $string, string $eol): void {
    fwrite(STDERR, $string . $eol);
}

// Moodle 5.0+ moves question banks into qbank activities with ad-hoc tasks after install; a fresh CI
// site never runs cron, so the seed runs them (see the same step in mod_adaptivequiz's seed).
$CFG->mtrace_wrapper = 'local_catquiz_a11y_mtrace';
$defaultbank = $CFG->corequestion_defaultqbankmod ?? 'qbank';
foreach (['transfer_question_categories', 'transfer_questions'] as $taskname) {
    $classname = "\\mod_{$defaultbank}\\task\\{$taskname}";
    if (class_exists($classname) && \core\task\manager::get_adhoc_tasks($classname)) {
        \core\cron::run_adhoc_tasks(time(), 0, false, null, null, $classname);
    }
}

$generator = new testing_data_generator();
/** @var local_catquiz_generator $catquiz */
$catquiz = $generator->get_plugin_generator('local_catquiz');
$now = time();

$course = $DB->get_record('course', ['shortname' => 'A11YCAT']);
if (!$course) {
    $course = $generator->create_course(['fullname' => 'CATquiz accessibility', 'shortname' => 'A11YCAT', 'numsections' => 1]);
}

// The scale and its two questions. The import matches the items to the questions by their label.
$scale = $DB->get_record('local_catquiz_catscales', ['name' => 'Ressourcen', 'parentid' => 0]);
if (!$scale) {
    $catquiz->create_catquiz_questions([
        'filepath' => 'local/catquiz/tests/fixtures/catquiz_resources_mc.xml',
        'filename' => 'catquiz_resources_mc.xml',
        'courseid' => $course->id,
    ]);
    $catquiz->create_catquiz_importedcatscales([
        'filepath' => 'local/catquiz/tests/fixtures/catquiz_resources_mc.csv',
        'filename' => 'catquiz_resources_mc.csv',
    ]);
    $scale = $DB->get_record('local_catquiz_catscales', ['name' => 'Ressourcen', 'parentid' => 0], '*', MUST_EXIST);
}
$contextid = (int) \local_catquiz\catscale::get_context_id((int) $scale->id);

// The test: a classical test of two questions that shows the questions in the progress summary.
$quiz = $DB->get_record('adaptivequiz', ['course' => $course->id, 'name' => 'A11y CAT test']);
if (!$quiz) {
    $quiz = $generator->create_module('adaptivequiz', [
        'course' => $course->id, 'name' => 'A11y CAT test', 'idnumber' => 'a11ycattest', 'section' => 1,
    ]);
    $strategyid = null;
    foreach (\local_catquiz\teststrategy\info::return_available_strategies() as $strategy) {
        if (isset($strategy->id) && $strategy->get_description() === 'Classical test') {
            $strategyid = $strategy->id;
        }
    }
    if ($strategyid === null) {
        fwrite(STDERR, "No strategy 'Classical test' - is the site in English?\n");
        exit(1);
    }
    $catquiz->create_catquiz_testsettings([
        'courseid' => $course->id, 'adaptivecatquizid' => $quiz->id, 'catmodel' => 'catquiz',
        'catscalesid' => $scale->id, 'cateststrategyid' => $strategyid,
        'catquiz_minquestions' => 2, 'catquiz_maxquestions' => 2,
        'catquiz_standarderror_min' => 0.0, 'catquiz_standarderror_max' => 1000.0,
        'catquiz_showquestion' => 1, 'catquiz_showquestionresponse' => 1, 'scalefeedbacktexts' => 1,
    ]);
}
$cm = get_coursemodule_from_instance('adaptivequiz', $quiz->id, $course->id, false, MUST_EXIST);

// The shortcodes need their filter.
filter_set_global_state('shortcodes', TEXTFILTER_ON);
$pages = [
    'A11y statistics' => '<p>[catquizstatistics]</p><p>[catquizstatistics]</p>',
    'A11y feedback' => '<p>[catquizfeedback]</p>',
];
$pagecms = [];
foreach ($pages as $name => $content) {
    $page = $DB->get_record('page', ['course' => $course->id, 'name' => $name]);
    if (!$page) {
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => $name, 'content' => $content]);
    }
    $pagecms[$name] = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
}

// Finished results of a peer, so the statistics have charts to draw (as the Behat step "the following
// CAT results ... exist" stores them).
$peer = $DB->get_record('user', ['username' => 'a11ycatpeer']);
if (!$peer) {
    $peer = $generator->create_user(['username' => 'a11ycatpeer', 'firstname' => 'Peer', 'lastname' => 'Result']);
    $generator->enrol_user($peer->id, $course->id, 'student');
    $scales = [(int) $scale->id, ...\local_catquiz\catscale::get_subscale_ids((int) $scale->id)];
    foreach ([3 => 1.0, 1 => -0.5] as $daysago => $ability) {
        $end = $now - $daysago * DAYSECS;
        $attemptid = $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $peer->id, 'scaleid' => $scale->id, 'contextid' => $contextid, 'courseid' => $course->id,
            'attemptid' => 990000 + $daysago, 'component' => 'mod_adaptivequiz', 'instanceid' => $quiz->id,
            'teststrategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC, 'status' => 0, 'starttime' => $end - 600,
            'endtime' => $end, 'personability_after_attempt' => $ability,
            'json' => json_encode(['personabilities' => array_fill_keys($scales, $ability)]),
            'timecreated' => $end, 'timemodified' => $end,
        ]);
        foreach ($scales as $scaleid) {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'userid' => $peer->id, 'catscaleid' => $scaleid, 'contextid' => $contextid, 'attemptid' => $attemptid,
                'ability' => $ability, 'isvalid' => 1, 'resultsource' => 'current',
                'isprimary' => $scaleid === (int) $scale->id ? 1 : 0, 'status' => 0,
                'timecreated' => $end, 'timemodified' => $end,
            ]);
        }
    }
}

// The CAT manager: the manager archetype with the capability only site administrators have by default.
$role = $DB->get_record('role', ['shortname' => 'a11ycatmanager']);
$roleid = $role ? (int) $role->id : create_role('A11y CAT manager', 'a11ycatmanager', '', 'manager');
if (!$role) {
    reset_role_capabilities($roleid);
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
assign_capability('local/catquiz:canmanage', CAP_ALLOW, $roleid, context_system::instance()->id, true);
$manager = $DB->get_record('user', ['username' => 'a11ycatmanager']);
if (!$manager) {
    $manager = $generator->create_user(['username' => 'a11ycatmanager', 'password' => $password,
        'firstname' => 'Cat', 'lastname' => 'Manager', 'email' => 'a11ycatmanager@example.com']);
}
update_internal_user_password($manager, $password);
role_assign($roleid, $manager->id, context_system::instance()->id);
$generator->enrol_user($manager->id, $course->id, 'editingteacher');

// The participant: new on every run, so the attempt is always the first one.
$user = $DB->get_record('user', ['username' => $username]);
if (!$user) {
    $user = $generator->create_user([
        'username' => $username, 'password' => $password,
        'firstname' => 'Ada', 'lastname' => 'Accessible', 'email' => $username . '@example.com',
    ]);
}
$generator->enrol_user($user->id, $course->id, 'student');
\local_catquiz\catquiz_handler::prepare_attempt_caches();

echo 'A11Y_USER=' . $username . "\n";
echo 'A11Y_PASSWORD=' . $password . "\n";
echo 'A11Y_MANAGER=a11ycatmanager' . "\n";
echo 'A11Y_ACTIVITY_URL=/mod/adaptivequiz/view.php?id=' . $cm->id . "\n";
echo 'A11Y_STATISTICS_URL=/mod/page/view.php?id=' . $pagecms['A11y statistics']->id . "\n";
echo 'A11Y_FEEDBACK_URL=/mod/page/view.php?id=' . $pagecms['A11y feedback']->id . "\n";
echo 'A11Y_SCALEID=' . $scale->id . "\n";
echo 'A11Y_CONTEXTID=' . $contextid . "\n";
fwrite(STDERR, "CATquiz fixture ready: course {$course->id}, test {$cm->id}, scale {$scale->id}, participant {$username}.\n");
