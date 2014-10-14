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

class file_api_folder_auth extends file_api_common
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

        $drivers = $this->api->get_drivers();

        foreach ($drivers as $driver_config) {
            if ($driver_config['title'] === $this->args['folder']) {
                $driver = $this->api->get_driver_object($driver_config);
                $meta   = $driver->driver_metadata();
            }
        }

        if (empty($driver)) {
            throw new Exception("Unknown folder", file_api::ERROR_CODE);
        }

        // check if authentication works
        $data = array_fill_keys(array_keys($meta['form']), '');
        $data = array_merge($data, $this->args);
        $data = $driver->driver_validate($data);

        // save changed data (except password)
        unset($data['password']);
        foreach (array_keys($meta['form']) as $key) {
            if ($meta['form_values'][$key] != $data[$key]) {
                // @TODO: save current driver config
                break;
            }
        }

        $result = array('folder' => $this->args['folder']);

        // get list if folders if requested
        if (rcube_utils::get_boolean((string) $this->args['list'])) {
            $prefix         = $this->args['folder'] . file_storage::SEPARATOR;
            $result['list'] = array();

            foreach ($driver->folder_list() as $folder) {
                $result['list'][] = $prefix . $folder;
            }
        }

        return $result;
    }
}
