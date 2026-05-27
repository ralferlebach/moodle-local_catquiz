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
 * JavaScript for mod_form to reload when a CAT model has been chosen.
 *
 * @module     mod_adaptivequiz/catquizTestChooser
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    CATTESTCHOOSER: '[data-on-change-action]',
    CATTESTSUBMIT: '[data-action="submitCatTest"]',
    CATSCALESUBMIT: '[data-action="submitCatScale"]',
    CATSCALESUBMITCONTAINER: '[id="fitem_id_submitcatscaleoption"]',
    CATTESTCHECKBOXES: 'input[name^="catquiz_subscalecheckbox"]',
    REPORTSCALECHECKBOXES: 'input[id^="id_catquiz_scalereportcheckbox"]',
    NUMBEROFFEEDBACKSSUBMIT: '[data-action="submitNumberOfFeedbackOptions"]',
    FEEDBACKVALUESSUBMIT: '[data-action="submitFeedbackValues"]'
};

/**
 * Initialise it all.
 */
export const init = () => {

    const selectors = document.querySelectorAll(SELECTORS.CATTESTCHOOSER);
    const checkboxes = document.querySelectorAll(SELECTORS.CATTESTCHECKBOXES);
    const reportscalecheckboxes = document.querySelectorAll(SELECTORS.REPORTSCALECHECKBOXES);
    const feedbacksubmitbuttons = document.querySelectorAll(SELECTORS.FEEDBACKVALUESSUBMIT);

    var elements = new Set([
        ...selectors,
        ...checkboxes
    ]);
    if (!elements) {
        return;
    }

    if (elements.length === 0) {
        return;
    }
    elements.forEach(selector =>
        selector.addEventListener('change', e => {
            // Setting defines if reload should be triggered automatically.
            if (e.target.dataset.manualreload) {
                let submitbuttoncontainer = document.querySelector(SELECTORS.CATSCALESUBMITCONTAINER);
                submitbuttoncontainer.classList.remove('hidden');

                let submitbutton = document.querySelector(SELECTORS.CATSCALESUBMIT);
                submitbutton.classList.remove('btn-primary');
                submitbutton.classList.add('btn-danger');
                submitbutton.classList.remove('hidden');
                return;
            }
            let triggeredButtonField = document.getElementsByName('triggered_button')[0];
            triggeredButtonField.value = '';

            switch (e.target.dataset.onChangeAction) {
                case 'reloadTestForm':
                    document.getElementsByName('triggered_button')[0].value = 'reloadTestForm';
                    clickNoSubmitButton(e.target, SELECTORS.CATTESTSUBMIT);
                    break;
                case 'reloadFormFromScaleSelect':
                    clickNoSubmitButton(e.target, SELECTORS.CATSCALESUBMIT);
                    break;
                case 'numberOfFeedbacksSubmit':
                    clickNoSubmitButton(e.target, SELECTORS.NUMBEROFFEEDBACKSSUBMIT);
                    break;
            }

        })
    );

    // Add a listener to the report checkboxes
    var checkboxelements = new Set([
        ...reportscalecheckboxes
    ]);
    feedbacksubmitbuttons.forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            copySettingsToSubscales(button);
        });
    });

    if (!checkboxelements || checkboxelements.length == 0) {
        return;
    }

    // On the first run when the page is loaded set the status according to
    // saved fields and add event listeners.
    checkboxelements.forEach(selector => {
        setCardDisabledStatus(selector);
        selector.addEventListener('change', e => setCardDisabledStatus(e.target));
    });
};

/**
 * Checks the report scale checkbox and disables/enables the input fields accordingly
 *
 * @param {HTMLElement} element
 */
function setCardDisabledStatus(element) {
    let reportScale = element.checked;
    let ownId = element.id || element.name;
    // Get the closest parent.
    let cardBody = element.closest('.card-body');
    if (!reportScale) {
        cardBody.classList.add('card-body-disabled');
    } else {
        cardBody.classList.remove('card-body-disabled');
    }
    // We want to just disable the form fields for the currently selected scale, not the nested scales.
    let currentScaleFields = [...cardBody.children].filter(c => !c.id.match(/^accordion/));

    currentScaleFields.forEach(element => {
        // Add or remove a 'disabled' class to all child input elements.
        Array.from(element.getElementsByTagName('input'))
            .forEach((i) => {
                if (i.id == ownId) {
                    return;
                }
                if (!reportScale) {
                    i.classList.add('disabled');
                } else {
                    i.classList.remove('disabled');
                }
            });

        // Set the 'contenteditable' attribute of the text editor to disable/enable editing.
        Array.from(element.getElementsByClassName('editor_atto_content'))
            .forEach((el) => {
                el.setAttribute('contenteditable', reportScale);
            });
    });
}

/**
 * No Submit Button triggered.
 * @param {HTMLElement} element
 * @param {string} buttonselector
 */
function clickNoSubmitButton(element, buttonselector) {

    const form = element.closest('form');
    // Find container for query selector.
    const submitCatTest = form.querySelector(buttonselector);
    const fieldset = submitCatTest.closest('fieldset');

    // eslint-disable-next-line no-console
    console.log(submitCatTest, 'submitCatTest');

    const url = new URL(form.action);
    url.hash = fieldset.id;

    form.action = url.toString();
    prepareNoSubmitButton(submitCatTest);
    submitCatTest.click();
}

/**
 * Ensures no-submit buttons are consistently present in the submitted payload.
 *
 * @param {HTMLElement} button
 */
function prepareNoSubmitButton(button) {
    const form = button.closest('form');
    const triggeredButtonField = form.querySelector('[name="triggered_button"]');

    if (triggeredButtonField) {
        triggeredButtonField.value = button.name;
    }

    let hiddenButtonField = form.querySelector('[data-nosubmit-proxy-for="' + button.name + '"]');
    if (!hiddenButtonField) {
        hiddenButtonField = document.createElement('input');
        hiddenButtonField.type = 'hidden';
        hiddenButtonField.name = button.name;
        hiddenButtonField.setAttribute('data-nosubmit-proxy-for', button.name);
        form.appendChild(hiddenButtonField);
    }

    hiddenButtonField.value = button.value || '1';
}

/**
 * Copies all feedback settings from one scale to the selected subscales.
 *
 * @param {HTMLElement} button
 */
function copySettingsToSubscales(button) {
    setCopyButtonStatus(button, button.dataset.copyingText);

    const form = button.closest('form');
    const sourceScaleId = button.dataset.sourceScaleId;
    const subscaleIds = (button.dataset.subscaleIds || '')
        .split(',')
        .map(id => id.trim())
        .filter(id => id.length > 0);
    const numberOfFeedbackOptions = parseInt(getElementValue(form, 'numberoffeedbackoptionsselect'), 10) || 0;
    const rangePrefixes = [
        'feedback_scaleid_limit_lower_',
        'feedback_scaleid_limit_upper_',
        'wb_colourpicker_',
        'feedbackeditor_scaleid_',
        'catquiz_group_',
        'catquiz_courses_',
        'enrolment_message_checkbox_',
        'feedbacklegend_scaleid_'
    ];
    const scalePrefixes = [
        'catquiz_scalereportcheckbox_'
    ];
    const selectedSubscaleIds = subscaleIds
        .filter(subscaleId => hasCopyTarget(form, subscaleId));

    selectedSubscaleIds.forEach(subscaleId => {
            setSubscaleSelected(form, subscaleId);
            scalePrefixes.forEach(prefix => {
                copyFieldGroup(form, prefix + sourceScaleId, prefix + subscaleId);
            });

            for (let index = 1; index <= numberOfFeedbackOptions; index++) {
                rangePrefixes.forEach(prefix => {
                    copyFieldGroup(
                        form,
                        prefix + sourceScaleId + '_' + index,
                        prefix + subscaleId + '_' + index
                    );
                });
            }
        });

    if (selectedSubscaleIds.length === 0) {
        setCopyButtonStatus(button, button.dataset.nothingCopiedText);
        return;
    }

    setCopyButtonStatus(button, button.dataset.copiedText.replace('{$a}', selectedSubscaleIds.length));
}

/**
 * Checks whether a subscale has copy targets in the current form.
 *
 * @param {HTMLFormElement} form
 * @param {string} subscaleId
 * @returns {boolean}
 */
function hasCopyTarget(form, subscaleId) {
    return getNamedElements(form, 'catquiz_scalereportcheckbox_' + subscaleId).length > 0;
}

/**
 * Marks a subscale checkbox as selected when present.
 *
 * @param {HTMLFormElement} form
 * @param {string} subscaleId
 */
function setSubscaleSelected(form, subscaleId) {
    const checkbox = getNamedElements(form, 'catquiz_subscalecheckbox_' + subscaleId)[0];

    if (!checkbox) {
        return;
    }

    checkbox.checked = true;
    checkbox.value = '1';
}

/**
 * Copies one field group, including editor subfields such as [text], [format], [itemid].
 *
 * @param {HTMLFormElement} form
 * @param {string} sourceBaseName
 * @param {string} targetBaseName
 */
function copyFieldGroup(form, sourceBaseName, targetBaseName) {
    const sourceElements = getNamedElements(form, sourceBaseName);

    sourceElements.forEach(sourceElement => {
        const targetName = sourceElement.name.replace(sourceBaseName, targetBaseName);
        const targetElement = getNamedElements(form, targetName)[0];

        if (!targetElement) {
            return;
        }

        copyElementValue(sourceElement, targetElement);

        if (targetName === targetBaseName || targetName === targetBaseName + '[text]') {
            updateEditorDisplay(targetElement);
        }
    });
}

/**
 * Returns all form controls matching the given name or editor subfield prefix.
 *
 * @param {HTMLFormElement} form
 * @param {string} baseName
 * @returns {HTMLElement[]}
 */
function getNamedElements(form, baseName) {
    return Array.from(form.elements).filter(element =>
        element.name === baseName || element.name.startsWith(baseName + '[')
    );
}

/**
 * Returns the scalar value of a form element by name.
 *
 * @param {HTMLFormElement} form
 * @param {string} name
 * @returns {string}
 */
function getElementValue(form, name) {
    const element = getNamedElements(form, name)[0];

    return element ? element.value : '';
}

/**
 * Copies the value from one control to another.
 *
 * @param {HTMLElement} sourceElement
 * @param {HTMLElement} targetElement
 */
function copyElementValue(sourceElement, targetElement) {
    if (targetElement.type === 'checkbox' || targetElement.type === 'radio') {
        targetElement.checked = sourceElement.checked;
    } else if (targetElement.tagName === 'SELECT' && targetElement.multiple) {
        const selectedValues = getAutocompleteValues(sourceElement);

        Array.from(targetElement.options).forEach(option => {
            option.selected = selectedValues.includes(option.value);
        });

        updateAutocompleteDisplay(targetElement, sourceElement);
    } else if (targetElement.tagName === 'SELECT') {
        targetElement.value = sourceElement.value;
        updateColourPickerDisplay(targetElement);
    } else if (targetElement.tagName === 'TEXTAREA') {
        targetElement.value = getEditorValue(sourceElement);
    } else {
        targetElement.value = sourceElement.value;
    }

    notifyFieldChanged(targetElement);
}

/**
 * Mirrors copied editor values into the visible Atto content area.
 *
 * @param {HTMLElement} targetElement
 */
function updateEditorDisplay(targetElement) {
    const editorContainer = targetElement.closest('.editor');

    if (typeof window.tinymce !== 'undefined' && targetElement.id) {
        const editor = window.tinymce.get(targetElement.id);

        if (editor) {
            editor.setContent(targetElement.value || '');
            editor.save();
        }
    }

    if (!editorContainer) {
        return;
    }

    const editable = editorContainer.querySelector('.editor_atto_content');
    if (!editable) {
        return;
    }

    editable.innerHTML = targetElement.value;
}

/**
 * Returns the current editor value, including TinyMCE content.
 *
 * @param {HTMLElement} sourceElement
 * @returns {string}
 */
function getEditorValue(sourceElement) {
    if (typeof window.tinymce !== 'undefined' && sourceElement.id) {
        const editor = window.tinymce.get(sourceElement.id);

        if (editor) {
            return editor.getContent();
        }
    }

    return sourceElement.value;
}

/**
 * Returns the selected values of a Moodle autocomplete/select field.
 *
 * @param {HTMLSelectElement} sourceElement
 * @returns {string[]}
 */
function getAutocompleteValues(sourceElement) {
    const selectedValues = Array.from(sourceElement.options)
        .filter(option => option.selected)
        .map(option => option.value);

    if (selectedValues.length > 0) {
        return selectedValues;
    }

    const autocompleteContainer = sourceElement.closest('.form-autocomplete-original-select') || sourceElement.parentElement;
    const selectionContainer = autocompleteContainer
        ? autocompleteContainer.parentElement.querySelector('.form-autocomplete-selection')
        : null;

    if (!selectionContainer) {
        return [];
    }

    return Array.from(selectionContainer.querySelectorAll('[data-value]'))
        .map(item => item.getAttribute('data-value'))
        .filter(value => value !== null && value !== '');
}

/**
 * Mirrors selected values into the visible Moodle autocomplete badges.
 *
 * @param {HTMLSelectElement} targetElement
 * @param {HTMLSelectElement} sourceElement
 */
function updateAutocompleteDisplay(targetElement, sourceElement) {
    const selectionContainer = findAutocompleteSelectionContainer(targetElement);
    const sourceSelectionContainer = findAutocompleteSelectionContainer(sourceElement);

    if (!selectionContainer) {
        return;
    }

    if (sourceSelectionContainer) {
        selectionContainer.innerHTML = sourceSelectionContainer.innerHTML;
        selectionContainer.removeAttribute('aria-activedescendant');
        selectionContainer.removeAttribute('data-active-value');
        Array.from(selectionContainer.querySelectorAll('[id]')).forEach(node => node.removeAttribute('id'));
        Array.from(selectionContainer.querySelectorAll('[data-active-selection]'))
            .forEach(node => node.removeAttribute('data-active-selection'));
        return;
    }

    const selectedOptions = Array.from(targetElement.options)
        .filter(option => option.selected && option.value !== '0' && option.value !== '');

    if (selectedOptions.length === 0) {
        selectionContainer.innerHTML = '<span class="m-1 h-5">Select course</span>';
    }
}

/**
 * Updates the visible colour picker circles to match the copied select value.
 *
 * @param {HTMLSelectElement} targetElement
 */
function updateColourPickerDisplay(targetElement) {
    const picker = document.querySelector('span[data-id="wb_colourpick_id_' + targetElement.name + '"]');

    if (!picker) {
        return;
    }

    const circles = Array.from(picker.querySelectorAll('.colourpickercircle'));
    const selectedCircle = circles.find(circle => circle.dataset.colour === targetElement.value);
    const selectedOption = Array.from(targetElement.options)
        .find(option => option.value === targetElement.value);
    const notify = picker.querySelector('.colourselectnotify');

    circles.forEach(circle => circle.classList.remove('selected'));

    if (selectedCircle) {
        selectedCircle.classList.add('selected');
    }

    if (notify && selectedOption) {
        notify.textContent = selectedOption.textContent.trim();
    }
}

/**
 * Finds the visible selection container for a Moodle autocomplete field.
 *
 * @param {HTMLSelectElement} element
 * @returns {HTMLElement|null}
 */
function findAutocompleteSelectionContainer(element) {
    const container = element.parentElement;

    if (!container) {
        return null;
    }

    return container.querySelector('.form-autocomplete-selection');
}


/**
 * Notifies both native listeners and jQuery-based Moodle widgets.
 *
 * @param {HTMLElement} targetElement
 */
function notifyFieldChanged(targetElement) {
    targetElement.dispatchEvent(new Event('input', {bubbles: true}));
    targetElement.dispatchEvent(new Event('change', {bubbles: true}));

    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(targetElement).trigger('change');
    }
}

/**
 * Shows a short status message next to the copy button.
 *
 * @param {HTMLElement} button
 * @param {string} message
 */
function setCopyButtonStatus(button, message) {
    let statusNode = button.parentElement.querySelector('[data-copy-status-for="' + button.name + '"]');

    if (!statusNode) {
        statusNode = document.createElement('span');
        statusNode.className = 'ml-2 text-muted small';
        statusNode.setAttribute('data-copy-status-for', button.name);
        statusNode.setAttribute('role', 'status');
        statusNode.setAttribute('aria-live', 'polite');
        button.parentElement.appendChild(statusNode);
    }

    statusNode.textContent = message || '';
}
