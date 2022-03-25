<?
class Cleverly {
  const OFFSET_CLOSE_TAG = 8;
  const OFFSET_CONTENT = 2;
  const OFFSET_OPEN_TAG = 4;
  const OFFSET_OPEN_ARGS = 5;
  const OFFSET_WHITESPACE = 1;
  const OFFSET_VAR_EXTRA = 10;
  const OFFSET_VAR_NAME = 9;
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
  protected $templateDir = array('templates');
  private $state;

  private static function stripNewline($line) {
    return strlen($line) !== 0 && $line[-1] === "\n"
      ? substr($line, 0, -1)
      : $line;
  }

  public function addTemplateDir($dir, $key = null) {
    if (is_array($dir)) {
      $this->templateDir = array_merge($this->templateDir, $dir);
    } elseif (is_null($key)) {
      $this->templateDir[] = $dir;
    } else {
      $this->templateDir[$key] = $dir;
    }
  }

  public function display($template, $variables = array()) {
    if (substr($template, 0, 7) === 'string:') {
      $handle = tmpfile();
      fwrite($handle, substr($template, 7));
      fseek($handle, 0);
    } else {
      if ($template[0] === '/') {
        $handle = fopen($template);
      } else {
        $dirs = array_values($this->templateDir);

        for (
          $handle = null, $dir_number = 0;
          !$handle && $dir_number < count($dirs);
          $handle = @fopen($dirs[$dir_number++] . '/' . $template, 'r')
        );
      }
    }

    $this->state = array();
    array_push($this->subs, $variables);
    $pattern = '/(\s*)(' . preg_quote($this->leftDelimiter, '/') .
        self::SUBPATTERN . preg_quote($this->rightDelimiter, '/') . ')/';
    $buffer = '';
    $indent = '';
    $newline = true;

    while (($line = fgets($handle)) !== false) {
      if ($line[-1] !== "\n") {
        $line .= "\n";
        $newline = false;
      }

      preg_match_all(
        $pattern,
        $line,
        $sets,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER
      );

      $offset = 0;

      foreach ($sets as $set) {
        $indent = $set[self::OFFSET_WHITESPACE][0];
        $buffer .=
            substr($line, $offset, $set[self::OFFSET_WHITESPACE][1] - $offset);
        $offset = $set[self::OFFSET_CONTENT][1] +
            strlen($set[self::OFFSET_CONTENT][0]);

        switch (@$this->state[count($this->state) - 1]) {
          case self::TAG_FOREACH:
            if (@$set[self::OFFSET_CLOSE_TAG][0] === self::TAG_FOREACH) {
              array_pop($this->state);

              if (count($this->state) !== 0) {
                $buffer .= $set[self::OFFSET_CONTENT][0];
              } else {
                foreach ($foreach_from as $value) {
                  $this->display('string:' . $buffer, array(
                    $foreach_item => $value
                  ));
                }

                $buffer = '';
              }
            } else {
              switch (@$set[self::OFFSET_OPEN_TAG][0]) {
                case self::TAG_FOREACH:
                case self::TAG_LITERAL:
                case self::TAG_PHP:
                  array_push($this->state, $set[self::OFFSET_OPEN_TAG][0]);
                  break;
              }

              $buffer .= $set[self::OFFSET_CONTENT][0];
            }

            break;
          case self::TAG_LITERAL:
            if (@$set[self::OFFSET_CLOSE_TAG][0] === self::TAG_LITERAL) {
              array_pop($this->state);
            } else {
              $buffer .= $set[self::OFFSET_CONTENT][0];
            }

            break;
          case self::TAG_PHP:
            if (@$set[self::OFFSET_CLOSE_TAG][0] === self::TAG_PHP) {
              array_pop($this->state);

              if (!count($this->state)) {
                eval($buffer);
                $buffer = '';
              }
            } else {
              $buffer .= $set[self::OFFSET_CONTENT][0];
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
                  if (
                    preg_match(self::PATTERN_VAR, @$args['from'], $variable)
                  ) {
                    $foreach_from =
                        $this->applySubs($variable[1], $variable[2]);
                  } elseif (
                    preg_match('/^\d+$/', @$args['loop'], $variable)
                  ) {
                    $foreach_from = range(0, $args['loop'] - 1);
                  } else {
                    throw new BadFunctionCallException(
                      'FOREACH tags must contain one of FROM or LOOP'
                    );
                  }

                  if (preg_match('/^\w+$/', @$args['item'])) {
                    $foreach_item = $args['item'];
                  } else {
                    $foreach_item = '';
                  }

                  array_push($this->state, self::TAG_FOREACH);
                  array_push($this->indent, $this->getLastIndent() . $indent);
                  echo $this->applyIndent($buffer);
                  $buffer = '';
                  array_pop($this->indent);
                  break;
                case 'include':
                  if (
                    preg_match(self::PATTERN_VAR, @$args['from'], $variable)
                  ) {
                    $value = $this->applySubs($variable[1], $variable[2]);
                    ob_start();
                    $value();
                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline(
                      $this->applyIndent(ob_get_clean())
                    );

                    array_pop($this->indent);
                  } elseif (
                    preg_match(self::PATTERN_FILE, @$args['file'], $submatches)
                  ) {
                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline($this->fetch(
                      $submatches[2] ?: $this->applySubs(
                        $submatches[3],
                        $submatches[4]
                      )
                    ));

                    array_pop($this->indent);
                  } else {
                    throw new BadFunctionCallException(
                      'INCLUDE tags must contain one of FROM or FILE'
                    );
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

                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline(
                      $this->applyIndent(ob_get_clean())
                    );

                    array_pop($this->indent);
                  } else {
                    throw new BadFunctionCallException(
                      'INCLUDE_PHP tags must contain FILE'
                    );
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
                  $buffer = $indent;
                  array_pop($this->indent);
                  break;
                case 'rdelim':
                  $buffer .= $this->rightDelimiter;
                  break;
                default:
                  throw new BadFunctionCallException(
                    'Unrecognized tag ' . strtoupper($open_tag)
                  );
              }
            } elseif ($set[self::OFFSET_VAR_NAME][0]) {
              array_push($this->indent, $indent);

              $buffer .= $this->applyIndent((string)$this->applySubs(
                $set[self::OFFSET_VAR_NAME][0],
                $set[self::OFFSET_VAR_EXTRA][0]
              ));

              array_pop($this->indent);
            } else {
              throw new BadFunctionCallException("Invalid tag format");
            }

            break;
        }
      }

      if (count($sets) !== 0) {
        $set = $sets[count($sets) - 1];
        $buffer .= substr($line, $set[self::OFFSET_CONTENT][1] +
            strlen($set[self::OFFSET_CONTENT][0]));
      } else {
        $buffer .= $line;
      }
    }

    echo $this->applyIndent($newline ? $buffer : substr($buffer, 0, -1));
    array_pop($this->subs);
    fclose($handle);
  }

  public function fetch($template, $variables = array()) {
    ob_start();
    $this->display($template, $variables);
    return ob_get_clean();
  }

  public function getTemplateDir($key = null) {
    return is_null($key) ? $this->templateDir : $this->templateDir[$key];
  }

  public function setTemplateDir($dir) {
    $this->templateDir = is_array($dir) ? $dir : array($dir);
  }

  private function applyIndent($lines) {
    if (strlen($lines) === 0) {
      return $lines;
    }

    $indent = $this->getLastIndent();
    $newline = $lines[-1] === "\n";

    return $indent . str_replace(
      "\n",
      "\n$indent",
      $newline ? substr($lines, 0, -1) : $lines
    ) . ($newline ? "\n" : '');
  }

  private function getLastIndent() {
    return count($this->indent) !== 0
      ? $this->indent[count($this->indent) - 1]
      : '';
  }

  private function applySubs($variable, $part = '') {
    foreach ($this->subs as $substitution) {
      if (array_key_exists($variable, $substitution)) {
        $variable_substituted = $substitution[$variable];

        while (preg_match('/\.(\w+)|\[(\w+)\]/', $part, $matches)) {
          $match = @$matches[1] . @$matches[2];

          if (array_key_exists($match, $variable_substituted)) {
            $variable_substituted = $variable_substituted[$match];
            $part = substr($part, strlen($matches[0]));
          } else {
            throw new OutOfBoundsException(
              "Variable $variable$part not found"
            );
          }
        }

        if (strlen($part)) {
          throw new OutOfBoundsException("Variable $variable$part not found");
        }

        return $variable_substituted;
      }
    }

    throw new OutOfBoundsException("Variable $variable not found");
  }
}
?>
