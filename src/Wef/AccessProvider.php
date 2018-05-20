<?php

namespace Wef;

use Silex\Application;
use Silex\ServiceProviderInterface;

class AccessProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['access_provider'] = $app->protect(function ($name) use ($app) {
            return $this->retrieveAccess($name, $app['db']);
        });
    }

    public function boot(Application $app){}

	public function retrieveAccess($name, $db){
		$return = Array();
		foreach($db->getCollection('SELECT * FROM ' . $_ENV['APP_SCHEMA'] . '.access WHERE active=true') as $row){
			$return[$row['login']] = Array($row['roles'],$row['password']);
		}
		return $return;
	}
}