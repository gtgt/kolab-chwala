<?php
/**
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

class file_api_document extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        if (empty($_GET['id'])) {
            throw new Exception("Missing document ID.", file_api_core::ERROR_CODE);
        }

        $method = $_SERVER['REQUEST_METHOD'];

        if ($method == 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD'])) {
            $method = $_SERVER['HTTP_X_HTTP_METHOD'];
        }

        $file = $this->get_file_path($_GET['id']);

        if ($method == 'PUT' || $method == 'GET') {
            return $this->{'document_' . strtolower($method)}($file);
        }
    }

    /**
     * Get file path from manticore session identifier
     */
    protected function get_file_path($id)
    {
        $manticore = new file_manticore($this->api);

        return $manticore->session_file($id);
    }

    /**
     * Update document file content
     */
    protected function document_put($file)
    {
        list($driver, $path) = $this->api->get_driver($file);

        $length   = rcube_utils::request_header('Content-Length');
        $tmp_dir  = unslashify($this->api->config->get('temp_dir'));
        $tmp_path = tempnam($tmp_dir, 'chwalaUpload');

        // Create stream to copy input into a temp file
        $input    = fopen('php://input', 'r');
        $tmp_file = fopen($tmp_path, 'w');

        if (!$input || !$tmp_file) {
            throw new Exception("Failed opening input or temp file stream.", file_api_core::ERROR_CODE);
        }

        // Create temp file from the input
        $copied = stream_copy_to_stream($input, $tmp_file);

        fclose($input);
        fclose($tmp_file);

        if ($copied < $length) {
            throw new Exception("Failed writing to temp file.", file_api_core::ERROR_CODE);
        }

        $file = array(
            'path' => $tmp_path,
            'type' => rcube_mime::file_content_type($tmp_path, $file),
        );

        $driver->file_update($path, $file);

        // remove the temp file
        unlink($tmp_path);
    }

    /**
     * Return document file content
     */
    protected function document_get($file)
    {
        list($driver, $path) = $this->api->get_driver($file);

        try {
            $driver->file_get($path);
        }
        catch (Exception $e) {
            header("HTTP/1.0 " . file_api_core::ERROR_CODE . " " . $e->getMessage());
        }

        exit;
    }
}
