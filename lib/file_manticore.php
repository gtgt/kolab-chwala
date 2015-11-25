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
    protected $user;
    protected $sessions_table    = 'chwala_sessions';
    protected $invitations_table = 'chwala_invitations';
    protected $icache            = array();

    const STATUS_INVITED   = 'invited';
    const STATUS_REQUESTED = 'requested';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_DECLINED  = 'declined';
    const STATUS_DECLINED_OWNER = 'declined-owner'; // same as 'declined' but done by the session owner
    const STATUS_ACCEPTED_OWNER = 'accepted-owner'; // same as 'accepted' but done by the session owner


    /**
     * Class constructor
     *
     * @param file_api Chwala API app instance
     */
    public function __construct($api)
    {
        $this->rc   = rcube::get_instance();
        $this->api  = $api;
        $this->user = $_SESSION['user'];

        $db = $this->rc->get_dbh();
        $this->sessions_table    = $db->table_name($this->sessions_table);
        $this->invitations_table = $db->table_name($this->invitations_table);
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
                throw new Exception("Document session not found.", file_api_core::ERROR_CODE);
            }

            // check session ownership
            if ($session['owner'] != $this->user) {
                // check if the user was invited
                $invitations = $this->invitations_find(array('session_id' => $session_id, 'user' => $this->user));
                $states      = array(self::STATUS_INVITED, self::STATUS_ACCEPTED, self::STATUS_ACCEPTED_OWNER);

                if (empty($invitations) || !in_array($invitations[0]['status'], $states)) {
                    throw new Exception("No permission to join the editing session.", file_api_core::ERROR_CODE);
                }

                // automatically accept the invitation, if not done yet
                if ($invitations[0]['status'] == self::STATUS_INVITED) {
                    $this->invitation_update($session_id, $this->user, self::STATUS_ACCEPTED);
                }
            }

            // authenticate to Manticore, we need auth token for frame_uri
            $req = $this->get_request();

            // @TODO: make sure the session exists in Manticore?
        }
        else {
            // @TODO: to prevent from creating a new sessions for the same file+user
            // (e.g. when user uses F5 to refresh the page), we should check
            // if such a session exist

            $session_id = rcube_utils::bin2ascii(md5(time() . $uri, true));
            $data       = array();
            $owner      = $this->user;

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
            throw new Exception("Document session not found.", file_api_core::ERROR_CODE);
        }

        $path = $this->uri2path($session['uri']);

        if (empty($path)) {
            throw new Exception("Document session not found.", file_api_core::ERROR_CODE);
        }

        // @TODO: check permissions to the session

        return $path;
    }

    /**
     * Get editing session info
     *
     * @param string $id               Session identifier
     * @param bool   $with_invitations Return invitations list
     */
    public function session_info($id, $with_invitations = false)
    {
        $session = $this->icache["session:$id"];

        if (!$session) {
            $db     = $this->rc->get_dbh();
            $result = $db->query("SELECT * FROM `{$this->sessions_table}`"
                . " WHERE `id` = ?", $id);

            if ($row = $db->fetch_assoc($result)) {
                $session = $this->session_info_parse($row);

                $this->icache["session:$id"] = $session;
            }
        }

        if ($session) {
            if ($session['owner'] == $this->user) {
                $session['is_owner'] = true;
            }

            if ($with_invitations && $session['is_owner']) {
                $session['invitations'] = $this->invitations_find(array('session_id' => $id));
            }
        }

        return $session;
    }

    /**
     * Find editing sessions for specified path
     */
    public function session_find($path, $invitations = true)
    {
        // create an URI for specified path
        list($driver, $path) = $this->api->get_driver($path);

        $uri = trim($driver->path2uri($path), '/') . '/';

        // get existing sessions
        $sessions = array();
        $filter   = array('file', 'owner', 'is_owner');
        $db       = $this->rc->get_dbh();
        $result   = $db->query("SELECT * FROM `{$this->sessions_table}`"
            . " WHERE `uri` LIKE '" . $db->escape($uri) . "%'");

        while ($row = $db->fetch_assoc($result)) {
            if ($path = $this->uri2path($row['uri'])) {
                $sessions[$row['id']] = $this->session_info_parse($row, $path, $filter);
            }
        }

        // set 'is_invited' flag
        if ($invitations && !empty($sessions)) {
            $invitations = $this->invitations_find(array('user' => $this->user));
            $states      = array(self::STATUS_INVITED, self::STATUS_ACCEPTED, self::STATUS_ACCEPTED_OWNER);

            foreach ($invitations as $invitation) {
                if (!empty($sessions[$invitation['session_id']]) && in_array($invitation['status'], $states)) {
                    $sessions[$invitation['session_id']]['is_invited'] = true;
                }
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
        $result = $db->query("DELETE FROM `{$this->sessions_table}`"
            . " WHERE `id` = ? AND `owner` = ?",
            $id, $this->user);

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
        $result = $db->query("INSERT INTO `{$this->sessions_table}`"
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
                        'permission' => file_manticore_api::ACCESS_WRITE,
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
     * Find invitations for current user. This will return all
     * invitations related to the user including his sessions.
     *
     * @param array $filter Search filter (see self::invitations_find())
     *
     * @return array Invitations list
     */
    public function invitations_list($filter = array())
    {
        $filter['user'] = $this->user;

        // list of invitations to the user or requested by him
        $result = $this->invitations_find($filter, true);

        unset($filter['user']);
        $filter['owner'] = $this->user;

        // other invitations that belong to the sessions owned by the user
        if ($other = $this->invitations_find($filter, true)) {
            $result = array_merge($result, $other);
        }

        return $result;
    }

    /**
     * Find invitations for specified filter
     *
     * @param array $filter Search filter (see self::invitations_find())
     *                      - session_id: session identifier
     *                      - timestamp: "changed > ?" filter
     *                      - user: Invitation user identifier
     *                      - owner: Session owner identifier
     * @param bool $extended Return session file names
     *
     * @return array Invitations list
     */
    public function invitations_find($filter, $extended = false)
    {
        $db     = $this->rc->get_dbh();
        $query  = '';
        $select = "i.*";

        foreach ($filter as $column => $value) {
            if ($column == 'timestamp') {
                $where[] = "i.`changed` > " . $db->fromunixtime($value);
            }
            else if ($column == 'owner') {
                $join[] = "`{$this->sessions_table}` s ON (i.`session_id` = s.`id`)";
                $where[] = "s.`owner` = " . $db->quote($value);
            }
            else {
                $where[] = "i.`$column` = " . $db->quote($value);
            }
        }

        if ($extended) {
            $select .= ", s.`uri`, s.`owner`";
            $join[]  = "`{$this->sessions_table}` s ON (i.`session_id` = s.`id`)";
        }

        if (!empty($join)) {
            $query .= ' JOIN ' . implode(' JOIN ', array_unique($join));
        }

        if (!empty($where)) {
            $query .= ' WHERE ' . implode(' AND ', array_unique($where));
        }

        $result = $db->query("SELECT $select FROM `{$this->invitations_table}` i"
            . "$query ORDER BY `changed`");

        if ($db->is_error($result)) {
            throw new Exception("Internal error.", file_api_core::ERROR_CODE);
        }

        $invitations = array();

        while ($row = $db->fetch_assoc($result)) {
            if ($extended) {
                try {
                    // add unix-timestamp of the `changed` date to the result
                    $dt = new DateTime($row['changed']);
                    $row['timestamp'] = $dt->format('U');
                }
                catch(Exception $e) { }

                // add filename to the result
                $filename = parse_url($row['uri'], PHP_URL_PATH);
                $filename = pathinfo($filename, PATHINFO_BASENAME);
                $filename = rawurldecode($filename);

                $row['filename'] = $filename;
                unset($row['uri']);
            }

            $invitations[] = $row;
        }

        return $invitations;
    }

    /**
     * Create an invitation
     *
     * @param string $session_id Document session identifier
     * @param string $user       User identifier (use null for current user)
     * @param string $status     Invitation status (invited, requested)
     *
     * @throws Exception
     */
    public function invitation_create($session_id, $user, $status = 'invited')
    {
        if (empty($user)) {
            $user = $this->user;
        }

        if ($status != self::STATUS_INVITED && $status != self::STATUS_REQUESTED) {
            throw new Exception("Invalid invitation status.", file_api_core::ERROR_CODE);
        }

        // get session information
        $session = $this->session_info($session_id);

        if (empty($session)) {
            throw new Exception("Document session not found.", file_api_core::ERROR_CODE);
        }

        // check session ownership, only owner can create 'new' invitations
        if ($status == self::STATUS_INVITED && $session['owner'] != $this->user) {
            throw new Exception("No permission to create an invitation.", file_api_core::ERROR_CODE);
        }

        if ($session['owner'] == $user) {
            throw new Exception("Not possible to create an invitation for the session creator.", file_api_core::ERROR_CODE);
        }

        // Update Manticore 'access' array
        if ($status == self::STATUS_INVITED) {
            $req = $this->get_request();
            $res = $req->editor_add($session_id, $user, file_manticore_api::ACCESS_WRITE);

            if (!$res) {
                throw new Exception("Failed to create an invitation.", file_api_core::ERROR_CODE);
            }
        }

        // insert invitation
        $db     = $this->rc->get_dbh();
        $result = $db->query("INSERT INTO `{$this->invitations_table}`"
            . " (`session_id`, `user`, `status`, `changed`)"
            . " VALUES (?, ?, ?, " . $db->now() . ")",
            $session_id, $user, $status);

        if (!$db->affected_rows($result)) {
            throw new Exception("Failed to create an invitation.", file_api_core::ERROR_CODE);
        }
    }

    /**
     * Delete an invitation (only session owner can do that)
     *
     * @param string $session_id Session identifier
     * @param string $user       User identifier
     *
     * @throws Exception
     */
    public function invitation_delete($session_id, $user)
    {
        $db     = $this->rc->get_dbh();
        $result = $db->query("DELETE FROM `{$this->invitations_table}`"
            . " WHERE `session_id` = ? AND `user` = ?"
                . " AND EXISTS (SELECT 1 FROM `{$this->sessions_table}` WHERE `id` = ? AND `owner` = ?)",
            $session_id, $user, $session_id, $this->user);

        if (!$db->affected_rows($result)) {
            throw new Exception("Failed to delete an invitation.", file_api_core::ERROR_CODE);
        }

        // Update Manticore 'access' array
        $req = $this->get_request();
        $res = $req->editor_delete($session_id, $user);

        if (!$res) {
            throw new Exception("Failed to remove an invitation.", file_api_core::ERROR_CODE);
        }
    }

    /**
     * Update an invitation status
     *
     * @param string $session_id Session identifier
     * @param string $user       User identifier (use null for current user)
     * @param string $status     Invitation status (accepted, declined)
     *
     * @throws Exception
     */
    public function invitation_update($session_id, $user, $status)
    {
        if (empty($user)) {
            $user = $this->user;
        }

        if ($status != self::STATUS_ACCEPTED && $status != self::STATUS_DECLINED) {
            throw new Exception("Invalid invitation status.", file_api_core::ERROR_CODE);
        }

        // get session information
        $session = $this->session_info($session_id);

        if (empty($session)) {
            throw new Exception("Document session not found.", file_api_core::ERROR_CODE);
        }

        // check session ownership
        if ($user != $this->user && $session['owner'] != $this->user) {
            throw new Exception("No permission to update an invitation.", file_api_core::ERROR_CODE);
        }

        if ($session['owner'] == $this->user) {
            $status = $status . '-owner';
        }

        $db     = $this->rc->get_dbh();
        $result = $db->query("UPDATE `{$this->invitations_table}`"
            . " SET `status` = ?, `changed` = " . $db->now()
            . " WHERE `session_id` = ? AND `user` = ?",
            $status, $session_id, $user);

        if (!$db->affected_rows($result)) {
            throw new Exception("Failed to update an invitation status.", file_api_core::ERROR_CODE);
        }

        // Update Manticore 'access' array if an owner accepted an invitation request
        if ($status == self::STATUS_ACCEPTED_OWNER) {
            // @todo
        }
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

        if ($session['owner'] == $this->user) {
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
