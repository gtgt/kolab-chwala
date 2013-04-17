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

class file_ui_file extends file_ui
{
    private $file;

    public function action_default()
    {
    
    }

    public function action_open()
    {
        // assign default set of translations
        $this->output->add_translation('saving', 'deleting');

        $this->ui_init();

        $this->file = $this->get_input('file', 'GET'); // @TODO: error handling
        $this->output->set_env('file', $this->file);

        // Set filename (without path?) as a page title
        $this->page_title = $this->file;
        $this->task_template = 'file_open';

        // fetch file metadata
        $response = $this->api_get('file_info', array('file' => $this->file));
        $this->filedata = $response->get(); // @TODO: error handling
    }

    public function file_open_frame()
    {
    /*
        $src = 'api/?method=file_get&file=' . urlencode($this->file)
          . '&token=' . urlencode($_SESSION['user']['token'])
          . '&force-type=' . urlencode('text/plain');
    */
        // src attribute will be set on page load
        return html::iframe(array('id' => 'file-content', 'onload' => 'ui.loader_hide(this)'));
    }

    public function file_open_data()
    {
        $table = new html_table(array('cols' => 2));

        // file name
        $table->add('label', $this->translate('file.name').':');
        $table->add('data filename', html::quote($this->filedata['name']));

        // file type
        // @TODO: human-readable type name
        $table->add('label', $this->translate('file.type').':');
        $table->add('data filetype', html::quote($this->filedata['type']));

        // file size
        $table->add('label', $this->translate('file.size').':');
        $table->add('data filesize', html::quote($this->show_bytes($this->filedata['size'])));

        // file modification time
        $table->add('label', $this->translate('file.mtime').':');
        $table->add('data filemtime', html::quote($this->filedata['mtime']));

        // @TODO: for images: width, height, color depth, etc.
        // @TODO: for text files: count of characters, lines, words

        return $table->show();
    }
}
