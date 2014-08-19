<?php
require_once(__DIR__ . '/../../.ignore.calendar-ics-authentication.inc.php');
require_once(__DIR__ . '/../../config.inc.php');
require_once(SMCANVASLIB_PATH . '/include/debug.inc.php');
require_once(SMCANVASLIB_PATH . '/include/page-generator.inc.php');
require_once(SMCANVASLIB_PATH . '/include/canvas-api.inc.php');
require_once(SMCANVASLIB_PATH . '/include/mysql.inc.php');

/* The authentication process runs as follows:
	0. Check if we're logged in
		If we are, rock and roll! Otherwise....
	1. The user enters their Canvas URL
	2. Request an authorization code from their Canvas instance to verify
	   their identity only
	3. Complete the identity request
	4. Look up their identity in our cache
		If they are cached, redirect to the original page requested,
		otherwise...
	6. Request a second authorization code from their Canvas instance to
	   acquire an access token
	7. Complete the access token request
	8. Cache their user information, then redirect to the original page
	   requested
*/

define('OAUTH_INCOMPLETE', 0);
define('OAUTH_URL_REQUESTED', 1); /* #1 completed */
define('OAUTH_IDENTITY_CODE_REQUESTED', 2); /* #2 completed */
define('OAUTH_TOKEN_CODE_REQUESTED', 3); /* #6 completed */
define('OAUTH_COMPLETE', 4); /* user cached with access token and
								$_SESSION updated -- after #4 or #8 */
if (!isset($argc)) {
	session_start();
}

/**
 * Ask the user to enter their Canvas instance URL. Updates the OAuth
 * process status for the session.
 **/
// TODO it would probably be nice to have some sort of drop-down of previously entered options, like ZenDesk
function displayCanvasUrlForm() {
	displayPage('
<form method="post" action="' . $_SERVER['PHP_SELF'] . '">
	<label for="url">Enter your Canvas url <span class="comment">FIXME pretty explanation</span></label>
	<input name="url" type="text" placeholder="' . CANVAS_URL_PLACEHOLDER . '" />
	<input type="submit" value="Log in" />
</form>
	');
	$_SESSION['oauth']['status'] = OAUTH_URL_REQUESTED;
	exit;
}

/**
 * Extract an store the Canvas url for this session. Return true on succes.
 **/
function isValidUrl() {
	if (isset($_REQUEST['url'])) {
		if ($_SESSION['oauth']['url'] = parse_url($_REQUEST['url'], PHP_URL_HOST)) {
			return true;
		}
	} else if (isset($_SESSION['oauth']['url'])) {
		return true;
	}
	return false;
}

/**
 * Send authorization code request as a redirect to the Canvas instance.
 * Updates the OAuth process status for the session.
 **/
function requestAuthorizationCode($url, $scopes = null) {
	if (isset($scopes)) {
		$_SESSION['oauth']['status'] = OAUTH_IDENTITY_CODE_REQUESTED;
	} else {
		$_SESSION['oauth']['status'] = OAUTH_TOKEN_CODE_REQUESTED;
	}
	header("Location: https://$url/login/oauth2/auth?" .
		'client_id=' . OAUTH_CLIENT_ID . '&' .
		'response_type=code&' .
		"redirect_uri={$_SESSION['oauth']['redirect_url']}&" .
		(isset($scopes) ? 'scopes=/auth/userinfo&' : '') .
		'purpose=' . TOOL_NAME . (
			$_SESSION['oauth']['status'] == OAUTH_IDENTITY_CODE_REQUESTED ?
				' (Authentication)' :
				' (API Access)'
		)
	);
	exit;
}

/**
 * Check if a user (and their token) has been cached. Update
 * $_SESSION['user'] if so and return true.
 **/
function isCached($url, $userId) {
	$userResponse = mysqlQuery("
		SELECT *
			FROM `users`
			WHERE
				`url` = 'https://$url/api/v1' AND
				`user[id]` = '$userId'
	");

	if ($user = $userResponse->fetch_assoc()) {
		/* test access token for validity, in case user has previously revoked
		   the cached token */
		$api = new CanvasApiProcess($user['url'], $user['access_token']);
		try {
			$api->get('users/self/profile', null, true);
		} catch (Exception $e) {
			return false;
		}

		$_SESSION['user'] = $user;
		return true;
	} else {
		mysqlQuery("
			INSERT INTO `users`
				(
					`url`,
					`user[id]`
				)
				VALUES (
					'https://$url/api/v1',
					'$userId'
				)
		");
		return false;
	}
}

/**
 * Complete identity verification or token request process via API
 **/
function requestIdentity($url, $code) {
	$authApi = new CanvasApiProcess("https://$url/login/oauth2", null);
	$authResponse = $authApi->post('token',
		array(
			'client_id' => OAUTH_CLIENT_ID,
			'redirect_uri' => $_SESSION['oauth']['redirect_url'],
			'client_secret' => OAUTH_CLIENT_KEY,
			'code' => $code
		)
	);

	if ($_SESSION['oauth']['status'] == OAUTH_IDENTITY_CODE_REQUESTED) {
		if (isCached($url, $authResponse['user']['id'])) {
			$_SESSION['oauth']['status'] = OAUTH_COMPLETE;
			landingPageRedirect();
		} else { /* not cached or invalid cached access_token, need a new
					access token */
			requestAuthorizationCode($url);
		}
	} else if ($_SESSION['oauth']['status'] == OAUTH_TOKEN_CODE_REQUESTED) {
		$apiEndPoint = "https://{$_SESSION['oauth']['url']}/api/v1";
		$api = new CanvasApiProcess($apiEndPoint, $authResponse['access_token']);
		$user = $api->get('users/self/profile');
		mysqlQuery("
			UPDATE `users`
				SET
					`access_token` = '{$authResponse['access_token']}'
				WHERE
					`url` = '$apiEndPoint' AND
					`user[id]` = '{$user['id']}'
		");
		if (isCached($url, $user['id'])) {
			$_SESSION['oauth']['status'] = OAUTH_COMPLETE;
			landingPageRedirect();
		} else {
			displayError(mysqlError(), false, 'Caching Error', 'FIXME pretty explanation');
			deauthenticate();
		}
	} else {
		displayError(array('OAuth Status' => $_SESSION['oauth']['status']), true, 'Authentication Error', 'FIXME pretty explanation');
		deauthenticate();
	}
}

/**
 * Send an authenticated, cached user on to their actual landing page.
 * Performs no validation checks!
 **/
function landingPageRedirect() {
	if (isset($_SESSION['landing_page'])) {
		header("Location: {$_SESSION['landing_page']}");
	} else {
		header('Location: ' . TOOL_START_PAGE);
	}
	exit;
}

/**
 * Check to see if there is an authenticated user. Includes a landing
 * page redirect URL, in case the user needs to be authenticated -- so
 * we can come back to wherever we started at!
 **/
function isAuthenticated($landingPage = null) {
	/* skip authentication and rely on cache if runnning from CLI */
	if (php_sapi_name() == 'cli') {
		return 'cli';
	}

	if (isset($landingPage)) {
		$_SESSION['landing_page'] = $landingPage;
	}
	if ($_SESSION['oauth']['status'] == OAUTH_COMPLETE && isset($_SESSION['user'])) {
		return true;
	} else {
		logout(); /* which becomes a login, after some clean up */
	}
}

/**
 * Clear session data and ready for a new login
 **/
function deauthenticate() {
	unset($_SESSION['oauth']);
	unset($_SESSION['user']);
	exit;
}

/**
 * Redirect to a new login
 **/
function logout() {
	header("Location: {$_SERVER['PHP_SELF']}");
	deauthenticate();
}

/**
 * Manage the OAuth process flow
 **/
function oauthStatusCheck() {
	if (!isset($_SESSION['oauth']['status'])) {
		$_SESSION['oauth']['status'] = OAUTH_INCOMPLETE;
	}

	/* consistent redirect URL for interaction with Canvas instance */
	if (!isset($_SESSION['oauth']['redirect_url'])) {
		$_SESSION['oauth']['redirect_url'] = APP_URL . '/' . basename($_SERVER['PHP_SELF']);
	}

	/* Check the OAuth process status and branch to the proper next step */
	switch ($_SESSION['oauth']['status']) {
		case OAUTH_INCOMPLETE:
			displayCanvasUrlForm();
			break;
		case OAUTH_URL_REQUESTED:
			if (isValidUrl()) {
				requestAuthorizationCode($_SESSION['oauth']['url'], '/auth/userinfo');
			} else {
				displayError($_REQUEST['url'], false, 'Invalid Canvas URL', 'FIXME pretty explanation');
				deauthenticate();
			}
			break;
		case OAUTH_IDENTITY_CODE_REQUESTED:
		case OAUTH_TOKEN_CODE_REQUESTED:
			if (isset($_REQUEST['code']) && isValidUrl()) {
				requestIdentity($_SESSION['oauth']['url'], $_REQUEST['code']);
			} else {
				displayError($_REQUEST['error'], false, 'Canvas Access Denied', 'FIXME pretty explanation');
				deauthenticate();
			}
			break;
		case OAUTH_COMPLETE:
			if (isAuthenticated()) {
				return true;
			} else {
				displayError(null, false, 'Authentication Error', 'FIXME pretty explanation');
				deauthenticate();
			}
			break;
	}
	return false;
}

/* skip OAuth entirely if running from the command line -- that means
   that we must be a scheduled cron job, and should simply use cached
   user information */
if (php_sapi_name() != 'cli') {
	/* enforce the OAuth process for anything other than CLI */
	if (!oauthStatusCheck() || isset($_REQUEST['logout'])) {
		logout();
	}	
}

?>