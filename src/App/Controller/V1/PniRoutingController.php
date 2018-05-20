<?php

namespace App\Controller\V1;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class PniRoutingController implements ControllerProviderInterface {

  public function connect(Application $app) {
    // creates a new controller based on the default route
    $controllers = $app['controllers_factory'];

    $controllers->get('/', function() use ($app) {
      return $app->json('pni');
    });

    //Album command
    $controllers->get('/album', __NAMESPACE__ . '\PniServicesController::album');

    //Got command
    $controllers->get('/got', __NAMESPACE__ . '\PniServicesController::got');

    //Missing command
    $controllers->get('/missing', __NAMESPACE__ . '\PniServicesController::missing');

    //Stats command
    $controllers->get('/stats', __NAMESPACE__ . '\PniServicesController::stats');

    //Find command
    $controllers->get('/find', __NAMESPACE__ . '\PniServicesController::find');

    //Exchange command
    $controllers->get('/exchange', __NAMESPACE__ . '\PniServicesController::exchange');

    //Totrade command
    $controllers->get('/totrade', __NAMESPACE__ . '\PniServicesController::totrade');

    //Traded command
    $controllers->get('/traded', __NAMESPACE__ . '\PniServicesController::traded');

    //Watch command
    $controllers->get('/watch', __NAMESPACE__ . '\PniServicesController::watch');


//        $controllers["cors-enabled"]($controllers);
    $app->options("{anything}", function () {
      return new \Symfony\Component\HttpFoundation\JsonResponse(null, 204);
    })->assert("anything", ".*");

    return $controllers;
  }

}
