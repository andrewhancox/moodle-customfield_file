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

namespace customfield_file\task;

use core\task\manager;
use core_customfield_generator;
use customfield_file\stdClass;

/**
 * Functional test for task update_file_context
 *
 * @package    customfield_file
 * @copyright  2024 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_file_context_test extends \advanced_testcase {

    /**
     * Get generator
     * @return core_customfield_generator
     */
    protected function get_generator() : core_customfield_generator {
        return $this->getDataGenerator()->get_plugin_generator('core_customfield');
    }

    /**
     * Test if the context is correctly set
     *
     * @covers ::update_file_context
     */
    public function test_context() {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cfcat = $this->get_generator()->create_category();
        $cfield = $this->get_generator()->create_field(
            ['categoryid' => $cfcat->get('id'), 'shortname' => 'myfield1', 'type' => 'file']);
        $course = $this->getDataGenerator()->create_course();
        $contextcourse = \context_course::instance($course->id);
        $usercontext = \context_user::instance($USER->id);

        // Create a user draft file for file customfield.
        $fs = get_file_storage();
        $userfilerecord = new \stdClass;
        $userfilerecord->contextid = $usercontext->id;
        $userfilerecord->component = 'user';
        $userfilerecord->filearea  = 'draft';
        $userfilerecord->itemid    = 123456;
        $userfilerecord->filepath  = '/';
        $userfilerecord->filename  = 'customfield.txt';
        $userfilerecord->source    = 'O:8:"stdClass":1:{s:6:"source";s:11:"example.csv";}';
        $userfile = $fs->create_file_from_string($userfilerecord, 'Test content');

        $cfdata = $this->get_generator()->add_instance_data($cfield,
            $course->id, $userfile->get_itemid());

        // Check customfield_data context.
        $this->assertEquals($contextcourse->id, $cfdata->get('contextid'));
        // Check file context.
        $params = ['component' => 'customfield_file', 'filearea' => 'value', 'itemid' => $cfdata->get('id')];
        $where = "component = :component AND filearea = :filearea AND itemid = :itemid AND filename != '.'";
        $files = $DB->get_records_select('files', $where, $params);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertEquals($contextcourse->id, $file->contextid);

        // Update record with wrong contextid.
        $file->contextid = 999999;
        $DB->update_record('files', $file);
        $file = $DB->get_record_select('files', $where, $params);
        $this->assertNotEquals($contextcourse->id, $file->contextid);

        // Run adhoc task to set correct contextid.
        manager::queue_adhoc_task(new update_file_context());
        $this->runAdhocTasks();

        // The contextid is fixed correctly.
        $file = $DB->get_record_select('files', $where, $params);
        $this->assertEquals($contextcourse->id, $file->contextid);
    }

}
