import {Page, expect} from '@playwright/test';
import {adminPass, adminUser, contextId, scaleId} from './env';
import {loginOverHttp} from './catpath';

/**
 * Logs in as the administrator over HTTP (support/catpath.ts), not through the form.
 *
 * The form flaked: the login page re-renders between filling and submitting often enough that Moodle receives an
 * empty password or a stale login token ("Your session has timed out") - on Moodle 4.5 in the re-audit run of
 * 20.11.2026 in two of the CAT manager and statistics stories, as before in local_adele. The request API shares its
 * cookies with the page, so the page is logged in afterwards; the generous timeout covers the cold caches of the
 * first request against a fresh site.
 *
 * @param page The Playwright page.
 */
export const login = async(page: Page): Promise<void> => loginOverHttp(page, adminUser(), adminPass());

/**
 * Opens the CAT manager on the seeded scale and switches to the questions tab.
 *
 * @param page The Playwright page.
 * @param extraParams Additional query parameters, e.g. the search term.
 */
export const openQuestionsTab = async(
    page: Page,
    extraParams: Record<string, string> = {}
): Promise<void> => {
    const params = new URLSearchParams({
        scaleid: scaleId(),
        contextid: contextId(),
        ...extraParams,
    });

    await page.goto(`/local/catquiz/manage_catscales.php?${params.toString()}`);

    // The questions table lives in a Bootstrap tab pane that starts hidden. Clicking
    // the tab is what makes it visible; asserting on the pane before that would pass
    // against markup nobody can see.
    await page.click('#local-catquiz-manager-questions-tab');
    await expect(page.locator('#local-catquiz-manager-questions')).toBeVisible();

    // The table renders its rows through an AJAX reload, so waiting for the table
    // element alone is not enough.
    await expect(page.locator('#local-catquiz-manager-questions table')).toBeVisible();
    await waitForTableSettled(page);
};

/**
 * Waits until the table has finished its AJAX reload.
 *
 * The list repaints itself through a web service call, and during that call the
 * previous - unfiltered - rows are still in the DOM. Counting rows without waiting
 * therefore measured whichever state happened to be rendered at that moment, which
 * showed up as a different test failing on every run.
 *
 * @param page The Playwright page.
 */
export const waitForTableSettled = async(page: Page): Promise<void> => {
    const spinner = page.locator('#local-catquiz-manager-questions .wb-table-call-spinner');
    if (await spinner.count() > 0) {
        await expect(spinner.first()).toBeHidden();
    }
    // Either rows or the explicit "no records" message must be present. Waiting for
    // one of the two is what distinguishes "finished and empty" from "still loading".
    await expect(
        page.locator('#local-catquiz-manager-questions table tbody tr, #local-catquiz-manager-questions .norecordsfound').first()
    ).toBeVisible();
};

/**
 * Returns the locator for the question list rows.
 *
 * @param page The Playwright page.
 */
export const questionRows = (page: Page) => page.locator('#local-catquiz-manager-questions table tbody tr');

/**
 * Returns the locator for the "no records found" message.
 *
 * @param page The Playwright page.
 */
export const noRecordsMessage = (page: Page) => page.locator('#local-catquiz-manager-questions .norecordsfound');

/**
 * Submits the question text search and waits for the list to settle.
 *
 * @param page The Playwright page.
 * @param term The search term.
 */
export const searchQuestionText = async(page: Page, term: string): Promise<void> => {
    await page.fill('#local-catquiz-qtsearch', term);
    await page.click('#local-catquiz-questiontext-search button[type="submit"]');
    await expect(page.locator('#local-catquiz-manager-questions')).toBeVisible();
    await waitForTableSettled(page);
};
