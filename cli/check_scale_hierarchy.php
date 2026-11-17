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
 * Lists cycles in the scale hierarchy (issue #169).
 *
 * Read-only. A scale in a cycle makes every walk through the hierarchy fail ("scalecycle"); the fix is to give one
 * scale of the cycle another parent, in the scale management or in the database - a deliberate step, not done here.
 *
 * Usage: php local/catquiz/cli/check_scale_hierarchy.php
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\catscale;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognised) {
    cli_writeln('Lists cycles in the scale hierarchy. Read-only.');
    exit($unrecognised ? 1 : 0);
}

$cycles = catscale::find_cycles();
foreach ($cycles as $cycle) {
    cli_writeln('Cycle: scale ' . implode(' -> parent ', $cycle) . ' -> parent ' . $cycle[0]);
}
cli_writeln($cycles ? count($cycles) . ' cycle(s) found.' : 'No cycles found.');
exit($cycles ? 2 : 0);
