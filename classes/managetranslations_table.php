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
 * @package filter_translations
 * @author Andrew Hancox <andrewdchancox@googlemail.com>
 * @author Open Source Learning <enquiries@opensourcelearning.co.uk>
 * @link https://opensourcelearning.co.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2021, Andrew Hancox
 */

namespace filter_translations;

use moodle_url;
use table_sql;
use html_writer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/tablelib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

class managetranslations_table extends table_sql {
    private $languages = null;

    public function __construct($filterparams, $sortcolumn) {
        global $DB, $PAGE, $CFG, $OUTPUT;

        parent::__construct('managetranslation_table');

        $this->languages = get_string_manager()->get_list_of_translations();

        $this->filterparams = $filterparams;

        $headers = [];
        $columns = [];

        $canbulkdelete = has_capability('filter/translations:bulkdeletetranslations', $PAGE->context);
        if ($canbulkdelete) {
            $mastercheckbox = new \core\output\checkbox_toggleall('translations-table', true, [
                'id' => 'select-all-translations',
                'name' => 'select-all-translations',
                'label' => get_string('selectall'),
                'labelclasses' => 'sr-only',
                'classes' => 'm-1',
                'checked' => false,
            ]);

            $headers[] = $OUTPUT->render($mastercheckbox);
            $columns[] = 'select';
        }

        $columns += ['md5key', 'targetlanguage', 'rawtext', 'substitutetext', 'usermodified', 'actions'];
        $headers += [
            get_string('md5key', 'filter_translations'),
            get_string('targetlanguage', 'filter_translations'),
            get_string('rawtext', 'filter_translations'),
            get_string('substitutetext', 'filter_translations'),
            get_string('translatedby', 'filter_translations'),
            get_string('actions'),
            '',
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->collapsible(false);
        $this->sortable(true);
        $this->pageable(true);
        $this->is_downloadable(true);
        $this->no_sorting('select');
        $this->sort_default_column = $sortcolumn;

        $wheres = [];
        $params = [];

        if (!empty($this->filterparams->rawtext)) {
            $params['rawtext'] = '%' . $DB->sql_like_escape($this->filterparams->rawtext) . '%';
            $wheres[] = $DB->sql_like('t.rawtext', ':rawtext', false);
        }

        if (!empty($this->filterparams->substitutetext)) {
            $params['substitutetext'] = '%' . $DB->sql_like_escape($this->filterparams->substitutetext) . '%';
            $wheres[] = $DB->sql_like('t.substitutetext', ':substitutetext', false);
        }

        if (!empty($this->filterparams->targetlanguage)) {
            $params['targetlanguage'] = $this->filterparams->targetlanguage;
            $wheres[] = 't.targetlanguage = :targetlanguage';
        } else if (!has_capability('filter/translations:editsitedefaulttranslations', $PAGE->context)) {
            $params['targetlanguage'] = $CFG->lang;
            $wheres[] = 't.targetlanguage <> :targetlanguage';
        }

        if (!empty($this->filterparams->hash)) {
            $params['hash'] = $this->filterparams->hash;
            $params['hash2'] = $this->filterparams->hash;
            $wheres[] = '(t.lastgeneratedhash = :hash OR t.md5key = :hash2)';
        }

        if (!empty($this->filterparams->usermodified)) {
            $params['userid'] = $this->filterparams->usermodified;
            $wheres[] = '(t.usermodified = :userid)';
        }

        if (empty($wheres)) {
            $wheres[] = '1=1';
        }

        $userfieldsapi = \core_user\fields::for_name()->including('username', 'deleted');
        $userfields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

        $this->set_sql('t.id, t.md5key, t.targetlanguage, t.rawtext, t.substitutetext, t.usermodified, t.contextid, ' . $userfields,
                '{filter_translations} t LEFT JOIN {user} u on t.usermodified = u.id',
            implode(' AND ', $wheres),
            $params);
    }

    /**
     * Generate the select column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_select($row) {
        global $OUTPUT;

        $checkbox = new \core\output\checkbox_toggleall('translations-table', false, [
            'classes' => 'translationcheckbox m-1',
            'id' => 'translation' . $row->id,
            'name' => 'translationid[]',
            'value' => $row->id,
            'checked' => false,
            'label' => get_string('selectitem', 'moodle', $row->id),
            'labelclasses' => 'accesshide',
        ]);

        return $OUTPUT->render($checkbox);
    }

    public function col_rawtext($row) {
        return shorten_text(strip_tags($row->rawtext));
    }

    public function col_substitutetext($row) {
        return shorten_text(strip_tags($row->substitutetext));
    }

    public function col_targetlanguage($row) {
        if (isset($this->languages[$row->targetlanguage])) {
            return $this->languages[$row->targetlanguage];
        }

        return $row->targetlanguage;
    }

    public function col_usermodified($row) {
        return \html_writer::link(
            new moodle_url('/user/view.php',
            array('id' => $row->usermodified)), fullname($row)
        );
    }

    public function col_actions($row) {
        global $PAGE;

        return html_writer::link(
            new moodle_url('/filter/translations/edittranslation.php', [
                'id' => $row->id,
                'contextid' => $row->contextid,
                'targetlanguage' => $row->targetlanguage,
                'returnurl' => $PAGE->url
            ]),
            get_string('edittranslation', 'filter_translations'),
            array('class' => 'btn btn-secondary')
        );
    }

    public function wrap_html_start() {
        global $PAGE;

        // Begin the form.
        echo html_writer::start_tag('form', array('method'=>'post', 'id' => 'bulkdeleteform', 'action' => 'action_redir.php'));
        echo html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=> sesskey()));
        echo html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'returnurl', 'value'=> $PAGE->url));
    }

    public function wrap_html_finish() {
        echo html_writer::start_tag('div', array('class' => 'actions my-1'));
        $this->submit_buttons();
        echo html_writer::end_tag('div');
        // Close the form.
        echo html_writer::end_tag('form');
    }

    /**
     * Output the submit button for the bulk delete action.
     */
    protected function submit_buttons() {
        global $PAGE;
        if (has_capability('filter/translations:bulkdeletetranslations', $PAGE->context)) {
            echo html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'action', 'value'=> 'bulkdelete'));

            $deletebuttonparams = [
                'type'  => 'submit',
                'class' => 'btn btn-secondary mr-1',
                'id'    => 'deletetranslationsbutton',
                'name'  => 'delete',
                'value' => get_string('deleteselected', 'filter_translations'),
                'data-action' => 'toggle',
                'data-togglegroup' => 'translations-table',
                'data-toggle' => 'action',
                'disabled' => true
            ];
            echo html_writer::empty_tag('input', $deletebuttonparams);
            $PAGE->requires->event_handler('#deletetranslationsbutton', 'click', 'M.util.show_confirm_dialog',
                    array('message' => get_string('bulkdeleteconfirmation', 'filter_translations')));
        }
    }
}
