<?php
require 'vendor/autoload.php';

ini_set('error_reporting', E_ALL);

$f3 = \Base::instance();

$f3->config('config/internal.ini');

require rtrim($f3->get('forum.path'), '/') . '/config.inc.php';
require rtrim(RELATIVE_WCF_DIR, '/') . '/config.inc.php';

if ($f3->get('forum.db.auto') == "1") {
	$config = [
		'type' => 'mysql',
		'user' => $dbUser,
		'pass' => $dbPassword,
		'base' => $dbName,
		'host' => $dbHost,
		'port' => $dbPort ?? null,
	];
	$f3->merge('forum.db', $config, true);
}

if ($f3->get('eo.db.auto') == "1") {
	$f3->merge('eo.db', $f3->get('forum.db'), true);
}

define('EO_PREFIX', $f3->get('eo.db.prefix'));

$f3->run();
