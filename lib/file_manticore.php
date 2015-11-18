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

/**
 * Document editing sessions handling
 */
class file_manticore
{
    protected $api;
    protected $rc;
    protected $request;
    protected $table = 'chwala_sessions';


    /**
     * Class constructor
     *
     * @param file_api Chwala API app instance
     */
    public function __construct($api)
    {
        $this->rc  = rcube::get_instance();
        $this->api = $api;
    }

    /**
     * Return viewer URI for specified file/session. This creates
     * a new collaborative editing session when needed.
     *
     * @param string $file        File path
     * @param string &$session_id Optional session ID to join to
     *
     * @return string Manticore URI
     * @throws Exception
     */
    public function session_start($file, &$session_id = null)
    {
        list($driver, $path) = $this->api->get_driver($file);

        $backend = $this->api->get_backend();
        $uri     = $driver->path2uri($path);

        if ($session_id) {
            $session = $this->session_info($session_id);

            if (empty($session)) {
                throw new Exception("Document session ID not found.", file_api_core::ERROR_CODE);
            }

            // check session membership
            if ($session['owner'] != $_SESSION['user']) {
                throw new Exception("No permission to join the editing session.", file_api_core::ERROR_CODE);
            }

            // @TODO: check if session exists in Manticore?
            // @TOOD: joining sessions of other users
        }
        else {
            $session_id = rcube_utils::bin2ascii(md5(time() . $uri, true));
            $data       = array();
            $owner      = $_SESSION['user'];

            // we'll store user credentials if the file comes from
            // an external source that requires authentication
            if ($backend != $driver) {
                $auth = $driver->auth_info();
                $auth['password']  = $this->rc->encrypt($auth['password']);
                $data['auth_info'] = $auth;
            }

            $res = $this->session_create($session_id, $uri, $owner, $data);

            if (!$res) {
                throw new Exception("Failed creating document editing session", file_api_core::ERROR_CODE);
            }
        }

        return $this->frame_uri($session_id);
    }

    /**
     * Get file path (not URI) from session.
     *
     * @param string $id Session ID
     *
     * @return string File path
     * @throws Exception
     */
    public function session_file($id)
    {
        $session = $this->session_info($id);

        if (empty($session)) {
            throw new Exception("Document session ID not found.", file_api_core::ERROR_CODE);
        }

        $path = $this->uri2path($session['uri']);

        if (empty($path)) {
            throw new Exception("Document session ID not found.", file_api_core::ERROR_CODE);
        }

        // @TODO: check permissions to the session

        return $path;
    }

    /**
     * Get editing session info
     */
    public function session_info($id)
    {
        $db     = $this->rc->get_dbh();
        $result = $db->query("SELECT * FROM `{$this->table}`"
            . " WHERE `id` = ?", $id);

        if ($row = $db->fetch_assoc($result)) {
            return $this->session_info_parse($row);
        }
    }

    /**
     * Find editing sessions for specified path
     */
    public function session_find($path)
    {
        // create an URI for specified path
        list($driver, $path) = $this->api->get_driver($path);

        $uri = trim($driver->path2uri($path), '/') . '/';

        // get existing sessions
        $sessions = array();
        $filter   = array('file', 'owner', 'is_owner');
        $db       = $this->rc->get_dbh();
        $result   = $db->query("SELECT * FROM `{$this->table}`"
            . " WHERE `uri` LIKE '" . $db->escape($uri) . "%'");

        if ($row = $db->fetch_assoc($result)) {
            if ($path = $this->uri2path($row['uri'])) {
                $sessions[$row['id']] = $this->session_info_parse($row, $path, $filter);
            }
        }

        return $sessions;
    }

    /**
     * Delete editing session (only owner can do that)
     *
     * @param string $id    Session identifier
     * @param bool   $local Remove session only from local database
     */
    public function session_delete($id, $local = false)
    {
        $db     = $this->rc->get_dbh();
        $result = $db->query("DELETE FROM `{$this->table}`"
            . " WHERE `id` = ? AND `owner` = ?",
            $id, $_SESSION['user']);

        $success = $db->affected_rows($result) > 0;

        // Send document delete to Manticore
        if ($success && !$local) {
            $req = $this->get_request();
            $res = $req->document_delete($id);
        }

        return $success;
    }

    /**
     * Create editing session
     */
    protected function session_create($id, $uri, $owner, $data)
    {
        // Do this before starting the session in Manticore,
        // it will immediately call api/document to get the file body
        $db     = $this->rc->get_dbh();
        $result = $db->query("INSERT INTO `{$this->table}`"
            . " (`id`, `uri`, `owner`, `data`) VALUES (?, ?, ?, ?)",
            $id, $uri, $owner, json_encode($data));

        $success = $db->affected_rows($result) > 0;

        // create the session in Manticore
        if ($success) {
            $req = $this->get_request();
            $res = $req->document_create(array(
                'id'     => $id,
                'title'  => '', // @TODO: maybe set to a file path without extension?
                'access' => array(
                    array(
                        'identity'   => $owner,
                        'permission' => 'write',
                    ),
                ),
            ));

            if (!$res) {
                $this->session_delete($id, true);
                return false;
            }
        }

        return $success;
    }

    /**
     * Parse session info data
     */
    protected function session_info_parse($record, $path = null, $filter = array())
    {
/*
        if (is_string($data) && !empty($data)) {
            $data = json_decode($data, true);
        }
*/
        $session = array();
        $fields  = array('id', 'uri', 'owner');

        foreach ($fields as $field) {
            if (isset($record[$field])) {
                $session[$field] = $record[$field];
            }
        }

        if ($path) {
            $session['file'] = $path;
        }

        // @TODO: is_invited?, last_modified?

        if ($session['owner'] == $_SESSION['user']) {
            $session['is_owner'] = true;
        }

        if (!empty($filter)) {
            $session = array_intersect_key($session, array_flip($filter));
        }

        return $session;
    }

    /**
     * Generate URI of Manticore editing session
     */
    protected function frame_uri($id)
    {
        $base_url = rtrim($this->rc->config->get('fileapi_manticore'), ' /');

        return $base_url . '/document/' . $id . '/' . $_SESSION['manticore_token'];
    }

    /**
     * Get file path from the URI
     */
    protected function uri2path($uri)
    {
        $backend = $this->api->get_backend();

        try {
            return $backend->uri2path($uri);
        }
        catch (Exception $e) {
                // do nothing
        }

        foreach ($this->api->get_drivers(true) as $driver) {
            try {
                $path  = $driver->uri2path($uri);
                $title = $driver->title();

                if ($title) {
                    $path = $title . file_storage::SEPARATOR . $path;
                }

                return $path;
            }
            catch (Exception $e) {
                // do nothing
            }
        }
    }

    /**
     * Return Manticore user/session info
     */
    public function user_info()
    {
        $req = $this->get_request();
        $res = $req->get('api/users/me');

        return $res->get();
    }

    /**
     * Initialize Manticore API request handler
     */
    protected function get_request()
    {
        if (!$this->request) {
            $uri = rcube_utils::resolve_url($this->rc->config->get('fileapi_manticore'));
            $this->request = new file_manticore_api($uri);

            // Use stored session token, check if it's still valid
            if ($_SESSION['manticore_token']) {
                $is_valid = $this->request->set_session_token($_SESSION['manticore_token'], true);

                if ($is_valid) {
                    return $this->request;
                }
            }

            $backend = $this->api->get_backend();
            $auth    = $backend->auth_info();

            $_SESSION['manticore_token'] = $this->request->login($auth['username'], $auth['password']);

            if (empty($_SESSION['manticore_token'])) {
                throw new Exception("Unable to login to Manticore server.", file_api_core::ERROR_CODE);
            }
        }

        return $this->request;
    }
}
