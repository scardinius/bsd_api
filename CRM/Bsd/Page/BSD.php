<?php

require_once 'CRM/Core/Page.php';

class CRM_Bsd_Page_BSD extends CRM_Core_Page {

  // TODO Lookup
  public $groupId = 42;

  public $campaign = array();

  public $campaignId = 0;

  public $fieldTemplateId = 'custom_3';

  public $fieldLanguage = 'custom_4';

  public $customFields = array();

  public $new_contact = false;

  function run() {

    $param = json_decode(file_get_contents('php://input'));


    if (!$param) {
      die ("missing POST PARAM");
    }

    $this->campaign = $this->getCampaign($param->external_id);
    if ($this->isValidCampaign($this->campaign)) {
      $this->campaignId = $this->campaign['id'];
    } else {
      header('HTTP/1.1 503 Men at work');
      return;
    }

    switch ($param->action_type) {
      case 'petition':
        $this->petition($param);
        break;

      case 'share':
        $this->share($param);
        break;

      default:
        CRM_Core_Error::debug_var('BSD API, Unsupported Action Type', $param->action_type, false, true);
    }

  }


  public function petition($param) {

    $contact = $this->createContact($param);
    if ($this->new_contact) {
      $this->setContactCreatedDate($contact['id'], $param->create_dt);
    }
    $activity = $this->createActivity($param, $contact['id'], 'Petition', 'Scheduled');

    if ($this->checkIfConfirm($param->external_id)) {
      $this->customFields = $this->getCustomFields($this->campaignId);
      CRM_Core_Error::debug_var('$this->customFields', $this->customFields, false, true);
      $h = $param->cons_hash;
      $this->sendConfirm($contact, $h->emails[0]->email, $activity['id']);
    }

  }


  public function share($param) {

    $contact = $this->createContact($param);
    $activity = $this->createActivity($param, $contact['id'], 'share', 'Completed');

  }


  /**
   * Create or update contact
   *
   * @param $param
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function createContact($param) {
    $h = $param->cons_hash;

    $apiAddressGet = 'api.Address.get';
    $apiAddressCreate = 'api.Address.create';
    $apiGroupContactGet = 'api.GroupContact.get';
    $apiGroupContactCreate = 'api.GroupContact.create';

    $contact = array(
      'sequential' => 1,
      'contact_type' => 'Individual',
      'first_name' => $h->firstname,
      'last_name' => $h->lastname,
      'email' => $h->emails[0]->email,
      $apiAddressGet => array(
        'id' => '$value.address_id',
        'contact_id' => '$value.id',
      ),
      $apiGroupContactGet => array(
        'group_id' => $this->groupId,
        'contact_id' => '$value.id',
        'status' => 'Added',
      ),
    );

    $result = civicrm_api3('Contact', 'get', $contact);

    unset($contact[$apiAddressGet]);
    unset($contact[$apiGroupContactGet]);
    $contact[$apiAddressCreate] = array(
      'postal_code' => $h->addresses[0]->zip,
      'is_primary' => 1,
    );
    if ($result['count'] == 1) {
      $contact['id'] = $result['values'][0]['id'];
      if ($result['values'][0][$apiAddressGet]['count'] == 1) {
        $contact[$apiAddressCreate]['id'] = $result['values'][0]['address_id'];
      } else {
        $contact[$apiAddressCreate]['location_type_id'] = 1;
      }
      if ($result['values'][0][$apiGroupContactGet]['count'] == 0) {
        $contact[$apiGroupContactCreate] = array(
          'group_id' => $this->groupId,
          'contact_id' => '$value.id',
          'status' => 'Pending',
        );
      }
    } else {
      $this->new_contact = true;
      $this->customFields = $this->getCustomFields($this->campaignId);
      $contact['preferred_language'] = $this->getLanguage();
      CRM_Core_Error::debug_var('$contact[preferred_language]', $contact['preferred_language'], false, true);
      $contact['source'] = 'speakout ' . $param->action_type . ' ' . $param->external_id;
      $contact[$apiAddressCreate]['location_type_id'] = 1;
      $contact[$apiGroupContactCreate] = array(
        'group_id' => $this->groupId,
        'contact_id' => '$value.id',
        'status' => 'Pending',
      );
    }

    CRM_Core_Error::debug_var('$createContact', $contact, false, true);
    return civicrm_api3('Contact', 'create', $contact);

  }


  /**
   * Create new activity for contact.
   *
   * @param $param
   * @param $contact_id
   * @param string $activity_type
   * @param string $activity_status
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function createActivity($param, $contact_id, $activity_type = 'Petition', $activity_status = 'Scheduled') {
    $activity_type_id = CRM_Core_OptionGroup::getValue('activity_type', $activity_type, 'name', 'String', 'value');
    $activity_status_id_scheduled = CRM_Core_OptionGroup::getValue('activity_status', $activity_status, 'name', 'String', 'value');
    $params = array(
      'source_contact_id' => $contact_id,
      'source_record_id' => $param->external_id,
      'campaign_id' => $this->campaignId,
      'activity_type_id' => $activity_type_id,
      'activity_date_time' => $param->create_dt,
      'subject' => $param->action_name,
      'location' => $param->action_technical_type,
      'status_id' => $activity_status_id_scheduled,
    );
    CRM_Core_Error::debug_var('$paramsCreateActivity', $params, false, true);
    return civicrm_api3('Activity', 'create', $params);
  }


  /**
   * Get campaign by external identifier.
   *
   * @param $external_identifier
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function getCampaign($external_identifier) {
    if ($external_identifier > 0) {
      $params = array(
        'sequential' => 1,
        'external_identifier' => (int)$external_identifier,
      );
      $result = civicrm_api3('Campaign', 'get', $params);
      if ($result['count'] == 1) {
        return $result['values'][0];
      }
    }
    return array();
  }


  /**
   * Setting up new campaign in CiviCRM if this is necessary.
   *
   * @param $param
   * @param $campaign
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function setCampaign($param, $campaign) {
    if (!$this->isValidCampaign($campaign)) {
      echo 0;
      if ($param->external_id > 0) {
        echo 1;
        $ext_campaign = json_decode(file_get_contents("https://act.wemove.eu/campaigns/{$param->external_id}.json"));
        $ext_campaign->msg_template_id = 69;
        $ext_campaign->preferred_language = 'pl_PL';
        echo "ext_campaign ";
        print_r($ext_campaign);
        // todo smarter validation?
        if (is_object($ext_campaign) &&
          property_exists($ext_campaign, 'name') && $ext_campaign->name != '' &&
          property_exists($ext_campaign, 'id') && $ext_campaign->id > 0 &&
          property_exists($ext_campaign, 'msg_template_id') && $ext_campaign->msg_template_id > 0 &&
          property_exists($ext_campaign, 'preferred_language') && $ext_campaign->preferred_language != ''
        ) {
          echo 2;
          $params = array(
            'sequential' => 1,
            'title' => $ext_campaign->name,
            'external_identifier' => $ext_campaign->id,
            'parent_id' => $ext_campaign->parent_id,
            $this->fieldTemplateId => $ext_campaign->msg_template_id,
            $this->fieldLanguage => $ext_campaign->preferred_language,
          );
          echo "params ";
          print_r($params);
          $result = civicrm_api3('Campaign', 'create', $params);
          echo "result ";
          print_r($result);
          if ($result['count'] == 1) {
            echo 3;
            return $result['values'][0];
          }
        }
      }
    }
    return array();
  }

  /**
   * Determine whether $campaign table has a valid structure.
   *
   * @param $campaign
   *
   * @return bool
   */
  public function isValidCampaign($campaign) {
    if (
      is_array($campaign) &&
      array_key_exists('id', $campaign) &&
      $campaign['id'] > 0
    ) {
      return true;
    }
    return false;
  }


  /**
   * Check whether this external campaing (SpeakOut ID Campaign) is marked as unsupported (ex. testing campaign).
   *
   * @param $external_id
   *
   * @return bool
   */
  public function checkIfConfirm($external_id) {
    $notconfirm_external_id = array(
      9,
    );
    return !in_array($external_id, $notconfirm_external_id);
  }


  /**
   * Send confirmation mail to contact.
   *
   * @param $contact_result
   * @param $email
   * @param $activity_id
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function sendConfirm($contact_result, $email, $activity_id) {
    $params = array(
      'sequential' => 1,
      'messageTemplateID' => $this->getTemplateId(),
      'toEmail' => $email,
      'contact_id' => $contact_result['id'],
      'activity_id' => $activity_id,
    );
    CRM_Core_Error::debug_var('$paramsSpeakoutSendConfirm', $params, false, true);
    return civicrm_api3("Speakout", "sendconfirm", $params);
  }


  /**
   * Get custom fields for campaign Id.
   * Warning! Switch on permission "CiviCRM: access all custom data" for "ANONYMOUS USER"
   * @param $campaignId
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function getCustomFields($campaignId) {
    $params = array(
      'sequential' => 1,
      'return' => "{$this->fieldTemplateId},{$this->fieldLanguage}",
      'id' => $campaignId,
    );
    CRM_Core_Error::debug_var('$paramsCampaignGet', $params, false, true);
    $result = civicrm_api3('Campaign', 'get', $params);
    CRM_Core_Error::debug_var('$resultCampaignGet', $result, false, true);
    if ($result['count'] == 1) {
      return $result['values'][0];
    } else {
      return array();
    }
  }


  /**
   * Get message template id from $customFields array generated by getCustomFields() method
   *
   * @return int
   */
  public function getTemplateId() {
    if (is_array($this->customFields) && array_key_exists($this->fieldTemplateId, $this->customFields)) {
      return (int)$this->customFields[$this->fieldTemplateId];
    }
    return 0;
  }


  /**
   * Get language from $customFields array generated by getCustomFields() method
   *
   * @return int
   */
  public function getLanguage() {
    if (is_array($this->customFields) && array_key_exists($this->fieldLanguage, $this->customFields)) {
      return $this->customFields[$this->fieldLanguage];
    }
    return '';
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
  public function setContactCreatedDate($contactId, $createdDate) {
    $format = 'Y-m-d\TH:i:s.uP';
    $dt = DateTime::createFromFormat($format, $createdDate);
    $time = explode(':', $dt->getTimezone()->getName());
    $hours = $time[0];
    $mins = $time[1];
    $sign = substr($dt->getTimezone()->getName(), 0, 1);
    $dt->modify("{$hours} hour {$sign}{$mins} minutes");

    $query = "UPDATE civicrm_contact SET created_date = %2 WHERE id = %1";
    $params = array(
      1 => array($contactId, 'Integer'),
      2 => array($dt->format("Y-m-d H:i:s"), 'String'),
    );
    CRM_Core_DAO::executeQuery($query, $params);
  }
}
