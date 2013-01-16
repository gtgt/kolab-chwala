<?php

/**
 * Kolab core library
 *
 * Plugin to setup a basic environment for the interaction with a Kolab server.
 * Other Kolab-related plugins will depend on it and can use the library classes
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class libkolab extends rcube_plugin
{
    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        // load local config
        $this->load_config();

        $this->add_hook('storage_init', array($this, 'storage_init'));

        // extend include path to load bundled lib classes
        $include_path = $this->home . '/lib' . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);

        $rcmail = rcube::get_instance();
        try {
            kolab_format::$timezone = new DateTimeZone($rcmail->config->get('timezone', 'GMT'));
        }
        catch (Exception $e) {
            rcube::raise_error($e, true);
            kolab_format::$timezone = new DateTimeZone('GMT');
        }
    }

    /**
     * Hook into IMAP FETCH HEADER.FIELDS command and request Kolab-specific headers
     */
    function storage_init($p)
    {
        $p['fetch_headers'] = trim($p['fetch_headers'] .' X-KOLAB-TYPE X-KOLAB-MIME-VERSION');
        return $p;
    }
}
