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
     * @param \Behat\Gherkin\Node\TableNode $table Columns: user, days ago, ability, valid.
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
            $end = time() - (int) $row['days ago'] * DAYSECS;
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
}
