<?php

if (!defined('CIVICRM_UF')) {
  if (getenv('CIVICRM_UF')) {
    define('CIVICRM_UF', getenv('CIVICRM_UF'));
  }
  else {
    define( 'CIVICRM_UF', 'Drupal');
  }
}

/**
 * Content Management System (CMS) Datasource:
 *
 * Update this setting with your CMS (Drupal or Joomla) database username, server and DB name.
 * Datasource (DSN) format:
 *      define( 'CIVICRM_UF_DSN', 'mysql://cms_db_username:cms_db_password@db_server/cms_database?new_link=true');
 */
if (!defined('CIVICRM_UF_DSN')) {
  define( 'CIVICRM_UF_DSN', 'mysql://root:root@localhost/drupal' );
}

/**
 * CiviCRM Database Settings
 *
 * Database URL (CIVICRM_DSN) for CiviCRM Data:
 * Database URL format:
 *      define( 'CIVICRM_DSN', 'mysql://crm_db_username:crm_db_password@db_server/crm_database?new_link=true');
 *
 */
if (!defined('CIVICRM_DSN')) {
  define( 'CIVICRM_DSN',  CIVICRM_UF_DSN);
}

/**
 * CiviCRM Logging Database
 *
 * Used to point to a different database to use for logging (if desired). If unset defaults to equal CIVICRM_DSN.
 * The CIVICRM_DSN user needs to have the rights to modify the below database schema and be able to write to it.
 */
if (!defined('CIVICRM_LOGGING_DSN')) {
  define('CIVICRM_LOGGING_DSN', CIVICRM_DSN);
}

/**
 * File System Paths:
 *
 * $civicrm_root is the file system path on your server where the civicrm
 * code is installed. Use an ABSOLUTE path (not a RELATIVE path) for this setting.
 *
 * CIVICRM_TEMPLATE_COMPILEDIR is the file system path where compiled templates are stored.
 * These sub-directories and files are temporary caches and will be recreated automatically
 * if deleted.
 *
 * IMPORTANT: The COMPILEDIR directory must exist,
 * and your web server must have read/write access to these directories.
 *
 * EXAMPLE - Drupal:
 * If the path to the Drupal home directory is /var/www/htdocs/drupal
 * the $civicrm_root setting would be:
 *      $civicrm_root = '/var/www/htdocs/drupal/sites/all/modules/civicrm/';
 *
 * the CIVICRM_TEMPLATE_COMPILEDIR would be:
 *      define( 'CIVICRM_TEMPLATE_COMPILEDIR', '/var/www/htdocs/drupal/sites/default/files/civicrm/templates_c/' );
 */

global $civicrm_root;

$civicrm_root = dirname(__DIR__) . '/all/modules/civicrm';
if (!defined('CIVICRM_TEMPLATE_COMPILEDIR')) {
  define( 'CIVICRM_TEMPLATE_COMPILEDIR', __DIR__ . '/files/civicrm/templates_c/' );
}

/**
 * Site URLs:
 *
 * This section defines absolute and relative URLs to access the host CMS (Drupal or Joomla) resources.
 *
 * IMPORTANT: Trailing slashes should be used on all URL settings.
 */
if (!defined('CIVICRM_UF_BASEURL')) {
  define( 'CIVICRM_UF_BASEURL'      , 'http://localhost/' );
}

/*
 * If you are using any CiviCRM script in the bin directory that
 * requires authentication, then you also need to set this key.
 * We recommend using a 16-32 bit alphanumeric/punctuation key.
 * More info at http://wiki.civicrm.org/confluence/display/CRMDOC/Command-line+Script+Configuration
 */
if (!defined('CIVICRM_SITE_KEY')) {
  define( 'CIVICRM_SITE_KEY', 'unit_test_key' );
}

/**
 * Enable this constant, if you want to send your email through the smarty
 * templating engine(allows you to do conditional and more complex logic)
 *
 */
define( 'CIVICRM_MAIL_SMARTY', 0 );

/**
 * This setting logs all emails to a file. Useful for debugging any mail (or civimail) issues.
 * Enabling this setting will not send any email, ensure this is commented out in production
 * The CIVICRM_MAIL_LOG is a debug option which disables MTA (mail transport agent) interaction.
 * You must disable CIVICRM_MAIL_LOG before CiviCRM will talk to your MTA.
 */
define('CIVICRM_MAIL_LOG', __DIR__ . '/files/mail.log');

if (!defined('CIVICRM_DOMAIN_ID')) {
  define( 'CIVICRM_DOMAIN_ID'      , 1 );
}

$include_path = '.'           . PATH_SEPARATOR .
                $civicrm_root . PATH_SEPARATOR .
                $civicrm_root . DIRECTORY_SEPARATOR . 'packages' . PATH_SEPARATOR .
                get_include_path( );
if ( set_include_path( $include_path ) === false ) {
   echo "Could not set the include path<p>";
   exit( );
}

if (!defined('CIVICRM_CLEANURL')) {
  if ( function_exists( 'variable_get' ) && variable_get('clean_url', '0') != '0' ) {
    define( 'CIVICRM_CLEANURL', 1 );
  } else {
    define( 'CIVICRM_CLEANURL', 0 );
  }
}

// force PHP to auto-detect Mac line endings
ini_set('auto_detect_line_endings', '1');

// make sure the memory_limit is at least 64 MB
$memLimitString = trim(ini_get('memory_limit'));
$memLimitUnit   = strtolower(substr($memLimitString, -1));
$memLimit       = (int) $memLimitString;
switch ($memLimitUnit) {
    case 'g': $memLimit *= 1024;
    case 'm': $memLimit *= 1024;
    case 'k': $memLimit *= 1024;
}
if ($memLimit >= 0 and $memLimit < 134217728) {
    ini_set('memory_limit', '128M');
}

require_once 'CRM/Core/ClassLoader.php';
CRM_Core_ClassLoader::singleton()->register();
