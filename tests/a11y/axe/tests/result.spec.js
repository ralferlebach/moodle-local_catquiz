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
 * A11Y-CQ-03 - the result page, the feedback shortcode and the question dialogue (issues #30, #32).
 *
 * The participant takes the test of the fixture once; the result page and the page with the feedback
 * shortcode then show its feedback. The progress summary opens a question in a dialogue that takes
 * the focus, keeps it, closes with Escape and gives it back to the button that opened it.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs, openResultPage} = require('../../support/env');
const {scan, duplicateIds, bootstrap4Leftovers, applyTheme, holdsFocus} = require('../support/checks');

test.describe('A11Y-CQ-03 - result page and feedback', () => {
    test.beforeEach(async({page}, testInfo) => {
        await applyTheme(page, testInfo);
        await loginAs(page, env.user, env.password);
    });

    test('result page: tabs, progress summary, question dialogue', async({page}, testInfo) => {
        await openResultPage(page);
        await page.waitForLoadState('networkidle');
        expect(await duplicateIds(page)).toEqual([]);
        expect(await bootstrap4Leftovers(page, testInfo, 'result-page')).toEqual([]);
        expect(await scan(page, testInfo, 'result-page', '#region-main')).toEqual([]);

        const summary = page.getByRole('tab', {name: 'Quiz progress summary'}).first();
        await summary.click();
        const table = page.locator('.catquiz-graphicalsummary-table').first();
        await expect(table).toBeVisible();
        expect(await duplicateIds(page)).toEqual([]);
        await testInfo.attach('result-summary.png', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});
        expect(await scan(page, testInfo, 'result-progress-summary', '#region-main')).toEqual([]);

        await test.step('the question dialogue holds the focus and gives it back', async() => {
            const button = table.getByRole('button', {name: 'Show question'}).first();
            await button.focus();
            await page.keyboard.press('Enter');
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await expect(dialog).toHaveAccessibleName(/.+/);
            await expect.poll(() => holdsFocus(dialog)).toBe(true);
            for (let i = 0; i < 4; i++) {
                await page.keyboard.press('Tab');
                expect(await holdsFocus(dialog), 'Tab ' + (i + 1)).toBe(true);
            }
            expect(await duplicateIds(page)).toEqual([]);
            expect(await scan(page, testInfo, 'result-question-dialog', '.modal.show .modal-dialog')).toEqual([]);
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(button).toBeFocused();
        });
    });

    test('feedback shortcode: the same tabs, own ids, no WCAG violation', async({page}, testInfo) => {
        // The attempt exists - the result page made sure of it.
        await openResultPage(page);
        await page.goto(env.feedbackUrl);
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.catquiz_feedback').first()).toBeVisible();
        expect(await duplicateIds(page)).toEqual([]);
        expect(await bootstrap4Leftovers(page, testInfo, 'feedback-shortcode')).toEqual([]);
        expect(await scan(page, testInfo, 'feedback-shortcode', '#region-main')).toEqual([]);
    });
});
