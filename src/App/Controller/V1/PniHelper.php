<?php

namespace App\Controller\V1;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use \DateTime;
use \DateInterval;
use Wef\Watchdog;
use Wef\ApplicationException;
use Wef\CurlHelper;
use Wef\Helpers;
use Wef\PGDb;
use \Wef\OAuthSalesforce;
use \Wef\JWT\JWT;
use \Wef\StringEncryption;
use \Wef\ExtCrypto;

/**
 * Description of BilateralHelper
 *
 * @author BMU
 */
class PniHelper {

  protected $app;
  protected $wd;
  protected $__status;
  protected $__schema;
  protected $__jwt;
  protected $_logged_sfid = NULL;
  protected $_oc_info = NULL;


  public function __construct($request, $app, $jWT = NULL) {
    $this->app = $app;
    // Remove debug from db
    $this->app['db']->setDebugLevel(0);
    $this->wd = new Watchdog($app);
    $this->__schema = $_ENV['APP_SCHEMA'];
    $this->setLoggedInAccount($this->findLoggedInAccount($request));

//    $jwt_sample = $jWT;
//    $jwt_key = $_ENV['JWT_SECRET'];
//    $jwt_algorithm = [$_ENV['JWT_ALGORITHM']];
//    try {
//      $this->__jwt = JWT::decode($jwt_sample, $jwt_key, $jwt_algorithm);
//      $this->wd->watchdog('RegHelper', 'Decoded JSON: @jwt', ['@jwt' => print_r($this->__jwt, TRUE)]);
//    }
//    catch (\Exception $e) {
//      $this->wd->watchdog('RegHelper', 'Error Decoding jWT, aborting (@e)', ['@e' => $e->getMessage()]);
//      $this->wd->watchdog('RegHelper', '@j', ['@j' => $jwt_sample]);
//    }

  }

  //////////////////////////////////////////////////////////////////////////
  //////////////////////////////// PROTECTED ///////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  protected function db() {
    return $this->app['db'];
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// PUBLIC /////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  public function getStatusNameFromNum($status_num) {
//    $this->wd->watchdog('RegHelper', 'getStatusNameFromNum called with @n', array('@n' => $status_num));
    return (isset($this->__status['name'][$status_num]) ? $this->__status['name'][$status_num] : NULL);
  }

  public function getStatusNumFromName($status_name) {
//    $this->wd->watchdog('RegHelper', 'getStatusNumFromName called with @n', array('@n' => $status_name));
    return (isset($this->__status['num'][$status_name]) ? $this->__status['num'][$status_name] : NULL);
  }

  /**
   * Get JSON from Salesforce for $event_sfid and $account_sfid and possibly $oper_contact_sfid
   *
   * @param type $event_sfid
   * @param type $account_sfid
   * @param type $oper_contact_sfid
   * @return null
   */
  public function loadPreviewJson($event_sfid, $form_id) {
//    https://weforum--ce04.cs87.my.salesforce.com/services/apexrest/getRegistrationForm?eventId=a0P8E00000146wGUAQ&formId=0018E00000AoQhXQAV&eventId=a0P8E00000146wGUAQ&operationalContactId=001xxx
    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/'
      . 'getRegistrationForm?eventId=' . Helpers::check_plain($event_sfid)
      . '&formId=' . Helpers::check_plain($form_id);

    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
    $this->wd->watchdog('RegHelper', 'loadJson try calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::NOTICE);
    $response = $c->httpRequest($url, NULL, $headers);

    $json = $this->getJsonDefaultReponse();
    if (!in_array($response->code, array(200, 201, 204))) {
      if ($this->isProdEnvironment()) {
        throw new ApplicationException('RegHelper', 'loadJson error while calling underlying service', array(), Watchdog::ERROR);
      }
      else {
        throw new ApplicationException('RegHelper', 'loadJson error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
      }
    }
    else {
      $rawResponse = json_decode($response->data);
      $payload = json_decode($rawResponse->response);
      if (is_null($payload)) {
        // There was an error decoding the JSON, in this case we most probably have an error message as response... Thx Martin!
        $json['errorMessage'] = $rawResponse->response;
      }
      else {
        $payload->augmented = $this->augmentJSON($payload, ['doCompletions' => TRUE, 'doValues' => TRUE, 'forceValue' => TRUE, 'forceDisplayValue' => TRUE]);
        $json['success'] = TRUE;
        $json['payload'] = $payload;
      }
    }
    return $json;
  }

  /**
   * Get JSON from Salesforce for $event_sfid and $account_sfid and possibly $oper_contact_sfid
   *
   * @param type $event_sfid
   * @param type $account_sfid
   * @param type $oper_contact_sfid
   * @return null
   */
  public function loadJson(&$event_sfid, &$account_sfid = NULL, &$oper_contact_sfid = NULL, $options = []) {
//    https://weforum--ce04.cs87.my.salesforce.com/services/apexrest/getRegistrationForm?constituentId=0018E00000AoQhXQAV&eventId=a0P8E00000146wGUAQ&operationalContactId=001xxx
    $do_spouse = (isset($options['childForm']) ? (0 == strcmp('spouse', $options['childForm'])) : FALSE);
    $do_accomp = (isset($options['childForm']) ? (0 == strcmp('accomp', $options['childForm'])) : FALSE);
    $inbound_id = (isset($options['inboundId']) ? $options['inboundId'] : NULL);
    $json = $this->getJsonDefaultReponse();

    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/getRegistrationForm';

    if ($inbound_id) {
      // This takes precedence on anything
      $url .= '?inboundData=' . Helpers::check_plain($inbound_id);
    }
    else {
      if (empty($event_sfid)) {
        // This is a huge error. Log it
        $this->wd->watchdog('RegHelper', 'loadJson event_sfid empty', NULL, Watchdog::ERROR);
        return $json;
      }
      $url .= '?eventId=' . Helpers::check_plain($event_sfid);

      if ($account_sfid) {
        $url .= '&constituentId=' . Helpers::check_plain($account_sfid);
      }
      else {
        // This may the case for anonymous forms
        $this->wd->watchdog('RegHelper', 'loadJson $account_sfid empty for event @e', array('@e' => $event_sfid), Watchdog::NOTICE);
      }

      if ($do_spouse) {
        // New spouse form
        $url .= '&spouseForm=true';
      }
      if ($do_accomp) {
        // New accomp form
        $url .= '&accompanyingForm=true';
      }
    }
    if ($oper_contact_sfid) {
      $url .= '&operationalContactId=' . Helpers::check_plain($oper_contact_sfid);
    }


    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
    $this->wd->watchdog('RegHelper', 'loadJson try calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::NOTICE);
    $response = $c->httpRequest($url, NULL, $headers);

    if (!in_array($response->code, array(200, 201, 204))) {
      if ($this->isProdEnvironment()) {
        throw new ApplicationException('RegHelper', 'loadJson error while calling underlying service', array(), Watchdog::ERROR);
      }
      else {
        throw new ApplicationException('RegHelper', 'loadJson error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
      }
    }
    else {
      $rawResponse = json_decode($response->data);
      $payload = json_decode($rawResponse->response);
      if (is_null($payload)) {
        // There was an error decoding the JSON, in this case we most probably have an error message as response... Thx Martin!
        $json['errorMessage'] = $rawResponse->response;
      }
      else {
        $json['success'] = TRUE;
        // Make sure event_sfid and account_sfid is correctly set
        $event_sfid = $payload->metadata->eventId;
        $account_sfid = (property_exists($payload->metadata, 'constituentId') ? $payload->metadata->constituentId : NULL);
        $oper_contact_sfid = (property_exists($payload->metadata, 'operationalContactId') ? $payload->metadata->operationalContactId : NULL);
        // YCH TOLI-741
        // If status is 'processed', try to get the full opty status. If Registration In Progress, set it back to "Submitted"
        if (property_exists($payload, 'status') && property_exists($payload->metadata, 'opportunityId')) {
          $status = $payload->status;
          if (0 == strcasecmp('processed', $status)) {
            // Fetch status from SF / Connect
            $opty_id = $payload->metadata->opportunityId;
            $payload->metadata->opportunityStatus = $this->getOptyStatus($opty_id);
            if (0 == strcasecmp('Registration In Progress', $payload->metadata->opportunityStatus)) {
              // In that case, change the status to "Submitted"
              $payload->status = 'Submitted';
              // And also change the receivedInboundData
              foreach ($payload->metadata->receivedInboundData as $key => $an_inboundData) {
                if (0 == strcmp($an_inboundData->inboundData, $payload->metadata->currentInboundDataId)) {
                  $payload->metadata->receivedInboundData[$key]->inboundDataStatus = $payload->status;
                  break;
                }
              }
            }
            if (in_array($payload->metadata->opportunityStatus, array(
                'Closed/Cancelled',
                'Closed/Declined',
                'Closed/Excluded',
                'Cancelled/No show',
              ))) {
              // In that case, change the status to "Cancelled"
              $payload->status = 'Cancelled';
              foreach ($payload->metadata->receivedInboundData as $key => $an_inboundData) {
                if (0 == strcmp($an_inboundData->inboundData, $payload->metadata->currentInboundDataId)) {
                  $payload->metadata->receivedInboundData[$key]->inboundDataStatus = $payload->status;
                  break;
                }
              }
            }
          }
        }
        // YCH TOLI-321 Feb 07 - Add some info for Visa Request if needed. This
        // needs to be revisited after SF updates the service
        if (!property_exists($payload->metadata, 'enableVisaRequest')) {
          if ((0 == strcasecmp($payload->status, 'processed')) || (0 == strcasecmp($payload->status, 'submitted'))) {
            if (isset($payload->metadata->eventId)) {
              $enable_visa_request = $this->enableVisaRequest($payload->metadata->eventId);
              $payload->metadata->enableVisaRequest = ($enable_visa_request['success'] ? $enable_visa_request['payload']['Enable_Visa_Request__c'] : FALSE);
            }
            else {
              $payload->metadata->enableVisaRequest = FALSE;
            }
          }
          else {
            $payload->metadata->enableVisaRequest = FALSE;
          }
        }
        if ($payload->metadata->enableVisaRequest) {
          if (isset($payload->metadata->opportunityId)) {
            $visa_request = $this->visaRequest($payload->metadata->opportunityId);
            $payload->metadata->visaRequestStatus = ($visa_request['success'] ? $visa_request['payload']['Visa_Letter_Required__c'] : FALSE);
          }
          else {
            $payload->metadata->visaRequestStatus = FALSE;
          }
        }
        // TOLI-95: Load the participant summary
        if (!empty($account_sfid)) {
          $lightweight = $this->participantSummary($event_sfid, $account_sfid);
          $payload->lightweight = ($lightweight['success'] ? $lightweight['payload'] : NULL);
        }
        else {
          $payload->lightweight = NULL;
        }
        // YCH Oct 31 - Add some info for Hotel Request if needed. This need to
        // be revisited after https://weforum.jira.com/browse/SFDC-329
        if (!property_exists($payload->metadata, 'hotelRequest')) {
          if (property_exists($payload->lightweight, 'participantSummaries')) {
            if (property_exists($payload->lightweight->participantSummaries[0], 'hotelRequest')) {
              $payload->metadata->hotelRequest = $payload->lightweight->participantSummaries[0]->hotelRequest;
            }
          }
          else {
            $payload->metadata->hotelRequest = TRUE;
          }
          if ($payload->metadata->hotelRequest) {
            if (property_exists($payload->lightweight->participantSummaries[0], 'hotelRequestStatus')) {
              $payload->metadata->hotelRequest = $payload->lightweight->participantSummaries[0]->hotelRequestStatus;
            }
            else {
              if (isset($payload->metadata->opportunityId)) {
                $hotel_request = $this->hotelRequest($payload->metadata->opportunityId, $payload->metadata->currentOutboundDataId);
                $payload->metadata->hotelRequestStatus = ($hotel_request['success'] ? $hotel_request['payload']['Hotel_Requested__c'] : FALSE);
              }
              else {
                $payload->metadata->hotelRequestStatus = FALSE;
              }
            }
          }
          else {
            $payload->metadata->hotelRequestStatus = FALSE;
          }
        }

        $json['payload'] = $payload;
      }
    }
    return $json;
  }

  /**
   * Json encode but first make sure it's correctly utf8 encoded
   *
   * @param type $data
   * @return type
   */
  public function myjson_encode($data) {
    // Is it really useful ?
    array_walk_recursive($data, function (&$item, $key) {
      if (\is_string($item)) {
        $item = \utf8_encode($item);
      }
    });
    return json_encode($data);
  }

  /**
   * Calls Salesforce and saves $data
   *
   * @param type $data
   * @return type
   * @throws ApplicationException
   */
  public function saveRegformAndCache($app, $payload, $params) {
    $json = $params['json'];
    $account_id = $params['account_id'];
    $event_id = $params['event_id'];
    $oper_contact_sfid = $params['oper_contact_sfid'];
    $form_type = $params['form_type'];
    $action = $params['action'];

    try {
      $return = $this->saveRegform($payload);
      $payload_back = $return['payload'];
      $this->wd->watchdog('RegHelper', 'After save, got @d', array('@d' => print_r($payload_back, TRUE)));

      if (empty($payload_back['isSuccess'])) {
        $this->wd->watchdog('RegServicesController', 'Failed to do action @a for @c (oper @o) at @e with msg : @m', Array(
          '@a' => $action,
          '@c' => $account_id,
          '@o' => $oper_contact_sfid,
          '@e' => $event_id,
          '@m' => $payload_back['message']), Watchdog::ERROR);
        return $app->json(['error' => TRUE, 'return' => $payload_back, 'errorMessage' => $payload_back['message']]);
      }
      else {
        // Now save the inboundData as received
        $this->updateCacheInboundId($json->augmented->inbound_hash, $payload_back['inboundDataId']);
        $this->invalidateCache($json->augmented->inbound_hash);
//        $this->setCache($json, $json->augmented->inbound_hash, $account_id, $event_id, $oper_contact_sfid, $payload_back['inboundDataId'], $form_type);
        return $app->json(['error' => FALSE, 'return' => $payload_back, 'errorMessage' => '']);
      }
    }
    catch (\Exception $e) {
      return $app->json(['error' => TRUE, 'return' => $return, 'errorMessage' => $e->getMessage()]);
    }
  }

  /**
   * Calls Salesforce and saves $data
   *
   * @param type $data
   * @return type
   * @throws ApplicationException
   */
  public function saveRegform($data) {
    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/putRegistrationForm';
    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
//    $this->wd->watchdog('RegHelper', 'saveRegform try calling url @url with headers @h and data @d', array(
//      '@url' => $url,
//      '@h' => print_r($headers, TRUE),
//      '@d' => print_r($data, TRUE),
//      ), Watchdog::NOTICE);
    $this->wd->watchdog('RegHelper', 'saveRegform try calling url @url with headers @h', array(
      '@url' => $url,
      '@h' => print_r($headers, TRUE),
      ), Watchdog::NOTICE);
    $json = $this->getJsonDefaultReponse();
    $response = $c->httpRequest($url, $data, $headers, 'PUT');
    if (!in_array($response->code, array(200, 201, 204))) {
      if ($this->isProdEnvironment()) {
        throw new ApplicationException('RegHelper', 'saveRegform error while calling underlying service', array(), Watchdog::ERROR);
      }
      else {
        throw new ApplicationException('RegHelper', 'saveRegform error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
      }
    }
    $json['success'] = TRUE;
    $json['payload'] = json_decode($response->data, TRUE);
    return $json;
  }

  /**
   * Retrieves visa information from Salesforce
   *
   * @param type $opty_id
   * @return type
   * @throws ApplicationException
   */
  public function visaInformation($opty_id) {
    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/tlvisaletter';
    $url .= '?opportunityId=' . $opty_id;
    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
//    $this->wd->watchdog('RegHelper', 'visaInformation try calling url @url with headers @h and data @d', array(
//      '@url' => $url,
//      '@h' => print_r($headers, TRUE),
//      '@d' => print_r($data, TRUE),
//      ), Watchdog::NOTICE);
    $this->wd->watchdog('RegHelper', 'visaInformation try calling url @url with headers @h', array(
      '@url' => $url,
      '@h' => print_r($headers, TRUE),
      ), Watchdog::NOTICE);
    $response = $c->httpRequest($url, NULL, $headers, 'GET');
    if (!in_array($response->code, array(200, 201, 204))) {
      if ($this->isProdEnvironment()) {
        throw new ApplicationException('RegHelper', 'visaInformation error while calling underlying service', array(), Watchdog::ERROR);
      }
      else {
        throw new ApplicationException('RegHelper', 'visaInformation error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
      }
    }
    $payload = json_decode($response->data);
    $json = $this->getJsonDefaultReponse();
    if (is_null($payload)) {
      // There was an error decoding the JSON, in this case we most probably have an error message as response...
      $json['errorMessage'] = 'Error while calling WS Visa Letter';
    }
    else {
      $json['success'] = TRUE;
      $json['payload'] = $payload;
    }
    return $json;
  }

  /**
   * Change hotel request status in Salesforce
   *
   * @param type $opty_id
   * @return type
   * @throws ApplicationException
   */
  public function hotelManage($opty_id, $inbound_id, $desired_state) {
    $query = "SELECT rl.sfid
              FROM opportunity o
              INNER JOIN RegistrationLogistics__c rl ON o.registration__c = rl.registration__c
              WHERE o.sfid = " . $this->db()->quote($opty_id);
    $reg_data = $this->db()->getRow($query);

    if ($reg_data) {
      $reg_log_sfid = $reg_data['sfid'];
      $this->wd->watchdog('hotelManage', 'Got @o and state @s -> @rd', ['@o' => $opty_id, '@s' => $desired_state, '@rd' => $reg_log_sfid]);
      // UPDATE RegistrationLogistics
      $record = new \stdClass();
      $record->fields = [
        'Id' => $reg_log_sfid,
        'Hotel_Requested__c' => $desired_state ? 'true' : 'false',
      ];

      $record->type = 'RegistrationLogistics__c';

      try {
        $this->app['sf']->update($record);
        // Update Heroku as well since it will take a few secs until it is refreshed
        $heroku_query = 'UPDATE RegistrationLogistics__c set hotel_requested__c=' . $this->db()->quote($desired_state ? 't' : 'f') . ' WHERE sfid=' . $this->db()->quote($reg_log_sfid);
        $this->db()->exec($heroku_query);
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Hotel_Requested__c' => $desired_state]];
      }
      catch (\Exception $e) {
        error_log($e->getMessage());
        return ['success' => FALSE, 'errorMessage' => $e->getMessage(), 'payload' => NULL];
      }
    }
    else {
      // Try to get from Inbound Data
      $record = new \stdClass();
      $record->fields = [
        'Id' => $inbound_id,
        'Hotel_Requested__c' => $desired_state ? 'true' : 'false',
      ];

      $record->type = 'Inbound_Data__c';

      try {
        $this->app['sf']->update($record);
        // Update Heroku as well since it will take a few secs until it is refreshed
        $heroku_query = 'UPDATE Inbound_Data__c set hotel_requested__c=' . $this->db()->quote($desired_state ? 't' : 'f') . ' WHERE sfid=' . $this->db()->quote($inbound_id);
        $this->db()->exec($heroku_query);
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Hotel_Requested__c' => $desired_state]];
      }
      catch (\Exception $e) {
        error_log($e->getMessage());
        return ['success' => FALSE, 'errorMessage' => $e->getMessage(), 'payload' => NULL];
      }
    }

    return ['success' => FALSE, 'errorMessage' => 'Unable to find RegistrationLogistics for this opportunity', 'payload' => $opty_id, 'query' => $query];
  }

  /**
   * Fetch hotel request status in Salesforce
   *
   * @param type $inbound_id
   * @return type
   * @throws ApplicationException
   */
  public function hotelRequest($opty_id, $inbound_id) {
    if (empty($opty_id) && empty($inbound_id)) {
      return ['success' => FALSE, 'errorMessage' => 'Empty opportunity id and inbound_id', 'payload' => NULL];
    }

    $query = "SELECT rl.hotel_requested__c
              FROM opportunity o
              INNER JOIN RegistrationLogistics__c rl ON o.registration__c = rl.registration__c
              WHERE o.sfid = " . $this->db()->quote($opty_id);
    $reg_data = $this->db()->getRow($query);

    if ($reg_data) {
      $reg_log_requested = $reg_data['hotel_requested__c'];
      return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Hotel_Requested__c' => $reg_log_requested]];
    }
    else {
      // Try inbound data
      $query = "SELECT ib.hotel_requested__c
                FROM Inbound_Data__c ib
                WHERE ib.sfid = " . $this->db()->quote($inbound_id);
      $reg_data = $this->db()->getRow($query);

      if ($reg_data) {
        $reg_log_requested = $reg_data['hotel_requested__c'];
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Hotel_Requested__c' => $reg_log_requested]];
      }
    }
    return ['success' => FALSE, 'errorMessage' => 'Unable to find RegistrationLogistics for this opportunity', 'payload' => NULL];
  }

  /**
   * Change visa Required status in Salesforce
   *
   * @param type $opty_id
   * @return type
   * @throws ApplicationException
   */
  public function visaManage($opty_id, $p_desired_state) {
    $query = "SELECT rl.sfid
              FROM opportunity o
              INNER JOIN RegistrationLogistics__c rl ON o.registration__c = rl.registration__c
              WHERE o.sfid = " . $this->db()->quote($opty_id);
    $reg_data = $this->db()->getRow($query);

    // We have a picklist value in Salesforce
    $desired_state = ($p_desired_state ? 'Yes' : 'No');
    if ($reg_data) {
      $reg_log_sfid = $reg_data['sfid'];
      $this->wd->watchdog('visaManage', '[RL mode] Got @o and state @s -> @rd', ['@o' => $opty_id, '@s' => $desired_state, '@rd' => $reg_log_sfid]);
      // UPDATE RegistrationLogistics
      $record = new \stdClass();
      $record->fields = [
        'Id' => $reg_log_sfid,
        'Visa_Letter_Required__c' => $desired_state,
      ];

      $record->type = 'RegistrationLogistics__c';

      try {
        $this->app['sf']->update($record);
        // Update Heroku as well since it will take a few secs until it is refreshed
        $heroku_query = 'UPDATE RegistrationLogistics__c set visa_letter_required__c=' . $this->db()->quote($desired_state) . ' WHERE sfid=' . $this->db()->quote($reg_log_sfid);
        $this->db()->exec($heroku_query);
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Visa_Letter_Required__c' => $desired_state]];
      }
      catch (\Exception $e) {
        error_log($e->getMessage());
        return ['success' => FALSE, 'errorMessage' => $e->getMessage(), 'payload' => NULL];
      }
    }
    else {
      $this->wd->watchdog('visaManage', '[noRL mode] Got @o and state @s', ['@o' => $opty_id, '@s' => $desired_state]);
      // UPDATE Opportunity
      $record = new \stdClass();
      $record->fields = [
        'Id' => $opty_id,
        'Visa_Requested__c' => $desired_state,
      ];

      $record->type = 'Opportunity';

      try {
        $this->app['sf']->update($record);
        // Update Heroku as well since it will take a few secs until it is refreshed
        $heroku_query = 'UPDATE Opportunity set visa_requested__c=' . $this->db()->quote($desired_state) . ' WHERE sfid=' . $this->db()->quote($opty_id);
        $this->db()->exec($heroku_query);
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Visa_Letter_Required__c' => $desired_state]];
      }
      catch (\Exception $e) {
        error_log($e->getMessage());
        return ['success' => FALSE, 'errorMessage' => $e->getMessage(), 'payload' => NULL];
      }
    }
    return ['success' => FALSE, 'errorMessage' => 'Unable to find RegistrationLogistics/Opportunity', 'payload' => NULL];
  }

  /**
   * Fetch visa required status in Salesforce
   *
   * @param type $opty_id
   * @return type
   * @throws ApplicationException
   */
  public function visaRequest($opty_id) {
    if (empty($opty_id)) {
      return ['success' => FALSE, 'errorMessage' => 'Empty opportunity id', 'payload' => NULL];
    }

    $query = "SELECT rl.visa_letter_required__c
              FROM opportunity o
              INNER JOIN RegistrationLogistics__c rl ON o.registration__c = rl.registration__c
              WHERE o.sfid = " . $this->db()->quote($opty_id);
    $reg_data = $this->db()->getRow($query);

    if ($reg_data) {
      $reg_visa_required = (0 == strcasecmp('Yes', $reg_data['visa_letter_required__c']));
      $this->wd->watchdog('visaRequest', 'Found visa request status as @vr for opty @o', array('@vr' => ($reg_visa_required ? 'ASKED' : 'NOT ASKED'), '@o' => $opty_id));
      return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Visa_Letter_Required__c' => $reg_visa_required]];
    }
    else {
      $query = "SELECT o.visa_requested__c
                FROM opportunity o
                WHERE o.sfid = " . $this->db()->quote($opty_id);
      $reg_data = $this->db()->getRow($query);

      if ($reg_data) {
        $reg_visa_required = (0 == strcasecmp('Yes', $reg_data['visa_requested__c']));
        $this->wd->watchdog('visaRequest', 'Found visa request status as @vr for opty @o', array('@vr' => ($reg_visa_required ? 'ASKED' : 'NOT ASKED'), '@o' => $opty_id));
        return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Visa_Letter_Required__c' => $reg_visa_required]];
      }
    }
    $this->wd->watchdog('visaRequest', 'Unable to find RegistrationLogistics for opty @o', array('@o' => $opty_id), Watchdog::ERROR);
    return ['success' => FALSE, 'errorMessage' => 'Unable to find RegistrationLogistics for opportunity ' . $opty_id, 'payload' => NULL];
  }

  /**
   * Check if for this event we need to be able to ask for Visa Request or
   *
   * @param type $event_id
   * @return type
   * @throws ApplicationException
   */
  public function enableVisaRequest($event_id) {
    if (empty($event_id)) {
      return ['success' => FALSE, 'errorMessage' => 'Empty event id', 'payload' => NULL];
    }

    // Heroku call
    $query = "SELECT evt.enable_visa_request__c
              FROM Event__c evt
              WHERE evt.sfid = " . $this->db()->quote($event_id);
    $reg_data = $this->db()->getRow($query);

    if ($reg_data) {
      $reg_enable_visa_request = $reg_data['enable_visa_request__c'];
      return ['success' => TRUE, 'errorMessage' => '', 'payload' => ['Enable_Visa_Request__c' => $reg_enable_visa_request]];
    }
    return ['success' => FALSE, 'errorMessage' => 'Unable to find Event for this id', 'payload' => NULL];
  }

  /**
   * Decline the regform
   *
   * @param type $event_id
   * @param type $account_id
   * @return type
   * @throws ApplicationException
   */
  public function declineRegform($event_id, $account_id) {
    $c = new CurlHelper($this->app);
    $decline_params = [
      'accountId' => $account_id,
      'eventId' => $event_id,
      'action' => 'decline',
    ];

    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/updateEventInvitation';
    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
//    $this->wd->watchdog('RegHelper', 'saveRegform try calling url @url with headers @h and data @d', array(
//      '@url' => $url,
//      '@h' => print_r($headers, TRUE),
//      '@d' => print_r($data, TRUE),
//      ), Watchdog::NOTICE);
    $this->wd->watchdog('RegHelper', 'declineRegform try calling url @url with headers @h and params @p', array(
      '@url' => $url,
      '@h' => print_r($headers, TRUE),
      '@p' => json_encode($decline_params),
      ), Watchdog::NOTICE);

    $response = $c->httpRequest($url, json_encode($decline_params), $headers, 'POST');
//    $response = $c->httpRequest($url, NULL, $headers, 'PUT');
    if (!in_array($response->code, array(200, 201, 204))) {
      throw new ApplicationException('RegHelper', 'saveRegform error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
    }
    return $response;
  }

  /**
   * Delete the regform
   *
   * @param type $inbound_hash
   * @param type $data_to_send
   * @return boolean
   * @throws ApplicationException
   */
  public function deleteRegform($inbound_hash, $data_to_send) {
    $this->wd->watchdog('RegHelper', 'deleteRegform: Called with @h', array('@h' => $inbound_hash));
    try {
      $return = $this->saveRegform($data_to_send);
      $this->deleteFromCache($inbound_hash);
    }
    catch (\Exception $e) {
      // We got an error, return it
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generates a md5 hash from $inbound_sfid
   *
   * @param type $inbound_sfid
   * @return type
   */
  public function getInboundHash() {
//    if (empty($inbound_sfid)) {
//      return strtoupper(md5($inbound_sfid . '#%#&#!getInboundHash is in the place! #%#&#!' . '-' . time()));
//    }
//    else {
//      return strtoupper(md5($inbound_sfid . '#%#&#!getInboundHash is in the place! #%#&#!' . $inbound_sfid));
//    }
    return strtoupper(md5('#%#&#!getInboundHash is in the place! #%#&#!' . '-' . microtime()));
  }

  /**
   * Get default json response, which is a success FALSE
   *
   * @return type
   */
  protected function getJsonDefaultReponse() {
    return ['success' => FALSE, 'errorMessage' => '', 'payload' => NULL];
  }

  /**
   * Get form actions. This should be called AFTER augmentation
   *
   * @param type $json
   * @param type $tlmeta
   * @param type $b_has_delegation
   * @return type
   */
  public function getFormActions($json, $tlmeta, $b_has_delegation) {
    $form_status = $json->status;
    $form_type = $tlmeta->form_type;
    $b_is_not_complete = in_array($form_status, Array('Not Complete', 'New'));
    // Blank OC Forms (i.e. empty tlmeta->accound_sfid) are not declineable
    $b_is_declinable = (!in_array($form_status, Array('Submitted', 'Processed', 'Cancelled')) && !empty($tlmeta->account_sfid) && (0 == strcasecmp('main', $form_type)));
    $b_is_submitted = in_array($form_status, Array('Submitted'));
    $b_is_processed = in_array($form_status, Array('Processed'));
    $b_is_cancelled = in_array($form_status, Array('Cancelled'));
    $b_is_deleteable = in_array($form_status, Array('Not Complete', 'New')) && $b_has_delegation;
    $b_payment_todo = $tlmeta->payment_required && !$tlmeta->payment_success;
    $maybe_display_submit_button = !$b_is_submitted && !$b_is_processed && !$b_is_cancelled && !$b_payment_todo;
    if ($maybe_display_submit_button) {
      // Check that everything is complete and add the corresponding button
      $do_display_submit_button = (100 == $json->augmented['completion']);
    }
    else {
      $do_display_submit_button = FALSE;
    }

    $b_add_spouse = $json->metadata->spouseAllowed && !$json->metadata->spouseRegistered && is_null($json->augmented['inbound']['spouse']);
    $b_add_accomp = $json->metadata->currentNumberOfAccompanying < $json->metadata->allowedNumberOfAccompanying;
    $b_display_visaRequest = $json->metadata->enableVisaRequest;
    $b_display_visaRequestStatus = $json->metadata->visaRequestStatus;
    $b_display_hotel = $json->metadata->hotelRequest;
    $b_display_hotel_status = $json->metadata->hotelRequestStatus;
    $logisticsAllowed = $json->metadata->logisticsAllowed;

    return [
      'b_has_delegation' => $b_has_delegation,
      'b_is_not_complete' => $b_is_not_complete,
      'b_is_declinable' => $b_is_declinable,
      'b_is_submitted' => $b_is_submitted,
      'b_is_processed' => $b_is_processed,
      'b_is_cancelled' => $b_is_cancelled,
      'b_is_deleteable' => $b_is_deleteable,
      'b_payment_todo' => $b_payment_todo,
      'do_display_submit_button' => $do_display_submit_button,
      'b_add_spouse' => $b_add_spouse,
      'b_add_accomp' => $b_add_accomp,
      'b_display_spouse' => $b_add_spouse && ($b_is_submitted || $b_is_processed) && $logisticsAllowed,
      'b_display_accomp' => $b_add_accomp && ($b_is_submitted || $b_is_processed) && $logisticsAllowed,
      'b_display_visaRequest' => $b_display_visaRequest && ($b_is_submitted || $b_is_processed) && $logisticsAllowed,
      'b_display_visaRequestStatus' => $b_display_visaRequestStatus,
      'b_display_printVisa' => !$b_display_visaRequest && $b_is_processed,
      'b_display_hotel' => $b_display_hotel && ($b_is_submitted || $b_is_processed) && $logisticsAllowed,
      'b_display_hotel_status' => $b_display_hotel_status,
    ];
  }

  /**
   * Get constituent name to display in the form detail
   *
   * @param type $json
   * @return string
   */
  public function getFormConstituentName($json) {
    $inbound_data = $json->metadata->currentInboundDataId;
    $result = 'N/A';
    if (isset($json->augmented['inbound']) && isset($json->augmented['inbound']['ids']) && isset($json->augmented['inbound']['ids'][$inbound_data])) {
      $root = $json->augmented['inbound']['ids'][$inbound_data];
      $result = \implode(' ', [$root->firstName, $root->lastName]);
    }
    return $result;
  }

  /**
   * Cache $json
   *
   * @param type $json
   * @param type $inbound_hash
   * @param type $account_id
   * @param type $event_id
   * @param type $oper_contact_sfid
   */
  public function setCache($json, $inbound_hash, $account_id, $event_id, $oper_contact_sfid, $inbound_data, $form_type, $cur_row = NULL) {
    // Force the cache of new forms to 1d. Goal is to make sure we don't lose
    // this form until it has been saved once
    $reg_lifetime = (empty($inbound_data) ? 86400 : $this->db()->getSetting('reg_lifetime', 900));
    $app_prefix = $this->db()->get_app_prefix();

    $generated = time();
    $tlmeta = new \stdClass();
    $tlmeta->inboundHash = $inbound_hash;
    $tlmeta->account_sfid = $account_id;
    $tlmeta->event_sfid = $event_id;
    $tlmeta->oper_contact_sfid = $oper_contact_sfid;
    $tlmeta->generated_at = date('Y-m-d H:i:s', $generated);
    $tlmeta->should_expire_at = date('Y-m-d H:i:s', $generated + $reg_lifetime);

    $tlmeta->payment_required = $json->augmented['payment_required'];
    $tlmeta->payment_success = ($tlmeta->payment_required && $json->metadata->paymentDone);

    $tlmeta->app_prefix = $app_prefix;
    $tlmeta->form_type = $form_type;

    if (is_object($json)) {
      $considered_as_oc = $this->consideredAsOC($account_id, $event_id);
      $tlmeta->actions = $this->getFormActions($json, $tlmeta, $considered_as_oc);
      $tlmeta->write_mode = !in_array($json->status, ['Processed', 'Submitted', 'Cancelled']);
      $tlmeta->full_name = $this->getFormConstituentName($json);
    }
    else {
      $tlmeta->actions = [];
      $tlmeta->write_mode = FALSE;
      $tlmeta->full_name = 'N/A';
    }

    if (is_object($json)) {
      $json->tlmeta = $tlmeta;
    }

    if (empty($cur_row)) {
      $row = $this->loadFromCache($event_id, $account_id, $form_type, $oper_contact_sfid, $inbound_hash, $inbound_data);
      $action = (!empty($row) ? 'UPDATE' : 'INSERT');
    }
    else {
      $action = 'UPDATE';
      $row = $cur_row;
    }

    $this->wd->watchdog('RegHelper', 'Doing a setCache @action with hash @ih', array(
      '@action' => $action,
      '@ih' => (empty($inbound_hash) ? 'N/A' : $inbound_hash),
    ));


    switch ($action) {
      case 'UPDATE':
        $query = "UPDATE " . $this->__schema . ".regcache SET "
          . "date_add = " . $generated
          . ", date_expire = " . ($generated + $reg_lifetime)
          . ", json = " . $this->db()->quote(json_encode($json))
          . ", inbound_hash = " . $this->db()->quote($inbound_hash)
          . ", inbound_data = " . $this->db()->quote($inbound_data)
          . " WHERE "
          . "   id = " . $this->db()->quote($row['id']);
        break;

      case 'INSERT':
        $query = "INSERT INTO " . $this->__schema . ".regcache (account_sfid, event_sfid, operational_contact_sfid, json, date_add, inbound_hash, inbound_data, form_type, date_expire, app_prefix) "
          . "VALUES ("
          . $this->db()->quote($account_id)
          . ", " . $this->db()->quote($event_id)
          . ", " . $this->db()->quote($oper_contact_sfid)
          . ", " . $this->db()->quote(json_encode($json))
          . ", " . $generated
          . ", " . $this->db()->quote($inbound_hash)
          . ", " . $this->db()->quote($inbound_data)
          . ", " . $this->db()->quote($form_type)
          . ", " . ($generated + $reg_lifetime)
          . ", " . $this->db()->quote($app_prefix)
          . ");";
        break;
    }

    $this->db()->exec($query);
  }

  public function loadFromCache($event_id, $account_id, $form_type = '', $oper_contact_sfid = NULL, $inbound_hash = NULL, $inbound_id = NULL) {
    $app_prefix = $this->db()->get_app_prefix();

    $this->wd->watchdog('RegHelper', 'loadFromCache called with event @e, account @a, oc @oc, form_type @ft, inbound hash @ih, inbound id @iid', array(
      '@e' => empty($event_id) ? 'N/A' : $event_id,
      '@a' => empty($account_id) ? 'N/A' : $account_id,
      '@oc' => empty($oper_contact_sfid) ? 'N/A' : $oper_contact_sfid,
      '@ft' => $form_type,
      '@ih' => empty($inbound_hash) ? 'N/A' : $inbound_hash,
      '@iid' => empty($inbound_id) ? 'N/A' : $inbound_id,
      )
    );

    if ($inbound_hash) {
      $select = "SELECT id, event_sfid, account_sfid, operational_contact_sfid, date_add, date_expire, json, form_type, inbound_data, inbound_hash "
        . " FROM " . $this->__schema . ".regcache "
        . " WHERE "
        . "   inbound_hash = " . $this->db()->quote($inbound_hash)
        . "   AND app_prefix = " . $this->db()->quote($app_prefix)
        . " LIMIT 1;";
    }
    elseif ($inbound_id) {
      $select = "SELECT id, event_sfid, account_sfid, operational_contact_sfid, date_add, date_expire, json, form_type, inbound_data, inbound_hash "
        . " FROM " . $this->__schema . ".regcache "
        . " WHERE "
        . "   inbound_data = " . $this->db()->quote($inbound_id)
        . "   AND app_prefix = " . $this->db()->quote($app_prefix)
        . " LIMIT 1;";
    }
    else {
      $select = "SELECT id, event_sfid, account_sfid, operational_contact_sfid, date_add, date_expire, json, form_type, inbound_data, inbound_hash "
        . " FROM " . $this->__schema . ".regcache "
        . " WHERE "
        . "   account_sfid = " . $this->db()->quote($account_id)
        . "   AND event_sfid = " . $this->db()->quote($event_id)
        . "   AND form_type = " . $this->db()->quote($form_type)
        . "   AND app_prefix = " . $this->db()->quote($app_prefix);
      if ($oper_contact_sfid) {
        $select .= "   AND operational_contact_sfid = " . $this->db()->quote($oper_contact_sfid);
      }
      $select .= " LIMIT 1;";
    }
    $this->wd->watchdog('RegHelper', 'SQL is @s', array(
      '@s' => $select,
      )
    );
    $result = $this->db()->getRow($select);
    return $result;
  }

  public function loadFromCache_hash($inbound_hash) {
    return $this->loadFromCache(NULL, NULL, '', NULL, $inbound_hash);
  }

  public function inboundTypeSFtoTL($inboundDataType) {
    switch ($inboundDataType) {
      case 'Participant':
        return '';

      case 'Spouse':
        return 'spouse';

      case 'Accompanying':
        return 'accomp';
    }
  }

  /**
   * Return an array of inboundData id contained in the $json
   *
   * @param type $json
   */
  public function getInboundIdfromJson($json, $full_sync = FALSE) {
    $currentInboundDataId = $json->metadata->currentInboundDataId;
//    $event_id = $json->metadata->eventId;
//    $account_id = $json->metadata->constituentId;

    $result = [
      'ids' => [],
      'status' => NULL,
      'participant' => NULL,
      'spouse' => NULL,
      'accomp' => [],
      'currentId' => $currentInboundDataId,
    ];

//    $this->wd->watchdog('RegHelper', 'Got json @json', array('@json' => print_r($json, TRUE)));
    if (!empty($json->metadata->receivedInboundData)) {
      foreach ($json->metadata->receivedInboundData as $an_inboundData) {
        // Don't include deleted forms
        if (0 == strcasecmp('deleted', $an_inboundData->inboundDataStatus)) {
          // And also remove it from the metadata
          unset($json->metadata->receivedInboundData[$key]);
          continue;
        }
        $inbound_data_id = $an_inboundData->inboundData;
        $result['ids'][$inbound_data_id] = $an_inboundData;
        $augmented = NULL;
        $inbound_hash = NULL;
        $inbound_data = NULL;

        if ($full_sync && (0 != strcmp($inbound_data_id, $currentInboundDataId))) {
          // In this case, we need to load the form and fill data as required
          $form_type = $this->inboundTypeSFtoTL($an_inboundData->inboundDataType);
          $data = $this->loadJsonAndCache(NULL, NULL, NULL, ['inboundId' => $inbound_data_id, 'childForm' => $form_type]);
          $this->wd->watchdog('RegHelper', 'Loaded @iid, got result as @s', array('@iid' => $inbound_data_id, '@s' => ($data['success'] ? 'success' : 'failure')));
          if ($data['success']) {
            $json_form = $data['payload'];
            $augmented = $this->augmentJSON($json_form, ['doCompletions' => FALSE, 'doValues' => FALSE, 'forceValue' => FALSE, 'forceDisplayValue' => FALSE]);
            $inbound_hash = $data['inboundHash'];
            $inbound_data = $data['inboundData'];
          }
          else {
            continue;
          }
        }
        else {
          $inbound_hash = $json->augmented['inbound_hash'];
          $inbound_data = $currentInboundDataId;
          $augmented = NULL;
        }
        switch (strtolower($an_inboundData->inboundDataType)) {
          case 'participant':
            $result['participant'] = $an_inboundData->inboundData;
            $result['status'] = $an_inboundData->inboundDataStatus;
            break;

          case 'spouse':
            $result['spouse'] = [
              'inboundData' => $inbound_data,
              'inboundHash' => $inbound_hash,
              'augmented' => $augmented,
            ];
            break;

          case 'accompanying':
            $result['accomp'][] = [
              'inboundData' => $inbound_data,
              'inboundHash' => $inbound_hash,
              'augmented' => $augmented,
            ];
            break;
        }
      }
    }

    return $result;
  }

  /**
   * Update the cache withe $inbound_hash with a new $inbound_data
   *
   * @param type $inbound_hash
   * @param type $inbound_data
   */
  public function updateCacheInboundId($inbound_hash, $inbound_data) {
    $generated = time();
    $reg_lifetime = $this->db()->getSetting('reg_lifetime', 900);

    $query = "UPDATE " . $this->__schema . ".regcache SET "
      . "date_add = " . $generated
      . ", date_expire = " . ($generated + $reg_lifetime)
      . ", inbound_data = " . $this->db()->quote($inbound_data)
      . " WHERE "
      . "   inbound_hash = " . $this->db()->quote($inbound_hash);

    $this->db()->exec($query);
  }

  /**
   * Invalidate cache by resetting the date_expire to date_add for the given
   * inbound hash
   *
   * @param type $inbound_hash
   */
  public function invalidateCache($inbound_hash) {
    $query = "UPDATE " . $this->__schema . ".regcache SET "
      . " date_expire = date_add"
      . " WHERE "
      . "   inbound_hash = " . $this->db()->quote($inbound_hash);
    $this->wd->watchdog('RegHelper', 'Invalidate cache for hash: inbound_hash @ih', array('@ih' => $inbound_hash));
    $this->db()->exec($query);
  }

  /**
   * Invalidate cache by resetting the date_expire to date_add for event_sfid and account_sfid
   *
   * @param type $event_sfid
   * @param type $account_sfid
   */
  public function invalidateCacheByEventAccount($event_sfid, $account_sfid) {
    $query = "UPDATE " . $this->__schema . ".regcache SET "
      . " date_expire = date_add"
      . " WHERE "
      . "   event_sfid = " . $this->db()->quote($event_sfid)
      . " AND account_sfid = " . $this->db()->quote($account_sfid);
    $this->wd->watchdog('RegHelper', 'Invalidate cache for event @e and account @a', array('@e' => $event_sfid, '@a' => $account_sfid));
    $this->db()->exec($query);
  }

  /**
   * Invalidate cache by resetting the date_expire to date_add for the given
   * inbound hash
   *
   * @param type $inbound_hash
   */
  public function invalidateCacheByAccount($account_id, $event_id) {
    $query = "UPDATE " . $this->__schema . ".regcache SET "
      . " date_expire = date_add"
      . " WHERE "
      . "   account_sfid = " . $this->db()->quote($account_id)
      . " AND event_sfid = " . $this->db()->quote($event_id);
    $this->wd->watchdog('RegHelper', 'Invalidate cache for account @a and event @e', array('@a' => $account_id, '@e' => $event_id));
    $this->db()->exec($query);
  }

  /**
   * Invalidate cache by resetting the date_expire to date_add for the given
   * inbound hash
   *
   * @param type $inbound_hash
   */
  public function deleteFromCache($inbound_hash) {
    $query = "DELETE FROM " . $this->__schema . ".regcache "
      . " WHERE "
      . "   inbound_hash = " . $this->db()->quote($inbound_hash);

    $this->db()->exec($query);
  }

  /**
   * Invalidate cache by resetting the date_expire to date_add for the given
   * inbound hash
   *
   * @param type $inbound_hash
   */
  public function invalidateParentCache($inbound_hash) {
    $parent = $this->getParentFormFromCache($inbound_hash);
    if ($parent) {
      $parent_hash = $parent['inbound_hash'];
      if (0 != strcmp($parent_hash, $inbound_hash)) {
        $this->invalidateCache($parent_hash);
      }
    }
  }

  /**
   * Invalidate parent and siblings cache by resetting the date_expire to date_add for the given
   * inbound hash
   *
   * @param type $inbound_hash
   */
  public function invalidateParentSiblingCache($inbound_hash) {
    // We need to get the cache entries that have the same account_sfid and event_sfid
    $parent = $this->getParentFormFromCache($inbound_hash);
    if ($parent) {
      $parent_account_id = $parent['account_sfid'];
      $parent_event_id = $parent['event_sfid'];
      $this->invalidateCacheByAccount($parent_account_id, $parent_event_id);
    }
  }

  /**
   * Load regform and cache the result
   *
   * @param type $event_id
   * @param type $account_id
   * @param type $oper_contact_sfid
   * @param type $force
   * @return type
   */
  public function loadJsonAndCache($p_event_id, $p_account_id, $p_oper_contact_sfid = NULL, $options = []) {
    $result = $this->getJsonDefaultReponse();

    $force = (isset($options['force']) ? $options['force'] : FALSE);
    $inbound_id = (isset($options['inboundId']) ? $options['inboundId'] : NULL);
    $inbound_hash = (isset($options['inboundHash']) ? $options['inboundHash'] : NULL);
    $do_child = (isset($options['childForm']) ? !empty($options['childForm']) : FALSE);
    $is_masquerading = (isset($options['is_masquerade']) ? $options['is_masquerade'] : FALSE);
    $form_type = ($do_child ? $options['childForm'] : 'main');

    // Is it a new child form ?
    $new_child = $do_child && is_null($inbound_id);
    $event_id = $p_event_id;
    $account_id = $p_account_id;
    $oper_contact_sfid = $p_oper_contact_sfid;

    $this->wd->watchdog('RegHelper', 'Call with form type @dc, new child @nc, account @aid, oper contact @ocid, inbound_id: @iid, inbound_hash @ih, force @force, masquerade @mq', array(
      '@dc' => $form_type,
      '@nc' => ($do_child ? ' TRUE' : ' FALSE'),
      '@aid' => (empty($account_id) ? 'N/A' : $account_id),
      '@iid' => (empty($inbound_id) ? 'N/A' : $inbound_id),
      '@ocid' => (empty($p_oper_contact_sfid) ? 'N/A' : $p_oper_contact_sfid),
      '@ih' => (empty($inbound_hash) ? 'N/A' : $inbound_hash),
      '@force' => ($force ? 'Yes' : 'No'),
      '@mq' => ($is_masquerading ? 'Yes' : 'No'),
    ));

    // Init json variable so that it will be fetched from SF if not in the cache
    $json = NULL;
    $row = NULL;

    if ($new_child) {
      // We are looking for a new child form.
      $form_type = $options['childForm'];
      if (!in_array($form_type, array('spouse', 'accomp'))) {
        $this->wd->watchdog('RegHelper', 'Unknown child form type detected (@f), aborting', array('@f' => $form_type));
        return $result;
      }
      // New child form cannot be from cache
      $row = NULL;
    }
    else {
      // Try to get the form from the cache
      if (!$force) {
        $row = $this->loadFromCache($event_id, $account_id, $form_type, $oper_contact_sfid, $inbound_hash, $inbound_id);
//        $this->wd->watchdog('loadJsonAndCache', 'Got row @r', array('@r' => base64_encode(print_r($row, TRUE))));
        if ($row) {
          $event_id = $row['event_sfid'];
          $account_id = $row['account_sfid'];
          $oper_contact_sfid = $row['operational_contact_sfid'];
          $inbound_id = $row['inbound_data'];
          $inbound_hash = $row['inbound_hash'];
          $form_type = $row['form_type'];
          $result['cacheData'] = [
            'event_sfid' => $event_id,
            'account_sfid' => $account_id,
            'oper_contact_sfid' => $oper_contact_sfid,
            'inbound_id' => $inbound_id,
            'inbound_hash' => $inbound_hash,
            'form_type' => $form_type,
          ];
          $this->wd->watchdog('loadJsonAndCache', 'Got cachedata @r', array('@r' => print_r($result['cacheData'], TRUE)));

          // Try to keep inbound hash if possible. Will not overwrite existing hash
          $inbound_hash = $row['inbound_hash'];

          if (time() < $row['date_expire']) {
            $json = json_decode($row['json']);
          }
        }
        else {
          $this->wd->watchdog('RegHelper', 'Error while load from cache');
        }
      }
    }

    if ($json) {
      $result['payload'] = $json;
      $result['success'] = TRUE;
      // Refresh the cache (this will refresh the hash only in this case)
      if (empty($inbound_hash)) {
        $inbound_hash = $this->getInboundHash();
        $this->setCache($json, $inbound_hash, $account_id, $event_id, $oper_contact_sfid, $inbound_id, $form_type, $row);
        $result['cacheData']['inbound_hash'] = $inbound_hash;
      }
      // Test if we are masquerading. This must not be cached
      $json->tlmeta->is_masquerading = $is_masquerading;
    }
    else {
      try {
        if ((!isset($options['inboundId']) || empty($options['inboundId'])) && isset($result['cacheData']['inbound_id']) && !empty($result['cacheData']['inbound_id'])) {
          // TOLI-716: If found, force to call SF with inbound data
          $options['inboundId'] = $result['cacheData']['inbound_id'];
        }
        if (isset($options['inboundId']) && !empty($options['inboundId'])) {
          // TOLI-716: In this case, inboundId is enough, do not include operational contact Id
          $oper_contact_sfid = NULL;
        }
        $result = $this->loadJson($event_id, $account_id, $oper_contact_sfid, $options);
        if ($result['success']) {
          $json = $result['payload'];
          $json->augmented = $this->augmentJSON($json, ['doCompletions' => TRUE, 'doValues' => TRUE, 'forceValue' => TRUE, 'forceDisplayValue' => TRUE]);

          // Make sure we have an inbound hash
          if (empty($inbound_hash)) {
            $inbound_hash = $this->getInboundHash();
          }
          $json->augmented['inbound_hash'] = $inbound_hash;

          // Only get this data for participant
          if (0 == strcasecmp('participant', $json->metadata->recordType)) {
            $json->augmented['inbound'] = $this->getInboundIdfromJson($json, TRUE);
          }
          $inbound_id = (!is_null($inbound_id) ? $inbound_id : (empty($json->metadata->currentInboundDataId) ? '' : $json->metadata->currentInboundDataId));
          if (empty($inbound_id) && !$new_child) {
            $inbound_id = $json->augmented['inbound']['participant']['inboundData'];
          }

          $this->setCache($json, $inbound_hash, $account_id, $event_id, $oper_contact_sfid, $inbound_id, $form_type, $row);
          $result['cacheData'] = [
            'event_sfid' => $event_id,
            'account_sfid' => $account_id,
            'oper_contact_sfid' => $oper_contact_sfid,
            'inbound_id' => $inbound_id,
            'inbound_hash' => $inbound_hash,
            'form_type' => $form_type,
          ];
          // Test if we are masquerading. This must not be cached
          $json->tlmeta->is_masquerading = $is_masquerading;
        }
        else {
          // There was an error processing the JSON, do not cache
          $this->wd->watchdog('RegHelper', 'Got an error while loading regform: @e ', array('@e' => $result['errorMessage']));
        }
      }
      catch (\Exception $e) {
        // There was an error processing the JSON, do not cache
        $this->wd->watchdog('RegHelper', 'Got an error while loading regform: @e', array('@e' => $e->getMessage()));
      }
    }

    return $result;
  }

  /**
   * Load json from cache using $inbound_hash
   *
   * @param type $inbound_hash
   * @return type
   */
  public function loadJsonByInboundHash($inbound_hash) {
    $select = "SELECT json "
      . "FROM " . $this->__schema . ".regcache "
      . "WHERE "
      . "   inbound_hash = " . $this->db()->quote($inbound_hash) . " "
      . "LIMIT 1;";

    $result = $this->getJsonDefaultReponse();
    if ($row = $this->db()->getRow($select)) {
      $result['payload'] = json_decode($row['json']);
      $result['success'] = TRUE;
    }

    return $result;
  }

  /**
   * Get the picklist values for $tag from Salesforce
   *
   * @param type $tag
   * @return type
   * @throws ApplicationException
   */
  public function loadPicklistValues($tag) {
//    https://weforum--ce04.cs87.my.salesforce.com/services/apexrest/getPicklistValues?tag=<!country!>
    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/' . 'getPicklistValues/?tag=' . $tag;

    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
    $this->wd->watchdog('RegHelper', 'loadJson try calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::NOTICE);
    $response = $c->httpRequest($url, NULL, $headers);
    if (!in_array($response->code, array(200, 201, 204))) {
      throw new ApplicationException('RegHelper', 'loadJson error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
    }
    return $response->data;
  }

  /**
   * Augment the data from Salesforce
   *
   * @param type $content
   * @param type $options
   * @return type
   */
  public function augmentJSON($content, $options = []) {
    if ( (isset($options['doCompletions']) && $options['doCompletions'])
         || (isset($options['doValues']) && $options['doValues']) ) {
      // Handle sections summary to compute completion percentage
//      $this->wd->watchdog('augmentJSON', 'Doing sections summary');

      if (!isset($content->fields)) {
        $this->wd->watchdog('augmentJSON', 'No fields found on the form, aborting');
      }
      else {
        // Recursively scan the sections and build completions (aka section summaries)
        //$content->raugmented = $this->recursiveAugmentation($content);
        $options['payment_field'] = (property_exists($content->metadata, 'paymentMethod') ? $content->metadata->paymentMethod : NULL);
        $this->wd->watchdog('augmentJSON', 'Payment field found as @pf', ['@pf' => $options['payment_field']]);
        return $this->manualAugmentation($content->fields, $options);
      }
    }
  }

  /**
   *
   *
   * @param type $json
   * @param type $fieldCode
   * @return null
   */
  private function getField($json, $fieldCode) {
    $augmented = (isset($json->augmented['sections']) ? $json->augmented['sections'] : NULL);
    if (empty($augmented)) {
      return NULL;
    }
    foreach ($augmented as $a_section) {
      foreach ($a_section['subsections'] as $a_subsection) {
        foreach ($a_subsection['fields'] as $a_field) {
          if (0 == strcmp($fieldCode, $a_field->fieldCode)) {
            // Found it
            return $a_field;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Returns the opportunity stage to be displayed in the delegation
   *
   * @param type $stageName
   * @return string
   */
  public function getDisplayedOpportunityStage($stageName) {
    switch (strtolower($stageName)) {
      case 'invitation sent':
        $stages = array('Invited');
        break;

      case 'idea':
      case 'awaiting invitation':
      case 'registration in progress':
      default:
        $stages = array();
        break;
    }

    $imploded = implode(',', $stages);
    if (!empty($imploded)) {
      $result = ' (' . $imploded . ')';
    }
    return $result;
  }

  /**
   * Check that the limits are not crossed (accomp + spouse)
   *
   * @param type $json
   * @param type $action
   */
  public function check_limits($json, $action = 'save') {
    $errors = [];
    $do_submit = (0 == strcmp($action, 'submit'));
    $this->wd->watchdog('RegHelper', 'Called check_limits with action @a', array('@a' => $action));
    if (!$do_submit) {
      // All good if we don't submit
      return $errors;
    }

    // Are we saving a child form ?
    $form_type = $json->metadata->recordType;
    switch ($form_type) {
      case 'Accompanying';
        if ($json->metadata->currentNumberOfAccompanying >= $json->metadata->allowedNumberOfAccompanying) {
          $errors[] = 'Maximum number of accompanying person (total: ' . $json->metadata->allowedNumberOfAccompanying . ') reached';
        }
        break;

      case 'Spouse';
        if (!$json->metadata->spouseAllowed) {
          $errors[] = 'You cannot register a spouse for this event';
        }
        if ($json->metadata->spouseAllowed && $json->metadata->spouseRegistered) {
          $errors[] = 'You have already registered a spouse for this event';
        }
        break;
    }
    return $errors;
  }

  /**
   * Check that all fields match their types and returns an error array
   *
   * @param type $json
   * @param type $params
   * @param type $action
   */
  public function check_fields($json, $fields, $action = 'save') {
    $errors = [];
    $do_submit = (0 == strcmp($action, 'submit'));

//    $this->wd->watchdog('RegHelper', 'Called check_fields with @c fields, action @a, json <pre>@j</pre>',
//      array('@c' => count($fields), '@a' => $action, '@j' => print_r($json->augmented, TRUE)));
    $this->wd->watchdog('RegHelper', 'Called check_fields with @c fields, action @a', array('@c' => count($fields), '@a' => $action));
    if (!$do_submit) {
      // All good if we don't submit
      return $errors;
    }
    // We do submit, check that everything is valid
    if (!property_exists($json, 'augmented')) {
      $this->wd->watchdog('RegHelper', 'Incorrect json structure, augmented is missing, aborting!');
      return ['Incorrect json structure, augmented is missing, aborting'];
    }
    $oFields = new RegistrationFields($json->augmented, $fields);
//    $this->wd->watchdog('RegHelper', 'Before foreach, augmented is @aug', array('@aug' => print_r($json->augmented, TRUE)));
    foreach ($fields as $a_field) {
      // Find the field in the structure;
//      $this->wd->watchdog('RegHelper', 'Found field @f', array('@f' => $a_field->fieldCode));
      $true_field = $oFields->loadField($a_field->fieldCode);
      if (is_null($true_field)) {
        $this->wd->watchdog('RegHelper', 'Field @f not found in the structure', array('@f' => $a_field->fieldCode));
        $errors[] = 'There was an error with field ' . $a_field->fieldCode;
      }
      else {
        if (!$oFields->isValid($true_field)) {
          $this->wd->watchdog('RegHelper', 'Field @f not valid', array('@f' => $true_field->fieldCode), Watchdog::ERROR);
          $errors[] = 'There was an error with field ' . $a_field->fieldCode;
        }
      }
//      $field_json = $this->getField($json, $a_field->fieldCode);
//      if (empty($field_json)) {
//        $this->wd->watchdog('RegHelper', 'Impossible to get Field field @fn', array('@fn' => $a_field->fieldCode));
//        $errors[] = 'Impossible to get Field field ' . $a_field->fieldCode;
//        continue;
//      }
//      $this->wd->watchdog('RegHelper', 'Checking field @fn (@ft), value @v',
//        array('@fn' => $a_field->fieldCode, '@ft' => $field_json->fieldType, '@v' => $a_field->value));
//
//      if ($do_submit && $field_json->required && empty($field_json->value)) {
//        // In this case, an empty required field is an error
//        $errors[] = 'Compulsory field ' . $a_field->fieldCode . ' is empty';
//      }
//
//      switch ($field_json->fieldType) {
//        case 'Text_Field':
//        case 'Checkbox_Field':
//        case 'Display_Text_Field':
//        case 'TextArea_Field':
//          // No need to check the validity of those fields
//          break;
//
//        case 'Date_Field':
//          // Check that it is a correct date
//          if (FALSE === strtotime($a_field->value)) {
//            $errors[] = 'Field ' . $a_field->fieldCode . ' is not a correct date';
//          }
//          break;
//
//        case 'File_Field':
//          // Should check we received a non empty file
//          break;
//
//        case 'Picklist_with_options':
//        case 'Picklist_without_options':
//          // Value must be one of the proposed value
//          break;
//
//        case 'Number_field':
//          if (FALSE === intval($a_field->value)) {
//            $errors[] = 'Field ' . $a_field->fieldCode . ' cannot be interpreted as an integer';
//          }
//          break;
//
//        case 'Radio_Field':
//        case 'YesNo_Field':
//          break;
//
//        default:
//          $errors[] = 'Unknown field type ' . $field_json->fieldType . ' for ' . $a_field;
//          break;
//      }
    }

    return $errors;
  }

  /**
   * Get the parent row for $inbound_hash
   *
   * @param type $inbound_hash
   * @return null
   */
  public function getParentFormFromCache($inbound_hash) {
    $row = $this->loadFromCache_hash($inbound_hash);
    if (empty($row)) {
      return NULL;
    }
    // Try to find the main form for this one
    $parent = $this->loadFromCache($row['event_sfid'], $row['account_sfid'], 'main', $row['operational_contact_sfid']);
    return $parent;
  }

  /**
   * Get participant summary from Lightweight SF endpoint
   *
   * @param type $event_sfid
   * @param type $account_sfid
   */
  public function participantSummary($event_sfid, $account_sfid = NULL, $operational_contact_sfid = NULL) {
    $json = $this->getJsonDefaultReponse();

    $c = new CurlHelper($this->app);
    $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/getParticipantSummary';
    $url .= '?eventId=' . Helpers::check_plain($event_sfid);
    // If account_sfid is present, add it or else provide an empty accountId
    if ($operational_contact_sfid) {
      $url .= '&operationalContactId=' . Helpers::check_plain($operational_contact_sfid);
    }
    else {
      $url .= '&accountId=' . ($account_sfid ? Helpers::check_plain($account_sfid) : '');
    }

    // Create the correct oAuth HTTP headers
    $headers = $this->getoAuthHeaders();
    $this->wd->watchdog('RegHelper', 'participantSummary try calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::NOTICE);
    $response = $c->httpRequest($url, NULL, $headers);
    $this->wd->watchdog('RegHelper', 'Got answer from participantSummary: @a', array('@a' => print_r($response, TRUE)));

    if (!in_array($response->code, array(200, 201, 204))) {
      throw new ApplicationException('RegHelper', 'participantSummary error while calling url @url with headers @h', array('@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
    }
    else {
      $payload = json_decode($response->data);
      if (is_null($payload)) {
        // There was an error decoding the JSON, in this case we most probably have an error message as response... Thx Martin!
        $json['errorMessage'] = $rawResponse->response;
      }
      else {
        $json['success'] = TRUE;
        $payload->eventId = $event_sfid;
        // Encode invoice ids if available
        if (property_exists($payload->participantSummaries[0], 'invoices')) {
          $se = new StringEncryption($this->app);
          if ($se) {
            foreach ($payload->participantSummaries[0]->invoices as $key => $an_invoice) {
              $invoice_data = new \stdClass();
              $invoice_data->id = $an_invoice->id;
              $invoice_data->account_sfid = $this->getLoggedInAccount();
              $invoice_data->time = time();

              $payload->participantSummaries[0]->invoices[$key]->id = $se->encrypt(\serialize($invoice_data));
              $payload->participantSummaries[0]->invoices[$key]->filename = $an_invoice->invoiceType . '_' . $an_invoice->lastName . '_' . str_replace('-', '_', substr($an_invoice->invoiceDate, 0, 10)) . '.pdf';
            }
          }
          else {
            foreach ($payload->participantSummaries[0]->invoices as $key => $an_invoice) {
              // Insecure, remove all invoices ids and change title to reflect that
              $payload->participantSummaries[0]->invoices[$key]->title = '* ' .  $an_invoice->title;
              $payload->participantSummaries[0]->invoices[$key]->id = '';
            }
          }
        }
        // TOLI-95: Create TEST data for the invoices
//        if (!property_exists($payload->participantSummaries[0], 'invoices')) {
//          $payload->participantSummaries[0]->invoices = [];
//          $invoice_data = new \stdClass();
//          $invoice_data->title = 'Title ' . rand(0, 65536);
//          $invoice_data->id = substr(md5(rand(0, 65536)), 0, 24);
//          $payload->participantSummaries[0]->invoices[] = $invoice_data;
//          $invoice_data2 = new \stdClass();
//          $invoice_data2->title = 'Title ' . rand(0, 65536);
//          $invoice_data2->id = substr(md5(rand(0, 65536)), 0, 24);
//          $payload->participantSummaries[0]->invoices[] = $invoice_data2;
//        }

        $json['payload'] = $payload;
      }
    }
    return $json;
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// PRIVATE ////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  private function getoAuthHeaders() {
    $oAuth = new OAuthSalesforce($this->app);
    $headers = [
      'Authorization' => 'OAuth ' . $oAuth->getAdminAccessToken(),
      'Content-type' => 'application/json',
    ];
    return $headers;
  }

  /**
   * Count the number of selectOptions available
   *
   * @param type $selectOptions
   */
  private function count_selectOptions($selectOptions) {
    if (is_array($selectOptions)) {
      return count($selectOptions);
    }
    $lines_arr = preg_split('/\r\n/', $selectOptions);
    return count($lines_arr);
  }

  /**
   * Obfuscate $value according to $algo
   *
   * @param type $value
   * @return type
   */
  private function obfuscateValue($value, $algo = 'simple') {
    switch ($algo) {
      case 'last4visible':
        $len = strlen($value);
        $clear = \floor(\min($len / 2, 4));
        $obfuscated = str_repeat('*', $len - $clear);
        // Then add $clear digits to the end
        $obfuscated .= ($clear ? substr($value, -$clear) : '');
        break;

      case 'simple':
      default:
        $obfuscated = str_repeat('*', strlen($value));
        break;
    }
    return $obfuscated;
  }

  /**
   * Manual augmentation of the $regform
   *
   * @param type $regform
   * @param type $options
   * @return type
   */
  private function manualAugmentation($regform, $options = []) {
    $return = [
      'nb_fields' => 0,
      'nb_required_fields' => 0,
      'nb_filled_required_fields' => 0,
      'completion' => 0,
      'sections' => [],
      'payment_required' => FALSE,
      'status' => FALSE,
      'health_check' => [
        'icon_category_missing' => FALSE,
        'too_many_sections' => FALSE,
        'duplicates' => [],
      ],
    ];

    $nb_sections = 0;
    foreach ($regform as $mainsection) {
      if (!empty($mainsection->section)) {
        $b_has_subsections = FALSE;

        $main_section_name = $mainsection->section->name;

        //Algo to fix the problem of missing subsections
        $fields_section = Array();
        foreach ($mainsection->section->fields as $subsection) {
          if (!empty($subsection->section)) {
            $b_has_subsections = TRUE;
            break;
          }

          if (!empty($subsection->fields)) {
            foreach ($subsection->fields as $field) {
              $fields_section[] = $field;
            }
          }
          else if (is_array($subsection)) {
            foreach ($subsection as $field) {
              $fields_section[] = $field;
            }
          }
          else if ($subsection) {
            $fields_section[] = $subsection;
          }
        }

        if (!$b_has_subsections) {
          $fake_subsection = new \stdClass();
          $fake_subsection->fields = $fields_section;
          $fake_subsection->name = $main_section_name;
          $fake_subsection_section = new \stdClass();
          $fake_subsection_section->section = $fake_subsection;
          $mainsection->section->fields = Array($fake_subsection_section);
        }

        foreach ($mainsection->section->fields as $subsection) {
          if (!empty($subsection->section)) {
            foreach ($subsection->section->fields as $field) {

              $sub_section_name = $subsection->section->name;
              $main_id = $nb_sections;

              if (!array_key_exists($main_id, $return['sections'])) {
                $cat_info = $this->findSectionCategory($mainsection);
                $return['health_check']['icon_category_missing'] = $return['health_check']['icon_category_missing'] || empty($cat_info['category']);
                $return['sections'][$main_id] = [
                  'name' => $sub_section_name,
                  'category' => $cat_info['category'],
                  'cat_weight' => $cat_info['weigth'],
                  'nb_fields' => 0,
                  'nb_required_fields' => 0,
                  'nb_filled_required_fields' => 0,
                  'completion' => 0,
                  'fields' => [],
                ];
              }

              //Stats nb_fields
              $return['nb_fields'] ++;
              $return['sections'][$main_id]['nb_fields'] ++;

              if (isset($options['doValues']) && $options['doValues']) {
                // This is a std field
                // Should we create the 'value' field ?
                if (!isset($field->value) && isset($options['forceValue']) && $options['forceValue']) {
                  $field->value = '';
                }
                if (!isset($field->inputValue)) {
                  $field->inputValue = '';
                }
                if (!isset($field->displayValue)) {
                  if (isset($field->value) ||
                    (!isset($field->value) && isset($options['forceDisplayValue']) && $options['forceDisplayValue'])) {
                    if ($field->obfuscated) {
                      // We should obfuscate this field.
                      // For now we replace every char with a '*', a more fancy algo
                      // can be done later
                      $field->displayValue = (isset($field->value) ? $this->obfuscateValue($field->value, 'last4visible') : '');
                    }
                    else {
                      $field->displayValue = (isset($field->value) ? $field->value : '');
                    }
                  }
                }
              }

              if ($field->fieldType == 'Picklist_without_options') {
                if (!empty($field->selectOption)) {
                  $jsonOptions = $this->getPicklistValues($field->selectOption);
                  if ($jsonOptions['success']) {
                    $field->selectOption = $jsonOptions['payload'];
                    $field->searchable = ($this->count_selectOptions($field->selectOption) > 15);
                  }
                  else {
                    $field->selectOption = array();
                    $field->searchable = FALSE;
                  }
                }
              }

              if ($field->fieldType == 'Picklist_with_options') {
                $jsonOptions = $this->formatPicklistValues($field->selectOption);
                if ($jsonOptions['success']) {
                  $field->selectOption = $jsonOptions['payload'];
                  $field->searchable = ($this->count_selectOptions($field->selectOption) > 15);
                }
                else {
                  $field->selectOption = array();
                }
              }

              if (0 == strcmp($field->fieldCode, $options['payment_field'])) {
                $this->wd->watchdog('manualAugmentation', 'Found payment field as @f, value @v',
                  ['@f' => $field->fieldCode, '@v' => $field->value]);
                $return['payment_required'] = (0 == strcasecmp('credit card', $field->value));
              }

              //Add field
              $return['sections'][$main_id]['fields'][] = $field;
            }
          }
          $nb_sections++;
        }
      }
//      $nb_sections++;
    }

    // Now that we have the correct structure, we can check the required fields
    // an accurate completion value
    $oFields = new RegistrationFields($return);
    $return['health_check']['duplicates'] = $oFields->check_duplicates();
    $return['health_check']['too_many_sections'] = (count($return['sections']) > 4);
    $return['status'] = empty($return['health_check']['duplicates'])
      && !$return['health_check']['too_many_sections']
      && !$return['health_check']['icon_category_missing'];

    foreach ($return['sections'] as $main_id => $section) {
      foreach ($section['fields'] as $field_key => $a_field) {
//        $this->wd->watchdog('RegHelper', 'Section @s, ss @ss, checking field @f', array('@s' => $main_id, '@ss' => $sub_id, '@f' => print_r($a_field, TRUE)));
        $isParentHidden = $oFields->isParentHidden($a_field);
        if ($oFields->isVisible($a_field)) {
          if ($a_field->required) {
            $return['nb_required_fields'] ++;
            $return['sections'][$main_id]['nb_required_fields'] ++;

//            if (isset($a_field->value) && !empty($a_field->value)) {
            if ($oFields->isValid($a_field)) {
              $return['nb_filled_required_fields'] ++;
              $return['sections'][$main_id]['nb_filled_required_fields'] ++;
            }

            $return['completion'] = $return['nb_required_fields'] ? intval((float) $return['nb_filled_required_fields'] / (float) $return['nb_required_fields'] * 100.0) : 100;
            $return['sections'][$main_id]['completion'] = $return['sections'][$main_id]['nb_required_fields'] ? intval((float) $return['sections'][$main_id]['nb_filled_required_fields'] / (float) $return['sections'][$main_id]['nb_required_fields'] * 100.0) : 100;
          }
        }
        else {
          // TL-1891 Field should not be visible. This won't be the case if parent
          // is hidden with Radu's current implementation however, so workaround
          // is to set it to hidden as well in that case
          if ($isParentHidden) {
            // In that case, we set the "hidden" attribute to TRUE so that it won't be displayed
            $return['sections'][$main_id]['fields'][$field_key]->hidden = TRUE;
          }
        }
        if ($isParentHidden) {
          // TL-1891: Another bug in Radu's code: if field are dependent on a hidden fields, the controlling field is shown on the form
          // Workaround: remove dependency:
          $return['sections'][$main_id]['fields'][$field_key]->controllingField = '';
        }
      }
      if (0 == $return['sections'][$main_id]['nb_required_fields']) {
        // In this case, it means that there were no required fields in the subsection, set the completion to 100
        $return['sections'][$main_id]['completion'] = 100;
      }
    }
    if (0 == $return['nb_required_fields']) {
      // In this case, it means that there were no required fields in the subsection, set the completion to 100
      $return['completion'] = 100;
    }

    return $return;
  }

  /**
   * Get the values of picklist from stored values
   *
   * @param type $tag
   * @return type
   */
  public function getPicklistValues($tag) {
    $json = $this->getJsonDefaultReponse();
    if (NULL === ($return = $this->app['db']->getSetting('sf_statics_' . $tag, NULL))) {
      try {
        if ($return = $this->loadPicklistValues($tag)) {
          $this->app['db']->setSetting('sf_statics_' . $tag, $return);
        }
      }
      catch (\Exception $e) {
        $this->wd->watchdog('getPicklistValues', 'Impossible to fetch picklist values for tag @t', ['@t' => $tag]);
        return $json;
      }
    }

    $values = json_decode($return, TRUE);
    // Now sort the array according to the 'label' key
    if (usort($values['selectOptions'], function ($a, $b) {
        return strcmp($a['label'], $b['label']);
      })) {
      $json['success'] = TRUE;
      $json['payload'] = $values['selectOptions'];
    }

    return $json;
  }

  /**
   * Format the values of picklist from stored values
   *
   * @param type $tag
   * @return type
   */
  public function formatPicklistValues($options_str) {
    $json = $this->getJsonDefaultReponse();
    $lines = preg_split('/\r\n/', $options_str);
    $options = array_map(function($a) {
      return array('value' => trim($a), 'label' => trim($a));
    }, $lines);

//    if (usort($options, function ($a, $b) { return strcmp($a['label'], $b['label']); })) {
//      $json['success'] = TRUE;
//      $json['payload'] = $options;
//    }
    $json['success'] = TRUE;
    $json['payload'] = $options;

    return $json;
  }

  /**
   * Fund the category of a section
   *
   * @param type $section
   * @return type
   */
  private function findSectionCategory($section) {
    switch ($section->section->name) {
      case 'Personal Details':
      case 'Personal':
        return Array('weigth' => 5, 'category' => 'profile');

      case 'Contact Details':
        return Array('weigth' => 5, 'category' => 'clipboard');

      case 'Professional':
        return Array('weigth' => 20, 'category' => 'people');

      case 'Payment':
      case 'Payment Details':
        return Array('weigth' => 10, 'category' => 'credit-card');
    }

    return Array('weigth' => 0, 'category' => '');
  }

  /**
   * Compute the summary of a $section. Handle recursively the sections if needed
   *
   * @param type $sections_summary
   * @param type $section
   */
  private function recursiveAugmentation(&$section, $level = 0) {
    $section_summary = [
      'name' => (isset($section->name) ? $section->name : 'Top'),
      'level' => $level,
      'stats_self' => [
        'nb_fields' => 0,
        'nb_required_fields' => 0,
        'nb_filled_required_fields' => 0,
      ],
      'stats_recursive' => [
        'nb_sections' => (0 == $level ? 0 : 1),
        'nb_fields' => 0,
        'nb_required_fields' => 0,
        'nb_filled_required_fields' => 0,
      ],
      'sections' => [],
    ];
    $this->wd->watchdog('sectionsSummary', 'Summary for section @s', array('@s' => $section_summary['name']));

    // Keep this ordering before calling recursively so that we have a correct
    // section ordering at the end of the process
    foreach ($section->fields as $field) {
      // Check if this is a field or section
      // If section, recursively call sectionSummary, if not add the corresponding
      // data to the $section_summary array
      if (isset($field->section) && is_object($field->section)) {
        $subsection = $this->recursiveAugmentation($field->section, $level + 1);
        $section_summary['sections'][] = $subsection;
        // Fill in the stats
        $section_summary['stats_recursive']['nb_sections'] += $subsection['stats_recursive']['nb_sections'];
        $section_summary['stats_recursive']['nb_fields'] += $subsection['stats_recursive']['nb_fields'];
        $section_summary['stats_recursive']['nb_required_fields'] += $subsection['stats_recursive']['nb_required_fields'];
        $section_summary['stats_recursive']['nb_filled_required_fields'] += $subsection['stats_recursive']['nb_filled_required_fields'];
      }
      else {
        // This is a std field
        // Fill in the stats
        $section_summary['stats_self']['nb_fields'] ++;
        $section_summary['stats_recursive']['nb_fields'] ++;
        if ($field->required) {
          $section_summary['stats_self']['nb_required_fields'] ++;
          $section_summary['stats_recursive']['nb_required_fields'] ++;
          if (isset($field->value) && !empty($field->value)) {
            $section_summary['stats_self']['nb_filled_required_fields'] ++;
            $section_summary['stats_recursive']['nb_filled_required_fields'] ++;
          }
        }
      }
    }
    // And now the important result
    // Total number of fields is in stats_recursive part
    $total_req_fields = $section_summary['stats_recursive']['nb_required_fields'];
    if (!empty($total_req_fields)) {
      $total_filled_req_fields = $section_summary['stats_recursive']['nb_filled_required_fields'];
      $section_summary['completion'] = intval((float) $total_filled_req_fields / (float) $total_req_fields * 100.0);
    }
    else {
      $section_summary['completion'] = 100;
    }
//    $sections_summary['sections'][$section_order] = $section_summary;
    return $section_summary;
  }

  /**
   * Returns TRUE is we are running on the prod environment, FALSE otherwise
   *
   * @return type
   */
  public function isProdEnvironment() {
    $running_env = $this->getEnvironment();
    return (0 == \strcasecmp('prod', $running_env));
  }

  /**
   * Returns the environment we are running on. Could be DEV, QA, STAGING or PROD.
   * If unset, will return an empty string
   *
   * @return type
   */
  public function getEnvironment() {
    return (isset($_ENV['RUNNING_ENV']) ? $_ENV['RUNNING_ENV'] : '');
  }

  /**
   * Get opportunity status from id in Heroku Connect
   *
   * @param type $opty_id
   */
  public function getOptyStatus($opty_id) {
    $query = "SELECT
      sfid,
      stagename
		FROM
      Opportunity
		WHERE
      sfid = " . $this->db()->quote($opty_id);
    $opty_data = $this->db()->getRow($query);

    if ($opty_data) {
      $stageName = $opty_data['stagename'];
      $this->wd->watchdog('getOptyStatus', 'Found stage "@s" for opty @d', array('@s' => $stageName, '@d' => $opty_id));
      return $stageName;
    }
    // If not found, return empty string
    $this->wd->watchdog('getOptyStatus', 'Unable to find stagename for opty @d', array('@d' => $opty_id), Watchdog::ERROR);
    return '';
  }

  /**
   * Get opportunity status for $constituents in Heroku Connect
   *
   * @param type $constituents
   */
  public function getOptyStatusForAccount($eventId, $constituents) {
    $query = "SELECT
      sfid,
      accountid,
      stagename
    FROM
      Opportunity
    WHERE
      event__c = " . $this->db()->quote($eventId)
      . ' AND' . $this->db()->in('accountid', $constituents, PGDb::IN_NONE_IF_EMPTY);

    $result = [];
    foreach ($this->db()->getCollection($query) as $an_opty) {
      $result[$an_opty['accountid']] = $an_opty['stagename'];
    }
    return $result;
  }

  /**
   * Download the invoice
   *
   * @param type $id
   */
  public function downloadInvoice($id) {
    // Prepare return JSON
    $json = $this->getJsonDefaultReponse();

    if (!empty($id)) {
      // $is is now encrypted, so we need to decrypt first
      $se = new StringEncryption($this->app);
      $invoice_id = NULL;
      if ($se) {
        $invoice_data = \unserialize($se->decrypt($id));
        $this->wd->watchdog('downloadInvoice', 'Got invoice data as @id', ['@id' => print_r($invoice_data, TRUE)]);
        if (is_object($invoice_data)) {
          // Check that the person asking for the invoice is the same that
          // asked for it
          $logged = $this->getLoggedInAccount();
//          if ($logged === $invoice_data->account_sfid) {
          if ($logged === $invoice_data->account_sfid && (time() - $invoice_data->time < 3600)) {
            $invoice_id = $invoice_data->id;
          }
        }
      }

      if (!$invoice_id) {
        return $json;
      }
      $c = new CurlHelper($this->app);
      $url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/apexrest/getinvoice';
      $url .= '?id=' . $invoice_id;
      // Create the correct oAuth HTTP headers
      $headers = $this->getoAuthHeaders();
      //    $this->wd->watchdog('RegHelper', 'visaInformation try calling url @url with headers @h and data @d', array(
      //      '@url' => $url,
      //      '@h' => print_r($headers, TRUE),
      //      '@d' => print_r($data, TRUE),
      //      ), Watchdog::NOTICE);
      $this->wd->watchdog('RegHelper', 'downloadInvoice try calling url @url with headers @h', array(
        '@url' => $url,
        '@h' => print_r($headers, TRUE),
        ), Watchdog::NOTICE);
      $response = $c->httpRequest($url, NULL, $headers, 'GET');
      if (in_array($response->code, array(200, 201, 204))) {
        $json['success'] = TRUE;
        $json['payload'] = \base64_encode($response->data);
      }
      elseif (in_array($response->code, array(400, 404))) {
//        $this->wd->watchdog('RegHelper', 'Fake data provided, got code @c', array('@c' => $response->code));
//        $this->wd->watchdog('RegHelper', 'downloadInvoice error @c received, data is @d', array('@c' => $response->code, '@d' => print_r($response->data, TRUE)));
//        $json['success'] = TRUE;
//        $json['payload'] = 'JVBERi0xLjIgDQol4uPP0w0KIA0KOSAwIG9iag0KPDwNCi9MZW5ndGggMTAgMCBSDQovRmlsdGVyIC9GbGF0ZURlY29kZSANCj4+DQpzdHJlYW0NCkiJzZDRSsMwFIafIO/we6eyZuckTZPtbtIWBi0UjYKQGxFbJmpliuLb26QM8X6CJBfJyf99ycmFF6xJagWrrMxzwJeCEMd+gFjWBC1dLPeCJFkbl/fTKfwnTqt1CK0xIZyEwFYZ2T+fwT8KnmIxUmJinNKJyUiyW7mZVEQ6I54m2K3ZzFiupvgPaee7JHFuZqyDvxuGBbZdu8D1y+7jYf+2e//C2KOJm9dxfEqqTHMRXZlR0hRJuKwZau6EJa+MOdjpYN/gprq8xVW7aRp0ZY162ySbktoWvxpPZULGxJLSr+G4UuX+QHrcl/rz/2eqvPgGPPWhqg0KZW5kc3RyZWFtDQplbmRvYmoNCjEwIDAgb2JqDQoyNDYNCmVuZG9iag0KNCAwIG9iag0KPDwNCi9UeXBlIC9QYWdlDQovUGFyZW50IDUgMCBSDQovUmVzb3VyY2VzIDw8DQovRm9udCA8PA0KL0YwIDYgMCBSIA0KL0YxIDcgMCBSIA0KPj4NCi9Qcm9jU2V0IDIgMCBSDQo+Pg0KL0NvbnRlbnRzIDkgMCBSDQo+Pg0KZW5kb2JqDQo2IDAgb2JqDQo8PA0KL1R5cGUgL0ZvbnQNCi9TdWJ0eXBlIC9UcnVlVHlwZQ0KL05hbWUgL0YwDQovQmFzZUZvbnQgL0FyaWFsDQovRW5jb2RpbmcgL1dpbkFuc2lFbmNvZGluZw0KPj4NCmVuZG9iag0KNyAwIG9iag0KPDwNCi9UeXBlIC9Gb250DQovU3VidHlwZSAvVHJ1ZVR5cGUNCi9OYW1lIC9GMQ0KL0Jhc2VGb250IC9Cb29rQW50aXF1YSxCb2xkDQovRmlyc3RDaGFyIDMxDQovTGFzdENoYXIgMjU1DQovV2lkdGhzIFsgNzUwIDI1MCAyNzggNDAyIDYwNiA1MDAgODg5IDgzMyAyMjcgMzMzIDMzMyA0NDQgNjA2IDI1MCAzMzMgMjUwIA0KMjk2IDUwMCA1MDAgNTAwIDUwMCA1MDAgNTAwIDUwMCA1MDAgNTAwIDUwMCAyNTAgMjUwIDYwNiA2MDYgNjA2IA0KNDQ0IDc0NyA3NzggNjY3IDcyMiA4MzMgNjExIDU1NiA4MzMgODMzIDM4OSAzODkgNzc4IDYxMSAxMDAwIDgzMyANCjgzMyA2MTEgODMzIDcyMiA2MTEgNjY3IDc3OCA3NzggMTAwMCA2NjcgNjY3IDY2NyAzMzMgNjA2IDMzMyA2MDYgDQo1MDAgMzMzIDUwMCA2MTEgNDQ0IDYxMSA1MDAgMzg5IDU1NiA2MTEgMzMzIDMzMyA2MTEgMzMzIDg4OSA2MTEgDQo1NTYgNjExIDYxMSAzODkgNDQ0IDMzMyA2MTEgNTU2IDgzMyA1MDAgNTU2IDUwMCAzMTAgNjA2IDMxMCA2MDYgDQo3NTAgNTAwIDc1MCAzMzMgNTAwIDUwMCAxMDAwIDUwMCA1MDAgMzMzIDEwMDAgNjExIDM4OSAxMDAwIDc1MCA3NTAgDQo3NTAgNzUwIDI3OCAyNzggNTAwIDUwMCA2MDYgNTAwIDEwMDAgMzMzIDk5OCA0NDQgMzg5IDgzMyA3NTAgNzUwIA0KNjY3IDI1MCAyNzggNTAwIDUwMCA2MDYgNTAwIDYwNiA1MDAgMzMzIDc0NyA0MzggNTAwIDYwNiAzMzMgNzQ3IA0KNTAwIDQwMCA1NDkgMzYxIDM2MSAzMzMgNTc2IDY0MSAyNTAgMzMzIDM2MSA0ODggNTAwIDg4OSA4OTAgODg5IA0KNDQ0IDc3OCA3NzggNzc4IDc3OCA3NzggNzc4IDEwMDAgNzIyIDYxMSA2MTEgNjExIDYxMSAzODkgMzg5IDM4OSANCjM4OSA4MzMgODMzIDgzMyA4MzMgODMzIDgzMyA4MzMgNjA2IDgzMyA3NzggNzc4IDc3OCA3NzggNjY3IDYxMSANCjYxMSA1MDAgNTAwIDUwMCA1MDAgNTAwIDUwMCA3NzggNDQ0IDUwMCA1MDAgNTAwIDUwMCAzMzMgMzMzIDMzMyANCjMzMyA1NTYgNjExIDU1NiA1NTYgNTU2IDU1NiA1NTYgNTQ5IDU1NiA2MTEgNjExIDYxMSA2MTEgNTU2IDYxMSANCjU1NiBdDQovRW5jb2RpbmcgL1dpbkFuc2lFbmNvZGluZw0KL0ZvbnREZXNjcmlwdG9yIDggMCBSDQo+Pg0KZW5kb2JqDQo4IDAgb2JqDQo8PA0KL1R5cGUgL0ZvbnREZXNjcmlwdG9yDQovRm9udE5hbWUgL0Jvb2tBbnRpcXVhLEJvbGQNCi9GbGFncyAxNjQxOA0KL0ZvbnRCQm94IFsgLTI1MCAtMjYwIDEyMzYgOTMwIF0NCi9NaXNzaW5nV2lkdGggNzUwDQovU3RlbVYgMTQ2DQovU3RlbUggMTQ2DQovSXRhbGljQW5nbGUgMA0KL0NhcEhlaWdodCA5MzANCi9YSGVpZ2h0IDY1MQ0KL0FzY2VudCA5MzANCi9EZXNjZW50IDI2MA0KL0xlYWRpbmcgMjEwDQovTWF4V2lkdGggMTAzMA0KL0F2Z1dpZHRoIDQ2MA0KPj4NCmVuZG9iag0KMiAwIG9iag0KWyAvUERGIC9UZXh0ICBdDQplbmRvYmoNCjUgMCBvYmoNCjw8DQovS2lkcyBbNCAwIFIgXQ0KL0NvdW50IDENCi9UeXBlIC9QYWdlcw0KL01lZGlhQm94IFsgMCAwIDYxMiA3OTIgXQ0KPj4NCmVuZG9iag0KMSAwIG9iag0KPDwNCi9DcmVhdG9yICgxNzI1LmZtKQ0KL0NyZWF0aW9uRGF0ZSAoMS1KYW4tMyAxODoxNVBNKQ0KL1RpdGxlICgxNzI1LlBERikNCi9BdXRob3IgKFVua25vd24pDQovUHJvZHVjZXIgKEFjcm9iYXQgUERGV3JpdGVyIDMuMDIgZm9yIFdpbmRvd3MpDQovS2V5d29yZHMgKCkNCi9TdWJqZWN0ICgpDQo+Pg0KZW5kb2JqDQozIDAgb2JqDQo8PA0KL1BhZ2VzIDUgMCBSDQovVHlwZSAvQ2F0YWxvZw0KL0RlZmF1bHRHcmF5IDExIDAgUg0KL0RlZmF1bHRSR0IgIDEyIDAgUg0KPj4NCmVuZG9iag0KMTEgMCBvYmoNClsvQ2FsR3JheQ0KPDwNCi9XaGl0ZVBvaW50IFswLjk1MDUgMSAxLjA4OTEgXQ0KL0dhbW1hIDAuMjQ2OCANCj4+DQpdDQplbmRvYmoNCjEyIDAgb2JqDQpbL0NhbFJHQg0KPDwNCi9XaGl0ZVBvaW50IFswLjk1MDUgMSAxLjA4OTEgXQ0KL0dhbW1hIFswLjI0NjggMC4yNDY4IDAuMjQ2OCBdDQovTWF0cml4IFswLjQzNjEgMC4yMjI1IDAuMDEzOSAwLjM4NTEgMC43MTY5IDAuMDk3MSAwLjE0MzEgMC4wNjA2IDAuNzE0MSBdDQo+Pg0KXQ0KZW5kb2JqDQp4cmVmDQowIDEzDQowMDAwMDAwMDAwIDY1NTM1IGYNCjAwMDAwMDIxNzIgMDAwMDAgbg0KMDAwMDAwMjA0NiAwMDAwMCBuDQowMDAwMDAyMzYzIDAwMDAwIG4NCjAwMDAwMDAzNzUgMDAwMDAgbg0KMDAwMDAwMjA4MCAwMDAwMCBuDQowMDAwMDAwNTE4IDAwMDAwIG4NCjAwMDAwMDA2MzMgMDAwMDAgbg0KMDAwMDAwMTc2MCAwMDAwMCBuDQowMDAwMDAwMDIxIDAwMDAwIG4NCjAwMDAwMDAzNTIgMDAwMDAgbg0KMDAwMDAwMjQ2MCAwMDAwMCBuDQowMDAwMDAyNTQ4IDAwMDAwIG4NCnRyYWlsZXINCjw8DQovU2l6ZSAxMw0KL1Jvb3QgMyAwIFINCi9JbmZvIDEgMCBSDQovSUQgWzw0NzE0OTUxMDQzM2RkNDg4MmYwNWY4YzEyNDIyMzczND48NDcxNDk1MTA0MzNkZDQ4ODJmMDVmOGMxMjQyMjM3MzQ+XQ0KPj4NCnN0YXJ0eHJlZg0KMjcyNg0KJSVFT0YNCg==';
        $error_message = json_decode($response->data);
        if ($error_message) {
          $json['errorMessage'] = $error_message->responseMessage;
        }
      }
      else {
        if ($this->isProdEnvironment()) {
          throw new ApplicationException('RegHelper', 'downloadInvoice error while calling underlying service', array(), Watchdog::ERROR);
        }
        else {
          throw new ApplicationException('RegHelper', 'downloadInvoice error (@ec) while calling url @url with headers @h', array('@ec' => $response->code, '@url' => $url, '@h' => print_r($headers, TRUE)), Watchdog::ERROR);
        }
      }
    }
    return $json;
  }

  /**
   * Fetch Organizations from Heroku
   *
   * @param type $account_sfid
   * @return \App\Controller\V1\StdClass
   */
  public function getOrganizations($account_sfid) {
    $result = new StdClass();
    $result->global = [];
    $result->local = [];

    // Return all organizations the user is member of by checking positions
    $select = "SELECT p.organization__c, o.top_level_organization__c
      FROM salesforce.position__c p
      INNER JOIN account o ON p.organization__c = o.sfid
      WHERE p.personname__c = " . $app['db']->quote($account_sfid) . "
      AND p.status__c = 'Active'
      AND p.organization__c is not null";

    $org_info = $app['db']->getCollection($select);
    if ($org_info) {
      foreach ($org_info as $row) {
        $result->global[] = $row['top_level_organization__c'];
        $result->local[] = $row['organization__c'];
      }
      $result->global = array_unique($result->global);
      $result->local = array_unique($result->local);
    }

    return $result;
  }

  /**
   * This function checks that the logged in person (identified by JWT) can manage
   * $account_sfid
   * It takes into account personal, group, local and event OC
   * It returns TRUE if logged in account can manager $account_sfid,
   * FALSE otherwise
   *
   * @param type $account_sfid
   * @param type $event_sfid
   */
  public function canManageAccount($account_sfid, $event_sfid = NULL) {
    $logged_sfid = $this->getLoggedInAccount();
    $oc_info = $this->getOCInfo();
    $this->wd->watchdog('canManageAccount', 'For logged @l, OC Info is @o', array(
      '@o' => print_r($oc_info, TRUE),
      '@l' => $logged_sfid,
    ));
    return TRUE;
    // Check easiest first, is $accound_sfid in the list of personal OC ?
    $result = in_array($account_sfid, $oc_info->is_personal_oc);
    if ($result) {
      return $result;
    }
    // Ok, now we need to get the $account_sfid local organization and top level organization
    $account_orgs = $this->getOrganizations($account_sfid);

    // Check local first
    $local_intersects = \array_intersect($account_orgs->local, $oc_info->is_org_oc);
    $result = !empty($local_intersects);
    if ($result) {
      return $result;
    }

    // Still nothing, this time checks top-level
    $global_intersects = \array_intersect($account_orgs->global, $oc_info->is_group_oc);
    $result = !empty($global_intersects);
    if ($result) {
      return $result;
    }

    // Still nothing. Last chance is that it is an event operational contact. In this case
    // we check that it is the correct event and that they both have the same top-level
    // First make sure that the event_sfid is in the list
    if (empty($event_sfid) || !in_array($event_sfid, $oc_info->is_event_oc)) {
      return FALSE;
    }

    // Now check the orgs of the logged in account
    $logged_orgs = $this->getOrganizations($logged_sfid);
    $event_global_intersects = array_intersect($logged_orgs->global, $account_orgs->global);
    return (!empty($event_global_intersects));
  }

  /**
   * Returns TRUE if the logged in account is a group, local, or event OC for $event_id
   * EventID will only be evaluated if $event_sfid is provided and non-null
   *
   * @param type $event_sfid
   * @return boolean
   */
  public function isEventGroupLocalOC($event_sfid = NULL) {
    $oc_info = $this->getOCInfo();

    if (!empty($oc_info->is_group_oc) || !empty($oc_info->is_org_oc)) {
      return TRUE;
    }

    return (in_array($event_sfid, $oc_info->is_event_oc));
  }

  /**
   * Returns TRUE if we consider that the logged in person can *manage* $account_sfid
   * You cannot manage yourself (i.e. logged_in == account_sfid) unless you are
   * a group, local or event OC (so no personal)
   *
   * @param type $account_sfid
   * @return boolean
   */
  public function consideredAsOC($account_sfid, $event_sfid = NULL) {
    $logged_sfid = $this->getLoggedInAccount();
    if (!$account_sfid || ($logged_sfid == $account_sfid)) {
      return $this->isEventGroupLocalOC($event_sfid);
    }
    return $this->canManageAccount($account_id, $event_id);
  }

  /////////// Logged in account ////////////////////

  /**
   * Sets the logged in account as $accound_sfid
   *
   * @param type $account_sfid
   */
  public function setLoggedInAccount($account_sfid) {
    $this->_logged_sfid = $account_sfid;
  }

  /**
   * Retrieves the logged in account
   *
   * @return type
   */
  public function getLoggedInAccount() {
    return $this->_logged_sfid;
  }

  /**
   * Finds the logged in account as set in the header
   *
   * @param Request $request
   * @return string
   */
  protected function findLoggedInAccount(Request $request) {
    $account_sfid = NULL;
    $jwtSer = $request->headers->get('__jwt_accountid');
    if (!empty($jwtSer)) {
//      $jwt = unserialize($jwtSer);
      $this->wd->watchdog('RegHelper', 'Got JWT as @jwt', ['@jwt' => print_r($jwtSer, TRUE)]);
      $account_sfid = $jwtSer;
    }
    if (is_null($account_sfid) && !$this->isProdEnvironment()) {
      $defaultAccountId = '001b0000002mW4lAAE';

      $this->wd->watchdog('RegHelper', 'JWT not available, fallback to default account @a', ['@a' => $defaultAccountId]);
      $account_sfid = $defaultAccountId;
    }
    return $account_sfid;
  }

  /////////// Operational Contact Information ////////////////////

  /**
   * Sets the oc_info as $oc_info
   *
   * @param type $oc_info
   */
  public function setOCInfo($oc_info) {
    $this->_oc_info = $oc_info;
  }

  /**
   * Retrieves the operational contact info
   *
   * @return type
   */
  public function getOCInfo() {
    return $this->_oc_info;
  }

  /**
   * Get Operational Contact Info from header
   *
   * @param Request $request
   * @return type
   */
  protected function findOCInfo(Request $request) {
    $result = new \stdClass();
    $result->is_group_oc = unserialize($request->headers->get('__is_group_oc', 'a:0:{}'));
    $result->is_event_oc = unserialize($request->headers->get('__is_event_oc', 'a:0:{}'));
    $result->is_org_oc = unserialize($request->headers->get('__is_org_oc', 'a:0:{}'));
    $result->is_personal_oc = unserialize($request->headers->get('__is_personal_oc', 'a:0:{}'));

    return $result;
  }

  public function getCCToken($inbound_hash, $fields) {
    // Get the needed variables
    $saferpay_url = (isset($_ENV['CC_PROVIDER_URL']) ? $_ENV['CC_PROVIDER_URL'] : NULL);
    $saferpay_username = (isset($_ENV['CC_PROVIDER_USERNAME']) ? $_ENV['CC_PROVIDER_USERNAME'] : NULL);
    $saferpay_pwd = (isset($_ENV['CC_PROVIDER_PWD']) ? $_ENV['CC_PROVIDER_PWD'] : NULL);
    $saferpay_custId = (isset($_ENV['CC_CUSTOMER_ID']) ? $_ENV['CC_CUSTOMER_ID'] : NULL);

    if (!$saferpay_pwd || !$saferpay_username || !$saferpay_url || !$saferpay_custId) {
      return ['error' => TRUE, 'response' => NULL, 'errorMessage' => 'Missing Credit Card Provider information ' . $saferpay_url];
    }

    // Build payload for Saferpay
    $payload = array(
      'RequestHeader' => array(
        'SpecVersion' => "1.6",
        'CustomerId' => $saferpay_custId,
        'RequestId' => $inbound_hash,
        'RetryIndicator' => 0,
      ),
      'PaymentMeans' => array(
        'Card' => array(
          'Number' => $fields['cc_number'],
          'ExpYear' => (int)$fields['exp_year'],
          'ExpMonth' => (int)$fields['exp_month'],
          'HolderName' => $fields['cc_cardholder'],
          'VerificationCode' => $fields['cc_verifcode'],
        ),
      ),
      'RegisterAlias' => array(
        'IdGenerator' => "RANDOM"
      )
    );

    $payload_json = json_encode($payload);
    $this->wd->watchdog('RegHelper', 'Payload json is: @p', ['@p' => $payload_json]);
    $curl = curl_init($saferpay_url . 'Payment/v1/Alias/InsertDirect');
    curl_setopt($curl, CURLOPT_HEADER, FALSE);
    //Return Response to Application
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    //Set Content-Headers to JSON
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
      "Content-type: application/json",
      "Accept: application/json"
    ));
    // Execute call via http-POST
    curl_setopt($curl, CURLOPT_POST, TRUE);
    //Set POST-Body
    //convert DATA-Array into a JSON-Object
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload_json);

    // Following option should NOT be "false"
    // Otherwise the connection is not secured
    // You can turn it of if you're working on the test-system with no vital data

    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->isProdEnvironment());
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    //HTTP-Basic Authentication for the Saferpay JSON-API
    curl_setopt($curl, CURLOPT_USERPWD, $saferpay_username . ":" . $saferpay_pwd);
    //CURL-Execute & catch response
    $jsonResponse = curl_exec($curl);
    //Get HTTP-Status
    //Abort if Status != 200
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (!in_array($status, array(200, 201, 204))) {
      return ['error' => TRUE, 'sent_data' => print_r($payload, TRUE), 'response' => $jsonResponse, 'errorMessage' => $status . ': ' . curl_error($curl)];
    }
    //Close connection
    curl_close($curl);
    //Convert response into an Array
    $response = json_decode($jsonResponse, true);

    return ['error' => FALSE, 'response' => $response, 'errorMessage' => ''];
  }

  /**
   * Saves user form
   *
   * @param type $event_id
   * @param type $account_id
   * @param type $inbound_hash
   * @param type $action
   * @param type $options
   */
  public function userSaveForm($event_id, $account_id, $inbound_hash, $action, $inbound_id = NULL, $fields = [], $options = []) {
    $operational_contact_id =  NULL;
    $form_type = 'main'; // Will be overridden later
    $data = $this->loadJsonAndCache($event_id, $account_id, $operational_contact_id, ['inboundHash' => $inbound_hash, 'inboundId' => $inbound_id]);

    $success = FALSE;
    if ($data['success']) {
      // We may have a new $inbound_hash now

      if (!empty($inbound_hash)) {
        // We got the data from inbound_id, now make sure all vars are what was
        // in the cache
        if (isset($data['cacheData'])) {
          $event_id = $data['cacheData']['event_sfid'];
          $account_id = $data['cacheData']['account_sfid'];
          $operational_contact_id = $data['cacheData']['operational_contact_sfid'];
          $inbound_id = $data['cacheData']['inbound_id'];
          $inbound_hash = $data['cacheData']['inbound_hash'];
          $form_type = $data['cacheData']['form_type'];
        }
        else {
          $this->wd->watchdog('userSaveForm', 'For form @ih, cacheData was not found', Array('@ih' => $inbound_hash));
        }
      }
      else {
        // We don't have an inbound_hash, we have an issue here
        return $this->app->json(['error' => TRUE, 'data_sent' => '', 'return' => '', 'errorMessage' => '[saveRegform] No inbound hash present, aborting save']);
      }
      if (!empty($event_id)) {
        $json = $data['payload'];
        // Try to decode json content
        //$this->wd->watchdog('userSaveForm', 'Raw fields @f', array('@f' => $request->get('fields')));
//        $this->wd->watchdog('userSaveForm', 'Got fields @f', array('@f' => print_r($fields, TRUE)));

        // Try to find paymentToken if available
        $paymentToken = (isset($options['paymentToken']) ? $options['paymentToken'] : '');

        $params = Array();
        $params['metadata'] = $json->metadata;
        // Make sure charter is Accepted if required. You cannot reach this point if you don't accept the charter anyway
        if (property_exists($json, 'charter')) {
          $params['metadata']->charterAccepted = TRUE;
        }
        // Check to see if we got a paymentToken. If yes insert it in the payload
        if (!empty($paymentToken)) {
          $params['metadata']->paymentToken = $paymentToken;
        }
        $params['metadata']->action = $action;
        $params['fields'] = (empty($fields) ? Array() : $fields);

        if (0 == strcasecmp($action, 'cancel')) {
          // Decline the regform and return nothing
          // Make sure we have the right data
          if (empty($event_id)) {
            $event_id = $json->metadata->eventId;
          }
          if (empty($account_id)) {
            $account_id = $json->metadata->constituentId;
          }
          if (!empty($event_id)) {
            $return = $this->declineRegform($event_id, $account_id);
            $data_returned = json_decode($return->data);
            if (!$data_returned->success) {
              return $this->app->json(['error' => TRUE, 'data_sent' => ['account_sfid' => $account_id, 'event_sfid' => $event_id], 'return' => NULL, 'result' => $return, 'errorMessage' => $data_returned->error]);
            }
            else {
              $this->invalidateCache($inbound_hash);
              return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => $return, 'result' => $json, 'errorMessage' => NULL]);
            }
          }
        }
        elseif (0 == strcasecmp($action, 'delete')) {
          // Make sure we have the right to delete this form
          if (in_array($json->status, ['Not Complete', 'New'])) {
            // Yes we are allowed to delete
            $data_to_send = $this->myjson_encode($params);
            if ($this->deleteRegForm($inbound_hash, $data_to_send)) {
              return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => '', 'result' => '', 'errorMessage' => NULL]);
            }
            return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => '', 'result' => '', 'errorMessage' => '[saveRegform] Impossible to delete the form with the given parameters']);
          }
          return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => '', 'result' => '', 'errorMessage' => '[saveRegform] Status not compatible with delete (' . $json->result->status . ')']);
        }
  //      try {
  //        $return = $this->declineRegform($event_id, $account_id);
  //        $data = $this->loadJsonAndCache($event_id, $account_id, NULL, ['force' => TRUE, 'inboundHash' => $inbound_hash]);
  //
  //        if ($data['success']) {
  //          $json = $data['payload'];
  //          return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => $return, 'result' => $json, 'errorMessage' => NULL]);
  //        }
  //        else {
  //          // This is not really an error, let the caller take care of this case
  //          return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => $return, 'result' => NULL, 'errorMessage' => NULL]);
  //        }
  //      }
  //      catch (Exception $e) {
  //        return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => 'Impossible to decline registration form', 'errorMessage' => $e->getMessage()]);
  //      }
        // Check params to send to make sure they are valid
//        $this->wd->watchdog('userSaveForm', 'Got params @p', array('@p' => print_r($params, TRUE)));
        $errors = $this->check_fields($json, $params['fields'], $params['metadata']->action);
        $this->wd->watchdog('userSaveForm', 'After checking fields, found @c errors', array('@c' => count($errors)));
        if (0 == count($errors)) {
          // Check limits
          $errors = $this->check_limits($json, $params['metadata']->action);
        }
        if (0 == count($errors)) {
  //        $this->wd->watchdog('userSaveForm', 'Before json_encode');
          try {
            $data_to_send = $this->myjson_encode($params);
  //          $this->wd->watchdog('userSaveForm', 'json encoded correctly');
            $return = $this->saveRegform($data_to_send);
            $success = TRUE;
          }
          catch (\Exception $e) {
            // We got an error, return it
            return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => '[saveRegform] Impossible to save the form with the given parameters', 'errorMessage' => $data['errorMessage']]);
          }
//          $data_saved = json_decode($return->data, TRUE);
          $data_saved = $return['payload'];
          $this->wd->watchdog('userSaveForm', 'For form @ih, got back return @d', Array('@ih' => $inbound_hash, '@d' => print_r($return, TRUE)));

          if (empty($data_saved['isSuccess'])) {
            $this->wd->watchdog('RegServicesController', 'Failed #1 to save form for @c at @e (inbound @i) with msg : @m', Array(
              '@c' => $account_id,
              '@e' => $event_id,
              '@i' => $inbound_id,
              '@m' => $data_saved['message']), Watchdog::ERROR);

            return $this->app->json(['error' => TRUE, 'return' => $data_saved, 'params' => $data_saved, 'errorMessage' => $data_saved['message']]);
          }

          // Update the cache if necessary
          $inbound_id = (isset($data_saved['inboundDataId']) ? $data_saved['inboundDataId'] : NULL);
          $this->updateCacheInboundId($inbound_hash, $inbound_id);
          $this->invalidateCache($inbound_hash);
          // If we are not saving the main form, invalidate cache of the parent
          $this->wd->watchdog('userSaveForm', 'Invalidate parent and sibling cache for hash @ih', Array('@ih' => $inbound_hash));
          $this->invalidateParentSiblingCache($inbound_hash);
//          $this->invalidateParentCache($inbound_hash);

          // Now force a reload data
          $data = $this->loadJsonAndCache($event_id, $account_id, $operational_contact_id, ['force' => TRUE, 'inboundId' => $inbound_id, 'inboundHash' => $inbound_hash]);

          if ($data['success']) {
            $json = $data['payload'];
            return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => $return, 'result' => $json, 'errorMessage' => NULL]);
          }
          else {
            // This is not really an error, let the caller take care of this case
            return $this->app->json(['error' => FALSE, 'data_sent' => $params, 'return' => $return, 'result' => NULL, 'errorMessage' => NULL]);
          }
        }
        else {
          // We got errors, return them
          $this->wd->watchdog('RegServicesController', 'Failed #2 to save form for @c at @e (inbound @i) with errors : @err', Array(
            '@c' => $account_id,
            '@e' => $event_id,
            '@i' => $inbound_id,
            '@err' => print_r($errors, TRUE)), Watchdog::ERROR);
          return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => NULL, 'regform' => NULL, 'errorMessage' => $errors]);
        }
      }
      else {
        $this->wd->watchdog('RegServicesController', 'Event_id is NULL for hash @i', ['@i' => $inbound_hash] );
      }
    }
    else {
      $this->wd->watchdog('RegServicesController', 'Impossible to load from cache (inbound @i)', ['@i' => $inbound_hash] );
    }
    if (!$success) {
      return $this->app->json(['error' => TRUE, 'data_sent' => $params, 'return' => '[loadJsonAndCache] Impossible to get the JSON file with the given parameters', 'errorMessage' => $data['errorMessage']]);
    }
  }

  /**
   * Decode a masquerade token, only return it if valid:
   *   -> User who called is the same user that generated the token
   *   -> Token has been generated less than 6h ago
   *   -> Role reg_ro_all is present
   *
   * @param type $token_to_decode
   * @return type
   */
  public function decodeToken($token_to_decode) {
    $crypto = new ExtCrypto($this->app);
    $decrypted = $crypto->decrypt($token_to_decode);
    $token = [];
    if ($decrypted) {
      $values = explode('|', $decrypted);
      if (3 == count($values)) {
        $token = [
          'account_sfid' => $values[0],
          'time' => $values[1],
          'expired' => (time() - $values[1] > 6*3600),
          'roles' => explode(',', $values[2])
        ];
      }
    }

    // If decoding fails we fail as well
    if (empty($token)) {
      return NULL;
    }

    // Check that token is not expired
    if (isset($token['expired']) && $token['expired']) {
      return NULL;
    }

    // Make sure token was generated by the same person calling us
    $logged_user = $this->getLoggedInAccount();
    if ($logged_user != $token['account_sfid']) {
      return NULL;
    }

    // Check that we have the correct role
    if (!\array_intersect($token['roles'], (array)$_ENV['REG_MASQUERADE_ROLES'])) {
      return NULL;
    }

    $token['valid'] = TRUE;
    return $token;
  }
}
