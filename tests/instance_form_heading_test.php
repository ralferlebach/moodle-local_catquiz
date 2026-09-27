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

namespace local_catquiz;

use advanced_testcase;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The CAT model section of the activity form works with the heading the host actually has.
 *
 * The 3.0 line of mod_adaptivequiz names that heading 'catmodelheader'; the 1.2 line called it
 * 'advancedheading'. The form hook asked for 'advancedheading' unconditionally. QuickForm answers a
 * missing element with a PEAR_Error, which under PHP 8 ends in 'Non-static method
 * PEAR::getStaticProperty() cannot be called statically' - 23 of 35 Behat scenarios on the
 * migration branch failed on it, every one that opened the activity form.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\catquiz_handler
 */
final class instance_form_heading_test extends advanced_testcase {
    /**
     * Builds the part of the activity form the hook works on, with the given heading.
     *
     * @param string $headingname Name of the heading the host provides.
     * @return MoodleQuickForm
     */
    private function form_with_heading(string $headingname): MoodleQuickForm {
        $mform = new MoodleQuickForm('mod_adaptivequiz_mod_form', 'post', '');
        $mform->addElement('header', $headingname, 'Heading as the host names it');

        return $mform;
    }

    /**
     * With the heading of the 3.0 host, the hook runs and relabels it.
     */
    public function test_the_heading_of_the_current_host(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = $this->form_with_heading('catmodelheader');
        catquiz_handler::instance_form_definition($mform);

        $this->assertEquals(
            get_string('catmodelsettings', 'local_catquiz'),
            $mform->getElement('catmodelheader')->_text,
            'The CAT model heading was not relabelled.'
        );
    }

    /**
     * With the heading of the 1.2 host, the hook runs as before.
     */
    public function test_the_heading_of_the_previous_host(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = $this->form_with_heading('advancedheading');
        catquiz_handler::instance_form_definition($mform);

        $this->assertEquals(
            get_string('catmodelsettings', 'local_catquiz'),
            $mform->getElement('advancedheading')->_text
        );
    }
}
