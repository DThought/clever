<?
class Cleverly {
  const OFFSET_CLOSE_TAG = 6;
  const OFFSET_OPEN_TAG = 2;
  const OFFSET_OPEN_ARGS = 3;
  const OFFSET_VAR_EXTRA = 8;
  const OFFSET_VAR_NAME = 7;
  const PATTERN_FILE = '/^(\'(.+?)\'|\$(\w+)(.*?))$/';
  const PATTERN_VAR = '/^\$(\w+)(.*?)$/';
  const SUBPATTERN = '((\w+)((\s+(\w+)=\S+?)*)|\/(\w+)|\$(\w+)(.*?))';

  public $leftDelimiter = '{';
  public $preserveIndent = false;
  public $rightDelimiter = '}';
  protected $indent = array();
  protected $subs = array();
  protected $templateDir = '.';
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

    $this->state = array(
      'foreach' => 0,
      'literal' => false,
      'php' => false
    );

    array_push($this->subs, $vars);
    $pattern =
        '/' . preg_quote($this->leftDelimiter, '/') . self::SUBPATTERN .
        preg_quote($this->rightDelimiter, '/') . '/';
    $buffer = '';

    while ($line = fgets($handle)) {
      if ($line[-1] != "\n") {
        $line .= "\n";
      }

      if ($this->preserveIndent) {
        preg_match('/^\s*/', $line, $matches);
        array_push($this->indent, $matches[0]);
      }

      preg_match_all(
        $pattern,
        $line,
        $sets,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER
      );

      $offset = 0;

      foreach ($sets as $set) {
        $buffer .= substr($line, $offset, $set[0][1] - $offset);
        $offset = $set[0][1] + strlen($set[0][0]);

        if ($this->state['literal']) {
          if (@$set[self::OFFSET_CLOSE_TAG][0] == 'literal') {
            $this->state['literal'] = false;
          } else {
            $buffer .= $set[0][0];
          }
        } elseif ($this->state['php']) {
          if (@$set[self::OFFSET_CLOSE_TAG][0] == 'php') {
            $this->state['literal'] = false;

            if (!$this->state['foreach']) {
              eval($buffer);
              $buffer = implode('', $this->indent);
            }
          } else {
            $buffer .= $set[0][0];
          }
        } elseif ($this->state['foreach']) {
          if (@$set[self::OFFSET_CLOSE_TAG][0] == 'foreach') {
            $this->state['foreach']--;

            if ($this->state['foreach']) {
              $buffer .= $set[0][0];
            } else {
              foreach ($foreach_from as $val) {
                $this->display('string:' . $buffer, array(
                  $foreach_item => $val
                ));
              }

              $buffer = implode('', $this->indent);
            }
          } else {
            switch (@$set[self::OFFSET_OPEN_TAG][0]) {
              case 'literal':
              case 'php':
                $this->state[$set[self::OFFSET_OPEN_TAG][0]] == true;
                break;
              case 'foreach':
                $this->state['foreach']++;
                break;
            }

            switch (@$set[self::OFFSET_CLOSE_TAG][0]) {
              case 'literal':
              case 'php':
                $this->state[$set[self::OFFSET_CLOSE_TAG][0]] == false;
                break;
            }

            $buffer .= $set[0][0];
          }
        } elseif ($set[self::OFFSET_OPEN_TAG][0]) {
          $args = array_reduce(
            array_map(
              function($arg) {
                $parts = explode('=', $arg, 2);

                return array(
                  $parts[0] => $parts[1]
                );
              },
              preg_split(
                '/\s+/',
                $set[self::OFFSET_OPEN_ARGS][0],
                NULL,
                PREG_SPLIT_NO_EMPTY
              )
            ),
            'array_merge',
            array()
          );

          switch ($set[self::OFFSET_OPEN_TAG][0]) {
            case 'foreach':
              if (
                preg_match(self::PATTERN_VAR, @$args['from'], $var) and
                    preg_match('/^\w+$/', @$args['item'])
              ) {
                $foreach_from = $this->applySubs($var[1], $var[2]);
                $foreach_item = $args['item'];
                $this->state['foreach'] = 1;
                echo $this->addIndent($buffer);
                $buffer = implode('', $this->indent);
              } else {
                throw new BadFunctionCallException;
              }

              break;
            case 'include':
              if (preg_match(self::PATTERN_VAR, @$args['from'], $var)) {
                $val = $this->applySubs($var[1], $var[2]);
                ob_start();
                $val();
                $buffer .= self::stripNewline(ob_get_clean());
              } elseif (preg_match(
                self::PATTERN_FILE,
                @$args['file'],
                $submatches
              )) {
                $buffer .= self::stripNewline($this->fetch(
                  $submatches[2] ?: $this->applySubs(
                    $submatches[3],
                    $submatches[4]
                  )
                ));
              } else {
                throw new BadFunctionCallException;
              }

              break;
            case 'include_php':
              if (preg_match(
                self::PATTERN_FILE,
                @$args['file'],
                $submatches
              )) {
                ob_start();

                include($submatches[2] ?: $this->applySubs(
                  $submatches[3],
                  $submatches[4]
                ));

                $buffer .= self::stripNewline(ob_get_clean());
              } else {
                throw new BadFunctionCallException;
              }

              break;
            case 'ldelim':
              $buffer .= $this->leftDelimiter;
              break;
            case 'rdelim':
              $buffer .= $this->rightDelimiter;
              break;
            default:
              if (
                array_key_exists($set[self::OFFSET_OPEN_TAG][0], $this->state)
              ) {
                $this->state[$set[self::OFFSET_OPEN_TAG][0]] = true;
                echo $this->addIndent($buffer);
                $buffer = implode('', $this->indent);
              } else {
                throw new BadFunctionCallException;
              }

              break;
          }
        } elseif ($set[self::OFFSET_VAR_NAME][0]) {
          $buffer .= $this->applySubs(
            $set[self::OFFSET_VAR_NAME][0],
            $set[self::OFFSET_VAR_EXTRA][0]
          );
        } else {
          throw new BadFunctionCallException;
        }
      }

      if ($sets) {
        $set = $sets[count($sets) - 1];
        $buffer .= substr($line, $set[0][1] + strlen($set[0][0]));
      } else {
        $buffer .= $line;
      }

      if ($this->preserveIndent) {
        array_pop($this->indent);
      }
    }

    echo $this->addIndent($buffer);
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

  private function addIndent($str) {
    return str_replace(
      "\n",
      "\n" . implode($this->indent),
      substr($str, 0, -1)
    ) . "\n";
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
