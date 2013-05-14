<?php
/*
 +--------------------------------------------------------------------------+
 | This file is part of the Kolab File API                                  |
 |                                                                          |
 | Copyright (C) 2011-2013, Kolab Systems AG                                |
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
 * Class integrating text editor http://ajaxorg.github.io/ace
 */
class file_ui_viewer_text extends file_ui_viewer
{
    /**
     * Mimetype to tokenizer map
     *
     * @var array
     */
    protected $mimetypes = array(
        'text/plain' => 'text',
        'text/html' => 'html',
        'text/javascript' => 'javascript',
        'text/ecmascript' => 'javascript',
        'text/x-c' => 'c_cpp',
        'text/css' => 'css',
        'text/x-java-source' => 'java',
        'text/x-php' => 'php',
        'text/x-sh' => 'sh',
        'text/xml' => 'xml',
        'application/xml' => 'xml',
        'application/x-vbscript' => 'vbscript',
        'message/rfc822' => 'text',
    );


    /**
     * Returns list of supported mimetype
     *
     * @return array List of mimetypes
     */
    public function supported_mimetypes()
    {
        return array_keys($this->mimetypes);
    }

    /**
     * Check if mimetype is supported by the viewer
     *
     * @param string $mimetype File type
     *
     * @return bool
     */
    public function supports($mimetype)
    {
        return $this->mimetypes[$mimetype] || preg_match('/^text\//', $mimetype);
    }

    /**
     * Print file content
     */
    protected function print_file($file)
    {
        $observer = new file_viewer_request_observer;
        $request  = $this->ui->api->request();

        $request->attach($observer);
        $this->ui->api->get('file_get', array('file' => $file, 'force-type' => 'text/plain'));
        $request->detach($observer);
    }

    /**
     * Print output and exit
     *
     * @param string $file     File name
     * @param string $mimetype File type
     */
    public function output($file, $mimetype = null)
    {
        $mode = $this->mimetypes[$mimetype] ?: 'text';

        echo '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <title>Editor</title>
  <script src="viewers/text/ace.js" type="text/javascript" charset="utf-8"></script>
  <style>
    #editor { top: 0; right: 0; bottom: 0; left: 0; position: absolute; font-size: 14px; padding: 0; margin: 0; }
    .ace_search_options { float: right; }
  </style>
</head>
<body>
  <pre id="editor">';

        $this->print_file($file);

        echo "</pre>
  <script>
    var editor = ace.edit('editor'),
      session = editor.getSession();

    editor.focus();
    editor.setReadOnly(true);
    session.setMode('ace/mode/$mode');
  </script>
</body>
</html>";
    }
}


/**
 * Observer for HTTP_Request2 implementing file body printing
 * with HTML special characters "escaping" for use in HTML code
 */
class file_viewer_request_observer implements SplObserver
{
    public function update(SplSubject $subject)
    {
        $event = $subject->getLastEvent();

        switch ($event['name']) {
        case 'receivedHeaders':
        case 'receivedBody':
            break;

        case 'receivedBodyPart':
        case 'receivedEncodedBodyPart':
            echo htmlspecialchars($event['data'],  ENT_COMPAT | ENT_HTML401 | ENT_IGNORE);
            break;
        }
    }
}
