<?php

require_once(__DIR__ . '/../../config.inc.php');
require_once(SMCANVASLIB_PATH . '/include/mysql.inc.php');

if(!defined('CACHE_TABLE')) {
	define('CACHE_TABLE', 'cache');
}

if(!defined('CACHE_DURATION')) {
	define('CACHE_DURATION', 60/*min*/ * 60/*sec*/);
}

/**
 * Retrieve cached data, false if no cache
 **/
function getCache($key, $id, $cache) {
	$acceptableCache = date('Y-m-d H:i:s',time() - CACHE_DURATION);
	if ($response = mysqlQuery("
			SELECT *
				FROM `" . CACHE_TABLE . "`
				WHERE
					`{$key}` = '{$id}' AND
					`timestamp` > '{$acceptableCache}'
		")) {
		if ($cacheData = $response->fetch_assoc()) {
			return unserialize($cacheData[$cache])
		}
	}
	
	return false;
}

/**
 * Cache some data for later retrieval
 **/
function setCache($key, $id, $cache, $cacheData) {
	mysqlQuery("
		DELETE *
			FROM `" . CACHE_TABLE . "`
			WHERE
				`{$key}` = '{$id}'
	");
	mysqlQuery("
		INSERT INTO `" . CACHE_TABLE . "`
			(
				`{$key}`,
				`{$cache}`
			) VALUES (
				'{$id}',
				'" . serialize($cacheData) . "'
			)
	");
}

?>