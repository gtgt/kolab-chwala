<?php

define('RCMAIL_PLUGINS_DIR', INSTALL_PATH . '/lib/kolab/plugins');

class kolab_file_storage implements file_storage
{
    /**
     * @var rcube
     */
    protected $rc;

    /**
     * @var array
     */
    protected $folders;


    /**
     * Class constructor
     */
    public function __construct()
    {
        $include_path = INSTALL_PATH . '/lib/kolab' . PATH_SEPARATOR;
        $include_path .= ini_get('include_path');
        set_include_path($include_path);

        $this->rc = rcube::get_instance();
        $this->init();

        // Get list of plugins
        // WARNING: We can use only plugins that are prepared for this
        //          e.g. are not using output or rcmail objects or
        //          doesn't throw errors when using them
        $plugins  = (array)$this->rc->config->get('fileapi_plugins', array('kolab_auth', 'kolab_folders'));
        $required = array('libkolab', 'kolab_folders');

        // Initialize/load plugins
        $this->rc->plugins = kolab_file_plugin_api::get_instance();
        $this->rc->plugins->init($this, '');
        $this->rc->plugins->load_plugins($plugins, $required);
    }

    /**
     * Authenticates a user
     *
     * @param string $username User name
     * @param string $password User password
     *
     * @param bool True on success, False on failure
     */
    public function authenticate($username, $password)
    {
        $auth = $this->rc->plugins->exec_hook('authenticate', array(
            'host'  => $this->select_host($username),
            'user'  => $username,
            'pass'  => $password,
            'valid' => true,
        ));

        // Authenticate - get Roundcube user ID
        if ($auth['valid'] && !$auth['abort']
            && ($this->login($auth['user'], $auth['pass'], $auth['host']))) {
            return true;
        }

        $this->rc->plugins->exec_hook('login_failed', array(
            'host' => $auth['host'],
            'user' => $auth['user'],
        ));
    }


    /**
     * Storage host selection
     */
    private function select_host($username)
    {
        // Get IMAP host
        $host = $this->rc->config->get('default_host');

        if (is_array($host)) {
            list($user, $domain) = explode('@', $username);

            // try to select host by mail domain
            if (!empty($domain)) {
                foreach ($host as $storage_host => $mail_domains) {
                    if (is_array($mail_domains) && in_array_nocase($domain, $mail_domains)) {
                        $host = $storage_host;
                        break;
                    }
                    else if (stripos($storage_host, $domain) !== false || stripos(strval($mail_domains), $domain) !== false) {
                        $host = is_numeric($storage_host) ? $mail_domains : $storage_host;
                        break;
                    }
                }
            }

            // take the first entry if $host is not found
            if (is_array($host)) {
                list($key, $val) = each($default_host);
                $host = is_numeric($key) ? $val : $key;
            }
        }

        return rcube_utils::parse_host($host);
    }

    /**
     * Authenticates a user in IMAP
     */
    private function login($username, $password, $host)
    {
        if (empty($username)) {
            return false;
        }

        $login_lc     = $this->rc->config->get('login_lc');
        $default_port = $this->rc->config->get('default_port', 143);

        // parse $host
        $a_host = parse_url($host);
        if ($a_host['host']) {
            $host = $a_host['host'];
            $ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? $a_host['scheme'] : null;
            if (!empty($a_host['port'])) {
                $port = $a_host['port'];
            }
            else if ($ssl && $ssl != 'tls' && (!$default_port || $default_port == 143)) {
                $port = 993;
            }
        }

        if (!$port) {
            $port = $default_port;
        }

        // Convert username to lowercase. If storage backend
        // is case-insensitive we need to store always the same username
        if ($login_lc) {
            if ($login_lc == 2 || $login_lc === true) {
                $username = mb_strtolower($username);
            }
            else if (strpos($username, '@')) {
                // lowercase domain name
                list($local, $domain) = explode('@', $username);
                $username = $local . '@' . mb_strtolower($domain);
            }
        }

        // Here we need IDNA ASCII
        // Only rcube_contacts class is using domain names in Unicode
        $host     = rcube_utils::idn_to_ascii($host);
        $username = rcube_utils::idn_to_ascii($username);

        // user already registered?
        if ($user = rcube_user::query($username, $host)) {
            $username = $user->data['username'];
        }

        // authenticate user in IMAP
        $storage = $this->rc->get_storage();
        if (!$storage->connect($host, $username, $password, $port, $ssl)) {
            return false;
        }

        // No user in database, but IMAP auth works
        if (!is_object($user)) {
            if ($this->rc->config->get('auto_create_user')) {
                // create a new user record
                $user = rcube_user::create($username, $host);

                if (!$user) {
                    rcube::raise_error(array(
                        'code' => 620, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Failed to create a user record",
                    ), true, false);
                    return false;
                }
            }
            else {
                rcube::raise_error(array(
                    'code' => 620, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Access denied for new user $username. 'auto_create_user' is disabled",
                ), true, false);
                return false;
            }
        }
        // set session vars
        $_SESSION['user_id']      = $user->ID;
        $_SESSION['username']     = $user->data['username'];
        $_SESSION['storage_host'] = $host;
        $_SESSION['storage_port'] = $port;
        $_SESSION['storage_ssl']  = $ssl;
        $_SESSION['password']     = $this->rc->encrypt($password);

        $this->init($user);

        // force reloading of mailboxes list/data
        $storage->clear_cache('mailboxes', true);

        return true;
    }

    protected function init($user = null)
    {
        if ($_SESSION['user_id'] || $user) {
            // overwrite config with user preferences
            $this->rc->user = $user ? $user : new rcube_user($_SESSION['user_id']);
            $this->rc->config->set_user_prefs((array)$this->rc->user->get_prefs());

            $storage = $this->rc->get_storage();
            $storage->set_charset($this->rc->config->get('default_charset', RCMAIL_CHARSET));

            setlocale(LC_ALL, 'en_US.utf8', 'en_US.UTF-8');
        }
    }

    /**
     * Create a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     * @param array  $file        File data (path, type)
     *
     * @throws Exception
     */
    public function file_create($folder_name, $file_name, $file)
    {
        $exists = $this->get_file_object($folder_name, $file_name, $folder);
        if (!empty($exists)) {
            throw new Exception("Storage error. File exists.", file_api::ERROR_CODE);
        }

        $object = $this->to_file_object(array(
            'name' => $file_name,
            'type' => $file['type'],
            'path' => $file['path'],
        ));

        // save the file object in IMAP
        $saved = $folder->save($object, 'file');
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving object to Kolab server"),
                true, false);

            throw new Exception("Storage error. Saving object failed.", file_api::ERROR_CODE);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     *
     * @throws Exception
     */
    public function file_delete($folder_name, $file_name)
    {
        $file = $this->get_file_object($folder_name, $file_name, $folder);
        if (empty($file)) {
            throw new Exception("Storage error. File not found.", file_api::ERROR_CODE);
        }

        $deleted = $folder->delete($file);
        if (!$deleted) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error deleting object from Kolab server"),
                true, false);

            throw new Exception("Storage error. Deleting object failed.", file_api::ERROR_CODE);
        }
    }

    /**
     * Return file body.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     *
     * @throws Exception
     */
    public function file_get($folder_name, $file_name)
    {
        $file = $this->get_file_object($folder_name, $file_name, $folder);
        if (empty($file)) {
            throw new Exception("Storage error. File not found.", file_api::ERROR_CODE);
        }

        $file = $this->from_file_object($file);

        header("Content-Transfer-Encoding: binary");
        header("Content-Type: " . $file['type']);
        header("Content-Length: " . $file['size']);

        $filename = addcslashes($file['name'], '"');
        header("Content-Disposition: inline; filename=\"$filename\"");

        $folder->get_attachment($file['_msguid'], $file['fileid'], $file['_mailbox'], true);
    }

    /**
     * List files in a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @return array List of files (file properties array indexed by filename)
     * @throws Exception
     */
    public function file_list($folder_name)
    {
        $folder = $this->get_folder_object($folder_name);
        $files  = $folder->select(array(array('type', '=', 'file')));
        $result = array();

        foreach ($files as $idx => $file) {
            $file = $this->from_file_object($file);

            if (!isset($file['name'])) {
                continue;
            }

            $result[$file['name']] = array(
                'size'  => (int) $file['size'],
                'type'  => (string) $file['type'],
                'mtime' => $file['changed']->format($_SESSION['config']['date_format']),
            );
            unset($files[$idx]);
        }

        // @TODO: sort by size and mtime, pagination, search (by filename, mimetype)

        if (!$sort || $sort == 'name') {
            ksort($result, SORT_LOCALE_STRING);
        }

        return $result;
    }

    /**
     * Rename a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     * @param string $new_name    New name of a file
     *
     * @throws Exception
     */
    public function file_rename($folder_name, $file_name, $new_name)
    {
        $file = $this->get_file_object($folder_name, $file_name, $folder);
        if (empty($file)) {
            throw new Exception("Storage error. File not found.", file_api::ERROR_CODE);
        }

        $new = $this->get_file_object($folder_name, $new_name);
        if (!empty($new)) {
            throw new Exception("Storage error. File exists.", file_api::ERROR_CODE);
        }

        // Save to temp file
        $temp_dir  = unslashify($this->rc->config->get('temp_dir'));
        $file_path = tempnam($temp_dir, 'rcmAttmnt');
        $fd        = fopen($file_path, 'w');

        if (!$fd) {
            throw new Exception("Storage error. File rename failed.", file_api::ERROR_CODE);
        }

        $folder->get_attachment($file['_msguid'], $file['fileid'], $file['_mailbox'], false, $fd);
        fclose($fp);

        if (!file_exists($file_path)) {
            throw new Exception("Storage error. File rename failed.", file_api::ERROR_CODE);
        }

        // Update object
        $cid = key($file['_attachments']);
        $file['_attachments'][$cid]['name'] = $new_name;
        $file['_attachments'][$cid]['path'] = $file_path;
        $file['_attachments'][0] = $file['_attachments'][$cid];
        $file['_attachments'][$cid] = false;

        $saved = $folder->save($file);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error updating object on Kolab server"),
                true, false);

            throw new Exception("Storage error. File rename failed.", file_api::ERROR_CODE);
        }
    }

    /**
     * Create a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception on error
     */
    public function folder_create($folder_name)
    {
        $folder_name = rcube_charset::convert($folder_name, RCMAIL_CHARSET, 'UTF7-IMAP');
        $success     = kolab_storage::folder_create($folder_name, 'file');

        if (!$success) {
            throw new Exception("Storage error. Unable to create folder", file_api::ERROR_CODE);
        }
    }

    /**
     * Delete a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception on error
     */
    public function folder_delete($folder_name)
    {
        $folder_name = rcube_charset::convert($folder_name, RCMAIL_CHARSET, 'UTF7-IMAP');
        $success     = kolab_storage::folder_delete($folder_name);

        if (!$success) {
            throw new Exception("Storage error. Unable to delete folder.", file_api::ERROR_CODE);
        }
    }

    /**
     * Rename a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $new_name    New name of a folder with full path
     *
     * @throws Exception on error
     */
    public function folder_rename($folder_name, $new_name)
    {
        $folder_name = rcube_charset::convert($folder_name, RCMAIL_CHARSET, 'UTF7-IMAP');
        $new_name    = rcube_charset::convert($new_name, RCMAIL_CHARSET, 'UTF7-IMAP');
        $success     = kolab_storage::folder_rename($folder_name, $new_name);

        if (!$success) {
            throw new Exception("Storage error. Unable to rename folder", file_api::ERROR_CODE);
        }
    }

    /**
     * Returns list of folders.
     *
     * @return array List of folders
     * @throws Exception
     */
    public function folder_list()
    {
        $storage = $this->rc->get_storage();
        $folders = $storage->list_folders('', '*', 'file');

        if (!is_array($folders)) {
            throw new Exception("Storage error. Unable to get folders list.", file_api::ERROR_CODE);
        }

        foreach ($folders as $folder) {
            $folder = rcube_charset::convert($folder_name, 'UTF7-IMAP', RCMAIL_CHARSET);
        }

        return $folders;
    }

    /**
     * Get file object.
     *
     * @param string               $folder_name Name of a folder with full path
     * @param string               $file_name   Name of a file
     * @param kolab_storage_folder $folder      Reference to folder object
     *
     * @return array File data
     * @throws Exception
     */
    protected function get_file_object($folder_name, $file_name, &$folder = null)
    {
        $folder = $this->get_folder_object($folder_name);
        $files  = $folder->select(array(
            array('type', '=', 'file'),
            array('words', '=', ' ' . $file_name . ' ') // @TODO: this looks like a hack
        ));

        return array_shift($files);
    }

    /**
     * Get folder object.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @return kolab_storage_folder Folder object
     * @throws Exception
     */
    protected function get_folder_object($folder_name)
    {
        if (empty($this->folders[$folder_name])) {
            $imap_name = rcube_charset::convert($folder_name, RCMAIL_CHARSET, 'UTF7-IMAP');
            $folder    = kolab_storage::get_folder($imap_name);

            if (!$folder) {
                throw new Exception("Storage error. Folder not found.", file_api::ERROR_CODE);
            }

            $this->folders[$folder_name] = $folder;
        }

        return $this->folders[$folder_name];
    }

    /**
     * Simplify internal structure of the file object
     */
    protected function from_file_object($file)
    {
        if (empty($file['_attachments'])) {
            return $file;
        }

        $attachment = array_shift($file['_attachments']);

        $file['name']   = $attachment['name'];
        $file['size']   = $attachment['size'];
        $file['type']   = $attachment['mimetype'];
        $file['fileid'] = $attachment['id'];

        unset($file['_attachments']);

        return $file;
    }

    /**
     * Convert to kolab_format internal structure of the file object
     */
    protected function to_file_object($file)
    {
        // @TODO if path is empty and fileid exists it is an update
        // get attachment body and save it in path

        $file['_attachments'] = array(
            0 => array(
                'name'     => $file['name'],
                'path'     => $file['path'],
                'mimetype' => $file['type'],
                'size'     => $file['size'],
        ));

        unset($file['name']);
        unset($file['size']);
        unset($file['type']);
        unset($file['path']);
        unset($file['fileid']);

        return $file;
    }
}
