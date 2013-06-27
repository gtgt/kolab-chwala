<?php

/**
 * Kolab Authentication (based on ldap_authentication plugin)
 *
 * Authenticates on LDAP server, finds canonized authentication ID for IMAP
 * and for new users creates identity based on LDAP information.
 *
 * Supports impersonate feature (login as another user). To use this feature
 * imap_auth_type/smtp_auth_type must be set to DIGEST-MD5 or PLAIN.
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_auth extends rcube_plugin
{
    static $ldap;
    private $data = array();

    public function init()
    {
        $rcmail = rcube::get_instance();

        $this->add_hook('authenticate', array($this, 'authenticate'));
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('user_create', array($this, 'user_create'));

        // Hooks related to "Login As" feature
        $this->add_hook('template_object_loginform', array($this, 'login_form'));
        $this->add_hook('storage_connect', array($this, 'imap_connect'));
        $this->add_hook('managesieve_connect', array($this, 'imap_connect'));
        $this->add_hook('smtp_connect', array($this, 'smtp_connect'));

        $this->add_hook('write_log', array($this, 'write_log'));

        // TODO: This section does not actually seem to work
        if ($rcmail->config->get('kolab_auth_auditlog', false)) {
            $rcmail->config->set('debug_level', 1);
            $rcmail->config->set('devel_mode', true);
            $rcmail->config->set('smtp_log', true);
            $rcmail->config->set('log_logins', true);
            $rcmail->config->set('log_session', true);
            $rcmail->config->set('sql_debug', true);
            $rcmail->config->set('memcache_debug', true);
            $rcmail->config->set('imap_debug', true);
            $rcmail->config->set('ldap_debug', true);
            $rcmail->config->set('smtp_debug', true);
        }

    }

    public function startup($args)
    {
        // Arguments are task / action, not interested
        if (!empty($_SESSION['user_roledns'])) {
            $this->load_user_role_plugins_and_settings($_SESSION['user_roledns']);
        }

        return $args;
    }

    public function load_user_role_plugins_and_settings($role_dns)
    {
        $rcmail = rcube::get_instance();
        $this->load_config();

        // Check role dependent plugins to enable and settings to modify

        // Example 'kolab_auth_role_plugins' =
        //
        //  Array(
        //      '<role_dn>' => Array('plugin1', 'plugin2'),
        //  );

        $role_plugins = $rcmail->config->get('kolab_auth_role_plugins');

        // Example $rcmail_config['kolab_auth_role_settings'] =
        //
        //  Array(
        //      '<role_dn>' => Array(
        //          '$setting' => Array(
        //              'mode' => '(override|merge)', (default: override)
        //              'value' => <>,
        //              'allow_override' => (true|false) (default: false)
        //          ),
        //      ),
        //  );

        $role_settings = $rcmail->config->get('kolab_auth_role_settings');

        foreach ($role_dns as $role_dn) {
            if (isset($role_plugins[$role_dn]) && is_array($role_plugins[$role_dn])) {
                foreach ($role_plugins[$role_dn] as $plugin) {
                    $this->require_plugin($plugin);
                }
            }

            if (isset($role_settings[$role_dn]) && is_array($role_settings[$role_dn])) {
                foreach ($role_settings[$role_dn] as $setting_name => $setting) {
                    if (!isset($setting['mode'])) {
                        $setting['mode'] = 'override';
                    }

                    if ($setting['mode'] == "override") {
                        $rcmail->config->set($setting_name, $setting['value']);
                    } elseif ($setting['mode'] == "merge") {
                        $orig_setting = $rcmail->config->get($setting_name);

                        if (!empty($orig_setting)) {
                            if (is_array($orig_setting)) {
                                $rcmail->config->set($setting_name, array_merge($orig_setting, $setting['value']));
                            }
                        } else {
                            $rcmail->config->set($setting_name, $setting['value']);
                        }
                    }

                    $dont_override = (array) $rcmail->config->get('dont_override');

                    if (!isset($setting['allow_override']) || !$setting['allow_override']) {
                        $rcmail->config->set('dont_override', array_merge($dont_override, array($setting_name)));
                    }
                    else {
                        if (in_array($setting_name, $dont_override)) {
                            $_dont_override = array();
                            foreach ($dont_override as $_setting) {
                                if ($_setting != $setting_name) {
                                    $_dont_override[] = $_setting;
                                }
                            }
                            $rcmail->config->set('dont_override', $_dont_override);
                        }
                    }
                }
            }
        }
    }

    public function write_log($args)
    {
        $rcmail = rcube::get_instance();

        if (!$rcmail->config->get('kolab_auth_auditlog', false)) {
            return $args;
        }

        $args['abort'] = true;

        if ($rcmail->config->get('log_driver') == 'syslog') {
            $prio = $args['name'] == 'errors' ? LOG_ERR : LOG_INFO;
            syslog($prio, $args['line']);
            return $args;
        }
        else {
            $line = sprintf("[%s]: %s\n", $args['date'], $args['line']);

            // log_driver == 'file' is assumed here
            $log_dir  = $rcmail->config->get('log_dir', INSTALL_PATH . 'logs');
            $log_path = $log_dir.'/'.strtolower($_SESSION['kolab_auth_admin']).'/'.strtolower($_SESSION['username']);

            // Append original username + target username
            if (!is_dir($log_path)) {
                // Attempt to create the directory
                if (@mkdir($log_path, 0750, true)) {
                    $log_dir = $log_path;
                }
            }
            else {
                $log_dir = $log_path;
            }

            // try to open specific log file for writing
            $logfile = $log_dir.'/'.$args['name'];

            if ($fp = fopen($logfile, 'a')) {
                fwrite($fp, $line);
                fflush($fp);
                fclose($fp);
                return $args;
            }
            else {
                trigger_error("Error writing to log file $logfile; Please check permissions", E_USER_WARNING);
            }
        }

        return $args;
    }

    /**
     * Sets defaults for new user.
     */
    public function user_create($args)
    {
        if (!empty($this->data['user_email'])) {
            // addresses list is supported
            if (array_key_exists('email_list', $args)) {
                $email_list = array_unique($this->data['user_email']);

                // add organization to the list
                if (!empty($this->data['user_organization'])) {
                    foreach ($email_list as $idx => $email) {
                        $email_list[$idx] = array(
                            'organization' => $this->data['user_organization'],
                            'email'        => $email,
                        );
                    }
                }

                $args['email_list'] = $email_list;
            }
            else {
                $args['user_email'] = $this->data['user_email'][0];
            }
        }

        if (!empty($this->data['user_name'])) {
            $args['user_name'] = $this->data['user_name'];
        }

        return $args;
    }

    /**
     * Modifies login form adding additional "Login As" field
     */
    public function login_form($args)
    {
        $this->load_config();
        $this->add_texts('localization/');

        $rcmail      = rcube::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $group       = $rcmail->config->get('kolab_auth_group');
        $role_attr   = $rcmail->config->get('kolab_auth_role');

        // Show "Login As" input
        if (empty($admin_login) || (empty($group) && empty($role_attr))) {
            return $args;
        }

        $input = new html_inputfield(array('name' => '_loginas', 'id' => 'rcmloginas',
            'type' => 'text', 'autocomplete' => 'off'));
        $row = html::tag('tr', null,
            html::tag('td', 'title', html::label('rcmloginas', Q($this->gettext('loginas'))))
            . html::tag('td', 'input', $input->show(trim(rcube_utils::get_input_value('_loginas', rcube_utils::INPUT_POST))))
        );
        $args['content'] = preg_replace('/<\/tbody>/i', $row . '</tbody>', $args['content']);

        return $args;
    }

    /**
     * Find user credentials In LDAP.
     */
    public function authenticate($args)
    {
        // get username and host
        $host    = $args['host'];
        $user    = $args['user'];
        $pass    = $args['pass'];
        $loginas = trim(rcube_utils::get_input_value('_loginas', rcube_utils::INPUT_POST));

        if (empty($user) || empty($pass)) {
            $args['abort'] = true;
            return $args;
        }

        $ldap = self::ldap();
        if (!$ldap || !$ldap->ready) {
            $args['abort'] = true;
            return $args;
        }

        // Find user record in LDAP
        $record = $ldap->get_user_record($user, $host);

        if (empty($record)) {
            $args['abort'] = true;
            return $args;
        }

        $rcmail      = rcube::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $admin_pass  = $rcmail->config->get('kolab_auth_admin_password');
        $login_attr  = $rcmail->config->get('kolab_auth_login');
        $name_attr   = $rcmail->config->get('kolab_auth_name');
        $email_attr  = $rcmail->config->get('kolab_auth_email');
        $org_attr    = $rcmail->config->get('kolab_auth_organization');
        $role_attr   = $rcmail->config->get('kolab_auth_role');

        if (!empty($role_attr) && !empty($record[$role_attr])) {
            $_SESSION['user_roledns'] = (array)($record[$role_attr]);
        }

        // Login As...
        if (!empty($loginas) && $admin_login) {
            // Authenticate to LDAP
            $result = $ldap->bind($record['dn'], $pass);

            if (!$result) {
                $args['abort'] = true;
                return $args;
            }

            // check if the original user has/belongs to administrative role/group
            $isadmin = false;
            $group   = $rcmail->config->get('kolab_auth_group');
            $role_dn = $rcmail->config->get('kolab_auth_role_value');

            // check role attribute
            if (!empty($role_attr) && !empty($role_dn) && !empty($record[$role_attr])) {
                $role_dn = $ldap->parse_vars($role_dn, $user, $host);
                if (in_array($role_dn, (array)$record[$role_attr])) {
                    $isadmin = true;
                }
            }

            // check group
            if (!$isadmin && !empty($group)) {
                $groups = $ldap->get_user_groups($record['dn'], $user, $host);
                if (in_array($group, $groups)) {
                    $isadmin = true;
                }
            }

            // Save original user login for log (see below)
            if ($login_attr) {
                $origname = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
            }
            else {
                $origname = $user;
            }

            $record = null;

            // user has the privilage, get "login as" user credentials
            if ($isadmin) {
                $record = $ldap->get_user_record($loginas, $host);
            }

            if (empty($record)) {
                $args['abort'] = true;
                return $args;
            }

            $args['user'] = $loginas;

            // Mark session to use SASL proxy for IMAP authentication
            $_SESSION['kolab_auth_admin']    = strtolower($origname);
            $_SESSION['kolab_auth_login']    = $rcmail->encrypt($admin_login);
            $_SESSION['kolab_auth_password'] = $rcmail->encrypt($admin_pass);
        }

        // Store UID and DN of logged user in session for use by other plugins
        $_SESSION['kolab_uid'] = is_array($record['uid']) ? $record['uid'][0] : $record['uid'];
        $_SESSION['kolab_dn']  = $record['dn'];

        // Set user login
        if ($login_attr) {
            $this->data['user_login'] = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
        }
        if ($this->data['user_login']) {
            $args['user'] = $this->data['user_login'];
        }

        // User name for identity (first log in)
        foreach ((array)$name_attr as $field) {
            $name = is_array($record[$field]) ? $record[$field][0] : $record[$field];
            if (!empty($name)) {
                $this->data['user_name'] = $name;
                break;
            }
        }
        // User email(s) for identity (first log in)
        foreach ((array)$email_attr as $field) {
            $email = is_array($record[$field]) ? array_filter($record[$field]) : $record[$field];
            if (!empty($email)) {
                $this->data['user_email'] = array_merge((array)$this->data['user_email'], (array)$email);
            }
        }
        // Organization name for identity (first log in)
        foreach ((array)$org_attr as $field) {
            $organization = is_array($record[$field]) ? $record[$field][0] : $record[$field];
            if (!empty($organization)) {
                $this->data['user_organization'] = $organization;
                break;
            }
        }

        // Log "Login As" usage
        if (!empty($origname)) {
            rcube::write_log('userlogins', sprintf('Admin login for %s by %s from %s',
                $args['user'], $origname, rcube_utils::remote_ip()));
        }

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for IMAP and Managesieve auth
     */
    public function imap_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcube::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['auth_cid'] = $admin_login;
            $args['auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for SMTP auth
     */
    public function smtp_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcube::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['options']['smtp_auth_cid'] = $admin_login;
            $args['options']['smtp_auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Initializes LDAP object and connects to LDAP server
     */
    public static function ldap()
    {
        if (self::$ldap) {
            return self::$ldap;
        }

        $rcmail = rcube::get_instance();

        // $this->load_config();
        // we're in static method, load config manually
        $fpath = $rcmail->plugins->dir . '/kolab_auth/config.inc.php';
        if (is_file($fpath) && !$rcmail->config->load_from_file($fpath)) {
            rcube::raise_error(array(
                'code' => 527, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Failed to load config from $fpath"), true, false);
        }

        $addressbook = $rcmail->config->get('kolab_auth_addressbook');

        if (!is_array($addressbook)) {
            $ldap_config = (array)$rcmail->config->get('ldap_public');
            $addressbook = $ldap_config[$addressbook];
        }

        if (empty($addressbook)) {
            return null;
        }

        require_once __DIR__ . '/kolab_auth_ldap.php';

        self::$ldap = new kolab_auth_ldap($addressbook);

        return self::$ldap;
    }
}
