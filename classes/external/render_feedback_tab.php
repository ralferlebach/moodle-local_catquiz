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

namespace local_catquiz\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_catquiz\local\access\question_review;
use local_catquiz\output\attemptfeedback;
use local_catquiz\teststrategy\feedbackgenerator;

/**
 * Renders one feedback tab of an attempt when it is opened (issue #85).
 *
 * The result page builds the main feedback and offers the other tabs empty; this fills one of them.
 * Access as for the page: the owner, or a user who may see the feedback of others; teacher tabs
 * need the teacher permission. A finished attempt is reviewed in the course, so a hidden activity
 * does not block it.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render_feedback_tab extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Id of the attempt: adaptivequiz_attempt.id'),
            'generatorname' => new external_value(PARAM_ALPHANUMEXT, 'Name of the feedback generator'),
            'audience' => new external_value(PARAM_ALPHA, 'student or teacher'),
        ]);
    }

    /**
     * Renders the tab.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id.
     * @param string $generatorname
     * @param string $audience
     * @return array
     */
    public static function execute(int $adaptiveattemptid, string $generatorname, string $audience): array {
        global $DB, $OUTPUT, $PAGE, $USER;

        [
            'attemptid' => $adaptiveattemptid,
            'generatorname' => $generatorname,
            'audience' => $audience,
        ] = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $adaptiveattemptid,
            'generatorname' => $generatorname,
            'audience' => $audience,
        ]);

        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $adaptiveattemptid], '*', MUST_EXIST);
        self::validate_context(question_review::login_context($attempt));
        $cm = get_coursemodule_from_instance('adaptivequiz', $attempt->instance, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $PAGE->set_context($context);

        if ((int) $attempt->userid !== (int) $USER->id) {
            require_capability('local/catquiz:view_users_feedback', $context);
        }
        if ($audience === feedbackgenerator::AUDIENCE_TEACHER) {
            require_capability('local/catquiz:view_teacher_feedback', $context);
        } else if ($audience !== feedbackgenerator::AUDIENCE_STUDENT) {
            throw new \invalid_parameter_exception('audience');
        }

        // Charts add their JavaScript to the page requirements; collect it for the client to run.
        $PAGE->set_pagelayout('embedded');
        $OUTPUT->doctype();
        $PAGE->start_collecting_javascript_requirements();
        $feedback = (new attemptfeedback($adaptiveattemptid))->get_single_feedback($generatorname, $audience);
        $javascript = $PAGE->requires->get_end_code();

        return [
            'html' => $feedback['content'] ?? get_string('attemptfeedbacknotavailable', 'local_catquiz'),
            'javascript' => $feedback ? $javascript : '',
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'The rendered content of the tab'),
            'javascript' => new external_value(PARAM_RAW, 'JavaScript the content needs'),
        ]);
    }
}
