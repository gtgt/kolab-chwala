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
     * Return viewer URI for specified file. This creates
     * a new collaborative editing session when needed
     *
     * @param string $file File path
     *
     * @return string Manticore URI
     * @throws Exception
     */
    public function viewer_uri($file)
    {
        list($driver, $path) = $this->api->get_driver($file);

        $backend = $this->api->get_backend();
        $uri     = $driver->path2uri($path);
        $id      = rcube_utils::bin2ascii(md5(time() . $uri, true));
        $data    = array(
            'user' => $_SESSION['user'],
        );

        // @TODO: check if session exists and is valid (?)

        // we'll store user credentials if the file comes from
        // an external source that requires authentication
        if ($backend != $driver) {
            $auth = $driver->auth_info();
            $auth['password']  = $this->rc->encrypt($auth['password']);
            $data['auth_info'] = $auth;
        }

        // Do this before starting the session in Manticore,
        // it will immediately call api/document to get the file body
        $res = $this->session_create($id, $uri, $data);

        if (!$res) {
            throw new Exception("Failed creating document editing session", file_api_core::ERROR_CODE);
        }

        // get filename
        $path     = explode(file_storage::SEPARATOR, $path);
        $filename = $path[count($path)-1];

        // create the session in Manticore
        $req = $this->get_request();
        $res = $req->session_create(array(
            'id'     => $id,
            'title'  => $filename,
            'access' => array(
                array(
                    'identity'   => $data['user'],
                    'permission' => 'write',
                ),
            ),
        ));

        if (!$res) {
            $this->session_delete($id);
            throw new Exception("Failed creating document editing session", file_api_core::ERROR_CODE);
        }

        return $this->frame_uri($id);
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
        $backend = $this->api->get_backend();
        $session = $this->session_info($id);

        if (empty($session)) {
            throw new Exception("Document session ID not found.", file_api_core::ERROR_CODE);
        }

        try {
            return $backend->uri2path($session['uri']);
        }
        catch (Exception $e) {
                // do nothing
        }

        foreach ($this->api->get_drivers(true) as $driver) {
            try {
                $path  = $driver->uri2path($session['uri']);
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

        if (empty($path)) {
            throw new Exception("Document session ID not found.", file_api_core::ERROR_CODE);
        }
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
            $row['data'] = json_decode($row['data'], true);
            return $row;
        }
    }

    /**
     * Create editing session
     */
    protected function session_create($id, $uri, $data)
    {
        $db     = $this->rc->get_dbh();
        $result = $db->query("INSERT INTO `{$this->table}`"
            . " (`id`, `uri`, `data`) VALUES (?, ?, ?)",
            $id, $uri, json_encode($data));

        return $db->affected_rows($result) > 0;
    }

    /**
     * Delete editing session
     */
    protected function session_delete($id)
    {
        $db     = $this->rc->get_dbh();
        $result = $db->query("DELETE FROM `{$this->table}`"
            . " WHERE `id` = ?", $id);

        return $db->affected_rows($result) > 0;
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
