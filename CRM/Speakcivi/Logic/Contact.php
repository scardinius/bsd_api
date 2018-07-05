<?php

class CRM_Speakcivi_Logic_Contact {

  /**
   * Get email
   * 
   * @param $contactId
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  public static function getEmail($contactId) {
    $result = civicrm_api3('Contact', 'get', array(
      'sequential' => 1,
      'return' => ['email', 'on_hold'],
      'id' => $contactId,
    ));
    return $result['values'][0];
  }


  /**
   * Get contact id (or ids) by using Email API
   *
   * @param $email
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public static function getContactByEmail($email) {
    $ids = array();
    $params = array(
      'sequential' => 1,
      'is_primary' => 1,
      'email' => $email,
      'return' => "contact_id",
    );
    $result = civicrm_api3('Email', 'get', $params);
    if ($result['count'] > 0) {
      foreach ($result['values'] as $contact) {
        $ids[$contact['contact_id']] = $contact['contact_id'];
      }
    }
    return $ids;
  }

  /**
   * Clear on_hold status of given email
   */
  public static function unholdEmail($emailId) {
    $email = new CRM_Core_BAO_Email();
    $email->id = $emailId;
    $email->on_hold = FALSE;
    $email->hold_date = 'null';
    $email->reset_date = date('YmdHis');
    $email->save();
  }


  /**
   * Set up own created date. Column created_date is kind of timestamp and therefore It can't be set up during creating new contact.
   *
   * @param $contactId
   * @param $createdDate
   *
   * @return bool
   *
   */
  public static function setContactCreatedDate($contactId, $createdDate) {
    $query = "UPDATE civicrm_contact SET created_date = %2 WHERE id = %1";
    $params = array(
      1 => array($contactId, 'Integer'),
      2 => array($createdDate, 'String'),
    );
    CRM_Core_DAO::executeQuery($query, $params);
  }


  /**
   * Check If contact need send email confirmation
   *
   * @param $newContact
   * @param $contactId
   * @param $groupId
   * @param $isOptOut
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public static function isContactNeedConfirmation($newContact, $contactId, $groupId, $isOptOut) {
    if ($newContact || $isOptOut) {
      return true;
    } else {
      $params = array(
        'sequential' => 1,
        'contact_id' => $contactId,
        'group_id' => $groupId,
      );
      $result = civicrm_api3('GroupContact', 'get', $params);
      if ($result['count'] == 0) {
        return true;
      }
    }
    return false;
  }


  /**
   * Set source fields in custom fields to contact
   *
   * @param int $contactId
   * @param array $fields
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function setSourceFields($contactId, $fields) {
    $params = array();
    $fields = (array)$fields;
    if (array_key_exists('source', $fields) && $fields['source']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_contact_source')] = $fields['source'];
    }
    if (array_key_exists('medium', $fields) && $fields['medium']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_contact_medium')] = $fields['medium'];
    }
    if (array_key_exists('campaign', $fields) && $fields['campaign']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_contact_campaign')] = $fields['campaign'];
    }
    self::set($contactId, $params);
  }


  /**
   * Set contact params
   *
   * @param int $contactId
   * @param array $contactParams
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function set($contactId, $contactParams) {
    $params = array(
      'sequential' => 1,
      'id' => $contactId,
    );
    $params = array_merge($params, $contactParams);
    if (count($params) > 2) {
      civicrm_api3('Contact', 'create', $params);
    }
  }


  /**
   * Set all needed objects for new contact like groups, tag and join activity.
   *
   * @param int $contactId
   * @param int $campaignId
   */
  public static function setMembers($contactId, $campaignId = 0) {
    $groupId = CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'group_id');
    $params = array(
      'sequential' => 1,
      'id' => $contactId,
      'return' => 'preferred_language',
      'api.GroupContact.get' => array(
        'sequential' => 1,
        'group_id' => $groupId,
        'contact_id' => '$value.id',
        'status' => 'Added',
      ),
    );
    $result = civicrm_api3('Contact', 'get', $params);
    if ($result['values'][0]['api.GroupContact.get']['count'] == 0) {
      $language = substr($result['values'][0]['preferred_language'], 0, 2);
      $page = new CRM_Speakcivi_Page_Post();
      $page->setGroupContactAdded($contactId, $groupId);
      $page->setLanguageGroup($contactId, $language);
      $page->setLanguageTag($contactId, $language);
      CRM_Speakcivi_Logic_Activity::join($contactId, 'donation_page', $campaignId, 0);
    }
  }


  /**
   * Check if updating of contact if it's necessary.
   *
   * @param array $params Array of params for API contact
   *
   * @return bool
   */
  public static function needUpdate($params) {
    unset($params['sequential']);
    unset($params['contact_type']);
    unset($params['email']);
    unset($params['id']);
    return (bool)count($params);
  }


  /**
   * Check if contact is anonymous (without email).
   *
   * @param $param
   *
   * @return bool
   */
  public static function isAnonymous($param) {
    return !($param->cons_hash->emails[0]->email);
  }


  /**
   * Get anonymous contact.
   *
   * @return array
   */
  public static function getAnonymous() {
    $anonymousId = Civi::settings()->get('anonymous_id');
    $params = array(
      'sequential' => 1,
      'id' => $anonymousId,
    );
    $result = civicrm_api3('Contact', 'get', $params);
    return $result['values'][0];
  }

  /**
   * Set all GDPR temporary fields to NULL for the given contact
   */
  public static function emptyGDPRFields($contactId) {
    $contactParams = [
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_date') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_version') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_language') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_utm_source') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_utm_medium') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_utm_campaign') => '',
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_consent_campaign_id') => '',
    ];
    self::set($contactId, $contactParams);
  }
}
