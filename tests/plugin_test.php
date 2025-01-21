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

namespace customfield_file;

use backup;
use backup_controller;
use context_user;
use core_customfield_generator;
use core_customfield_test_instance_form;
use core_customfield\data;
use restore_controller;
use restore_dbops;
use stdClass;

/**
 * Functional test for customfield_file
 *
 * @package    customfield_file
 * @copyright  2024 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugin_test extends \advanced_testcase {

    /** @var stdClass  */
    private $course;
    /** @var \core_customfield\category_controller */
    private $cfcat;
    /** @var \core_customfield\field_controller */
    private $cfield;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->cfcat = $this->get_generator()->create_category();
        $this->cfield = $this->get_generator()->create_field(
            ['categoryid' => $this->cfcat->get('id'), 'shortname' => 'myfield1', 'type' => 'file']);
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Get generator
     * @return core_customfield_generator
     */
    protected function get_generator(): core_customfield_generator {
        return $this->getDataGenerator()->get_plugin_generator('core_customfield');
    }

    /**
     * Test if the context is correctly set
     *
     * @covers ::instance_form_save
     */
    public function test_context() {
        global $DB, $USER;
        $contextcourse = \context_course::instance($this->course->id);
        $usercontext = \context_user::instance($USER->id);
        // Create a user draft file for file customfield.
        $fs = get_file_storage();
        $userfilerecord = new stdClass;
        $userfilerecord->contextid = $usercontext->id;
        $userfilerecord->component = 'user';
        $userfilerecord->filearea  = 'draft';
        $userfilerecord->itemid    = 123456;
        $userfilerecord->filepath  = '/';
        $userfilerecord->filename  = 'customfield.txt';
        $userfilerecord->source    = 'test';
        $userfile = $fs->create_file_from_string($userfilerecord, 'Test content');

        $cfdata = $this->get_generator()->add_instance_data($this->cfield,
            $this->course->id, $userfile->get_itemid());

        // Check customfield_data context.
        $this->assertEquals($contextcourse->id, $cfdata->get('contextid'));
        // Check file context.
        $params = ['component' => 'customfield_file', 'filearea' => 'value', 'itemid' => $cfdata->get('id')];
        $where = "component = :component AND filearea = :filearea AND itemid = :itemid AND filename != '.'";
        $files = $DB->get_records_select('files', $where, $params);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertEquals($contextcourse->id, $file->contextid);
    }

    /**
     * Test file backup and restore.
     *
     * @covers \customfield_file\data_controller::backup_define_structure
     * @covers \customfield_file\data_controller::backup_restore_structure
     */
    public function test_file_backup_and_restore(): void {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/customfield/tests/fixtures/test_instance_form.php');

        $this->setAdminUser();

        $handler = $this->cfcat->get_handler();

        // Create a file.
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => 'mytextfile.txt',
        ];
        $myfile = $fs->create_file_from_string($filerecord, 'Some text contents');

        // Add the file to the custom field and submit.
        $formdata = array_merge((array) $this->course, ['customfield_' . $this->cfield->get('shortname') => $filerecord['itemid']]);
        core_customfield_test_instance_form::mock_submit($formdata, []);

        $form = new core_customfield_test_instance_form('POST', ['handler' => $handler, 'instance' => $this->course]);
        $this->assertTrue($form->is_validated());

        $formsubmission = $form->get_data();
        $this->assertNotEmpty($formsubmission->customfield_myfield1);
        $handler->instance_form_save($formsubmission);

        // Verify the draft file exists.
        $context = $handler->get_instance_context($formsubmission->id);
        $file = $fs->get_file($filerecord['contextid'], $filerecord['component'], $filerecord['filearea'], $filerecord['itemid'],
            $filerecord['filepath'], $filerecord['filename']);
        $this->assertNotEmpty($file);

        // Verify the permanent file exists.
        $datainstance = data::get_record(['fieldid' => $this->cfield->get('id'), 'instanceid' => $formsubmission->id]);
        $files = get_file_storage()->get_area_files($datainstance->get('contextid'), 'customfield_file', 'value',
            $datainstance->get('id'), '', false);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertEquals('/', $file->get_filepath());
        $this->assertEquals('mytextfile.txt', $file->get_filename());

        // Backup and restore the course.
        $backupid = $this->backup($this->course);
        $newcourseid = $this->restore($backupid, $this->course, '_copy');

        // Verify that the permanent file exists in the new course after restore.
        $newcontext = $handler->get_instance_context($newcourseid);
        $newcfdata = $DB->get_record('customfield_data', ['instanceid' => $newcourseid, 'fieldid' => $this->cfield->get('id')]);
        $file = $fs->get_file($newcontext->id, 'customfield_file', 'value', $newcfdata->id, '/', 'mytextfile.txt');
        $this->assertNotEmpty($file);
    }

    /**
     * Backs up a course to a temp directory.
     *
     * @param  stdClass $course The course object to back up.
     * @return string ID of backup.
     */
    protected function backup(stdClass $course): string {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        // Initialise backup controller with default settings.
        // MODE_IMPORT means it will just create the directory and not zip it.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );

        // Configure back up settings.
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value(true);

        // Execute the backup plan and retrieve the backup ID.
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restores a course from a backup and returns the new course ID.
     *
     * @param  string   $backupid The backup ID to restore.
     * @param  stdClass $course   The course object containing the original course details.
     * @param  string   $suffix   The suffix to append to the course shortname and fullname.
     * @return int New course id
     */
    protected function restore(string $backupid, stdClass $course, string $suffix): int {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Restore to new course with default settings.
        $newcourseid = restore_dbops::create_new_course(
            $course->fullname . $suffix,
            $course->shortname . $suffix,
            $course->category
        );

        // Initialise the restore controller.
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );

        // Configure the restore settings.
        $rc->get_plan()->get_setting('logs')->set_value(true);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->get_plan()->get_setting('customfields')->set_value(true);

        // Execute the restore process.
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
