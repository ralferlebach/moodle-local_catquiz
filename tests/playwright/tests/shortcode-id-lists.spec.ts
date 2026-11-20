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
 * Statistics shortcodes over lists of tests and courses in a real browser (re-audit 19.11.2026, Playwright P0;
 * engine #175, #150, #173).
 *
 * A page with [catquizstatistics] over 1, 3 and 25 CAT tests of course A, over 100 tests of both courses, over both
 * courses and over the tests of course B only: as an administrator every block renders, its lazy charts load, its CSV holds the attempts of
 * its tests, and a reload shows the same; as the teacher of one course a block that names a test or course of the
 * other shows nothing at all (fails closed), the blocks of the own course render. The debug PDF of an attempt for
 * the manager, refused for a teacher. Axe finds no violation in a block (P1 accessibility). No console error, no
 * HTTP error; the page on a phone.
 *
 * Seed: seed.php (administrator) and seed_cat_path.php.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import AxeBuilder from '@axe-core/playwright';
import {expect, Page, test} from '@playwright/test';
import {catpath, keep, loginAdmin, loginAs, watchErrors, WCAG} from '../support/catpath';

const BLOCKS = ['one', 'three', 'twentyfive', 'hundred', 'courses', 'onlyb'];

/**
 * The block of the page with the given key.
 *
 * @param page
 * @param key
 */
const block = (page: Page, key: string) => page.locator(`[data-catpath="${key}"]`);

/**
 * Whether a block shows statistics: rendered, no raw shortcode, no error.
 *
 * @param page
 * @param key
 */
const expectRendered = async(page: Page, key: string): Promise<void> => {
    const b = block(page, key);
    await expect(b, `block ${key}`).not.toContainText('[catquizstatistics');
    await expect(b.locator('.alert-danger, .errormessage'), `block ${key} without an error`).toHaveCount(0);
    await expect(b.locator('.tab-content, .chart-area, table').first(), `block ${key} shows statistics`).toBeVisible();
};

test.describe('Statistics over lists of tests @shortcodes', () => {
    test('administrator: 1, 3, 25 and 100 tests, two courses - rendered, lazy charts, CSV, reload', async({page}, testInfo) => {
        const errors = watchErrors(page);
        await loginAdmin(page);
        const t0 = Date.now();
        await page.goto(`/mod/page/view.php?id=${catpath('PAGE_CMID')}`);
        const pagems = Date.now() - t0;
        for (const key of BLOCKS) {
            await expectRendered(page, key);
        }

        // Lazy charts: the tab "Testusage" of the block over 100 tests builds its chart when it is shown.
        const timings: Record<string, number> = {page: pagems};
        for (const key of ['three', 'hundred']) {
            const tab = block(page, key).locator('a[id$="-testusage-tab"]').first();
            const t1 = Date.now();
            await tab.click();
            const pane = block(page, key).locator('div.tab-pane[id$="-testusage"]').first();
            await expect(pane).toBeVisible({timeout: 30_000});
            await expect(pane.locator('canvas, .chart-area, table').first()).toBeVisible({timeout: 60_000});
            timings['lazy_' + key] = Date.now() - t1;
        }

        // P1 accessibility: the statistics of a block - tabs, charts with their data tables, the table of the
        // scales - pass axe, with the lazy tab open.
        const axe = await new AxeBuilder({page}).withTags(WCAG).include('[data-catpath="three"]').analyze();
        expect(axe.violations.map((v) => `${v.id}: ${v.nodes.length}`), 'axe on the statistics block').toEqual([]);

        // The CSV of the block over three tests: its attempts - three people in each of the first, from the seed.
        const link = block(page, 'three').locator('a[href*="export_statistics_csv.php"]').first();
        await expect(link).toHaveCount(1);
        const csv = await page.request.get((await link.getAttribute('href')) as string);
        expect(csv.status()).toBe(200);
        const lines = (await csv.text()).trim().split('\n').filter((l) => l.trim() !== '');
        expect(lines.length, 'a header and the attempts').toBeGreaterThanOrEqual(4);

        // A reload shows the same blocks, and the lazy tab loads again.
        await page.reload();
        for (const key of BLOCKS) {
            await expectRendered(page, key);
        }
        await block(page, 'hundred').locator('a[id$="-testusage-tab"]').first().click();
        await expect(block(page, 'hundred').locator('div.tab-pane[id$="-testusage"]').first()).toBeVisible({timeout: 30_000});
        await keep(testInfo, 'shortcodes-administrator', {timings, csvlines: lines.length});
        expect(errors).toEqual([]);
    });

    test('teacher of course A: 1, 3 and 25 tests of A render, a block naming course B shows nothing', async({page}) => {
        const errors = watchErrors(page);
        await loginAs(page, 'teachera');
        await page.goto(`/mod/page/view.php?id=${catpath('PAGE_CMID')}`);
        // 1, 3 and 25 tests of course A: rendered for its teacher.
        await expectRendered(page, 'one');
        await expectRendered(page, 'three');
        await expectRendered(page, 'twentyfive');
        // 100 tests of both courses, both courses, the tests of B: one without the right empties the block.
        for (const key of ['hundred', 'courses', 'onlyb']) {
            // Fails closed: one test or course without the right, and the whole block is empty - no data of B.
            await expect(block(page, key), `block ${key}`).not.toContainText('[catquizstatistics');
            await expect(block(page, key).locator('canvas, .chart-area, table, .tab-content'), `block ${key}`).toHaveCount(0);
        }
        expect(errors).toEqual([]);
    });

    test('teacher of course B: the tests of B render, the block over both courses shows nothing', async({page}) => {
        const errors = watchErrors(page);
        await loginAs(page, 'teacherb');
        await page.goto(`/mod/page/view.php?id=${catpath('PAGEB_CMID')}`);
        await expectRendered(page, 'onlyb');
        await expect(block(page, 'courses').locator('canvas, .chart-area, table, .tab-content')).toHaveCount(0);
        expect(errors).toEqual([]);
    });

    // The endpoint called send_file() without filelib.php and ended in an exception page (found here, 19.11.2026).
    test('debug PDF of an attempt: for the manager, refused for a teacher (engine #173)', async({page}) => {
        await loginAdmin(page);
        const pdf = await page.request.get(`/local/catquiz/export_debuginfo_pdf.php?attemptid=${catpath('STATS_ATTEMPT_A')}`);
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()['content-type']).toContain('application/pdf');
        expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
        await loginAs(page, 'teacherb');
        const refused = await page.request.get(`/local/catquiz/export_debuginfo_pdf.php?attemptid=${catpath('STATS_ATTEMPT_A')}`);
        expect(refused.headers()['content-type'] || '').not.toContain('application/pdf');
        expect(await refused.text()).not.toContain('%PDF');
    });

    test('the statistics on a phone @responsive', async({page}, testInfo) => {
        test.skip(testInfo.project.name !== 'mobile', 'Played in the project "mobile" only.');
        const errors = watchErrors(page);
        await loginAdmin(page);
        await page.goto(`/mod/page/view.php?id=${catpath('PAGE_CMID')}`);
        await expectRendered(page, 'three');
        await block(page, 'three').locator('a[id$="-testusage-tab"]').first().click();
        await expect(block(page, 'three').locator('div.tab-pane[id$="-testusage"]').first()).toBeVisible({timeout: 30_000});
        expect(errors).toEqual([]);
    });
});
