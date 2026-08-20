<?php
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
 * Defines message providers (types of messages being sent)
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * To create catquiz specific behat scearios.
 * @package local_catquiz
 */
class behat_catquiz extends behat_base {
    /**
     * Fill specified HTMLQuickForm element by its number under goven xpath with a value.
     * @When /^I fill in the "([^"]*)" element number "([^"]*)" with the dynamic identifier "([^"]*)" with "([^"]*)"$/
     *
     * @param string $fieldtype
     * @param string $numberofitem
     * @param string $dynamicidentifier
     * @param string $value
     *
     * @return void
     *
     */
    public function i_fill_in_the_element_with_dynamic_identifier($fieldtype, $numberofitem, $dynamicidentifier, $value) {
        // Use $dynamicIdentifier to locate and fill in the corresponding form field.
        // Use $value to set the desired value in the form field.

        // First we need to open all collapsibles.
        // We should probably have a single fuction for that.
        $xpathtarget = "//div[contains(@id, 'catquiz_feedback_collapse_')]";
        $fields = $this->getSession()->getPage()->findAll('xpath', $xpathtarget);

        foreach ($fields as $field) {
            $id = $field->getAttribute('id');
            // Use JavaScript to add the expected class to the element.
            $script = "document.getElementById('$id').classList.add('show');";
            $this->getSession()->executeScript($script);
            $this->getSession()->wait(500);
        }
        // Now we get the form element fields by the identifier and its number in DOM.
        switch ($fieldtype) {
            case 'editor':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')][@contenteditable='true'])
                    [" . $numberofitem . "]";
                break;
            case 'autocomplete':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//input[contains(@id, 'form_autocomplete_input-')]";
                $xpathtarget1 = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//li[contains(@id, 'form_autocomplete_suggestions-')]";
                break;
            case 'wb_colourpicker':
                $xpathtarget = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                    [" . $numberofitem . "]//span[@data-colour=" . $value . "]";
                break;
            default:
                $xpathtarget = "(//" . $fieldtype . "[contains(@id, '" . $dynamicidentifier . "')])[" . $numberofitem . "]";
        }

        // Assuming you want to find an editor element related to the competency and fill it with the specified value.
        $field = $this->getSession()->getPage()->find('xpath', $xpathtarget);
        if ($field->isVisible()) {
            switch ($fieldtype) {
                case 'autocomplete':
                    $field->setValue($value);
                    // The suggestion list is populated asynchronously (debounced
                    // AJAX). Wait for the suggestion that actually contains the
                    // requested value instead of pressing Enter and clicking the
                    // first list item: Enter may already commit the top item, in
                    // which case a subsequent click hits a different (or already
                    // selected) entry and the selection is lost again.
                    $escapedvalue = behat_context_helper::escape($value);
                    $suggestionxpath = $xpathtarget1 . "[contains(normalize-space(.), $escapedvalue)]";
                    $suggestion = null;
                    for ($attempt = 0; $attempt < 20; $attempt++) {
                        $suggestion = $this->getSession()->getPage()->find('xpath', $suggestionxpath);
                        if ($suggestion) {
                            break;
                        }
                        $this->getSession()->wait(300);
                    }
                    if (!$suggestion) {
                        throw new \Behat\Mink\Exception\ExpectationException(
                            "Autocomplete suggestion containing '$value' did not appear",
                            $this->getSession()
                        );
                    }
                    $suggestion->click();
                    // Close the suggestion list so it cannot overlap and swallow
                    // clicks meant for the elements that are filled next.
                    $field->keyPress(27);
                    // The visible autocomplete widget and the underlying native
                    // <select multiple> can drift apart: clicking a suggestion may
                    // fail to mark the matching <option> as selected, and it is the
                    // native <select> — not the visible chips — that is submitted
                    // with the form. If it is left unselected the value is silently
                    // dropped on save and is gone after reload (Behat 001 /
                    // catquiz_courses). Enforce the native selection as the source
                    // of truth and notify listeners so the widget re-renders.
                    $containerxpath = "(//div[contains(@id, '" . $dynamicidentifier . "')])
                        [" . $numberofitem . "]";
                    $this->ensure_native_select_option_selected($containerxpath, $value);
                    break;
                case 'wb_colourpicker':
                    $field->click();
                    break;
                default:
                    // Fill in the form field with the specified value.
                    $field->setValue($value);
            }
        }
    }

    /**
     * Ensures the native <select> inside a container has the option with the
     * given visible text selected, then notifies listeners.
     *
     * Moodle's autocomplete enhancement hides the real <select> and drives its
     * own visible widget. When a test operates the visible widget, the native
     * <select> can be left without a selected <option>; since the <select> is
     * what the form submits, the value is silently dropped on save. This makes
     * the native selection the source of truth and dispatches change events so
     * the visible widget re-renders to match.
     *
     * @param string $containerxpath Xpath of the form item wrapping the select.
     * @param string $value          The visible option text to select.
     *
     * @return void
     */
    protected function ensure_native_select_option_selected($containerxpath, $value) {
        $select = $this->getSession()->getPage()->find('xpath', $containerxpath . "//select");
        if (!$select) {
            return;
        }
        $selectid = $select->getAttribute('id');
        if (empty($selectid)) {
            return;
        }
        $jsid = json_encode($selectid);
        $jsvalue = json_encode($value);
        $js = "(function(){"
            . "var select=document.getElementById($jsid);"
            . "if(!select){return;}"
            . "var wanted=$jsvalue;"
            . "for(var i=0;i<select.options.length;i++){"
            . "var opt=select.options[i];"
            . "if(opt.text.trim()===wanted||opt.textContent.indexOf(wanted)!==-1){opt.selected=true;}"
            . "}"
            . "select.dispatchEvent(new Event('change',{bubbles:true}));"
            . "if(window.jQuery){window.jQuery(select).trigger('change');}"
            . "})();";
        $this->getSession()->executeScript($js);
        $this->getSession()->wait(300);
    }

    /**
     * Asserts that the native <select> of an autocomplete has an option with the
     * given text selected.
     *
     * This checks the value that is actually submitted with the form, which is
     * independent of the visible autocomplete chips. Placing this assertion at
     * the hand-over points (right after selection, after a failed validation
     * submit, and after save + reload) localises exactly where a selection is
     * lost (Behat 001 / catquiz_courses).
     *
     * @Then /^the autocomplete number "([^"]*)" for "([^"]*)" has "([^"]*)" natively selected$/
     *
     * @param string $numberofitem
     * @param string $dynamicidentifier
     * @param string $value
     *
     * @return void
     */
    public function autocomplete_should_have_natively_selected($numberofitem, $dynamicidentifier, $value) {
        $containerxpath = "(//div[contains(@id, '" . $dynamicidentifier . "')])[" . $numberofitem . "]";
        $select = $this->getSession()->getPage()->find('xpath', $containerxpath . "//select");
        if (!$select) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "No native <select> found under '$dynamicidentifier' number '$numberofitem'",
                $this->getSession()
            );
        }
        foreach ($select->findAll('xpath', ".//option") as $option) {
            if ($option->isSelected() && strpos($option->getText(), $value) !== false) {
                return;
            }
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            "Native <select> under '$dynamicidentifier' number '$numberofitem' "
                . "has no selected option containing '$value'",
            $this->getSession()
        );
    }
}
