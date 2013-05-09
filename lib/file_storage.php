<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2013, Kolab Systems AG                                |
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

interface file_storage
{
    const CAPS_MAX_UPLOAD = 'MAX_UPLOAD';
    const CAPS_ACL        = 'ACL';

    const ERROR_FILE_EXISTS = 550;

    /**
     * Authenticates a user
     *
     * @param string $username User name
     * @param string $password User password
     *
     * @return bool True on success, False on failure
     */
    public function authenticate($username, $password);

    /**
     * Storage driver capabilities
     *
     * @return array List of capabilities
     */
    public function capabilities();

    /**
     * Create a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param array  $file      File data (path, type)
     *
     * @throws Exception
     */
    public function file_create($file_name, $file);

    /**
     * Delete a file.
     *
     * @param string $file_name Name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_delete($file_name);

    /**
     * Returns file body.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param array  $params    Parameters (force-download)
     *
     * @throws Exception
     */
    public function file_get($file_name, $params = array());

    /**
     * Move (or rename) a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param string $new_name  New name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_move($file_name, $new_name);

    /**
     * Copy a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param string $new_name  New name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_copy($file_name, $new_name);

    /**
     * Returns file metadata.
     *
     * @param string $file_name Name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_info($file_name);

    /**
     * List files in a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param array  $params      List parameters ('sort', 'reverse', 'search')
     *
     * @return array List of files (file properties array indexed by filename)
     * @throws Exception
     */
    public function file_list($folder_name, $params = array());

    /**
     * Create a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_create($folder_name);

    /**
     * Delete a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_delete($folder_name);

    /**
     * Rename a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $new_name    New name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_rename($folder_name, $new_name);

    /**
     * Returns list of folders.
     *
     * @return array List of folders
     * @throws Exception
     */
    public function folder_list();
}
