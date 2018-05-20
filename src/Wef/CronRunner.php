<?php

namespace Wef;
use Silex\Application;

class CronRunner{
  public static function run(Application $app){
    $data = $app['db']->getCollection("SELECT *
                                      FROM _trigger_log
                                      WHERE state = 'FAILED'
                                      ORDER BY id DESC");

    if($data){
      $message = \Swift_Message::newInstance()
          ->setSubject('['.$_SERVER['HTTP_HOST'].'] Sync failed')
          ->setFrom(array('yves.cheneval@weforum.org'))
          ->setTo(array('obb_devteam@weforum.org'))
          ->setBody(print_r($data, TRUE));

      $app['mailer']->send($message);

      //Retry automatically
      $app['db']->exec("UPDATE _trigger_log SET state = 'NEW' WHERE state = 'FAILED';");
    }

    $date = date('Y-m-d');
    if( $app['db']->getSetting('db_last_cleanup') != $date ){
      $app['db']->exec("DELETE FROM " . $_ENV['APP_SCHEMA'] . ".user_sessions WHERE session_time + sess_lifetime < " . $app['db']->quote(time()) );
      $app['db']->exec("DELETE FROM " . $_ENV['APP_SCHEMA'] . ".access_logs WHERE date < NOW() - INTERVAL '1 MONTH';");
      $app['db']->setSetting('db_last_cleanup', $date);
    }
    return $app->json(['result' => 'OK']);
	}
}

