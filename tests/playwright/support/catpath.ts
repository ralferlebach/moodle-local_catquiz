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
 * The integrated browser path (re-audit 19.11.2026, Playwright P0): what seed_cat_path.php exported, what the
 * browser cannot see (cat_path_helper.php), and the steps every story shares.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import AxeBuilder from '@axe-core/playwright';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {expect, Page, TestInfo} from '@playwright/test';

/**
 * A variable of the seed; the story fails at once without it.
 *
 * @param name
 */
export const catpath = (name: string): string => {
    const value = process.env['CATPATH_' + name];
    if (!value) {
        throw new Error(`CATPATH_${name} is not set: run seed_cat_path.php from the Moodle root and export its output.`);
    }
    return value;
};

/** What the helper reports about the last attempt of a person at an activity. */
export interface AttemptState {
    attempts: number;
    attemptid?: number;
    state?: string;
    stopcode?: string | null;
    resultvalid?: number | null;
    resultstatus?: string | null;
    questionsattempted?: number;
    slots?: number;
    distinct?: number;
    catattempts?: number;
    catuserid?: number | null;
    userid: number;
    finalised?: boolean;
    completionevents?: number | null;
    grade?: number | null;
    completion?: number;
}

/**
 * Runs cat_path_helper.php on the site.
 *
 * @param args
 */
export const helper = (...args: string[]): any => {
    const script = path.join(catpath('DIRROOT'), 'local/catquiz/tests/playwright/cat_path_helper.php');
    return JSON.parse(execFileSync('php', [script, ...args], {encoding: 'utf8'}).trim().split('\n').pop() as string);
};

/**
 * The state of the last attempt of a person at an activity.
 *
 * @param cmid
 * @param username Without the prefix catpath_.
 */
export const attemptState = (cmid: string, username: string): AttemptState =>
    helper('--state', `--cmid=${cmid}`, `--username=catpath_${username}`);

/**
 * Collects what the browser reports as wrong: console errors, uncaught page errors, HTTP 5xx and 4xx responses of
 * the site (a favicon or a missing optional asset aside; resources of other hosts aside).
 *
 * One pair of console errors is Moodle's own and no fault: a request of core/ajax that is still running when the page
 * is left - core_message_get_unsent_message and core_courseformat_get_state run on every page - is cut off by the
 * navigation, and core/ajax logs "Page unloaded." and then the error of the cut-off request ("TypeError: Failed to
 * fetch"). Both are left out; any other console error counts (CI 20.11.2026: the story of HIGHESTSUB failed on that
 * pair alone).
 *
 * @param page
 */
export const watchErrors = (page: Page): string[] => {
    const errors: string[] = [];
    let unloading = false;
    // The site under test; what other hosts serve (Moodle 4.5 loads MathJax from cdn.jsdelivr.net) is not the plugin's
    // to answer for - a CDN that is slow, blocked or answers without CORP header must not fail a story (local 4.5,
    // 20.11.2026: "Failed to load resource: net::ERR_BLOCKED_BY_RESPONSE.NotSameOrigin" for MathJax).
    const site = new URL(process.env.CATQUIZ_BASE_URL || 'http://127.0.0.1:8000').host;
    const foreign = (url: string | undefined): boolean => {
        try {
            return !!url && /^https?:/.test(url) && new URL(url).host !== site;
        } catch (e) {
            return false;
        }
    };
    page.on('console', (msg) => {
        if (msg.type() !== 'error') {
            return;
        }
        const text = msg.text();
        if (/^Failed to load resource/.test(text) && foreign(msg.location()?.url)) {
            return;
        }
        if (text === 'Page unloaded.') {
            unloading = true;
            return;
        }
        if (unloading && /^$|Failed to fetch|NetworkError|Load failed|abort|^error$/i.test(text)) {
            unloading = false;
            return;
        }
        unloading = false;
        errors.push(`console: ${text}`);
    });
    page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
    page.on('response', (response) => {
        const status = response.status();
        if (status >= 400 && !/favicon|\.map$/.test(response.url()) && !foreign(response.url())) {
            errors.push(`HTTP ${status}: ${response.url()}`);
        }
    });
    return errors;
};

/**
 * Logs in over HTTP, not through the form - the lesson of local_adele: the login page re-renders between filling
 * and submitting often enough that Moodle receives an empty password ("Invalid login") in a share of the runs. The
 * request API shares its cookies with the page, so the page is logged in afterwards.
 *
 * @param page
 * @param username Without the prefix catpath_.
 */
export const loginAs = async(page: Page, username: string): Promise<void> =>
    loginOverHttp(page, `catpath_${username}`, catpath('PASSWORD'));

/**
 * The administrator of the site (seed.php: CATQUIZ_ADMIN_USER, CATQUIZ_ADMIN_PASS), over HTTP as loginAs().
 *
 * @param page
 */
export const loginAdmin = async(page: Page): Promise<void> =>
    loginOverHttp(page, process.env.CATQUIZ_ADMIN_USER || 'admin', process.env.CATQUIZ_ADMIN_PASS || 'Admin!23');

/**
 * The login request of the form, sent by the request API of the page's context.
 *
 * @param page
 * @param username
 * @param password
 */
export const loginOverHttp = async(page: Page, username: string, password: string): Promise<void> => {
    // The page of the person before is left first: its scripts (core/courseformat, core/ajax) would otherwise send
    // their requests with the session key of that person into the session of the next one - "invalidsesskey",
    // "EXCEPTION RAISED WHILE INIT COURSE EDITOR" in the console (4.5, 20.11.2026).
    await page.goto('about:blank');
    await page.context().clearCookies();
    const api = page.context().request;
    const form = await api.get('/login/index.php', {timeout: 60_000});
    const token = /name="logintoken" value="([^"]+)"/.exec(await form.text())?.[1] ?? '';
    const response = await api.post('/login/index.php', {
        form: {anchor: '', logintoken: token, username, password}, timeout: 60_000,
    });
    expect(response.url(), `login of ${username}`).not.toContain('/login/index.php');
};

/** The slot of the question on the screen, or null when none is shown. */
export const shownSlot = async(page: Page): Promise<string | null> => {
    const slots = page.locator('form#responseform input[name="slots"]');
    return (await slots.count()) ? await slots.first().getAttribute('value') : null;
};

/**
 * What the page shows at this moment: the next question, the end of the attempt, or nothing yet (wait).
 *
 * While the browser navigates - after a click on "Submit answer", twice after a double click - the document of the
 * moment can be gone between two calls ("Execution context was destroyed", CI 20.11.2026) or not be built yet: that
 * is waiting, never the end. The end is a loaded page that is not the attempt page: the host always redirects to
 * attemptfinished.php (or to view.php) when an attempt ends.
 *
 * @param page
 * @param before The slot before the click.
 */
const nextState = async(page: Page, before: string | null): Promise<'next' | 'end' | 'wait'> => {
    try {
        const ready = await page.evaluate(() => document.readyState);
        if (ready === 'loading') {
            return 'wait';
        }
        const slot = await shownSlot(page);
        if (slot === null) {
            return /\/mod\/adaptivequiz\/attempt\.php/.test(page.url()) ? 'wait' : 'end';
        }
        return slot !== before && await page.locator('#responseform .qtext').first().isVisible() ? 'next' : 'wait';
    } catch (e) {
        if (/Execution context was destroyed|navigat|Target page, context or browser has been closed/i.test(String(e))) {
            return 'wait';
        }
        throw e;
    }
};

/** Times from the click to the question a person can read, in ms, per step. */
export interface Timing {
    step: number;
    ms: number;
}

/**
 * Waits until a question is visible with another slot than before, or the attempt has ended (no response form).
 *
 * @param page
 * @param before The slot before the click.
 * @returns The slot shown now, or null at the end.
 */
export const nextQuestionOrEnd = async(page: Page, before: string | null): Promise<string | null> => {
    const seen: {state: 'next' | 'end' | 'wait'} = {state: 'wait'};
    await expect.poll(async() => {
        seen.state = await nextState(page, before);
        return seen.state;
    }, {timeout: 90_000, intervals: [100, 200, 500]}).not.toBe('wait');
    await page.waitForLoadState('domcontentloaded');
    return seen.state === 'end' ? null : shownSlot(page);
};

/**
 * Starts the attempt from the activity page; returns the time to the first readable question.
 *
 * @param page
 * @param cmid
 */
export const startAttempt = async(page: Page, cmid: string): Promise<number> => {
    await page.goto(`/mod/adaptivequiz/view.php?id=${cmid}`);
    const start = page.getByRole('button', {name: /start attempt|versuch beginnen/i})
        .or(page.getByRole('link', {name: /start attempt|versuch beginnen/i})).first();
    const t0 = Date.now();
    await start.click();
    await expect(page.locator('#responseform .qtext').first()).toBeVisible({timeout: 90_000});
    return Date.now() - t0;
};

/**
 * Answers the question on the screen - right ("richtige Antwort") or wrong - and submits it.
 *
 * @param page
 * @param right
 * @param how click (default) or dblclick on the submit button
 */
export const answer = async(page: Page, right: boolean, how: 'click' | 'dblclick' = 'click'): Promise<void> => {
    const option = right
        ? page.getByRole('radio', {name: 'richtige Antwort'})
        : page.getByRole('radio', {name: /falsche Antwort/});
    await option.first().check();
    const submit = page.getByRole('button', {name: /submit answer|antwort (absenden|abgeben)/i}).first();
    if (how === 'dblclick') {
        await submit.dblclick();
    } else {
        await submit.click();
    }
};

/**
 * Plays the attempt on the screen to its end with the answers of the pattern (1 right, 0 wrong; repeated), at most
 * $max answers. Records the time from each click to the next readable question.
 *
 * @param page
 * @param pattern
 * @param max
 * @returns answers given and the timings
 */
export const playToTheEnd = async(page: Page, pattern: string, max = 40): Promise<{answers: number; timings: Timing[]}> => {
    const timings: Timing[] = [];
    let slot = await shownSlot(page);
    let answers = 0;
    while (slot !== null && answers < max) {
        const right = pattern[answers % pattern.length] === '1';
        const t0 = Date.now();
        await answer(page, right);
        answers++;
        slot = await nextQuestionOrEnd(page, slot);
        timings.push({step: answers, ms: Date.now() - t0});
    }
    return {answers, timings};
};

/**
 * Keeps the timings of a story in the output of the run (CATPATH_OUT or the test's output directory).
 *
 * @param testInfo
 * @param name
 * @param data
 */
export const keep = async(testInfo: TestInfo, name: string, data: unknown): Promise<void> => {
    const body = JSON.stringify(data, null, 2);
    await testInfo.attach(name + '.json', {body, contentType: 'application/json'});
    const dir = process.env.CATPATH_OUT;
    if (dir) {
        fs.mkdirSync(dir, {recursive: true});
        fs.writeFileSync(path.join(dir, name + '.json'), body);
    }
};

/**
 * What every finished attempt must be: complete, every question once, the CAT attempt of the person, finalised
 * once, completion and grade as the validity of the result says.
 *
 * @param state
 * @param answers
 */
export const expectFinishedOnce = (state: AttemptState, answers: number): void => {
    expect(state.attempts, 'one attempt').toBe(1);
    expect(state.state).toBe('complete');
    expect(state.slots, 'every answer a slot').toBe(answers);
    expect(state.distinct, 'no question twice').toBe(state.slots);
    expect(state.questionsattempted, 'the host counts every answer once').toBe(answers);
    expect(state.catattempts, 'one CAT attempt').toBe(1);
    expect(state.catuserid, 'the CAT attempt of the person').toBe(state.userid);
    expect(state.finalised, 'finalised').toBe(true);
    if (state.completionevents !== null && state.completionevents !== undefined) {
        expect(state.completionevents, 'announced once').toBe(1);
    }
    const valid = state.resultvalid === 1;
    expect(state.completion, 'completion follows the validity of the result').toBe(valid ? 1 : 0);
    if (valid) {
        expect(state.grade, 'a grade for a valid result').not.toBeNull();
    } else {
        expect(state.grade ?? null, 'no grade - not 0 - for an invalid result').toBeNull();
    }
};

/**
 * The rows of a CSV text (RFC 4180: quoted fields, doubled quotes, delimiters and line breaks inside quotes).
 *
 * @param text
 * @param delimiter Moodle's csv_export_writer: comma, or semicolon (the attempt export).
 */
export const csvRows = (text: string, delimiter = ','): string[][] => {
    const rows: string[][] = [];
    let row: string[] = [];
    let field = '';
    let quoted = false;
    const body = text.replace(/^\uFEFF/, '');
    for (let i = 0; i < body.length; i++) {
        const c = body[i];
        if (quoted) {
            if (c === '"' && body[i + 1] === '"') {
                field += '"';
                i++;
            } else if (c === '"') {
                quoted = false;
            } else {
                field += c;
            }
        } else if (c === '"') {
            quoted = true;
        } else if (c === delimiter) {
            row.push(field);
            field = '';
        } else if (c === '\n' || c === '\r') {
            if (c === '\r' && body[i + 1] === '\n') {
                i++;
            }
            row.push(field);
            rows.push(row);
            row = [];
            field = '';
        } else {
            field += c;
        }
    }
    if (field !== '' || row.length) {
        row.push(field);
        rows.push(row);
    }
    return rows.filter((r) => r.some((f) => f.trim() !== ''));
};

/** p50 and p95 (nearest rank). */
export const percentiles = (values: number[]): {n: number; p50: number | null; p95: number | null; max: number | null} => {
    const sorted = [...values].sort((a, b) => a - b);
    const rank = (p: number) => sorted.length ? sorted[Math.max(0, Math.ceil(p * sorted.length) - 1)] : null;
    return {n: sorted.length, p50: rank(0.5), p95: rank(0.95), max: sorted.length ? sorted[sorted.length - 1] : null};
};

/** The rules of WCAG 2.0, 2.1 and 2.2 at levels A and AA - as the host's axe suite. */
export const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * The end of a test as a person meets it (P1 accessibility, host #20/#23, engine #172). An end the person may not
 * expect - a technical error, all scales complete - is explained in a dialog before the feedback: it opens by itself,
 * has the focus, is named by its title and described by the reason (what a screen reader says), and axe finds no
 * violation in it. Confirmed, it closes, the feedback is shown and the focus is on the notice on the page; axe then
 * checks the page with the feedback. A regular end (the maximum) has no notice and no dialog.
 *
 * @param page
 * @param notice Whether this end is explained (stop codes error, scalescomplete, ...).
 * @returns Whether a dialog was shown.
 */
export const expectAccessibleEnd = async(page: Page, notice: boolean): Promise<boolean> => {
    const region = page.locator('[data-region="adaptivequiz-stopnotice"]');
    const main = '#region-main, [role="main"]';
    if (!notice) {
        await expect(region, 'a regular end has no notice').toHaveCount(0);
        await expect(page.getByRole('dialog')).toHaveCount(0);
        const result = await new AxeBuilder({page}).withTags(WCAG).include(main).analyze();
        expect(result.violations.map((v) => `${v.id}: ${v.nodes.length}`), 'axe on the result page').toEqual([]);
        return false;
    }
    await expect(region, 'the notice is on the page').toHaveCount(1);
    const dialog = page.getByRole('dialog');
    await expect(dialog, 'the end is explained in a dialog').toBeVisible({timeout: 30_000});
    await expect.poll(() => page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]')),
        {message: 'the focus is in the dialog', timeout: 10_000}).toBe(true);
    const described = await dialog.getAttribute('aria-describedby');
    expect(described, 'the dialog is described by the reason').toBeTruthy();
    await expect(page.locator(`[id="${described}"]`)).not.toBeEmpty();
    await expect(page.locator('[data-region="adaptivequiz-attempt-feedback"]'), 'the feedback waits for the dialog')
        .toBeHidden();
    const indialog = await new AxeBuilder({page}).withTags(WCAG).include('[role="dialog"]').analyze();
    expect(indialog.violations.map((v) => `${v.id}: ${v.nodes.length}`), 'axe on the dialog').toEqual([]);

    // Confirmed with the keyboard: the feedback follows, the focus goes to the notice on the page.
    await dialog.getByRole('button', {name: /^ok$/i}).focus();
    await page.keyboard.press('Enter');
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('[data-region="adaptivequiz-attempt-feedback"]')).not.toHaveAttribute('hidden');
    await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('data-region')),
        {message: 'the focus is on the notice', timeout: 10_000}).toBe('adaptivequiz-stopnotice');
    const after = await new AxeBuilder({page}).withTags(WCAG).include(main).analyze();
    expect(after.violations.map((v) => `${v.id}: ${v.nodes.length}`), 'axe on the result page').toEqual([]);
    return true;
};
