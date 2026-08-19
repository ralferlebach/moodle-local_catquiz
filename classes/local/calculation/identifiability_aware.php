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

namespace local_catquiz\local\calculation;

/**
 * Writes the aggregate item identifiability report (K5) into a calculation result.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait identifiability_aware {
    /**
     * Populate the result's after-criteria and warnings from the identifiability summary.
     *
     * @param calculation_result $result
     * @param array|null $identifiability aggregate from catmodel_info::update_params
     * @return void
     */
    protected function apply_identifiability(calculation_result $result, ?array $identifiability): void {
        if (empty($identifiability)) {
            return;
        }
        $result->set('criteriaafter', [
            'itemstotal' => $identifiability['total'] ?? 0,
            'wellidentified' => $identifiability['wellidentified'] ?? 0,
            'weaklyidentified' => $identifiability['weaklyidentified'] ?? 0,
            'atbound' => $identifiability['atbound'] ?? 0,
        ]);
        foreach (($identifiability['warnings'] ?? []) as $warning) {
            $result->add_warning($warning);
        }
    }
}
