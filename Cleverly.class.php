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
				if (($pos = strpos($buffer, $left_delimiter . '/literal' . $right_delimiter)) !== false) {
					echo substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + $len + 8);
				} else {
					echo $buffer;
					continue;
				}
			} elseif ($state['php']) {
				if (($pos = strpos($buffer, $left_delimiter . '/php' . $right_delimiter)) != false) {
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

					if (preg_match('/ file=(\'(.+)\'|\$(\w+)) /', $matches[1] . ' ', $submatches)) {
						include($submatches[2] ? $submatches[2] : apply_subs($submatches[3]));
						return '';
					}

					throw new BadFunctionCallException;
				} elseif (substr($matches[1], 0, 8) == 'include ') {
					$args = explode(' ', $matches[1]);

					if (preg_match('/ name=(\w+) /', $matches[1] . ' ', $submatches)) {
						foreach ($subs as $sub) {
							if (isset($sub[$str])) {
								$sub[$str]();
								return '';
							}
						}

						throw new OutOfBoundsException;
					} elseif (preg_match('/ file=(\'(.+)\'|\$(\w+)) /', $matches[1] . ' ', $submatches)) {
						display($submatches[2] ? $submatches[2] : $this->apply_subs($submatches[3]));
						return '';
					}

					throw new BadFunctionCallException;
				} elseif (isset($state[$matches[1]])) {
					$state[$matches[1]] = true;
					$php = '';
					return '';
				} elseif (preg_match('/^\$(\w+)$/', $matches[1], $submatches)) {
					return $this->apply_subs($submatches[1]);
				} else {
					throw new BadFunctionCallException;
				}
			}, $buffer);
		}

		array_pop($subs);
		fclose($handle);
	}

	public function fetch($template) {
		ob_start();
		display($template);
		return ob_get_clean();
	}

	private function apply_subs($str) {
		foreach ($this->subs as $sub) {
			if (isset($sub[$str])) {
				return $sub[$str];
			}
		}

		throw new OutOfBoundsException;
	}
}
?>
