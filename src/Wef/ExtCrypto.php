<?php

namespace Wef;

use Silex\Application;

class ExtCrypto {

  protected $app;
  protected $__AUTH_ALGO;
  protected $__AUTH_IV;
  protected $__AUTH_KEY;
  protected $__blocksize = 16;

  public function __construct($app, $key = NULL, $iv = NULL) {
    $this->app = $app;
    $this->__AUTH_ALGO = (isset($_ENV['CRYPTO_ALGORITHM']) ? $_ENV['CRYPTO_ALGORITHM'] : 'AES-256-CBC');
    $this->__AUTH_KEY = $key ? $key : (isset($_ENV['CRYPTO_AUTH_KEY']) ? $_ENV['CRYPTO_AUTH_KEY'] : 'This is it! The wonderful salt!!');
    $this->__AUTH_IV = $iv ? $iv : (isset($_ENV['CRYPTO_AUTH_IV']) ? $_ENV['CRYPTO_AUTH_IV'] : '1029384756AfBeCdh29kka3b19m34xcv');

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

  public function decrypt($data) {
    $decrypted = openssl_decrypt(hex2bin($data), $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV);
    $unpadded = $this->unpad($decrypted, $this->__blocksize);
    return (ctype_print($unpadded) ? $unpadded : FALSE);
  }

  public function encrypt($data) {
    //don't use default php padding which is '\0'
    $pad = $this->__blocksize - (strlen($data) % $this->__blocksize);
    $data = $data . str_repeat(chr($pad), $pad);
    return bin2hex(openssl_encrypt($data, $this->__AUTH_ALGO, $this->__AUTH_KEY, OPENSSL_RAW_DATA, $this->__AUTH_IV));
  }

  private function unpad($str, $blocksize) {
    $len = mb_strlen($str);
    $pad = ord($str[$len - 1]);
    if ($pad && $pad < $blocksize) {
      $pm = preg_match('/' . chr($pad) . '{' . $pad . '}$/', $str);
      if ($pm) {
        return mb_substr($str, 0, $len - $pad);
      }
    }
    return $str;
  }

}

