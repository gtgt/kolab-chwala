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

class file_api_file_create extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        parent::handle();

        if (!isset($this->args['file']) || $this->args['file'] === '') {
            throw new Exception("Missing file name", file_api_core::ERROR_CODE);
        }

        if (!isset($this->args['content'])) {
            throw new Exception("Missing file content", file_api_core::ERROR_CODE);
        }

        $request = $this instanceof file_api_file_update ? 'file_update' : 'file_create';
        $file    = array(
            'content' => $this->args['content'],
            'type'    => rcube_mime::file_content_type($this->args['content'],
                $this->args['file'], $this->args['content-type'], true),
        );

        list($driver, $path) = $this->api->get_driver($this->args['file']);

        $driver->$request($path, $file);

        if (rcube_utils::get_boolean((string) $this->args['info'])) {
            return $driver->file_info($path);
        }
    }
}
