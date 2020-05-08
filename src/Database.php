<?php
class Database extends pdo {
	private const PDO_OPTIONS = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_AUTOCOMMIT => true,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
	];

	private const TABLE_PREFIXES = [
		'{wbb}' => 'wbb' . WCF_N . '_',
		'{wcf}' => 'wcf' . WCF_N . '_',
		'{eo}' => EO_PREFIX,
	];

	public function __construct($config) {
		parent::__construct(sprintf('%s:host=%s;port=%s;dbname=%s',
			$config['type'], $config['host'], $config['port'], $config['base']),
			$config['user'], $config['pass'], self::PDO_OPTIONS);
	}

	private function wrap($query, $args) {
		$query = strtr($query, self::TABLE_PREFIXES);

		foreach($args as & $arg) {
			$arg = $this->quote($arg);
		}

		$num = substr_count($query, '?');
		while($num > count($args)) {
			$args[] = '?';
		}

		return vsprintf(str_replace('?', '%s', $query), $args);
	}

	public function doQuery() {
		$args = func_get_args();
		$query = array_shift($args);
		return parent::query($this->wrap($query, $args));
	}

	public function doPrepare() {
		$args = func_get_args();
		$query = array_shift($args);
		return parent::prepare($this->wrap($query, $args));
	}
}
