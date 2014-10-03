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

require_once __DIR__ . "/common.php";

class file_api_lock_create extends file_api_common
{
    /**
     * Request handler
     */
    public function handle()
    {
        parent::handle();

        // arguments: uri, owner, timeout, scope, depth, token
        foreach (array('uri', 'token') as $arg) {
            if (!isset($this->args[$arg]) || $this->args[$arg] === '') {
                throw new Exception("Missing lock $arg", file_api::ERROR_CODE);
            }
        }

        list($driver, $uri) = $this->api->get_driver($this->args['uri']);

        $driver->lock($uri, $this->args);
    }
}
