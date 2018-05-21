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
    return (0 == strcmp($this->input->token, $_ENV['SLACK_APPTOKEN']));
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
   * Return all stickers for a given album
   *
   */
  public function getStickersByAlbum($album_id) {
    $query = "SELECT
                st.id,
                st.ident,
                st.name,
                st.team_album_id,
                st.url
            FROM " . $this->__schema . ".sticker st
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
        $data['payload'][] = [
          'id' => $a_sticker['id'],
          'ident' => $a_sticker['ident'],
          'name' => $a_sticker['name'],
          'team_album_id' => $a_sticker['team_album_id'],
          'url' => $a_sticker['url'],
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
            . ($use_like ? "st.ident LIKE '%" . $ref . "%'" : "st.ident = " . $this->db()->quote($ref))
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
            WHERE st.name LIKE '%" . $ref . "%'"
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
            . " WHERE te.name LIKE '%" . $ref . "%'"
            . " AND st.album_id = " . $this->db()->quote($album_id);
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
      $stickers = $this->getStickerByIdent($album_id, $ref, TRUE);
      if (empty($stickers)) {
        $stickers = $this->getStickerByName($album_id, $ref);
        if (empty($stickers)) {
          $stickers = $this->getStickerByTeam($album_id, $ref);
        }
      }
      return stickers;
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
      $dashes_block = \explode('-', $a_sticker_block);
      if (count($dashes_block) > 1) {
        $this->wd->watchdog('decodeStickers', 'Block found: @b', ['@b' => print_r($dashes_block, TRUE)]);
        // We do have a dash, so enumerate from start to finish
        for ($i=$dashes_block[0]; $i <= $dashes_block[1]; $i++) {
          // No need of foreach as this will return a single sticker for sure
          $result[] = $this->findStickerByRef($album_id, $i);
        }
      }
      else {
        // No dashes
        $result = array_merge($result, $this->findStickerByRef($album_id, $a_sticker_block));
        $this->wd->watchdog('decodeStickers', 'No block: @b, found stick', ['@b' => $a_sticker_block]);
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
  public function getSlackPlayer($slack_player_id) {
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
   * Check if player exists, if not create it and return data
   *
   * @return type
   */
  public function checkandCreateUser($album_id) {
    switch ($this->input->client_type) {
      case 'slack':
        $slack_player = $this->getSlackPlayer($this->input->user_id);

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
        $slack_player = $this->getSlackPlayer($this->input->user_id);
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
   * Indicated which stickers you already got
   *
   */
  public function got($player_id, $album_id, $stickers) {
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
    $result_stickers = [
      'to_add' => [],
      'to_remove' => [],
    ];
    $exclude_following = FALSE;
    foreach ($s_array as $s_arr_value) {
      switch ($s_arr_value) {
        case 'all':
          // We are talking about all stickers, add them all to $result_stickers
          $result_stickers['to_add'] = array_map(function ($a_sticker) {
            return $a_sticker['id'];
          }, $all_stickers['payload']);
          break;

        case 'but':
        case 'except':
          // to handle /got all but 3-4
          $exclude_following = TRUE;
          break;

        default:
          // Ok so this is the list of stickers. Find'em all!
          $this->wd->watchdog('got', 'Default case, trying to find stickers for: @s', ['@s' => $s_arr_value]);
          $input_stickers = $this->decodeStickers($all_stickers, $s_arr_value);
          $key = ($exclude_following ? 'to_remove' : 'to_add');
          $this->wd->watchdog('got', 'Default case, operation @k, decoded stickers: @s', ['@k' => $key, '@s' => $input_stickers]);
          foreach ($input_stickers as $an_input_sticker) {
            $result_stickers[$key][] = $an_input_sticker;
          }
          break;
      }
    }
    $this->wd->watchdog('got', 'Found result_stickers: @rs', ['@rs' => print_r($result_stickers, TRUE)]);

    // Now we should have in $result_stickers the list of things to do
    // in to_add or to_remove
    if (!empty($result_stickers)) {
      if (!empty($result_stickers['to_add'])) {
        $query = "UPDATE player_sticker SET owned = TRUE WHERE sticker_id IN (" . \implode(',', $result_stickers['to_add']) . ')'
          . ' AND player_id = ' . $this->db()->quote($player_id);
        $this->wd->watchdog('got', 'Add Query to execute: @q', ['@q' => $query]);
        $result = $this->db()->exec($query);
      }
      if (!empty($result_stickers['to_remove'])) {
        $query = "UPDATE player_sticker SET owned = FALSE WHERE sticker_id IN (" . \implode(',', $result_stickers['to_remove']) . ')'
          . ' AND player_id = ' . $this->db()->quote($player_id);
        $this->wd->watchdog('got', 'Remove Query to execute: @q', ['@q' => $query]);
        $result = $this->db()->exec($query);
      }
      return [
        'success' => TRUE,
        'msg' => 'Stickers have been processed and added (or removed) from your album',
        'slack_attachments' => NULL,
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
}
