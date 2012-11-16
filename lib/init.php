<?php

/**
 +--------------------------------------------------------------------------+
 | Kolab File API                                                           |
 |                                                                          |
 | Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>         |
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

// Roundcube Framework constants
define('RCMAIL_START', microtime(true));
define('RCMAIL_VERSION', '0.9-git');
define('RCMAIL_CHARSET', 'UTF-8');
define('INSTALL_PATH', realpath(dirname(__FILE__)) . '/');
define('RCMAIL_CONFIG_DIR', INSTALL_PATH . '../config');

// PHP configuration
$config = array(
    'error_reporting'         => E_ALL &~ (E_NOTICE | E_STRICT),
    'mbstring.func_overload'  => 0,
//    'suhosin.session.encrypt' => 0,
    'session.auto_start'      => 0,
    'file_uploads'            => 1,
    'magic_quotes_runtime'    => 0,
    'magic_quotes_sybase'     => 0,
);
foreach ($config as $optname => $optval) {
    if ($optval != ini_get($optname) && @ini_set($optname, $optval) === false) {
        die("ERROR: Wrong '$optname' option value!");
    }
}

// Define include path
$include_path  = INSTALL_PATH . PATH_SEPARATOR;
$include_path .= INSTALL_PATH . '/client' . PATH_SEPARATOR;
$include_path .= INSTALL_PATH . '/ext' . PATH_SEPARATOR;
$include_path .= ini_get('include_path');
set_include_path($include_path);

// @TODO: what is a reasonable value for File API?
//@set_time_limit(600);

// set internal encoding for mbstring extension
if (extension_loaded('mbstring')) {
    mb_internal_encoding(RCMAIL_CHARSET);
    @mb_regex_encoding(RCMAIL_CHARSET);
}

// include global functions from Roundcube Framework
require_once 'Roundcube/rcube_shared.inc';

// Register main autoloader
spl_autoload_register('kolab_sync_autoload');

// set PEAR error handling (will also load the PEAR main class)
PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'rcube_pear_error');

// Autoloader for Syncroton
//require_once 'Zend/Loader/Autoloader.php';
//$autoloader = Zend_Loader_Autoloader::getInstance();
//$autoloader->setFallbackAutoloader(true);

/**
 * Use PHP5 autoload for dynamic class loading
 */
function kolab_sync_autoload($classname)
{
    // Roundcube Framework
    $filename = preg_replace(
        array(
            '/Mail_(.+)/',
            '/Net_(.+)/',
            '/Auth_(.+)/',
            '/^html_.+/',
            '/^rcube(.*)/',
            '/^utf8$/',
        ),
        array(
            'Mail/\\1',
            'Net/\\1',
            'Auth/\\1',
            'Roundcube/html',
            'Roundcube/rcube\\1',
            'utf8.class',
        ),
        $classname
    );

    if ($fp = @fopen("$filename.php", 'r', true)) {
        fclose($fp);
        include_once "$filename.php";
        return true;
    }

    // Syncroton, replacement for Zend autoloader
    $filename = str_replace('_', DIRECTORY_SEPARATOR, $classname);

    if ($fp = @fopen("$filename.php", 'r', true)) {
        fclose($fp);
        include_once "$filename.php";
        return true;
    }

    return false;
}
