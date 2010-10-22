<?php

set_include_path(get_include_path().PATH_SEPARATOR.'./lib');
require_once("Net/SmartIRC.php");
require_once("twitter.class.php");
require_once("config.php");

class TwitChat
{
  static $mentionLastId;

  function __construct(&$irc, &$twitter)
  {
    $irc->setUseSockets(TRUE);
    $irc->registerTimehandler(IRC_TIMER, $this, 'timer'); 
    $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^quit$', $this, 'quit');
    $irc->registerActionHandler(SMARTIRC_TYPE_CHANNEL, '.*', $this, 'listen');
    $this->twitter = &$twitter;

    $this->mentionLastId = $this->twitter->getMentionLastId();
  }

  public function timer(&$irc)
  {
    $this->postMentions($irc);
  }

  public function postMentions(&$irc)
  {
    $statusList = $this->twitter->getMentions(array('since_id' => $this->mentionLastId));
    if (!count($statusList)) return;

    $this->mentionLastId = $statusList[0]->id;

    krsort($statusList);
    foreach ($statusList as $status)
    {
      $irc->message(SMARTIRC_TYPE_NOTICE, IRC_CHANNEL, $this->decode($status->user->screen_name.'> '.$status->text));
    }
  }

  public function listen(&$irc, &$data)
  {
    $nick    = $this->encode($data->nick);
    $message = $this->encode($data->message);

    // bit.ly
    $message = preg_replace_callback('/https?:\/\/(?:[a-zA-Z0-9_\-\/.,:;~?@=+$%#!()]|&amp;)+/', array(__CLASS__, 'shortenUrl'), $message);

    // twitter
    $post = $nick.'> '.$message;
    if (mb_strlen($post) > 140)
    {
      $post = mb_substr($post, 0, 138).'..';
    }

    $this->twitter->postStatus($post);
  }

  public function quit(&$irc)
  {
    $irc->quit('bye all.');
  }

  protected function encode($text)
  {
    return mb_convert_encoding($text, mb_internal_encoding(), IRC_ENCODING);
  }

  protected function decode($text)
  {
    return mb_convert_encoding($text, IRC_ENCODING, mb_internal_encoding());
  }

  public static function shortenUrl($matches)
  {
    $url = $matches[0];

    if (defined('BIT_LY_ID') && defined('BIT_LY_API_KEY') && BIT_LY_ID && BIT_LY_API_KEY)
    {
      $q = 'version=2.0.1&longUrl='.urlencode($url)
         . '&login='.BIT_LY_ID.'&apiKey='.BIT_LY_API_KEY;
      $ch = curl_init('http://api.bit.ly/shorten?'.$q);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $result = json_decode(curl_exec($ch), true);
      curl_close($ch);

      if ('OK' === $result['statusCode'])
      {
        return $result['results'][$url]['shortUrl'];
      }
    }

    return $url;
  }
}

$irc = new Net_SmartIRC();
$twitter = new Twitter(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$bot = new TwitChat($irc, $twitter);

$irc->connect(IRC_HOST, IRC_PORT);
$irc->login(IRC_NICK, IRC_INFO);
$irc->join(array(IRC_CHANNEL));
$irc->listen();
