<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Admin;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use \Wef\OAuthSalesforce;

/**
 * Description of TbbAdminController
 *
 * @author BMU
 */
class TbbAdminController {
  const TBB_ROOMTYPE_BUSINESS = 'Business';
  const TBB_ROOMTYPE_PF = 'Public Figures';
  const TBB_ROOMTYPE_IP = 'IP Lounge';

  public static function login(Request $request, Application $app){
    if( $redirect = $request->get('redirect') ){
      $app['session']->set('redirect', $redirect);
    }
    return $app->redirect(OAuthSalesforce::getUrlForSfAuthorizationCode());
  }

  public static function authorize(Request $request, Application $app){
    if( $request->get('pass', '') ){
      $app['session']->set('authorizing', TRUE);
      return $app->redirect(OAuthSalesforce::getUrlForSfAuthorizationCode());
    }
    else{
      return '<h1>Authorize should be done with a specific user (heroku.api) to enforce some validation rules!!!!!!!</h1>'
             .'<p style="color:red;">As you are already authenticated on Salesforce with your own account to see this page, open <a href="./authorize?pass=TRUE">this link</a> in a new incognito tab to continue.</p>'
             .'<p>Add ?pass=TRUE to the end of this URL to continue.';
    }
  }

  public static function salesforceOAuthCallback(Request $request, Application $app){
    $oauth = new OAuthSalesforce($app);

    if( !$code = $request->get('code', FALSE) ){
      throw new \Exception('No code');
    }

    $b_authorize = $app['session']->get('authorizing');

    $data = $oauth->processAuthorizationCode($code, $b_authorize);

    if($b_authorize){
      $app['session']->set('authorizing', NULL);
      return $app->json([
          'success' => TRUE,
          'IMPORTANT' => 'Authorize should be done with a specific user (heroku.api) to enforce some validation rules!!!!!!!',
          'sf_url' => $app['db']->getSetting('sf_url'),
          'sf_refresh_token' => $app['db']->getSetting('sf_refresh_token'),
          'test' => $app['sf']->query("SELECT COUNT(Id) FROM Session__c"),
          'debug' => $app['db']->getDebugInfo()]);
    }

    list($instance_url, $access_token) = $data;

    $curl = curl_init($instance_url . '/services/oauth2/userinfo?format=json');
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, empty($_ENV['DEV']));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: OAuth " . $access_token));

    $json_response = curl_exec($curl);
    curl_close($curl);

    $userinfo = json_decode($json_response, true);

    if(!$userinfo['organization_id']){
      throw new \Exception("Error - User has not organization!");
    }
    else if( substr($userinfo['organization_id'], 0, 15) != substr($_ENV['SALESFORCE_ORGANIZATION_ID'], 0, 15) ){
      throw new \Exception("Error - Organization is not allowed!");
      die();
    }


    if(!$userinfo['active']){
      throw new \Exception("Error - User not active!");
    }

    $app['session']->set('user', ['access_token' => $access_token, 'user_sfid' => $userinfo['user_id'], 'user_name' => $userinfo['name']]);

    if( $redirect = $app['session']->get('redirect') ){
      $app['session']->set('redirect', NULL);
      return $app->redirect($redirect);
    }

    return $app->redirect('./booking');
  }

  public static function getUserInfo(Request $request, Application $app){
    return $app->json(['data' => $app['session']->get('user'), 'debug' => $app['db']->getDebugInfo()]);
  }

  public static function searchParticipants(Request $request, Application $app){
    $event_sfid = $request->get('event_sfid');
    $search = $request->get('q');
    $host_sfid = $request->get('host_sfid');
    $guest_sfid = $request->get('guest_sfid');
    $registered = $app['db']->getCollection(" SELECT ap.fullnametext__c || ' (' || CASE WHEN ao.name IS NULL THEN 'N/A' ELSE ao.name END || ')' as name, ap.sfid as person_sfid, ao.sfid as org_sfid
                                              FROM opportunity o
                                              INNER JOIN account ap ON (ap.sfid = o.accountid)
                                              LEFT JOIN position__c p ON (p.sfid = o.position__c)
                                              LEFT JOIN account ao ON (ao.sfid = p.organization__c)
                                              WHERE o.event__c = " . $app['db']->quote($event_sfid) . "
                                              AND o.stagename = 'Closed/Registered'
                                              AND ap.fullnametext__c ILIKE " . $app['db']->quote('%' . $search . '%') . "
                                              AND ap.sfid NOT IN (" . $app['db']->quote($host_sfid) . "," . $app['db']->quote($guest_sfid) . ")
                                              ORDER BY ap.fullnametext__c");

    $return = ['incomplete_results' => false, 'items' => [], 'debug' => $app['db']->getDebugInfo()];

    foreach($registered as $person){
      $return['items'][] = ['id' => $person['person_sfid'], 'name' => $person['name']];
    }

    $return['total_count'] = count($return['items']);

    return $app->json($return);
  }

  /**
   * Get the meeting default duration of the slots, either '15m' or '20m'
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Silex\Application $app
   * @return type
   */
  public static function getDefaultDuration(Request $request, Application $app) {
    $bh = new \App\Controller\V1\BilateralHelper($app);
    $event_sfid = $request->get('event_sfid');
    $return = [];
    $return['_duration'] = $bh->getDefaultDuration($event_sfid);
    return $app->json($return);
  }

  public static function loadTimesAvailability(Request $request, Application $app){
    $bh = new \App\Controller\V1\BilateralHelper($app);

    $event_sfid = $request->get('event_sfid');
    $host_sfid = $request->get('host_sfid', '');
    $guest_sfid = $request->get('guest_sfid', '');
    $duration = $request->get('duration', 15);
    $room_type = $request->get('room_type', '');

    if (empty($host_sfid) || empty($guest_sfid)) {
      // In this case, we preset $room_type and $quota_type to the user choice
      $room_type = (empty($room_type) ? self::TBB_ROOMTYPE_BUSINESS . '|' . self::TBB_ROOMTYPE_PF : $room_type);
      $quota_type = ''; // Do not display any quota
    }
    else {
      if(!$room_type) {
        // Type of rooms. By default we say it's 'Business', if we find anyone is a
        // public figure, we switch
        $room_type = self::TBB_ROOMTYPE_BUSINESS;
        $quota_type = self::TBB_ROOMTYPE_BUSINESS;

        $query = "SELECT a.sfid, a.salutation || ' ' || a.fullnametext__c as name, o.identified_as_public_figure__c, q.pf, q.sp
                  FROM account a
                  INNER JOIN opportunity o ON (o.accountid = a.sfid AND o.event__c = " . $app['db']->quote($event_sfid) . ")
                  LEFT JOIN registration_qa.obb_bilateral_quota q ON (o.event__c = q.event__c)
                  WHERE a.sfid IN (" . $app['db']->quote($host_sfid) . ", " . $app['db']->quote($guest_sfid) . ");";

        foreach ($app['db']->getCollection($query) as $account) {
          if ($account['identified_as_public_figure__c']) {
            $room_type = self::TBB_ROOMTYPE_PF;
          }
          if (0 == \strcmp($guest_sfid, $account['sfid'])) {
            // For the count, what we are interested in is the identified as public figure of the guest
            $quota_type = ($account['identified_as_public_figure__c'] ? self::TBB_ROOMTYPE_PF : self::TBB_ROOMTYPE_BUSINESS);
          }
        }
      }
    }

    // Find the correct count
    switch ($quota_type) {
      case self::TBB_ROOMTYPE_BUSINESS:
        $b_is_public_figure = FALSE;
        $limit_bilat_counts = $bh->getBilateralQuotas($event_sfid);
        $limit_count = $limit_bilat_counts[0];
        $current_count = $bh->getBilateralCount($event_sfid, $host_sfid, $b_is_public_figure);
        $display_text = $current_count . ' bookings done (quota ' . $limit_count . ')';
        $display_quota = TRUE;
        break;

      case self::TBB_ROOMTYPE_PF:
        $b_is_public_figure = TRUE;
        $limit_bilat_counts = $bh->getBilateralQuotas($event_sfid);
        $limit_count = $limit_bilat_counts[1];
        $current_count = $bh->getBilateralCount($event_sfid, $host_sfid, $b_is_public_figure);
        $display_text =  $current_count . ' requests done (quota ' . $limit_count . ')';
        $display_quota = TRUE;
        break;

      default:
        // Don't check anything on the IP Lounge, this does *not* count in the quota
        $display_quota = FALSE;
        $current_count = -1;
        $limit_count = -1;
        $display_text = 'N/A';
        break;
    }

    $return = [
      'quotas' => ['count' => $current_count, 'limit' => $limit_count, 'display' => $display_quota, 'display_text' => $display_text],
      'slots' => [],
    ];

    $room_name = $bh->room_type_to_name($room_type);
    $data = $bh->getAllTimeSlots($event_sfid, $duration, $room_name);
    foreach($data as $day => $slots){
      $ts = strtotime($day);
      $return['slots'][date('d/m/Y l', $ts)] = [];
      foreach($slots as $slot){
        $ts2 = strtotime($slot['start']);
        $return['slots'][date('d/m/Y l', $ts)][$slot['start']] = date('H:i', $ts2);
      }
    }
    return $app->json($return);
  }

  public static function loadRoomsAvailability(Request $request, Application $app){
    $bh = new \App\Controller\V1\BilateralHelper($app);

    $event_sfid = $request->get('event_sfid');
    $host_sfid = $request->get('host_sfid');
    $guest_sfid = $request->get('guest_sfid');
    $duration = $request->get('duration', 15);
    $datetime = $request->get('datetime');
    $room_type = $request->get('room_type', '');

    if (empty($room_type)) {
      if (empty($host_sfid) || empty($guest_sfid)) {
        $room_type = self::TBB_ROOMTYPE_BUSINESS . '|' . self::TBB_ROOMTYPE_PF;
      }
      else {
        $room_type = self::TBB_ROOMTYPE_BUSINESS;

        $query = "SELECT a.sfid, a.salutation || ' ' || a.fullnametext__c as name, o.identified_as_public_figure__c, q.pf, q.sp
                  FROM account a
                  INNER JOIN opportunity o ON (o.accountid = a.sfid AND o.event__c = " . $app['db']->quote($event_sfid) . ")
                  LEFT JOIN registration_qa.obb_bilateral_quota q ON (o.event__c = q.event__c)
                  WHERE a.sfid IN (" . $app['db']->quote($host_sfid) . ", " . $app['db']->quote($guest_sfid) . ");";

        foreach ($app['db']->getCollection($query) as $account) {
          if ($account['identified_as_public_figure__c']) {
            $room_type = self::TBB_ROOMTYPE_PF;
          }
        }
      }
    }

    $bilateral_room = array();
    $public_rooms = array();
    $room_name = $bh->room_type_to_name($room_type);

    $query = "SELECT * FROM obb_sel_room_by_slot(" . $app['db']->quote($event_sfid) . ", " . $app['db']->quote($datetime) . ", " .
        $app['db']->quote($duration) . ", " . $app['db']->quote("{" . str_replace('|', ',', $room_name) . "}") . "::character varying[])";
    $return = '';
    foreach($app['db']->getCollection($query) as $room){
      $bilateral_room[$room['room_id']] = $room['room_name'];
      $return .= '<option value="' . $room['room_id'] . '">' . $room['room_name'] . ' (' . $room['out_room_type'] . ')' . '</option>';
    }

    // add public rooms
    if(strpos($room_type, '|') !== FALSE){
      $public_rooms = $bh->getPublicRooms($event_sfid, self::TBB_ROOMTYPE_BUSINESS) + $bh->getPublicRooms($event_sfid, self::TBB_ROOMTYPE_PF);
    }
    else{
      $public_rooms = $bh->getPublicRooms($event_sfid, $room_type);
    }
    foreach($public_rooms as $public_rooms_id => $public_rooms_name){
      if(array_key_exists($public_rooms_id, $bilateral_room)) continue;
      $return .= '<option value="' . $public_rooms_id . '">' . $public_rooms_name . ' (' . $room['out_room_type'] . ')' . '</option>';
    }

    return $return;
  }

  public static function bookBilateral(Request $request, Application $app){
    $bh = new \App\Controller\V1\BilateralHelper($app);

    $event_sfid = $request->get('event_sfid');
    $host_sfid = $request->get('host_sfid');
    $guest_sfid = $request->get('guest_sfid');
    $duration = $request->get('duration', 15);
    $datetime = $request->get('datetime');
    $room = $request->get('room');
    $description = $request->get('description');
    $context = $request->get('context');

    if($event_sfid && $host_sfid && $guest_sfid && $duration && $datetime && $room){
      $oDtStart = new \DateTime($datetime);
      $oDtEnd = clone $oDtStart;
      $oDtEnd->add(new \DateInterval('PT' . $duration . 'M'));

      $staffname = $app['session']->get('user')?$app['session']->get('user')['user_name']:'';
      $options = [];
      $options['rationale_guest'] = 'This meeting has been created by ' . $staffname;
      $options['rationale_host']  = 'This meeting has been created by ' . $staffname;
      $options['slot']            = $oDtStart->format('Y-m-d Hi') . '-' . $oDtEnd->format('Hi');
      $options['room_sfid']       = $room;
      $options['location']        = $room;
      $options['description']     = $description;
      $options['context']         = $context;
      $options['action']          = 'create_bilateral_internal';
      $options['duration']        = $duration;

      try{
        $session_sfid = $bh->createSessionInDb($event_sfid, $host_sfid, $guest_sfid, $options);
        $osc = new \App\Controller\V1\ObbServicesController();
        $request->attributes->set('session_sfid', $session_sfid);
        return $osc->getDetailSession($request, $app);
      }
      catch (Exception $e){
        return $app->json(['error' => $e->getMessage(), 'debug' => $app['db']->getDebugInfo()]);
      }
    }
    return $app->json(['error' => 'Some information is missing', 'debug' => $app['db']->getDebugInfo()]);
  }

  public static function booking(Request $request, Application $app){
    $return = self::top();

    $events = $app['db']->getCollection("SELECT e.sfid, e.name, e.programmes_start_date__c
                                        FROM event__c e
                                        INNER JOIN programme__c p ON (p.event__c = e.sfid AND p.type__c = 'Bilateral')
                                        WHERE programmes_end_date__c > NOW() - INTERVAL '1 WEEK' AND programmes_start_date__c <= NOW() + INTERVAL '6 MONTHS'
                                        GROUP BY e.id
                                        ORDER BY e.programmes_start_date__c ASC");


    $return .= '
        <div id="page-wrapper" class="staff-booking-create">
            <div class="row">
                <div class="col-lg-12">
                    <h1 class="page-header">Book a bilateral meeting</h1>
                </div>
                <!-- /.col-lg-12 -->
            </div>
            <!-- /.row -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            New bilateral meeting
                        </div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-lg-6">
                                  <form role="form">
                                    <div class="form-group">
                                        <label>Event</label>
                                        <select class="form-control event_sfid" name="event_sfid">
                                          <option value="">- -</option>';
    foreach($events as $event){
      $return .= '<option value="' . $event['sfid'] . '" ' . (count($events)==1?'SELECTED':'') . ' >' . $event['name'] . '</option>';
    }
    $return .= '
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Host of the bilateral</label>
                                        <select class="form-control person-picker" name="host_sfid">
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Guest of the bilateral</label>
                                        <select class="form-control person-picker" name="guest_sfid">
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Quota</label>
                                        <span id="quota_text"></span>
                                    </div>
                                    <div class="form-group">
                                        <label>Meeting duration</label>
                                        <label class="radio-inline">
                                            <input type="radio" name="duration" id="duration1" value="15" checked>15 minutes
                                        </label>
                                        <label class="radio-inline" id="duration30m">
                                            <input type="radio" name="duration" id="duration2"value="30">30 minutes
                                        </label>
                                        <label class="radio-inline" id="duration20m">
                                            <input type="radio" name="duration" id="duration3" value="20">20 minutes
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label>Room Type</label>
                                        <label class="radio-inline">
                                            <input type="radio" name="room_type" value="" checked>SP/PF
                                        </label>
                                        <label class="radio-inline">
                                            <input type="radio" name="room_type" value="ip_ggc">Partners/Forum Members (ex GGCs)
                                        </label>
                                    </div>
                                    <div class="form-group ">
                                        <label>Date</label>
                                        <select class="form-control" name="date" disabled>
                                          <option>Available dates</option>
                                        </select>
                                    </div>
                                    <div class="form-group ">
                                        <label>Times</label>
                                        <select multiple class="form-control" name="datetime" style="height:100px;" disabled>
                                          <option>Available times</option>
                                        </select>
                                    </div>
                                    <div class="form-group second-step third-step">
                                        <label>Rooms</label>
                                        <select multiple class="form-control" name="room" style="height:150px;" disabled>
                                          <option>Available rooms</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label><br/>
                                        <textarea name="description" rows="5" class="form-control" maxlength="32767" placeholder="Please describe briefly the purpose of this bilateral meeting and the primary goals you seek to accomplish"></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Context</label><br/>
                                        <textarea name="context" rows="5" class="form-control" maxlength="32767" placeholder="Please provide a summary of the recent touch points of the Forum with this individual or their organization as context for the bilateral briefing document"></textarea>
                                    </div>
                                    <button type="button" class="btn btn-primary submit" disabled>Reserve a room</button>
                                  </form>
                                </div>
                                <div class="col-lg-6 tbb-result">
                                    <div class="panel panel-info">
                                        <div class="panel-heading">
                                            Bilateral booking
                                        </div>
                                        <div class="panel-body">
                                            <p>Complete the form on the left to create a new bilateral session.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- /.col-lg-6 -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      ';


    return $return . self::bottom();
  }

  public static function head(){
    return '
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Toplink Bilateral Tool</title>

    <!-- Bootstrap Core CSS -->
    <link href="../resources/bower_components/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- MetisMenu CSS -->
    <link href="../resources/bower_components/metisMenu/dist/metisMenu.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="../resources/dist/css/sb-admin-2.css" rel="stylesheet">

    <!-- Custom Fonts -->
    <link href="../resources/bower_components/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link href="../resources/select2/dist/css/select2.min.css" rel="stylesheet" />

</head>

<body>';


  }

  public static function top(){
    return self::head() . '

    <div id="wrapper">

        <!-- Navigation -->
        <nav class="navbar navbar-default navbar-static-top" role="navigation" style="margin-bottom: 0">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="index.html">Toplink Bilateral Booking</a>
            </div>
            <!-- /.navbar-header -->

            <ul class="nav navbar-top-links navbar-right">

                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                        <i class="fa fa-user fa-fw"></i>  <i class="fa fa-caret-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-user">
                        <!--<li><a href="#"><i class="fa fa-user fa-fw"></i> User Profile</a>
                        </li>
                        <li><a href="#"><i class="fa fa-gear fa-fw"></i> Settings</a>
                        </li>
                        <li class="divider"></li>-->
                        <li><a href="./logout"><i class="fa fa-sign-out fa-fw"></i> Logout</a>
                        </li>
                    </ul>
                    <!-- /.dropdown-user -->
                </li>
                <!-- /.dropdown -->
            </ul>
            <!-- /.navbar-top-links -->

            <div class="navbar-default sidebar" role="navigation">
                <div class="sidebar-nav navbar-collapse">
                    <ul class="nav" id="side-menu">
                        <li>
                            <a href="./booking"><i class="fa fa-dashboard fa-fw"></i> Book a meeting</a>
                        </li>
                    </ul>
                </div>
                <!-- /.sidebar-collapse -->
            </div>
            <!-- /.navbar-static-side -->
        </nav>';
  }

  public static function bottom(){
    return '
    </div>
    <!-- /#wrapper -->

    <!-- jQuery -->
    <script src="../resources/bower_components/jquery/dist/jquery.min.js"></script>

    <!-- Bootstrap Core JavaScript -->
    <script src="../resources/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

    <!-- Metis Menu Plugin JavaScript -->
    <script src="../resources/bower_components/metisMenu/dist/metisMenu.min.js"></script>

    <!-- Custom Theme JavaScript -->
    <script src="../resources/dist/js/sb-admin-2.js"></script>

    <script src="../resources/select2/dist/js/select2.min.js"></script>

    <script src="../resources/js/main.js"></script>
</body>

</html>
';
  }

  public static function deploy(Request $request, Application $app){
    $return = self::top();

    $get_functions_in_files_fn = function() use ($app){

      $Directory = new \RecursiveDirectoryIterator(PG_FUNC_DIR);
      $Iterator = new \RecursiveIteratorIterator($Directory);
      $Regex = new \RegexIterator($Iterator, '#^.+\.sql$#i', \RecursiveRegexIterator::GET_MATCH);

      $fns = [];
      foreach($Regex as $file){
        if( $content = file_get_contents($file[0]) ){
          if( preg_match('#-- Function:.*$#', $content, $match) ){
            $fn = str_replace(array_key_exists('PG_DEPLOY_SPECIFIC_SCHEMA', $_ENV)?$_ENV['PG_DEPLOY_SPECIFIC_SCHEMA']:'salesforcetraining2', $_ENV['APP_SCHEMA'], trim($match[0]));
            $fns[basename($file[0])] = $fn;
          }

        }
        if( $f = fopen($file[0], 'r') ){
          $line = fgets($f);
          $fns[basename($file[0])] = str_replace('salesforcetraining2.', '', substr($line, strpos($line, ':') +1));
          fclose($f);
        }
      }
      asort($fns);
      return $fns;
    };


    $get_functions_in_db_fn = function() use ($app){
      $functions = $app['db']->getCollection("SELECT routines.specific_name, routines.routine_name, parameters.data_type::text, parameters.ordinal_position
                                          FROM information_schema.routines
                                          JOIN information_schema.parameters ON routines.specific_name=parameters.specific_name
                                          WHERE routines.specific_schema='salesforcetraining2' AND routines.routine_name NOT LIKE 'hc_%' AND routines.data_type != 'trigger'  AND parameters.parameter_mode = 'IN'
                                          ORDER BY routines.routine_name, parameters.ordinal_position;");


      $tmp = [];
      foreach($functions as $function){
        if( !array_key_exists($function['specific_name'], $tmp) ){
          $tmp[$function['specific_name']] = [];
        }
        if( !array_key_exists($function['routine_name'], $tmp[$function['specific_name']]) ){
          $tmp[$function['specific_name']][$function['routine_name']] = [];
        }
        $tmp[$function['specific_name']][$function['routine_name']][$function['ordinal_position']] = $function['data_type'];
      }


      $fns = [];
      foreach($tmp as $fn_sn => $_fn){
        foreach($_fn as $fn => $args){
          ksort($args);
          $fns [] = $fn . '(' . implode(', ', $args) . ')';
        }
      }
      sort($fns);
      return $fns;
    };

    $functions_in_db = $get_functions_in_db_fn();
    $functions_in_files = $get_functions_in_files_fn();

    $result = [];
    $b_was_in_transaction = FALSE;
    if( $request->get('action') == 'deploy' ){
      foreach(array_reverse($functions_in_files) as $filename => $fn){
        //Let's start without current transaction
        if ($app['db']->inTransaction()) {
          $app['db']->rollBack();
          $b_was_in_transaction = TRUE;
        }

        if( $content = file_get_contents(PG_FUNC_DIR . $filename) ){
          //Remove BOM marker
          $content = str_replace("\xEF\xBB\xBF",'',$content);

          //Start a new conection
          $app['db']->exec("BEGIN;");

          //Match & execute the Drop Command
          if( preg_match('#DROP FUNCTION.*;#', $content, $match) ){
            try{
              //Change the schema to the local env
              $drop = str_replace(array_key_exists('PG_DEPLOY_SPECIFIC_SCHEMA', $_ENV)?$_ENV['PG_DEPLOY_SPECIFIC_SCHEMA']:'salesforcetraining2', $_ENV['APP_SCHEMA'], $match[0]);
              $app['db']->exec($drop);
            } catch (\PDOException $ex) {
              //In case of the drop failed because the function doesn't exist, reopen a new transaction -> no error
              $app['db']->exec("ROLLBACK;");
              $app['db']->exec("BEGIN;");
            }
          }

          //Change the schema to the local env
          $content = str_replace(array_key_exists('PG_DEPLOY_SPECIFIC_SCHEMA', $_ENV)?$_ENV['PG_DEPLOY_SPECIFIC_SCHEMA']:'salesforcetraining2', $_ENV['APP_SCHEMA'], $content);

          //PHP Driver accepts only one DDL command, so remove the extras commands
          $content = preg_replace('#ALTER FUNCTION[^;]+;#m', '', $content);
          $content = preg_replace('#COMMENT ON FUNCTION.*$#m', '', $content);
          $content = preg_replace('#--.*$#m', '', $content);

          try{
            $key = $filename . ':' . $fn;
            $result[$key] = FALSE;

            // Import the function
            if( $app['db']->exec($content) ){
              $result[$key] = TRUE;
              // \o/
              $app['db']->exec("COMMIT;");
            }
            else{
              //Query failed, rollback anyway
              $result[$key] = 'ROLLBACK';
              $app['db']->exec("ROLLBACK;");
            }
          } catch (\PDOException $ex) {
            //Query failed, rollback anyway
            $result[$key] = $ex->getMessage() . $content;
            $app['db']->exec("ROLLBACK;");
          }
        }
      }

      //Refresh new data
      $functions_in_db = $get_functions_in_db_fn();
    }

    if($b_was_in_transaction){
      $app['db']->beginTransaction();
    }

    $items = [];
    $return .= '
        <div id="page-wrapper" class="staff-booking-create">
            <div class="row">
                <div class="col-lg-12">
                    <h1 class="page-header">Postgres functions</h1>
                </div>
                <!-- /.col-lg-12 -->
            </div>
            <!-- /.row -->
              <div class="row">
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            Items in files
                        </div>
                        <!-- /.panel-heading -->
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Function name</th>
                                        </tr>
                                    </thead>
                                    <tbody>';
    foreach($functions_in_files as $file => $fn){
      $key = $file . ':' . $fn;
      $message = '';
      $color = '';
      if(array_key_exists($key, $result)){
        if($result[$key] === TRUE){
          $message = ' --> OK';
          $color = 'green';
        }
        else if(!$result[$key]){
          $message = ' --> KO';
          $color = 'red';
        }
        else {
          $message = ' --> ' . $result[$key];
          $color = 'red';
        }
      }

      $return .= '                     <tr>
                                            <td style="color:'.$color.';" data-file="' . $file . '">' . $fn . $message . '</td>
                                        </tr>';
    }

    $return .= '
                                    </tbody>
                                </table>
                            </div>
                            <!-- /.table-responsive -->
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            Items in DB
                        </div>
                        <!-- /.panel-heading -->
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Function name</th>
                                        </tr>
                                    </thead>
                                    <tbody>';
    foreach($functions_in_db as $fn){
      $return .= '                     <tr>
                                            <td>' . $fn . '</td>
                                        </tr>';
    }

    $return .= '
                                    </tbody>
                                </table>
                            </div>
                            <!-- /.table-responsive -->
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                  </div>
                  <form><input type="submit" name="action" value="deploy"/></form><br><br><br><br>
        </div>
      ';

    return $return . self::bottom();
  }


  public static function retry(Request $request, Application $app){
    $return = self::top();

    $table = $request->get('retrytable', '_trigger_log');


    $items = $app['db']->getCollection("SELECT *
                                          FROM " . $table . "
                                          WHERE state = 'FAILED'
                                          ORDER BY created_at ASC");


    $return .= '
        <div id="page-wrapper" class="staff-booking-create">
            <div class="row">
                <div class="col-lg-12">
                    <h1 class="page-header">Retry</h1>
                </div>
                <!-- /.col-lg-12 -->
            </div>
            <!-- /.row -->
              <div class="row">
                <div class="col-lg-12">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            Items failed
                        </div>
                        <!-- /.panel-heading -->
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Log Id</th>
                                            <th>State</th>
                                            <th>Table</th>
                                            <th>Record id</th>
                                            <th>Action</th>
                                            <th>Message</th>
                                            <th>values</th>
                                            <th>date</th>
                                        </tr>
                                    </thead>
                                    <tbody>';
    foreach($items as $item){
      $return .= '                     <tr>
                                            <td>' . $item['id'] . '</td>
                                            <td>' . $item['state'] . '</td>
                                            <td>' . $item['table_name'] . '</td>
                                            <td>' . $item['record_id'] . '</td>
                                            <td>' . $item['action'] . '</td>
                                            <td>' . $item['sf_message'] . '</td>
                                            <td>' . $item['values'] . '</td>
                                            <td>' . $item['created_at'] . '</td>
                                        </tr>';
    }

    $return .= '
                                    </tbody>
                                </table>
                            </div>
                            <!-- /.table-responsive -->
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                  </div>
        </div>
      ';


    return $return . self::bottom();
  }

}
