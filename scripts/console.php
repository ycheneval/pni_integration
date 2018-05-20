<?php
use Symfony\Component\HttpFoundation\Request;

$app = require __DIR__ . '/../app/app.php';

if ($app instanceof Silex\Application) {
  list($_, $method, $path) = $argv;
  error_log('Console mode : ' . $method . ' ' .$path);
  $request = Request::create($path, $method);
  $app->run($request);
} else {
  echo 'Failed to initialize application.';
}
