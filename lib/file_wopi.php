<?php
/**
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2012-2016, Kolab Systems AG                                |
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
 * Document editing sessions handling (WOPI)
 */
class file_wopi extends file_document
{
    /**
     * Return viewer URI for specified file/session. This creates
     * a new collaborative editing session when needed.
     *
     * @param string $file        File path
     * @param string &$session_id Optional session ID to join to
     *
     * @return string WOPI URI for specified document
     * @throws Exception
     */
    public function session_start($file, &$session_id = null)
    {
        parent::session_start($file, $session_id);

        if ($session_id) {
            // Create Chwala session for use as WOPI access_token
            // This session will have access to this one document session only
            $keys = array('language', 'user_id', 'user', 'username', 'password',
                'storage_host', 'storage_port', 'storage_ssl');

            $data = array_intersect_key($_SESSION, array_flip($keys));
            $data['document_session'] = $session_id;
            $token = $this->api->session->create($data);
rcube::console('-----' . $token);
rcube::console($data);
        }

        return $this->frame_uri($session_id, $token);
    }

    /**
     * Generate URI of WOPI editing session (WOPIsrc)
     */
    protected function frame_uri($id, $token)
    {
        $office_url  = rtrim($this->rc->config->get('fileapi_wopi_office'), ' /');  // Collabora
        $service_url = rtrim($this->rc->config->get('fileapi_wopi_service'), ' /'); // kolab-wopi

        // https://wopi.readthedocs.io/en/latest/discovery.html#action-urls
        // example urlsrc="https://office.example.org/loleaflet/1.8.3/loleaflet.html?"
        // example WOPIsrc="https://office.example.org:4000/wopi/files/$id"

        // @TODO: Parsing and replacing placeholder values

        // @TODO: passing access_token to the client
        // http://wopi.readthedocs.io/en/latest/hostpage.html?highlight=token

        // @TODO: access_token_ttl

        $service_url .= '/wopi/files/' . $id;

        $params = array(
            'file_path'    => $service_url,
            'access_token' => $token,
        );

        return $office_url . '?' . http_build_query($params);
    }

    public function supported_filetypes()
    {
        // @TODO: Use WOPI discovery to get the list of supported
        //   filetypes and urlsrc attrbutes
        //   this should probably be cached
        // https://wopi.readthedocs.io/en/latest/discovery.html
    }
}
