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
use local_catquiz\teststrategy\feedbackgenerator;
use local_catquiz\teststrategy\info;
use templatable;
use renderable;

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
    public int $teststrategy;

    /**
     * Constructor of class.
     *
     * @param int $attemptid
     * @param int $contextid
     * @param int $catscaleid
     *
     */
    public function __construct(int $attemptid, int $contextid = 0, int $catscaleid = 0) {
        global $USER;
        if ($attemptid === 0) {
            // This can still return nothing. In that case, we show a message that the user has no attempts yet.
            if (!$attemptid = catquiz::get_last_user_attemptid($USER->id)) {
                return;
            }
        }
        $this->attemptid = $attemptid;

        if (!$testenvironment = catquiz::get_testenvironment_by_attemptid($attemptid)) {
            return;
        }

        $settings = json_decode($testenvironment->json);
        $this->teststrategy = intval($settings->catquiz_selectteststrategy);

        if ($contextid === 0) {
            // Get the contextid from the attempt.
            $contextid = intval($settings->catquiz_catcontext);
        }
        $this->contextid = $contextid;

        if ($catscaleid === 0) {
            $catscaleid = intval($settings->catquiz_catscales);
        }
        $this->catscaleid = $catscaleid;
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

        $generators = $this->get_feedback_generators_for_teststrategy($this->teststrategy);

        // Todo: Depending on the strategy, we should also sort the data differently.
        // Eg: Infer lowest skillgap should order the lowest person ability in a subscale on top.
        // While highest skill should sort the highest ability on top. etc.
        // Right now, we just sort for lowest skill.

        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $context = [
            'attemptid' => $this->attemptid,
            'contextid' => $this->contextid,
            'needsimprovementthreshold' => 0, // TODO: Get the quantile threshold from the quiz settings.
            'userid' => $USER->id,
            'catscaleid' => $this->catscaleid,
            'teststrategy' => $this->teststrategy,
            'starttime' => $cache->get('starttime'),
            'endtime' => $cache->get('endtime'),
            'total_number_of_testitems' => $cache->get('totalnumberoftestitems'),
            'number_of_testitems_used' => empty($cache->get('playedquestions')) ? 0 : count($cache->get('playedquestions')),
            'ability_before_attempt' => $cache->get('abilitybeforeattempt'),
            'studentfeedback' => [],
            'teacherfeedback' => [],
            'quizsettings' => $cache->get('quizsettings'),
            'personabilities' => $cache->get('personabilities'),
        ];

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
        if ($savetodb) {
            $id = catquiz::save_attempt_to_db($feedbackdata);
        }
        return $this->generate_feedback($generators, $feedbackdata);
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

        $generators = array_map(
            fn ($classname) => new $classname(),
            $attemptstrategy->get_feedbackgenerators());

        return $generators;
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

        // At this point, we might want to resort data, depending on the teststrategy.
        // Todo: Give this task to the teststrategy classes.

        switch ($feedbackdata["teststrategy"]) {
            case STRATEGY_LOWESTSUB:
                sort($feedbackdata["personabilities"]);
                usort($feedbackdata["feedback_personabilities"], fn($a, $b) => $a['ability'] > $b['ability'] ? 1 : -1);
                break;
            case STRATEGY_HIGHESTSUB:
                rsort($feedbackdata["personabilities"]);
                usort($feedbackdata["feedback_personabilities"], fn($a, $b) => $a['ability'] < $b['ability'] ? 1 : -1);
                break;
            default:
                sort($feedbackdata["personabilities"]);
                usort($feedbackdata["feedback_personabilities"], fn($a, $b) => $a['ability'] > $b['ability'] ? 1 : -1);
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
