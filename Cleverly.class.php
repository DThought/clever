<?
class Cleverly {
	public $left_delimiter = '{';
	public $right_delimiter = '}';
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
			$handle = fopen($template[0] == '/' ? $template : $this->template_dir . '/' . $template, 'r');
		}

		array_push($this->subs, $vars);
		$pattern = '/' . preg_quote($this->left_delimiter, '/') . '(.*?)' . preg_quote($this->right_delimiter, '/') . '/';
		$len = strlen($this->left_delimiter) + strlen($this->right_delimiter);

		$state = array(
			'foreach' => false,
			'literal' => false,
			'php' => false
		);

		while (($buffer = fgets($handle)) !== false) {
			if ($state['literal']) {
				if (($pos = strpos($buffer, $this->left_delimiter . '/literal' . $this->right_delimiter)) !== false) {
					echo substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + $len + 8);
				} else {
					echo $buffer;
					continue;
				}
			} elseif ($state['foreach']) {
				if (($pos = strpos($buffer, $this->left_delimiter . '/foreach' . $this->right_delimiter)) != false) {
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
				if (($pos = strpos($buffer, $this->left_delimiter . '/php' . $this->right_delimiter)) !== false) {
					eval($php . substr($buffer, 0, $pos));
					$buffer = substr($buffer, $pos + $len + 4);
					$state['php'] = false;
				} else {
					$php .= $buffer;
					continue;
				}
			}

			echo preg_replace_callback($pattern, function($matches) {
				global $php;
				global $state;

				if ($matches[1] == 'ldelim') {
					return $this->left_delimiter;
				} elseif ($matches[1] == 'rdelim') {
					return $this->right_delimiter;
				} elseif (substr($matches[1], 0, 8) == 'include ') {
					if (preg_match('/ name=(\w+)(.*?) /', $matches[1] . ' ', $submatches)) {
						$val = $this->apply_subs($submatches[1], $submatches[2]);
						ob_start();
						$val();
						return ob_get_clean();
					} elseif (preg_match('/ file=(\'(.+?)\'|\$(\w+)(.*?)) /', $matches[1] . ' ', $submatches)) {
						return $this->fetch($submatches[2] ? $submatches[2] : $this->apply_subs($submatches[3], $submatches[4]));
					}

					throw new BadFunctionCallException;
				} elseif (substr($matches[1], 0, 12) == 'include_php ') {
					if (preg_match('/ file=(\'(.+?)\'|\$(\w+)(.*?)) /', $matches[1] . ' ', $submatches)) {
						ob_start();
						include($this->template_dir . '/' . ($submatches[2] ? $submatches[2] : $this->apply_subs($submatches[3], $submatches[4])));
						return ob_get_clean();
					}

					throw new BadFunctionCallException;
				} elseif (isset($state[$matches[1]])) {
					$state[$matches[1]] = true;
					$php = '';
					return '';
				} elseif (preg_match('/^foreach \$(\w+)(.*?) as \$(\w+)$/', $matches[1], $submatches)) {
					$foreach = '';
					$foreach_loop = apply_subs($submatches[1], $submatches[2]);
					$foreach_name = $submatch[3];
				} elseif (preg_match('/^\$(\w+)(.*?)$/', $matches[1], $submatches)) {
					return $this->apply_subs($submatches[1], $submatches[2]);
				} else {
					throw new BadFunctionCallException;
				}
			}, $buffer);
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

	private function apply_subs($str1, $str2 = '') {
		foreach ($this->subs as $sub) {
			if (isset($sub[$str1])) {
				$val = $sub[$str1];

				while (preg_match('/\.(\w+)|\[(\w+)\]/', $str2, $matches)) {
					$match = $matches[1] . $matches[2];

					if (isset($val[$match])) {
						$val = $val[$match];
						$str2 = substr($str2, strlen($matches[0]));
					} else {
						throw new OutOfBoundsException;
					}
				}

				if (strlen($str2)) {
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
