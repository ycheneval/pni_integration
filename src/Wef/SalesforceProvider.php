<?php

namespace Wef;

use Silex\Application;
use Silex\ServiceProviderInterface;

class SalesforceProvider implements ServiceProviderInterface
{
    const WSDL_DIR = 'resources/Force.com/';

    public function register(Application $app)
    {
      $sf_api = new SalesforceApi($app);
      $app['sf'] = $sf_api;
    }

    public function boot(Application $app){}

}
