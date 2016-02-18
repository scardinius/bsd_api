<?php

require_once 'speakcivi.civix.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function speakcivi_civicrm_config(&$config) {
  _speakcivi_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function speakcivi_civicrm_xmlMenu(&$files) {
  _speakcivi_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function speakcivi_civicrm_install() {
  _speakcivi_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function speakcivi_civicrm_uninstall() {
  _speakcivi_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function speakcivi_civicrm_enable() {
  _speakcivi_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function speakcivi_civicrm_disable() {
  _speakcivi_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function speakcivi_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _speakcivi_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function speakcivi_civicrm_managed(&$entities) {
  _speakcivi_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function speakcivi_civicrm_caseTypes(&$caseTypes) {
  _speakcivi_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function speakcivi_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _speakcivi_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implementation of hook_civicrm_tokens
 *
 * @param $tokens
 */
function speakcivi_civicrm_tokens(&$tokens) {
  $tokens['speakcivi'] = array(
    'speakcivi.confirmation_hash' => 'Confirmation hash',
  );
}

/**
 * Implementation of hook_civicrm_tokenValues
 *
 * @param $values
 * @param $cids
 * @param null $job
 * @param array $tokens
 * @param null $context
 */
function speakcivi_civicrm_tokenValues(&$values, $cids, $job = null, $tokens = array(), $context = null) {
  foreach ($cids as $cid) {
    $values[$cid]['speakcivi.confirmation_hash'] = sha1(CIVICRM_SITE_KEY . $cid);
  }
}


function speakcivi_civicrm_alterMailParams(&$params, $context) {
  CRM_Core_Error::debug_var('"alterMailParams"', "alterMailParams", false, true);
  CRM_Core_Error::debug_var('$context', $context, false, true);
  CRM_Core_Error::debug_var('$params[ams]', $params['ams'], false, true);
  // when Send test then:
  // $context = 'civimail';
  // $params['job_id'] -> civicrm_mailing_job.id

  // when Send an Email then:
  // $context = '';
  // $params['job_id'] - this keys doesn't exist
  if (array_key_exists('ams', $params) && $params['ams']) {
    $session = CRM_Core_Session::singleton();
    $session->set('ams', $params['ams'], 'ams');
  }
}


/**
 * Implements hook_civicrm_alterMailer().
 *
 * @param $mailer
 * @param $driver
 * @param $params
 */
function speakcivi_civicrm_alterMailer(&$mailer, $driver, $params) {
  CRM_Core_Error::debug_var('"alterMailer"', "alterMailer", false, true);
  $session = CRM_Core_Session::singleton();
  $ams = $session->get('ams', 'ams');
  CRM_Core_Error::debug_var('$ams', $ams, false, true);
}
