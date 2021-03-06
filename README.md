INSTALLATION PROCEDURE
======================

This package contains required PHP libraries as well as the Roundcube Framework
placed in lib/ext directory. In case you're using a package version
with lib/ext directory empty make sure all dependencies are installed
on your system. These are Roundcube Framework and its dependencies plus
PEAR::HTTP_Request2 and PEAR::Net_URL2 packages. Additionally Smarty v3 need
to be installed. Webdav driver requires SabreDAV library.

1. Create local config

The configuration for this service inherits basic options from the Roundcube
config. To make that available, symlink the Roundcube config file
(config.inc.php) into the local config/ directory.

2. Give write access for the webserver user to the logs, cache and temp folders:

$ chown <www-user> logs
$ chown <www-user> cache
$ chown <www-user> temp

3. Execute database initialization scripts from doc/SQL/ on Roundcube database.

4. Optionally, configure your webserver to point to the 'public_html' directory of this
package as document root.


SabreDAV INSTALLATION
=====================

cd lib/ext
wget https://github.com/fruux/sabre-dav/releases/download/2.1.6/sabredav-2.1.6.zip
unzip sabredav-2.1.6.zip
rm -f sabredav-2.1.6.zip


CREATING BACKEND-DRIVER
=======================

Chwala API supports creation of different storage backends.
It is possible to create a driver class that will store files on
any storage e.g. local filesystem. As for now it is possible to use
only one storage driver at a time.

There are currently two drivers available for Chwala: Kolab and Seafile.
The Kolab driver is considered the reference driver. Both can be found
in the lib/drivers directory.

The Kolab driver is based on Roundcube Framework and implements storage
the "Kolab way", which is to store files in IMAP. The main file is
lib/drivers/kolab/kolab_file_storage.php.

To create a new driver for a different storage system you need to:

1. Create driver directory as lib/drivers/<driver_name>. This directory will be
   added to PHP's include path.

2. Create lib/drivers/<driver_name>/<driver_name>_file_storage.php file.
   This file should define a class <driver_name>_file_storage which
   implements the file_storage interface as defined in lib/file_storage.php.

3. To change the driver set 'fileapi_backend' option to the driver name
   in main configuration file. The default is 'kolab'.


Driver initialization
---------------------

Driver object is initialized in file_api::api_init() method.
After the object instance is created we call configure() method.


Driver methods
--------------

1. configure - Is used to configure the driver.

2. authenticate - Is used to authenticate a user in authenticate
   request.

3. capabilities - Is supposed to return capabilities and limitations
   (like max. upload size) supported by the driver.

Other methods are self explanatory and well documented in
interface class file. API documentation can be generated
using phpDocumentor (http://phpdoc.org).
