<?
class Cleverly {
  public $left_delimiter = '{';
  public $preserve_indent = false;
  public $right_delimiter = '}';
  protected $indent = array();
  protected $subs = array();
  protected $template_dir = '.';

  public function display($template, $vars = array()) {
    global $php;
    global $state;

    if (substr($template, 0, 7) == 'string:') {
      $handle = tmpfile();
      fwrite($handle, substr($template, 7));
      fseek($handle, 0);
    } else {
      $handle = fopen(
        $template[0] == '/' ? $template : $this->template_dir . '/' . $template,
        'r'
      );
    }

    array_push($this->subs, $vars);
    $pattern =
        '/' . preg_quote($this->left_delimiter, '/') . '(.*?)' .
        preg_quote($this->right_delimiter, '/') . '/';
    $len = strlen($this->left_delimiter) + strlen($this->right_delimiter);

    $state = array(
      'foreach' => false,
      'literal' => false,
      'php' => false
    );

    while ($buffer = fgets($handle)) {
      if ($buffer[-1] != "\n") {
        $buffer .= "\n";
      }

      if ($state['literal']) {
        $pos = strpos(
          $buffer,
          $this->left_delimiter . '/literal' . $this->right_delimiter
        );

        if ($pos !== false) {
          echo substr($buffer, 0, $pos);
          $buffer = substr($buffer, $pos + $len + 8);
        } else {
          echo $buffer;
          continue;
        }
      } elseif ($state['foreach']) {
        $pos = strpos(
          $buffer,
          $this->left_delimiter . '/foreach' . $this->right_delimiter
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
      } elseif ($state['php']) {
        $pos = strpos(
          $buffer,
          $this->left_delimiter . '/php' . $this->right_delimiter
        );

        if ($pos !== false) {
          eval($php . substr($buffer, 0, $pos));
          $buffer = substr($buffer, $pos + $len + 4);
          $state['php'] = false;
        } else {
          $php .= $buffer;
          continue;
        }
      }

      if ($this->preserve_indent) {
        preg_match('/^\s*/', $buffer, $matches);
        array_push($this->indent, $matches[0]);
      }

      $buffer = preg_replace_callback($pattern, function($matches) {
        global $php;
        global $state;

        if ($matches[1] == 'ldelim') {
          return $this->left_delimiter;
        } elseif ($matches[1] == 'rdelim') {
          return $this->right_delimiter;
        } elseif (substr($matches[1], 0, 8) == 'include ') {
          if (
            preg_match('/ name=(\w+)(.*?) /', $matches[1] . ' ', $submatches)
          ) {
            $val = $this->apply_subs($submatches[1], $submatches[2]);
            ob_start();
            $val();
            return $this->strip_newline(ob_get_clean());
          } elseif (preg_match(
            '/ file=(\'(.+?)\'|\$(\w+)(.*?)) /',
            $matches[1] . ' ',
            $submatches
          )) {
            return $this->strip_newline($this->fetch(
              $submatches[2]
                ? $submatches[2]
                : $this->apply_subs($submatches[3], $submatches[4])
            ));
          }

          throw new BadFunctionCallException;
        } elseif (substr($matches[1], 0, 12) == 'include_php ') {
          if (preg_match(
            '/ file=(\'(.+?)\'|\$(\w+)(.*?)) /',
            $matches[1] . ' ',
            $submatches
          )) {
            ob_start();

            include($this->template_dir . '/' . (
              $submatches[2]
                ? $submatches[2]
                : $this->apply_subs($submatches[3], $submatches[4])
            ));

            return $this->strip_newline(ob_get_clean());
          }

          throw new BadFunctionCallException;
        } elseif (isset($state[$matches[1]])) {
          $state[$matches[1]] = true;
          $php = '';
          return '';
        } elseif (preg_match(
          '/^foreach \$(\w+)(.*?) as \$(\w+)$/',
          $matches[1],
          $submatches
        )) {
          $foreach = '';
          $foreach_loop = $this->apply_subs($submatches[1], $submatches[2]);
          $foreach_name = $submatch[3];
        } elseif (preg_match('/^\$(\w+)(.*?)$/', $matches[1], $submatches)) {
          return $this->apply_subs($submatches[1], $submatches[2]);
        } else {
          throw new BadFunctionCallException;
        }
      }, $buffer);

      echo $this->preserve_indent
        ? str_replace(
            "\n",
            "\n" . implode($this->indent),
            substr($buffer, 0, -1)
          ) . "\n"
        : $buffer;

      if ($this->preserve_indent) {
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
    $this->template_dir = $dir;
  }

  private function apply_subs($var, $part = '') {
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

  private function strip_newline($str) {
    return $str && $str[-1] == "\n" ? substr($str, 0, -1) : $str;
  }
}
?>
