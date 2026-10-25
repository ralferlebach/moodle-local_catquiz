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
 * Defines message providers (types of messages being sent)
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * To create catquiz specific behat scearios.
 * @package local_catquiz
 */
class behat_catquiz extends behat_base {
    /**
     * Opens the CAT attempt feedback review page for a user's most recent attempt.
     *
     * Issue #18: reviewing another user's attempt is the situation in which the
     * permission context matters. The attempt id is generated at runtime, so it
     * cannot be written into a feature file; this step looks it up by username.
     *
     * @Given /^I visit the CAT attempt feedback page for the last attempt of "([^"]*)"$/
     *
     * @param string $username
     * @return void
     */
    public function i_visit_the_cat_attempt_feedback_page_for_the_last_attempt_of(string $username): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempt = $DB->get_records(
            'local_catquiz_attempts',
            ['userid' => $userid],
            'id DESC',
            'attemptid, contextid',
            0,
            1
        );

        if (empty($attempt)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'No CAT attempt found for user ' . $username,
                $this->getSession()
            );
        }

        $attempt = reset($attempt);
        $url = new moodle_url('/local/catquiz/show_attemptfeedback.php', [
            'attemptid' => $attempt->attemptid,
            'contextid' => $attempt->contextid,
        ]);

        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Opens the result page of a user's last attempt, as the end of the attempt shows it (issue #121).
     *
     * @Given /^I open the result page of the last attempt of "([^"]*)"$/
     *
     * @param string $username
     * @return void
     */
    public function i_open_the_result_page_of_the_last_attempt_of(string $username): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, instance, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $attempt = reset($attempts);
        $cm = get_coursemodule_from_instance('adaptivequiz', $attempt->instance, 0, false, MUST_EXIST);
        $url = new moodle_url('/mod/adaptivequiz/attemptfinished.php', [
            'cmid' => $cm->id, 'id' => $attempt->instance, 'uattid' => $attempt->uniqueid,
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Opens the result page of a user's last attempt in one activity (mod_adaptivequiz #22).
     *
     * @Given /^I open the result page of the last attempt of "([^"]*)" in "([^"]*)"$/
     *
     * @param string $username
     * @param string $idnumber The activity's ID number.
     * @return void
     */
    public function i_open_the_result_page_of_the_last_attempt_of_in(string $username, string $idnumber): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $attempts = $DB->get_records(
            'adaptivequiz_attempt',
            ['userid' => $userid, 'instance' => $cm->instance],
            'id DESC',
            'id, instance, uniqueid',
            0,
            1
        );
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException("No attempt of $username in $idnumber", $this->getSession());
        }
        $attempt = reset($attempts);
        $url = new moodle_url('/mod/adaptivequiz/attemptfinished.php', [
            'cmid' => $cm->id, 'id' => $attempt->instance, 'uattid' => $attempt->uniqueid,
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Makes an activity unavailable to participants: a date restriction that ended an hour ago (issue #121).
     *
     * @Given /^the activity "([^"]*)" is no longer available to participants$/
     *
     * @param string $idnumber
     * @return void
     */
    public function the_activity_is_no_longer_available_to_participants(string $idnumber): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $availability = \core_availability\tree::get_root_json([
            \availability_date\condition::get_json(\availability_date\condition::DIRECTION_UNTIL, time() - HOURSECS),
        ]);
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cm->id]);
        rebuild_course_cache($cm->course, true);
    }

    /**
     * Offers the CAT settings of an activity as a template of the given name (issue #123).
     *
     * A template is a test environment without an activity (componentid 0) that is active.
     *
     * @Given /^the CAT settings of "([^"]*)" are offered as the template "([^"]*)"$/
     *
     * @param string $idnumber ID number of the activity.
     * @param string $name Name of the template.
     * @return void
     */
    public function the_cat_settings_are_offered_as_the_template(string $idnumber, string $name): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $test = $DB->get_record(
            'local_catquiz_tests',
            ['component' => 'mod_adaptivequiz', 'componentid' => $cm->instance],
            '*',
            MUST_EXIST
        );
        $json = json_decode($test->json);
        $json->componentid = 0;
        $json->name = $name;
        // A template is saved by the form, and the form sends the limits of every feedback range. The generator
        // of test settings writes the number of ranges but limits only for the scale ids of its fixture: without
        // limits of its own, the form filled the ranges of the activity in and only the added ones from the
        // template - a gap the form refuses. The limits the form computes, where none are stored.
        $options = (int) ($json->numberoffeedbackoptionsselect ?? 0);
        $rootid = (int) ($json->catquiz_catscales ?? 0);
        if ($options > 0 && $rootid > 0) {
            $range = (new \local_catquiz\catscale($rootid))->get_ability_range($rootid);
            foreach (get_object_vars($json) as $key => $value) {
                if (!preg_match('/^catquiz_subscalecheckbox_(\d+)$/', $key, $matches) || empty($value)) {
                    continue;
                }
                for ($i = 1; $i <= $options; $i++) {
                    foreach (['lower' => true, 'upper' => false] as $side => $lower) {
                        $limitkey = "feedback_scaleid_limit_{$side}_{$matches[1]}_{$i}";
                        if (!isset($json->$limitkey)) {
                            $json->$limitkey = \local_catquiz\feedback\feedbackclass::return_limits_for_scale(
                                $options,
                                $i,
                                $lower,
                                (float) $range['minscalevalue'],
                                (float) $range['maxscalevalue']
                            );
                        }
                    }
                }
            }
        }
        unset($test->id);
        $test->componentid = 0;
        $test->name = $name;
        $test->status = LOCAL_CATQUIZ_STATUS_TEST_ACTIVE;
        $test->json = json_encode($json);
        $test->timecreated = time();
        $test->timemodified = time();
        $DB->insert_record('local_catquiz_tests', $test);
        \cache_helper::purge_all();
    }

    /**
     * Hides an activity from participants after the fact (issue #139).
     *
     * @Given /^the activity "([^"]*)" is hidden from participants$/
     *
     * @param string $idnumber
     * @return void
     */
    public function the_activity_is_hidden_from_participants(string $idnumber): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        set_coursemodule_visible($cm->id, 0);
        rebuild_course_cache($cm->course, true);
    }

    /**
     * Deletes the question usage of a user's last attempt, as a restore or a clean-up can (issue #139).
     *
     * A page that was opened before still offers the questions; the modal must then report the
     * missing question instead of loading for ever.
     *
     * @Given /^the questions of the last attempt of "([^"]*)" no longer exist$/
     *
     * @param string $username
     * @return void
     */
    public function the_questions_of_the_last_attempt_no_longer_exist(string $username): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        \question_engine::delete_questions_usage_by_activity((int) reset($attempts)->uniqueid);
    }

    /**
     * Stores the quiz history of the last CAT attempt of a person in the older format (issue #152).
     *
     * Before personability_after existed, a row carried the ability as personability, and no pilot flag.
     *
     * @Given /^the quiz history of the last CAT attempt of "([^"]*)" is stored in the older format$/
     *
     * @param string $username
     * @return void
     */
    public function the_quiz_history_is_stored_in_the_older_format(string $username): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $records = $DB->get_records('local_catquiz_attempts', ['userid' => $userid], 'id DESC', 'id, json', 0, 1);
        $record = reset($records);
        $data = $record ? json_decode((string) $record->json, true) : null;
        if (empty($data['graphicalsummary_data'])) {
            throw new \Behat\Mink\Exception\ExpectationException('No quiz history of ' . $username, $this->getSession());
        }
        foreach ($data['graphicalsummary_data'] as &$row) {
            $row['personability'] = $row['personability_after'] ?? null;
            unset($row['personability_after'], $row['pilot']);
        }
        unset($row);
        $DB->set_field('local_catquiz_attempts', 'json', json_encode($data), ['id' => $record->id]);
        \cache_helper::purge_all();
    }

    /**
     * Gives the parameters of the questions a model that is not installed (issue #153).
     *
     * As after uninstalling the subplugin of their model, or importing parameters of a model this site does not have.
     *
     * @Given /^the parameters of the questions "([^"]*)" belong to a model that is not installed$/
     *
     * @param string $idnumbers Question id numbers, comma-separated.
     * @return void
     */
    public function the_parameters_belong_to_a_model_not_installed(string $idnumbers): void {
        global $DB;
        foreach (array_map('trim', explode(',', $idnumbers)) as $idnumber) {
            $questionid = $DB->get_field_sql(
                "SELECT qv.questionid
                   FROM {question_bank_entries} qbe
                   JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  WHERE qbe.idnumber = :idnumber",
                ['idnumber' => $idnumber],
                MUST_EXIST
            );
            $DB->set_field('local_catquiz_itemparams', 'model', 'notinstalled', ['componentid' => $questionid]);
        }
        \cache_helper::purge_all();
    }

    /**
     * Connects STACK to Maxima with the test configuration of config.php (issue #139).
     *
     * The scenario is skipped where qtype_stack is not installed or no Maxima is configured - the
     * same rule as STACK's own step "I set up STACK using the PHPUnit configuration", which cannot be
     * used here because it does not exist without STACK.
     *
     * @Given /^STACK is connected to Maxima for this test$/
     *
     * @return void
     */
    public function stack_is_connected_to_maxima(): void {
        $dir = \core_component::get_plugin_directory('qtype', 'stack');
        if (!$dir || !file_exists($dir . '/version.php')) {
            throw new \Moodle\BehatExtension\Exception\SkippedException('qtype_stack is not installed.');
        }
        require_once($dir . '/tests/fixtures/test_maxima_configuration.php');
        if (!\qtype_stack_test_config::is_test_config_available()) {
            throw new \Moodle\BehatExtension\Exception\SkippedException(
                'No Maxima configuration for tests (QTYPE_STACK_TEST_CONFIG_* in config.php).'
            );
        }
        \qtype_stack_test_config::setup_test_maxima_connection();
    }

    /**
     * Checks that an image of the question in the open dialogue was delivered (issue #139).
     *
     * An image the browser could not load - refused or missing file - has no natural width.
     *
     * @Then /^the image "([^"]*)" in the question dialogue has loaded$/
     *
     * @param string $alt Alternative text of the image.
     * @return void
     */
    public function the_image_in_the_question_dialogue_has_loaded(string $alt): void {
        $alt = json_encode($alt);
        $script = "return (function() {"
            . " var img = Array.from(document.querySelectorAll('.modal.show img'))"
            . "   .find(function(i) { return i.getAttribute('alt') === $alt; });"
            . " if (!img) { return 'missing'; }"
            . " if (!img.complete) { return 'loading'; }"
            . " return img.naturalWidth > 0 ? 'loaded' : 'broken ' + img.src;"
            . "})();";
        $this->spin(function () use ($script) {
            $state = $this->getSession()->evaluateScript($script);
            if ($state !== 'loaded') {
                throw new \Behat\Mink\Exception\ExpectationException('Image state: ' . $state, $this->getSession());
            }
            return true;
        }, [], behat_base::get_extended_timeout());
    }

    /**
     * Restricts an activity to the members of a group (issue #132).
     *
     * @Given /^the activity "([^"]*)" is restricted to the group "([^"]*)"$/
     *
     * @param string $idnumber
     * @param string $groupname
     * @return void
     */
    public function the_activity_is_restricted_to_the_group(string $idnumber, string $groupname): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $groupid = $DB->get_field('groups', 'id', ['courseid' => $cm->course, 'name' => $groupname], MUST_EXIST);
        $availability = \core_availability\tree::get_root_json([\availability_group\condition::get_json((int) $groupid)]);
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cm->id]);
        rebuild_course_cache($cm->course, true);
    }

    /**
     * Stores finished CAT results of a test, as the finalisation stores them (issues #119, #132).
     *
     * One row per result: user, how many days ago it was finished, the ability on the test's scale and
     * its subscales, and whether it is valid. Builds a history without taking the test day after day.
     *
     * @Given /^the following CAT results of "([^"]*)" exist:$/
     *
     * @param string $idnumber
     * @param \Behat\Gherkin\Node\TableNode $table Columns: user, days ago or end (UTC, Y-m-d H:i - issue #151), ability,
     *     valid.
     * @return void
     */
    public function the_following_cat_results_exist(string $idnumber, \Behat\Gherkin\Node\TableNode $table): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        static $hostattemptid = 900000;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $test = $DB->get_record(
            'local_catquiz_tests',
            ['componentid' => $cm->instance, 'component' => 'mod_adaptivequiz'],
            '*',
            MUST_EXIST
        );
        $rootid = (int) $test->catscaleid;
        $scales = [$rootid, ...\local_catquiz\catscale::get_subscale_ids($rootid)];
        $contextid = (int) \local_catquiz\catscale::get_context_id($rootid);
        foreach ($table->getHash() as $row) {
            $userid = (int) $DB->get_field('user', 'id', ['username' => $row['user']], MUST_EXIST);
            $end = isset($row['end'])
                ? (new \DateTimeImmutable($row['end'], new \DateTimeZone('UTC')))->getTimestamp()
                : time() - (int) $row['days ago'] * DAYSECS;
            $ability = (float) $row['ability'];
            $id = $DB->insert_record('local_catquiz_attempts', (object) [
                'userid' => $userid, 'scaleid' => $rootid, 'contextid' => $contextid, 'courseid' => $cm->course,
                'attemptid' => ++$hostattemptid, 'component' => 'mod_adaptivequiz', 'instanceid' => $cm->instance,
                'teststrategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC, 'status' => 0, 'starttime' => $end - 600,
                'endtime' => $end, 'personability_after_attempt' => $ability,
                'json' => json_encode(['personabilities' => array_fill_keys($scales, $ability)]),
                'timecreated' => $end, 'timemodified' => $end,
            ]);
            foreach ($scales as $scaleid) {
                $DB->insert_record('local_catquiz_personparams', (object) [
                    'userid' => $userid, 'catscaleid' => $scaleid, 'contextid' => $contextid, 'attemptid' => $id,
                    'ability' => $ability, 'isvalid' => $row['valid'] === 'yes' ? 1 : 0, 'resultsource' => 'current',
                    'isprimary' => $scaleid === $rootid ? 1 : 0, 'status' => 0, 'timecreated' => $end, 'timemodified' => $end,
                ]);
            }
        }
    }

    /**
     * Limits the time of an attempt in the stored CAT settings of a test (issue #141, stop reasons).
     *
     * @Given /^the CAT settings of "([^"]*)" limit an attempt to (\d+) minutes$/
     *
     * @param string $idnumber
     * @param int $minutes
     * @return void
     */
    public function the_cat_settings_limit_an_attempt(string $idnumber, int $minutes): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $test = $DB->get_record(
            'local_catquiz_tests',
            ['componentid' => $cm->instance, 'component' => 'mod_adaptivequiz'],
            '*',
            MUST_EXIST
        );
        $json = json_decode($test->json);
        $json->catquiz_includetimelimit = 1;
        $json->catquiz_timelimitgroup = (object) [
            'catquiz_maxtimeperattempt' => $minutes,
            'catquiz_timeselect_attempt' => 'min',
            'catquiz_maxtimeperitem' => 0,
            'catquiz_timeselect_item' => 'min',
        ];
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $test->id]);
        \cache_helper::purge_all();
    }

    /**
     * Limits the time for one question in the stored CAT settings of a test (mod_adaptivequiz #5).
     *
     * @Given /^the CAT settings of "([^"]*)" limit a question to (\d+) minutes$/
     *
     * @param string $idnumber
     * @param int $minutes
     * @return void
     */
    public function the_cat_settings_limit_a_question(string $idnumber, int $minutes): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $test = $DB->get_record(
            'local_catquiz_tests',
            ['componentid' => $cm->instance, 'component' => 'mod_adaptivequiz'],
            '*',
            MUST_EXIST
        );
        $json = json_decode($test->json);
        $json->catquiz_includetimelimit = 1;
        $json->catquiz_timelimitgroup = (object) [
            'catquiz_maxtimeperattempt' => 0,
            'catquiz_timeselect_attempt' => 'min',
            'catquiz_maxtimeperitem' => $minutes,
            'catquiz_timeselect_item' => 'min',
        ];
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $test->id]);
        \cache_helper::purge_all();
    }

    /**
     * Moves the start of a user's open attempt of the activity into the past (mod_adaptivequiz #5).
     *
     * The activity judges the time of the attempt by its own start; the CAT progress is moved with it.
     *
     * @Given /^the open attempt of "([^"]*)" started (\d+) minutes ago$/
     *
     * @param string $username
     * @param int $minutes
     * @return void
     */
    public function the_open_attempt_started_minutes_ago(string $username, int $minutes): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $open = ['userid' => $userid, 'attemptstate' => 'inprogress'];
        $attempts = $DB->get_records('adaptivequiz_attempt', $open, 'id DESC', '*', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No open attempt of ' . $username, $this->getSession());
        }
        $attempt = reset($attempts);
        $started = (int) $attempt->timecreated - $minutes * MINSECS;
        $DB->set_field('adaptivequiz_attempt', 'timecreated', $started, ['id' => $attempt->id]);
        if ($DB->record_exists('local_catquiz_progress', ['userid' => $userid])) {
            $this->the_running_cat_attempt_started_minutes_ago($username, $minutes);
        }
    }

    /**
     * Moves the moment the question on a user's screen was shown into the past (mod_adaptivequiz #5).
     *
     * @Given /^the question on the screen of "([^"]*)" was shown (\d+) minutes ago$/
     *
     * @param string $username
     * @param int $minutes
     * @return void
     */
    public function the_question_on_the_screen_was_shown_minutes_ago(string $username, int $minutes): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $open = ['userid' => $userid, 'attemptstate' => 'inprogress'];
        $attempts = $DB->get_records('adaptivequiz_attempt', $open, 'id DESC', '*', 0, 1);
        $attempt = reset($attempts);
        if (!$attempt || empty($attempt->uniqueid)) {
            throw new \Behat\Mink\Exception\ExpectationException('No question on the screen of ' . $username, $this->getSession());
        }
        $slot = (int) $DB->get_field_sql(
            'SELECT MAX(slot) FROM {question_attempts} WHERE questionusageid = ?',
            [$attempt->uniqueid]
        );
        $DB->execute(
            "UPDATE {question_attempt_steps}
                SET timecreated = timecreated - :shift
              WHERE questionattemptid IN (SELECT id FROM {question_attempts} WHERE questionusageid = :usage AND slot = :slot)",
            ['shift' => $minutes * MINSECS, 'usage' => $attempt->uniqueid, 'slot' => $slot]
        );
        \cache_helper::purge_by_event('changesinadaptivequizattempt');
    }

    /**
     * Moves the start of a user's running CAT attempt into the past (issue #141, stop reasons).
     *
     * Saves waiting for a time limit in real time. The progress is read again from the database on the
     * next request.
     *
     * @Given /^the running CAT attempt of "([^"]*)" started (\d+) minutes ago$/
     *
     * @param string $username
     * @param int $minutes
     * @return void
     */
    public function the_running_cat_attempt_started_minutes_ago(string $username, int $minutes): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $rows = $DB->get_records('local_catquiz_progress', ['userid' => $userid], 'id DESC', '*', 0, 1);
        if (!$rows) {
            throw new \Behat\Mink\Exception\ExpectationException('No running CAT attempt of ' . $username, $this->getSession());
        }
        $row = reset($rows);
        $json = json_decode($row->json);
        $json->starttime = (int) ($json->starttime ?? time()) - $minutes * MINSECS;
        $DB->set_field('local_catquiz_progress', 'json', json_encode($json), ['id' => $row->id]);
        // The progress lives in the session cache as well; the invalidation event empties it.
        \cache_helper::purge_by_event('changesinadaptivequizattempt');
    }

    /**
     * Deactivates the CAT items of every question a user's last attempt has not shown yet (issues #141, #60).
     *
     * The pool runs out during the attempt - what settings checked before the start cannot foresee. The
     * runtime pool is invalidated by the event a change of the items fires.
     *
     * @Given /^the CAT items of the questions "([^"]*)" has not seen yet are deactivated$/
     *
     * @param string $username
     * @return void
     */
    public function the_cat_items_not_seen_yet_are_deactivated(string $username): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $seen = $DB->get_fieldset_select('question_attempts', 'questionid', 'questionusageid = ?', [reset($attempts)->uniqueid]);
        [$notin, $params] = $DB->get_in_or_equal($seen ?: [0], SQL_PARAMS_QM, 'param', false);
        $DB->set_field_select(
            'local_catquiz_items',
            'status',
            LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE,
            "componentname = 'question' AND componentid $notin",
            $params
        );
        \cache_helper::purge_by_event('changesintestitems');
    }

    /**
     * Checks the questions of a user's last attempt against the subscales of a scale (issue #135).
     *
     * Each question counts for the direct subscale of the scale its item lies under; the attempt must have asked
     * the number of questions, each once, and drawn them from at least the number of subscales.
     *
     * @Then /^the last CAT attempt of "([^"]*)" asked (\d+) different questions from at least (\d+) subscales of "([^"]*)"$/
     *
     * @param string $username
     * @param int $questions
     * @param int $subscales
     * @param string $scalename
     * @return void
     */
    public function the_last_cat_attempt_asked_questions_from_subscales(
        string $username,
        int $questions,
        int $subscales,
        string $scalename
    ): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $asked = $DB->get_fieldset_select('question_attempts', 'questionid', 'questionusageid = ?', [reset($attempts)->uniqueid]);
        $rootid = (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => $scalename, 'parentid' => 0], MUST_EXIST);
        $parents = $DB->get_records_menu('local_catquiz_catscales', null, '', 'id, parentid');
        $top = [];
        foreach ($asked as $questionid) {
            $scaleids = $DB->get_fieldset_select(
                'local_catquiz_items',
                'catscaleid',
                "componentname = 'question' AND componentid = ?",
                [$questionid]
            );
            foreach ($scaleids as $scaleid) {
                $scaleid = (int) $scaleid;
                while ($scaleid && (int) ($parents[$scaleid] ?? 0) !== $rootid) {
                    $scaleid = (int) ($parents[$scaleid] ?? 0);
                }
                if ($scaleid) {
                    $top[$scaleid] = true;
                }
            }
        }
        $problems = [];
        if (count($asked) !== $questions) {
            $problems[] = count($asked) . " questions asked, not $questions";
        }
        if (count(array_unique($asked)) !== count($asked)) {
            $problems[] = 'a question was asked twice';
        }
        if (count($top) < $subscales) {
            $problems[] = 'questions of ' . count($top) . " subscales, not at least $subscales";
        }
        if ($problems) {
            throw new \Behat\Mink\Exception\ExpectationException(implode('; ', $problems), $this->getSession());
        }
    }

    /**
     * Switches the reporting of a scale off in the stored CAT settings of a test (issue #117).
     *
     * @Given /^the scale "([^"]*)" is not reported by "([^"]*)"$/
     *
     * @param string $scalename
     * @param string $idnumber
     * @return void
     */
    public function the_scale_is_not_reported_by(string $scalename, string $idnumber): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $test = $DB->get_record(
            'local_catquiz_tests',
            ['componentid' => $cm->instance, 'component' => 'mod_adaptivequiz'],
            '*',
            MUST_EXIST
        );
        $scaleid = $DB->get_field('local_catquiz_catscales', 'id', ['name' => $scalename], MUST_EXIST);
        $json = json_decode($test->json);
        $json->{'catquiz_scalereportcheckbox_' . $scaleid} = '0';
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $test->id]);
        \cache_helper::purge_all();
    }

    /**
     * A large scale tree: more subscales below a scale (issue #51).
     *
     * @Given /^the CAT scale "([^"]*)" has (\d+) more subscales$/
     *
     * @param string $scalename
     * @param int $number
     * @return void
     */
    public function the_cat_scale_has_more_subscales(string $scalename, int $number): void {
        global $DB;

        $parent = $DB->get_record('local_catquiz_catscales', ['name' => $scalename], '*', MUST_EXIST);
        for ($i = 1; $i <= $number; $i++) {
            \local_catquiz\data\dataapi::create_catscale(new \local_catquiz\data\catscale_structure([
                'name' => $scalename . ' extra ' . $i, 'parentid' => $parent->id, 'contextid' => $parent->contextid,
                'minscalevalue' => $parent->minscalevalue, 'maxscalevalue' => $parent->maxscalevalue,
                'timecreated' => time(), 'timemodified' => time(),
            ]));
        }
        \cache_helper::purge_all();
    }

    /**
     * The number of feedback editors in the settings form - of the one scale being edited (issue #51).
     *
     * @Then /^the settings form has (\d+) feedback editors$/
     *
     * @param int $number
     * @return void
     */
    public function the_settings_form_has_feedback_editors(int $number): void {
        $editors = $this->getSession()->getPage()->findAll(
            'xpath',
            "//textarea[starts-with(@name, 'feedbackeditor_scaleid_')]"
        );
        if (count($editors) !== $number) {
            throw new \Behat\Mink\Exception\ExpectationException(
                sprintf('%d feedback editors in the form, %d expected.', count($editors), $number),
                $this->getSession()
            );
        }
    }

    /**
     * The number of scales whose feedback is carried along without fields (issue #51).
     *
     * @Then /^the settings form carries the feedback of at least (\d+) more scales$/
     *
     * @param int $number
     * @return void
     */
    public function the_settings_form_carries_feedback_of(int $number): void {
        $carriers = $this->getSession()->getPage()->findAll(
            'xpath',
            "//input[@type='hidden' and starts-with(@name, 'catquiz_feedbackcarrier_') and not(@disabled)]"
        );
        if (count($carriers) < $number) {
            throw new \Behat\Mink\Exception\ExpectationException(
                sprintf('The feedback of %d scales is carried along, at least %d expected.', count($carriers), $number),
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the keyboard focus is inside the open dialogue (issue #139).
     *
     * Moodle decides which element of a dialogue takes the focus; what matters is that it is in the
     * dialogue and not left on the page behind it.
     *
     * @Then /^the focus is inside the open dialogue$/
     *
     * @return void
     */
    public function the_focus_is_inside_the_open_dialogue(): void {
        $this->spin(function () {
            $inside = $this->getSession()->evaluateScript(
                "return !!(document.activeElement && document.activeElement.closest('.modal.show'));"
            );
            if (!$inside) {
                throw new \Behat\Mink\Exception\ExpectationException(
                    'The focus is not in the open dialogue.',
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Puts the first question of a user's last attempt into its usage a second time (issue #125).
     *
     * Reproduces what older versions of the activity did on a reload: the same question in a further
     * slot of the same usage. The host no longer does it, so the state is built here - the reviewer's
     * view of such an attempt is what the scenario checks.
     *
     * @Given /^the last CAT attempt of "([^"]*)" administered its first question a second time$/
     *
     * @param string $username
     * @return void
     */
    public function the_last_cat_attempt_administered_its_first_question_again(string $username): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $quba = \question_engine::load_questions_usage_by_activity((int) reset($attempts)->uniqueid);
        $first = $quba->get_question(1, false);
        $slot = $quba->add_question(\question_bank::load_question($first->id));
        $quba->start_question($slot);
        \question_engine::save_questions_usage_by_activity($quba);
    }

    /**
     * Hands the saved progress of a person's running CAT attempt to someone else (issue #96).
     *
     * The state that #96 refuses: a progress row whose person is not the person of its CAT attempt. The
     * session cache is emptied as well, or the own entry would be used and the row never checked.
     *
     * @Given /^the saved progress of the running CAT attempt of "([^"]*)" belongs to "([^"]*)"$/
     *
     * @param string $owner
     * @param string $other
     * @return void
     */
    public function the_saved_progress_belongs_to(string $owner, string $other): void {
        global $DB;
        $catattempt = $this->last_cat_attempt_of($owner);
        $otherid = $DB->get_field('user', 'id', ['username' => $other], MUST_EXIST);
        $progress = $DB->get_record('local_catquiz_progress', ['attemptid' => $catattempt->id]);
        if (!$progress) {
            throw new \Behat\Mink\Exception\ExpectationException('No saved progress of ' . $owner, $this->getSession());
        }
        $DB->set_field('local_catquiz_progress', 'userid', $otherid, ['id' => $progress->id]);
        \cache_helper::purge_by_event('changesinadaptivequizattempt');
    }

    /**
     * The saved progress of a person's CAT attempt still names the given person, and there is one.
     *
     * @Then /^the saved progress of the CAT attempt of "([^"]*)" still belongs to "([^"]*)"$/
     *
     * @param string $owner
     * @param string $expected
     * @return void
     */
    public function the_saved_progress_still_belongs_to(string $owner, string $expected): void {
        global $DB;
        $catattempt = $this->last_cat_attempt_of($owner);
        $expectedid = (int) $DB->get_field('user', 'id', ['username' => $expected], MUST_EXIST);
        $rows = $DB->get_records('local_catquiz_progress', ['attemptid' => $catattempt->id]);
        if (count($rows) !== 1 || (int) reset($rows)->userid !== $expectedid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                sprintf(
                    'Progress of the CAT attempt %d: %d rows, user %s.',
                    $catattempt->id,
                    count($rows),
                    $rows ? reset($rows)->userid : '-'
                ),
                $this->getSession()
            );
        }
    }

    /**
     * A refused progress was logged with its reason (issue #96).
     *
     * @Then /^a progress integrity failure "([^"]*)" was logged$/
     *
     * @param string $reason
     * @return void
     */
    public function a_progress_integrity_failure_was_logged(string $reason): void {
        global $DB;
        $events = $DB->get_records('logstore_standard_log', ['eventname' => '\\local_catquiz\\event\\progress_integrity_failed']);
        foreach ($events as $event) {
            $other = json_decode((string) $event->other, true);
            if (($other['reason'] ?? null) === $reason) {
                return;
            }
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            sprintf('No progress integrity failure "%s" logged (%d others).', $reason, count($events)),
            $this->getSession()
        );
    }

    /**
     * Sets the numbering of CAT attempts exactly N ahead of the activity's (issues #95, #101).
     *
     * Afterwards the next CAT attempt gets the id the next activity attempt gets, plus N. Rows are inserted
     * and deleted again, which moves the sequence on every database and leaves nothing that statistics
     * could count. Whichever numbering is behind is moved.
     *
     * @Given /^the CAT attempt numbering is (\d+) ahead of the activity attempts$/
     *
     * @param int $n
     * @return void
     */
    public function the_cat_attempt_numbering_is_ahead(int $n): void {
        $catnext = $this->next_id('local_catquiz_attempts');
        $activitynext = $this->next_id('adaptivequiz_attempt');
        if ($catnext - $activitynext < $n) {
            $this->advance_to('local_catquiz_attempts', $activitynext + $n);
        } else if ($catnext - $activitynext > $n) {
            $this->advance_to('adaptivequiz_attempt', $catnext - $n);
        }
    }

    /**
     * The next activity attempt gets the id the CAT attempt of a person already has (issue #101).
     *
     * The case where mixing up the two id spaces does harm: the attempt id of person B is the CAT id of
     * person A.
     *
     * @Given /^the next activity attempt id equals the CAT attempt id of "([^"]*)"$/
     *
     * @param string $username
     * @return void
     */
    public function the_next_activity_attempt_id_equals_the_cat_attempt_id_of(string $username): void {
        $target = (int) $this->last_cat_attempt_of($username)->id;
        $next = $this->next_id('adaptivequiz_attempt');
        if ($next > $target) {
            throw new \Behat\Mink\Exception\ExpectationException(
                sprintf('The activity attempts are already at %d, beyond %d; move the CAT numbering ahead first.', $next, $target),
                $this->getSession()
            );
        }
        $this->advance_to('adaptivequiz_attempt', $target);
    }

    /**
     * The last CAT attempt of a person is filed under that person's own activity attempt, and the two ids
     * differ (issues #95, #101).
     *
     * @Then /^the CAT attempt of "([^"]*)" is filed under its own activity attempt with a different id$/
     *
     * @param string $username
     * @return void
     */
    public function the_cat_attempt_is_filed_under_its_own_activity_attempt(string $username): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, userid', 0, 1);
        $attempt = reset($attempts);
        $catattempt = $this->last_cat_attempt_of($username);
        $progress = $DB->get_records('local_catquiz_progress', ['attemptid' => $catattempt->id]);
        $problems = [];
        if (!$attempt || (int) $catattempt->attemptid !== (int) $attempt->id) {
            $problems[] = 'the CAT attempt names another activity attempt';
        }
        if ((int) $catattempt->userid !== $userid) {
            $problems[] = 'the CAT attempt belongs to someone else';
        }
        if ($attempt && (int) $catattempt->id === (int) $attempt->id) {
            $problems[] = 'both ids are the same, nothing was tested';
        }
        if (count($progress) > 1 || ($progress && (int) reset($progress)->userid !== $userid)) {
            $problems[] = 'the progress is not the person\'s one';
        }
        if ($problems) {
            throw new \Behat\Mink\Exception\ExpectationException(implode('; ', $problems), $this->getSession());
        }
    }

    /**
     * The last CAT attempt of a person.
     *
     * @param string $username
     * @return \stdClass
     */
    private function last_cat_attempt_of(string $username): \stdClass {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $rows = $DB->get_records('local_catquiz_attempts', ['userid' => $userid], 'id DESC', '*', 0, 1);
        if (!$rows) {
            throw new \Behat\Mink\Exception\ExpectationException('No CAT attempt of ' . $username, $this->getSession());
        }
        return reset($rows);
    }

    /**
     * A row for moving the numbering of a table, deleted right away.
     *
     * @param string $table local_catquiz_attempts or adaptivequiz_attempt
     * @return int Its id.
     */
    private function insert_and_delete(string $table): int {
        global $DB;
        $id = (int) $DB->insert_record($table, (object) $this->numbering_row($table));
        $DB->delete_records($table, ['id' => $id]);
        return $id;
    }

    /**
     * The id the next row of a table gets.
     *
     * @param string $table
     * @return int
     */
    private function next_id(string $table): int {
        return $this->insert_and_delete($table) + 1;
    }

    /**
     * Moves the numbering of a table on: its next row gets the id given.
     *
     * @param string $table
     * @param int $next
     */
    private function advance_to(string $table, int $next): void {
        global $DB;
        // In one go: the sequences of a test site start far apart, and a row inserted per step took
        // hundreds of thousands of them. A row with the id before the target makes the sequence
        // continue after it; it is deleted again.
        $before = $next - 1;
        $row = $this->numbering_row($table);
        $DB->execute(
            'INSERT INTO {' . $table . '} (id, ' . implode(', ', array_keys($row)) . ') VALUES (?'
                . str_repeat(', ?', count($row)) . ')',
            array_merge([$before], array_values($row))
        );
        $DB->get_manager()->reset_sequence(new \xmldb_table($table));
        $DB->delete_records($table, ['id' => $before]);
    }

    /**
     * A row that can be put into a table to move its numbering.
     *
     * @param string $table
     * @return array
     */
    private function numbering_row(string $table): array {
        $row = $table === 'local_catquiz_attempts'
            ? ['userid' => 0, 'attemptid' => -1, 'component' => 'behat_numbering', 'status' => 1, 'json' => '{}']
            : ['instance' => 0, 'userid' => 0, 'uniqueid' => 0, 'attemptstate' => 'complete', 'attemptstopcriteria' => '',
                'questionsattempted' => 0, 'difficultysum' => 0, 'standarderror' => 0, 'measure' => 0];
        return $row + ['timecreated' => 0, 'timemodified' => 0];
    }

    /**
     * Fill specified HTMLQuickForm element by its number under goven xpath with a value.
     * @When /^I fill in the "([^"]*)" element number "([^"]*)" with the dynamic identifier "([^"]*)" with "([^"]*)"$/
     *
     * @param string $fieldtype
     * @param string $numberofitem
     * @param string $dynamicidentifier
     * @param string $value
     *
     * @return void
     *
     */
    public function i_fill_in_the_element_with_dynamic_identifier($fieldtype, $numberofitem, $dynamicidentifier, $value) {
        // Use $dynamicIdentifier to locate and fill in the corresponding form field.
        // Use $value to set the desired value in the form field.

        // First we need to open all collapsibles.
        // We should probably have a single fuction for that.
        $xpathtarget = "//div[contains(@id, 'catquiz_feedback_collapse_')]";
        $fields = $this->getSession()->getPage()->findAll('xpath', $xpathtarget);

        foreach ($fields as $field) {
            $id = $field->getAttribute('id');
            // Use JavaScript to add the expected class to the element.
            $script = "document.getElementById('$id').classList.add('show');";
            $this->getSession()->executeScript($script);
            $this->getSession()->wait(500);
        }
        // Now we get the form element fields by the identifier and its number in DOM.
        switch ($fieldtype) {
            case 'editor':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')][@contenteditable='true'])
                    [" . $numberofitem . "]";
                break;
            case 'autocomplete':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//input[contains(@id, 'form_autocomplete_input-')]";
                $xpathtarget1 = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//li[contains(@id, 'form_autocomplete_suggestions-')]";
                break;
            case 'wb_colourpicker':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//span[@data-colour=" . $value . "]";
                break;
            default:
                $xpathtarget = "(//" . $fieldtype . "[contains(@id, '" . $dynamicidentifier . "')])[" . $numberofitem . "]";
        }

        // Assuming you want to find an editor element related to the competency and fill it with the specified value.
        $field = $this->getSession()->getPage()->find('xpath', $xpathtarget);
        if ($field->isVisible()) {
            switch ($fieldtype) {
                case 'autocomplete':
                    $field->setValue($value);
                    // The suggestion list is populated asynchronously (debounced
                    // AJAX). Wait for the visible suggestion that actually
                    // contains the requested value instead of pressing Enter and
                    // clicking the first list item.
                    $escapedvalue = behat_context_helper::escape($value);
                    $suggestionxpath = $xpathtarget1
                        . "[@role='option']"
                        . "[not(@aria-hidden='true')]"
                        . "[contains(normalize-space(.), $escapedvalue)]";
                    $suggestion = null;
                    for ($attempt = 0; $attempt < 20; $attempt++) {
                        $candidate = $this->getSession()->getPage()->find('xpath', $suggestionxpath);
                        if ($candidate && $candidate->isVisible()) {
                            $suggestion = $candidate;
                            break;
                        }
                        $this->getSession()->wait(300);
                    }
                    if (!$suggestion) {
                        throw new \Behat\Mink\Exception\ExpectationException(
                            "Visible autocomplete suggestion containing '$value' did not appear",
                            $this->getSession()
                        );
                    }
                    $suggestion->click();
                    // Moodle's autocomplete transfers the selection to the hidden
                    // native <select> asynchronously (a promise chain that ends in
                    // option.selected = true plus a native change event). Wait for
                    // that to complete rather than forcing the native state
                    // ourselves: the test must verify Moodle's own behaviour, and
                    // the native <select> is the value actually submitted (Behat
                    // 001 / catquiz_courses).
                    $containerxpath = "(//div[contains(@id, '" . $dynamicidentifier . "')])[" . $numberofitem . "]";
                    $selected = false;
                    for ($attempt = 0; $attempt < 30; $attempt++) {
                        if ($this->native_autocomplete_has_selected_text($containerxpath, $value)) {
                            $selected = true;
                            break;
                        }
                        $this->getSession()->wait(200);
                    }
                    if (!$selected) {
                        throw new \Behat\Mink\Exception\ExpectationException(
                            "Autocomplete '$value' was clicked, but Moodle did not select the "
                                . "corresponding native option. Native options: "
                                . $this->get_native_autocomplete_debug($containerxpath),
                            $this->getSession()
                        );
                    }
                    // Close the suggestion list only after the selection completed,
                    // so it cannot overlap and swallow later clicks.
                    $field->keyPress(27);
                    break;
                case 'wb_colourpicker':
                    $field->click();
                    break;
                default:
                    // Fill in the form field with the specified value.
                    $field->setValue($value);
            }
        }
    }

    /**
     * Whether the hidden native <select> of a Moodle autocomplete has an option
     * with the given text selected.
     *
     * Uses evaluateScript so it reads the real DOM state: Moodle hides the
     * original <select> (display:none, aria-hidden), so Mink's getText() on its
     * options is unreliable, while option.selected and option.textContent are
     * accurate regardless of visibility.
     *
     * @param string $containerxpath Xpath of the form item wrapping the select.
     * @param string $value          The visible option text to look for.
     *
     * @return bool
     */
    protected function native_autocomplete_has_selected_text($containerxpath, $value) {
        $xpath = json_encode($containerxpath . '//select');
        $wanted = json_encode($value);
        $script = <<<JS
(function() {
    const select = document.evaluate(
        $xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null
    ).singleNodeValue;
    if (!select) {
        return false;
    }
    const wanted = $wanted;
    return Array.from(select.options).some(function (option) {
        return option.selected && (option.textContent || '').trim().indexOf(wanted) !== -1;
    });
})()
JS;
        return (bool) $this->getSession()->evaluateScript($script);
    }

    /**
     * Returns a JSON dump of the native <select> options and their state.
     *
     * Included in failure messages so a red run shows the actual DOM state
     * (which option exists and whether it is selected) instead of only
     * "not selected".
     *
     * @param string $containerxpath Xpath of the form item wrapping the select.
     *
     * @return string
     */
    protected function get_native_autocomplete_debug($containerxpath) {
        $xpath = json_encode($containerxpath . '//select');
        $script = <<<JS
(function() {
    const select = document.evaluate(
        $xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null
    ).singleNodeValue;
    if (!select) {
        return 'NO SELECT';
    }
    return JSON.stringify(Array.from(select.options).map(function (option) {
        return {
            value: option.value,
            text: (option.textContent || '').trim(),
            selected: option.selected,
            disabled: option.disabled
        };
    }));
})()
JS;
        return (string) $this->getSession()->evaluateScript($script);
    }

    /**
     * Asserts that the native <select> of an autocomplete has an option with the
     * given text selected.
     *
     * This checks the value that is actually submitted with the form, which is
     * independent of the visible autocomplete chips. Placing this assertion at
     * the hand-over points (right after selection, after a failed validation
     * submit, and after save + reload) localises exactly where a selection is
     * lost (Behat 001 / catquiz_courses).
     *
     * @Then /^the autocomplete number "([^"]*)" for "([^"]*)" has "([^"]*)" natively selected$/
     *
     * @param string $numberofitem
     * @param string $dynamicidentifier
     * @param string $value
     *
     * @return void
     */
    public function autocomplete_should_have_natively_selected($numberofitem, $dynamicidentifier, $value) {
        $containerxpath = "(//div[contains(@id, '" . $dynamicidentifier . "')])[" . $numberofitem . "]";
        if ($this->native_autocomplete_has_selected_text($containerxpath, $value)) {
            return;
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            "Native autocomplete select under '$dynamicidentifier' number '$numberofitem' "
                . "does not have '$value' selected. Native options: "
                . $this->get_native_autocomplete_debug($containerxpath),
            $this->getSession()
        );
    }

    /**
     * The question id of a question bank entry, by its idnumber: the version a CAT item refers to (issue #82).
     *
     * @param string $idnumber
     * @return int
     */
    private function catquiz_item_question_id(string $idnumber): int {
        global $DB;
        $ids = $DB->get_fieldset_sql(
            "SELECT qv.questionid
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {local_catquiz_items} lci ON lci.componentid = qv.questionid AND lci.componentname = 'question'
              WHERE qbe.idnumber = :idnumber
           ORDER BY qv.version DESC",
            ['idnumber' => $idnumber]
        );
        if (!$ids) {
            throw new \Behat\Mink\Exception\ExpectationException('No CAT item for the question ' . $idnumber, $this->getSession());
        }
        return (int) reset($ids);
    }

    /**
     * The id of a CAT scale, by its name.
     *
     * @param string $scalename
     * @return int
     */
    private function catquiz_scale_id(string $scalename): int {
        global $DB;
        return (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => $scalename], MUST_EXIST);
    }

    /**
     * Hides a question in the question bank, the way the bank does it for a question in use (issue #82).
     *
     * Deleting a question that is in use only sets the status of its versions to hidden -
     * question_delete_question() - and triggers no event. That is what this step does, for every version of
     * the entry, whether or not something uses it yet.
     *
     * @Given /^the question "([^"]*)" is hidden in the question bank$/
     *
     * @param string $idnumber
     * @return void
     */
    public function the_question_is_hidden_in_the_question_bank(string $idnumber): void {
        global $DB;
        $entryid = $DB->get_field('question_bank_entries', 'id', ['idnumber' => $idnumber], MUST_EXIST);
        $DB->set_field(
            'question_versions',
            'status',
            \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN,
            ['questionbankentryid' => $entryid]
        );
    }

    /**
     * Assigns a question that is already a CAT item to a further scale, active there (issue #82).
     *
     * @Given /^the question "([^"]*)" is also an item of the CAT scale "([^"]*)"$/
     *
     * @param string $idnumber
     * @param string $scalename
     * @return void
     */
    public function the_question_is_also_an_item_of_the_cat_scale(string $idnumber, string $scalename): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $result = \local_catquiz\catscale::add_or_update_testitem_to_scale(
            $this->catquiz_scale_id($scalename),
            $this->catquiz_item_question_id($idnumber),
            LOCAL_CATQUIZ_TESTITEM_STATUS_ACTIVE
        );
        if (!$result->isOk()) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Not assigned to ' . $scalename . ': ' . $result->get_status(),
                $this->getSession()
            );
        }
    }

    /**
     * Deactivates a CAT item in one scale, as the eye icon of the CAT manager does (issue #82).
     *
     * @Given /^the question "([^"]*)" is deactivated in the CAT scale "([^"]*)"$/
     *
     * @param string $idnumber
     * @param string $scalename
     * @return void
     */
    public function the_question_is_deactivated_in_the_cat_scale(string $idnumber, string $scalename): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        \local_catquiz\catscale::add_or_update_testitem_to_scale(
            $this->catquiz_scale_id($scalename),
            $this->catquiz_item_question_id($idnumber),
            LOCAL_CATQUIZ_TESTITEM_STATUS_INACTIVE
        );
    }

    /**
     * Opens the questions of one CAT scale - without its subscales - in the CAT manager (issue #82).
     *
     * @Given /^I open the questions of the CAT scale "([^"]*)" in the CAT manager$/
     *
     * @param string $scalename
     * @return void
     */
    public function i_open_the_questions_of_the_cat_scale_in_the_cat_manager(string $scalename): void {
        $scaleid = $this->catquiz_scale_id($scalename);
        $url = new moodle_url('/local/catquiz/manage_catscales.php', [
            'tab' => 'questions',
            'scaleid' => $scaleid,
            'contextid' => \local_catquiz\catscale::get_context_id($scaleid),
            'usesubs' => 0,
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Opens the detail card of a CAT item in the CAT manager (issue #82).
     *
     * @Given /^I open the CAT item "([^"]*)" of the CAT scale "([^"]*)" in the CAT manager$/
     *
     * @param string $idnumber
     * @param string $scalename
     * @return void
     */
    public function i_open_the_cat_item_of_the_cat_scale_in_the_cat_manager(string $idnumber, string $scalename): void {
        $scaleid = $this->catquiz_scale_id($scalename);
        $url = new moodle_url('/local/catquiz/manage_catscales.php', [
            'tab' => 'questions',
            'scaleid' => $scaleid,
            'contextid' => \local_catquiz\catscale::get_context_id($scaleid),
            'id' => $this->catquiz_item_question_id($idnumber),
            'component' => 'question',
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Checks that the last attempt of a user never asked a question (issue #82).
     *
     * @Then /^the last CAT attempt of "([^"]*)" did not ask "([^"]*)"$/
     *
     * @param string $username
     * @param string $idnumber
     * @return void
     */
    public function the_last_cat_attempt_did_not_ask(string $username, string $idnumber): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id, uniqueid', 0, 1);
        if (!$attempts) {
            throw new \Behat\Mink\Exception\ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $attempt = reset($attempts);
        $asked = $DB->get_fieldset_sql(
            "SELECT qbe.idnumber
               FROM {question_attempts} qa
               JOIN {question_versions} qv ON qv.questionid = qa.questionid
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qa.questionusageid = :usage",
            ['usage' => $attempt->uniqueid]
        );
        if (!$asked) {
            throw new \Behat\Mink\Exception\ExpectationException('The last attempt asked nothing.', $this->getSession());
        }
        if (in_array($idnumber, $asked, true)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The last attempt asked ' . $idnumber . ': ' . implode(', ', $asked),
                $this->getSession()
            );
        }
    }

    /**
     * Checks the runtime pool of one CAT scale - as a test of that scale reads it - for a question (issue #82).
     *
     * @Then /^the CAT pool of "([^"]*)" (contains|does not contain) the question "([^"]*)"$/
     *
     * @param string $scalename
     * @param string $which
     * @param string $idnumber
     * @return void
     */
    public function the_cat_pool_contains_the_question(string $scalename, string $which, string $idnumber): void {
        $scaleid = $this->catquiz_scale_id($scalename);
        $pool = (new \local_catquiz\catscale($scaleid))->get_testitems(\local_catquiz\catscale::get_context_id($scaleid));
        $contains = array_key_exists($this->catquiz_item_question_id($idnumber), $pool);
        if ($contains !== ($which === 'contains')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The pool of ' . $scalename . ($contains ? ' contains ' : ' does not contain ') . $idnumber,
                $this->getSession()
            );
        }
    }

    /**
     * Checks that a root scale points to a context of its own that exists (issue #81).
     *
     * @Then /^the CAT scale "([^"]*)" has a context of its own$/
     *
     * @param string $scalename
     * @return void
     */
    public function the_cat_scale_has_a_context_of_its_own(string $scalename): void {
        global $DB;
        $contextid = (int) $DB->get_field('local_catquiz_catscales', 'contextid', ['name' => $scalename], MUST_EXIST);
        if ($contextid <= 0 || !$DB->record_exists('local_catquiz_catcontext', ['id' => $contextid])) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The scale ' . $scalename . ' points to context ' . $contextid,
                $this->getSession()
            );
        }
    }

    /**
     * Checks that a subscale stores no context and resolves to the one of its root (issue #81).
     *
     * @Then /^the CAT scale "([^"]*)" has no context of its own and uses the one of "([^"]*)"$/
     *
     * @param string $scalename
     * @param string $rootname
     * @return void
     */
    public function the_cat_scale_uses_the_context_of(string $scalename, string $rootname): void {
        global $DB;
        $scale = $DB->get_record('local_catquiz_catscales', ['name' => $scalename], '*', MUST_EXIST);
        $rootcontext = (int) $DB->get_field('local_catquiz_catscales', 'contextid', ['name' => $rootname], MUST_EXIST);
        \cache_helper::purge_by_event('changesincatscales');
        if ($scale->contextid !== null || \local_catquiz\catscale::get_context_id((int) $scale->id) !== $rootcontext) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The scale ' . $scalename . ' stores context ' . var_export($scale->contextid, true),
                $this->getSession()
            );
        }
    }

    /**
     * Checks that no id is used twice on the page (issue #30).
     *
     * Tabs, collapses and dialogues find their target by id. Two instances with the same ids - the same
     * shortcode twice, a shortcode next to the result page - make a click in one open the panel of the
     * other, or none at all. The duplicates are named in the failure.
     *
     * @Then /^the page has no duplicate ids$/
     *
     * @return void
     */
    public function the_page_has_no_duplicate_ids(): void {
        $this->spin(function () {
            $duplicates = $this->getSession()->evaluateScript(
                "return (function() {" .
                "  var seen = {}, twice = [];" .
                "  document.querySelectorAll('[id]').forEach(function(el) {" .
                "    if (!el.id) { return; }" .
                "    if (seen[el.id] === 1) { twice.push(el.id); }" .
                "    seen[el.id] = (seen[el.id] || 0) + 1;" .
                "  });" .
                "  return twice;" .
                "})();"
            );
            if (!empty($duplicates)) {
                throw new \Behat\Mink\Exception\ExpectationException(
                    'Ids used more than once on the page: ' . implode(', ', $duplicates),
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * The templates of CAT settings - test environments of no activity - with a name, or all of them (issue #89).
     *
     * @param string|null $name
     * @return \stdClass[]
     */
    private function cat_templates(?string $name = null): array {
        global $DB;
        $conditions = ['componentid' => 0];
        if ($name !== null) {
            $conditions['name'] = $name;
        }
        return $DB->get_records('local_catquiz_tests', $conditions, 'id');
    }

    /**
     * Checks how many templates of CAT settings exist, with a name or at all (issue #89).
     *
     * @Then /^there (?:is|are) (\d+) CAT templates?(?: named "([^"]*)")?$/
     *
     * @param int $count
     * @param string $name
     * @return void
     */
    public function there_are_cat_templates(int $count, string $name = ''): void {
        $found = count($this->cat_templates($name === '' ? null : $name));
        if ($found !== $count) {
            $named = $name ? " named \"$name\"" : '';
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected $count CAT templates$named, found $found.",
                $this->getSession()
            );
        }
    }

    /**
     * Checks a stored value of a template of CAT settings; nested keys with a dot (issue #89).
     *
     * @Then /^the CAT template "([^"]*)" stores "([^"]*)" as "([^"]*)"$/
     *
     * @param string $name
     * @param string $key E.g. maxquestionsgroup.catquiz_maxquestions
     * @param string $expected
     * @return void
     */
    public function the_cat_template_stores(string $name, string $key, string $expected): void {
        $templates = $this->cat_templates($name);
        if (count($templates) !== 1) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected one CAT template named \"$name\", found " . count($templates) . '.',
                $this->getSession()
            );
        }
        $value = json_decode(reset($templates)->json, true);
        foreach (explode('.', $key) as $part) {
            $value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : null;
        }
        if ((string) $value !== $expected) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Template \"$name\": $key is \"" . (string) $value . "\", not \"$expected\".",
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the CAT settings of an activity name the template they are based on (issue #89).
     *
     * @Then /^the CAT settings of "([^"]*)" are based on the template "([^"]*)"$/
     *
     * @param string $idnumber
     * @param string $name
     * @return void
     */
    public function the_cat_settings_are_based_on_the_template(string $idnumber, string $name): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $parentid = (int) $DB->get_field('local_catquiz_tests', 'parentid', [
            'component' => 'mod_adaptivequiz', 'componentid' => $cm->instance,
        ], MUST_EXIST);
        $templates = $this->cat_templates($name);
        $template = reset($templates);
        if (!$template || (int) $template->id !== $parentid) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "The CAT settings of $idnumber are based on template $parentid, not on \"$name\".",
                $this->getSession()
            );
        }
    }

    /**
     * Checks lines of the visible text of the main region, exactly and in the given order (issue #77).
     *
     * The written feedback is one block with a line per scale; "should appear before" compares elements and
     * finds the same block for every line. Each line has to be found as a whole line, after the one before.
     *
     * @Then /^the following lines appear in this order in the main region:$/
     *
     * @param \Behat\Gherkin\Node\TableNode $table One line per row, first column.
     * @return void
     */
    public function the_following_lines_appear_in_this_order(\Behat\Gherkin\Node\TableNode $table): void {
        $text = (string) $this->evaluate_script(
            "return (document.querySelector('#region-main') || document.body).innerText;"
        );
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), 'strlen'));
        $from = 0;
        foreach ($table->getRows() as $row) {
            $wanted = trim($row[0]);
            $found = null;
            for ($i = $from; $i < count($lines); $i++) {
                if ($lines[$i] === $wanted) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                $where = in_array($wanted, $lines, true) ? 'only before the line before it' : 'not as a line';
                throw new \Behat\Mink\Exception\ExpectationException("\"$wanted\" is $where.", $this->getSession());
            }
            $from = $found + 1;
        }
    }

    /**
     * The items of a CAT scale and its subscales lose their parameters, as after a new calibration context or
     * before the first calibration: the results stay, the test information has nothing to sum (issue #150).
     *
     * @Given /^the items of the CAT scale "([^"]*)" have no parameters$/
     *
     * @param string $scalename
     * @return void
     */
    public function the_items_of_the_cat_scale_have_no_parameters(string $scalename): void {
        global $DB;
        $rootid = $this->catquiz_scale_id($scalename);
        $scaleids = [$rootid, ...\local_catquiz\catscale::get_subscale_ids($rootid)];
        [$insql, $params] = $DB->get_in_or_equal($scaleids);
        $itemids = $DB->get_fieldset_select('local_catquiz_items', 'id', "catscaleid $insql", $params);
        if (!$itemids) {
            throw new \Behat\Mink\Exception\ExpectationException("No items in $scalename.", $this->getSession());
        }
        [$initem, $itemparams] = $DB->get_in_or_equal($itemids);
        $DB->execute("UPDATE {local_catquiz_items} SET activeparamid = NULL WHERE id $initem", $itemparams);
        $DB->delete_records_select('local_catquiz_itemparams', "itemid $initem", $itemparams);
        \cache_helper::purge_all();
    }

    /**
     * A CAT scale moves to a new CAT context, as a new calibration starts it: through the scale update of the CAT
     * manager, which takes the items and parameters along (issue #148). The results stay in the old context.
     *
     * @Given /^the CAT scale "([^"]*)" is switched to a new CAT context$/
     *
     * @param string $scalename
     * @return void
     */
    public function the_cat_scale_is_switched_to_a_new_cat_context(string $scalename): void {
        global $DB;
        $scaleid = $this->catquiz_scale_id($scalename);
        $contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
            'name' => 'Recalibration of ' . $scalename, 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'starttimestamp' => time() - 60, 'endtimestamp' => time() + YEARSECS, 'timecreated' => time(),
            'timemodified' => time(), 'usermodified' => 0,
        ]);
        \local_catquiz\data\dataapi::update_catscale((object) ['id' => $scaleid, 'contextid' => $contextid]);
        if ((int) \local_catquiz\catscale::get_context_id($scaleid) !== $contextid) {
            throw new \Behat\Mink\Exception\ExpectationException("$scalename did not move.", $this->getSession());
        }
        \cache_helper::purge_all();
    }
}
