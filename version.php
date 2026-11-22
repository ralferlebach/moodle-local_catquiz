<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     local_catquiz
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_catquiz';
// Moodle 4.5 only. The upper bound used to say 500, which declares support for a
// release line this plugin has not been verified against - the CI matrix builds
// MOODLE_405_STABLE, and nothing here has been run on 5.x. Declaring support that
// was never tested is a promise to administrators that the code does not keep.
// Moodle 5.x is a work package of its own.
$plugin->supported = [405, 405];
// Issue #31: "supported" only informs; this keeps the 1.2 line off Moodle 5.0 and later (the 1.3 line is for 5.x).
$plugin->incompatible = 500;
$plugin->release = '1.2.1';
$plugin->version = 2026111711;
$plugin->requires = 2024100700;
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = [
    'local_wunderbyte_table' => 2024040200,
    // Issue #31: what this line needs and is delivered with. Since 17.11.2026 the host with the stop codes
    // scalescomplete and noeligiblebeforeminimum (#172, host #23) and the adapter that hands them over (adapter #12).
    // The CI waits for these versions on the branches of host and adapter (.github/scripts/await_dependencies.sh).
    // Since 19.11.2026 the triple of this delivery: host with the switch of the request timing (host #17),
    // adapter with its callback, the stop matrix and the link-function goldens (adapter #12, host #25).
    'mod_adaptivequiz' => 2026111701,
    'adaptivequizcatmodel_catquiz' => 2026111706,
];
