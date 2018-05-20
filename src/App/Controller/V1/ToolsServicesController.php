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
class ToolsServicesController {

  /**
   * Get watchdogs
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Silex\Application $app
   * @return type
   */
  public function getWatchdog(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $options = [
      'wids' => htmlentities($request->get('wids')),
      'last' => htmlentities($request->get('last')),
      'search' => htmlentities($request->get('search')),
      'since' => htmlentities($request->get('since')),
      'app' => htmlentities($request->get('prefix')),
    ];
    foreach ($options as $key => $value) {
      if (empty($value)) {
        unset($options[$key]);
      }
    }
    $wids_str = $wd->getWatchdog($options);
    return $app->json(['count' => count($wids_str), 'result' => $wids_str]);
  }


}
