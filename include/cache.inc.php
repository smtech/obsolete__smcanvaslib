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
 * Return cached data if present, false otherwise
 **/
function getCache($key, $id, $cache) {
	$acceptableCache = date('Y-m-d H:i:s', time() - CACHE_DURATION);
	if ($response = mysqlQuery("
		SELECT *
			FROM `" . CACHE_TABLE . "`
			WHERE
				`{$key}` = '{$id}' AND
				`timestamp` > '{$acceptableCache}'
	")) {
		if ($cachedData = $response->fetch_assoc()) {
			return unserialize($cachedData[$cache]);
		}	
	}
	
	return false;
}

/**
 * Cache some data
 **/
function setCache($key, $id, $cache, $cachedData) {
	resetCache($key, $id);
	mysqlQuery("
		INSERT
			INTO `" . CACHE_TABLE . "`
			(
				`{$key}`,
				`{$cache}`
			) VALUES (
				'{$id}',
				'" . serialize($cachedData) . "'
			)
	");
}

/**
 * Reset a cache
 **/
function resetCache($key, $id) {
	mysqlQuery("
		DELETE
			FROM `" . CACHE_TABLE . "`
			WHERE
				`{$key}` = '{$id}'
	");
}

?>