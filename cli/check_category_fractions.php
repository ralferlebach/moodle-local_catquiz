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
 * Polytomous items and responses that the category mapping before the re-audit "ab #147" (#163) touched. Read-only.
 *
 * Usage: php local/catquiz/cli/check_category_fractions.php [--contextid=1605] [--json]
 * Exit code 2 when an item or a response is concerned. See local_catquiz\local\integrity\category_fraction_check.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['contextid' => 0, 'json' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognised) {
    cli_writeln('Polytomous items (GRM, GGRM, PCM, GPCM) and responses the old category mapping touched. Read-only.');
    cli_writeln('  --contextid=ID   only this CAT context');
    cli_writeln('  --json           JSON instead of text');
    exit($options['help'] ? 0 : 1);
}
$result = \local_catquiz\local\integrity\category_fraction_check::check((int) $options['contextid']);
if ($options['json']) {
    cli_writeln(json_encode($result, JSON_PRETTY_PRINT));
} else {
    cli_writeln("Parameter rows of polytomous models: {$result['parameters']}");
    cli_writeln("  with a category key that rounds down: {$result['keys']} (active: {$result['activekeys']})"
        . ($result['samplekeys'] ? '  ids: ' . implode(', ', $result['samplekeys']) : ''));
    cli_writeln("Items with active polytomous parameters: {$result['items']}");
    cli_writeln("  graded responses whose fraction rounds down: {$result['responses']} in {$result['questions']} questions"
        . ($result['samplequestions'] ? '  question ids: ' . implode(', ', $result['samplequestions']) : ''));
}
exit($result['keys'] + $result['responses'] > 0 ? 2 : 0);
