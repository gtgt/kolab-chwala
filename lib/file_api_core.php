<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2014, Kolab Systems AG                                |
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

class file_api_core extends file_locale
{
    const API_VERSION = 2;

    const ERROR_CODE    = 500;
    const ERROR_INVALID = 501;

    const OUTPUT_JSON = 'application/json';
    const OUTPUT_HTML = 'text/html';

    public $config = array(
        'date_format' => 'Y-m-d H:i',
        'language'    => 'en_US',
    );

    protected $app_name = 'Kolab File API';
    protected $drivers  = array();
    protected $backend;

    /**
     * Returns API version
     */
    public function client_version()
    {
        return self::API_VERSION;
    }

    /**
     * Initialise authentication/configuration backend class
     *
     * @return file_storage Main storage driver
     */
    public function get_backend()
    {
        if ($this->backend) {
            return $this->backend;
        }

        $rcube  = rcube::get_instance();
        $driver = $rcube->config->get('fileapi_backend', 'kolab');

        $this->backend = $this->load_driver_object($driver);

        // configure api
        $this->backend->configure($this->config);

        return $this->backend;
    }

    /**
     * Return supported/enabled external storage instances
     *
     * @param bool $as_objects Return drivers as objects not config data
     *
     * @return array List of storage drivers
     */
    public function get_drivers($as_objects = false)
    {
        $rcube   = rcube::get_instance();
        $enabled = $rcube->config->get('fileapi_drivers');
        $preconf = $rcube->config->get('fileapi_sources');
        $result  = array();
        $all     = array();
        $iRony   = defined('KOLAB_DAV_ROOT');

        if (!empty($enabled)) {
            $backend = $this->get_backend();
            $drivers = $backend->driver_list();

            foreach ($drivers as $item) {
                // Disable webdav sources/drivers in iRony
                // It does not work when the API is used where
                // some SabreDAV classes are redefined
                if ($iRony && $item['driver'] == 'webdav') {
                    continue;
                }

                $all[] = $item['title'];

                if ($item['enabled'] && in_array($item['driver'], (array) $enabled)) {
                    $result[] = $as_objects ? $this->get_driver_object($item) : $item;
                }
            }
        }

        if (empty($result) && !empty($preconf)) {
            foreach ((array) $preconf as $title => $item) {
                if (!in_array($title, $all)) {
                    $item['title'] = $title;
                    $item['admin'] = true;

                    $result[] = $as_objects ? $this->get_driver_object($item) : $item;
                }
            }
        }

        return $result;
    }

    /**
     * Return driver for specified file/folder path
     *
     * @param string $path Folder/file path
     *
     * @return array Storage driver object, modified path, driver config
     */
    public function get_driver($path)
    {
        $drivers = $this->get_drivers();

        foreach ($drivers as $item) {
            $prefix = $item['title'] . file_storage::SEPARATOR;

            if ($path == $item['title'] || strpos($path, $prefix) === 0) {
                $selected = $item;
                break;
            }
        }

        if (empty($selected)) {
            return array($this->get_backend(), $path);
        }

        $path = substr($path, strlen($selected['title']) + 1);

        return array($this->get_driver_object($selected), $path, $selected);
    }

    /**
     * Initialize driver instance
     *
     * @param array $config Driver config
     *
     * @return file_storage Storage driver instance
     */
    public function get_driver_object($config)
    {
        $key = $config['title'];

        if (empty($this->drivers[$key])) {
            $this->drivers[$key] = $driver = $this->load_driver_object($config['driver']);

            if ($config['username'] == '%u') {
                $backend            = $this->get_backend();
                $auth_info          = $backend->auth_info();
                $config['username'] = $auth_info['username'];
                $config['password'] = $auth_info['password'];
            }
            else if (!empty($config['password']) && empty($config['admin']) && !empty($key)) {
                $config['password'] = $this->decrypt($config['password']);
            }

            // configure api
            $driver->configure(array_merge($config, $this->config), $key);
        }

        return $this->drivers[$key];
    }

    /**
     * Loads a driver
     */
    public function load_driver_object($name)
    {
        $class = $name . '_file_storage';

        if (!class_exists($class, false)) {
            $include_path = __DIR__ . "/drivers/$name" . PATH_SEPARATOR;
            $include_path .= ini_get('include_path');
            set_include_path($include_path);
        }

        return new $class;
    }

    /**
     * Returns storage(s) capabilities
     *
     * @return array Capabilities
     */
    public function capabilities()
    {
        $rcube   = rcube::get_instance();
        $backend = $this->get_backend();
        $caps    = array();

        // check support for upload progress
        if (($progress_sec = $rcube->config->get('upload_progress'))
            && ini_get('apc.rfc1867') && function_exists('apc_fetch')
        ) {
            $caps[file_storage::CAPS_PROGRESS_NAME] = ini_get('apc.rfc1867_name');
            $caps[file_storage::CAPS_PROGRESS_TIME] = $progress_sec;
        }

        // get capabilities of main storage module
        foreach ($backend->capabilities() as $name => $value) {
            // skip disabled capabilities
            if ($value !== false) {
                $caps[$name] = $value;
            }
        }

        // get capabilities of other drivers
        $drivers = $this->get_drivers(true);

        foreach ($drivers as $driver) {
            if ($driver != $backend) {
                $title = $driver->title();
                foreach ($driver->capabilities() as $name => $value) {
                    // skip disabled capabilities
                    if ($value !== false) {
                        $caps['MOUNTPOINTS'][$title][$name] = $value;
                    }
                }
            }
        }

        return $caps;
    }

    /**
     * Return mimetypes list supported by built-in viewers
     *
     * @return array List of mimetypes
     */
    protected function supported_mimetypes()
    {
        $mimetypes = array();
        $dir       = __DIR__ . '/viewers';

        if ($handle = opendir($dir)) {
            while (false !== ($file = readdir($handle))) {
                if (preg_match('/^([a-z0-9_]+)\.php$/i', $file, $matches)) {
                    include_once $dir . '/' . $file;
                    $class  = 'file_viewer_' . $matches[1];
                    $viewer = new $class($this);

                    $mimetypes = array_merge($mimetypes, $viewer->supported_mimetypes());
                }
            }
            closedir($handle);
        }

        return $mimetypes;
    }

    /**
     * Encrypts data with current user password
     *
     * @param string $str A string to encrypt
     *
     * @return string Encrypted string (and base64-encoded)
     */
    public function encrypt($str)
    {
        $rcube = rcube::get_instance();
        $key   = $this->get_crypto_key();

        return $rcube->encrypt($str, $key, true);
    }

    /**
     * Decrypts data encrypted with encrypt() method
     *
     * @param string $str Encrypted string (base64-encoded)
     *
     * @return string Decrypted string
     */
    public function decrypt($str)
    {
        $rcube = rcube::get_instance();
        $key   = $this->get_crypto_key();

        return $rcube->decrypt($str, $key, true);
    }

    /**
     * Set encryption password
     */
    protected function get_crypto_key()
    {
        $key      = 'chwala_crypto_key';
        $rcube    = rcube::get_instance();
        $backend  = $this->get_backend();
        $user     = $backend->auth_info();
        $password = $user['password'] . $user['username'];

        // encryption password must be 24 characters, no less, no more
        if (($len = strlen($password)) > 24) {
            $password = substr($password, 0, 24);
        }
        else {
            $password = $password . substr($rcube->config->get('des_key'), 0, 24 - $len);
        }

        $rcube->config->set($key, $password);

        return $key;
    }
}
