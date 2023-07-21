<?php

namespace local_catquiz\teststrategy\preselect_task;

use cache;
use local_catquiz\catcalc;
use local_catquiz\catcontext;
use local_catquiz\catquiz;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_strategy;
use local_catquiz\local\result;
use local_catquiz\local\status;
use local_catquiz\teststrategy\preselect_task;
use local_catquiz\wb_middleware;
use moodle_exception;

/**
 * Update the person ability based on the result of the previous question
 *
 * @package local_catquiz\teststrategy\preselect_task
 */
final class updatepersonability extends preselect_task implements wb_middleware
{
    const UPDATE_THRESHOLD = 0.001;

    public function run(array $context, callable $next): result {
        $lastquestion = $context['lastquestion'];
        // If we do not know the answer to the last question, we do not have to
        // update the person ability. Also, pilot questions should not be used
        // to update a student's ability.
        if ($lastquestion === NULL || !empty($lastquestion->is_pilot)) {
            return $next($context);
        }

        $cache = cache::make('local_catquiz', 'userresponses');
        $cachedresponses = $cache->get('userresponses') ?: [];

        global $USER;
        $responses = catcontext::create_response_from_db($context['contextid']);
        $components = ($responses->as_array())[$USER->id];
        if (count($components) > 1) {
            throw new moodle_exception('User has answers to more than one component.');
        }
        $userresponses = reset($components);
        $cache->set('userresponses', $userresponses);

        // If the last answer was incorrect and the question was excluded due to
        // having only incorrect answers, the response object is the same as in
        // the previous run and we don't have to update the person ability.
        $responses_changed = $this->has_changed($userresponses, $cachedresponses);

        if (!$responses_changed) {
            // Nothing changed since the last question, so we do not need to
            // update the person ability
            return $next($context);
        }

        // We will update the person ability. Select the correct model for each item:
        $model_strategy = new model_strategy($responses);
        $item_param_lists = [];
        foreach (array_keys($model_strategy->get_installed_models()) as $model) {
            $item_param_lists[$model] = model_item_param_list::load_from_db($context['contextid'], $model);
        }
        $item_param_list = $model_strategy->select_item_model($item_param_lists);

        $updated_ability = catcalc::estimate_person_ability($userresponses, $item_param_list);
        catquiz::update_person_param($USER->id, $context['contextid'], $updated_ability);
        if (abs($context['person_ability'] - $updated_ability) < self::UPDATE_THRESHOLD) {
            // If we do have more than the minimum questions, we should return
            if ($context['questionsattempted'] >= $context['minimumquestions']) {
                return result::err(status::ABORT_PERSONABILITY_NOT_CHANGED);
            }
        }

        $context['person_ability'] = $updated_ability;
        return $next($context);
    }

    public function get_required_context_keys(): array {
        return [
            'contextid',
            'lastquestion'
        ];
    }

    private function has_changed(array $newresponses, array $oldresponses, $field = 'fraction'): bool {
        if (count($newresponses) !== count($oldresponses)) {
            return true;
        }

        $diff = array_udiff($newresponses, $oldresponses, function($a, $b) use ($field) {
            if ($a[$field] < $b[$field]) {
                return -1;
            } elseif ($a[$field] > $b[$field]) {
                return 1;
            } else {
                return 0;
            }
        });

        return count($diff) !== 0;
    }
}