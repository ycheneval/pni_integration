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
  public $max_attachments = 25;

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
    $this->input->params = \explode(' ', $this->input->text);
    $this->input->response_url = $_POST['response_url'];
    $this->input->trigger_id = $_POST['trigger_id'];
    $this->input->client_type = 'slack';

//    $jwt_sample = $jWT;
//    $jwt_key = $_ENV['JWT_SECRET'];
//    $jwt_algorithm = [$_ENV['JWT_ALGORITHM']];
//    try {
//      $this->__jwt = JWT::decode($jwt_sample, $jwt_key, $jwt_algorithm);
//      $this->wd->watchdog('RegHelper', 'Decoded JSON: @jwt', ['@jwt' => print_r($this->__jwt, TRUE)]);
//    }
//    catch (\Exception $e)
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
    $slack_correct_token = (0 == strcmp($this->input->token, $_ENV['SLACK_APPTOKEN']));
    //Check correct channel
//    $slack_correct_channel = (0 == strcmp($this->input->channel_id, $_ENV['SLACK_CHANNEL']));
    $slack_correct_channel = TRUE;
    return ($slack_correct_channel && $slack_correct_token);
  }

  /**
   * Return album data from its name
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
   * Return album data from its name
   *
   */
  public function getAlbumById($album_id) {
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
            WHERE al.id = " . $this->db()->quote($album_id);
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
   * Return all stickers for a given album
   *
   */
  public function getStickersByAlbum($album_id) {
    $query = "SELECT
                st.id,
                st.ident,
                st.name,
                st.team_album_id,
                st.url,
                te.name as team_name
            FROM " . $this->__schema . ".sticker st
            LEFT JOIN " . $this->__schema . ".team_album ta ON st.team_album_id = ta.id
            LEFT JOIN " . $this->__schema . ".team te ON ta.team_id = te.id
            WHERE st.album_id = " . $this->db()->quote($album_id);
    $stickers = $this->db()->getCollection($query);
    $data = [
      'success' => FALSE,
      'album_id' => $album_id,
      'payload' => [],
    ];
    if ($stickers) {
      $data['success'] = TRUE;
      foreach($stickers as $a_sticker) {
        $data['payload'][$a_sticker['id']] = [
          'id' => $a_sticker['id'],
          'ident' => $a_sticker['ident'],
          'name' => $a_sticker['name'],
          'team_album_id' => $a_sticker['team_album_id'],
          'url' => $a_sticker['url'],
          'team_name' => $a_sticker['team_name'],
          ];
      }
    }
    return $data;
  }

  /**
   * Find a sticker by its ident
   *
   * @param type $album_id
   * @param type $ref
   * @return type
   */
  public function getStickerByIdent($album_id, $ref, $use_like = FALSE) {
    $query = "SELECT
                st.id,
                st.ident,
                st.name,
                st.team_album_id,
                st.url
            FROM " . $this->__schema . ".sticker st
            WHERE "
            . ($use_like ? "st.ident ILIKE '%" . $ref . "%'" : "st.ident = " . $this->db()->quote($ref))
            . " AND st.album_id = " . $this->db()->quote($album_id);
    $stickers = $this->db()->getRow($query);
    if ($stickers) {
      return (array)$stickers['id'];
    }
    return NULL;
  }

  /**
   * Find a sticker by its name
   *
   * @param type $album_id
   * @param type $ref
   * @return type
   */
  public function getStickerByName($album_id, $ref) {
    $query = "SELECT
                st.id,
                st.ident,
                st.name,
                st.team_album_id,
                st.url
            FROM " . $this->__schema . ".sticker st
            WHERE st.name ILIKE '%" . $ref . "%'"
            . " AND st.album_id = " . $this->db()->quote($album_id);
    $stickers = $this->db()->getRow($query);
    if ($stickers) {
      return (array)$stickers['id'];
    }
    return NULL;
  }

  /**
   * Find a sticker by its team
   *
   * @param type $album_id
   * @param type $ref
   * @return type
   */
  public function getStickerByTeam($album_id, $ref) {
    $query = "SELECT
                st.id,
                st.ident,
                st.name,
                st.team_album_id,
                st.url
            FROM " . $this->__schema . ".sticker st "
            . " INNER JOIN " . $this->__schema . ".team_album ta ON st.team_album_id = ta.id"
            . " INNER JOIN " . $this->__schema . ".team te ON ta.team_id = te.id"
            . " WHERE te.name ILIKE '%" . $ref . "%'"
            . " AND st.album_id = " . $this->db()->quote($album_id);
    $this->wd->watchdog('getStickerByTeam', 'Query @q', ['@q' => $query]);
    $stickers = $this->db()->getCollection($query);
    if ($stickers) {
      foreach ($stickers as $a_sticker) {
        $result[] = $a_sticker['id'];
      }
      return $result;
    }
    return NULL;
  }

  /**
   * Return a sticker id (or an array of sticker ids) based on the $ref and $album_id
   *
   * @param type $album_id
   * @param type $ref
   */
  public function findStickerByRef($album_id, $ref) {
    // Find a sticker. It can be an ident like a number (205), a name like pogba
    // or even maybe a team
    if (is_numeric($ref)) {
      // Look by ident for sure
      return $this->getStickerByIdent($album_id, $ref);
    }
    else {
      // Ok so in this case, it's a bit more complicated
      // Either we have a string ident (c1, c4), or a player name (Pogba) or
      // a team name (France). Try ident first, then name, then team
      $this->wd->watchdog('findStickerByRef', 'Trying to find sticker by ident for: @r', ['@r' => $ref]);
      $stickers = $this->getStickerByIdent($album_id, $ref, TRUE);
      if (empty($stickers)) {
        $this->wd->watchdog('findStickerByRef', 'Trying to find sticker name for: @r', ['@r' => $ref]);
        $stickers = $this->getStickerByName($album_id, $ref);
        if (empty($stickers)) {
          $this->wd->watchdog('findStickerByRef', 'Trying to find sticker by team for: @r', ['@r' => $ref]);
          $stickers = $this->getStickerByTeam($album_id, $ref);
        }
      }
      return $stickers;
    }

    return NULL;
  }

  /**
   * The goal is to get sticker list and output a sticker array of recognized sticker id
   *
   */
  public function decodeStickers($all_stickers, $stickers_input) {
    $result = [];
    // We already know that $all_stickers is valid
    $album_id = $all_stickers['album_id'];

    // First, split by ,
    $sticker_blocks = \explode(',', $stickers_input);
    $this->wd->watchdog('decodeStickers', 'Sticker blocks @b', ['@b' => print_r($sticker_blocks, TRUE)]);
    foreach ($sticker_blocks as $a_sticker_block) {
      // Do we have dashes ?
      if (0 == strlen($a_sticker_block)) {
        continue;
      }
      $dashes_block = \explode('-', $a_sticker_block);
      if (count($dashes_block) > 1) {
        $this->wd->watchdog('decodeStickers', 'Block found: @b', ['@b' => print_r($dashes_block, TRUE)]);
        // We do have a dash, so enumerate from start to finish
        for ($i=$dashes_block[0]; $i <= $dashes_block[1]; $i++) {
          // No need of foreach as this will return a single sticker for sure
          $result = array_merge($result, $this->findStickerByRef($album_id, $i));
        }
      }
      else {
        // No dashes
        $new_operation = $this->findStickerByRef($album_id, $a_sticker_block);
        $this->wd->watchdog('decodeStickers', 'Found sticker @r for block @b', ['@b' => $a_sticker_block, '@r' => print_r($new_operation, TRUE)]);
        if ($new_operation) {
          $result = array_merge($result, $new_operation);
        }
//        $this->wd->watchdog('decodeStickers', 'No block: @b, found sticker @r', ['@b' => $a_sticker_block, '@r' => print_r($result, TRUE)]);
//        foreach ($unref_stickers as $an_unref_sticker) {
//          $result[] = $an_unref_sticker;
//        }
      }
    }
    return $result;
  }

  /**
   * Return player data
   *
   */
  public function getPlayer($player_id) {
    $query = "SELECT
                pl.id,
                pl.external_id,
                pl.external_type,
                pl.first,
                pl.last,
                pl.nick,
                pl.date_added,
                pl.privacy,
                pl.current_album_id
            FROM " . $this->__schema . ".player pl
            WHERE pl.id = " . $this->db()->quote($player_id);
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
   * Return player data
   *
   */
  public function getPlayerByNick($player_name) {
    $query = "SELECT
                pl.id,
                pl.external_id,
                pl.external_type,
                pl.first,
                pl.last,
                pl.nick,
                pl.date_added,
                pl.privacy,
                pl.current_album_id
            FROM " . $this->__schema . ".player pl
            WHERE pl.nick = " . $this->db()->quote(\trim($player_name));
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
   * Return player data
   *
   */
  public function getPlayerByExternalId($slack_player_id) {
    $query = "SELECT
                pl.id,
                pl.external_id,
                pl.external_type,
                pl.first,
                pl.last,
                pl.nick,
                pl.date_added,
                pl.privacy,
                pl.current_album_id
            FROM " . $this->__schema . ".player pl
            WHERE pl.external_id = " . $this->db()->quote($slack_player_id)
            . " AND pl.external_type = 'slack'";
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
   * Return player stickers data for $album_id
   *
   * @param type $player_id
   * @param type $album_id
   * @return type
   */
  public function getPlayerStickers($player_id, $album_id) {
    $query = "SELECT
                st.id,
                st.ident,
                ps.owned,
                ps.trading_capacity
            FROM " . $this->__schema . ".player_sticker ps
            INNER JOIN " . $this->__schema . ".sticker st ON ps.sticker_id = st.id
            WHERE ps.player_id = " . $this->db()->quote($player_id)
            . " AND st.album_id = " . $this->db()->quote($album_id);
    $data = $this->db()->getCollection($query);
    if ($data) {
      $sticker_keys = array_map(function($an_object) { return $an_object['id']; }, $data);
      //echo 'Result: ' . print_r($sticker_keys, TRUE);
      $payload = array_combine($sticker_keys, $data);
      return [
        'success' => TRUE,
        'player_id' => $player_id,
        'album_id' => $album_id,
        'payload' => $payload,
      ];
    }
    else {
      return [
        'success' => FALSE,
        'player_id' => NULL,
        'album_id' => NULL,
        'payload' => NULL,
      ];
    }
  }

  /**
   * Check if player exists, if not create it and return data
   *
   * @return type
   */
  public function checkandCreateUser($album_id) {
    switch ($this->input->client_type) {
      case 'slack':
        $slack_player = $this->getPlayerByExternalId($this->input->user_id);

        if ($slack_player['success']) {
          // Are we talking about the correct album?
          if ($album_id != $slack_player['payload']['current_album_id']) {
            $query = "UPDATE " . $this->__schema . ".player SET current_album_id = " . $this->db()->quote($album_id)
              . " WHERE id = " . $slack_player['payload']['id'];
            $this->db()->exec($query);
          }
          // All good, no need to create player
          return [
            'success' => TRUE,
            'new_player' => FALSE,
            'player_id' => $slack_player['payload']['id'],
            'payload' => $slack_player['payload'],
            ];
        }
        else {
          // Insert new player
          $query = "INSERT INTO " . $this->__schema . ".player (external_id, external_type, nick, privacy, current_album_id) "
            . "VALUES ("
            . $this->db()->quote($this->input->user_id)
            . ", " . $this->db()->quote('slack')
            . ", " . $this->db()->quote($this->input->user_name)
            . ", FALSE"
            . ", " . $this->db()->quote($album_id)
            . ") RETURNING id";
        }
        $result = $this->db()->exec($query);
        $this->wd->watchdog('checkandCreateUser', 'Got result as @r', ['@r' => print_r($result, TRUE)]);
        // Get player id
        $slack_player = $this->getPlayerByExternalId($this->input->user_id);
        if ($slack_player['success']) {
          return [
            'success' => TRUE,
            'new_player' => TRUE,
            'player_id' => $slack_player['payload']['id'],
            'payload' => $slack_player['payload'],
            ];
        }
        // If we reach here, we got and error
        break;

      default:
        break;
    }
    return [
      'success' => FALSE,
      'player_id' => NULL,
      'payload' => NULL,
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

    $return_result = [
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
        $query = "INSERT INTO " . $this->__schema . ".player_sticker "
          . "  SELECT " . $player_id . " as player_id, st.id as sticker_id, false as owned, 0 as trading_capacity FROM " . $this->__schema . ".sticker st WHERE st.album_id = " . $this->db()->quote($album_id);
        $result = $this->db()->exec($query);
        $this->wd->watchdog('linkPlayerAlbum', 'Operation @o, result as @r', ['@o' => $operation, '@r' => print_r($result, TRUE)]);
        // Now make sure there is data in the player_album table
        $query = "INSERT INTO " . $this->__schema . ".player_album (player_id, album_id)
          VALUES ( " . $player_id . ', ' . $album_id
          . " )";
        $result = $this->db()->exec($query);
        break;
    }
    return $return_result;
  }

  /**
   * Returns the active watches for this player
   *
   * @param type $player_id
   * @return type
   */
  public function getWatchByPlayer($player_id) {
    $query = "SELECT
                wa.id,
                wa.player_id,
                wa.sticker_id,
                wa.date_started,
                wa.date_expiring,
                st.name as sticker_name,
                st.ident as sticker_number
            FROM " . $this->__schema . ".watch wa
            INNER JOIN " . $this->__schema . ".sticker st ON wa.sticker_id = st.id
            WHERE wa.player_id = " . $this->db()->quote($player_id)
            . " AND wa.date_expiring > now()"
            . " ORDER BY wa.date_expiring";
    $data = $this->db()->getCollection($query);
    if ($data) {
      $sticker_keys = array_map(function($an_object) { return $an_object['sticker_id']; }, $data);
      $payload = array_combine($sticker_keys, $data);
      return [
        'success' => TRUE,
        'player_id' => $player_id,
        'payload' => $payload,
      ];
    }
    else {
      return [
        'success' => FALSE,
        'player_id' => NULL,
        'payload' => [],
      ];
    }
  }

  /**
   * Returns the active watches for this player
   *
   * @param type $player_id
   * @return type
   */
  public function getWatchBySticker($sticker_id) {
    $query = "SELECT
                wa.id,
                wa.player_id,
                wa.sticker_id,
                wa.date_started,
                wa.date_expiring,
                st.name as sticker_name,
                st.ident as sticker_number
            FROM " . $this->__schema . ".watch wa
            INNER JOIN " . $this->__schema . ".sticker st ON wa.sticker_id = st.id
            WHERE wa.sticker_id = " . $this->db()->quote($sticker_id)
            . " AND wa.date_expiring > now()";
    $data = $this->db()->getCollection($query);
    if ($data) {
      $sticker_keys = array_map(function($an_object) { return $an_object['sticker_id']; }, $data);
      $payload = array_combine($sticker_keys, $data);
      return [
        'success' => TRUE,
        'payload' => $payload,
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
   * Indicate which stickers you already got
   *
   */
  public function got($player_id, $album_id, $stickers, $reverse = FALSE) {
    $this->wd->watchdog('got', 'Trying to process @t for album @a and player @p', ['@t' => $stickers, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('got', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    // First split the $stickers
    $s_array = \explode(' ', $stickers);
//    if (!$s_array)
//      {
//        $s_array = (array)$stickers;
//      }

    // Setup some variables
    $stickers_operations = [];
    $slack_attachments = NULL;

    //Handling missing and got in the same routine
    if ($reverse) {
      $add_op = 'to_remove';
      $remove_op = 'to_add';
    }
    else {
      $add_op = 'to_add';
      $remove_op = 'to_remove';
    }
    $exclude_following = ($reverse ? TRUE : FALSE);
    foreach ($s_array as $s_arr_value) {
      switch ($s_arr_value) {
        case 'all':
          // We are talking about all stickers, add them all to $result_stickers
          $stickers_operations[] = [$add_op => array_map(function ($a_sticker) {
            return $a_sticker['id'];
          }, $all_stickers['payload'])];
          break;

        case 'but':
        case 'except':
          // to handle /got all but 3-4
          $exclude_following = ($reverse ? FALSE : TRUE);
          break;

        default:
          // Ok so this is the list of stickers. Find'em all!
          $this->wd->watchdog('got', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
          $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
          $key = ($exclude_following ? 'to_remove' : 'to_add');
          $this->wd->watchdog('got', 'Default case, operation @k, decoded stickers: @s', ['@k' => $key, '@s' => print_r($input_stickers, TRUE)]);
          $stickers_operations[] = [$key => $input_stickers];
          break;
      }
    }
    $this->wd->watchdog('got', 'Found result_stickers: @rs', ['@rs' => print_r($result_stickers, TRUE)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    if (!empty($stickers_operations)) {
      foreach ($stickers_operations as $a_sticker_operation) {
        if (empty(current($a_sticker_operation))) {
          continue;
        }
        $query = "UPDATE " . $this->__schema . ".player_sticker SET owned = " . (key($a_sticker_operation) == 'to_add' ? "TRUE" : "FALSE") . " WHERE sticker_id IN (" . \implode(',', current($a_sticker_operation)) . ")"
          . " AND player_id = " . $this->db()->quote($player_id);
        $this->wd->watchdog('got', 'Query to execute: @q', ['@q' => $query]);
        $result = $this->db()->exec($query);
      }
//      if (!empty($result_stickers['to_add'])) {
//        $query = "UPDATE player_sticker SET owned = " .  WHERE sticker_id IN (" . \implode(',', $result_stickers['to_add']) . ')'
//          . ' AND player_id = ' . $this->db()->quote($player_id);
//        $this->wd->watchdog('got', 'Add Query to execute: @q', ['@q' => $query]);
//        $result = $this->db()->exec($query);
//      }
//      if (!empty($result_stickers['to_remove'])) {
//        $query = "UPDATE player_sticker SET owned = FALSE WHERE sticker_id IN (" . \implode(',', $result_stickers['to_remove']) . ')'
//          . ' AND player_id = ' . $this->db()->quote($player_id);
//        $this->wd->watchdog('got', 'Remove Query to execute: @q', ['@q' => $query]);
//        $result = $this->db()->exec($query);
//      }
      $collection_data = $this->getPlayerStickers($player_id, $album_id);
      if ($collection_data['success']) {
        if ($reverse) {
          // /missing command was input, now returns the list of missing stickers
          // Find the missing stickers stickers
          $missing_stickers = array_filter($collection_data['payload'], function($an_object) { return !$an_object['owned']; });
          $result_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $missing_stickers);
        }
        else {
          $owned_stickers = array_filter($collection_data['payload'], function($an_object) { return $an_object['owned']; });
          $result_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $owned_stickers);
        }
        $slack_attachments[] = [
          'color' => "#7F8DE1",
          'fields' => [
            [
              'title' => ($reverse ? 'Missing' : 'Owned') . ' stickers (' . count($result_stickers_ident) . '):',
              'value' => $this->encodeStickers($result_stickers_ident, TRUE),
              'short' => FALSE
            ],
          ],
        ];
      }
      return [
        'success' => TRUE,
        'msg' => 'Stickers have been processed and added (or removed) from your album',
        'slack_attachments' => $slack_attachments,
      ];
    }
    return [
      'success' => FALSE,
      'msg' => 'There was an error processing your command, please review the syntax',
      'slack_attachments' => NULL,
    ];
//    attachments.push({color: "#A094ED", fields: fields});
//                });
//                res.json({response_type: 'ephemeral', text: "Contacts matching '" + req.body.text + "':", attachments: attachments});
  }

  /**
   * Indicate which stickers you can trade
   *
   */
  public function totrade($player_id, $album_id, $stickers) {
    $this->wd->watchdog('totrade', 'Trying to process @t for album @a and player @p', ['@t' => $stickers, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('totrade', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    // First split the $stickers
    $s_array = \explode(' ', $stickers);

    // Setup some variables
    $stickers_operations = [];

    foreach ($s_array as $s_arr_value) {
      switch ($s_arr_value) {
        default:
          // Ok so this is the list of stickers. Find'em all!
          $this->wd->watchdog('totrade', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
          $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
          $key = 'to_trade';
          $this->wd->watchdog('totrade', 'Operation @k, decoded stickers: @s', ['@k' => $key, '@s' => print_r($input_stickers, TRUE)]);
          $stickers_operations[] = [$key => $input_stickers];
          break;
      }
    }
    $this->wd->watchdog('totrade', 'Found result_stickers: @rs', ['@rs' => print_r($result_stickers, TRUE)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    if (!empty($stickers_operations)) {
      foreach ($stickers_operations as $a_sticker_operation) {
        if (empty(current($a_sticker_operation))) {
          continue;
        }
        $query = "UPDATE " . $this->__schema . ".player_sticker SET trading_capacity = trading_capacity+1 WHERE sticker_id IN (" . \implode(',', current($a_sticker_operation)) . ")"
          . " AND player_id = " . $this->db()->quote($player_id);
        $this->wd->watchdog('totrade', 'Query to execute: @q', ['@q' => $query]);
//        $this->checkWatch($player_id, current($a_sticker_operation));
        $result = $this->db()->exec($query);
      }

      $collection_data = $this->getPlayerStickers($player_id, $album_id);
      if ($collection_data['success']) {
        // Find the list of stickers for trade
        $to_trade_stickers = array_filter($collection_data['payload'], function($an_object) { return $an_object['trading_capacity'] > 0;});
        $result_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $to_trade_stickers);
        $slack_attachments[] = [
          'color' => "#7F8DE1",
          'fields' => [
            [
              'title' => 'Stickers for trade (' . count($result_stickers_ident) . '):',
              'value' => $this->encodeStickers($result_stickers_ident, TRUE),
              'short' => FALSE
            ],
          ],
        ];
      }

      return [
        'success' => TRUE,
        'msg' => 'Stickers to trade have been updated for your album',
        'slack_attachments' => $slack_attachments,
      ];
    }
    return [
      'success' => FALSE,
      'msg' => 'There was an error processing your command, please review the syntax',
      'slack_attachments' => NULL,
    ];
  }

  /**
   * Indicate which stickers you have traded
   *
   */
  public function traded($player_id, $album_id, $stickers) {
    $this->wd->watchdog('traded', 'Trying to process @t for album @a and player @p', ['@t' => $stickers, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('traded', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    // First split the $stickers
    $s_array = \explode(' ', $stickers);

    // Setup some variables
    $stickers_operations = [];

    foreach ($s_array as $s_arr_value) {
      switch ($s_arr_value) {
        default:
          // Ok so this is the list of stickers. Find'em all!
          $this->wd->watchdog('traded', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
          $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
          $key = 'traded';
          $this->wd->watchdog('traded', 'Operation @k, decoded stickers: @s', ['@k' => $key, '@s' => print_r($input_stickers, TRUE)]);
          $stickers_operations[] = [$key => $input_stickers];
          break;
      }
    }
    $this->wd->watchdog('traded', 'Found result_stickers: @rs', ['@rs' => print_r($result_stickers, TRUE)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    if (!empty($stickers_operations)) {
      foreach ($stickers_operations as $a_sticker_operation) {
        if (empty(current($a_sticker_operation))) {
          continue;
        }
        $query = "UPDATE " . $this->__schema . ".player_sticker SET trading_capacity = GREATEST(0, trading_capacity - 1) WHERE sticker_id IN (" . \implode(',', current($a_sticker_operation)) . ")"
          . " AND player_id = " . $this->db()->quote($player_id);
        $this->wd->watchdog('traded', 'Query to execute: @q', ['@q' => $query]);
        $result = $this->db()->exec($query);
        // Update log
        $query = "INSERT INTO " . $this->__schema . ".traded_log (player_id, album_id, sticker_id) "
          . " VALUES ";
        $first_row = TRUE;
        foreach (current($a_sticker_operation) as $a_sticker_traded) {
          $query .= (!$first_row ? "," : "") . "(" . $player_id . ", " . $album_id . ", " . $a_sticker_traded . ")";
          $first_row = FALSE;
        }
        $query .= ';';
        $this->wd->watchdog('traded', 'Log Query to execute: @q', ['@q' => $query]);
        $result = $this->db()->exec($query);
      }

      return [
        'success' => TRUE,
        'msg' => 'Stickers traded have been updated for your album',
        'slack_attachments' => NULL,
      ];
    }
    return [
      'success' => FALSE,
      'msg' => 'There was an error processing your command, please review the syntax',
      'slack_attachments' => NULL,
    ];
  }

  /**
   * Returns a collection of the stickers available for trade in the list of $wanted_stickers
   * for $album_id. If you specify $excluded_player_id, will remove result for this player
   * If you specify $only_player_id, will return data only for this player
   * Those 2 options are generally exclusive but can be combined
   *
   * @param type $wanted_stickers
   * @param type $album_id
   * @param type $exluded_player_id
   * @param type $only_player_id
   * @return type
   */
  public function getStickersAvailableForPlayerMatching($wanted_stickers, $album_id, $excluded_player_id = NULL, $only_player_id = NULL) {
    if (empty($wanted_stickers)) {
      return NULL;
    }
    $query = "SELECT pl.nick, string_agg(st.ident::character varying, ',') as stickers "
      . " FROM " . $this->__schema . ".player_sticker ps "
      . " INNER JOIN " . $this->__schema . ".player pl ON ps.player_id = pl.id "
      . " INNER JOIN " . $this->__schema . ".sticker st ON ps.sticker_id = st.id "
      . " WHERE ps.sticker_id IN (" . \implode(',', $wanted_stickers) . ") "
      . " AND st.album_id = " . $album_id
      . " AND ps.trading_capacity > 0 "
      . ($excluded_player_id ? " AND ps.player_id != " . $this->db()->quote($excluded_player_id) : "")
      . ($only_player_id ? " AND ps.player_id = " . $this->db()->quote($only_player_id) : "")
      . " GROUP BY pl.nick ";
    $this->wd->watchdog('getStickersAvailableForPlayerMatching', 'Query to execute: @q', ['@q' => $query]);
    $stickers_available = $this->db()->getCollection($query);
    return $stickers_available;
  }

  /**
   * Find sticker(s) available to trade
   *
   * @param type $player_id
   * @param type $album_id
   * @param type $stickers
   * @return type
   */
  public function find($player_id, $album_id, $stickers) {
    $this->wd->watchdog('find', 'Trying to process @t for album @a and player @p', ['@t' => $stickers, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('find', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    // Setup some variables
    $stickers_operations = [];

    // Check if $stickers is not a special command
    switch (trim($stickers)) {
      case '-missing':
      case '':
        // In this case, we need to use missing stickers as input
        $collection_data = $this->getPlayerStickers($player_id, $album_id);
        $missing_stickers_id = $this->findMissingInCollection($collection_data);
        if ($missing_stickers_id) {
          $stickers_operations[] = ['find' => $missing_stickers_id];
        }
        break;

      default:
        // Std operation, $stickers contains a list of comma separated stickers
        // First split the $stickers
        $s_array = \explode(' ', $stickers);

        foreach ($s_array as $s_arr_value) {
          // Ok so this is the list of stickers. Find'em all!
//          $this->wd->watchdog('find', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
          $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
          $key = 'find';
//          $this->wd->watchdog('find', 'Operation @k, decoded stickers: @s', ['@k' => $key, '@s' => print_r($input_stickers, TRUE)]);
          $stickers_operations[] = [$key => $input_stickers];
        }
        break;
    }

    $this->wd->watchdog('find', 'Found Sticker operation: @rs', ['@rs' => print_r($stickers_operations, TRUE)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    $attachments = [];
    $nb_opportunities = 0;
    $ma = $this->max_attachments;
    if (!empty($stickers_operations)) {
      foreach ($stickers_operations as $a_sticker_operation) {
        $stickers_available = $this->getStickersAvailableForPlayerMatching(current($a_sticker_operation), $album_id, $player_id);
        // Now get attachments to display the data
        foreach ($stickers_available as $a_sticker_available) {
//          $an_attachment = new \stdClass;
//          $an_attachment->title = 'Player';
//          $an_attachment->value = 'You can trade ' . $a_sticker_available['stickers'] . ' with ' . $a_sticker_available['nick'];
//          $an_attachment->short = false;
          $nb_opportunities++;
          if ($ma < $nb_opportunities) {
            continue;
          }
          $an_attachment = [
            'title' => 'Trading opportunity:',
            'value' => 'You can trade ' . $a_sticker_available['stickers'] . ' with ' . $a_sticker_available['nick'],
            'short' => FALSE
          ];
          $attachments[] = [
            'color' => "#7F8DE1",
            'fields' => [$an_attachment],
          ];
        }
      }
      $this->wd->watchdog('find', 'Found attachments: @a', ['@a' => print_r($attachments, TRUE)]);

      $nb_attachments = count($attachments);

      return [
        'success' => TRUE,
        'main_title' => $nb_opportunities . ' trading ' . ($nb_attachments > 1 ? 'opportunities' : 'opportunity') . ' found' . ($ma < $nb_opportunities ? ', we are displaying the first ' . $max_attach_reached . ' only' : ''),
        'slack_attachments' => $attachments,
      ];
    }
    return [
      'success' => FALSE,
      'msg' => 'There was an error processing your command, please review the syntax',
      'slack_attachments' => NULL,
    ];
  }

  /**
   * Return the list of missing stickers for player in $collection_data
   *
   * @param type $collection_data
   * @return type
   */
  public function findMissingInCollection($collection_data) {
    $missing_stickers_id = NULL;
    if ($collection_data['success']) {
      // Find the missing stickers of owned stickers
      $missing_stickers = array_filter($collection_data['payload'], function($an_object) { return !$an_object['owned']; });
      $missing_stickers_id = array_map(function($a_value) { return $a_value['id'];}, $missing_stickers);
    }
    return $missing_stickers_id;
  }

  /**
   * Find exchange with a given player
   *
   */
  public function exchange($player_id, $album_id, $player_nick) {
    $this->wd->watchdog('exchange', 'Trying to process with player @pn for album @a and player @p', ['@pn' => $player_nick, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('find', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    // Setup some variables
    $stickers_operations = [];

    $collection_data_own = $this->getPlayerStickers($player_id, $album_id);
    if ($collection_data_own['success']) {
      $missing_own = $this->findMissingInCollection($collection_data_own);
      $other_player_info = $this->getPlayerByNick(trim($player_nick));
      if ($other_player_info['success']) {
        $other_player_id = $other_player_info['payload']['id'];
        $collection_data_other = $this->getPlayerStickers($other_player_info['payload']['id'], $album_id);
        $missing_other = $this->findMissingInCollection($collection_data_other);

        // Now check what we can do to trade.
        // To do this, we need to find for each player which one is missing and
        // available to trade by the other
        // Start with the stickers the other player has that we need
        $available_at_other = $this->getStickersAvailableForPlayerMatching($missing_own, $album_id, NULL, $other_player_id);
        if ($available_at_other) {
          foreach ($available_at_other as $a_sticker_available) {
            $count_sticker_available = count(\explode(',', $a_sticker_available['stickers']));
            $an_attachment = [
              'title' => $player_nick . ' to you: ' . $count_sticker_available,
              'value' => 'You can get ' . $a_sticker_available['stickers'] . ' from him',
              'short' => FALSE,
            ];
            $attachments[] = [
              'color' => "#7F8DE1",
              'fields' => [$an_attachment],
            ];
          }
        }
        else {
          $an_attachment = [
            'title' => $other_player_info['payload']['nick'] . ' to you: 0',
            'title' => 'Trading opportunity from ' . $other_player_info['payload']['nick']. ':',
            'value' => 'Unfortunately, ' . $other_player_info['payload']['nick'] . ' does not own any of your missing stickers',
            'short' => FALSE,
          ];
          $attachments[] = [
            'color' => "#7F8DE1",
            'fields' => [$an_attachment],
          ];
        }
        $available_at_own = $this->getStickersAvailableForPlayerMatching($missing_other, $album_id, NULL, $player_id);
        if ($available_at_own) {
          foreach ($available_at_own as $a_sticker_available) {
            $count_sticker_available = count(\explode(',', $a_sticker_available['stickers']));
            $an_attachment = [
              'title' => 'You to ' . $player_nick . ': ' . $count_sticker_available,
              'value' => 'You can give him ' . $a_sticker_available['stickers'],
              'short' => FALSE,
            ];
            $attachments[] = [
              'color' => "#7F8DE1",
              'fields' => [$an_attachment],
            ];
          }
        }
        else {
          $an_attachment = [
            'title' => 'You to ' . $other_player_info['payload']['nick'] . ': 0',
            'value' => 'Unfortunately, you do not have any stickers for trade that ' . $other_player_info['payload']['nick'] . ' is missing',
            'short' => FALSE,
          ];
          $attachments[] = [
            'color' => "#7F8DE1",
            'fields' => [$an_attachment],
          ];
        }
        $this->wd->watchdog('find', 'Found attachments: @a', ['@a' => print_r($attachments, TRUE)]);

        return [
          'success' => TRUE,
          'msg' => 'Exchange result',
          'slack_attachments' => $attachments,
        ];
      }
    }

    return [
      'success' => FALSE,
      'msg' => 'There was an error processing your command, please review the syntax',
      'slack_attachments' => NULL,
    ];
  }

  /**
   * Goal is to encode (implode) stickers. If $match_interval is FALSE, this is
   * where the difficult part starts: we want to merge
   * 1,2,3,4,5,6,9,10,11,12,13,14 to 1-6,9-14
   *
   * @param type $stickers
   */
  public function encodeStickers($stickers, $find_intervals = FALSE) {
    if (!$find_intervals) {
      return (\implode(',', $stickers));
    }
    else {
      // Fun starts
      $intervals = [];
      // First sort them
      if (!uasort($stickers, function($a, $b) {
        $a_intval = intval($a);
        $b_intval = intval($b);
        if (0 == $a_intval && '0' != $a) {
          if (0 == $b_intval && '0' != $b) {
            // Both are not numeric, use strcmp
            return (strcmp($a, $b));
          }
          else {
            // $b is numeric, $a is string, $b comes first
            return 1;
          }
        }
        else {
          if (0 == $b_intval && '0' != $b) {
            // $a is numeric, $b is string, $a comes first
            return -1;
          }
          else {
            // Both are numeric
            return ($a > $b);
          }
        }
      }
      )) {
        return (\implode(',', $stickers));
      }
      $current_interval = [
        'start' => NULL,
        'stop' => NULL,
      ];
//      echo 'Stickers sorted ' . print_r($stickers, TRUE);
      foreach ($stickers as $a_sticker) {
        if ((0 == intval($a_sticker)) && ($a_sticker != '0')) {
          if (!is_null($current_interval['start'])) {
            $intervals[] = $current_interval;
            $current_interval = [
              'start' => NULL,
              'stop' => NULL,
            ];
          }
          $intervals[] = [
            'start' => $a_sticker,
            'stop' => $a_sticker,
          ];
          continue;
        }
        $cur_sticker = intval($a_sticker);
        if (!is_null($current_interval['stop']) && ($current_interval['stop'] === ($cur_sticker - 1))) {
          $current_interval['stop'] = $cur_sticker;
        }
        else {
          // Start or End of the interval
          if (is_null($current_interval['start'])) {
            $current_interval['start'] = $cur_sticker;
            $current_interval['stop'] = $cur_sticker;
          }
          else {
            $intervals[] = $current_interval;
            $current_interval['start'] = $cur_sticker;
            $current_interval['stop'] = $cur_sticker;
          }
        }
      }
      if (!is_null($current_interval['start'])) {
        $intervals[] = $current_interval;
      }
//      echo 'Intervals ' . print_r($intervals, TRUE);
      // Now we should have all intervals, output them
      $result_array = [];
      foreach ($intervals as $an_interval) {
        if ($an_interval['start'] == $an_interval['stop']) {
          $result_array = array_merge($result_array,  array($an_interval['start']));
        }
        elseif (($an_interval['start'] + 1) == $an_interval['stop']) {
          $result_array = array_merge($result_array,  array(\implode(',', $an_interval)));
        }
        else {
          $result_array = array_merge($result_array,  array(\implode('-', $an_interval)));
        }
      }
      return (\implode(',', $result_array));
    }
  }

  /**
   * Return the list of players that are on the same album than you
   *
   * @param type $player_id
   * @param type $album_id
   * @param type $player_name
   * @return string
   */
  public function players($player_id, $album_id) {

  }

  /**
   * Get back some stats on the user
   *
   * @param type $player_id
   * @param type $album_id
   * @param type $player_name
   * @return string
   */
  public function stats($player_id, $album_id, $player_name) {
    // For now, does not take into account $player_name
//    $found_player_name = $this->input->user_name;
    $found_player_info = $this->getPlayerByNick($player_name);
    $found_player_info = ($found_player_info['success'] ? $found_player_info : $this->getPlayer($player_id));
    $album_data = $this->getAlbumById($album_id);
    $stickers = $this->getStickersByAlbum($album_id);
    $msg = [
      'success' => FALSE,
      'user_name' => $found_player_info['payload']['nick'] . ($found_player_info['payload']['id'] == $player_id ? ' (you)' : ''),
    ];
    if ($album_data['success']) {
      $msg['msg'] = 'Stats information for player ' . $found_player_info['payload']['name'];
      $collection_data = $this->getPlayerStickers($found_player_info['payload']['id'], $album_id);
      $this->wd->watchdog('stats', 'Found @s stickers for this album', ['@s' => count($collection_data['payload'])]);
      if ($collection_data['success']) {
        $total_stickers_count = count($stickers['payload']);
        $fields[] = [
          'title' => 'Total number of stickers',
          'value' => $total_stickers_count,
          'short' => TRUE,
        ];
        // Find the number of owned stickers
        $owned_stickers = array_filter($collection_data['payload'], function($an_object) { return $an_object['owned'];});
        $owned_stickers_count = count($owned_stickers);
        $fields[] = [
          'title' => 'Stickers owned (missing)',
          'value' => $owned_stickers_count . ' (' . ($total_stickers_count - $owned_stickers_count) . ')',
          'short' => TRUE,
        ];
        // Display the stickers owned or missing (the least of those 2 numbers)
        if ($owned_stickers_count < ($total_stickers_count - $owned_stickers_count)) {
          $title = 'Owned stickers list';
          $ownedormissing_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $owned_stickers);
        }
        else {
          $title = 'Missing stickers';
          $missing_stickers = array_filter($collection_data['payload'], function($an_object) { return !$an_object['owned'];});
          $ownedormissing_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $missing_stickers);
        }
        $fields[] = [
          'title' => $title,
          'value' => $this->encodeStickers($ownedormissing_stickers_ident, TRUE),
          'short' => FALSE,
        ];
        // Find the stickers available to trade
        $traded_stickers = array_filter($collection_data['payload'], function($an_object) { return $an_object['trading_capacity'] > 0;});
        $traded_stickers_ident = array_map(function($a_value) { return $a_value['ident']; }, $traded_stickers);
        $fields[] = [
          'title' => 'Stickers available to trade',
          'value' => $this->encodeStickers($traded_stickers_ident, TRUE),
          'short' => FALSE,
        ];
        $attachments[] = [
          'color' => "#7F8DE1",
          'fields' => $fields,
        ];

        $msg['slack_attachments'] = $attachments;
      }
      $msg['success'] = TRUE;
    }
    return $msg;
  }


    /**
   * Indicate which stickers you have traded
   *
   */
  public function sticker($player_id, $album_id, $stickers) {
    $this->wd->watchdog('sticker', 'Trying to process @t for album @a and player @p', ['@t' => $stickers, '@a' => $album_id, '@p' => $player_id]);
    $all_stickers = $this->getStickersByAlbum($album_id);
    if (!$all_stickers['success']) {
      // There are no stickers, return an error
      return [
        'success' => FALSE,
        'error_message' => 'We cannot find stickers for this album!',
      ];
    }
    $this->wd->watchdog('sticker', 'All Stickers found: @c', ['@c' => count($all_stickers['payload'])]);

    $collection_data = $this->getPlayerStickers($player_id, $album_id);
    $display_owned_information = $collection_data['success'];

    // First split the $stickers
    $s_array = \explode(' ', $stickers);

    // Setup some variables
    $stickers_list = [];

    $msg = [
      'success' => FALSE,
    ];
    foreach ($s_array as $s_arr_value) {
      // Ok so this is the list of stickers. Find'em all!
      $this->wd->watchdog('sticker', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
      $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
      $stickers_list += $input_stickers;
    }
    $this->wd->watchdog('sticker', 'Found @c sticker(s) for detailed information', ['@c' => count($stickers_list)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    if (!empty($stickers_list)) {
      $ma = $this->max_attachments;
      $attachments = [];
      foreach ($stickers_list as $a_sticker) {
        $fields = [];
        if (empty($a_sticker) || ($ma < count($attachments))) {
          continue;
        }
//        $this->wd->watchdog('sticker', 'For sticker @s, found info @i', ['@s' => $a_sticker, '@i' => print_r($all_stickers['payload'][$a_sticker], TRUE)]);
        $fields[] = [
          'title' => 'Name',
          'value' => $all_stickers['payload'][$a_sticker]['name'],
          'short' => TRUE,
        ];
        $fields[] = [
          'title' => 'Number',
          'value' => $all_stickers['payload'][$a_sticker]['ident'],
          'short' => TRUE,
        ];
//        $this->wd->watchdog('sticker', 'For sticker @s, current fields @f', ['@s' => $a_sticker, '@f' => print_r($fields, TRUE)]);
        if (!empty($all_stickers['payload'][$a_sticker]['team_name'])) {
          $fields[] = [
            'title' => 'Team',
            'value' => $all_stickers['payload'][$a_sticker]['team_name'],
            'short' => TRUE,
          ];
        }
        if ($display_owned_information) {
          $fields[] = [
            'title' => 'Owned',
            'value' => ($collection_data['payload'][$a_sticker]['owned'] ? 'Yes' : 'No'),
            'short' => TRUE,
          ];
        }
//        $this->wd->watchdog('sticker', 'For sticker @s, current fields @f', ['@s' => $a_sticker, '@f' => print_r($fields, TRUE)]);
        $attachments[] = [
          'color' => "#7F8DE1",
          'image_url' => $all_stickers['payload'][$a_sticker]['url'],
          'fields' => $fields,
        ];
      }
      $msg['slack_attachments'] = $attachments;
      $msg['main_title'] = count($stickers_list) . " sticker" . (count($attachments) > 1 ? 's' : '') . ' found' . ($ma < count($stickers_list) ? ', only the first ' . $ma. ' are displayed' : '');
      $msg['success'] = TRUE;
    }

    return $msg;
  }

  /**
   * Send a ephemeral message to user $player_externalid
   *
   * @param type $text
   * @param type $player_externalid
   * @return boolean
   */
  protected function sendEphemeralMsgToPlayer($text, $player_externalid) {
    $feature_enabled =  $_ENV['SLACK_BOT_ENABLED'];

    // Check that bot is enabled
    if (!$feature_enabled) {
      return FALSE;
    }

    $slack_channel = $_ENV['SLACK_CHANNEL'];
    $slack_bot_token = $_ENV['SLACK_BOT_TOKEN'];
    $slackapi_chat_ephemeral =  $_ENV['SLACKAPI_CHATEPHEMERAL_URL'];
    if (!empty($slack_channel) && !empty($slack_bot_token) && !empty($slackapi_chat_ephemeral))
    $msg = [
      'channel' => $slack_channel,
      'text' => $text,
      'as_user' => TRUE,
      'user' => $player_externalid,
    ];
    $ch = new CurlHelper($this->app);
    $headers = [
      'Content-Type' => 'application/json; charset=utf-8',
      'Authorization' => 'Bearer ' . $slack_bot_token
    ];
    $response = $ch->httpRequest($slackapi_chat_ephemeral, $this->app->json($msg), $headers, 'POST');
    if (!in_array($response->code, array(200, 201, 204))) {
      // We got an error
      return FALSE;
    }
    return TRUE;
  }

  protected function watch_expire($watch_id) {
    $query = "UPDATE " . $this->__schema . ".watch SET date_expiring = NOW() WHERE id = " . $this->db()->quote($watch_id);
    return $this->db()->exec($query);
  }

  protected function processWatchAction($watches, $player_id, $cur_action, $stickers) {
    $max_watches =  $_ENV['MAX_WATCHES'];
    $cur_watch_nb = count($watches);

    $attachments = [];
    switch ($cur_action) {
      case 'add':
        if ($cur_watch_nb >= $max_watches) {
          break;
        }
        // We have room for a new watch (at least)
        foreach ($stickers as $a_sticker) {
          if ($cur_watch_nb >= $max_watches) {
            break;
          }
          // First check if we have the watch already
          if (array_key_exists($a_sticker, $watches)) {
            // Nothing to do
            continue;
          }
          // Insert the new watch in the list
          $query = "INSERT INTO " . $this->__schema . ".watch (player_id, sticker_id, date_expiring)"
            . " VALUES "
            . " (" . $this->db()->quote($player_id) . ", " . $a_sticker . ", NOW() + interval '1 day'" . ")"
            . " RETURNING date_expiring";

          $result = $this->db()->exec($query);
          // Insert the result in the return data
          $fields = [];
          $fields[] = [
            'title' => 'Add',
            'value' => 'A new watch for sticker ' . $a_sticker . ' has been added',
            'short' => TRUE,
          ];
          $fields[] = [
            'title' => 'Expiring',
            'value' => 'Watch expiring on ' . print_r($result, TRUE),
            'short' => TRUE,
          ];
          $attachments[] = [
            'color' => "#7F8DE1",
            'fields' => $fields,
          ];
          $cur_watch_nb++;
        }
        break;

      case 'remove':
        foreach ($stickers as $a_sticker) {
          // First check if we have the watch already
          if (!array_key_exists($a_sticker, $watches)) {
            // Nothing to do
            continue;
          }
          $this->watch_expire($watches[$a_sticker]['id']);
          $cur_watch_nb--;
          $fields = [];
          $fields[] = [
            'title' => 'Remove',
            'value' => 'The existing watch for sticker ' . $a_sticker . ' has been removed',
            'short' => FALSE,
          ];
          $attachments[] = [
            'color' => "#7F8DE1",
            'fields' => $fields,
          ];
        }
        break;
    }
    return [
      'success' => TRUE,
      'main_title' => 'Watches operations',
      'slack_attachments' => $attachments,
      ];
  }

  /**
   * Check if there is a watch for $sticker_id and send notification if necessary
   *
   * @param type $player_id
   * @param type $sticker_id
   */
  protected function checkWatch($player_id, $stickers) {
    foreach ($stickers as $a_sticker) {
      $sticker_info = $this->getStickerById($a_sticker);
      if ($sticker_info['success']) {
        $watches = $this->getWatchBySticker($a_sticker);
        foreach ($watches as $a_watch) {
          $player_info = $this->getPlayer($a_watch['player_id']);
          if ($player_info['success']) {
            $dest_player_id = $player_info['payload']['id'];
            $dest_player_nick = $player_info['payload']['nick'];
            $dest_player_external_id = $player_info['payload']['external_id'];
            $msg = strtr('The sticker @sident (@sn) is now for trade by player @pn', [
              '@sident' => $sticker_info['payload']['ident'],
              '@sn' => $sticker_info['payload']['name'],
              '@pn' => $dest_player_nick]);
            if ($this->sendEphemeralMsgToPlayer($msg, $dest_player_external_id)) {
              $query = "INSERT INTO " . $this->__schema . ".watch_notification (watch_id, msg, vector)"
                . " VALUES "
                . "(" . $a_watch['id'] . ", " . $this->db()->quote($msg) . ", " . "'slack_bot'" . ")";
              $this->db()->exec($query);
              $this->watch_expire($a_watch['id']);
            }
          }
        }
      }
    }

  }

  /**
   * Manage watches
   *
   * @param type $player_id
   * @param type $album_id
   * @param type $params
   * @return type
   */
  public function watch($player_id, $album_id, $params) {
    $this->wd->watchdog('watch', 'Trying to process @p for album @a and player @p', ['@p' => $params, '@a' => $album_id, '@p' => $player_id]);

    $feature_enabled =  $_ENV['SLACK_BOT_ENABLED'];
    $watches = $this->getWatchByPlayer($player_id);
    $this->wd->watchdog('watch', 'For player @p, got watches @w', ['@p' => $player_id, '@w' => print_r($watches, TRUE)]);

    $actions = \explode(' ', $params);
    $cur_action = 'add';
    foreach ($actions as $an_action) {
      switch ($an_action) {
        case 'list':
          // Return the list of watches
          $attachments = [];
          if ($watches['payload']) {
            foreach ($watches['payload'] as $a_watch) {
              $fields[] = [
                'title' => 'Sticker',
                'value' => $a_watch['sticker_number'] . ' (' . $a_watch['sticker_name'] . ')',
                'short' => TRUE,
              ];
              $date_expiring = \DateTime::createFromFormat('Y-m-d H:i:s.u', $a_watch['date_expiring']);
              $fields[] = [
                'title' => 'Expiring',
                'value' => ($date_expiring ? $date_expiring->format('Y-M-d H:i') : $a_watch['date_expiring']),
                'short' => TRUE,
              ];
              $attachments[] = [
                'color' => "#7F8DE1",
                'fields' => $fields,
              ];
            }
            $main_title = 'Watch list (' . count($watches['payload']) . ')';
          }
          else {
            $main_title = 'You do not have currently any watches';
          }
          return [
            'success' => TRUE,
            'main_title' => $main_title,
            'slack_attachments' => $attachments,
          ];
          break;

        case 'remove':
        case 'add':
          $cur_action = $an_action;
          break;

        default:
          // This is the list of stickers to process
          $stickers = $this->decodeStickers($all_stickers, $stickers_input);
          return $this->processWatchAction($watches['payload'], $player_id, $cur_action, $stickers);
          break;
      }
    }
  }
}
