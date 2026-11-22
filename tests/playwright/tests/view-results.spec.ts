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
 * The views of a result, re-audit "ab #147" (19.11.2026).
 *
 * Engine #166: attempts of 20 and of 100 answers, in the browser, to their result: the comparison with the other people
 * of the test is there, and it is the same later - in the history of the person, on the feedback page of the teacher,
 * and once the activity is hidden (the participant keeps reading the own result, the teacher too).
 *
 * Engine #159: results stored as older versions left them - without the scope of #168, without the comparison of
 * #166, without the history of the answers, without the standard error of the subscales, without abilities - before
 * and after the real retention task, for the participant, the teacher and another participant: the same page after
 * the task as before (decision of 21.11.2026: what the stored result lacks is kept from the progress before it is
 * removed), never a number the attempt did not have (no invented zero), nothing for another person.
 *
 * Runs after cat-attempt-path.spec.ts (the files run in their order, one worker): the attempts of results_helper.php
 * change the population of the Simulation scale those stories start from.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {expect, Page, test} from '@playwright/test';
import {attemptState, expectFinishedOnce, keep, loginAs, playToTheEnd, startAttempt, watchErrors} from '../support/catpath';
import {decimals, feedbackTabs, normalise, openFeedback, results} from '../support/results';

// No retry: the stories make attempts and run the retention task - a second pass would start from another state.
test.describe.configure({mode: 'serial', retries: 0});

let data: any;

/** The result page of the participant: where the attempt ends, and where it is read again. */
const ownResult = async(page: Page, cmid: number, instance: number, uniqueid: number): Promise<number> => {
    const response = await page.goto(`/mod/adaptivequiz/attemptfinished.php?cmid=${cmid}&id=${instance}&uattid=${uniqueid}`);
    return response?.status() ?? 0;
};

/**
 * No exception, no error code - and, where the stored result is complete, no section that could not be shown.
 *
 * @param page
 * @param complete
 */
const expectReadable = async(page: Page, complete = true): Promise<void> => {
    await expect(page.locator('body')).not.toContainText(/exception|Stack trace|error code/i);
    if (complete) {
        await expect(page.locator('body')).not.toContainText(/could not be shown/i);
    }
};

/** The states whose stored result holds everything the page needs. */
const COMPLETE = ['current', 'pre168', 'prepeer'];

test.describe('Result views (re-audit "ab #147") @results', () => {
    test.beforeAll(async() => {
        test.setTimeout(1_800_000);
        data = results('--results');
    });

    for (const length of [20, 100]) {
        test(`#166: ${length} answers to the result - the comparison, then the same in history, for the teacher and hidden`,
            async({page}, testInfo) => {
                test.setTimeout(900_000);
                const errors = watchErrors(page);
                const cmid = String(data[`cmid${length}`]);
                const user = `len${length}`;
                await loginAs(page, user);
                await startAttempt(page, cmid);
                const run = await playToTheEnd(page, '10011', length + 5);
                expect(run.answers).toBe(length);
                await expectReadable(page);
                const end = page.url();
                const atend = await feedbackTabs(page);
                const comparison = atend['comparetotestaverage/student'] ?? '';
                // Three other people finished the test before: the comparison is there.
                // ("Your score is better than ...%" follows only when somebody of the group is below the person.)
                expect(comparison, 'the comparison with the other people').toMatch(/average score of all participants is -?\d/);
                const state = attemptState(cmid, user);
                expectFinishedOnce(state, length);

                // The participant reads the result again: the same.
                await page.goto(end);
                const again = await feedbackTabs(page);
                expect(again, 'read again: the same').toEqual(atend);

                // The history and the feedback page of the teacher of the course.
                await loginAs(page, 'teacherc');
                await page.goto(`/mod/adaptivequiz/viewattemptreport.php?cmid=${cmid}&userid=${state.userid}`);
                await expectReadable(page);
                const history = normalise(await page.locator('#region-main').innerText());
                expect(history).toContain(String(length));
                const contextid = data.attempts.current.contextid;
                expect(await openFeedback(page, state.attemptid as number, contextid)).toBe(200);
                await expectReadable(page);
                const teacher = await feedbackTabs(page);
                // The teacher reads the same comparison; the page adds, for reviewers only, which item pool the test
                // information of the chart is built from.
                expect(teacher['comparetotestaverage/student'], 'the teacher sees the comparison of the end')
                    .toContain(comparison);

                // The activity is hidden: the participant keeps the own result, the teacher the feedback page.
                results('--visible=0', `--cmid=${cmid}`);
                try {
                    expect(await openFeedback(page, state.attemptid as number, contextid)).toBe(200);
                    expect((await feedbackTabs(page))['comparetotestaverage/student']).toContain(comparison);
                    await loginAs(page, user);
                    await page.goto(end);
                    await expectReadable(page);
                    expect((await feedbackTabs(page))['comparetotestaverage/student'], 'hidden: the own result').toBe(comparison);
                } finally {
                    results('--visible=1', `--cmid=${cmid}`);
                }
                await keep(testInfo, `results-${length}`, {
                    state, answers: run.answers, timings: run.timings, comparison, history: history.slice(0, 2000),
                });
                expect(errors).toEqual([]);
            });
    }

    test('#159: stored states before and after the retention task - participant, teacher, another participant',
        async({page}, testInfo) => {
            test.setTimeout(900_000);
            const errors = watchErrors(page);
            const cmid = data.cmidretention;
            const states = Object.keys(data.attempts);
            const read = async(): Promise<Record<string, any>> => {
                const out: Record<string, any> = {};
                for (const state of states) {
                    const a = data.attempts[state];
                    await loginAs(page, `rt_${state}`);
                    expect(await ownResult(page, cmid, a.instance, a.uniqueid), `${state}: the own result page`).toBe(200);
                    await expectReadable(page, COMPLETE.includes(state));
                    const participant = await feedbackTabs(page);
                    await loginAs(page, 'teacherc');
                    expect(await openFeedback(page, a.attemptid, a.contextid), `${state}: the teacher's page`).toBe(200);
                    await expectReadable(page, COMPLETE.includes(state));
                    out[state] = {participant, teacher: await feedbackTabs(page)};
                }
                return out;
            };
            const before = await read();
            const sweep = results('--retention');
            expect(sweep.before, 'progress of finished attempts before the task').toBeGreaterThan(0);
            expect(sweep.after, 'the task removed every one').toBe(0);
            const after = await read();
            await keep(testInfo, 'retention-states', {sweep, before, after});

            for (const state of states) {
                for (const audience of ['participant', 'teacher']) {
                    const was = before[state][audience] as Record<string, string>;
                    const is = after[state][audience] as Record<string, string>;
                    if (COMPLETE.includes(state)) {
                        expect(Object.keys(is), `${state}/${audience}: the same sections`).toEqual(Object.keys(was));
                    }
                    for (const [section, text] of Object.entries(is)) {
                        // No number the page did not show while the progress was there - an invented zero above all.
                        const known = new Set(decimals(was[section] ?? ''));
                        const added = decimals(text).filter((value) => !known.has(value));
                        expect(added, `${state}/${audience}/${section}: no new numbers after the task`).toEqual([]);
                        expect(text, `${state}/${audience}/${section}`).not.toMatch(/±\s*0[.,]00\b/);
                    }
                    // Every state, the incomplete ones as well (decision of 21.11.2026): the same values and labels after
                    // the task - what the stored result lacked was kept from the progress before it was removed.
                    expect(is, `${state}/${audience}: the same after the task`).toEqual(was);
                }
            }
            // The full result: the root scale with its value, the comparison with the group.
            const current = after.current.participant['comparetotestaverage/student'] ?? '';
            expect(current).toMatch(/Simulation/);
            expect(current).toMatch(/You reached a score of -?\d+\.\d\d \(with a standard error of ± \d+\.\d\d\)/);
            expect(current, 'the comparison with the group').toMatch(/average score of all participants is -?\d/);
            // Ended before the comparison was stored with the result: none is made up afterwards.
            const prepeer = after.prepeer.participant['comparetotestaverage/student'] ?? '';
            expect(prepeer, 'no comparison computed afterwards').not.toMatch(/better than|average score of all participants/);
            // Without abilities nothing is estimated again: no score at all, never one of 0.
            for (const text of Object.values(after.noabilities.participant) as string[]) {
                expect(text).not.toMatch(/You reached a score of -?0[.,]00\b/);
            }

            expect(errors, 'no error while the views were read').toEqual([]);

            // Another participant of the course: neither the result page nor the feedback page of rt_current - the
            // refusal is the expected answer, whatever its form.
            const a = data.attempts.current;
            await loginAs(page, 'rt_other');
            await ownResult(page, cmid, a.instance, a.uniqueid);
            await expect(page.locator('body')).not.toContainText(/You reached a score/);
            await openFeedback(page, a.attemptid, a.contextid);
            await expect(page.locator('body')).not.toContainText(/You reached a score/);
        });
});
