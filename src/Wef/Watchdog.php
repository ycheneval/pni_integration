<?php

namespace Wef;

use Silex\Application;

class Watchdog {

  protected $app;
  protected $debugLevel = 0;
  protected $__status;
  protected $__schema;
  protected $_ip_address;

  /**
   * Log message severity -- Emergency: system is unusable.
   */
  const EMERGENCY = 0;
  const EMERGENCY_NAME = 'emergency';

  /**
   * Log message severity -- Alert: action must be taken immediately.
   */
  const ALERT = 1;
  const ALERT_NAME = 'alert';

  /**
   * Log message severity -- Critical conditions.
   */
  const CRITICAL = 2;
  const CRITICAL_NAME = 'critical';

  /**
   * Log message severity -- Error conditions.
   */
  const ERROR = 3;
  const ERROR_NAME = 'error';

  /**
   * Log message severity -- Warning conditions.
   */
  const WARNING = 4;
  const WARNING_NAME = 'warning';

  /**
   * Log message severity -- Normal but significant conditions.
   */
  const NOTICE = 5;
  const NOTICE_NAME = 'notice';

  /**
   * Log message severity -- Informational messages.
   */
  const INFO = 6;
  const INFO_NAME = 'info';

  /**
   * Log message severity -- Debug-level messages.
   */
  const DEBUG = 7;
  const DEBUG_NAME = 'debug';

  public function __construct($app) {
    $this->app = $app;
    $this->__schema = $_ENV['APP_SCHEMA'];
    $this->__status = [
      'name' => [
        self::EMERGENCY => self::EMERGENCY_NAME,
        self::ALERT => self::ALERT_NAME,
        self::CRITICAL => self::CRITICAL_NAME,
        self::ERROR => self::ERROR_NAME,
        self::WARNING => self::WARNING_NAME,
        self::NOTICE => self::NOTICE_NAME,
        self::INFO => self::INFO_NAME,
        self::DEBUG => self::DEBUG_NAME,
      ],
    ];
    // Swap values and keys to get inverse mapping
    $this->__status['num'] = array_flip($this->__status['name']);
  }

  /**
   * Logs a system message.
   *
   * @param $type
   *   The category to which this message belongs. Can be any string, but the
   *   general practice is to use the name of the module calling watchdog().
   * @param $p_message
   *   The message to store in the log. Keep $message translatable
   *   by not concatenating dynamic values into it! Variables in the
   *   message should be added by using placeholder strings alongside
   *   the variables argument to declare the value of the placeholders.
   *   See t() for documentation on how $message and $variables interact.
   * @param $variables
   *   Array of variables to replace in the message on display or
   *   NULL if message is already translated or not possible to
   *   translate.
   * @param $severity
   *   The severity of the message; one of the following values as defined in
   *   @link http://www.faqs.org/rfcs/rfc3164.html RFC 3164: @endlink
   *   - EMERGENCY: Emergency, system is unusable.
   *   - ALERT: Alert, action must be taken immediately.
   *   - CRITICAL: Critical conditions.
   *   - ERROR: Error conditions.
   *   - WARNING: Warning conditions.
   *   - NOTICE: (default) Normal but significant conditions.
   *   - INFO: Informational messages.
   *   - DEBUG: Debug-level messages.
   * @param $link
   *   A link to associate with the message.
   *
   * @see watchdog_severity_levels()
   */
  public function watchdog($type, $p_message, $variables = array(), $severity = self::NOTICE, $link = NULL) {
    static $in_error_state = FALSE;
    list ($caller, $caller1) = debug_backtrace(FALSE);
    if (isset($caller1['function'])) {
      $variables['@%%CALLER%%%'] = $caller1['function'] . (isset($caller['line']) ? ' (' . $caller['line'] . ')' : '');
      $message = '[@%%CALLER%%%]: ' . $p_message;
    }
    else {
      $message = $p_message;
    }
//    $this->app['db']->addDebug(['msg' => 'watchdog called', 'type' => $type], 1);
    // It is possible that the error handling will itself trigger an error. In that case, we could
    // end up in an infinite loop. To avoid that, we implement a simple static semaphore.
    if (!$in_error_state) {
      $in_error_state = TRUE;

      // Prepare the fields to be logged
      $log_entry = array(
        'type' => $type,
        'message' => $message,
        'variables' => $variables,
        'severity' => $severity,
        'link' => $link,
        'request_uri' => $_SERVER['REQUEST_URI'],
        'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
        'ip' => $this->ip_address(),
        // Request time isn't accurate for long processes, use time() instead.
        'timestamp' => time(),
        'app_prefix' => $this->db()->get_app_prefix(),
      );

      // Call the db log
      $this->db()->addDebug([
        'time' => date('d/m H:i:s'),
        'type' => 'watchdog',
        'msg' => $this->str_watchdog($log_entry),
        ], $this->debugLevel);
      $this->db_watchdog($log_entry);
//      $this->debug[] = $log_entry;
      // It is critical that the semaphore is only cleared here, in the parent
      // watchdog() call (not outside the loop), to prevent recursive execution.
      $in_error_state = FALSE;
    }
  }

  public function setDebugLevel($level) {
    $this->debugLevel = $level;
  }

  /**
   * Returns a list of severity levels, as defined in RFC 3164.
   *
   * @return
   *   Array of the possible severity levels for log messages.
   *
   * @see http://www.ietf.org/rfc/rfc3164.txt
   * @see watchdog()
   * @ingroup logging_severity_levels
   */
  public function watchdog_severity_levels() {
    return $this->__status['name'];
  }

  /**
   * Provide a integer $status_num and gets the associated level name
   *
   * @param type $status_num
   * @return type
   */
  public function getStatusNameFromNum($status_num) {
    return (isset($this->__status['name'][$status_num]) ? $this->__status['name'][$status_num] : NULL);
  }

  /**
   * Provide an error level name (such as 'WARNING') and get the associated status number
   *
   * @param type $status_name
   * @return type
   */
  public function getStatusNumFromName($status_name) {
    return (isset($this->__status['num'][$status_name]) ? $this->__status['num'][$status_name] : NULL);
  }

  /**
   * Clear the logs according to $options, an array where keys can be:
   * - type: Restrict to this error type
   * - severity: Restrict to this severity and less important
   * - since: Only remove entries that are older than $since (unix timestamp)
   *
   * Example call: clearLogs(['severity' => Watchdog::WARNING,
   *                         'type'     => 'curlHelper',
   *
   * This would remove all entries of severity WARNING, NOTICE, INFO and DEBUG
   * that are related to curlHelper, regardless of start date
   *
   * @param type $options
   */
  public function clearLogs($options) {
    // Get the options
    $type = (isset($options['type']) ? $options['type'] : '');
    $severity = (isset($options['severity']) ? $options['severity'] : self::EMERGENCY);
    $since = (isset($options['since']) ? $options['since'] : 0);

    // Build the query
    $query = 'DELETE FROM ' . $this->__schema . '.watchdog ';
    $query .= ' WHERE 1=1';
    if (!empty($since)) {
      $query .= ' AND timestamp <= ' . $this->db()->quote($since);
    }
    if (!empty($severity)) {
      $query .= ' AND severity >= ' . $this->db()->quote($severity);
    }
    if (!empty($type)) {
      $query .= ' AND type = ' . $this->db()->quote($type);
    }
    $this->db()->exec($query);
  }

  /**
   * Get watchdog string with id $wid
   *
   * @param type $wid
   * @param type $options
   */
  public function getWatchdog($options = []) {
    $base_query = "SELECT wid, type, message, convert_from(variables, 'UTF-8') as variables, severity, link, location, referer, hostname, timestamp";
    $base_query .= " FROM " . $this->__schema . ".watchdog ";

    // Conditions
    $conditions = [];

    // Add current app_prefix
    if (isset($options['app'])) {
      $conditions[] = "app_prefix = '" . $options['app'] . "'";
    }
    else {
      $app_prefix = $this->db()->get_app_prefix();
      $conditions[] = "app_prefix = '" . $app_prefix . "'";
    }

    // Specific wids
    if (!empty($options['wids'])) {
      $wids_str = \implode(',', explode(',', $options['wids']));
      $conditions[] = 'wid IN (' . $wids_str . ')';
    }

    // Search
    if (isset($options['search'])) {
      $search = $options['search'];
      $conditions[] = "lower(message) like '%" . strtolower($search) . "%' OR lower(variables::varchar) like '%" . strtolower($search) . "%'";
    }

    // Search
    if (isset($options['since'])) {
      $since_seconds = $this->toSeconds($options['since']);
      $now = new \DateTime();
      $result = $now->sub(new \DateInterval('PT' . $since_seconds . 'S'));
      $conditions[] = 'timestamp > ' . $result->getTimestamp();
    }

    // Footer
    $footer_query = '';
    if (isset($options['last'])) {
      $last = min([500, $options['last']]);
      $footer_query .= "LIMIT " . $last;
    }
    else {
      $footer_query .= "LIMIT 100";
    }

    // Build query
    $query = $this->query_toString($base_query, $conditions, $footer_query);
//    $this->watchdog('getWatchdog', 'Query is @q', array('@q' => $query));
    // Process results
    $w_str = [];
    foreach ($this->db()->getCollection($query) as $row) {
      $row['variables'] = unserialize($row['variables']);
//        $w_str[] = print_r($row, TRUE);
      $w_str[] = $this->str_watchdog($row, ['ts-format' => 'd/m H:i:s', 'with-wid' => TRUE]);
    }
    return $w_str;
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// PRIVATE ////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  protected function db() {
    return $this->app['db'];
  }

  /**
   * Returns the IP address of the client machine.
   *
   * If Drupal is behind a reverse proxy, we use the X-Forwarded-For header
   * instead of $_SERVER['REMOTE_ADDR'], which would be the IP address of
   * the proxy server, and not the client's. The actual header name can be
   * configured by the reverse_proxy_header variable.
   *
   * @return
   *   IP address of client machine, adjusted for reverse proxy and/or cluster
   *   environments.
   */
  private function ip_address() {
    if (!isset($this->_ip_address)) {
      $this->_ip_address = $_SERVER['REMOTE_ADDR'];

      if ($this->db()->getSetting('reverse_proxy', FALSE)) {
        $reverse_proxy_header = $this->db()->getSetting('reverse_proxy_header', 'HTTP_X_FORWARDED_FOR');
        if (!empty($_SERVER[$reverse_proxy_header])) {
          // If an array of known reverse proxy IPs is provided, then trust
          // the XFF header if request really comes from one of them.
          $reverse_proxy_addresses = $this->db()->getSetting('reverse_proxy_addresses', array());

          // Turn XFF header into an array.
          $forwarded = explode(',', $_SERVER[$reverse_proxy_header]);

          // Trim the forwarded IPs; they may have been delimited by commas and spaces.
          $forwarded = array_map('trim', $forwarded);

          // Tack direct client IP onto end of forwarded array.
          $forwarded[] = $ip_address;

          // Eliminate all trusted IPs.
          $untrusted = array_diff($forwarded, $reverse_proxy_addresses);

          // The right-most IP is the most specific we can trust.
          $this->_ip_address = array_pop($untrusted);
        }
      }
    }

    return $this->_ip_address;
  }

  private function db_watchdog(array $log_entry) {
    $query = 'INSERT INTO ' . $this->__schema . '.watchdog (type, message, variables, severity, link, location, referer, hostname, timestamp, app_prefix) '
      . ' VALUES ('
      . $this->db()->quote(substr($log_entry['type'], 0, 64)) . ', '
      . $this->db()->quote($log_entry['message']) . ', '
      . $this->db()->quote(str_replace('\\', '\\\\', serialize($log_entry['variables']))) . ', '
      . $this->db()->quote($log_entry['severity']) . ', '
      . $this->db()->quote(substr($log_entry['link'], 0, 255)) . ', '
      . $this->db()->quote($log_entry['request_uri']) . ', '
      . $this->db()->quote($log_entry['referer']) . ', '
      . $this->db()->quote(substr($log_entry['ip'], 0, 128)) . ', '
      . $this->db()->quote($log_entry['timestamp']) . ', '
      . $this->db()->quote($log_entry['app_prefix']) . ''
      . ');';
    $this->db()->exec($query);
  }

  /**
   * Returns a string showing the error message
   *
   * @param array $log_entry
   * @return type
   */
  private function str_watchdog(array $log_entry, $options = []) {
    $str = (isset($log_entry['wid']) && isset($options['with-wid']) ? '[' . $log_entry['wid'] . '] ' : '');
    $str .= (isset($options['ts-format']) ? date($options['ts-format'] . ' ', $log_entry['timestamp']) : '');
    $str .= $log_entry['type']
      . ': ('
      . $this->getStatusNameFromNum($log_entry['severity'])
      . ') '
      . strtr($log_entry['message'], $log_entry['variables']);
    return $str;
  }

  /**
   * Sends $log_entry by email
   *
   * @global type $base_url
   * @global type $language
   * @param type $log_entry
   */
  private function mail_watchdog($log_entry) {
    global $base_url, $language;

    $severity_list = $this->watchdog_severity_levels();

    $to = 'someone@example.com';
    $params = array();
    $params['subject'] = t('[@site_name] @severity_desc: Alert from your web site', array(
      '@site_name' => variable_get('site_name', 'Drupal'),
      '@severity_desc' => $severity_list[$log_entry['severity']],
    ));

    $params['message'] = "\nSite:          @base_url";
    $params['message'] .= "\nApp:          (@app)";
    $params['message'] .= "\nSeverity:     (@severity) @severity_desc";
    $params['message'] .= "\nTimestamp:    @timestamp";
    $params['message'] .= "\nType:         @type";
    $params['message'] .= "\nIP Address:   @ip";
    $params['message'] .= "\nRequest URI:  @request_uri";
    $params['message'] .= "\nReferrer URI: @referer_uri";
    $params['message'] .= "\nUser:         (@uid) @name";
    $params['message'] .= "\nLink:         @link";
    $params['message'] .= "\nMessage:      \n\n@message";

    $params['message'] = Helpers::format_string($params['message'], array(
        '@base_url' => $base_url,
        '@severity' => $log_entry['severity'],
        '@severity_desc' => $severity_list[$log_entry['severity']],
        '@timestamp' => Helpers::format_date($log_entry['timestamp']),
        '@type' => $log_entry['type'],
        '@ip' => $log_entry['ip'],
        '@request_uri' => $log_entry['request_uri'],
        '@referer_uri' => $log_entry['referer'],
        '@link' => strip_tags($log_entry['link']),
        '@message' => strip_tags($log_entry['message']),
        '@app' => strip_tags($log_entry['app_prefix']),
    ));

//    drupal_mail('emaillog', 'entry', $to, $language, $params);
  }

  /**
   * Build a valid watchdog sql query
   *
   * @param type $base_query
   * @param type $conditions
   * @param type $footer
   * @return string
   */
  private function query_toString($base_query, $conditions, $footer) {
    $condition_str = '';
    foreach ($conditions as $a_condition) {
      $condition_str .= (empty($condition_str) ? ' WHERE ' : ' AND ');
      $condition_str .= $a_condition;
    }
    $result = $base_query . $condition_str . ' ORDER BY wid DESC ' . $footer;

    return $result;
  }

  /**
   * This function converts a time span (such as 1w) to a number of seconds
   * We are accepting the following suffixes: y (years), M (months), w (weeks),
   * d (days), h (hours), m (min), s (sec), and a combination of those, e.g. 5w2d
   * years are considered to have 365 days, months considered to have 30 days,
   * so use y & m with caution
   *
   * @param type $time_string
   */
  private function toSeconds($time_string) {
    $reg = "/(\\d+)(y|M|w|d|h|m|s)/";

    $durations = [
      'y' => 86400 * 365,
      'M' => 86400 * 30,
      'w' => 86400 * 7,
      'd' => 86400,
      'h' => 3600,
      'm' => 60,
      's' => 1,
    ];
    $matches = [];
    $result = 0;
    preg_match_all($reg, $time_string, $matches);
    foreach ($matches[0] as $key => $val) {
      $result += $matches[1][$key] * $durations[$matches[2][$key]];
    }

    return $result;
  }

}
