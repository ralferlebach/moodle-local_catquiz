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
// Moodle 5.1 to 5.3. The declaration is backed by what has actually been run: the
// plugin installs on 5.3 and its PHPUnit suite passes there, and the CI matrix
// builds 5.1, 5.2 and 5.3. The browser side - Bootstrap 5 markup, core modals,
// the public webroot - is not verified yet; narrow this again if that work stalls.
$plugin->supported = [501, 503];
$plugin->release = '1.3.0';
$plugin->version = 2026102402;
$plugin->requires = 2025100600;
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = [
    'local_wunderbyte_table' => 2024040200,
    // Issue #31: what this line needs and is delivered with - the host with the stop reasons and the
    // reason of a refused start (#60), the adapter with the attempt ids and the timeline (#146, adapter #6).
    'mod_adaptivequiz' => 2026101801,
    'adaptivequizcatmodel_catquiz' => 2026101801,
];
