<?php

namespace Wef;

use \Exception;
use Wef\SfException;

class Helpers {

  protected static $debug = [];

  public function getDebugInfo(){
    return self::$debug;
  }

  public function __construct() {
  }

  //////////////////////////////////////////////////////////////////////////
  ///////////////////////////////// STATIC ////////////////////////////////
  //////////////////////////////////////////////////////////////////////////

  /**
   * Encodes special characters in a plain-text string for display as HTML.
   *
   * Also validates strings as UTF-8 to prevent cross site scripting attacks on
   * Internet Explorer 6.
   *
   * @param string $text
   *   The text to be checked or processed.
   *
   * @return string
   *   An HTML safe version of $text. If $text is not valid UTF-8, an empty string
   *   is returned and, on PHP < 5.4, a warning may be issued depending on server
   *   configuration (see @link https://bugs.php.net/bug.php?id=47494 @endlink).
   *
   * @see drupal_validate_utf8()
   * @ingroup sanitization
   */
  public static function check_plain($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  }

  /**
   * Formats text for emphasized display in a placeholder inside a sentence.
   *
   * Used automatically by format_string().
   *
   * @param $text
   *   The text to format (plain-text).
   *
   * @return
   *   The formatted text (html).
   */
  public static function placeholder($text) {
    return '<em class="placeholder">' . self::check_plain($text) . '</em>';
  }

  /**
   * Formats a string for HTML display by replacing variable placeholders.
   *
   * This function replaces variable placeholders in a string with the requested
   * values and escapes the values so they can be safely displayed as HTML. It
   * should be used on any unknown text that is intended to be printed to an HTML
   * page (especially text that may have come from untrusted users, since in that
   * case it prevents cross-site scripting and other security problems).
   *
   * In most cases, you should use t() rather than calling this function
   * directly, since it will translate the text (on non-English-only sites) in
   * addition to formatting it.
   *
   * @param $string
   *   A string containing placeholders.
   * @param $args
   *   An associative array of replacements to make. Occurrences in $string of
   *   any key in $args are replaced with the corresponding value, after optional
   *   sanitization and formatting. The type of sanitization and formatting
   *   depends on the first character of the key:
   *   - @variable: Escaped to HTML using check_plain(). Use this as the default
   *     choice for anything displayed on a page on the site.
   *   - %variable: Escaped to HTML and formatted using drupal_placeholder(),
   *     which makes it display as <em>emphasized</em> text.
   *   - !variable: Inserted as is, with no sanitization or formatting. Only use
   *     this for text that has already been prepared for HTML display (for
   *     example, user-supplied text that has already been run through
   *     check_plain() previously, or is expected to contain some limited HTML
   *     tags and has already been run through filter_xss() previously).
   *
   * @see t()
   * @ingroup sanitization
   */
  public static function format_string($string, array $args = array()) {
    // Transform arguments before inserting them.
    foreach ($args as $key => $value) {
      switch ($key[0]) {
        case '@':
          // Escaped only.
          $args[$key] = self::check_plain($value);
          break;

        case '%':
        default:
          // Escaped and placeholder.
          $args[$key] = self::placeholder($value);
          break;

        case '!':
          // Pass-through.
          break;
      }
    }
    return strtr($string, $args);
  }

  public static function format_date($timestamp, $type = 'medium', $format = '') {
    switch ($type) {
      case 'short':
        $format = 'm/d/Y - H:i';
        break;

      case 'long':
        $format = 'l, F j, Y - H:i';
        break;

      case 'custom':
        // No change to format.
        break;

      case 'medium':
      default:
        // Retrieve the format of the custom $type passed.
        if ('' === $format) {
          $format = 'D, m/d/Y - H:i';
        }
        break;
    }

    // Create a DateTime object from the timestamp.
    $date_time = date_create('@' . $timestamp);

    // Encode markers that should be translated. 'A' becomes '\xEF\AA\xFF'.
    // xEF and xFF are invalid UTF-8 sequences, and we assume they are not in the
    // input string.
    // Paired backslashes are isolated to prevent errors in read-ahead evaluation.
    // The read-ahead expression ensures that A matches, but not \A.
    $format = preg_replace(array('/\\\\\\\\/', '/(?<!\\\\)([AaeDlMTF])/'), array("\xEF\\\\\\\\\xFF", "\xEF\\\\\$1\$1\xFF"), $format);

    // Call date_format().
    $format = date_format($date_time, $format);

    // Translate the marked sequences.
    return preg_replace_callback('/\xEF([AaeDlMTF]?)(.*?)\xFF/', '_format_date_callback', $format);
  }

  /**
   * Translates a formatted date string.
   *
   * Callback for preg_replace_callback() within format_date().
   */
  private static function _format_date_callback(array $matches = NULL) {
    // We cache translations to avoid redundant and rather costly calls to format_string().
    static $cache;

    $code = $matches[1];
    $string = $matches[2];

    if (!isset($cache[$langcode][$code][$string])) {
      $options = array(
        'langcode' => $langcode,
      );

      if ($code == 'F') {
        $options['context'] = 'Long month name';
      }

      if ($code == '') {
        $cache[$langcode][$code][$string] = $string;
      }
      else {
        $cache[$langcode][$code][$string] = self::format_string($string, array());
      }
    }
    return $cache[$langcode][$code][$string];
  }

}
