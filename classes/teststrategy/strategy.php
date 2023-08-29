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
 * Abstract class strategy.
 *
 * @package local_catquiz
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy;

use cache;
use local_catquiz\catquiz;
use local_catquiz\catscale;
use local_catquiz\local\result;
use local_catquiz\teststrategy\info;
use local_catquiz\teststrategy\preselect_task;
use local_catquiz\wb_middleware_runner;
use moodle_exception;
use stdClass;

/**
 * Base class for test strategies.
 *
 * @package local_catquiz
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class strategy {

    /**
     *
     * @var int $id // strategy id defined in lib.
     */
    public int $id = 0; // Administrativ.

    /**
     *
     * @var int $id scaleid.
     */
    public int $scaleid;

    /**
     *
     * @var int $catcontextid
     */
    public int $catcontextid;

    /**
     * @var array<preselect_task>
     */
    public array $scoremodifiers;

    /**
     * Instantioate parameters.
     */
    public function __construct() {
        $this->scoremodifiers = info::get_score_modifiers();
    }

    /**
     * Returns an array of score modifier classes
     *
     * The classes will be called in the given order to calculate the score of a question
     *
     * @return array
     */
    abstract public function requires_score_modifiers(): array;

    /**
     * Returns the translated description of this strategy
     *
     * @return string
     */
    public function get_description(): string {

        $classname = get_class($this);

        $parts = explode('\\', $classname);
        $classname = array_pop($parts);
        return get_string($classname, 'local_catquiz');
    }

    /**
     * Strategy specific way of returning the next testitem.
     *
     * @param array $context
     *
     * @return mixed
     *
     */
    public function return_next_testitem(array $context) {
        $now = time();

        foreach ($this->requires_score_modifiers() as $modifier) {
            if (!array_key_exists($modifier, $this->scoremodifiers)) {
                throw new moodle_exception(
                    sprintf(
                        'Strategy requires a score modifier that is not available: %s',
                        $modifier
                    )
                );
            }
            $middlewares[] = $this->scoremodifiers[$modifier];
        }

        $result = wb_middleware_runner::run($middlewares, $context);

        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        if ($result->isErr()) {
            $cache->set('stopreason', $result->get_status());
            return $result;
        }

        $selectedquestion = $result->unwrap();
        if (!$selectedquestion) {
            return result::err();
        }

        $selectedquestion->lastattempttime = $now;
        $selectedquestion->userlastattempttime = $now;

        // Keep track of which question was selected.
        $playedquestions = $cache->get('playedquestions') ?: [];
        $playedquestions[$selectedquestion->id] = $selectedquestion;
        $cache->set('playedquestions', $playedquestions);
        $cache->set('isfirstquestionofattempt', false);

        if (! empty($selectedquestion->is_pilot)) {
            $numpilotquestions = $cache->get('num_pilot_questions') ?: 0;
            $cache->set('num_pilot_questions', ++$numpilotquestions);
        }

        // Keep track of the questions played per scale
        $playedquestionsperscale = $cache->get('playedquestionsperscale') ?: [];
        $updated = $this->update_playedquestionsperscale($selectedquestion, $playedquestionsperscale);
        $cache->set('playedquestionsperscale', $updated);

        $cache->set('lastquestion', $selectedquestion);


        catscale::update_testitem(
            $context['contextid'],
            $selectedquestion,
            $context['includesubscales']
        );
        return result::ok($selectedquestion);
    }

    /**
     * Retrieves all the available testitems from the current scale.
     *
     * @param int  $catscaleid
     * @param bool $includesubscales
     * @return array
     */
    public function get_all_available_testitems(int $catscaleid, bool $includesubscales = false):array {

        $catscale = new catscale($catscaleid);

        return $catscale->get_testitems($this->catcontextid, $includesubscales);

    }

    /**
     * Set catscale id.
     * @param int $scaleid
     * @return self
     */
    public function set_scale(int $scaleid) {
        $this->scaleid = $scaleid;
        return $this;
    }

    /**
     * Set the CAT context id
     * @param int $catcontextid
     * @return $this
     */
    public function set_catcontextid(int $catcontextid) {
        $this->catcontextid = $catcontextid;
        return $this;
    }

    /**
     * Provide feedback about the last quiz attempt
     *
     * @return array<string>
     */
    public static function attempt_feedback(int $contextid): array {
        $feedback = [];

        // Summary: performance in this attempt compared to other students.
        $feedback[] = self::compare_user_to_test_average($contextid);

        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        if ($stopreason = $cache->get('stopreason')) {
            $feedback[] = sprintf(
                "%s: %s",
                get_string('attemptstopcriteria', 'mod_adaptivequiz'),
                get_string($stopreason, 'local_catquiz')
            );
        }

        $quizsettings = $cache->get('quizsettings');
        $personabilities = $cache->get('personabilities') ?: [];
        if ($scalefeedback = self::feedbackforscales($quizsettings, $personabilities)) {
            $feedback[] = $scalefeedback;
        }

        return $feedback;
    }

    public function update_playedquestionsperscale(
        stdClass $selectedquestion,
        array $playedquestionsperscale = []
    ): array {
        if (!array_key_exists($selectedquestion->catscaleid, $playedquestionsperscale)) {
            $playedquestionsperscale[$selectedquestion->catscaleid] = [];
        }
        $playedquestionsperscale[$selectedquestion->catscaleid][] = $selectedquestion;
        return $playedquestionsperscale;
    }

    /**
     * Returns an array with catscale feedback strings indexed by the catscale ID.
     * 
     * @param mixed $quizsettings 
     * @param mixed $personabilities 
     * @return array 
     */
    private static function feedbackforscales($quizsettings, $personabilities): string {
        $scalefeedback = [];
        foreach ($personabilities as $catscaleid => $personability) {
            $lowerlimitprop = sprintf('feedback_scaleid_%d_lowerlimit', $catscaleid);
            $lowerlimit = floatval($quizsettings->$lowerlimitprop);
            if ($personability >= $lowerlimit) {
                continue;
            }

            $feedbackprop = sprintf('feedback_scaleid_%d_feedback', $catscaleid);
            $feedback = $quizsettings->$feedbackprop;
            // Do not display empty feedback messages.
            if (!$feedback) {
                continue;
            }

            $scalefeedback[$catscaleid] = $feedback;
        }
        
        if (! $scalefeedback) {
            return "";
        }

        $catscales = catquiz::get_catscales(array_keys($scalefeedback));
        $result = "";
        foreach ($catscales as $cs) {
            $result .= $cs->name . ': ' . $scalefeedback[$cs->id] . '<br/>';
        }
        return $result;
    }

    private static function compare_user_to_test_average(int $contextid): string {
        global $USER;
        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $quizsettings = $cache->get('quizsettings');
        if (! $catscaleid = $quizsettings->catquiz_catcatscales) {
            return '';
        }

        $abilities = $cache->get('personabilities');
        if (! $abilities) {
            return '';
        }
        $ability = $abilities[$catscaleid];
        if (! $ability) {
            return '';
        }

        $personparams = catquiz::get_person_abilities($contextid, array_keys($abilities));
        $worseabilities = array_filter(
            $personparams,
            fn ($pp) => $pp->ability < $ability
        );

        if (!$worseabilities) {
            return '';
        }

        $quantile = (count($worseabilities)/count($personparams)) * 100;
        $feedback = get_string('feedbackcomparetoaverage', 'local_catquiz', $quantile);
        $needsimprovementthreshold = 40; // TODO: do not hardcode.
        if ($quantile < $needsimprovementthreshold) {
            $feedback .= " " . get_string('feedbackneedsimprovement', 'local_catquiz');
        }
        return $feedback;
    }
}
