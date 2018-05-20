<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Wef;

/**
 * Description of Logger
 *
 * @author BMU
 */
class Logger {
  public static function run($app, $data){
    $app['db']->exec("INSERT INTO " . $_ENV['APP_SCHEMA'] . ".access_logs (dyno, username, method, ip, uri) VALUES "
            . "(" . $app['db']->quote(empty($data['DYNO'])?'':$data['DYNO']) . ", " . $app['db']->quote(empty($data['PHP_AUTH_USER'])?'':$data['PHP_AUTH_USER']) . ", " . $app['db']->quote(empty($data['REQUEST_METHOD'])?'':$data['REQUEST_METHOD']) . ", " . $app['db']->quote(empty($data['HTTP_X_FORWARDED_FOR'])?'':$data['HTTP_X_FORWARDED_FOR']) . ", " . $app['db']->quote(empty($data['REQUEST_URI'])?'':$data['REQUEST_URI']) . ");");
  }
}
