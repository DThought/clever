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
  const TAG_FOREACH = 'foreach';
  const TAG_LITERAL = 'literal';
  const TAG_PHP = 'php';

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

    $this->state = array();
    array_push($this->subs, $vars);
    $pattern =
        '/' . preg_quote($this->leftDelimiter, '/') . self::SUBPATTERN .
        preg_quote($this->rightDelimiter, '/') . '/';
    $buffer = '';
    $indent = '';

    while ($line = fgets($handle)) {
      if ($line[-1] != "\n") {
        $line .= "\n";
      }

      if ($this->preserveIndent) {
        preg_match('/^\s*/', $line, $matches);
        $indent = $matches[0];
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

        switch (@$this->state[count($this->state) - 1]) {
          case self::TAG_FOREACH:
            if (@$set[self::OFFSET_CLOSE_TAG][0] == self::TAG_FOREACH) {
              array_pop($this->state);

              if (count($this->state)) {
                $buffer .= $set[0][0];
              } else {
                foreach ($foreach_from as $val) {
                  $this->display('string:' . $buffer, array(
                    $foreach_item => $val
                  ));
                }

                $buffer = $this->getLastIndent() . $indent;
              }
            } else {
              switch (@$set[self::OFFSET_OPEN_TAG][0]) {
                case self::TAG_FOREACH:
                case self::TAG_LITERAL:
                case self::TAG_PHP:
                  array_push($this->state, $set[self::OFFSET_OPEN_TAG][0]);
                  break;
              }

              $buffer .= $set[0][0];
            }

            break;
          case self::TAG_LITERAL:
            if (@$set[self::OFFSET_CLOSE_TAG][0] == self::TAG_LITERAL) {
              array_pop($this->state);
            } else {
              $buffer .= $set[0][0];
            }

            break;
          case self::TAG_PHP:
            if (@$set[self::OFFSET_CLOSE_TAG][0] == self::TAG_PHP) {
              array_pop($this->state);

              if (!count($this->state)) {
                eval($buffer);
                $buffer = $this->getLastIndent() . $indent;
              }
            } else {
              $buffer .= $set[0][0];
            }

            break;
          default:
            $open_tag = $set[self::OFFSET_OPEN_TAG][0];

            if ($open_tag) {
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

              switch ($open_tag) {
                case self::TAG_FOREACH:
                  if (preg_match(self::PATTERN_VAR, @$args['from'], $var)) {
                    $foreach_from = $this->applySubs($var[1], $var[2]);
                  } elseif (
                    preg_match('/^\d+$/', @$args['loop'], $var)
                  ) {
                    $foreach_from = range(0, $args['loop'] - 1);
                  } else {
                    throw new BadFunctionCallException;
                  }

                  if (preg_match('/^\w+$/', @$args['item'])) {
                    $foreach_item = $args['item'];
                  } else {
                    $foreach_item = '';
                  }

                  array_push($this->state, self::TAG_FOREACH);
                  array_push($this->indent, $this->getLastIndent() . $indent);
                  echo $this->applyIndent($buffer);
                  $buffer = $this->getLastIndent();
                  array_pop($this->indent);
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
                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline($this->fetch(
                      $submatches[2] ?: $this->applySubs(
                        $submatches[3],
                        $submatches[4]
                      )
                    ));

                    array_pop($this->indent);
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
                case self::TAG_LITERAL:
                case self::TAG_PHP:
                  array_push($this->state, $open_tag);
                  array_push($this->indent, $indent);
                  echo $this->applyIndent($buffer);
                  $buffer = $this->getLastIndent();
                  array_pop($this->indent);
                  break;
                case 'rdelim':
                  $buffer .= $this->rightDelimiter;
                  break;
                default:
                  throw new BadFunctionCallException;
              }
            } elseif ($set[self::OFFSET_VAR_NAME][0]) {
              $buffer .= $this->applySubs(
                $set[self::OFFSET_VAR_NAME][0],
                $set[self::OFFSET_VAR_EXTRA][0]
              );
            } else {
              throw new BadFunctionCallException;
            }

            break;
        }
      }

      if ($sets) {
        $set = $sets[count($sets) - 1];
        $buffer .= substr($line, $set[0][1] + strlen($set[0][0]));
      } else {
        $buffer .= $line;
      }
    }

    echo $this->applyIndent($buffer);
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

  private function applyIndent($str) {
    return str_replace(
      "\n",
      "\n" . $this->getLastIndent(),
      substr($str, 0, -1)
    ) . "\n";
  }

  private function getLastIndent() {
    return empty($this->indent) ? '' : $this->indent[count($this->indent) - 1];
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
