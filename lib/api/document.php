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
        $method     = $_SERVER['REQUEST_METHOD'];
        $this->args = $_GET;

        if ($method == 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD'])) {
            $method = $_SERVER['HTTP_X_HTTP_METHOD'];
        }

        // Invitation notifications
        if ($this->args['method'] == 'invitations') {
            return $this->invitations();
        }
        // Session and invitations management
        else if (strpos($this->args['method'], 'document_') === 0) {
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $post = file_get_contents('php://input');
                $this->args += (array) json_decode($post, true);
                unset($post);
            }

            if (empty($this->args['id'])) {
                throw new Exception("Missing document ID.", file_api_core::ERROR_CODE);
            }
 
            switch ($this->args['method']) {
                case 'document_delete':
                case 'document_invite':
                case 'document_request':
                case 'document_decline':
                case 'document_accept':
                case 'document_cancel':
                    return $this->{$this->args['method']}($this->args['id']);
            }
        }
        // Document content actions for Manticore
        else if ($method == 'PUT' || $method == 'GET') {
            if (empty($this->args['id'])) {
                throw new Exception("Missing document ID.", file_api_core::ERROR_CODE);
            }

            $file = $this->get_file_path($this->args['id']);

            return $this->{'document_' . strtolower($method)}($file);
        }

        throw new Exception("Unknown method", file_api_core::ERROR_INVALID);
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
     * Get invitations list
     */
    protected function invitations()
    {
        $timestamp = time();

        // Initial tracking request, return just the current timestamp
        if ($this->args['timestamp'] == -1) {
            return array('timestamp' => $timestamp);
        }

        $manticore = new file_manticore($this->api);
        $filter    = array();

        if ($this->args['timestamp']) {
            $filter['timestamp'] = $this->args['timestamp'];
        }

        $list = $manticore->invitations_list($filter);

        return array(
            'list'      => $list,
            'timestamp' => $timestamp,
        );
    }

    /**
     * Close (delete) manticore session
     */
    protected function document_delete($id)
    {
        $manticore = new file_manticore($this->api);

        if (!$manticore->session_delete($id)) {
            throw new Exception("Failed deleting the document session.", file_api_core::ERROR_CODE);
        }
    }

    /**
     * Invite/add a session participant(s)
     */
    protected function document_invite($id)
    {
        $manticore = new file_manticore($this->api);
        $users     = $this->args['users'];
        $comment   = $this->args['comment'];

        if (empty($users)) {
            throw new Exception("Invalid arguments.", file_api_core::ERROR_CODE);
        }

        foreach ((array) $users as $user) {
            if (!empty($user['user'])) {
                $manticore->invitation_create($id, $user['user'], file_manticore::STATUS_INVITED, $comment, $user['name']);

                $result[] = array(
                    'session_id' => $id,
                    'user'       => $user['user'],
                    'user_name'  => $user['name'],
                    'status'     => file_manticore::STATUS_INVITED,
                );
            }
        }

        return array(
            'list' => $result,
        );
    }

    /**
     * Request an invitation to a session
     */
    protected function document_request($id)
    {
        $manticore = new file_manticore($this->api);
        $manticore->invitation_create($id, null, file_manticore::STATUS_REQUESTED, $this->args['comment']);
    }

    /**
     * Decline an invitation to a session
     */
    protected function document_decline($id)
    {
        $manticore = new file_manticore($this->api);
        $manticore->invitation_update($id, $this->args['user'], file_manticore::STATUS_DECLINED, $this->args['comment']);
    }

    /**
     * Accept an invitation to a session
     */
    protected function document_accept($id)
    {
        $manticore = new file_manticore($this->api);
        $manticore->invitation_update($id, $this->args['user'], file_manticore::STATUS_ACCEPTED, $this->args['comment']);
    }

    /**
     * Remove a session participant(s) - cancel invitations
     */
    protected function document_cancel($id)
    {
        $manticore = new file_manticore($this->api);
        $users     = $this->args['users'];

        if (empty($users)) {
            throw new Exception("Invalid arguments.", file_api_core::ERROR_CODE);
        }

        foreach ((array) $users as $user) {
            $manticore->invitation_delete($id, $user);
            $result[] = $user;
        }

        return array(
            'list' => $result,
        );
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
            $params = array('force-type' => 'application/vnd.oasis.opendocument.text');

            $driver->file_get($path, $params);
        }
        catch (Exception $e) {
            header("HTTP/1.0 " . file_api_core::ERROR_CODE . " " . $e->getMessage());
        }

        exit;
    }
}
