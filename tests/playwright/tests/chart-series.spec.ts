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
 * Engine #150 in the browser (re-audit "ab #147", 19.11.2026): the charts of the statistics and of the feedback of a
 * finished attempt, with the item pool full, empty (every item parameter gone) and half (every second one gone), and
 * full again - the situation of the report: results exist, the pool has changed since.
 *
 * Every chart is drawn - a canvas with its data table - or, with nothing to draw, the page says so; never an empty
 * chart area, never "Invalid values received for series." or another console error. The data of every chart the
 * browser received (inline and loaded with a tab) is kept per state, reduced to its shape: type, number of labels,
 * per series its name, number of values and of empty values - no person, no value. The comparison of the states is
 * the counter-check with the full pool.
 *
 * Runs after cat-attempt-path.spec.ts; the item parameters are back at the end, whatever happens.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {expect, Locator, Page, test} from '@playwright/test';
import {catpath, helper, keep, loginAdmin, watchErrors} from '../support/catpath';
import {openFeedback, results} from '../support/results';

test.describe.configure({mode: 'serial'});

/** The shape of the data of a chart: no person, no value. */
interface Shape {
    type: string;
    labels: number;
    series: Array<{label: string; values: number; empty: number}>;
}

/**
 * The data of every chart in a piece of HTML or JavaScript: local_catquiz/chart gets it as "var data = {...},".
 *
 * @param text
 */
const shapes = (text: string): Shape[] => {
    const out: Shape[] = [];
    for (const match of text.matchAll(/var data = (\{.*?\}),\s*uniqid/gs)) {
        try {
            const data = JSON.parse(match[1]);
            out.push({
                type: String(data.type),
                labels: (data.labels || []).length,
                series: (data.series || []).map((s: any) => ({
                    label: String(s.label),
                    values: (s.values || []).length,
                    empty: (s.values || []).filter((v: unknown) => v === null || v === '').length,
                })),
            });
        } catch (e) {
            out.push({type: 'unparsable', labels: -1, series: []});
        }
    }
    return out;
};

/** Every visible chart area in the scope is drawn or says why not. */
const expectDrawn = async(scope: Locator): Promise<{drawn: number; message: number; notice: number}> => {
    const areas = scope.locator('.chart-area');
    let drawn = 0;
    let message = 0;
    for (let i = 0; i < await areas.count(); i++) {
        const area = areas.nth(i);
        if (!(await area.isVisible())) {
            continue;
        }
        const canvas = area.locator('canvas');
        const refused = area.getByText(/could not be drawn/);
        await expect(canvas.or(refused).first(), 'a chart or its message, never an empty area').toBeVisible({timeout: 30_000});
        if (await canvas.count()) {
            drawn++;
        } else {
            message++;
        }
    }
    const nodata = new RegExp('No attempt data available|There are no valid results yet|No ability scores were calculated'
        + '|No item difficulties');
    const notice = await scope.getByText(nodata).count();
    return {drawn, message, notice};
};

/** Opens every tab of a statistics block or a feedback page and checks its charts. */
const everyTab = async(page: Page, scope: Locator): Promise<Record<string, any>> => {
    const out: Record<string, any> = {};
    const tabs = scope.locator('a[role="tab"]');
    for (let i = 0; i < await tabs.count(); i++) {
        const tab = tabs.nth(i);
        const name = ((await tab.innerText()) || `tab ${i}`).trim();
        await tab.click();
        const href = (await tab.getAttribute('href')) || '';
        const pane = href.startsWith('#') ? page.locator(href) : scope;
        await expect(pane).toBeVisible({timeout: 30_000});
        await expect(
            pane.locator('[data-region^="local_catquiz-lazy"]:not([data-state="loaded"]):not([data-state="failed"])'),
            `${name}: loaded`
        ).toHaveCount(0, {timeout: 60_000});
        const failed = pane.locator('[data-region^="local_catquiz-lazy"][data-state="failed"]');
        await expect(failed, `${name}: not failed`).toHaveCount(0);
        out[name] = await expectDrawn(pane);
    }
    return out;
};

test.describe('Charts with a changed item pool (#150) @charts', () => {
    test('statistics and feedback: full, empty, half, full again', async({page}, testInfo) => {
        test.setTimeout(900_000);
        const errors = watchErrors(page);
        const received: string[] = [];
        page.on('response', async(response) => {
            if (response.url().includes('/lib/ajax/service.php') && /render_(statistics_chart|feedback_tab)/.test(response.url())) {
                received.push(await response.text().catch(() => ''));
            }
        });
        const attempt = Number(catpath('STATS_ATTEMPT_A'));
        const contextid = results('--context-of', `--attemptid=${attempt}`).contextid;
        await loginAdmin(page);

        const states: Record<string, any> = {};
        const visit = async(state: string): Promise<void> => {
            received.length = 0;
            const response = await page.goto(`/mod/page/view.php?id=${catpath('PAGE_CMID')}`);
            const inline = shapes(await response!.text());
            const statistics = await everyTab(page, page.locator('[data-catpath="three"]'));
            expect(await openFeedback(page, attempt, contextid)).toBe(200);
            const feedback = await everyTab(page, page.locator('#region-main'));
            const loaded = received.flatMap((body) => shapes(body.replace(/\\"/g, '"').replace(/\\n/g, '\n')));
            const all = [...inline, ...loaded];
            for (const chart of all) {
                expect(chart.type, `${state}: chart data that parse`).not.toBe('unparsable');
                for (const series of chart.series) {
                    // What core/chart_series refuses: a series without values, or not one value per label.
                    expect(series.values, `${state}: ${series.label} has values`).toBeGreaterThan(0);
                    expect(series.values, `${state}: ${series.label}, one value per label`).toBe(chart.labels);
                }
            }
            // The lazy charts answer in any order: compared as a set.
            const sorted = all.map((chart) => JSON.stringify(chart)).sort().map((chart) => JSON.parse(chart));
            states[state] = {statistics, feedback, charts: sorted};
        };

        try {
            await visit('full');
            helper('--break-items');
            await visit('empty');
            helper('--restore-items');
            results('--break-half');
            await visit('half');
        } finally {
            results('--restore-half');
            helper('--restore-items');
        }
        await visit('full again');
        await keep(testInfo, 'chart-series-pool-states', states);

        // The counter-check: with the full pool the charts are drawn; after it, the same as before.
        const drawn = (state: string) => Object.values({...states[state].statistics, ...states[state].feedback})
            .reduce((sum: number, tab: any) => sum + tab.drawn, 0);
        expect(drawn('full'), 'the full pool draws charts').toBeGreaterThan(0);
        expect(states['full again'], 'the full pool again: the same charts').toEqual(states.full);
        expect(errors.filter((e) => /Invalid values received for series/.test(e)), 'core never refused a series').toEqual([]);
        expect(errors).toEqual([]);
    });
});
