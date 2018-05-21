<?php

namespace App\Controller\V1;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use \DateTime;
use \DateInterval;
use Wef\Watchdog;
use Wef\ApplicationException;
use Wef\CurlHelper;
use Wef\Helpers;
use Wef\PGDb;
use \Wef\OAuthSalesforce;
use \Wef\JWT\JWT;
use \Wef\StringEncryption;
use \Wef\ExtCrypto;

/**
 * Description of BilateralHelper
 *
 * @author BMU
 */
class PniHelper {

  protected $app;
  protected $wd;
  protected $__status;
  protected $__schema;
  protected $__jwt;
  protected $_logged_sfid = NULL;
  protected $_oc_info = NULL;
  public $input = NULL;
  const CLIENT_TYPE = 'slack';

  public function __construct($request, $app, $jWT = NULL) {
    $this->app = $app;
    // Remove debug from db
    $this->app['db']->setDebugLevel(0);
    $this->wd = new Watchdog($app);
    $this->__schema = $_ENV['APP_SCHEMA'];
//    $this->setLoggedInAccount($this->findLoggedInAccount($request));
    $this->input = new \stdClass();
    $this->input->token = $_POST['token'];
    $this->input->team_id = $_POST['team_id'];
    $this->input->team_domain = $_POST['team_domain'];
    $this->input->channel_id = $_POST['channel_id'];
    $this->input->channel_name = $_POST['channel_name'];
    $this->input->user_id = $_POST['user_id'];
    $this->input->user_name = $_POST['user_name'];
    $this->input->command = $_POST['command'];
    $this->input->text = $_POST['text'];
    $this->input->params = \split(' ', $this->input->text);
    $this->input->response_url = $_POST['response_url'];
    $this->input->trigger_id = $_POST['trigger_id'];
    $this->input->client_type = $this->CLIENT_TYPE;

//    $jwt_sample = $jWT;
//    $jwt_key = $_ENV['JWT_SECRET'];
//    $jwt_algorithm = [$_ENV['JWT_ALGORITHM']];
//    try {
//      $this->__jwt = JWT::decode($jwt_sample, $jwt_key, $jwt_algorithm);
//      $this->wd->watchdog('RegHelper', 'Decoded JSON: @jwt', ['@jwt' => print_r($this->__jwt, TRUE)]);
//    }
//    catch (\Exception $e) {
//      $this->wd->watchdog('RegHelper', 'Error Decoding jWT, aborting (@e)', ['@e' => $e->getMessage()]);
//      $this->wd->watchdog('RegHelper', '@j', ['@j' => $jwt_sample]);
//    }

  }

  //////////////////////////////////////////////////////////////////////////
  //////////////////////////////// PROTECTED ///////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  protected function db() {
    return $this->app['db'];
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// PUBLIC /////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  public function checkAuth() {
    return (0 == strcmp($this->input->token, $_POST[$_ENV['SLACK_APPKEY']]));
  }

  /**
   * Return album data
   *
   */
  public function getAlbum($album_name) {
    $query = "SELECT
                al.id,
                al.name,
                al.year,
                al.version,
                al.location,
                al.sport,
                al.event_type,
                al.manufacturer,
                al.url
            FROM " . $this->__schema . ".album al
            WHERE al.name = " . $this->db()->quote($album_name);
    $data = $this->db()->getRow($query);
    if ($data) {
      return [
        'success' => TRUE,
        'payload' => $data,
      ];
    }
    else {
      return [
        'success' => FALSE,
        'payload' => NULL,
      ];
    }
  }

  /**
   * Check if player exists, if not create it and return data
   *
   * @return type
   */
  public function checkandCreateUser() {
    switch ($this->input->client_type) {
      case 'slack':
        $query = "SELECT pl.id, pl.external_id
                FROM " . $this->__schema . ".player pl
                WHERE pl.external_id = " . $this->db()->quote($this->input->user_id)
                . " AND pl.external_type = 'slack'";
        $data = $this->db()->getRow($query);

        if ($data) {
          // All good, no need to create anything
          return [
            'success' => TRUE,
            'new_player' => FALSE,
            'player_id' => $reg_data['id']
            ];
        }
        else {
          // Insert new player
        $query = "INSERT INTO " . $this->__schema . ".player (external_id, external_type, nick, privacy) "
          . "VALUES ("
          . $this->db()->quote($this->input->user_id)
          . ", " . $this->db()->quote('slack')
          . ", " . $this->db()->quote($this->input->user_name)
          . ", FALSE"
          . ") RETURNING id";
        }
        $result = $this->db()->exec($query);
        $this->wd->watchdog('checkandCreateUser', 'Got result as @r', ['@r' => print_r($result, TRUE)]);
        // Get player id
        $query = "SELECT pl.id
                FROM " . $this->__schema . ".player pl
                WHERE pl.external_id = " . $this->db()->quote($this->input->user_id)
                . " AND pl.external_type = 'slack'";
        $data = $this->db()->getRow($query);
        if ($data) {
          return [
            'success' => TRUE,
            'new_player' => TRUE,
            'player_id' => $reg_data['id']
            ];
        }
        break;

      default:
        break;
    }
    return [
      'success' => FALSE,
    ];
  }

  /**
   * Link $player_id with $album_id. I
   *
   * @param type $player_id
   * @param type $album_id
   */
  public function linkPlayerAlbum($player_id, $album_id) {
    // We need to add all stickers from $album_id. We need first to check if we have data
    $query = "SELECT plal.player_id, plal.album_id
            FROM " . $this->__schema . ".player_album plal
            WHERE plal.player_id = " . $this->db()->quote($player_id)
            . " AND plal.album_id = " . $this->db()->quote($album_id);
    $data = $this->db()->getRow($query);

    // By default, operation is INSERT
    $operation = 'INSERT';
    if ($data) {
      // Ok we have data
      if (in_array('reset', $this->input->params)) {
        // We need to reset all album data
        $operation = 'UPDATE';
      }
      else {
        $operation = 'NONE';
      }
    }

    $result = [
      'success' => TRUE,
      'operation' => $operation,
    ];

    switch ($operation) {
      case 'NONE':
      default:
        // Do nothing return gracefully
        break;

      case 'UPDATE':
        // In this case, we need to reset all stickers data
        $query = "UPDATE " . $this->__schema . ".player_sticker ps
          SET owned = FALSE, trading_capacity = 0
          WHERE ps.player_id = " . $this->db()->quote($player_id)
          . " AND sicker_id IN ("
          . "   SELECT st.id FROM " . $this->__schema . ".sticker st WHERE st.album_id = " . $this->db()->quote($album_id)
          . " )";
        $result = $this->db()->exec($query);
        $this->wd->watchdog('linkPlayerAlbum', 'Operation @o, result as @r', ['@o' => $operation, '@r' => print_r($result, TRUE)]);
        break;

      case 'INSERT':
        // We need to setup the album
        $query = "INSERT INTO " . $this->__schema . ".player_sticker ps (player_id, sticker_id, owned, trading_capacity)
          VALUES ( "
          . "   SELECT " . $player_id . ", st.id, false, 0 FROM " . $this->__schema . ".sticker st WHERE st.album_id = " . $this->db()->quote($album_id)
          . " )";
        $result = $this->db()->exec($query);
        $this->wd->watchdog('linkPlayerAlbum', 'Operation @o, result as @r', ['@o' => $operation, '@r' => print_r($result, TRUE)]);
        break;
    }
    return $result;
  }

}
