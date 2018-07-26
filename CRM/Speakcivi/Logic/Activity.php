<?php

class CRM_Speakcivi_Logic_Activity {


  /**
   * Get activity status id. If status isn't exist, create it.
   *
   * @param string $activityStatus Internal name of status
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  public static function getStatusId($activityStatus) {
    $params = array(
      'sequential' => 1,
      'option_group_id' => 'activity_status',
      'name' => $activityStatus,
    );
    $result = civicrm_api3('OptionValue', 'get', $params);
    if ($result['count'] == 0) {
      $params['is_active'] = 1;
      $result = civicrm_api3('OptionValue', 'create', $params);
    }
    return (int)$result['values'][0]['value'];
  }


  /**
   * Create activity for contact.
   *
   * @param $contactId
   * @param $typeId
   * @param $subject
   * @param $campaignId
   * @param $parentActivityId
   * @param $activity_date_time
   * @param $location
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private static function createActivity($contactId, $typeId, $subject = '', $campaignId = 0, $parentActivityId = 0, $activity_date_time = '', $location = '') {
    $params = array(
      'sequential' => 1,
      'activity_type_id' => $typeId,
      'activity_date_time' => date('Y-m-d H:i:s'),
      'status_id' => 'Completed',
      'subject' => $subject,
      'source_contact_id' => $contactId,
    );
    if ($campaignId) {
      $params['campaign_id'] = $campaignId;
    }
    if ($parentActivityId) {
      $params['parent_id'] = $parentActivityId;
    }
    if ($activity_date_time) {
      $params['activity_date_time'] = $activity_date_time;
    }
    if ($location) {
      $params['location'] = $location;
    }
    return civicrm_api3('Activity', 'create', $params);
  }

  /**
   * Create new Data Policy Acceptance activity for contact
   *
   * @param \CRM_Speakcivi_Logic_Consent $consent
   * @param int $contactId
   * @param int $campaignId
   * @param string $activityStatus
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public static function dpa(CRM_Speakcivi_Logic_Consent $consent, $contactId, $campaignId, $activityStatus = 'Completed') {
    $activityTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'SLA Acceptance');
    $activityStatusId = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'status_id', $activityStatus);
    $params = [
      'sequential' => 1,
      'source_contact_id' => $contactId,
      'campaign_id' => $campaignId,
      'activity_type_id' => $activityTypeId,
      'activity_date_time' => $consent->createDate,
      'subject' => $consent->version,
      'location' => $consent->language,
      'status_id' => $activityStatusId,
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_source') => $consent->utmSource,
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_medium') => $consent->utmMedium,
      CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_campaign') => $consent->utmCampaign,
    ];
    return self::setActivity($params, ['activity_date_time']);
  }

  /**
   * Returns the latest Data Policy Acceptance activity of the given contact if any, NULL otherwise
   */
  public static function getLatestDPA($contactId) {
    $activityTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'SLA Acceptance');
    $apiParams = [
      'sequential' => 1,
      'source_contact_id' => $contactId,
      'activity_type_id' => $activityTypeId,
      'options' => ['limit' => 1, 'sort' => "activity_date_time DESC"],
    ];
    $result = civicrm_api3('Activity', 'get', $apiParams);
    $activity = NULL;
    if ($result['count']) {
      $activity = $result['values'][0];
    }
    return $activity;
  }

  /**
   * Find the latest DPA activity associated to the given contact, and set its status to 'Cancelled'.
   * If there is no such activity, create one with subject 'unknkown'.
   */
  public static function cancelLatestDPA($contactId) {
    $cancelledStatus = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'status_id', 'Cancelled');
    $dpa = self::getLatestDPA($contactId);
    if ($dpa == NULL) {
      //We create a recognizable activity directly with Cancelled status, to make sure we record this event
      $consent = new CRM_Speakcivi_Logic_Consent();
      $consent->version = 'unknown';
      $consent->language = 'unknown';
      $consent->createDate = date('Y-m-d H:i:s');
      self::dpa($consent, $contactId, NULL, 'Cancelled');
      CRM_Core_Error::debug_log_message("I will create a Cancelled DPA activity for $contactId");
    }
    else if ($dpa['status_id'] != $cancelledStatus) {
      //Let's not spend DB resources if the activity is already cancelled, but let's log it
      civicrm_api3('Activity', 'create', ['id' => $dpa['id'], 'status_id' => $cancelledStatus]);
    }
    else {
      CRM_Core_Error::debug_log_message("$contactId is leaving, but the latest DPA activity {$dpa['id']} is already cancelled.");
    }
  }

  /**
   * Set unique activity. Method gets activity by given params and creates only if needed.
   * Need more performance but provide database consistency.
   *
   * @param array $params
   * @param array $ignore parameters to ignore for de-duplication (default value for backward compatibility)
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public static function setActivity($params, $ignore = ['status_id', 'campaign_id']) {
    $contactId = $params['source_contact_id'];
    $getParams = $params;
    foreach ($ignore as $pname) {
      unset($getParams[$pname]);
    }
    $result = civicrm_api3('Activity', 'get', $getParams);

    if ($result['count'] == 0) {
      return civicrm_api3('Activity', 'create', $params);
    } elseif ($result['count'] == 1) {
      return $result;
    } else {
      $activities = $result['values'];
      //Sort by descending date
      usort($activities, function($a1, $a2) { 
        return -strcmp($a1['activity_date_time'], $a2['activity_date_time']); 
      });

      return array(
        'count' => 1,
        'id' => $activities[0]['id'],
        'values' => array(0 => $activities[0]),
      );
    }
  }


  /**
   * Add Join activity to contact
   *
   * @param $contactId
   * @param $subject
   * @param $campaignId
   * @param $parentActivityId
   *
   * @return int
   */
  public static function join($contactId, $subject = '', $campaignId = 0, $parentActivityId = 0) {
    $activityTypeId = CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'activity_type_join');
    $result = self::createActivity($contactId, $activityTypeId, $subject, $campaignId, $parentActivityId);
    return (int) $result['id'];
  }

  /**
   * Add Leave activity to contact
   *
   * @param $contactId
   * @param $subject
   * @param $campaignId
   * @param $parentActivityId
   * @param $activity_date_time
   * @param $location
   */
  public static function leave($contactId, $subject = '', $campaignId = 0, $parentActivityId = 0, 
                               $activity_date_time = '', $location = '') {

    $activityTypeId = CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'activity_type_leave');
    self::createActivity($contactId, $activityTypeId, $subject, $campaignId, 
                         $parentActivityId, $activity_date_time, $location);
    self::cancelLatestDPA($contactId);
    CRM_Speakcivi_Logic_Contact::emptyGDPRFields($contactId);
  }

  /**
   * Set source fields in custom fields
   *
   * @param $activityId
   * @param $fields
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function setSourceFields($activityId, $fields) {
    $params = array(
      'sequential' => 1,
      'id' => $activityId,
    );
    $fields = (array)$fields;
    if (array_key_exists('source', $fields) && $fields['source']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_source')] = $fields['source'];
    }
    if (array_key_exists('medium', $fields) && $fields['medium']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_medium')] = $fields['medium'];
    }
    if (array_key_exists('campaign', $fields) && $fields['campaign']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_activity_campaign')] = $fields['campaign'];
    }
    if (count($params) > 2) {
      civicrm_api3('Activity', 'create', $params);
    }
  }


  /**
   * Set share fields in custom fields (medium and tracking code)
   *
   * @param $activityId
   * @param $fields
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function setShareFields($activityId, $fields) {
    $params = array(
      'sequential' => 1,
      'id' => $activityId,
    );
    $fields = (array)$fields;
    if (array_key_exists('source', $fields) && $fields['source']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_tracking_codes_source')] = $fields['source'];
    }
    if (array_key_exists('medium', $fields) && $fields['medium']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_tracking_codes_medium')] = $fields['medium'];
    }
    if (array_key_exists('campaign', $fields) && $fields['campaign']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_tracking_codes_campaign')] = $fields['campaign'];
    }
    if (array_key_exists('content', $fields) && $fields['content']) {
      $params[CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'field_tracking_codes_content')] = $fields['content'];
    }
    if (count($params) > 2) {
      civicrm_api3('Activity', 'create', $params);
    }
  }


  /**
   * Check If activity has own Join activity
   *
   * @param $activityId
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public static function hasJoin($activityId) {
    $activityTypeId = CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'activity_type_join');
    $params = array(
      'sequential' => 1,
      'activity_type_id' => $activityTypeId,
      'parent_id' => $activityId,
    );
    return (bool)civicrm_api3('Activity', 'getcount', $params);
  }
}
