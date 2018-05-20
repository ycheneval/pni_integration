<?php

namespace Wef;

use Silex\Application;

class StringEncryption {

  protected $app;
  protected $__AUTH_ALGO;
  protected $__AUTH_IV;
  protected $__AUTH_KEY;

  public function __construct($app) {
    $this->app = $app;
    $this->__AUTH_ALGO = (isset($_ENV['ENCRYPTION_ALGORITHM']) ? $_ENV['ENCRYPTION_ALGORITHM'] : 'AES-256-CBC');
    $this->__AUTH_KEY = (isset($_ENV['ENCRYPTION_AUTH_KEY']) ? $_ENV['ENCRYPTION_AUTH_KEY'] : 'This is it! The wonderful salt!!');
    $this->__AUTH_IV = (isset($_ENV['ENCRYPTION_AUTH_IV']) ? $_ENV['ENCRYPTION_AUTH_IV'] : '1029384756AfBeCdh29kka3b19m34xcv');

    switch ($this->__AUTH_ALGO) {
      case 'AES-256-CBC':
        $this->__AUTH_IV = substr($this->__AUTH_IV, 0, 16);
        break;

      default:
        return FALSE;
        break;
    }
    return TRUE;
  }

  /**
   * Encode the $input URL
   *
   * @param type $input
   * @return type
   */
  protected function base64_url_encode($input) {
    return \strtr(\base64_encode($input), '+/=', '-_,');
  }

  /**
   * Decode the $input URL
   *
   * @param type $input
   * @return type
   */
  protected function base64_url_decode($input) {
    return \base64_decode(strtr($input, '-_,', '+/='));
  }

  /**
   * Add necessary padding to $string
   *
   * @param type $string
   * @param type $blocksize
   * @return type
   */
  protected function addpadding($string, $blocksize = 32) {
    $len = strlen($string);
    $pad = $blocksize - ($len % $blocksize);
    $string .= \str_repeat(chr($pad), $pad);
    return $string;
  }

  /**
   * Remove padding from $string
   *
   * @param type $string
   * @return type
   */
  protected function strippadding($string) {
    $result = FALSE;
    $slast = ord(substr($string, -1));
    $slastc = chr($slast);
    if ($slast != 47 && ($pcheck = substr($string, -$slast)) !== FALSE) {
      $expr = "/" . preg_quote($slastc) . "{" . $slast . "}/";
      if (preg_match($expr, $string)) {
        $string = substr($string, 0, strlen($string) - $slast);
        $result = $string;
      }
    }
    return $result;
  }

  /**
   * Decrypt an encrypted value
   *
   * @param type $value_crypted
   * @return type
   */
  public function decrypt($value_crypted) {
    $result = FALSE;

    if (is_array($value_crypted)) {
      $result = array();
      foreach ($value_crypted as $a_value_crypted) {
        $b64token = $this->base64_url_decode($a_value_crypted);
        if (FALSE !== $b64token) {
//          $result[] = $this->strippadding(mcrypt_decrypt($this->__AUTH_ALGO, $this->__AUTH_KEY, $b64token, MCRYPT_MODE_CBC, $this->__AUTH_IV));
          $result[] = $this->strippadding(openssl_decrypt($b64token, $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV));
        }
      }
    }
    else {
      $b64token = $this->base64_url_decode($value_crypted);
      if (FALSE !== $b64token) {
//        $result = $this->strippadding(mcrypt_decrypt($this->__AUTH_ALGO, $this->__AUTH_KEY, $b64token, MCRYPT_MODE_CBC, $this->__AUTH_IV));
        $result = $this->strippadding(openssl_decrypt($b64token, $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV));
      }
    }

    return $result;
  }

  /**
   * Encrypt $to_encode
   *
   * @param type $to_encode
   * @return type
   */
  public function encrypt($to_encode) {
    $result = FALSE;

    if (is_array($to_encode)) {
      $result = array();
      foreach ($to_encode as $a_value_toencode) {
//        $aes = mcrypt_encrypt($this->__AUTH_ALGO, $this->__AUTH_KEY, $this->addpadding($a_value_toencode), MCRYPT_MODE_CBC, $this->__AUTH_IV);
        $aes = openssl_encrypt($this->addpadding($a_value_toencode), $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV);
        $result[] = $this->base64_url_encode($aes);
      }
    }
    else {
//      $aes = mcrypt_encrypt($this->__AUTH_ALGO, $this->__AUTH_KEY, $this->addpadding($to_encode), MCRYPT_MODE_CBC, $this->__AUTH_IV);
      $aes = openssl_encrypt($this->addpadding($to_encode), $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV);
      $result = $this->base64_url_encode($aes);
    }

    return $result;
  }

}
