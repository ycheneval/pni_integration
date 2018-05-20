<?php

namespace Wef;

use Silex\Application;
use Silex\ServiceProviderInterface;

class DatabaseProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['db'] = new PGDb($app['db.dsn_from_env_var']);
    }

    public function boot(Application $app){}
}