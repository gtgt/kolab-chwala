<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2015, Kolab Systems AG                                |
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

class file_api_file_info extends file_api_common
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

        list($driver, $path) = $this->api->get_driver($this->args['file']);

        $info = $driver->file_info($path);

        // Possible 'viewer' types are defined in files_api.js:file_type_supported()
        // 1 - Native browser support
        // 2 - Chwala viewer exists
        // 4 - Manticore (WebODF collaborative editor)

        if (rcube_utils::get_boolean((string) $this->args['viewer'])) {
            $this->file_viewer_info($this->args['file'], $info);

            if ((intval($this->args['viewer']) & 4) && $this->rc->config->get('fileapi_manticore')) {
                $this->file_manticore_handler($this->args['file'], $info);
            }
        }

        return $info;
    }

    /**
     * Merge file viewer data into file info
     */
    protected function file_viewer_info($file, &$info)
    {
        if ($viewer = $this->find_viewer($info['type'])) {
            $info['viewer'] = array();
            if ($frame = $viewer->frame($file, $info['type'])) {
                $info['viewer']['frame'] = $frame;
            }
            else if ($href = $viewer->href($file, $info['type'])) {
                $info['viewer']['href'] = $href;
            }
        }
    }

    /**
     * Merge manticore session data into file info
     */
    protected function file_manticore_handler($file, &$info)
    {
        // check if file type is supported by webodf editor?
        if (strtolower($info['type']) != 'application/vnd.oasis.opendocument.text') {
            return;
        }

        $manticore = new file_manticore($this->api);

        if ($uri = $manticore->viewer_uri($file)) {
            $info['viewer']['href']      = $uri;
            $info['viewer']['manticore'] = true;
        }
    }
}
