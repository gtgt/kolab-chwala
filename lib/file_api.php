<?php

class file_api
{
    const ERROR_CODE = 500;
    const OUTPUT_JSON = 'application/json';
    const OUTPUT_HTML = 'text/html';

    private $app_name = 'Kolab File API';
    private $api;
    private $output_type = self::OUTPUT_JSON;
    private $config = array(
        'date_format' => 'Y-m-d H:i',
        'language'    => 'en_US',
    );

    public function __construct()
    {
    }

    /**
     * Initialise backend class
     */
    protected function api_init()
    {
        if ($this->api) {
            return;
        }

        // @TODO: config
        $driver = 'kolab';
        $class  = $driver . '_file_storage';

        require_once $driver . '/' . $class . '.php';

        $this->api = new $class;
    }

    /**
     * Process the request and dispatch it to the requested service
     */
    public function run()
    {
        $request = strtolower($_GET['method']);

        // Check the session, authenticate the user
        if (!$this->session_validate()) {
            @session_destroy();

            if ($request == 'authenticate') {
                session_start();

                if ($username = $this->authenticate()) {
                    $_SESSION['user']   = $username;
                    $_SESSION['time']   = time();
                    $_SESSION['config'] = $this->config;

                    $this->output_success(array('token' => session_id()));
                }
            }

            throw new Exception("Invalid session", 403);
        }

        // Call service method
        $result = $this->request($request);

        // Send success response, errors should be handled by driver class
        // by throwing exceptions or sending output by itself
        $this->output_success($result);
    }

    /**
     * Session validation check
     */
    private function session_validate()
    {
        $sess_id = rcube_utils::request_header('X-Session-Token') ?: $_REQUEST['token'];

        if (empty($sess_id)) {
            return false;
        }

        session_id($sess_id);
        session_start();

        if (empty($_SESSION['user'])) {
            return false;
        }

        // Session timeout
//        $timeout = $this->config->get('kolab_wap', 'session_timeout');
        $timeout = 3600;
        if ($timeout && $_SESSION['time'] && $_SESSION['time'] < time() - $timeout) {
            return false;
        }
        // update session time
        $_SESSION['time'] = time();

        return true;
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
            $this->api_init();
            $result = $this->api->authenticate($username, $password);
        }

        if (empty($result)) {
/*
            header('WWW-Authenticate: Basic realm="' . $this->app_name .'"');
            header('HTTP/1.1 401 Unauthorized');
            exit;
*/
            throw new Exception("Invalid password or username", file_api::ERROR_CODE);
        }

        return $username;
    }

    /**
     * Storage driver method caller
     */
    private function request($request)
    {
        if ($request == 'ping') {
            return array();
        }

        $this->api_init();

        switch ($request) {
            case 'file_list':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }

                $params = array('reverse' => !empty($_GET['reverse']) && rcube_utils::get_boolean($_GET['reverse']));
                if (!empty($_GET['sort'])) {
                    $params['sort'] = strtolower($_GET['sort']);
                }

                if (!empty($_GET['search'])) {
                    $params['search'] = $_GET['search'];
                    if (!is_array($params['search'])) {
                        $params['search'] = array('name' => $params['search']);
                    }
                }

                return $this->api->file_list($_GET['folder'], $params);

            case 'file_create':
                // for Opera upload frame response cannot be application/json
                $this->output_type = self::OUTPUT_HTML;

                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }

                $uploads = $this->upload();
                $result  = array();

                foreach ($uploads as $file) {
                    $this->api->file_create($_GET['folder'], $file['name'], $file);
                    unset($file['path']);
                    $result[$file['name']] = array(
                        'type' => $file['type'],
                        'size' => $file['size'],
                    );
                }

                return $result;

            case 'file_delete':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }

                $files = (array) $_GET['file'];

                if (empty($files)) {
                    throw new Exception("Missing file name", file_api::ERROR_CODE);
                }

                foreach ($files as $file) {
                    $this->api->file_delete($_GET['folder'], $file);
                }
                return;

            case 'file_info':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }

                if (!isset($_GET['file']) || $_GET['file'] === '') {
                    throw new Exception("Missing file name", file_api::ERROR_CODE);
                }

                return $this->api->file_info($_GET['folder'], $_GET['file']);

            case 'file_get':
                $this->output_type = self::OUTPUT_HTML;

                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    header("HTTP/1.0 ".file_api::ERROR_CODE." Missing folder name");
                }

                if (!isset($_GET['file']) || $_GET['file'] === '') {
                    header("HTTP/1.0 ".file_api::ERROR_CODE." Missing file name");
                }

                $params = array(
                    'force-download' => !empty($_GET['force-download']) && rcube_utils::get_boolean($_GET['force-download'])
                );

                try {
                    $this->api->file_get($_GET['folder'], $_GET['file'], $params);
                }
                catch (Exception $e) {
                    header("HTTP/1.0 ".file_api::ERROR_CODE." " . $e->getMessage());
                }
                exit;

            case 'file_rename':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }
                if (!isset($_GET['file']) || $_GET['file'] === '') {
                    throw new Exception("Missing file name", file_api::ERROR_CODE);
                }
                if (!isset($_GET['new']) || $_GET['new'] === '') {
                    throw new Exception("Missing new file name", file_api::ERROR_CODE);
                }
                if ($_GET['new'] === $_GET['file']) {
                    throw new Exception("Old and new file name is the same", file_api::ERROR_CODE);
                }

                return $this->api->file_rename($_GET['folder'], $_GET['file'], $_GET['new']);

            case 'folder_create':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }
                return $this->api->folder_create($_GET['folder']);

            case 'folder_delete':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing folder name", file_api::ERROR_CODE);
                }
                return $this->api->folder_delete($_GET['folder']);

            case 'folder_rename':
                if (!isset($_GET['folder']) || $_GET['folder'] === '') {
                    throw new Exception("Missing source folder name", file_api::ERROR_CODE);
                }
                if (!isset($_GET['new']) || $_GET['new'] === '') {
                    throw new Exception("Missing destination folder name", file_api::ERROR_CODE);
                }
                if ($_GET['new'] === $_GET['folder']) {
                    return;
                }
                return $this->api->folder_rename($_GET['folder'], $_GET['new']);

            case 'folder_list':
                return $this->api->folder_list();

            case 'quit':
                @session_destroy();
                return array();

            case 'configure':
                foreach (array_keys($this->config) as $name) {
                    if (isset($_GET[$name])) {
                        $this->config[$name] = $_GET[$name];
                    }
                }
                $_SESSION['config'] = $this->config;

                return $this->config;
        }

        if ($request) {
            throw new Exception("Unknown method", 501);
        }
    }

    /**
     * File uploads handler
     */
    protected function upload()
    {
        $files = array();

        if (is_array($_FILES['file']['tmp_name'])) {
            foreach ($_FILES['file']['tmp_name'] as $i => $filepath) {
                if ($err = $_FILES['file']['error'][$i]) {
                    if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
                        throw new Exception("Maximum file size exceeded", file_api::ERROR_CODE);
                    }
                    throw new Exception("File upload failed", file_api::ERROR_CODE);
                }

                $files[] = array(
                    'path' => $filepath,
                    'name' => $_FILES['file']['name'][$i],
                    'size' => filesize($filepath),
                    'type' => rcube_mime::file_content_type($filepath, $_FILES['file']['name'][$i], $_FILES['file']['type']),
                );
            }
        }
        else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            throw new Exception("File upload failed", file_api::ERROR_CODE);
        }

        return $files;
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

        $this->output_send(array('status' => 'OK', 'result' => $data));
    }

    /**
     * Send error response
     *
     * @param mixed $data Data
     */
    public function output_error($errdata, $code = null)
    {
        if (is_string($errdata)) {
            $errdata = array('reason' => $errdata);
        }

        if (!$code) {
            $code = file_api::ERROR_CODE;
        }

        $this->output_send(array('status' => 'ERROR', 'code' => $code) + $errdata);
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
}
