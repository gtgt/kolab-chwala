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

class file_viewer_odf
{
    protected $mimetypes = array(
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/vnd.oasis.opendocument.graphics',
        'application/vnd.oasis.opendocument.chart',
//        'application/vnd.oasis.opendocument.formula',
        'application/vnd.oasis.opendocument.image',
        'application/vnd.oasis.opendocument.text-master',
//        'application/vnd.sun.xml.base',
//        'application/vnd.oasis.opendocument.base',
//        'application/vnd.oasis.opendocument.database',
        'application/vnd.oasis.opendocument.text-template',
        'application/vnd.oasis.opendocument.spreadsheet-template',
        'application/vnd.oasis.opendocument.presentation-template',
        'application/vnd.oasis.opendocument.graphics-template',
        'application/vnd.oasis.opendocument.chart-template',
//        'application/vnd.oasis.opendocument.formula-template',
        'application/vnd.oasis.opendocument.image-template',
    );


    /**
     * Returns list of supported mimetype
     *
     * @return array List of mimetypes
     */
    public function supported_mimetypes()
    {
        // @TODO: check supported browsers
        return $this->mimetypes;
    }

    /**
     * Return output of file content area
     *
     * @param string $file_uri File URL
     * @param string $mimetype File type
     */
    public function frame($file_uri, $mimetype = null)
    {
        // we use iframe method, see output()
    }

    /**
     * Print output and exit
     *
     * @param string $file_uri File URL
     * @param string $mimetype File type
     */
    public function output($file_uri, $mimetype = null)
    {
        $file_uri = htmlentities($file_uri);

        echo <<<EOT
<html>
  <head>
    <link rel="stylesheet" type="text/css" href="viewers/odf/webodf.css" />
    <script type="text/javascript" src="viewers/odf/webodf.js" charset="utf-8"></script>
    <script type="text/javascript" charset="utf-8">
      function init() {
        var odfelement = document.getElementById("odf"),
          odfcanvas = new odf.OdfCanvas(odfelement);
        odfcanvas.load("$file_uri");
      }
      window.setTimeout(init, 0);
    </script>
  </head>
  <body>
    <div id="odf"></div>
  </body>
</html>
EOT;
    }
}
