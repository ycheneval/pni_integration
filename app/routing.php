<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This file should be included from app.php, and is where you hook
 * up routes to controllers.
 *
 * @link http://silex.sensiolabs.org/doc/usage.html#routing
 */

$app->get('/', function() use ($app) {
  return $app->json('hello');
});


$app->mount('/api/V1/pni', new App\Controller\V1\PniRoutingController());
$app->mount('/api/V1/tools', new App\Controller\V1\ToolsRoutingController());


$app->finish(function () use ($app) {
  global $_SERVER;
  flush();
  if(function_exists('fastcgi_finish_request')){
    fastcgi_finish_request();
  }
  \Wef\Logger::run($app, $_SERVER);
});
