=== Admin's Debug Tool ===
Contributors: pantsonhead
Tags: widget, plugin, debug, admin, hooks, monitor, execution, timer, queries, WP_DEBUG
Requires at least: 3.0
Tested up to: 4.2.3
Stable tag: trunk

Admin-only tool for checking execution times and error output of current theme/plugins 

== Description ==

Admin's Debug Tool allows administrators to analyze page execution without executing/displaying for non-admin users. 
This can be useful when trying to track slow queries or badly performing plugins or widgets. 
The admin-only nature of this plugin can also be useful when trying to track issues that only occur on production servers.

== Installation ==

1. Upload `admins_debug_tool.php` to the `/wp-content/plugins/` directory of your WordPress installation
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Set the appropriate options on the Admin page in the Tools menu 


== Changelog ==

= v0.1 2015-08-08 =

* Fixed "undefined constant" error
* Tested to 4.2.3

= v0.0.2 2012-10-11 =

* Fixed small display / typo issues

= v0.0.1a 2012-10-11 =

* Initial release