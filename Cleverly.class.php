<?
class Cleverly {
  const PATTERN_FILE = '/^(\'(.+?)\'|\$(\w+)(.*?))$/';
  const PATTERN_VAR = '/^\$(\w+)(.*?)$/';

  public $leftDelimiter = '{';
  public $preserveIndent = false;
  public $rightDelimiter = '}';
  protected $indent = array();
  protected $subs = array();
  protected $templateDir = '.';
  private $php;
  private $state;

  private static function stripNewline($str) {
    return $str && $str[-1] == "\n" ? substr($str, 0, -1) : $str;
  }

  public function display($template, $vars = array()) {
    if (substr($template, 0, 7) == 'string:') {
      $handle = tmpfile();
      fwrite($handle, substr($template, 7));
      fseek($handle, 0);
    } else {
      $handle = fopen(
        $template[0] == '/' ? $template : $this->templateDir . '/' . $template,
        'r'
      );
    }

    array_push($this->subs, $vars);
    $pattern_function =
        '/' . preg_quote($this->leftDelimiter, '/') .
        '(\w+)((\s+(\w+)=\S+)*)' .
        preg_quote($this->rightDelimiter, '/') . '/';
    $len = strlen($this->leftDelimiter) + strlen($this->rightDelimiter);

    $this->state = array(
      'foreach' => false,
      'literal' => false,
      'php' => false
    );

    while ($buffer = fgets($handle)) {
      if ($buffer[-1] != "\n") {
        $buffer .= "\n";
      }

      if ($this->state['literal']) {
        $pos = strpos(
          $buffer,
          $this->leftDelimiter . '/literal' . $this->rightDelimiter
        );

        if ($pos !== false) {
          echo substr($buffer, 0, $pos);
          $buffer = substr($buffer, $pos + $len + 8);
        } else {
          echo $buffer;
          continue;
        }
      } elseif ($this->state['foreach']) {
        $pos = strpos(
          $buffer,
          $this->leftDelimiter . '/foreach' . $this->rightDelimiter
        );

        if ($pos != false) {
          $foreach .= substr($buffer, 0, $pos);

          foreach ($foreach_loop as $val) {
            $this->display('string:' . $foreach, array(
              $foreach_name => $val
            ));
          }

          $buffer = substr($buffer, $pos + $len + 8);
        } else {
          $foreach .= $buffer;
          continue;
        }
      } elseif ($this->state['php']) {
        $pos = strpos(
          $buffer,
          $this->leftDelimiter . '/php' . $this->rightDelimiter
        );

        if ($pos !== false) {
          eval($this->php . substr($buffer, 0, $pos));
          $buffer = substr($buffer, $pos + $len + 4);
          $this->state['php'] = false;
        } else {
          $this->php .= $buffer;
          continue;
        }
      }

      if ($this->preserveIndent) {
        preg_match('/^\s*/', $buffer, $matches);
        array_push($this->indent, $matches[0]);
      }

      $buffer = preg_replace_callback($pattern_function, function($matches) {
        $args = call_user_func_array('array_merge', array_map(function($arg) {
          $parts = explode('=', $arg, 2);
          return array(
            $parts[0] => $parts[1]
          );
        }, preg_split('/\s+/', $matches[2], NULL, PREG_SPLIT_NO_EMPTY)));

        switch ($matches[1]) {
          case 'foreach':
            if (
              preg_match(self::PATTERN_FILE, @$args['from'], $submatches) and
                  preg_match('/^\w+$/', @$args['item'])
            ) {
              $foreach = '';
              $foreach_loop = $this->applySubs($submatches[1], $submatches[2]);
              $foreach_name = $args['item'];
              return '';
            } else {
              throw new BadFunctionCallException;
            }
          case 'include':
            if (preg_match(self::PATTERN_VAR, @$args['from'], $submatches)) {
              $val = $this->applySubs($submatches[1], $submatches[2]);
              ob_start();
              $val();
              return self::stripNewline(ob_get_clean());
            } elseif (preg_match(
              self::PATTERN_FILE,
              @$args['file'],
              $submatches
            )) {
              return self::stripNewline($this->fetch(
                $submatches[2]
                  ? $submatches[2]
                  : $this->applySubs($submatches[3], $submatches[4])
              ));
            } else {
              throw new BadFunctionCallException;
            }
          case 'include_php':
            if (preg_match(
              self::PATTERN_FILE,
              @$args['file'],
              $submatches
            )) {
              ob_start();

              include($this->templateDir . '/' . (
                $submatches[2]
                  ? $submatches[2]
                  : $this->applySubs($submatches[3], $submatches[4])
              ));

              return self::stripNewline(ob_get_clean());
            } else {
              throw new BadFunctionCallException;
            }
          case 'ldelim':
            return $this->leftDelimiter;
          case 'rdelim':
            return $this->rightDelimiter;
          default:
            if (isset($this->state[$matches[1]])) {
              $this->state[$matches[1]] = true;
              $this->php = '';
              return '';
            } elseif (preg_match(self::PATTERN_VAR, $matches[1], $submatches)) {
              return $this->applySubs($submatches[1], $submatches[2]);
            } else {
              throw new BadFunctionCallException;
            }
        }
      }, $buffer);

      echo $this->preserveIndent
        ? str_replace(
            "\n",
            "\n" . implode($this->indent),
            substr($buffer, 0, -1)
          ) . "\n"
        : $buffer;

      if ($this->preserveIndent) {
        array_pop($this->indent);
      }
    }

    array_pop($this->subs);
    fclose($handle);
  }

  public function fetch($template, $vars = array()) {
    ob_start();
    $this->display($template, $vars);
    return ob_get_clean();
  }

  public function setTemplateDir($dir) {
    $this->templateDir = $dir;
  }

  private function applySubs($var, $part = '') {
    foreach ($this->subs as $sub) {
      if (isset($sub[$var])) {
        $val = $sub[$var];

        while (preg_match('/\.(\w+)|\[(\w+)\]/', $part, $matches)) {
          $match = $matches[1] . $matches[2];

          if (isset($val[$match])) {
            $val = $val[$match];
            $part = substr($part, strlen($matches[0]));
          } else {
            throw new OutOfBoundsException;
          }
        }

        if (strlen($part)) {
          throw new BadFunctionCallException;
        } else {
          return $val;
        }
      }
    }

    throw new OutOfBoundsException;
  }
}
?>
