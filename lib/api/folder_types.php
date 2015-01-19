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

class file_api_folder_types extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        parent::handle();

        $drivers = $this->rc->config->get('fileapi_drivers');
        $result  = array();

        if (!empty($drivers)) {
            foreach ((array) $drivers as $driver_name) {
                if ($driver_name != 'kolab' && !isset($result[$driver_name])) {
                    $driver = $this->api->load_driver_object($driver_name);
                    $meta   = $driver->driver_metadata();

                    $result[$driver_name] = $this->parse_metadata($meta);
                }
            }
        }
/*
        // add local storage to the list
        if (!empty($result)) {
            $backend = $this->api->get_backend();
            $meta = $backend->driver_metadata();

            $result = array_merge(array('default' => $this->parse_metadata($meta, true)), $result);
        }
*/
        return $result;
    }
}
