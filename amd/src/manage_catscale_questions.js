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

/*
 * @package    local_catquiz
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';

const SELECTORS = {
    FORMCONTAINER: '#select_context_form',
    CHECKBOX: 'input.integrate-subscales-checkbox',
};
/**
 * Initialize the form with event listeners.
 */
export const init = () => {
    initDynamicForm();
    initCheckbox();

};

/**
 * Initialize the checkbox.
 */
function initCheckbox() {

    const checkbox = document.querySelector(SELECTORS.CHECKBOX);

    // eslint-disable-next-line no-console
    console.log('checkbox init', checkbox);

    checkbox.addEventListener('click', e => {

        if (checkbox.checked === true) {
            // eslint-disable-next-line no-console
            console.log("checkbox checked ", e);
        } else {
            // eslint-disable-next-line no-console
            console.log("checkbox unchecked");
        }

    });
}

/**
 * Add event listener to select and set URL params.
 *
 */
function initDynamicForm() {
    // Initialize the form - pass the container element and the form class name.
    const dynamicForm = new DynamicForm(document.querySelector(
        SELECTORS.FORMCONTAINER),
        'local_catquiz\\form\\contextselector'
    );

    // eslint-disable-next-line no-console
    console.log("dynamicForm", dynamicForm);
    // If a user selects a context, redirect to a URL that includes the selected
    // context as `contextid` query parameter
    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;

        if (!response.contextid) {
            return;
        }

        let searchParams = new URLSearchParams(window.location.search);
        searchParams.set("contextid", response.contextid);
        window.location.search = searchParams.toString();
    });

    // If a user selects a cat context, submit the form without waiting for the
    // user to click the submit button
    dynamicForm.addEventListener('change', (e) => {
        e.preventDefault();

        // We have to wait a little bit so that the data are included in the submit
        // request
        setTimeout(() => {
            dynamicForm.submitFormAjax();
        }, 500);
    });
};