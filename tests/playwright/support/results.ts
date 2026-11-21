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
 * The result views of the re-audit "ab #147" (19.11.2026): the data of results_helper.php and the text of a result
 * page, tab by tab, as a person reads it.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {expect, Page} from '@playwright/test';
import {catpath} from './catpath';

/**
 * Runs results_helper.php on the site; the last line of its output is JSON.
 *
 * @param args
 */
export const results = (...args: string[]): any => {
    const script = path.join(catpath('DIRROOT'), 'local/catquiz/tests/playwright/results_helper.php');
    const out = execFileSync('php', [script, ...args], {
        encoding: 'utf8', env: {...process.env, CATPATH_PASSWORD: catpath('PASSWORD')}, timeout: 1_500_000,
    });
    return JSON.parse(out.trim().split('\n').pop() as string);
};

/**
 * Text without the ids the templates generate per rendering and without runs of white space.
 *
 * @param text
 */
export const normalise = (text: string): string =>
    text.replace(/\b(?:[a-z_]*)?[0-9a-f]{13,}\b/gi, '#').replace(/\s+/g, ' ').trim();

/**
 * Waits until Moodle's scripts on the page are done (M.util.pending_js, the condition Behat waits for). The lazy tabs
 * of the feedback and of the statistics load their content when they are shown - once their module has bound its
 * handler. A click before that shows an empty pane that never loads; on the slower CI runners this happens.
 *
 * @param page
 */
export const scriptsReady = async(page: Page): Promise<void> => {
    await page.waitForFunction(
        () => !!(window as any).M?.util?.pending_js && (window as any).M.util.pending_js.length === 0,
        undefined,
        {timeout: 60_000}
    );
};

/**
 * Opens every tab of the feedback of an attempt and returns its text, after its lazy part has loaded: the key is the
 * generator and the audience (student or teacher).
 *
 * @param page
 */
export const feedbackTabs = async(page: Page): Promise<Record<string, string>> => {
    const texts: Record<string, string> = {};
    await scriptsReady(page);
    const tabs = page.locator('a.feedbacktab');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
        const tab = tabs.nth(i);
        const id = (await tab.getAttribute('id')) || '';
        const key = `${await tab.getAttribute('data-feedbackname')}/${id.endsWith('-teacher-tab') ? 'teacher' : 'student'}`;
        await tab.click();
        const pane = page.locator('#' + ((await tab.getAttribute('href')) || '#').substring(1));
        await expect(pane, `the pane of ${key}`).toBeVisible({timeout: 30_000});
        await expect(
            pane.locator('[data-region="local_catquiz-lazyfeedback"]:not([data-state="loaded"]):not([data-state="failed"])'),
            `the pane of ${key} has loaded`
        ).toHaveCount(0, {timeout: 60_000});
        texts[key] = normalise(await pane.innerText());
    }
    return texts;
};

/**
 * The feedback page of an attempt as the CAT manager and the teacher open it.
 *
 * @param page
 * @param attemptid The attempt of the activity.
 * @param contextid The CAT context of the attempt.
 */
export const openFeedback = async(page: Page, attemptid: number, contextid: number): Promise<number> => {
    const response = await page.goto(`/local/catquiz/show_attemptfeedback.php?attemptid=${attemptid}&contextid=${contextid}`);
    return response?.status() ?? 0;
};

/**
 * Values with two decimals that a page shows, e.g. abilities, standard errors and averages.
 *
 * @param text
 */
export const decimals = (text: string): string[] => text.match(/-?\d+[.,]\d\d\b/g) ?? [];
