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
 * @package customfield_file
 * @author Andrew Hancox <andrewdchancox@googlemail.com>
 * @author Open Source Learning <enquiries@opensourcelearning.co.uk>
 * @link https://opensourcelearning.co.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2021, Andrew Hancox
 */

namespace customfield_file;

use moodle_url;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die;

class data_controller extends \core_customfield\data_controller {

    /**
     * Return the name of the field where the information is stored
     * @return string
     */
    public function datafield(): string {
        return 'intvalue';
    }

    /**
     * Add fields for editing a file field.
     *
     * @param MoodleQuickForm $mform
     */
    public function instance_form_definition(MoodleQuickForm $mform) {
        $mform->addElement('filemanager', $this->get_form_element_name(), $this->get_field()->get_formatted_name(), null, $this->get_filemanageroptions());
    }

    private function get_filemanageroptions() {
        $field = $this->get_field();

        return [
            'maxfiles' => $field->get_configdata_property('maximumfiles'),
            'maxbytes' => $field->get_configdata_property('maximumbytes'),
            'subdirs' => 0,
            'accepted_types' => '*'
        ];
    }

    public function instance_form_save(stdClass $datanew) {
        $fieldname = $this->get_form_element_name();
        if (!property_exists($datanew, $fieldname)) {
            return;
        }

        if (!$this->get('id')) {
            $this->data->set('value', '');
            $this->data->set('valueformat', FORMAT_MOODLE);
            $this->save();
        }

        $context = $this->get_field()->get_handler()->get_configuration_context();
        file_save_draft_area_files(
            $datanew->{$fieldname},
            $context->id,
            'customfield_file',
            "value",
            $this->get('id'),
            $this->get_filemanageroptions()
        );

        parent::instance_form_save($datanew);
    }

    /**
     * Returns the default value as it would be stored in the database (not in human-readable format).
     *
     * @return mixed
     */
    public function get_default_value() {
        return false;
    }

    public function instance_form_before_set_data($data) {
        $context = $this->get_field()->get_handler()->get_configuration_context();
        $draftid_editor = file_get_submitted_draft_itemid($this->get_form_element_name());
        file_prepare_draft_area($draftid_editor, $context->id, 'customfield_file',
            'value', $this->get('id'), $this->get_filemanageroptions());
        $data->{$this->get_form_element_name()} = $draftid_editor;
    }

    /**
     * Returns value in a human-readable format
     *
     * @return mixed|null value or null if empty
     */
    public function export_value() {
        global $OUTPUT, $PAGE;

        $context = $this->get_field()->get_handler()->get_configuration_context();
        $fs = get_file_storage();

        $files = $fs->get_area_files($context->id, 'customfield_file', "value",
            $this->get('id'),
            'timemodified',
            false);

        $data = [];

        foreach ($files as $file) {
            $url = moodle_url::make_pluginfile_url($file->get_contextid(),
                $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(),
                $file->get_filename());
            $filename = $file->get_filename();
            $data[] = (object)['url' => $url->out(false), 'filename' => $filename];
        }
        $model = (object)['files' => $data];

        $renderer = $PAGE->get_renderer('core_customfield');
        $customrenderer = 'render_customfield'
            . '_' . $this->get_field()->get_handler()->get_component()
            . '_' . $this->get_field()->get_handler()->get_area()
            . '_' . $this->get_field()->get('shortname');
        if (method_exists($renderer, $customrenderer)) {
            return $renderer->$customrenderer($model);
        }

        return $OUTPUT->render_from_template('customfield_file/exportvalue', $model);
    }

    /**
     * Delete data
     *
     * @return bool
     */
    public function delete() {
        get_file_storage()->delete_area_files($this->get('contextid'), 'customfield_file',
            'value', $this->get('id'));
        return parent::delete();
    }
}
