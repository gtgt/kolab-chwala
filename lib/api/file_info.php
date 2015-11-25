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

        $manticore = $this->rc->config->get('fileapi_manticore');

        // support file_info by session ID
        if ((!isset($this->args['file']) || $this->args['file'] === '')
            && $manticore && !empty($this->args['session'])
        ) {
            $this->args['file'] = $this->file_manticore_file($this->args['session']);
        }

        if (!isset($this->args['file']) || $this->args['file'] === '') {
            throw new Exception("Missing file name", file_api_core::ERROR_CODE);
        }

        list($driver, $path) = $this->api->get_driver($this->args['file']);

        $info = $driver->file_info($path);
        $info['file'] = $this->args['file'];

        // Possible 'viewer' types are defined in files_api.js:file_type_supported()
        // 1 - Native browser support
        // 2 - Chwala viewer exists
        // 4 - Manticore (WebODF collaborative editor)

        if (rcube_utils::get_boolean((string) $this->args['viewer'])) {
            $this->file_viewer_info($info);

            // check if file type is supported by webodf editor?
            if ($manticore) {
                if (strtolower($info['type']) == 'application/vnd.oasis.opendocument.text') {
                    $info['viewer']['manticore'] = true;
                }
            }

            if ((intval($this->args['viewer']) & 4) && $info['viewer']['manticore']) {
                $this->file_manticore_handler($info);
            }
        }

        return $info;
    }

    /**
     * Merge file viewer data into file info
     */
    protected function file_viewer_info(&$info)
    {
        $file   = $this->args['file'];
        $viewer = $this->find_viewer($info['type']);

        if ($viewer) {
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
    protected function file_manticore_handler(&$info)
    {
        $manticore = new file_manticore($this->api);
        $file      = $this->args['file'];
        $session   = $this->args['session'];

        if ($uri = $manticore->session_start($file, $session)) {
            $info['viewer']['href'] = $uri;
            $info['session']        = $manticore->session_info($session, true);
        }
    }

    /**
     * Get file from manticore session
     */
    protected function file_manticore_file($session_id)
    {
        $manticore = new file_manticore($this->api);

        return $manticore->session_file($session_id);
    }
}
