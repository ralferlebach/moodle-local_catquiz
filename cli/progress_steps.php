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
 * The working state stored as head and events (issue #177): check it, or write it back in the format before.
 *
 * Usage:
 *   php local/catquiz/cli/progress_steps.php --check [--attempt=ID[,ID...]] [--ids]
 *   php local/catquiz/cli/progress_steps.php --rollback [--attempt=ID[,ID...]] [--execute]
 *
 * --check reads only; exit code 1 when a working state is wrong. --rollback shows what it would write; with --execute
 * it writes, and only while the site is in maintenance mode: a running attempt would otherwise go on with a state that
 * counts its events as stored. The way back is for returning to code before #177 - which Moodle installs only as a
 * version with a higher number, or with the database of before.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\local\attempt\progress_steps_maintenance;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['check' => false, 'rollback' => false, 'attempt' => '', 'execute' => false, 'ids' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || $unrecognised || $options['check'] === $options['rollback']) {
    cli_writeln('The working state stored as head and events (local_catquiz #177).');
    cli_writeln('  --check          Checks the stored working states. Reads only; exit code 1 on a wrong state.');
    cli_writeln('  --rollback       Writes them back in the format before #177 and deletes the events.');
    cli_writeln('                   Shows what it would write; --execute writes (maintenance mode only).');
    cli_writeln('  --attempt=ID,..  Only these CAT attempts (local_catquiz_attempts.id).');
    cli_writeln('  --ids            With --check: list the attempts of every finding.');
    exit(($unrecognised || $options['check'] === $options['rollback']) && !$options['help'] ? 1 : 0);
}

$ids = $options['attempt'] === '' ? null : array_map('intval', array_filter(explode(',', (string) $options['attempt'])));

if ($options['check']) {
    $result = progress_steps_maintenance::check($ids);
    foreach ($result['heads'] as $format => $count) {
        cli_writeln(sprintf('Working states in format %-3s %8d', $format < 0 ? '?' : $format, $count));
    }
    $errors = 0;
    foreach ($result['findings'] as $finding => $attempts) {
        $iserror = in_array($finding, progress_steps_maintenance::ERRORS, true);
        $errors += $iserror ? count($attempts) : 0;
        cli_writeln(sprintf('%-28s %8d%s', $finding . ':', count($attempts), $iserror ? '' : '  (reported, not an error)'));
        if ($options['ids'] && $attempts) {
            cli_writeln('    ' . implode(' ', $attempts));
        }
    }
    cli_writeln($errors ? "$errors finding(s)." : 'No wrong working state found.');
    exit($errors ? 1 : 0);
}

$execute = (bool) $options['execute'];
if ($execute && empty($CFG->maintenance_enabled) && !file_exists($CFG->dataroot . '/climaintenance.html')) {
    cli_error('--execute only in maintenance mode (admin/cli/maintenance.php --enable).');
}
$result = progress_steps_maintenance::rollback($ids, $execute);
$before = array_sum(array_column($result['attempts'], 'bytesbefore'));
$after = array_sum(array_column($result['attempts'], 'bytesafter'));
$events = array_sum(array_column($result['attempts'], 'events'));
cli_writeln(sprintf(
    '%s %d working state(s) with %d event(s): %d bytes as head and events, %d bytes in the format before.',
    $execute ? 'Written back:' : 'Would write back:',
    count($result['attempts']),
    $events,
    $before,
    $after
));
foreach ($result['failed'] as $catattemptid => $message) {
    cli_writeln("Not written back, CAT attempt $catattemptid: $message");
}
exit($result['failed'] ? 1 : 0);
