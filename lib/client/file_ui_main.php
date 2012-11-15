<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2011-2012, Kolab Systems AG                                |
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

        // add watermark content
        $this->output->set_env('watermark', $this->output->get_template('watermark'));
//        $this->watermark('taskcontent');

        // assign default set of translations
        $this->output->add_translation('loading', 'saving', 'deleting', 'servererror',
            'search', 'search.loading', 'search.acchars');

//        $this->output->assign('tasks', $this->menu);
//        $this->output->assign('main_menu', $this->menu());
        $this->output->assign('user', $_SESSION['user']);

        $this->output->command('command', 'folder.list');
    }
}
