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

namespace local_catquiz\output;

use cache;
use context_system;
use local_catquiz\catquiz;
use local_catquiz\catscale;
use local_catquiz\teststrategy\feedbackgenerator;
use local_catquiz\teststrategy\feedbacksettings;
use local_catquiz\teststrategy\info;
use local_catquiz\teststrategy\progress;
use templatable;
use renderable;
use stdClass;

/**
 * Renderable class for the catscalemanagers
 *
 * @package    local_catquiz
 * @copyright  2023 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attemptfeedback implements renderable, templatable {

    /**
     * @var ?int
     */
    public int $attemptid;

    /**
     * @var ?int
     */
    public int $contextid;

    /**
     * @var ?int
     */
    public int $catscaleid;

    /**
     * @var ?int
     */
    public int $courseid;

    /**
     * @var ?int
     */
    public int $teststrategy;

    /**
     * @var ?object
     */
    public feedbacksettings $feedbacksettings;

    /**
     * @var ?object
     */
    public stdClass $quizsettings;

    /**
     * Constructor of class.
     *
     * @param int $attemptid
     * @param int $contextid
     * @param ?feedbacksettings $feedbacksettings
     * @param int $courseid
     *
     */
    public function __construct(
        int $attemptid,
        int $contextid = 0,
        ?feedbacksettings $feedbacksettings = null,
        $courseid = null) {
        global $USER;
        if ($attemptid === 0) {
            // This can still return nothing. In that case, we show a message that the user has no attempts yet.
            if (!$attemptid = catquiz::get_last_user_attemptid($USER->id)) {
                return;
            }
        }
        $this->attemptid = $attemptid;

        if (!empty($courseid)) {
            $this->courseid = $courseid;
        }

        if (!$testenvironment = catquiz::get_testenvironment_by_attemptid($attemptid)) {
            return;
        }

        $settings = json_decode($testenvironment->json);
        $this->quizsettings = $settings;
        $this->teststrategy = intval($settings->catquiz_selectteststrategy);

        if (!isset($feedbacksettings)) {
            $this->feedbacksettings = new feedbacksettings();
        } else {
            $this->feedbacksettings = $feedbacksettings;
        }
        $catscaleid = intval($this->quizsettings->catquiz_catscales);
        $this->catscaleid = $catscaleid;

        if ($contextid === 0) {
            // Get the contextid of the catscale.
            $contextid = catscale::get_context_id($catscaleid);
        }
        $this->contextid = $contextid;
    }

    /**
     * Renders strategy feedback.
     * In addition, it saves all feedback data to the database.
     *
     * @param  bool $savetodb
     * @return mixed
     *
     */
    private function render_strategy_feedback($savetodb = true) {
        global $USER;
        if (!$this->teststrategy) {
            return '';
        }
        $progress = progress::load($this->attemptid);

        $generators = $this->get_feedback_generators_for_teststrategy($this->teststrategy);

        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $context = [
            'attemptid' => $this->attemptid,
            'contextid' => $this->contextid,
            'courseid' => $this->courseid ?? 0,
            'needsimprovementthreshold' => 0, // TODO: Get the quantile threshold from the quiz settings.
            'userid' => $USER->id,
            'catscaleid' => $this->catscaleid,
            'teststrategy' => $this->teststrategy,
            'starttime' => $cache->get('starttime'),
            'endtime' => $cache->get('endtime'),
            'total_number_of_testitems' => $cache->get('totalnumberoftestitems'),
            'number_of_testitems_used' => empty($progress->get_playedquestions()) ? 0 : count($progress->get_playedquestions()),
            'ability_before_attempt' => $cache->get('abilitybeforeattempt'),
            'catquizerror' => $cache->get('catquizerror'),
            'studentfeedback' => [],
            'teacherfeedback' => [],
            'quizsettings' => $cache->get('quizsettings'),
            'personabilities' => $cache->get('personabilities'),
        ];

        $feedbackdata = $this->load_data_from_generators($generators, $context);
        if (!$feedbackdata) {
            return [];
        }

        // If courses or groups are selected, User is enrolled to.
        catquiz::enrol_user($USER->id, (array)$cache->get('quizsettings'), (array)$cache->get('personabilities'));

        if ($savetodb) {
            $id = catquiz::save_attempt_to_db($feedbackdata);
        }
        return $this->generate_feedback($generators, $feedbackdata);
    }

    /**
     * Get the data from the feedbackgenerators.
     *
     * @param array $generators
     * @param array $context
     * @return array
     */
    private function load_data_from_generators(array $generators, array $context): array {
        // Get the data required to generate the feedback. This can be saved to
        // the DB.
        $feedbackdata = $context;
        foreach ($generators as $generator) {
            $generatordata = $generator->load_data($this->attemptid, $context);
            if (! $generatordata) {
                continue;
            }
            $feedbackdata = array_merge(
                $feedbackdata,
                $generatordata
            );
        }

        return $feedbackdata;
    }

    /**
     * Gets feedback generators for teststrategy.
     *
     * @param int $strategyid
     * @return array<feedbackgenerator>
     */
    public function get_feedback_generators_for_teststrategy(int $strategyid): array {
        if (! $attemptstrategy = info::get_teststrategy($strategyid)) {
            return [];
        }

        if (!isset($this->feedbacksettings)) {
            $this->feedbacksettings = new feedbacksettings();
        }
        return $attemptstrategy->get_feedbackgenerators($this->feedbacksettings);
    }

    /**
     * Gets feedback for attempt.
     *
     * @param int $attemptid
     *
     * @return array
     *
     */
    public function get_feedback_for_attempt(int $attemptid): array {
        global $DB;
        $feedbackdata = json_decode(
            $DB->get_field(
                'local_catquiz_attempts',
                'json',
                ['attemptid' => $attemptid]
            ),
            true
        );
        if (empty($feedbackdata)) {
            return [];
        }
        $generators = $this->get_feedback_generators_for_teststrategy($feedbackdata['teststrategy']);
        return $this->generate_feedback($generators, $feedbackdata);
    }

    /**
     * Export for template.
     *
     * @param \renderer_base $output
     * @param boolean $savetodb
     *
     * @return array
     *
     */
    public function export_for_template(\renderer_base $output, $savetodb = true): array {
        return [
            'feedback' => $this->render_strategy_feedback($savetodb),
        ];
    }

    /**
     * Generates feedback.
     *
     * @param array $generators
     * @param array $feedbackdata
     *
     * @return array
     *
     */
    private function generate_feedback(array $generators, array $feedbackdata): array {
        if (!$feedbackdata) {
            return [];
        }
        foreach ($generators as $generator) {
            $feedback = $generator->get_feedback($feedbackdata);
            // Loop over studentfeedback and teacherfeedback.
            foreach ($feedback as $fbtype => $feedback) {
                if (!$feedback) {
                    continue;
                }
                $context[$fbtype][] = $feedback;
            }
        }
        return $context;
    }
}
