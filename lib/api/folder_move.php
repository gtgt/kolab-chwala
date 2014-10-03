<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2014, Kolab Systems AG                                |
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

require_once __DIR__ . "/common.php";

class file_api_folder_move extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        parent::handle();

        if (!isset($this->args['folder']) || $this->args['folder'] === '') {
            throw new Exception("Missing folder name", file_api::ERROR_CODE);
        }

        if (!isset($this->args['new']) || $this->args['new'] === '') {
            throw new Exception("Missing destination folder name", file_api::ERROR_CODE);
        }

        if ($this->args['new'] === $this->args['folder']) {
            return;
        }

        list($driver, $path) = $this->api->get_driver($this->args['folder']);
        list($new_driver, $new_path) = $this->api->get_driver($this->args['new']);

        // @TODO: cross-driver move
        if ($driver != $new_driver) {
            throw new Exception("Unsupported operation", file_api::ERROR_CODE);
        }

        return $driver->folder_move($path, $new_path);
    }
}
