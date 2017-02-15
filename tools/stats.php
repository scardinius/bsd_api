<?php

session_start();
$settingsFile = trim(implode('', file('path.inc'))).'/civicrm.settings.php';
define('CIVICRM_SETTINGS_PATH', $settingsFile);
define('CIVICRM_CLEANURL', 1);
$error = @include_once( $settingsFile );
if ( $error == false ) {
  echo "Could not load the settings file at: {$settingsFile}\n";
  exit( );
}

// Load class loader
global $civicrm_root;
require_once $civicrm_root . '/CRM/Core/ClassLoader.php';
CRM_Core_ClassLoader::singleton()->register();

require_once 'CRM/Core/Config.php';
require_once '../CRM/Speakcivi/Tools/Stat.php';
$config = CRM_Core_Config::singleton();

$filename = 'petition';
$data = CRM_Speakcivi_Tools_Stat::getCsv($filename);
$header = array(
  'start' => 'start',
  'after createContact()' => 'after createContact()',
  'after isContactNeedConfirmation()' => 'after isContactNeedConfirmation()',
  'after createActivity()' => 'after createActivity()',
  'after activity::setSourceFields()' => 'after activity::setSourceFields()',
  'after newContact' => 'after newContact',
  'after optIn 0' => 'after optIn 0',
  'after sendConfirm()' => 'after sendConfirm()',
);
$rows = CRM_Speakcivi_Tools_Stat::buildRowsFixed($data, $header);
echo count($rows) . "\n";
$calcs = CRM_Speakcivi_Tools_Stat::calculateFixed($header, $rows);
$calcs = CRM_Speakcivi_Tools_Stat::replaceDots($calcs);
echo count($calcs) . "\n\n";
CRM_Speakcivi_Tools_Stat::saveReportFixed($filename, array('SID' => 'SID') + $header, $calcs);
