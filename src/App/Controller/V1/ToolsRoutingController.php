<?php

namespace App\Controller\V1;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class ToolsRoutingController implements ControllerProviderInterface {

  public function connect(Application $app) {
    // creates a new controller based on the default route
    $controllers = $app['controllers_factory'];

    $controllers->get('/', function() use ($app) {
      return $app->json('tools');
    });


    // Return watchdog
    $controllers->get('/watchdog', __NAMESPACE__ . '\ToolsServicesController::getWatchdog');

//        $controllers["cors-enabled"]($controllers);
    $app->options("{anything}", function () {
      return new \Symfony\Component\HttpFoundation\JsonResponse(null, 204);
    })->assert("anything", ".*");

    return $controllers;
  }

}
