<?php

namespace Wef;

use Silex\Application;
use \Exception;
use Wef\SfException;
use Wef\OAuthSalesforce;

class SalesforceApi {

  protected $app = NULL;
  protected $access_token = NULL;
  protected $instance_url = NULL;
  protected static $debug = [];
  
  public function getDebugInfo(){
    return self::$debug;
  }
  
  public function __construct($app) {
    $this->app = $app;
  }

  protected function initialize() {
    if( !$this->access_token ){
      if (!$this->app) {
        return FALSE;
      }

      $oauth = new OAuthSalesforce($this->app);

      try {
        if ( $access_token = $oauth->getAdminAccessToken() ) {
          $this->access_token = $access_token;
          $this->instance_url = $oauth->getInstanceUrl();
        }
      }
      catch (Exception $ex) {
        // We got an error from refreshing the token
        throw new Exception('SF Init Failed with message : ' . $ex->getMessage() );
      }
    }
  }


  /**
   * Base API call to Salesforce
   *
   * @param type $url
   * @param type $content
   * @param type $encoding
   * @param type $call_type
   * @param type $post
   * @return null
   * @throws Exception
   */
  public function apiCall($url, $options = array()) {
    // Handle options
    $content = (isset($options['content']) ? $options['content'] : NULL);
    $encoding = (isset($options['encoding']) ? $options['encoding'] : '');
    $method = (isset($options['method']) ? $options['method'] : 'GET');
    $full_url = (isset($options['is_full_url']) ? $options['is_full_url'] : TRUE);

    $this->initialize();
            
    if (!$full_url) {
      $url = $this->instance_url . $url;
    }
    
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, empty($_ENV['DEV']));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $headers = array("Authorization: OAuth " . $this->access_token);
    switch ($encoding) {
      case 'json':
        $headers[] = 'Content-type: application/json';
        break;

      default:
        break;
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    if ($content) {
      if (0 == strcmp('POST', $method)) {
        curl_setopt($curl, CURLOPT_POST, true);
      }
      elseif (0 != strcmp('GET', $method)) {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
      }
      
      curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
    }
    elseif (0 != strcmp('GET', $method)) {
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    }

    $json_response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    switch ($status) {
      case 200:
      case 201:
      case 204:
        // All OK
        break;

      default:
        throw new \Exception("Error: call to token URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
        return NULL;
    }

    curl_close($curl);
    $response = json_decode($json_response, true);
    $result = [
      'status' => $status,
      'response' => $response,
    ];
    
    return $result;
  }

  /**
   * Fetches data from Salesforce. By default, returns at most 200 records
   *
   * @param type $q
   * @return type
   */
  protected function _base_query($q) {
    // Is it ok to hardcode the V27.0 ?
    $url = "/services/data/v27.0/query?q=" . urlencode($q);
    $apiResponse = $this->apiCall($url, ['is_full_url' => FALSE]);
    return $apiResponse['response'];
  }

  /**
   * Fetches at most $max_size records (or all if omitted or 0) using $q.
   * If there was an error, NULL is returned
   *
   * @param type $q
   * @param type $max_size
   * @return type
   */
  public function query($q, $max_size = 0) {
    self::$debug[] = ['query' => $q];
    $total_results = NULL;

    $results = $this->_base_query($q);

    if ($results) {
      $total_results = $results['records'];
      $records_to_fetch = min($results['totalSize'], ($max_size == 0 ? $results['totalSize'] : $max_size));
      // Make sure we don't overcall SF
      while (count($total_results) < $records_to_fetch) {
        // Fetch more records
        if (isset($results['nextRecordsUrl']) && ($results = $this->apiCall(substr($results['nextRecordsUrl'], stripos($results['nextRecordsUrl'], '/query') + 1)))) {
          $results = $results['response'];
          $total_results = array_merge($total_results, $results['records']);
        }
      }
      // Trim the array to the correct size if necessary
      if (count($total_results) > $records_to_fetch) {
        $total_results = array_slice($total_results, 0, $records_to_fetch);
      }
    }

    return $total_results;
  }

  /**
   * Create a new record in salesforce and returns the associated sfid if successful
   *
   * @param type $record
   * @return type
   */
  public function create($record) {
    self::$debug[] = ['create' => $record];
    $url = '/services/data/v27.0/sobjects/' . $record->type . '/';
    $content = json_encode($record->fields);
    $response = $this->apiCall($url, ['content' => $content, 'encoding' => 'json', 'method' => 'POST', 'is_full_url' => FALSE]);
    
    
    if( !empty($response['response']['success']) ){
      return $response['response']['id'];
    }
    var_dump($record, $response);
    foreach($response['response']['errors'] as $error){
      throw new SfException($error->message);
    }
  }

  /**
   * Update an existing record in salesforce and returns TRUE if successful, FALSE otherwise
   *
   * @param type $record
   * @return boolean
   */
  public function update($record) {
    self::$debug[] = ['update' => $record];
    $id = $record->fields['Id'];
    if (!empty($id)) {
      $url = "/services/data/v27.0/sobjects/" . $record->type . "/" . $id;
      unset($record->fields['Id']);
      $content = json_encode($record->fields);
      $response = $this->apiCall($url, ['content' => $content, 'encoding' => 'json', 'method' => 'PATCH', 'is_full_url' => FALSE]);
      
      if( $response['status'] == 204 ){
        return TRUE;
      }
      foreach($response['response']['errors'] as $error){
        throw new SfException($error->message);
      }
    }
    throw new SfException('Id is missing');
  }
  
  public function delete($record_type, $record_id){
    self::$debug[] = ['delete' => [$record_type, $record_id]];
    $url = "/services/data/v27.0/sobjects/" . $record_type . "/" . $record_id;
    $response = $this->apiCall($url, ['content' => '', 'encoding' => 'json', 'method' => 'DELETE', 'is_full_url' => FALSE]);
    
    if( $response['status'] == 204 ){
      return TRUE;
    }

    throw new SfException('Id is missing');
  }
}
