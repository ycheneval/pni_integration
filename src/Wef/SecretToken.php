<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Wef;

/**
 * Description of SecretToken
 *
 * @author BMU
 */
class SecretToken
{
  const ENCRYPT_METHOD = ENCRYPT_METHOD;
  const ENCRYPT_PASSWORD = ENCRYPT_PASSWORD;

  /**
   * Encode
   */
  public static function encode(StdClass $o, $ttl = 3600)
  {
    $data_struct = Array(
          'timestamp' => time(),
          'ttl' => (int) $ttl,
          'data' => $o
      );

    $json = json_encode( $data_struct );

    return self::encrypt( $json );
  }

  public static function decode( $string )
  {
    $json = self::decrypt( $string );
    if( $data_struct = json_decode( $json ) ){
      if( isset($data_struct->timestamp) && isset($data_struct->ttl) && isset($data_struct->data) ){
        if( (int) $data_struct->timestamp + (int) $data_struct->ttl >= time() ){
          return $data_struct->data;
        }
      }
    }
    return NULL;
  }

  private static function encrypt( $json )
  {
    return openssl_encrypt( $json , self::ENCRYPT_METHOD, self::ENCRYPT_PASSWORD );
  }

  private static function decrypt( $secret )
  {
    return openssl_decrypt( $secret , self::ENCRYPT_METHOD, self::ENCRYPT_PASSWORD );
  }
}
