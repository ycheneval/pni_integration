<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Wef;

/**
 * Description of OAuthSalesforce
 *
 * @author BMU
 */
class OAuthSalesforce {
  protected $app = NULL;
  
  public function __construct($app) {
    $this->app = $app;
  }
  
  public function getRefreshToken(){
    return $this->app['db']->getSetting('sf_refresh_token');
  }
  
  public function getInstanceUrl(){
    return $this->app['db']->getSetting('sf_url');
  }
  
  public static function getUrlForSfAuthorizationCode(){

    $auth_url = $_ENV['SALESFORCE_LOGIN_URI']
        . "/services/oauth2/authorize?response_type=code&client_id="
        . $_ENV['SALESFORCE_CLIENT_ID'] . "&redirect_uri=" . urlencode($_ENV['SALESFORCE_REDIRECT_URI']);
    return $auth_url;
  }
  
  public function processAuthorizationCode($code, $b_authorize){
    
    $params = "code=" . $code
        . "&grant_type=authorization_code"
        . "&client_id=" . $_ENV['SALESFORCE_CLIENT_ID']
        . "&client_secret=" . $_ENV['SALESFORCE_CLIENT_SECRET']
        . "&redirect_uri=" . urlencode($_ENV['SALESFORCE_REDIRECT_URI']);

    $curl = curl_init($_ENV['SALESFORCE_LOGIN_URI'] . "/services/oauth2/token");
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, empty($_ENV['DEV']));

    $json_response = curl_exec($curl);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 200 ) {
      throw new \Exception("Error: call to token URL " . $_ENV['SALESFORCE_LOGIN_URI']. "/services/oauth2/token failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
    }

    curl_close($curl);

    $response = json_decode($json_response, true);

    $access_token = isset($response['access_token']) ? $response['access_token'] : '';
    $refresh_token = isset($response['refresh_token']) ? $response['refresh_token'] : '';
    $instance_url = isset($response['instance_url']) ? $response['instance_url'] : '';

    if (empty($access_token)) {
        throw new \Exception("Error - access token missing from response!");
    }
    if (empty($refresh_token)) {
        throw new \Exception("Error - refresh token missing from response!");
    }
    if (empty($instance_url)) {
        throw new \Exception("Error - instance URL missing from response!");
    }

    if($b_authorize){
      $this->app['db']->setSetting('sf_url', $instance_url);
      $this->app['db']->setSetting('sf_refresh_token', $refresh_token);
    }

    return [$instance_url, $access_token];
  }
  
  public function getAdminAccessToken(){
    $refresh_token = $this->getRefreshToken();

    if (!$refresh_token) {
      throw new \Exception('No refresh token, application need to be authorized!');
    }

    $params = "grant_type=refresh_token"
        . '&refresh_token=' . $refresh_token
        . "&client_id=" . $_ENV['SALESFORCE_CLIENT_ID']
        . "&client_secret=" . $_ENV['SALESFORCE_CLIENT_SECRET'];
    
    $headers = array(
      // This is an undocumented requirement on Salesforce's end.
      'Content-Type' => 'application/x-www-form-urlencoded',
    );

    $token_url = $_ENV['SALESFORCE_LOGIN_URI'] . '/services/oauth2/token';
    $curl = curl_init($token_url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, empty($_ENV['DEV']));
    
    $json_response = curl_exec($curl);

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ( $status != 200 ) {
      throw new \Exception("Error: call to refresh Token URL $token_url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
    }

    curl_close($curl);

    $response = json_decode($json_response, true);

    if (isset($response['error'])) {
      throw new \Exception('Error: ' . $response['error_description'] . '(' . $response['error'] . ')');
    }

    $access_token = isset($response['access_token']) ? $response['access_token'] : '';

    if (empty($access_token)) {
      throw new \Exception("Error - access token missing from response!");
    }

    return $access_token;
  }
}
