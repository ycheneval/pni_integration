<?php
namespace App\Test\V1;

use App\Controller\V1\BilateralHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of ObbServicesControllerTest
 *
 * @author BMU
 */
class ObbServicesControllerTest {
  
  protected static $EVENT_SFID      = NULL; 
  protected static $ACCOUNT_PF_SFID = NULL; 
  protected static $ACCOUNT_B1_SFID = NULL; 
  protected static $ACCOUNT_B2_SFID = NULL; 
  
  protected static $app = NULL;

  protected static function setVars(){
    self::$EVENT_SFID       = self::$app['db']->getSetting('OBB_SERVICES_EVENT_SFID');
    self::$ACCOUNT_PF_SFID  = self::$app['db']->getSetting('OBB_SERVICES_ACCOUNT_PF_SFID');
    self::$ACCOUNT_B1_SFID  = self::$app['db']->getSetting('OBB_SERVICES_ACCOUNT_B1_SFID');
    self::$ACCOUNT_B2_SFID  = self::$app['db']->getSetting('OBB_SERVICES_ACCOUNT_B2_SFID');
    
    return self::$EVENT_SFID && self::$ACCOUNT_PF_SFID && self::$ACCOUNT_B1_SFID && self::$ACCOUNT_B2_SFID;
  }

  public static function run($app){
    self::$app = $app;
    set_time_limit(4*2*60);
    //3 tests a waiting 2 minutes the coding of the role to SF
    $sleep = 2*60;
    $err = [];
    
    if( self::setVars() ){
      try{
        if( $errors = self::testB2BProcess($sleep) ){
          $err['testB2BProcess'] = $errors;
        }
      }
      catch(\Exception $e){
        $err['testB2BProcess'][] = 'Catching Exception : ' . $e->getMessage();
      }
      
      try{
        if( $errors = self::testB2PFProcess1($sleep) ){
          $err['testB2PFProcess1'] = $errors;
        }
      }
      catch(\Exception $e){
        $err['testB2BProcess'][] = 'Catching Exception : ' . $e->getMessage();
      }
      
      try{
        if( $errors = self::testB2PFProcess2($sleep) ){
          $err['testB2PFProcess2'] = $errors;
        }
      }
      catch(\Exception $e){
        $err['testB2BProcess'][] = 'Catching Exception : ' . $e->getMessage();
      }
    }
    else{
      $err = ['variables for the regressions tests are not set'];
    }
    

    
    if($err){
      $message = \Swift_Message::newInstance()
          ->setSubject('['.$_SERVER['HTTP_HOST'].'] Regressions Test failed! Please investigate!')
          ->setFrom(array('benoit.mugnier@weforum.org'))
          ->setTo(array('obb_devteam@weforum.org'))
          ->setBody(print_r($err, TRUE));

      $app['mailer']->send($message);
      
      error_log('ObbServicesControllerTest sent failed ' . count($err) . ' items!');
    }
    else{
      error_log('ObbServicesControllerTest has run successfully!');
    }
        
    return $app->json(['debug' => ['db' => $app['db']->getDebugInfo(), 'sf' => $app['sf']->getDebugInfo()]]);
  }
  
  /**
   * Create & book the meeting
   * Invite Participants
   * Cancel the meeting
   */
  protected static function testB2BProcess($sleep){
    $errors = [];
    
    //Get a timeslot
    $duration = rand()%2==0?30:15;
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_PF_SFID . '/' . self::$ACCOUNT_B1_SFID, 'GET', ['duration' => $duration]);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());
    
    if( empty($data->result) 
        || empty($data->result->guest)
        || empty($data->result->host)
        || empty($data->result->slots) ){
      
      $errors[] = ['message' => 'Retrieving timeslots failed', 'data' => $data]; 
      return $errors;
    }
    
    $day = array_rand((array)$data->result->slots);
    $slots = $data->result->slots->$day;
    $o_slot = $slots[array_rand($slots)];
    
    if( empty($o_slot->start) 
        || empty($o_slot->end) ){
      $errors[] = ['message' => 'Retrieving timeslots failed (data)', 'data' => $data]; 
      return $errors;
    }
    
    $slot = str_replace(':', '', substr($o_slot->start, 0, 16) . '-' . substr($o_slot->end, 11, 6));

    //Create & book the meeting
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_PF_SFID . '/' . self::$ACCOUNT_B1_SFID, 'POST', ['slot' => $slot, 'action' => 'create_bilateral']);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());
    
    if( empty($data->result->session)
        || empty($data->result->session->session_sfid)
        || $data->result->session->session_status != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
        || $data->result->session->session_start != $o_slot->start
        || $data->result->session->session_end != $o_slot->end
        || empty($data->result->session->session_room)
        || count($data->result->session->roles) != 2 ){
      $errors[] = ['message' => 'Bilateral creation failed (data)', 'data' => $data]; 
      return $errors;
    }
    
    
    $rows = self::$app['sf']->query( "SELECT "
            . "                         Event__c, "
            . "                         Id, "
            . "                         End_Date_Time_sortable__c, "
            . "                         RecordType.DeveloperName, "
            . "                         Status__c, "
            . "                         Start_Date_Time_sortable__c, "
            . "                         TECH_Room_Name__c "
            . "                       FROM "
            . "                         Session__c "
            . "                       WHERE"
            . "                         Id = '" . $data->result->session->session_sfid . "'");
    
    if( $session = array_shift($rows) ){
      if( $session['Status__c']                       != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
          || $session['Event__c']                     != self::$EVENT_SFID
          || $session['RecordType']['DeveloperName']  != 'Bilateral'
          || str_replace('/', '-', $session['Start_Date_Time_sortable__c'] . ':00') != $o_slot->start
          || str_replace('/', '-', $session['End_Date_Time_sortable__c'] . ':00') != $o_slot->end
          || empty($session['TECH_Room_Name__c']) ){
        $errors[] = ['message' => 'Bilateral is not in SF (data)', 'data' => $session]; 
      }
    }
    else{
      $errors[] = ['message' => 'Bilateral is not in SF', 'data' => $data]; 
    }
        
    if( $session_sfid = $data->result->session->session_sfid ){
      //Invite Participants
      $participant_sfids = [self::$ACCOUNT_B2_SFID];
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'POST', ['session_participants' => implode(',', $participant_sfids)]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->result->session) 
          || count($data->result->session->roles) != 3 ){
        $errors[] = ['message' => 'Invite Participants failed', 'data' => $data]; 
      }
      
      sleep($sleep);
      
      $roles = self::$app['sf']->query( "SELECT "
              . "                         Id, "
              . "                         Session_Bilateral_Participant_Role__c, "
              . "                         RecordType.DeveloperName, "
              . "                         Constituent__c, "
              . "                         Person_Bilateral_Participant_Role__c, "
              . "                         Type__c, "
              . "                         Status__c "
              . "                       FROM "
              . "                         Role__c "
              . "                       WHERE"
              . "                         Session__c = '" . $data->result->session->session_sfid . "'");

      if( count($roles) == 3 ){
        foreach($roles as $role){
          if( $role['Status__c']                                    != 'Confirmed'
              || $role['RecordType']['DeveloperName']            != 'Bilateral_Participant'
              || $role['Session_Bilateral_Participant_Role__c']  != $session_sfid
              || $role['Constituent__c']                         != $role['Person_Bilateral_Participant_Role__c']
              || !( ( $role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_HOST && $role['Constituent__c'] == self::$ACCOUNT_PF_SFID ) 
                      || ($role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_GUEST && $role['Constituent__c'] == self::$ACCOUNT_B1_SFID )
                      || ($role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_IN_THE_PRESENCE_OF && $role['Constituent__c'] == self::$ACCOUNT_B2_SFID )) ){
            $errors[] = ['message' => 'Roles are not in SF (data)', 'data' => $role]; 
          }
        }
      }
      else{
        $errors[] = ['message' => 'Roles are not in SF', 'data' => $roles]; 
      }
      
      //Cancel the meeting
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'POST', ['session_status' => BilateralHelper::BILATERAL_SESSION_STATUS_CANCELLED]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->result->session) 
          || !in_array($data->result->session->session_status, [BilateralHelper::BILATERAL_SESSION_STATUS_CANCELLED, BilateralHelper::BILATERAL_SESSION_STATUS_DELETED]) ){
        $errors[] = ['message' => 'Cancel the meeting failed', 'data' => $data]; 
      }
      
      $rows = self::$app['sf']->query( "SELECT "
              . "                         Status__c "
              . "                       FROM "
              . "                         Session__c "
              . "                       WHERE"
              . "                         Id = '" . $session_sfid . "'");

      if( $session = array_shift($rows) ){
        if( !in_array($session['Status__c'], [BilateralHelper::BILATERAL_SESSION_STATUS_CANCELLED, BilateralHelper::BILATERAL_SESSION_STATUS_DELETED]) ){
          $errors[] = ['message' => 'Cancel the meeting failed in SF (data)', 'data' => $session]; 
        }
      }
      else{
        $errors[] = ['message' => 'Cancel the meeting failed in SF', 'data' => $rows]; 
      }
      
      self::$app['sf']->delete('Session__c', $session_sfid);
    }
    
    return $errors;
  }
  
  /**
   * Create a request
   * Decline the request
   */
  protected static function testB2PFProcess1($sleep){
    $errors = [];
    
    //Get timeslots to build host availabilities
    $duration = rand()%2==0?30:15;
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_PF_SFID . '/' . self::$ACCOUNT_B1_SFID, 'GET', ['duration' => $duration]);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());
    
    if( empty($data->result) 
        || empty($data->result->guest)
        || empty($data->result->host)
        || empty($data->result->slots) ){
      
      $errors[] = ['message' => 'Retrieving timeslots failed', 'data' => $data]; 
      return $errors;
    }
    
    $slots = [];
    for($i = 0; $i < rand(1,10) ; $i++){
      $day = array_rand((array)$data->result->slots);
      $t_slots = $data->result->slots->$day;
      $o_slot = $t_slots[array_rand($t_slots)];

      if( empty($o_slot->start) 
          || empty($o_slot->end) ){
        $errors[] = ['message' => 'Retrieving timeslots failed (data)', 'data' => $data]; 
        return $errors;
      }

      $slots[] = str_replace(':', '', substr($o_slot->start, 0, 16) . '-' . substr($o_slot->end, 11, 6));
    }

    //Create a request
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_B2_SFID . '/' . self::$ACCOUNT_PF_SFID, 'POST', ['slots' => implode(',', $slots), 'action' => 'request_bilateral']);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());
    
    if( empty($data->result->session)
        || empty($data->result->session->session_sfid)
        || $data->result->session->session_status != BilateralHelper::BILATERAL_SESSION_STATUS_PENDING_REQUEST
        || count($data->result->session->roles) != 2 ){
      $errors[] = ['message' => 'Bilateral request creation failed (data)', 'data' => $data]; 
      return $errors;
    }

    $rows = self::$app['sf']->query( "SELECT "
            . "                         Event__c, "
            . "                         Id, "
            . "                         End_Date_Time_sortable__c, "
            . "                         RecordType.DeveloperName, "
            . "                         Status__c, "
            . "                         Start_Date_Time_sortable__c, "
            . "                         TECH_Room_Name__c "
            . "                       FROM "
            . "                         Session__c "
            . "                       WHERE"
            . "                         Id = '" . $data->result->session->session_sfid . "'");
    
    if( $session = array_shift($rows) ){
      if( $session['Status__c']                       != BilateralHelper::BILATERAL_SESSION_STATUS_PENDING_REQUEST
          || $session['Event__c']                     != self::$EVENT_SFID
          || $session['RecordType']['DeveloperName']  != 'Bilateral' ){
        $errors[] = ['message' => 'Bilateral request creation failed in SF (data)', 'data' => $session]; 
      }
    }
    else{
      $errors[] = ['message' => 'Bilateral request creation failed in SF', 'data' => $data]; 
    }
    
    sleep($sleep);
    
    $roles = self::$app['sf']->query( "SELECT "
            . "                         Id, "
            . "                         Session_Bilateral_Participant_Role__c, "
            . "                         RecordType.DeveloperName, "
            . "                         Constituent__c, "
            . "                         Person_Bilateral_Participant_Role__c, "
            . "                         Type__c, "
            . "                         Status__c "
            . "                       FROM "
            . "                         Role__c "
            . "                       WHERE"
            . "                         Session__c = '" . $data->result->session->session_sfid . "'");

    if( count($roles) == 2 ){
      foreach($roles as $role){
        if( $role['Status__c']                                    != 'Confirmed'
            || $role['RecordType']['DeveloperName']            != 'Bilateral_Participant'
            || $role['Session_Bilateral_Participant_Role__c']  != $data->result->session->session_sfid
            || $role['Constituent__c']                         != $role['Person_Bilateral_Participant_Role__c']
            || !( ( $role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_HOST && $role['Constituent__c'] == self::$ACCOUNT_B2_SFID ) 
                    || ($role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_GUEST && $role['Constituent__c'] == self::$ACCOUNT_PF_SFID ) ) ){
          $errors[] = ['message' => 'Roles are not in SF (data)', 'data' => $role]; 
        }
      }
    }
    else{
      $errors[] = ['message' => 'Roles are not in SF', 'data' => $roles]; 
    }
    
    if( $session_sfid = $data->result->session->session_sfid ){
      //Decline the request
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'POST', ['session_status' => BilateralHelper::BILATERAL_SESSION_STATUS_REQUEST_DECLINED]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->error) 
          || $data->error != 'No session' ){
        $errors[] = ['message' => 'Declining the request failed', 'data' => $data]; 
      }
      
      $rows = self::$app['sf']->query( "SELECT "
              . "                         Status__c "
              . "                       FROM "
              . "                         Session__c "
              . "                       WHERE"
              . "                         Id = '" . $session_sfid . "'");

      if( $session = array_shift($rows) ){
        if( $session['Status__c'] != BilateralHelper::BILATERAL_SESSION_STATUS_REQUEST_DECLINED ){
          $errors[] = ['message' => 'Declining the request failed in SF (data)', 'data' => $session]; 
        }
      }
      else{
        $errors[] = ['message' => 'Declining the request failed in SF', 'data' => $rows]; 
      }
      self::$app['sf']->delete('Session__c', $session_sfid);
    }
    
    return $errors;
  }
  
  
  /**
   * Create a request
   * Book the meeting
   * Update the booking
   */
  protected static function testB2PFProcess2($sleep){
    $errors = [];
    
    //Get timeslots to build host availabilities
    $duration = rand()%2==0?30:15;
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_PF_SFID . '/' . self::$ACCOUNT_B1_SFID, 'GET', ['duration' => $duration]);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());
    
    if( empty($data->result) 
        || empty($data->result->guest)
        || empty($data->result->host)
        || empty($data->result->slots) ){
      
      $errors[] = ['message' => 'Retrieving timeslots failed 1', 'data' => $data]; 
      return $errors;
    }
    
    $slots = [];
    for($i = 0; $i < rand(1,10) ; $i++){
      $day = array_rand((array)$data->result->slots);
      $t_slots = $data->result->slots->$day;
      $o_slot = $t_slots[array_rand($t_slots)];

      if( empty($o_slot->start) 
          || empty($o_slot->end) ){
        $errors[] = ['message' => 'Retrieving timeslots failed 1 (data)', 'data' => $data]; 
        return $errors;
      }

      $slots[] = str_replace(':', '', substr($o_slot->start, 0, 16) . '-' . substr($o_slot->end, 11, 6));
    }

    //Create a request
    $subRequest = Request::create('/api/V1/obb/session-create/' . self::$EVENT_SFID . '/' . self::$ACCOUNT_B1_SFID . '/' . self::$ACCOUNT_PF_SFID, 'POST', ['slots' => implode(',', $slots), 'action' => 'request_bilateral']);
    $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    $data = json_decode($response->getContent());

    if( empty($data->result->session)
        || empty($data->result->session->session_sfid)
        || $data->result->session->session_status != BilateralHelper::BILATERAL_SESSION_STATUS_PENDING_REQUEST
        || count($data->result->session->roles) != 2 ){
      $errors[] = ['message' => 'Bilateral request creation failed (data)', 'data' => $data]; 
      return $errors;
    }

    $rows = self::$app['sf']->query( "SELECT "
            . "                         Event__c, "
            . "                         Id, "
            . "                         End_Date_Time_sortable__c, "
            . "                         RecordType.DeveloperName, "
            . "                         Status__c, "
            . "                         Start_Date_Time_sortable__c, "
            . "                         TECH_Room_Name__c "
            . "                       FROM "
            . "                         Session__c "
            . "                       WHERE"
            . "                         Id = '" . $data->result->session->session_sfid . "'");
    
    if( $session = array_shift($rows) ){
      if( $session['Status__c']                       != BilateralHelper::BILATERAL_SESSION_STATUS_PENDING_REQUEST
          || $session['Event__c']                     != self::$EVENT_SFID
          || $session['RecordType']['DeveloperName']  != 'Bilateral' ){
        $errors[] = ['message' => 'Bilateral request creation failed in SF (data)', 'data' => $session]; 
      }
    }
    else{
      $errors[] = ['message' => 'Bilateral request creation failed in SF', 'data' => $data]; 
    }
    
    sleep($sleep);
    
    $roles = self::$app['sf']->query( "SELECT "
            . "                         Id, "
            . "                         Session_Bilateral_Participant_Role__c, "
            . "                         RecordType.DeveloperName, "
            . "                         Constituent__c, "
            . "                         Person_Bilateral_Participant_Role__c, "
            . "                         Type__c, "
            . "                         Status__c "
            . "                       FROM "
            . "                         Role__c "
            . "                       WHERE"
            . "                         Session__c = '" . $data->result->session->session_sfid . "'");

    if( count($roles) == 2 ){
      foreach($roles as $role){
        if( $role['Status__c']                                    != 'Confirmed'
            || $role['RecordType']['DeveloperName']            != 'Bilateral_Participant'
            || $role['Session_Bilateral_Participant_Role__c']  != $data->result->session->session_sfid
            || $role['Constituent__c']                         != $role['Person_Bilateral_Participant_Role__c']
            || !( ( $role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_HOST && $role['Constituent__c'] == self::$ACCOUNT_B1_SFID ) 
                    || ($role['Type__c'] == BilateralHelper::BILATERAL_SESSION_ROLE_GUEST && $role['Constituent__c'] == self::$ACCOUNT_PF_SFID ) ) ){
          $errors[] = ['message' => 'Roles are not in SF (data)', 'data' => $role]; 
        }
      }
    }
    else{
      $errors[] = ['message' => 'Roles are not in SF', 'data' => $roles]; 
    }
    
    if( $session_sfid = $data->result->session->session_sfid ){
      //Get Slots
      $duration = rand()%2==0?30:15;
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'GET', ['duration' => $duration]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->result) 
          || empty($data->result->slots) ){

        $errors[] = ['message' => 'Retrieving timeslots failed 2', 'data' => $data]; 
        return $errors;
      }

      $day = array_rand((array)$data->result->slots);
      $slots = $data->result->slots->$day;
      $o_slot = $slots[array_rand($slots)];

      if( empty($o_slot->start) 
          || empty($o_slot->end) ){
        $errors[] = ['message' => 'Retrieving timeslots failed 2 (data)', 'data' => $data]; 
        return $errors;
      }

      $slot = str_replace(':', '', substr($o_slot->start, 0, 16) . '-' . substr($o_slot->end, 11, 6));

      
      //Book the meeting
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'POST', ['slot' => $slot]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->result->session)
          || empty($data->result->session->session_sfid)
          || $data->result->session->session_status != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
          || $data->result->session->session_start != $o_slot->start
          || $data->result->session->session_end != $o_slot->end
          || empty($data->result->session->session_room)
          || count($data->result->session->roles) != 2 ){
        $errors[] = ['message' => 'Request confirmation failed (data)', 'data' => $data]; 
      }
      
      $rows = self::$app['sf']->query( "SELECT "
              . "                         Event__c, "
              . "                         Id, "
              . "                         End_Date_Time_sortable__c, "
              . "                         RecordType.DeveloperName, "
              . "                         Status__c, "
              . "                         Start_Date_Time_sortable__c, "
              . "                         TECH_Room_Name__c "
              . "                       FROM "
              . "                         Session__c "
              . "                       WHERE"
              . "                         Id = '" . $session_sfid . "'");

      if( $session = array_shift($rows) ){
        if( $session['Status__c']                       != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
            || $session['Event__c']                     != self::$EVENT_SFID
            || $session['RecordType']['DeveloperName']  != 'Bilateral'
            || str_replace('/', '-', $session['Start_Date_Time_sortable__c'] . ':00') != $o_slot->start
            || str_replace('/', '-', $session['End_Date_Time_sortable__c'] . ':00') != $o_slot->end
            || empty($session['TECH_Room_Name__c']) ){
          $errors[] = ['message' => 'Request confirmation failed in SF (data)', 'data' => $session]; 
        }
      }
      else{
        $errors[] = ['message' => 'Request confirmation failed in SF', 'data' => $data]; 
      }
      
      //Get Slots
      $duration = rand()%2==0?30:15;
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'GET', ['duration' => $duration]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());

      if( empty($data->result) 
          || empty($data->result->slots) ){

        $errors[] = ['message' => 'Retrieving timeslots failed 3', 'data' => $data]; 
        return $errors;
      }

      $day = array_rand((array)$data->result->slots);
      $slots = $data->result->slots->$day;
      $o_slot = $slots[array_rand($slots)];

      if( empty($o_slot->start) 
          || empty($o_slot->end) ){
        $errors[] = ['message' => 'Retrieving timeslots failed 3 (data)', 'data' => $data]; 
        return $errors;
      }

      $slot = str_replace(':', '', substr($o_slot->start, 0, 16) . '-' . substr($o_slot->end, 11, 6));
      
      //Update the booking
      $subRequest = Request::create('/api/V1/obb/session-update/' . $session_sfid, 'POST', ['slot' => $slot]);
      $response = self::$app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
      $data = json_decode($response->getContent());
      
      if( empty($data->result->session)
          || empty($data->result->session->session_sfid)
          || $data->result->session->session_status != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
          || $data->result->session->session_start != $o_slot->start
          || $data->result->session->session_end != $o_slot->end
          || empty($data->result->session->session_room)
          || count($data->result->session->roles) != 2 ){
        $errors[] = ['message' => 'Request confirmation failed (data)', 'data' => $data]; 
      }
      
      $rows = self::$app['sf']->query( "SELECT "
              . "                         Event__c, "
              . "                         Id, "
              . "                         End_Date_Time_sortable__c, "
              . "                         RecordType.DeveloperName, "
              . "                         Status__c, "
              . "                         Start_Date_Time_sortable__c, "
              . "                         TECH_Room_Name__c "
              . "                       FROM "
              . "                         Session__c "
              . "                       WHERE"
              . "                         Id = '" . $session_sfid . "'");

      if( $session = array_shift($rows) ){
        if( $session['Status__c']                       != BilateralHelper::BILATERAL_SESSION_STATUS_OPEN
            || $session['Event__c']                     != self::$EVENT_SFID
            || $session['RecordType']['DeveloperName']  != 'Bilateral'
            || str_replace('/', '-', $session['Start_Date_Time_sortable__c'] . ':00') != $o_slot->start
            || str_replace('/', '-', $session['End_Date_Time_sortable__c'] . ':00') != $o_slot->end
            || empty($session['TECH_Room_Name__c']) ){
          $errors[] = ['message' => 'Booking update failed in SF (data)', 'data' => $session]; 
        }
      }
      else{
        $errors[] = ['message' => 'Booking update failed in SF', 'data' => $data]; 
      }
      
      self::$app['sf']->delete('Session__c', $session_sfid);
    }
    
    return $errors;
  }
}
