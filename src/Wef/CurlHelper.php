<?php

namespace Wef;

use Silex\Application;
use \Exception;
use Wef\SfException;

class CurlHelper {

  protected $app = NULL;
  protected $wd = NULL;
  protected static $debug = [];

  public function getDebugInfo(){
    return self::$debug;
  }

  public function __construct($app) {
    $this->app = $app;
    $this->wd = new Watchdog($app);
  }

  public function httpRequest($url, $data, $headers = array(), $method = 'GET') {
    // Build the request, including path and headers. Internal use.
    $options = array(
      'method' => $method,
      'headers' => $headers,
      'data' => $data,
    );
    return $this->curl_http_request($url, $options, 90);
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// PRIVATE ////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  private function parseHeader($raw_headers){
    $headers = [];

    foreach( explode("\n", $raw_headers) as $i => $h ){
      $h = explode(':', $h, 2);

      if (isset($h[1])) {
        $headers[$h[0]] = trim($h[1]);
      }
    }

    return $headers;
  }


  private function curl_http_request($url, $options, $timeout = 30) {
    static $ch = NULL;

    $start_microtime = microtime(TRUE);
    if (!isset($ch)) {
      $ch = curl_init();
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout - 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, TRUE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    $fp = NULL;
    if (0 == strcasecmp($options['method'], 'PUT_FILE')) {
      // In this case, we need to transfer a file whose name is in $data
      curl_setopt($ch, CURLOPT_PUT, TRUE);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
      curl_setopt($ch, CURLOPT_BINARYTRANSFER, TRUE);
      $fp = fopen($options['data'], 'r');
      if ($fp) {
        $fstats = fstat($fp);
        $this->wd->watchdog('CurlHelper', 'fp (@fn) opened, stats: @fs, sending put', array('@fn' => $os_filename, '@fs' => print_r($fstats, TRUE)));
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fstats['size']);
      }
    }
    elseif (0 == strcasecmp($options['method'], 'PUT')) {
      // In this case, we need to transfer a file which is in $data
      curl_setopt($ch, CURLOPT_PUT, TRUE);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
      curl_setopt($ch, CURLOPT_BINARYTRANSFER, TRUE);
      $fp = fopen('php://temp/maxmemory:256000', 'w');
      if ($fp) {
        $bytes_available = strlen($options['data']);
        $bytes_written = fwrite($fp, $options['data']);
        if ($bytes_written == $bytes_available) {
          // Move back to the beginning of the file
          fseek($fp, 0);
          curl_setopt($ch, CURLOPT_INFILE, $fp);
          curl_setopt($ch, CURLOPT_INFILESIZE, $bytes_written);
        }
        else {
          $debug[] = $this->wd->watchdog('curlHelper', 'write bytes mismatch (@written vs. @avail', array('@written' => $bytes_written, '@avail' => $bytes_available));
        }
      }
    }
    elseif (0 != strcasecmp($options['method'], 'GET')) {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $options['method']);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
    }
    else {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
      curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }

    $headers = array();
    foreach ($options['headers'] as $hk => $hn) {
      $headers[] = $hk . ': ' . $hn;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw_content = curl_exec($ch);
    $this->wd->watchdog('curlHelper', 'Calling "@url"', array('@url' => $url));
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contents = substr($raw_content, $header_size);
    $stop_microtime = microtime(TRUE);

    if (empty($contents)) {
      $this->wd->watchdog('CurlHelper', 'CUrl error while retrieving @url: @error', array('@url' => $url, 'error' => curl_error($ch)), Watchdog::ALERT);
    }

    $response = new \StdClass();
    $response->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response->data = $contents;
    $response->headers = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $response->headers_in = $this->parseHeader(substr($raw_content, 0, $header_size));
    $response->time = (int) (($stop_microtime - $start_microtime) * 1000);

    // Stop curl
    if ($fp) {
      fclose($fp);
      $fp = NULL;
    }
    curl_close($ch);
    $ch = NULL;
    return $response;
  }

}
