<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2011-2013, Kolab Systems AG                                |
 |                                                                          |
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the             |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program. If not, see <http://www.gnu.org/licenses/>      |
 +--------------------------------------------------------------------------+
 | Author: Aleksander Machniak <machniak@kolabsys.com>                      |
 +--------------------------------------------------------------------------+
*/

class file_ui_main extends file_ui
{
    public function action_default()
    {
        // assign token
        $this->output->set_env('token', $_SESSION['user']['token']);

        // assign capabilities
        $this->output->set_env('capabilities', $_SESSION['caps']);

        // add watermark content
        $this->output->set_env('watermark', $this->output->get_template('watermark'));
//        $this->watermark('taskcontent');

        // assign default set of translations
        $this->output->add_translation('loading', 'saving', 'deleting', 'servererror',
            'search', 'search.loading');

//        $this->output->assign('tasks', $this->menu);
//        $this->output->assign('main_menu', $this->menu());
        $this->output->assign('user', $_SESSION['user']);
        $this->output->assign('max_upload', $this->show_bytes($_SESSION['caps']['MAX_UPLOAD']));
    }

    public function folder_create_form()
    {
        $input_name = new html_inputfield(array(
            'type'  => 'text',
            'name'  => 'name',
            'value' => '',
        ));
        $input_parent = new html_checkbox(array(
            'name'  => 'parent',
            'value' => '1',
        ));
        $submit = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_create_submit()',
            'value'   => $this->translate('form.submit'),
        ));
        $cancel = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_create_stop()',
            'value'   => $this->translate('form.cancel'),
        ));

        $table = new html_table;

        $table->add(null, $input_name->show() . $input_parent->show() . $this->translate('folder.under'));
        $table->add('buttons', $submit->show() . $cancel->show());

        $content = html::tag('fieldset', null,
            html::tag('legend', null,
                $this->translate('folder.createtitle')) . $table->show());

        $form = html::tag('form', array(
            'id'       => 'folder-create-form',
            'onsubmit' => 'ui.folder_create_submit(); return false'),
            $content);

        return $form;
    }

    public function folder_edit_form()
    {
        $input_name = new html_inputfield(array(
            'type'  => 'text',
            'name'  => 'name',
            'value' => '',
        ));
        $submit = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_edit_submit()',
            'value'   => $this->translate('form.submit'),
        ));
        $cancel = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_edit_stop()',
            'value'   => $this->translate('form.cancel'),
        ));

        $table = new html_table;

        $table->add(null, $input_name->show());
        $table->add('buttons', $submit->show() . $cancel->show());

        $content = html::tag('fieldset', null,
            html::tag('legend', null,
                $this->translate('folder.edittitle')) . $table->show());

        $form = html::tag('form', array(
            'id'       => 'folder-edit-form',
            'onsubmit' => 'ui.folder_edit_submit(); return false'),
            $content);

        return $form;
    }

    public function file_search_form()
    {
        $input_name = new html_inputfield(array(
            'type'  => 'text',
            'name'  => 'name',
            'value' => '',
        ));
        $submit = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_edit_submit()',
            'value'   => $this->translate('form.submit'),
        ));
        $cancel = new html_inputfield(array(
            'type'    => 'button',
            'onclick' => 'ui.folder_edit_stop()',
            'value'   => $this->translate('form.cancel'),
        ));

        $table = new html_table;

        $table->add(null, $input_name->show());
        $table->add('buttons', $submit->show() . $cancel->show());

        $content = html::tag('fieldset', null,
            html::tag('legend', null,
                $this->translate('file.search')) . $table->show());

        $form = html::tag('form', array(
            'id'       => 'file-search-form',
            'onsubmit' => 'ui.file_search_submit(); return false'),
            $content);

        return $form;
    }

    /**
     * Create a human readable string for a number of bytes
     *
     * @param int Number of bytes
     *
     * @return string Byte string
     */
    protected function show_bytes($bytes)
    {
        if (!$bytes) {
            return null;
        }

        if ($bytes >= 1073741824) {
            $gb  = $bytes/1073741824;
            $str = sprintf($gb>=10 ? "%d " : "%.1f ", $gb) . $this->translate('size.GB');
        }
        else if ($bytes >= 1048576) {
            $mb  = $bytes/1048576;
            $str = sprintf($mb>=10 ? "%d " : "%.1f ", $mb) . $this->translate('size.MB');
        }
        else if ($bytes >= 1024) {
            $str = sprintf("%d ",  round($bytes/1024)) . $this->translate('size.KB');
        }
        else {
            $str = sprintf("%d ", $bytes) . $this->translate('size.B');
        }

        return $str;
    }

}
