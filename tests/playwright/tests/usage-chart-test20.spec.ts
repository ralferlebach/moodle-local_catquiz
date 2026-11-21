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
 * Engine #160 in the browser (re-audit "ab #147", 19.11.2026): test 20 of the export of 27.10.2026 - 17 persons, one
 * attempt each under "Infer lowest skill gap", no valid measurement of the root scale, 13 with a valid subscale.
 *
 * The decision of 16.11.2026: the usage charts of the root scale are coloured by the valid measurement of the root
 * scale. All 17 attempts are grey, "No valid measurement of this scale", and the note under each chart says that a
 * valid result on another scale is possible. Counted in the data table of each chart, as a screen reader reads it.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {expect, test} from '@playwright/test';
import {keep, loginAdmin, watchErrors} from '../support/catpath';
import {results, scriptsReady} from '../support/results';

/**
 * The data table of every chart in a pane: series name => sum of its values. core/chart_output_htmltable builds the
 * table from rows alone (no thead, no tbody): the first row has an empty cell and a th per series (scope col), every
 * other row a th for its label (scope row) and a td per series. The row "No attempts" is left out.
 */
const seriesSums = (pane: HTMLElement): Array<Record<string, number>> =>
    Array.from(pane.querySelectorAll('.chart-area table')).map((table) => {
        const [head, ...rows] = Array.from(table.querySelectorAll('tr'))
            .map((row) => Array.from(row.querySelectorAll('th, td')).map((cell) => (cell.textContent || '').trim()));
        const sums: Record<string, number> = {};
        for (const cells of rows) {
            // The people of the courses without an attempt in the selection are a row of their own in the chart of
            // the attempts per person: they made no attempt to colour.
            if (cells[0] === 'No attempts') {
                continue;
            }
            cells.slice(1).forEach((value, i) => {
                const name = (head || [])[i + 1] || `series ${i + 1}`;
                sums[name] = (sums[name] || 0) + (Number(value.replace(',', '.')) || 0);
            });
        }
        return sums;
    });

test.describe('Usage charts of test 20 (#160) @statistics', () => {
    test('all 17 attempts grey on the root scale, with the note', async({page}, testInfo) => {
        const errors = watchErrors(page);
        const data = results('--test20');
        await loginAdmin(page);
        await page.goto(`/mod/page/view.php?id=${data.page}`);
        const block = page.locator('[data-catpath="test20"]');
        // The tab loads its charts once the scripts of the page have bound it (see scriptsReady).
        await scriptsReady(page);
        await block.locator('a[id$="-testusage-tab"]').first().click();
        const pane = block.locator('div.tab-pane[id$="-testusage"]').first();
        await expect(pane).toBeVisible({timeout: 30_000});
        await expect(pane.locator('.chart-area canvas').first()).toBeVisible({timeout: 30_000});
        await expect(pane.locator('.chart-area table').first()).toBeAttached({timeout: 30_000});

        const charts = await pane.evaluate(seriesSums);
        const tables = await pane.locator('.chart-area table').evaluateAll((all) => all.map((table) =>
            Array.from(table.querySelectorAll('tr')).map((row) =>
                Array.from(row.querySelectorAll('th, td')).map((cell) => (cell.textContent || '').trim()))));
        const text = (await pane.innerText()).replace(/\s+/g, ' ');
        await keep(testInfo, 'test20-usage-charts', {data, charts, tables, text: text.slice(0, 3000)});

        // The two usage charts: attempts per range of time and attempts per person.
        const usage = charts.filter((sums) => 'No valid measurement of this scale' in sums);
        expect(usage.length, 'both usage charts have the grey series').toBeGreaterThanOrEqual(2);
        for (const sums of usage) {
            expect(sums['No valid measurement of this scale'], 'all 17 attempts grey').toBe(data.persons);
            const others = Object.entries(sums).filter(([name]) => name !== 'No valid measurement of this scale');
            expect(others.length, 'the ranges of the scale as series').toBeGreaterThan(0);
            for (const [name, sum] of others) {
                expect(sum, `${name}: no attempt coloured by a subscale`).toBe(0);
            }
        }
        // The legend and the note under the charts.
        await expect(pane.getByText('No valid measurement of this scale').first()).toBeAttached();
        expect(text).toContain('Coloured by the valid measurement of this scale');
        expect(text).toContain('it may have a valid result on another scale');
        expect(errors).toEqual([]);
    });
});
