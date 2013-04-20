INTALLATION PROCEDURE
=====================

This package contains required PHP libraries as well as the Roundcube Framework
placed in lib/ext directory. In case you're using a package version
with lib/ext directory empty make sure all dependencies are installed
on your system. These are Roundcube Framework and its dependencies plus
PEAR::HTTP_Request2 and PEAR::Net_URL2 packages. Additionally Smarty v3 need
to be installed.

1. Create local config

The configuration for this service inherits basic options from the Roundcube
config. To make that available, symlink the Roundcube config files
(main.inc.php and db.inc.php) into the local config/ directory.

2. Give write access for the webserver user to the logs, cache and temp folders:

$ chown <www-user> logs
$ chown <www-user> cache
$ chown <www-user> temp

3. Optionally, configure your webserver to point to the 'public_html' directory of this
package as document root.
