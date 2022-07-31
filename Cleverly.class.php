<?
class Cleverly {
  const OFFSET_CLOSE_TAG = 26;
  const OFFSET_CONTENT = 2;
  const OFFSET_FILE_VAR_EXTRA = 3;
  const OFFSET_FILE_VAR_NAME = 2;
  const OFFSET_FILE_STRING = 4;
  const OFFSET_IF_LEFT = 8;
  const OFFSET_IF_LEFT_NUMBER = 12;
  const OFFSET_IF_LEFT_STRING = 11;
  const OFFSET_IF_LEFT_VAR_EXTRA = 10;
  const OFFSET_IF_LEFT_VAR_NAME = 9;
  const OFFSET_IF_OPERATOR = 17;
  const OFFSET_IF_RIGHT_NUMBER = 22;
  const OFFSET_IF_RIGHT_STRING = 21;
  const OFFSET_IF_RIGHT_VAR_EXTRA = 20;
  const OFFSET_IF_RIGHT_VAR_NAME = 19;
  const OFFSET_OPEN_TAG = 4;
  const OFFSET_OPEN_ARGS = 5;
  const OFFSET_VAR_EXTRA = 28;
  const OFFSET_VAR_NAME = 27;
  const OFFSET_WHITESPACE = 1;
  const SUBPATTERN_VAR = '\$(\w+)([^\s}]*)';
  const TAG_FOREACH = 'foreach';
  const TAG_IF = 'if';
  const TAG_LITERAL = 'literal';
  const TAG_PHP = 'php';

  private $PATTERN_FILE;
  private $PATTERN_VAR;
  private $SUBPATTERN;
  private $SUBPATTERN_FILE;
  private $SUBPATTERN_VALUE;

  public $leftDelimiter = '{';
  public $preserveIndent = false;
  public $rightDelimiter = '}';
  protected $indent = array();
  protected $substitutions = array();
  protected $templateDir = array('templates');
  private $state;

  public function __construct() {
    $this->SUBPATTERN_FILE = self::SUBPATTERN_VAR . '|\'(.+?)\'';
    $this->SUBPATTERN_VALUE =
        $this->SUBPATTERN_FILE . '|((\d*\.)?\d+(e[+-]?(\d+))?)';
    $this->PATTERN_FILE = "/^($this->SUBPATTERN_FILE)$/";
    $this->PATTERN_VAR = sprintf('/^%s$/', self::SUBPATTERN_VAR);

    $this->SUBPATTERN = sprintf(
      '((\w+)((\s+(\w+)=[^\s}]+)*)|if\s+(%s)(\s+([^\s}]+)\s+(%s))?|\/(\w+)|%s)',
      $this->SUBPATTERN_VALUE,
      $this->SUBPATTERN_VALUE,
      self::SUBPATTERN_VAR
    );
  }

  private static function stripNewline($line) {
    return strlen($line) !== 0 && $line[-1] === "\n"
      ? substr($line, 0, -1)
      : $line;
  }

  public function addTemplateDir($dir, $key = null) {
    if (is_array($dir)) {
      $this->templateDir = array_merge($this->templateDir, $dir);
    } else if (is_null($key)) {
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
    array_push($this->substitutions, $variables);
    $pattern = '/(\s*)(' . preg_quote($this->leftDelimiter, '/') .
        $this->SUBPATTERN . preg_quote($this->rightDelimiter, '/') . ')/';
    $buffer = '';
    $foreach_from = null;
    $foreach_item = null;
    $if_evaluable = false;
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

                array_pop($this->indent);
                $buffer = '';
              }
            } else {
              if (@$set[self::OFFSET_IF_LEFT][0]) {
                array_push($this->state, self::TAG_IF);
              } else {
                switch ($set[self::OFFSET_OPEN_TAG][0]) {
                  case self::TAG_FOREACH:
                  case self::TAG_LITERAL:
                  case self::TAG_PHP:
                    array_push($this->state, $set[self::OFFSET_OPEN_TAG][0]);
                    break;
                }
              }

              $buffer .= $set[0][0];
            }

            break;
          case self::TAG_IF:
            if (@$set[self::OFFSET_CLOSE_TAG][0] === self::TAG_IF) {
              array_pop($this->state);

              if (count($this->state) !== 0) {
                $buffer .= $set[self::OFFSET_CONTENT][0];
              } else {
                if ($if_evaluable) {
                  $this->display('string:' . $buffer);
                }

                array_pop($this->indent);
                $buffer = '';
              }
            } else {
              if (@$set[self::OFFSET_IF_LEFT][0]) {
                array_push($this->state, self::TAG_IF);
              } else {
                switch ($set[self::OFFSET_OPEN_TAG][0]) {
                  case self::TAG_FOREACH:
                  case self::TAG_LITERAL:
                  case self::TAG_PHP:
                    array_push($this->state, $set[self::OFFSET_OPEN_TAG][0]);
                    break;
                }
              }

              $buffer .= $set[0][0];
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
            $if_operator = @$set[self::OFFSET_IF_OPERATOR][0];

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
                    preg_match($this->PATTERN_VAR, @$args['from'], $variable)
                  ) {
                    $foreach_from = $this->applySubstitutions(
                      $variable[1],
                      $variable[2],
                      false
                    );
                  } else if (
                    preg_match('/^\d+$/', @$args['loop'], $variable)
                  ) {
                    $foreach_from = range(0, $args['loop'] - 1);
                  } else {
                    throw new BadFunctionCallException(
                      'FOREACH tags must contain one of FROM or LOOP'
                    );
                  }

                  $foreach_item = preg_match('/^\w+$/', @$args['item'])
                    ? $args['item']
                    : '';
                  array_push($this->state, self::TAG_FOREACH);
                  echo $this->applyIndent($buffer);
                  array_push($this->indent, $this->getLastIndent() . $indent);
                  $buffer = '';
                  break;
                case 'include':
                  if (
                    preg_match($this->PATTERN_VAR, @$args['from'], $variable)
                  ) {
                    $value = $this->applySubstitutions(
                      $variable[1],
                      $variable[2],
                      false
                    );
                    ob_start();
                    $value();
                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline(
                      $this->applyIndent(ob_get_clean())
                    );

                    array_pop($this->indent);
                  } else if (
                    preg_match($this->PATTERN_FILE, @$args['file'], $file)
                  ) {
                    array_push($this->indent, $indent);

                    $buffer .= self::stripNewline(
                      $this->fetch(
                        @$file[self::OFFSET_FILE_STRING] ?:
                            $this->applySubstitutions(
                          $file[self::OFFSET_FILE_VAR_NAME],
                          $file[self::OFFSET_FILE_VAR_EXTRA],
                          false
                        )
                      )
                    );

                    array_pop($this->indent);
                  } else {
                    throw new BadFunctionCallException(
                      'INCLUDE tags must contain one of FROM or FILE'
                    );
                  }

                  break;
                case 'include_php':
                  if (preg_match($this->PATTERN_FILE, @$args['file'], $file)) {
                    ob_start();

                    include(
                      @$file[self::OFFSET_FILE_STRING] ?:
                          $this->applySubstitutions(
                        $file[self::OFFSET_FILE_VAR_NAME],
                        $file[self::OFFSET_FILE_VAR_EXTRA],
                        false
                      )
                    );

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
                  echo $this->applyIndent($buffer);
                  $buffer = $indent;
                  break;
                case 'rdelim':
                  $buffer .= $this->rightDelimiter;
                  break;
                default:
                  throw new BadFunctionCallException(
                    'Unrecognized tag ' . strtoupper($open_tag)
                  );
              }
            } else if ($set[self::OFFSET_IF_LEFT][0]) {
              $if_left = @$set[self::OFFSET_IF_LEFT_STRING][0] ?: (
                @$set[self::OFFSET_IF_LEFT_NUMBER][0]
                  ? (float)$set[self::OFFSET_IF_LEFT_NUMBER][0]
                  : $this->applySubstitutions(
                    $set[self::OFFSET_IF_LEFT_VAR_NAME][0],
                    $set[self::OFFSET_IF_LEFT_VAR_EXTRA][0],
                    true
                  )
              );

              if ($if_operator) {
                $if_right = @$set[self::OFFSET_IF_RIGHT_STRING][0] ?: (
                  @$set[self::OFFSET_IF_RIGHT_NUMBER][0]
                    ? (float)$set[self::OFFSET_IF_RIGHT_NUMBER][0]
                    : $this->applySubstitutions(
                      $set[self::OFFSET_IF_RIGHT_VAR_NAME][0],
                      $set[self::OFFSET_IF_RIGHT_VAR_EXTRA][0],
                      true
                    )
                );

                switch ($if_operator) {
                  case '==':
                  case 'eq':
                    $if_evaluable = $if_left == $if_right;
                    break;
                  case '!=':
                  case 'ne':
                  case 'neq':
                    $if_evaluable = $if_left != $if_right;
                    break;
                  case '>':
                  case 'gt':
                    $if_evaluable = $if_left > $if_right;
                    break;
                  case '<':
                  case 'lt':
                    $if_evaluable = $if_left < $if_right;
                    break;
                  case '>=':
                  case 'gte':
                  case 'ge':
                    $if_evaluable = $if_left >= $if_right;
                    break;
                  case '<=':
                  case 'lte':
                  case 'le':
                    $if_evaluable = $if_left <= $if_right;
                    break;
                  case '===':
                    $if_evaluable = $if_left === $if_right;
                    break;
                  default:
                    throw new BadFunctionCallException(sprintf(
                      'Unrecognized IF operator %s',
                      $if_operator
                    ));
                }
              } else {
                $if_evaluable = (bool)$if_left;
              }

              array_push($this->state, self::TAG_IF);
              echo $this->applyIndent($buffer);
              array_push($this->indent, $this->getLastIndent() . $indent);
              $buffer = '';
            } else if ($set[self::OFFSET_VAR_NAME][0]) {
              array_push($this->indent, $indent);

              $buffer .= $this->applyIndent((string)$this->applySubstitutions(
                $set[self::OFFSET_VAR_NAME][0],
                $set[self::OFFSET_VAR_EXTRA][0],
                false
              ));

              array_pop($this->indent);
            } else {
              throw new BadFunctionCallException(sprintf(
                'Invalid tag format %s',
                $set[0][0]
              ));
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
    array_pop($this->substitutions);
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
    if (!$this->preserveIndent || strlen($lines) === 0) {
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

  private function applySubstitutions($variable, $part, $nullable) {
    foreach ($this->substitutions as $substitution) {
      if (array_key_exists($variable, $substitution)) {
        $variable_substituted = $substitution[$variable];

        while (preg_match('/\.(\w+)|\[(\w+)\]/', $part, $indices)) {
          $index = @$indices[1] . @$indices[2];

          if (preg_match($this->PATTERN_VAR, $index, $subvariable)) {
            $index = $this->applySubstitutions(
              $subvariable[1],
              $subvariable[2],
              $nullable
            );
          }

          if (
            is_object($variable_substituted) &&
                property_exists($variable_substituted, $index)
          ) {
            $variable_substituted = $variable_substituted->$index;
          } else if (
            is_array($variable_substituted) &&
                array_key_exists($index, $variable_substituted)
          ) {
            $variable_substituted = $variable_substituted[$index];
          } else if ($nullable) {
            return null;
          } else {
            throw new OutOfBoundsException(
              "Variable $variable$part not found"
            );
          }

          $part = substr($part, strlen($indices[0]));
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
