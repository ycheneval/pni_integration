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

  public function album(Request $request, Application $app) {
//    $ph = new PniHelper($request, $app);
    $message = [
      'response_type' => 'ephemeral',
      'text' => "A new album has been setup:",
    ];
    return $app->json($message);
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
