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

class file_api_folder_list extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        parent::handle();

        // get folders from main driver
        $backend = $this->api->get_backend();
        $folders = $backend->folder_list();

        // old result format
        if ($this->api->client_version() < 2) {
            return $folders;
        }

        $drivers  = $this->api->get_drivers(true);
        $has_more = false;
        $errors   = array();

        // get folders from external sources
        foreach ($drivers as $driver) {
            $title  = $driver->title();
            $prefix = $title . file_storage::SEPARATOR;

            // folder exists in main source, replace it with external one
            if (($idx = array_search($title, $folders)) !== false) {
                foreach ($folders as $idx => $folder) {
                    if ($folder == $title || strpos($folder, $prefix) === 0) {
                        unset($folders[$idx]);
                    }
                }
            }

            $folders[] = $title;
            $has_more  = true;

            if ($driver != $backend) {
                try {
                    foreach ($driver->folder_list() as $folder) {
                        $folders[] = $prefix . $folder;
                    }
                }
                catch (Exception $e) {
                    if ($e->getCode() == file_storage::ERROR_NOAUTH) {
                        // inform UI about to ask user for credentials
                        $errors[$title] = $this->parse_metadata($driver->driver_metadata());
                    }
                }
            }
        }

        // re-sort the list
        if ($has_more) {
            usort($folders, array($this, 'sort_folder_comparator'));
        }

        return array(
            'list'        => $folders,
            'auth_errors' => $errors,
        );
    }

    /**
     * Callback for uasort() that implements correct
     * locale-aware case-sensitive sorting
     */
    protected function sort_folder_comparator($str1, $str2)
    {
        $path1 = explode(file_storage::SEPARATOR, $str1);
        $path2 = explode(file_storage::SEPARATOR, $str2);

        foreach ($path1 as $idx => $folder1) {
            $folder2 = $path2[$idx];

            if ($folder1 === $folder2) {
                continue;
            }

            return strcoll($folder1, $folder2);
        }
    }
}
