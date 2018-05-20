<?php

namespace Wef;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class SessionProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
      $app->register(new \Silex\Provider\SessionServiceProvider);

      $app['session.storage.handler'] = $app->share(function () use ($app) {
          return new PdoSessionHandler(
              $app['db'],
              ['db_table' => $_ENV['APP_SCHEMA'] . '.user_sessions', 'db_id_col' => 'session_id', 'db_data_col' => 'session_value', 'db_time_col' => 'session_time'],
              ['name' => '_TBB', 'cookie_lifetime' => 0]
          );
      });
    }

    public function boot(Application $app){}
}