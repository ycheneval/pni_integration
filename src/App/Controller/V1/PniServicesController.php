<?php

namespace App\Controller\V1;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use \DateTime;
use \DateInterval;
use Wef\SalesforceApi;
use Wef\Watchdog;
use Wef\Helpers;
use Wef\PGDb;
use Wef\ExtCrypto;

/**
 * DefaultController is here to help you get started.
 *
 * You would probably put most of your actions in other more domain specific
 * controller classes.
 *
 * Controllers are completely separated from Silex, any dependencies should be
 * injected through the constructor. When used with a smart controller resolver,
 * the Request object can be automatically added as an argument if you use type
 * hinting.
 *
 * @author Gunnar Lium <gunnar@aptoma.com>
 */
class PniServicesController {

  protected $error_msg = [
    'response_type' => 'ephemeral',
     'text' => "There was an error processing your command",
  ];

  /**
   * Setup a new album or list existing albums
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function album(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $album_data = $ph->getAlbum($ph->input->params[0]);
    if (!$album_data['success']) {
      $this->error_msg['text'] .= ': Cannot find album';
      return $app->json($this->error_msg);
    }

    $wd->watchdog('notice', 'Found album @ad', ['@ad' => print_r($album_data, TRUE)]);
    $player_data = $ph->checkandCreateUser($album_data['payload']['id']);
    if (!$player_data['success']) {
      $this->error_msg['text'] .= ': Impossible to find or create user';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Found player @ad', ['@ad' => print_r($player_data, TRUE)]);
    $player_album = $ph->linkPlayerAlbum($player_data['player_id'], $album_data['payload']['id']);
    if ($player_album['success']) {
      return $app->json([
        'response_type' => 'ephemeral',
        'text' => 'Operation ' . $player_album['operation'] . ' performed successfully',
      ]);
    }

//    $wd = new Watchdog($app);
//    $wd->watchdog('warning', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
//    $app['monolog']->addWarning("This is a test!", (array)$request);
    return $app->json[$this->$error_msg];

  }

  /**
   * Set or get the stickers you have
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function got(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->got($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
//          'text' => "Operation result   ",
          'attachments' => $result['slack_attachments']
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Set of get the missing stickers
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function missing(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->got($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text, TRUE);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
//          'text' => "Missing command result:",
          'attachments' => $result['slack_attachments']
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Statistics for a player
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function stats(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');
    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->stats($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text, TRUE);
      $wd->watchdog('stats', 'Got stats @s', ['@s' => print_r($result, TRUE)]);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => "Stats for player " . $result['user_name'],
          'attachments' => $result['slack_attachments'],
        ];
        $json = $app->json($message);
//        $wd->watchdog('notice', 'Find results json @j', ['@j' => $json]);
        $app['monolog']->addWarning("Stats JSON data:", (array)$json);
        return $json;
      }
    }
    $message = [
      'response_type' => 'ephemeral',
      'text' => "Sorry, the stats feature is not implemented yet",
    ];
    return $app->json($message);

  }


  /**
   * Find stickers to trade
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function find(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->find($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => $result['main_title'],
          'attachments' => $result['slack_attachments']
        ];
        $json = $app->json($message);
        $wd->watchdog('notice', 'Find results json @j', ['@j' => $json]);
        $app['monolog']->addWarning("This is a test!", (array)$json);
        return $json;
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Ask to match player cards with another player
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function exchange(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->exchange($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => "Exchange results",
          'attachments' => $result['slack_attachments']
        ];
        $json = $app->json($message);
        $wd->watchdog('notice', 'Find results json @j', ['@j' => $json]);
        $app['monolog']->addWarning("This is a test!", (array)$json);
        return $json;
      }
    }

    return $app->json($this->error_msg);
  }

  /**
   * Set the cards available to trade with other players.
   * You can *remove* a card (without trading it) by putting the "remove"
   * keyword just after the slash command: /totrade remove 25,23 (TBD)
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function totrade(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->totrade($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => "You have added new stickers on your to trade list",
          'attachments' => $result['slack_attachments']
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Announce which cards have been traded and therefore are not available to
   * trade anymore (unless multiple doubles)
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function traded(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->traded($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => 'The traded stickers have been removed from your to trade list',
          'attachments' => $result['slack_attachments']
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Add a watch for a given card. When this card is available to trade,
   * the player will receive a notification
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function watch(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->watch($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => $result['main_title'],
          'attachments' => $result['slack_attachments'],
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Find sticker info
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function sticker(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->sticker($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => $result['main_title'],
          'attachments' => $result['slack_attachments'],
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }

  /**
   * Find player info
   *
   * @param Request $request
   * @param Application $app
   * @return type
   */
  public function player(Request $request, Application $app) {
    $wd = new Watchdog($app);
    $ph = new PniHelper($request, $app);
    $wd->watchdog('notice', 'Here is our request object @r', ['@r' => print_r($_POST, TRUE)]);
    if (!$ph->checkAuth()) {
      $this->error_msg['text'] .= ': Request not coming from slack';
      return $app->json($this->error_msg);
    }
    $wd->watchdog('notice', 'Origin checked');

    $player_data = $ph->getPlayerByExternalId($ph->input->user_id);
    if ($player_data['success']) {
      $result = $ph->player($player_data['payload']['id'], $player_data['payload']['current_album_id'], $ph->input->text);
      if ($result['success']) {
        $message = [
          'response_type' => 'ephemeral',
          'text' => $result['main_title'],
          'attachments' => $result['slack_attachments'],
        ];
        return $app->json($message);
      }
    }
    return $app->json($this->error_msg);
  }


}
