<?php
namespace Wef;

class PGDb extends \PDO{
  /**
   * For the in() function, will return all results if the $values are empty
   */
	const IN_ALL_IF_EMPTY = 1;
  protected $__schema;

  /**
   * For the in() function, will return no results if the $values are empty
   */
  const IN_NONE_IF_EMPTY = 0;

  protected static $debug = Array();
  protected static $_debug_level = 0; // Disable debug by default
  protected static $settings = NULL;

  public function __construct($dsn_from_env_var) {
    parent::__construct(self::parseDsnFromEnv($dsn_from_env_var));
    $this->__schema = $_ENV['APP_SCHEMA'];
    $this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
    $this->setAttribute(self::ATTR_DEFAULT_FETCH_MODE, self::FETCH_ASSOC);
  }

  public static function parseDsnFromEnv($dsn_from_env_var) {
    if (!array_key_exists($dsn_from_env_var, $_ENV)) {
      throw new \Exception('Database configuration is missing');
    }
    $data = parse_url($_ENV[$dsn_from_env_var]);


    switch ($data['scheme']) {
      case 'postgres':
        return 'pgsql:host=' . $data['host'] . ';dbname=' . substr($data['path'], 1) . ';user=' . $data['user'] . ';port=' . $data['port'] . ';sslmode=require;password=' . $data['pass'];
    }
  }

  public function getDebugInfo() {
    return self::$debug;
  }

  public function addDebug(array $info, $debug_level = NULL) {
    $cur_level = (!empty($debug_level) ? $debug_level : self::$_debug_level);
    if (1 <= $cur_level) {
      self::$debug[] = $info;
    }
  }

  public function setDebugLevel($level) {
    self::$debug[] = ['msg' => 'Set the debug level to ' . $level];
    self::$_debug_level = $level;
  }

  public function microtime_float() {
    return microtime(TRUE);
//      list($usec, $sec) = explode(" ", microtime());
//      return ((float)$usec + (float)$sec);
  }

  public function exec($query, $t_params = Array()) {
    $a = $this->microtime_float();
    $stmt = $this->prepare($query);
    if ($return = $stmt->execute($t_params)) {
      $rows = $stmt->rowCount();
      $return = $rows? : $return;
    }
    $this->addDebug(['q' => $query, 'time' => $this->microtime_float() - $a]);
    return $return;
  }

  public function &getRow($query, $t_params = Array(), $fetch_style = \PDO::FETCH_ASSOC) {
    if (!$query) {
      return false;
    }

    $a = $this->microtime_float();

    $stmt = $this->prepare($query);

    $res = $stmt->execute($t_params);

    if (!$res) {
      return false;
    }

    $r = $stmt->fetch($fetch_style);

    $this->addDebug(['q' => $query, 'time' => $this->microtime_float() - $a]);

    return $r;
  }

  protected function getStaticSettings($force = FALSE) {
    if (self::$settings === NULL || $force) {
      self::$settings = [];
      foreach ($this->getCollection("SELECT key, value from " . $this->__schema . ".settings") as $row) {
        self::$settings[trim($row['key'])] = $row['value'];
      }
    }

    return self::$settings;
  }

  public function get_app_prefix() {
    return (!empty($_ENV['APP_SETTINGS_PREFIX']) ? $_ENV['APP_SETTINGS_PREFIX'] : '');
  }

  public function getSetting($p_key, $default_value = NULL) {
    $key = $this->get_app_prefix() . $p_key;

    $settings = $this->getStaticSettings();

    if (array_key_exists($key, $settings)) {
      return $settings[$key];
    }

    return $default_value;
  }

  public function setSetting($p_key, $value) {
    $key = $this->get_app_prefix() . $p_key;

    $settings = $this->getStaticSettings();
    if (array_key_exists($key, $settings)) {
      if ($settings[$key] != $value) {
        $this->exec("UPDATE " . $this->__schema . ".settings SET value = " . $this->quote($value) . ", lastUpdateTS = NOW() WHERE key = " . $this->quote($key));
      }
    }
    else {
      $this->exec("INSERT INTO " . $this->__schema . ".settings (key, value) VALUES (" . $this->quote($key) . "," . $this->quote($value) . ")");
    }

    $this->getStaticSettings(TRUE);
  }

  public function &getCollection($query, $t_params = Array(), $fetch_style = \PDO::FETCH_ASSOC) {
    if (!$query) {
      return false;
    }

    $a = $this->microtime_float();

    $stmt = $this->prepare($query);

    $res = $stmt->execute($t_params);

    if (!$res) {
      return false;
    }

    $r = $stmt->fetchAll($fetch_style);

    $this->addDebug(['q' => $query, 'time' => $this->microtime_float() - $a]);

    return $r;
  }

  /**
   * Builds a string with the correct syntax for the IN parameter. The $mode parameter
   * indicates what happens if $values are empty. If IN_ALL_IF_EMPTY (default) is used,
   * it will return a (1=1) statement (always matching), if IN_NONE_IF_EMPTY is used it will return
   * a (0=1) statement (never matching)
   * Usage: in('a_field', ['abc','def','ghi']) will return the following string:
   * " a_field IN ('abc','def','ghi')"
   *
   * @param type $field
   * @param type $values
   * @param type $mode
   * @return type
   */
  public function in($field, $values, $mode = self::IN_ALL_IF_EMPTY) {
    $values_in = implode(',', array_map(function ($val) {
        return $this->quote($val);
      }, $values));
    $values_ok = (!empty($values_in) ? '(' . $values_in . ')' : '');
    return (!empty($values_ok) ? ' ' . $field . ' IN ' . $values_ok : ' (' . $mode . '=1)');
  }


}

