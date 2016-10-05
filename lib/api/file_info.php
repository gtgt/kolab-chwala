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

        // check Manticore support. Note: we don't use config->get('fileapi_manticore')
        // here as it may be not properly set if backend driver wasn't initialized yet
        $capabilities = $this->api->capabilities(false);
        $manticore    = $capabilities['MANTICORE'];
        $wopi         = $capabilities['WOPI'];

        // support file_info by session ID
        if (!isset($this->args['file']) || $this->args['file'] === '') {
            if ($manticore && !empty($this->args['session'])) {
                $this->args['file'] = $this->file_manticore_file($this->args['session']);
            }
            else {
                throw new Exception("Missing file name", file_api_core::ERROR_CODE);
            }
        }

        if ($this->args['file'] !== null) {
            list($driver, $path) = $this->api->get_driver($this->args['file']);

            $info = $driver->file_info($path);
            $info['file'] = $this->args['file'];
        }
        else {
            $info = array(
                // @TODO: session exists, invitation exists, assume ODF format
                // however, this should be done in a different way,
                // e.g. this info should be stored in sessions database
                'type'     => 'application/vnd.oasis.opendocument.text',
                'writable' => false,
            );
        }

        // Possible 'viewer' types are defined in files_api.js:file_type_supported()
        // 1 - Native browser support
        // 2 - Chwala viewer exists
        // 4 - Editor exists (manticore/wopi)

        if (rcube_utils::get_boolean((string) $this->args['viewer'])) {
            if ($this->args['file'] !== null) {
                $this->file_viewer_info($info);
            }

            // check if file type is supported by webodf editor?
            if ($manticore) {
                if (strtolower($info['type']) == 'application/vnd.oasis.opendocument.text') {
                    $info['viewer']['manticore'] = true;
                }
            }
            if ($wopi) {
                // @TODO: check supported mimetype
                $info['viewer']['wopi'] = true;
            }

            if ((intval($this->args['viewer']) & 4)) {
                // @TODO: Chwala client should have a possibility to select
                //        between wopi and manticore?
                if ($info['viewer']['wopi']) {
                    $this->file_wopi_handler($info);
                }
                else if ($info['viewer']['manticore']) {
                    $this->file_manticore_handler($info);
                }
            }
        }

        // check writable flag
        if ($this->args['file'] !== null) {
            $path = explode(file_storage::SEPARATOR, $path);
            array_pop($path);
            $path = implode(file_storage::SEPARATOR, $path);
            $acl  = $driver->folder_rights($path);

            $info['writable'] = ($acl & file_storage::ACL_WRITE) != 0;
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

        if ($uri = $manticore->session_start($file, $info['type'], $session)) {
            $info['viewer']['href'] = $uri;
            $info['viewer']['post'] = $manticore->editor_post_params($info);
            $info['session']        = $manticore->session_info($session, true);
        }
    }

    /**
     * Get file from manticore session
     */
    protected function file_manticore_file($session_id)
    {
        $manticore = new file_manticore($this->api);

        return $manticore->session_file($session_id, true);
    }

    /**
     * Merge WOPI session data into file info
     */
    protected function file_wopi_handler(&$info)
    {
        $wopi    = new file_wopi($this->api);
        $file    = $this->args['file'];
        $session = $this->args['session'];

        if ($uri = $wopi->session_start($file, $info['type'], $session)) {
            $info['viewer']['href'] = $uri;
            $info['viewer']['post'] = $wopi->editor_post_params($info);
            $info['session']        = $wopi->session_info($session, true);
        }
    }
}
