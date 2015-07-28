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

require 'SabreDAV/vendor/autoload.php';
use Sabre\DAV\Client;

class webdav_file_storage implements file_storage
{
    /**
     * @var rcube
     */
    protected $rc;


    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var string
     */
    protected $title;
    
    /**
     * @var Sabre\DAV\Client
     */
    protected $client;


    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->rc = rcube::get_instance();
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
        $settings = array(
            'baseUri'  => $this->config['baseUri'],
            'userName' => $username,
            'password' => $password,
            'authType' => Client::AUTH_BASIC,
        );
        
        $client = new Client($settings);
        
        try {
            $client->propfind('',array());
        } catch (Exception $e) {
            return false;
        }
        if ($this->title) { 
            $_SESSION[$this->title . '_webdav_user']  = $username;
            $_SESSION[$this->title . '_webdav_pass']  = $this->rc->encrypt($password);
            $this->client = $client;
        }
        return true;
    }

    /**
     * Get password and name of authenticated user
     *
     * @return array Authenticated user data
     */
    public function auth_info()
    {
        return array(
            'username' => $this->config['username'],
            'password' => $this->config['password'],
        );
    }

    /**
     * Configures environment
     *
     * @param array  $config Configuration
     * @param string $title  Source identifier
     */
    public function configure($config, $title = null)
    {
        $this->config = array_merge($this->config, $config);
        $this->title = $title;
    }
    
    /**
     * Initializes WebDAV client
     */
    protected function init()
    {
        if ($this->client !== null)
            return true;
        
        //Load configuration for main driver
        $config['baseUri'] = $this->rc->config->get('fileapi_webdav_baseUri');
        if (!empty($config['baseUri'])) {
            $config['username'] = $_SESSION['username'];
            $config['password'] = $this->rc->decrypt($_SESSION['password']);
        }
        $this->config = array_merge($config, $this->config);
        
        //Use session username if not set in configuration
        if (!isset($this->config['username']))
            $this->config['username'] = $_SESSION[$this->title . 'webdav_user'];
        if (!isset($this->config['password']) )   
            $this->config['password'] = $this->rc->decrypt($_SESSION[$this->title . 'webdav_pass']);

        $this->client = new Client(array(
            'baseUri'  => $this->config['baseUri'],
            'userName' => $this->config['username'],
            'password' => $this->config['password'],
            'authType' => Client::AUTH_BASIC,
        ));
    }

    /**
     * Returns current instance title
     *
     * @return string Instance title (mount point)
     */
    public function title()
    {
        return $this->title;
    }

    /**
     * Storage driver capabilities
     *
     * @return array List of capabilities
     */
    public function capabilities()
    {
        // find max filesize value
        $max_filesize = parse_bytes(ini_get('upload_max_filesize'));
        $max_postsize = parse_bytes(ini_get('post_max_size'));
        if ($max_postsize && $max_postsize < $max_filesize) {
            $max_filesize = $max_postsize;
        }

        return array(
            file_storage::CAPS_MAX_UPLOAD => $max_filesize,
            file_storage::CAPS_QUOTA      => true,
            file_storage::CAPS_LOCKS      => true, //TODO: Implement WebDAV locks
        );
    }

    /**
     * Save configuration of external driver (mount point)
     *
     * @param array $driver Driver data
     *
     * @throws Exception
     */
    public function driver_create($driver)
    {
        throw new Exception("Not implemented", file_storage::ERROR_UNSUPPORTED);
    }

    /**
     * Delete configuration of external driver (mount point)
     *
     * @param string $name Driver instance name
     *
     * @throws Exception
     */
    public function driver_delete($name)
    {
        throw new Exception("Not implemented", file_storage::ERROR_UNSUPPORTED);
    }

    /**
     * Return list of registered drivers (mount points)
     *
     * @return array List of drivers data
     * @throws Exception
     */
    public function driver_list()
    {
        return array();
        //TODO: Stub. Not implemented.
    }

    /**
     * Update configuration of external driver (mount point)
     *
     * @param string $title  Driver instance title
     * @param array  $driver Driver data
     *
     * @throws Exception
     */
    public function driver_update($title, $driver)
    {
        throw new Exception("Not implemented", file_storage::ERROR_UNSUPPORTED);
    }

    /**
     * Returns metadata of the driver
     *
     * @return array Driver meta data (image, name, form)
     */
    public function driver_metadata()
    {
        $image_content = file_get_contents(__DIR__ . '/owncloud.png');

        $metadata = array(
            'image' => 'data:image/png;base64,' . base64_encode($image_content),
            'name'  => 'WebDAV',
            'ref'   => 'http://www.webdav.org/',
            'description' => 'WebDAV client',
            'form'  => array(
                'baseUri'  => 'Base URI',
                'username' => 'Username',
                'password' => 'Password',
            ),
        );
        
        // these are returned when authentication on folders list fails
        if ($this->config['userName']) {
            $metadata['form_values'] = array(
                'baseUri'  => $this->config['baseUri'],
                'username' => $this->config['username'],
            );
        }

        return $metadata;
    }

    /**
     * Validate metadata (config) of the driver
     *
     * @param array $metadata Driver metadata
     *
     * @return array Driver meta data to be stored in configuration
     * @throws Exception
     */
    public function driver_validate($metadata)
    {
        if (!is_string($metadata['username']) || !strlen($metadata['username'])) {
            throw new Exception("Missing user name.", file_storage::ERROR);
        }

        if (!is_string($metadata['password']) || !strlen($metadata['password'])) {
            throw new Exception("Missing user password.", file_storage::ERROR);
        }

        if (!is_string($metadata['baseUri']) || !strlen($metadata['baseUri'])) {
            throw new Exception("Missing base URL.", file_storage::ERROR);
        }
        
        // Ensure baseUri ends with a slash
        $baseUri = $metadata['baseUri'];
        if (substr($baseUri, -1) != '/')
            $baseUri .= '/';
        
        $this->config['baseUri'] = $baseUri;

        if (!$this->authenticate($metadata['username'], $metadata['password'])) {
            throw new Exception("Unable to authenticate user", file_storage::ERROR_NOAUTH);
        }

        return array(
            'baseUri' => $baseUri,
            'username' => $metadata['username'],
            'password' => $metadata['password'],
        );
    }

    /**
     * Create a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param array  $file      File data (path, type)
     *
     * @throws Exception
     */
    public function file_create($file_name, $file)
    {
        $this->init();
        
        if ($file['path']) {
            $data = fopen($file['path'], 'r');
        } else {
            //Resource or data
            $data = $file['content'];
        }
        $response = $this->client->request('PUT', $file_name, $data);
        
        if ($response['statusCode'] != 201) {
            throw new Exception("Storage error. ".$response['body'], file_storage::ERROR);
        }
    }

    /**
     * Update a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param array  $file      File data (path, type)
     *
     * @throws Exception
     */
    public function file_update($file_name, $file)
    {
        $this->init();
        
        if ($file['path']) {
            $data = fopen($file['path'], 'r');
        } else {
            //Resource or data
            $data = $file['content'];
        }
        $response = $this->client->request('PUT', $file_name, $data);
        
        if ($response['statusCode'] != 204) {
            throw new Exception("Storage error. ".$response['body'], file_storage::ERROR);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $file_name Name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_delete($file_name)
    {
        $this->init();
        print_r($file_name);
        
        $response = $this->client->request('DELETE', $file_name);
        if ($response['statusCode'] != 204) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
        }
    }

    /**
     * Return file body.
     *
     * @param string   $file_name Name of a file (with folder path)
     * @param array    $params    Parameters (force-download)
     * @param resource $fp        Print to file pointer instead (send no headers)
     *
     * @throws Exception
     */
    public function file_get($file_name, $params = array(), $fp = null)
    {
        $this->init();
        
        //TODO: Write directly to $fp
        $response = $this->client->request('GET', $file_name);

        $size = $response['headers']['content-length'][0];
        if ($response['statusCode'] != 200) {
            throw new Exception("Storage error. File not found.", file_storage::ERROR);
        }
        
        // write to file pointer, send no headers
        if ($fp) {
            if ($size)
                fwrite($fp, $response['body']);
            return;
        }

        if (!empty($params['force-download'])) {
            $disposition = 'attachment';
            header("Content-Type: application/octet-stream");
// @TODO
//            if ($browser->ie)
//                header("Content-Type: application/force-download");
        }
        else {
            $mimetype = file_utils::real_mimetype($params['force-type'] ? $params['force-type'] : $file['type']);
            $disposition = 'inline';

            header("Content-Transfer-Encoding: binary");
            header("Content-Type: $mimetype");
        }

        $filename = addcslashes(end(explode('/', $file_name)), '"');

        // Workaround for nasty IE bug (#1488844)
        // If Content-Disposition header contains string "attachment" e.g. in filename
        // IE handles data as attachment not inline
/*
@TODO
        if ($disposition == 'inline' && $browser->ie && $browser->ver < 9) {
            $filename = str_ireplace('attachment', 'attach', $filename);
        }
*/
        header("Content-Length: " . $size);
        header("Content-Disposition: $disposition; filename=\"$filename\"");

        if ($size)
            echo $response['body'];
    }

    /**
     * Returns file metadata.
     *
     * @param string $file_name Name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_info($file_name)
    {
        $this->init();
        
        try {
            $props = $this->client->propfind($file_name, array(
                '{DAV:}resourcetype',
                '{DAV:}getcontentlength',
                '{DAV:}getcontenttype',
                '{DAV:}getlastmodified',
                '{DAV:}creationdate'
            ), 0);
        } catch (Exception $e) {
            throw new Exception("Storage error. File not found.", file_storage::ERROR);
        }
        
        $mtime = new DateTime($props['{DAV:}getlastmodified']);
        $ctime = new DateTime($props['{DAV:}creationdate']);
    
        return array (         
            'name'     => end(explode('/', $file_name)),
            'size'     => (int) $props['{DAV:}getcontentlength'],
            'type'     => (string) $props['{DAV:}getcontenttype'],
            'mtime'    => $mtime ? $mtime->format($this->config['date_format']) : '',
            'ctime'    => $ctime ? $ctime->format($this->config['date_format']) : '',
            'modified' => $mtime ? $mtime->format('U') : 0,
            'created'  => $ctime ? $ctime->format('U') : 0,
            );
    }

    /**
     * List files in a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param array  $params      List parameters ('sort', 'reverse', 'search', 'prefix')
     *
     * @return array List of files (file properties array indexed by filename)
     * @throws Exception
     */
    public function file_list($folder_name, $params = array())
    {
        $this->init();
        
        try {
            $items = $this->client->propfind($folder_name, array(
                '{DAV:}resourcetype',
                '{DAV:}getcontentlength',
                '{DAV:}getcontenttype',
                '{DAV:}getlastmodified',
                '{DAV:}creationdate'
            ), 1);
        } catch (Exception $e) {
            throw new Exception("Storage error. Folder not found.", file_storage::ERROR);
        }

        $result = array();
        foreach($items as $file => $props) {
            //Skip directories
            $is_dir = in_array('{DAV:}collection', $props['{DAV:}resourcetype']->resourceType);
            if ($is_dir)
                continue;
            
            $mtime = new DateTime($props['{DAV:}getlastmodified']);
            $ctime = new DateTime($props['{DAV:}creationdate']);
        
            $path = $this->get_full_url($file);
        
            $result[$path] = array (         
                'name'     => end(explode('/', $path)),
                'size'     => (int) $props['{DAV:}getcontentlength'],
                'type'     => (string) $props['{DAV:}getcontenttype'],
                'mtime'    => $mtime ? $mtime->format($this->config['date_format']) : '',
                'ctime'    => $ctime ? $ctime->format($this->config['date_format']) : '',
                'modified' => $mtime ? $mtime->format('U') : 0,
                'created'  => $ctime ? $ctime->format('U') : 0,
                );
        }

        // @TODO: pagination, search (by filename, mimetype)
        
        // Sorting
        $sort  = !empty($params['sort']) ? $params['sort'] : 'name';
        $index = array();

        if ($sort == 'mtime') {
            $sort = 'modified';
        }

        if (in_array($sort, array('name', 'size', 'modified'))) {
            foreach ($result as $key => $val) {
                $index[$key] = $val[$sort];
            }
            array_multisort($index, SORT_ASC, SORT_NUMERIC, $result);
        }

        if ($params['reverse']) {
            $result = array_reverse($result, true);
        }
        
        return $result;
    }

    /**
     * Copy a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param string $new_name  New name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_copy($file_name, $new_name)
    {
        $this->init();
            
        $response = $this->client->request('COPY', $file_name, null, array('Destination' => $this->config['baseUri'].'/'.$new_name));
        if ($response['statusCode'] != 201) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
        }
    }

    /**
     * Move (or rename) a file.
     *
     * @param string $file_name Name of a file (with folder path)
     * @param string $new_name  New name of a file (with folder path)
     *
     * @throws Exception
     */
    public function file_move($file_name, $new_name)
    {
        $this->init();
            
        $response = $this->client->request('MOVE', $file_name, null, array('Destination' => $this->config['baseUri'].'/'.$new_name));
        if ($response['statusCode'] != 201) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
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
        $this->init();
        
        $response = $this->client->request('MKCOL', $folder_name);
        if ($response['statusCode'] != 201) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
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
        $this->init();
        
        $response = $this->client->request('DELETE', $folder_name);
        if ($response['statusCode'] != 204) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
        }
    }

    /**
     * Move/Rename a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $new_name    New name of a folder with full path
     *
     * @throws Exception on error
     */
    public function folder_move($folder_name, $new_name)
    {
        $this->init();
        
        $response = $this->client->request('MOVE', $folder_name, null, array('Destination' => $this->config['baseUri'].'/'.$new_name));
        if ($response['statusCode'] != 201) {
            throw new Exception("Storage error: ".$response['body'], file_storage::ERROR);
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
        $this->init();
        
        try {
            $items = $this->client->propfind('', array(
                '{DAV:}resourcetype',
            ), 'infinity'); //TODO: Maybe replace infinity by recursion
        } catch (Exception $e) {
            print_r($e);
            throw new Exception("User credentials not provided", file_storage::ERROR_NOAUTH);
        }
        $result = array('.');
        foreach($items as $file => $props) {
            //Skip directories
            $is_dir = in_array('{DAV:}collection', $props['{DAV:}resourcetype']->resourceType);
            if (!$is_dir)
                continue;
            
            $path = $this->get_relative_url($file);
            
            if (!empty($path))
                $result[] = './'.$path;
        }
        // ensure sorted folders
        usort($result, array($this, 'sort_folder_comparator'));
        return $result;
    }

    /**
     * Returns a list of locks
     *
     * This method should return all the locks for a particular URI, including
     * locks that might be set on a parent URI.
     *
     * If child_locks is set to true, this method should also look for
     * any locks in the subtree of the URI for locks.
     *
     * @param string $uri         URI
     * @param bool   $child_locks Enables subtree checks
     *
     * @return array List of locks
     * @throws Exception
     */
    public function lock_list($uri, $child_locks = false)
    {
        $this->init_lock_db();

        // convert URI to global resource string
        $uri = $this->uri2resource($uri);

        // get locks list
        $list = $this->lock_db->lock_list($uri, $child_locks);

        // convert back resource string into URIs
        foreach ($list as $idx => $lock) {
            $list[$idx]['uri'] = $this->resource2uri($lock['uri']);
        }

        return $list;
    }

    /**
     * Locks a URI
     *
     * @param string $uri  URI
     * @param array  $lock Lock data
     *                     - depth: 0/'infinite'
     *                     - scope: 'shared'/'exclusive'
     *                     - owner: string
     *                     - token: string
     *                     - timeout: int
     *
     * @throws Exception
     */
    public function lock($uri, $lock)
    {
        $this->init_lock_db();

        // convert URI to global resource string
        $uri = $this->uri2resource($uri);

        if (!$this->lock_db->lock($uri, $lock)) {
            throw new Exception("Database error. Unable to create a lock.", file_storage::ERROR);
        }
    }

    /**
     * Removes a lock from a URI
     *
     * @param string $path URI
     * @param array  $lock Lock data
     *
     * @throws Exception
     */
    public function unlock($uri, $lock)
    {
        $this->init_lock_db();

        // convert URI to global resource string
        $uri = $this->uri2resource($uri);

        if (!$this->lock_db->unlock($uri, $lock)) {
            throw new Exception("Database error. Unable to remove a lock.", file_storage::ERROR);
        }
    }

    /**
     * Return disk quota information for specified folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @return array Quota
     * @throws Exception
     */
    public function quota($folder)
    {
        $this->init();
        
        $props = $this->client->propfind($file_name, array(
            '{DAV:}quota-available-bytes',
            '{DAV:}quota-used-bytes',
        ), 0);
        
        $usedB = $props['{DAV:}quota-used-bytes'];
        $availableB = $props['{DAV:}quota-available-bytes'];

        return array(
            // expected values in kB
            'total' => ($usedB + $availableB) / 1024,
            'used'  => $usedB / 1024,
        );
    }
    
    /**
     * Gets the relative URL of a resource
     *
     * @param string $url WebDAV URL
     * @return string Path relative to root (title/.)
     */
    protected function get_relative_url($url)
    {
        return trim(
            str_replace(
                $this->config['baseUri'], 
                '',
                $this->client->getAbsoluteUrl($url))
            , '/');
    }
    
    /**
     * Gets the full URL of a resource
     *
     * @param string $url WebDAV URL
     * @return string Path relative to chwala root
     */
    protected function get_full_url($url)
    {
        if (!empty($this->title))
            return $this->title.'/'.$this->get_relative_url($url);
        return $this->get_relative_url($url);
    }
    
    protected function uri2resource($uri)
    {
        list($file, $repo_id, $library) = $this->find_library($uri);

        // convert to imap charset (to be safe to store in DB)
        $uri = rcube_charset::convert($uri, RCUBE_CHARSET, 'UTF7-IMAP');

        return 'seafile://' . urlencode($library['owner']) . '@' . $this->config['host'] . '/' . $uri;
    }

    protected function resource2uri($resource)
    {
        if (!preg_match('|^seafile://([^@]+)@([^/]+)/(.*)$|', $resource, $matches)) {
            throw new Exception("Internal storage error. Unexpected data format.", file_storage::ERROR);
        }

        $user = urldecode($matches[1]);
        $uri  = $matches[3];

        // convert from imap charset (to be safe to store in DB)
        $uri = rcube_charset::convert($uri, 'UTF7-IMAP', RCUBE_CHARSET);

        return $uri;
    }

    /**
     * Initializes file_locks object
     */
    protected function init_lock_db()
    {
        if (!$this->lock_db) {
            $this->lock_db = new file_locks;
        }
    }
}

