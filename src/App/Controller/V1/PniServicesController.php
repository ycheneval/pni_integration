<?php

namespace App\Controller\V1;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use \DateTime;
use \DateInterval;
use Wef\SalesforceApi;
use Wef\Watchdog;
use Wef\Helpers;
use Wef\PGDb;
use Wef\ExtCrypto;

/**
 * DefaultController is here to help you get started.
 *
 * You would probably put most of your actions in other more domain specific
 * controller classes.
 *
 * Controllers are completely separated from Silex, any dependencies should be
 * injected through the constructor. When used with a smart controller resolver,
 * the Request object can be automatically added as an argument if you use type
 * hinting.
 *
 * @author Gunnar Lium <gunnar@aptoma.com>
 */
class PniServicesController {

  protected $error_msg = [
    'response_type' => 'ephemeral',
     'text' => "There was an error processing your command",
  ];

  public function album(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      return $app->json($error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $album_data = $ph->getAlbum($ph->input->params[0]);
    if (!$album_data['success']) {
      $error_msg['text'] .= ': Cannot find album';
      return $app->json($error_msg);
    }

    $wd->watchdog('notice', 'Found album @ad', ['@ad' => print_r($album_data, TRUE)]);
    $player_data = $ph->checkandCreateUser();
    if (!$player_data['success']) {
      $error_msg['text'] .= ': Impossible to find or create user';
      return $app->json($error_msg);
    }
    $wd->watchdog('notice', 'Found player @ad', ['@ad' => print_r($player_data, TRUE)]);
    $player_album = $ph->linkPlayerAlbum($player_data['player_id'], $album_data['payload']['id']);
    if ($player_album['success']) {
      return $app->json([
        'response_type' => 'ephemeral',
        'text' => 'Operation ' . $player_album['operation'] . ' performed successfully',
      ]);
    }

//    $wd = new Watchdog($app);
//    $wd->watchdog('warning', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
//    $app['monolog']->addWarning("This is a test!", (array)$request);
    return $app->json[$result];

  }

  public function got(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new got has been setup:",
    ];
    return $app->json($message);
  }

  public function missing(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new missing has been setup:",
    ];
    return $app->json($message);
  }

  public function stats(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new stats has been setup:",
    ];
    return $app->json($message);
  }

  public function find(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new find has been setup:",
    ];
    return $app->json($message);
  }

  public function exchange(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new echange has been setup:",
    ];
    return $app->json($message);
  }

  public function totrade(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new totrade has been setup:",
    ];
    return $app->json($message);
  }

  public function traded(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new traded has been setup:",
    ];
    return $app->json($message);
  }

  public function watch(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new watch has been setup:",
    ];
    return $app->json($message);
  }


}
