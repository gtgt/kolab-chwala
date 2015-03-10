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

class file_api extends file_api_core
{
    public $session;
    public $output_type = file_api_core::OUTPUT_JSON;

    private $conf;
    private $browser;


    public function __construct()
    {
        $rcube = rcube::get_instance();
        $rcube->add_shutdown_function(array($this, 'shutdown'));

        $this->conf = $rcube->config;
        $this->session_init();

        if ($_SESSION['config']) {
            $this->config = $_SESSION['config'];
        }

        $this->locale_init();
    }

    /**
     * Process the request and dispatch it to the requested service
     */
    public function run()
    {
        $this->request = strtolower($_GET['method']);

        // Check the session, authenticate the user
        if (!$this->session_validate()) {
            $this->session->destroy(session_id());

            if ($this->request == 'authenticate') {
                $this->session->regenerate_id(false);

                if ($username = $this->authenticate()) {
                    $_SESSION['user']   = $username;
                    $_SESSION['time']   = time();
                    $_SESSION['config'] = $this->config;

                    // remember client API version
                    if (is_numeric($_GET['version'])) {
                        $_SESSION['version'] = $_GET['version'];
                    }

                    $this->output_success(array(
                        'token'        => session_id(),
                        'capabilities' => $this->capabilities(),
                    ));
                }
            }

            throw new Exception("Invalid session", 403);
        }

        // Call service method
        $result = $this->request_handler($this->request);

        // Send success response, errors should be handled by driver class
        // by throwing exceptions or sending output by itself
        $this->output_success($result);
    }

    /**
     * Session validation check and session start
     */
    private function session_validate()
    {
        $sess_id = rcube_utils::request_header('X-Session-Token') ?: $_REQUEST['token'];

        if (empty($sess_id)) {
            session_start();
            return false;
        }

        session_id($sess_id);
        session_start();

        if (empty($_SESSION['user'])) {
            return false;
        }

        $timeout = $this->conf->get('session_lifetime', 0) * 60;
        if ($timeout && $_SESSION['time'] && $_SESSION['time'] < time() - $timeout) {
            return false;
        }
        // update session time
        $_SESSION['time'] = time();

        return true;
    }

    /**
     * Initializes session
     */
    private function session_init()
    {
        $rcube     = rcube::get_instance();
        $sess_name = $this->conf->get('session_name');
        $lifetime  = $this->conf->get('session_lifetime', 0) * 60;

        if ($lifetime) {
            ini_set('session.gc_maxlifetime', $lifetime * 2);
        }

        ini_set('session.name', $sess_name ? $sess_name : 'file_api_sessid');
        ini_set('session.use_cookies', 0);
        ini_set('session.serialize_handler', 'php');

        // Roundcube Framework >= 1.2
        if (in_array('factory', get_class_methods('rcube_session'))) {
            $this->session = rcube_session::factory($this->conf);
        }
        // Rouncube Framework < 1.2
        else {
            $this->session = new rcube_session($rcube->get_dbh(), $this->conf);
            $this->session->set_secret($this->conf->get('des_key') . dirname($_SERVER['SCRIPT_NAME']));
            $this->session->set_ip_check($this->conf->get('ip_check'));
        }

        $this->session->register_gc_handler(array($rcube, 'gc'));

        // this is needed to correctly close session in shutdown function
        $rcube->session = $this->session;
    }

    /**
     * Script shutdown handler
     */
    public function shutdown()
    {
        // write performance stats to logs/console
        if ($this->conf->get('devel_mode')) {
            if (function_exists('memory_get_peak_usage'))
                $mem = memory_get_peak_usage();
            else if (function_exists('memory_get_usage'))
                $mem = memory_get_usage();

            $log = trim($this->request . ($mem ? sprintf(' [%.1f MB]', $mem/1024/1024) : ''));
            if (defined('FILE_API_START')) {
                rcube::print_timer(FILE_API_START, $log);
            }
            else {
                rcube::console($log);
            }
        }
    }

    /**
     * Authentication request handler (HTTP Auth)
     */
    private function authenticate()
    {
        if (isset($_POST['username'])) {
            $username = $_POST['username'];
            $password = $_POST['password'];
        }
        else if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $username = $_SERVER['PHP_AUTH_USER'];
            $password = $_SERVER['PHP_AUTH_PW'];
        }
        // when used with (f)cgi no PHP_AUTH* variables are available without defining a special rewrite rule
        else if (!isset($_SERVER['PHP_AUTH_USER'])) {
            // "Basic didhfiefdhfu4fjfjdsa34drsdfterrde..."
            if (isset($_SERVER["REMOTE_USER"])) {
                $basicAuthData = base64_decode(substr($_SERVER["REMOTE_USER"], 6));
            }
            else if (isset($_SERVER["REDIRECT_REMOTE_USER"])) {
                $basicAuthData = base64_decode(substr($_SERVER["REDIRECT_REMOTE_USER"], 6));
            }
            else if (isset($_SERVER["Authorization"])) {
                $basicAuthData = base64_decode(substr($_SERVER["Authorization"], 6));
            }
            else if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
                $basicAuthData = base64_decode(substr($_SERVER["HTTP_AUTHORIZATION"], 6));
            }

            if (isset($basicAuthData) && !empty($basicAuthData)) {
                list($username, $password) = explode(":", $basicAuthData);
            }
        }

        if (!empty($username)) {
            $backend = $this->get_backend();
            $result  = $backend->authenticate($username, $password);
        }

        if (empty($result)) {
/*
            header('WWW-Authenticate: Basic realm="' . $this->app_name .'"');
            header('HTTP/1.1 401 Unauthorized');
            exit;
*/
            throw new Exception("Invalid password or username", file_api_core::ERROR_CODE);
        }

        return $username;
    }

    /**
     * Storage/System method handler
     */
    private function request_handler($request)
    {
        // handle "global" requests that don't require api driver
        switch ($request) {
            case 'ping':
                return array();

            case 'quit':
                $this->session->destroy(session_id());
                return array();

            case 'configure':
                foreach (array_keys($this->config) as $name) {
                    if (isset($_GET[$name])) {
                        $this->config[$name] = $_GET[$name];
                    }
                }
                $_SESSION['config'] = $this->config;

                return $this->config;

            case 'upload_progress':
                return $this->upload_progress();

            case 'mimetypes':
                return $this->supported_mimetypes();

            case 'capabilities':
                return $this->capabilities();
        }

        // handle request
        if ($request && preg_match('/^[a-z0-9_-]+$/', $request)) {
            // request name aliases for backward compatibility
            $aliases = array(
                'lock'          => 'lock_create',
                'unlock'        => 'lock_delete',
                'folder_rename' => 'folder_move',
            );

            $request = $aliases[$request] ?: $request;

            require_once __DIR__ . "/api/common.php";
            include_once __DIR__ . "/api/$request.php";

            $class_name = "file_api_$request";
            if (class_exists($class_name, false)) {
                $handler = new $class_name($this);
                return $handler->handle();
            }
        }

        throw new Exception("Unknown method", file_api_core::ERROR_INVALID);
    }

    /**
     * File upload progress handler
     */
    protected function upload_progress()
    {
        if (function_exists('apc_fetch')) {
            $prefix   = ini_get('apc.rfc1867_prefix');
            $uploadid = rcube_utils::get_input_value('id', rcube_utils::INPUT_GET);
            $status   = apc_fetch($prefix . $uploadid);

            if (!empty($status)) {
                $status['percent'] = round($status['current']/$status['total']*100);
                if ($status['percent'] < 100) {
                    $diff = time() - intval($status['start_time']);
                    // calculate time to end of uploading (in seconds)
                    $status['eta'] = intval($diff * (100 - $status['percent']) / $status['percent']);
                    // average speed (bytes per second)
                    $status['rate'] = intval($status['current'] / $diff);
                }
            }

            $status['id'] = $uploadid;

            return $status; // id, done, total, current, percent, start_time, eta, rate
        }

        throw new Exception("Not supported", file_api_core::ERROR_CODE);
    }

    /**
     * Returns complete File URL
     *
     * @param string $file File name (with path)
     *
     * @return string File URL
     */
    public function file_url($file)
    {
        return file_utils::script_uri(). '?method=file_get'
            . '&file=' . urlencode($file)
            . '&token=' . urlencode(session_id());
    }

    /**
     * Returns web browser object
     *
     * @return rcube_browser Web browser object
     */
    public function get_browser()
    {
        if ($this->browser === null) {
            $this->browser = new rcube_browser;
        }

        return $this->browser;
    }

    /**
     * Send success response
     *
     * @param mixed $data Data
     */
    public function output_success($data)
    {
        if (!is_array($data)) {
            $data = array();
        }

        $response = array('status' => 'OK', 'result' => $data);

        if (!empty($_REQUEST['req_id'])) {
            $response['req_id'] = $_REQUEST['req_id'];
        }

        $this->output_send($response);
    }

    /**
     * Send error response
     *
     * @param mixed $response Response data
     * @param int   $code     Error code
     */
    public function output_error($response, $code = null)
    {
        if (is_string($response)) {
            $response = array('reason' => $response);
        }

        $response['status'] = 'ERROR';

        if ($code) {
            $response['code'] = $code;
        }

        if (!empty($_REQUEST['req_id'])) {
            $response['req_id'] = $_REQUEST['req_id'];
        }

        if (empty($response['code'])) {
            $response['code'] = file_api_core::ERROR_CODE;
        }

        $this->output_send($response);
    }

    /**
     * Send response
     *
     * @param mixed $data Data
     */
    protected function output_send($data)
    {
        // Send response
        header("Content-Type: {$this->output_type}; charset=utf-8");
        echo json_encode($data);
        exit;
    }

    /**
     * Returns API version supported by the client
     */
    public function client_version()
    {
        return $_SESSION['version'];
    }

    /**
     * Create a human readable string for a number of bytes
     *
     * @param int Number of bytes
     *
     * @return string Byte string
     */
    public function show_bytes($bytes)
    {
        if ($bytes >= 1073741824) {
            $gb  = $bytes/1073741824;
            $str = sprintf($gb >= 10 ? "%d " : "%.1f ", $gb) . 'GB';
        }
        else if ($bytes >= 1048576) {
            $mb  = $bytes/1048576;
            $str = sprintf($mb >= 10 ? "%d " : "%.1f ", $mb) . 'MB';
        }
        else if ($bytes >= 1024) {
            $str = sprintf("%d ",  round($bytes/1024)) . 'KB';
        }
        else {
            $str = sprintf('%d ', $bytes) . 'B';
        }

        return $str;
    }
}
