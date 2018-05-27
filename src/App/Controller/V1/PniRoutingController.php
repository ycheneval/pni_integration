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
    $controllers->post('/album', __NAMESPACE__ . '\PniServicesController::album');

    //Got command
    $controllers->post('/got', __NAMESPACE__ . '\PniServicesController::got');

    //Missing command
    $controllers->post('/missing', __NAMESPACE__ . '\PniServicesController::missing');

    //Stats command
    $controllers->post('/stats', __NAMESPACE__ . '\PniServicesController::stats');

    //Find command
    $controllers->post('/find', __NAMESPACE__ . '\PniServicesController::find');

    //Exchange command
    $controllers->post('/exchange', __NAMESPACE__ . '\PniServicesController::exchange');

    //Totrade command
    $controllers->post('/totrade', __NAMESPACE__ . '\PniServicesController::totrade');

    //Traded command
    $controllers->post('/traded', __NAMESPACE__ . '\PniServicesController::traded');

    //Watch command
    $controllers->post('/watch', __NAMESPACE__ . '\PniServicesController::watch');

    //Sticker command
    $controllers->post('/sticker', __NAMESPACE__ . '\PniServicesController::sticker');


//        $controllers["cors-enabled"]($controllers);
    $app->options("{anything}", function () {
      return new \Symfony\Component\HttpFoundation\JsonResponse(null, 204);
    })->assert("anything", ".*");

    return $controllers;
  }

}
