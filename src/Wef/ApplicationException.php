<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Wef;

/**
 * Description of Exception
 *
 * @author BMU
 */
class ApplicationException extends \Exception{
  private $module;
  private $params;

  public function __construct($module = '', $message = '', $params = [], $level = NULL, $code = 0, $previous = NULL ){
    global $app;

    $this->module = $module;
    $this->params = $params;

    $wd = new Watchdog($app);
    $wd->watchdog($module, $message, $params, $level);
    $full_message = Helpers::format_string($message, $params);

    parent::__construct($full_message, $code, $previous);
  }

  public function getWdMessage(){
    return $this->module . ': ' . str_replace(array_keys($this->params), array_values($this->params), $this->getMessage() );
  }
}
