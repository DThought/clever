<?
class Cleverly {
	public $left_delimiter = '{';
	public $right_delimiter = '}';
	protected $subs = array();

	public function display($template, $vars = array()) {
		$handle = fopen($template, 'r');
		array_push($this->subs, $vars);
		$pattern = '/' . preg_quote($this->left_delimiter, '/') . '(.*)' . preg_quote($this->right_delimiter, '/') . '/';
		$len = strlen($this->left_delimiter) + strlen($this->right_delimiter);

		$state = array(
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
			} elseif ($state['php']) {
				if (($pos = strpos($buffer, $this->left_delimiter . '/php' . $this->right_delimiter)) != false) {
					eval($php . substr($buffer, 0, $pos));
					$buffer = substr($buffer, $pos + $len + 4);
				} else {
					$php .= $buffer;
					continue;
				}
			}

			echo preg_replace_callback($pattern, function($matches) {
				if ($matches[1] == 'ldelim') {
					return $this->left_delimiter;
				} elseif ($matches[1] == 'rdelim') {
					return $this->right_delimiter;
				} elseif (substr($matches[1], 0, 12) == 'include_php ') {
					$args = explode(' ', $matches[1]);

					if (preg_match('/ file=(\'(.+)\'|\$(\w+)(.*?)) /', $matches[1] . ' ', $submatches)) {
						include($submatches[2] ? $submatches[2] : $this->apply_subs($submatches[3], $submatches[4]));
						return '';
					}

					throw new BadFunctionCallException;
				} elseif (substr($matches[1], 0, 8) == 'include ') {
					$args = explode(' ', $matches[1]);

					if (preg_match('/ name=(\w+)(.*?) /', $matches[1] . ' ', $submatches)) {
						$val = $this->apply_subs($submatches[1], $submatches[2]);
						$val();
						return '';
					} elseif (preg_match('/ file=(\'(.+)\'|\$(\w+)(.*?)) /', $matches[1] . ' ', $submatches)) {
						display($submatches[2] ? $submatches[2] : $this->apply_subs($submatches[3], $submatches[4]));
						return '';
					}

					throw new BadFunctionCallException;
				} elseif (isset($state[$matches[1]])) {
					$state[$matches[1]] = true;
					$php = '';
					return '';
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

	public function fetch($template) {
		ob_start();
		display($template);
		return ob_get_clean();
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
