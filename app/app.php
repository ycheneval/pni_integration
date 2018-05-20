<?php

use Silex\Application;
use Silex\Provider\HttpCacheServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\ServiceControllerServiceProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use \Firebase\JWT\JWT;
use Wef\Watchdog;

require_once __DIR__ . '/../vendor/autoload.php';
define('ROOT_DIR', __DIR__ . '/../');
include __DIR__ . '/config.php';


define('PG_FUNC_DIR', ROOT_DIR . '/resources/SQL/functions/');

ini_set('soap.wsdl_cache_enabled', '0');

if (!empty($_ENV['PHP_MEMORY_LIMIT'])) {
  ini_set('memory_limit', $_ENV['PHP_MEMORY_LIMIT']);
}

// Initialize Application
$app = new Silex\Application($config);

$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app['swiftmailer.options'] = array(
  'host' => $config['swiftmailer.options.host'],
  'port' => $config['swiftmailer.options.port'],
  'username' => $config['swiftmailer.options.username'],
  'password' => $_ENV[$config['swiftmailer.options.password.env']],
  'encryption' => null,
  'auth_mode' => null,
);

$app->error(function ($e, $code) use($app) {
  $traces = '';
  foreach ($e->getTrace() as $trace) {
    $args = [];
    foreach ($trace['args'] as $arg) {
      $args[] = is_scalar($arg) ? $arg : (is_null($arg) ? 'NULL' : gettype($arg));
    }
    $traces .= (empty($trace['file']) ? '' : $trace['file']) . ':' . (empty($trace['line']) ? '' : $trace['line']) . ' ' . $trace['function'] . ' (' . implode(', ', $args) . ')<br>';
  }

  $message = \Swift_Message::newInstance()
    ->setSubject('[' . $_SERVER['HTTP_HOST'] . '] Exception')
    ->setFrom(array('yves.cheneval@weforum.org'))
    ->setTo(array('yves.cheneval@weforum.org'))
    ->setBody($e->getFile() . ':' . $e->getLine() . ' ' . $e->getMessage() . '<br><br>' . $traces . '<br><br>' . print_r($_SERVER, TRUE), 'text/html');

  $app['mailer']->send($message);

  return $app->json(['error' => $e->getMessage()]);
});

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => $app['monolog.logfile'],
  'monolog.level' => $app['monolog.level'],
));

// Register the Twig templating engine
//$app->register(new Silex\Provider\TwigServiceProvider(), array(
//  'twig.path' => $app['twig.path'],
//  'twig.options' => $app['twig.options'],
//));

$app->register(new Wef\DatabaseProvider());

if (0 != \strcasecmp('prod', $_ENV['RUNNING_ENV'])) {
  $app['db']->setDebugLevel(1);
}

//var_dump('SET search_path = ' . $_ENV['HEROKUCONNECT_SCHEMA'] . ', public;');
$app['db']->exec('SET search_path = ' . $_ENV['APP_SCHEMA'] . ', public;');


$app->register(new Wef\AccessProvider());
//$app->register(new Wef\SalesforceProvider());

$app->register(new Silex\Provider\SecurityServiceProvider(), array(
  'security.firewalls' => array(
    'default' => array(
      'http' => true,
      'pattern' => 'tbb/|tools/',
      'users' => $app['access_provider']('default'),
    ),
  ),
));

set_time_limit(30);
//var_dump($app['security.encoder.digest']->encodePassword('secret', ''));die();
// Map routes to controllers
include __DIR__ . '/routing.php';

// Check jWT
$app->before(function (Request $request, Application $app) {
  // Check if we need to have jWT
  $needs_jWT = needsjWT($app);
  if (!$needs_jWT) {
    // Grant access
    return;
  }
  $jWT = substr($request->headers->get('Authorization'), 7);
  if (empty($jWT)) {
    //return new RedirectResponse('/unauthorized');
    $response = new Response();
    $response->setStatusCode(401, 'No Authorization');
    return $response;
  }
  // Decode jWT
  $jwt_key = $_ENV['JWT_SECRET'];
  $jwt_algorithm = [$_ENV['JWT_ALGORITHM']];
  try {
    $wd = new Watchdog($app);

//    $wd->watchdg('__bootstrap', 'Got JWT as @jwt', ['@jwt' => $jWT]);
    $jwt = JWT::decode($jWT, $jwt_key, $jwt_algorithm);
//    $wd->watchdog('__bootstrap', 'Got decoded JWT as @jwt', ['@jwt' => print_r($jwt, TRUE)]);
//    $request->headers->set('__jwt', ));
    $request->headers->set('__jwt', serialize($jwt));
    $request->headers->set('__jwt_accountid', $jwt->user->salesforce_id);
    $wd->watchdog('__bootstrap', 'Account JWT as @jwt', ['@jwt' => $request->headers->get('__jwt_accountid')]);
    $wd->watchdog('__bootstrap', 'Request @req', ['@req' => print_r($request->headers, TRUE)]);
//    $request->headers->set('__is_group_oc', serialize($oc_info->is_group_oc));
//    $request->headers->set('__is_event_oc', serialize($oc_info->is_event_oc));
//    $request->headers->set('__is_org_oc', serialize($oc_info->is_org_oc));
//    $request->headers->set('__is_personal_oc', serialize($oc_info->is_personal_oc));
//
//    $wd->watchdog('__bootstrap', 'OC Info for @a is @oci', ['@a' => $jwt->user->salesforce_id, '@oci' => print_r($oc_info, TRUE)]);
  }
  catch (\Exception $e) {
    //return new RedirectResponse('/unauthorized');
    $response = new Response();
    $response->setStatusCode(401, 'No Authorization');
    return $response;
  }
}, Application::EARLY_EVENT);

// CORS Setup. Also worth noting that should modify the routing to authorize the OPTIONS calls
// http://jamesowers.co.uk/2016/06/28/silex-cors-preflight-access-control-allow-origin.html
$app->after(function (Request $request, Response $response) {
  $response->headers->set('Access-Control-Allow-Origin', '*');
  $response->headers->set('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, tl-jwt-dev');
  $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
});

return $app;


function needsjWT(Application $app) {
  // Check the app
  return FALSE;

}
