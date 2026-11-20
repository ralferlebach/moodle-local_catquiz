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
 * What the seeds of the browser and load tests (playwright/seed.php, playwright/seed_cat_path.php,
 * load/seed_attempt_load.php) share about the question bank of the site.
 *
 * Moodle 5.0+ keeps questions in qbank activities only. After an install, ad-hoc tasks move the old banks there; a
 * category they find at the top of the system context without children is deleted with it, its questions are left
 * without a category. A fresh CI site runs no cron, so the seeds run these tasks themselves - before they create
 * anything - and put their questions where the version of Moodle keeps them: under the top category of the site's
 * system bank (5.x) or of the system context (4.5). Re-audit 19.11.2026: the CAT manager seed had made its category
 * a top category of the system context; on 5.1 the migration of the second seed removed it and the CAT manager
 * found no questions - a fault of the seed, not of the plugin (real banks are moved, not deleted).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Runs the question bank migration of Moodle 5.0+ if it is pending, and fails when it did not complete. Moodle 4.5
 * has no such tasks: nothing happens.
 *
 * @return void
 */
function local_catquiz_seed_run_qbank_migration(): void {
    global $CFG;
    $defaultbank = $CFG->corequestion_defaultqbankmod ?? 'qbank';
    // The tasks report through mtrace(), which writes to STDOUT past any output buffer; the seeds print the shell
    // variables of the run there. A wrapper keeps their report out of it.
    $wrapper = $CFG->mtrace_wrapper ?? null;
    $CFG->mtrace_wrapper = 'local_catquiz_seed_mtrace_quiet';
    try {
        foreach (['transfer_question_categories', 'transfer_questions'] as $taskname) {
            $classname = "\\mod_{$defaultbank}\\task\\{$taskname}";
            if (class_exists($classname) && \core\task\manager::get_adhoc_tasks($classname)) {
                ob_start();
                \core\cron::run_adhoc_tasks(time(), 0, false, null, null, $classname);
                ob_end_clean();
            }
        }
    } finally {
        if ($wrapper === null) {
            unset($CFG->mtrace_wrapper);
        } else {
            $CFG->mtrace_wrapper = $wrapper;
        }
    }
    $bankhelper = \core_question\local\bank\question_bank_helper::class;
    if (
        class_exists($bankhelper) && method_exists($bankhelper, 'has_bank_migration_task_completed_successfully')
        && !$bankhelper::has_bank_migration_task_completed_successfully()
    ) {
        cli_error('The question bank migration tasks did not complete; no activity would be attemptable.');
    }
}

/**
 * Swallows what a task reports (see local_catquiz_seed_run_qbank_migration()).
 *
 * @param mixed $string
 * @param mixed $eol
 * @return void
 */
function local_catquiz_seed_mtrace_quiet($string, $eol): void {
}

/**
 * A question category the version of Moodle keeps questions in: a child of the top category of the site's system
 * bank (Moodle 5.x) or of the system context (Moodle 4.5).
 *
 * @param string $name
 * @return int The id of the category.
 */
function local_catquiz_seed_question_category(string $name): int {
    global $DB;
    $bankhelper = \core_question\local\bank\question_bank_helper::class;
    if (class_exists($bankhelper) && method_exists($bankhelper, 'get_default_open_instance_system_type')) {
        $bank = $bankhelper::get_default_open_instance_system_type(get_site(), true);
        $context = $bank->context;
    } else {
        $context = context_system::instance();
    }
    $top = question_get_top_category($context->id, true);
    return (int) $DB->insert_record('question_categories', (object) [
        'name' => $name,
        'contextid' => $context->id,
        'info' => '',
        'infoformat' => FORMAT_HTML,
        'stamp' => make_unique_id_code(),
        'parent' => $top->id,
        'sortorder' => 999,
    ]);
}
