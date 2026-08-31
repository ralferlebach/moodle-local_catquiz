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
 * Seeds a deterministic CAT manager fixture for the Playwright tests.
 *
 * Prints shell "export KEY='value'" lines so it can be sourced locally and parsed
 * by the workflow. Run from the Moodle root:
 *
 *   php local/catquiz/tests/playwright/seed.php
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

global $CFG, $DB;

// The search terms are deliberately nonsense words. A real word could occur in
// Moodle's own sample content, and the test would then pass or fail depending on
// what else happens to be installed rather than on the search itself.
/** @var string A word that appears in exactly one seeded question text. */
const CATQUIZ_SEED_MATCHING_TERM = 'zorblewump';

/** @var string A word that appears in no seeded question text. */
const CATQUIZ_SEED_MISSING_TERM = 'quxnotpresentqux';

/**
 * Prints the shell exports both the fresh and the reused path need.
 *
 * @param string $wwwroot
 * @param int $scaleid
 * @param int $contextid
 * @param int $matchingid
 * @return void
 */
function catquiz_seed_export(string $wwwroot, int $scaleid, int $contextid, int $matchingid): void {
    echo "export CATQUIZ_BASE_URL='" . $wwwroot . "'\n";
    echo "export CATQUIZ_SCALEID='" . $scaleid . "'\n";
    echo "export CATQUIZ_CONTEXTID='" . $contextid . "'\n";
    echo "export CATQUIZ_MATCHING_TERM='" . CATQUIZ_SEED_MATCHING_TERM . "'\n";
    echo "export CATQUIZ_MISSING_TERM='" . CATQUIZ_SEED_MISSING_TERM . "'\n";
    echo "export CATQUIZ_MATCHING_QUESTION='Playwright matching question'\n";
    echo "export CATQUIZ_MATCHING_QUESTIONID='" . $matchingid . "'\n";
    echo "export CATQUIZ_UNUSABLE_QUESTION='Playwright unusable question'\n";
    echo "export CATQUIZ_PILOT_QUESTION='Playwright pilot question'\n";
    echo "export CATQUIZ_ADMIN_USER='admin'\n";
    echo "export CATQUIZ_ADMIN_PASS='Admin!23'\n";
}

$now = time();

// Idempotent: a second run must not build a second scale next to the first. Two
// identically named scales in different contexts is what made earlier browser runs
// fail in ways that looked like application bugs - the tests then pointed at
// whichever of the two the environment happened to name.
// get_record() would warn and pick arbitrarily if an older run left more than one
// scale behind; take the oldest deterministically instead.
$existing = $DB->get_records('local_catquiz_catscales', ['name' => 'Playwright scale'], 'id ASC', '*', 0, 1);
$existing = $existing ? reset($existing) : false;
if ($existing) {
    $contextid = (int) $existing->contextid;
    $scaleid = (int) $existing->id;
    catquiz_seed_export($CFG->wwwroot, $scaleid, $contextid, (int) $DB->get_field_sql(
        "SELECT MIN(componentid) FROM {local_catquiz_items} WHERE catscaleid = :id",
        ['id' => $scaleid]
    ));
    exit(0);
}

$contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
    'name' => 'Playwright context',
    'description' => '',
    'descriptionformat' => FORMAT_HTML,
    'starttimestamp' => $now - 86400,
    'endtimestamp' => $now + 86400,
    'timecreated' => $now,
    'timemodified' => $now,
    'usermodified' => 0,
]);

$scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
    'parentid' => 0,
    'name' => 'Playwright scale',
    'contextid' => $contextid,
    'timecreated' => $now,
    'timemodified' => $now,
]);

// A question category is needed so the question bank joins resolve.
$systemcontext = context_system::instance();
$categoryid = (int) $DB->insert_record('question_categories', (object) [
    'name' => 'Playwright category',
    'contextid' => $systemcontext->id,
    'info' => '',
    'infoformat' => FORMAT_HTML,
    'stamp' => make_unique_id_code(),
    'parent' => 0,
    'sortorder' => 0,
]);

/**
 * Creates a question with its bank entry, version and CAT item.
 *
 * @param string $name
 * @param string $text
 * @param int $categoryid
 * @param int $scaleid
 * @param int $contextid
 * @param ?array $param
 * @return int The question id.
 */
function catquiz_seed_question(
    string $name,
    string $text,
    int $categoryid,
    int $scaleid,
    int $contextid,
    ?array $param = ['model' => 'rasch', 'difficulty' => 0.5]
): int {
    global $DB;

    $now = time();

    $questionid = (int) $DB->insert_record('question', (object) [
        'name' => $name,
        'questiontext' => $text,
        'questiontextformat' => FORMAT_HTML,
        'qtype' => 'truefalse',
        'generalfeedback' => '',
        'generalfeedbackformat' => FORMAT_HTML,
        'timecreated' => $now,
        'timemodified' => $now,
        'createdby' => 2,
        'modifiedby' => 2,
    ]);

    $entryid = (int) $DB->insert_record('question_bank_entries', (object) [
        'questioncategoryid' => $categoryid,
        'idnumber' => null,
        'ownerid' => 2,
    ]);
    $DB->insert_record('question_versions', (object) [
        'questionbankentryid' => $entryid,
        'version' => 1,
        'questionid' => $questionid,
        'status' => 'ready',
    ]);

    $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
        'componentid' => $questionid,
        'componentname' => 'question',
        'catscaleid' => $scaleid,
        'contextid' => $contextid,
        'activeparamid' => 0,
        'status' => 0,
    ]);

    // No parameter at all: a classic pilot item.
    if ($param === null) {
        return $questionid;
    }

    $record = (object) array_merge([
        'itemid' => $itemid,
        'componentname' => 'question',
        'contextid' => $contextid,
        'status' => 4,
        'timecreated' => $now,
        'timemodified' => $now,
    ], $param);

    // Stamp through the same helper the plugin uses, so the fixture carries the
    // usability flag the application would have written.
    \local_catquiz\local\itemparam_validity::stamp($record);

    $paramid = (int) $DB->insert_record('local_catquiz_itemparams', $record);
    $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);

    return $questionid;
}

$matchingid = catquiz_seed_question(
    'Playwright matching question',
    '<p>This question body contains ' . CATQUIZ_SEED_MATCHING_TERM . ' as its marker.</p>',
    $categoryid,
    $scaleid,
    $contextid
);

// Two further questions without the marker, so a successful search has to reduce
// the list rather than simply return everything.
catquiz_seed_question(
    'Playwright other question one',
    '<p>An unrelated body.</p>',
    $categoryid,
    $scaleid,
    $contextid
);
catquiz_seed_question(
    'Playwright other question two',
    '<p>Another unrelated body.</p>',
    $categoryid,
    $scaleid,
    $contextid
);

// Issue #54: an item whose parameters exist but violate the contract of their
// model. A 2PL item with discrimination 0 is mathematically mute, so it is played
// as a pilot item - exactly the state the backend column has to make visible.
$unusableid = catquiz_seed_question(
    'Playwright unusable question',
    '<p>Parameters exist but cannot be used.</p>',
    $categoryid,
    $scaleid,
    $contextid,
    ['model' => 'raschbirnbaum', 'difficulty' => 0.5, 'discrimination' => 0.0]
);

// An item in piloting: registered for the scale, but without any active parameter.
$pilotid = catquiz_seed_question(
    'Playwright pilot question',
    '<p>No parameters at all.</p>',
    $categoryid,
    $scaleid,
    $contextid,
    null
);

catquiz_seed_export($CFG->wwwroot, $scaleid, $contextid, $matchingid);
