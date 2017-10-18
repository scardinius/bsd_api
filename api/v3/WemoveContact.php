<?php
function _civicrm_api3_wemove_contact_create_spec($params) {

  //  $params = array(
  //    'action_type',
  //    'action_technical_type',  // different than a speakout, not a link to fetch campaign
  //    'create_dt',
  //    'external_id',  // not a speakout campaign?
  //    'firstname',
  //    'lastname',
  //    'email',
  //    'zip',
  //    'country',
  //  );

}

function civicrm_api3_wemove_contact_create($params) {
  if (!$params['email']) {
    $result = CRM_Speakcivi_Logic_Contact::getAnonymous();
    return civicrm_api3_create_success($result, $params);
  }

  $param = (object) array(
    'action_type' => $params['action_type'],
    'external_id' => $params['external_id'],
    'cons_hash' => array(
      'firstname' => $params['firstname'],
      'lastname' => $params['lastname'],
      'addresses' => array(
        array(
          'zip' => $params['zip'],
          'country' => $params['contry'],
        ),
      ),
    ),
  );

  $speakcivi = new CRM_Speakcivi_Page_Speakcivi();
  $speakcivi->setDefaults();
  $speakcivi->setCountry($param);
  $speakcivi->campaignObj = new CRM_Speakcivi_Logic_Campaign();
  $speakcivi->campaignObj->campaign = CRM_Speakcivi_Logic_Cache_Campaign::getCampaignByExternalId($param);
  if ($speakcivi->campaignObj->isValidCampaign($speakcivi->campaignObj->campaign)) {
    $speakcivi->campaignId = (int) $speakcivi->campaignObj->campaign['id'];
    $speakcivi->locale = $speakcivi->campaignObj->getLanguage();
  }
  $groupId = $speakcivi->determineGroupId();

  $contact = array(
    'contact_type' => 'Individual',
    'email' => $params['email'],
    'api.Address.get' => array(
      'id' => '$value.address_id',
      'contact_id' => '$value.id',
    ),
    'api.GroupContact.get' => array(
      'group_id' => $groupId,
      'contact_id' => '$value.id',
      'status' => 'Added',
    ),
    'return' => 'id,email,first_name,last_name,preferred_language,is_opt_out',
  );

  $contacIds = CRM_Speakcivi_Logic_Contact::getContactByEmail($params['email']);
  if (is_array($contacIds) && count($contacIds) > 0) {
    $contactParam = $contact;
    $contactParam['id'] = array('IN' => array_keys($contacIds));
    unset($contactParam['email']); // getting by email (pseudoconstant) sometimes doesn't work
    $result = civicrm_api3('Contact', 'get', $contactParam);
    if ($result['count'] == 1) {
      $contact = $speakcivi->prepareParamsContact($param, $contact, $groupId, $result, $result['id']);
      if (!CRM_Speakcivi_Logic_Contact::needUpdate($contact)) {
        return civicrm_api3_create_success($result['values'][$result['id']], $params);
      }
    }
    elseif ($result['count'] > 1) {
      $lastname = $speakcivi->cleanLastname($params['lastname']);
      $newContact = $contact;
      $newContact['first_name'] = $params['firstname'];
      $newContact['last_name'] = $lastname;
      $similarity = $speakcivi->glueSimilarity($newContact, $result['values']);
      unset($newContact);
      $contactIdBest = $speakcivi->chooseBestContact($similarity);
      $contact = $speakcivi->prepareParamsContact($param, $contact, $groupId, $result, $contactIdBest);
      if (!CRM_Speakcivi_Logic_Contact::needUpdate($contact)) {
        return civicrm_api3_create_success($result['values'][$contactIdBest], $params);
      }
    }
  }
  else {
    $speakcivi->newContact = TRUE;
    $contact = $speakcivi->prepareParamsContact($param, $contact, $groupId);
  }

  $result = civicrm_api3('Contact', 'create', $contact);
  $contactId = $result['id'];
  $contactResult = $result['values'][$result['id']];

  $language = substr($speakcivi->locale, 0, 2);
  $pagePost = new CRM_Speakcivi_Page_Post();
  $rlg = $pagePost->setLanguageGroup($contactId, $language);
  $pagePost->setLanguageTag($contactId, $language);
  if ($speakcivi->addJoinActivity) {
    CRM_Speakcivi_Logic_Activity::join($contactId, 'donation', $speakcivi->campaignId);
  }
  if ($contactResult['preferred_language'] != $speakcivi->locale && $rlg == 1) {
    CRM_Speakcivi_Logic_Contact::set($contactId, array('preferred_language' => $speakcivi->locale));
  }

  return civicrm_api3_create_success($result['values'][$result['id']], $params);
}
