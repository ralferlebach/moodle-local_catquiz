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
 * The integrated browser path of a CAT attempt (re-audit 19.11.2026, Playwright P0): host mod_adaptivequiz, CAT
 * model adaptivequizcatmodel_catquiz and engine local_catquiz together, in a real browser, as a participant plays.
 *
 * Covered: start, question, answer, end for the six strategies; reload and back; a double click on the answer; a
 * second tab; the hidden activity; the two production lengths of engine #174 (13 and 17 questions) and the two
 * replays of engine #172 (all scales complete after 17 and 16); a technical error before and after the minimum
 * (host #23) - and the same end in the CSV, the debug PDF and the history of the attempt (host #23 AK4); grade
 * and completion as the validity of the result says (host #25); the time from a click to the
 * next readable question (host #17) - kept as JSON; the end of the test as a person meets it - the dialog for error
 * and scalescomplete opens by itself, has the focus and its description, is confirmed with the keyboard, then the
 * feedback; axe finds no violation in the dialog and on the result page (P1 accessibility); no console error, no
 * uncaught page error, no HTTP error.
 *
 * Seed: seed_cat_path.php. What the browser cannot see comes from cat_path_helper.php on the same machine.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {expect, test} from '@playwright/test';
import {
    answer, attemptState, catpath, csvRows, expectAccessibleEnd, expectFinishedOnce, helper, keep, loginAdmin, loginAs,
    nextQuestionOrEnd, percentiles, playToTheEnd, shownSlot, startAttempt, watchErrors,
} from '../support/catpath';

// One attempt after the other: the population of the scale is part of what the selection reads. No retry: a retry
// of a serial group plays it again from its first story, on a population the first pass has changed and with people
// who have their attempt already - the replays of #172 then end after 14 questions instead of 17 and hide the failure
// that caused the retry (CI 20.11.2026). The causes of flakiness are handled where they are (watchErrors,
// nextQuestionOrEnd); a failure is reported as it is.
test.describe.configure({mode: 'serial', retries: 0});

const STRATEGIES = ['fastest', 'allsubs', 'lowestsub', 'highestsub', 'classic', 'relsubs'];

test.describe('CAT attempt path @catpath', () => {
    // First, on the fresh population: the two replays of engine #172 end with all scales of the set complete.
    for (const [key, cmid, user, pattern, length] of [
        ['RELSUBS', 'REPLAY_RELSUBS', 'replayrel', '1001101010111111011100111110100110111111', 17],
        ['LOWESTSUB', 'REPLAY_LOWESTSUB', 'replaylow', '1101101101101101101101101101101101101101', 16],
    ] as [string, string, string, string, number][]) {
        test(`replay ${key}: all scales complete after ${length} questions`, async({page}, testInfo) => {
            const errors = watchErrors(page);
            await loginAs(page, user);
            await startAttempt(page, catpath('CMID_' + cmid));
            const run = await playToTheEnd(page, pattern, 25);
            const dialog = await expectAccessibleEnd(page, true);
            const state = attemptState(catpath('CMID_' + cmid), user);
            await keep(testInfo, `replay-${key.toLowerCase()}`, {state, timings: run.timings, dialog});
            expect(dialog, 'all scales complete is explained in a dialog').toBe(true);
            expect(state.stopcode).toBe('scalescomplete');
            expect(run.answers, `ends after ${length} questions, as the production attempt`).toBe(length);
            expectFinishedOnce(state, run.answers);
            expect(state.resultvalid, 'a valid result with every scale complete').toBe(1);
            expect(errors).toEqual([]);
        });
    }

    for (const key of STRATEGIES) {
        test(`${key}: start, answers, end - grade and completion`, async({page}, testInfo) => {
            const errors = watchErrors(page);
            await loginAs(page, key);
            const first = await startAttempt(page, catpath('CMID_' + key.toUpperCase()));
            const run = await playToTheEnd(page, '1101', 8);
            // The result page: no exception, a regular end without a notice, no axe violation.
            await expect(page.locator('body')).not.toContainText(/exception|Stack trace|error code/i);
            await expectAccessibleEnd(page, false);
            const state = attemptState(catpath('CMID_' + key.toUpperCase()), key);
            await keep(testInfo, `strategy-${key}`, {
                state, first, timings: run.timings, percentiles: percentiles([first, ...run.timings.map((t) => t.ms)]),
            });
            expect(run.answers).toBe(8);
            expect(state.stopcode).toBe('maximumquestions');
            expectFinishedOnce(state, 8);
            expect(errors).toEqual([]);
        });
    }

    for (const [key, user, length] of [['LEN13', 'len13', 13], ['LEN17', 'len17', 17]] as [string, string, number][]) {
        test(`production length ${length}: the result keeps its global scale`, async({page}, testInfo) => {
            const errors = watchErrors(page);
            await loginAs(page, user);
            await startAttempt(page, catpath('CMID_' + key));
            const run = await playToTheEnd(page, '10011', 30);
            const state = attemptState(catpath('CMID_' + key), user);
            await keep(testInfo, `length-${length}`, {state, timings: run.timings});
            expect(run.answers).toBe(length);
            expectFinishedOnce(state, length);
            // Engine #174: the forms of the production attempts - global scale missing, no stored result - are gone.
            await expect(page.locator('body')).not.toContainText(/global_scale_missing|no_stored_result/);
            expect(errors).toEqual([]);
        });
    }

    test('reload and back: the same question, nothing processed twice', async({page}, testInfo) => {
        const errors = watchErrors(page);
        await loginAs(page, 'reload');
        const cmid = catpath('CMID_CLASSIC');
        await startAttempt(page, cmid);
        for (let i = 0; i < 3; i++) {
            const before = await shownSlot(page);
            await answer(page, i % 2 === 0);
            await nextQuestionOrEnd(page, before);
        }
        const slot = await shownSlot(page);
        await page.reload();
        await expect(page.locator('#responseform .qtext').first()).toBeVisible();
        expect(await shownSlot(page), 'a reload shows the same question').toBe(slot);
        await page.goBack();
        await page.goForward();
        await page.goto(`/mod/adaptivequiz/attempt.php?cmid=${cmid}`);
        expect(await shownSlot(page), 'back and forward: still the same question').toBe(slot);
        let state = attemptState(cmid, 'reload');
        expect(state.slots, 'no slot added by reload or back').toBe(4);
        expect(state.distinct).toBe(4);
        expect(state.questionsattempted).toBe(3);
        const run = await playToTheEnd(page, '01', 10);
        state = attemptState(cmid, 'reload');
        await keep(testInfo, 'reload-back', {state, timings: run.timings});
        expectFinishedOnce(state, 3 + run.answers);
        expect(errors).toEqual([]);
    });

    test('a double click on the answer adds one question', async({page}) => {
        const errors = watchErrors(page);
        await loginAs(page, 'double');
        const cmid = catpath('CMID_ALLSUBS');
        await startAttempt(page, cmid);
        const before = await shownSlot(page);
        await answer(page, true, 'dblclick');
        await nextQuestionOrEnd(page, before);
        await page.waitForLoadState('networkidle');
        const state = attemptState(cmid, 'double');
        expect(state.slots, 'one answer, one next question').toBe(2);
        expect(state.distinct).toBe(2);
        expect(state.questionsattempted).toBe(1);
        const run = await playToTheEnd(page, '10', 10);
        expectFinishedOnce(attemptState(cmid, 'double'), 1 + run.answers);
        // The second click of a double click may be refused by the attempt lock (HTTP 409/423) - nothing else.
        expect(errors.filter((e) => !/HTTP 4(09|23)/.test(e))).toEqual([]);
    });

    test('a second tab with the same question: one answer counts', async({page, context}) => {
        const errors = watchErrors(page);
        await loginAs(page, 'twotabs');
        const cmid = catpath('CMID_HIGHESTSUB');
        await startAttempt(page, cmid);
        const second = await context.newPage();
        const errors2 = watchErrors(second);
        await second.goto(`/mod/adaptivequiz/attempt.php?cmid=${cmid}`);
        const slot = await shownSlot(page);
        expect(await shownSlot(second), 'both tabs show the same question').toBe(slot);
        await answer(page, true);
        await nextQuestionOrEnd(page, slot);
        // The stale tab answers the question that was answered already.
        await answer(second, false);
        await second.waitForLoadState('domcontentloaded');
        const state = attemptState(cmid, 'twotabs');
        expect(state.questionsattempted, 'the stale answer is not processed again').toBe(1);
        expect(state.distinct).toBe(state.slots);
        await second.close();
        await page.goto(`/mod/adaptivequiz/attempt.php?cmid=${cmid}`);
        const run = await playToTheEnd(page, '01', 10);
        expectFinishedOnce(attemptState(cmid, 'twotabs'), 1 + run.answers);
        expect([...errors, ...errors2].filter((e) => !/HTTP 4(09|23)/.test(e))).toEqual([]);
    });

    test('the hidden activity: no attempt for a participant', async({page}) => {
        await loginAs(page, 'hidden');
        const response = await page.goto(`/mod/adaptivequiz/view.php?id=${catpath('CMID_HIDDEN')}`);
        expect(response?.status()).toBeLessThan(500);
        await expect(page.getByRole('button', {name: /start attempt/i})).toHaveCount(0);
        await page.goto(`/mod/adaptivequiz/attempt.php?cmid=${catpath('CMID_HIDDEN')}`);
        await expect(page.locator('form#responseform')).toHaveCount(0);
        expect(attemptState(catpath('CMID_HIDDEN'), 'hidden').attempts, 'no attempt created').toBe(0);
    });

    for (const [when, user, pattern] of [
        ['before', 'errorbefore', '10'],
        ['after', 'errorafter', '10011010101110'],
    ] as [string, string, string][]) {
        test(`a technical error ${when} the minimum (host #23)`, async({page}, testInfo) => {
            const cmid = catpath('CMID_ERROR_' + when.toUpperCase());
            await loginAs(page, user);
            await startAttempt(page, cmid);
            for (let i = 0; i < pattern.length - 1; i++) {
                const before = await shownSlot(page);
                await answer(page, pattern[i] === '1');
                expect(await nextQuestionOrEnd(page, before), 'still running before the error').not.toBeNull();
            }
            try {
                // The error is real: the item parameters disappear under the running attempt.
                helper('--break-items');
                const before = await shownSlot(page);
                await answer(page, pattern[pattern.length - 1] === '1');
                expect(await nextQuestionOrEnd(page, before), 'the error ends the attempt').toBeNull();
            } finally {
                helper('--restore-items');
            }
            // Said as an error, never as a regular end - in a dialog that has the focus and passes axe.
            await expect(page.locator('body')).toContainText(/ended|beendet/i);
            const dialog = await expectAccessibleEnd(page, true);
            const state = attemptState(cmid, user);
            await keep(testInfo, `error-${when}`, {state, dialog});
            expect(dialog, 'a technical error is explained in a dialog').toBe(true);
            expect(state.stopcode).toBe('error');
            expectFinishedOnce(state, pattern.length);
            if (when === 'before') {
                expect(state.resultvalid, 'below the minimum: no valid result').toBe(0);
            }

            // Host #23 AK4: the same matrix in the CSV of the attempt, its debug PDF and the history of the person -
            // the technical end and the validity of the result side by side, never a regular end.
            await loginAdmin(page);
            const csv = await page.request.get(`/local/catquiz/export_attempt_csv.php?attemptid=${state.attemptid}`);
            expect(csv.status()).toBe(200);
            const [header, ...rows] = csvRows(await csv.text(), ';');
            const row = Object.fromEntries(header.map((name, i) => [name, rows[0]?.[i] ?? '']));
            expect(row.stopreason, 'the CSV names the technical end').toMatch(/error/i);
            expect(row.resultvalid, 'the CSV has the validity of the result').toBe(String(state.resultvalid));
            expect(row.lifecycle).toBe(state.resultvalid === 1 ? 'completed_valid' : 'completed_invalid');
            const pdf = await page.request.get(`/local/catquiz/export_debuginfo_pdf.php?attemptid=${state.attemptid}`);
            expect(pdf.status()).toBe(200);
            expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
            await page.goto(`/mod/adaptivequiz/viewattemptreport.php?cmid=${cmid}&userid=${state.userid}`);
            const history = page.locator('#region-main table').first();
            await expect(history, 'the history names the technical end').toContainText(/error/i);
            await expect(history).not.toContainText(/maximum number of questions|measured as far as the test requires/i);
            await keep(testInfo, `error-${when}-exports`, {csv: row, history: await history.innerText()});
        });
    }

    test('responsive: an attempt on a phone @responsive', async({page}, testInfo) => {
        test.skip(testInfo.project.name !== 'mobile', 'Played in the project "mobile" only.');
        const errors = watchErrors(page);
        await loginAs(page, 'mobile');
        await startAttempt(page, catpath('CMID_FASTEST'));
        // Nothing of the question wider than the screen.
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, 'no horizontal scrolling').toBeLessThanOrEqual(1);
        const run = await playToTheEnd(page, '1101', 8);
        expectFinishedOnce(attemptState(catpath('CMID_FASTEST'), 'mobile'), run.answers);
        expect(errors).toEqual([]);
    });
});
