<?php
$app = require __DIR__ . '/../app/app.php';

if ($app instanceof Silex\Application) {
  App\Test\V1\ObbServicesControllerTest::run($app);
} else {
  echo 'Failed to initialize application.';
}
