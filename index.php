<?php
	// 
	$FEIDE_CONNECT_CONFIG_PATH = '/var/www/etc/ac-csv-import/feideconnect_config.js';
	$ADOBE_CONNECT_CONFIG_PATH = '/var/www/etc/ac-csv-import/adobe_config.js';
	//
	$BASE          = dirname(__FILE__);
	$API_BASE_PATH = '/api/ac-csv-import'; // Remember to update .htacces as well. Same with a '/' at the end...
	// Result or error responses
	require_once($BASE . '/lib/response.class.php');
	// Checks CORS and pulls FeideConnect info from headers
	require_once($BASE . '/lib/feideconnect.class.php');
	$feide_config = json_decode(file_get_contents($FEIDE_CONNECT_CONFIG_PATH), true);
	$feide        = new FeideConnect($feide_config);
	//  http://altorouter.com
	require_once($BASE . '/lib/router.class.php');
	$router = new Router();
	$router->setBasePath($API_BASE_PATH);
	// Proxy API to Adobe Connect
	require_once($BASE . '/lib/adobeconnect.class.php');
	$adobe_config = json_decode(file_get_contents($ADOBE_CONNECT_CONFIG_PATH), true);
	$connect      = new AdobeConnect($adobe_config);

// ---------------------- DEFINE ROUTES ----------------------


	/**
	 * GET all REST routes
	 */
	$router->map('GET', '/', function () {
		Response::result(array('status' => true, 'routes' => $GLOBALS['router']->getRoutes()));
	}, 'Routes listing');


	/**
	 * GET Adobe Connect version
	 */
	$router->map('GET', '/version/', function () {
		Response::result($GLOBALS['connect']->getConnectVersion());
	}, 'Adobe Connect version');


	/**
	 * Get all subfolders for Shared Meetings/{$orgFolderName}
	 */
	$router->map('GET', '/folder/[a:org]/nav/', function ($orgFolderName) {
		verifyOrgAccess($orgFolderName);
		Response::result($GLOBALS['connect']->getOrgFolderNav($orgFolderName));
	}, 'Org subfolders in Shared Meetings folder');


	/**
	 * CREATE rooms from POSTED data (CSV, prefix and folder)
	 */
	$router->map('POST', '/rooms/create/', function () {
		verifyOrgAccess($_POST['user_org_shortname']);
		Response::result($GLOBALS['connect']->createRooms($_POST));
	});


	/**
	 * CREATE users from POSTED data
	 */
	$router->map('POST', '/users/create/', function () {
		verifyOrgAccess($_POST['user_org_shortname']);
		Response::result($GLOBALS['connect']->createUsers($_POST));
	});


	// -------------------- UTILS -------------------- //

	// Make sure requested org name is the same as logged in user's org
	function verifyOrgAccess($orgName){
		if(strcasecmp($orgName, $GLOBALS['feide']->getUserOrg()) !== 0) {
			Response::error(401, $_SERVER["SERVER_PROTOCOL"] . ' 401 Unauthorized (request mismatch org/user). ');
		}
	}

	/**
	 *
	 *
	 * http://stackoverflow.com/questions/4861053/php-sanitize-values-of-a-array/4861211#4861211
	 */
	function sanitizeInput(){
		$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
		$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
	}

	// -------------------- ./UTILS -------------------- //

// UNUSED BELOW

/*
// Check if a folder with a:org exists on server (mostly for testing for now)
	$router->map('GET', '/folder/[a:org]/', function ($orgFolderName) {
		if(strcasecmp($orgFolderName, $GLOBALS['feide']->getUserOrg()) !== 0) {
			Response::error(401, $_SERVER["SERVER_PROTOCOL"] . ' 401 Unauthorized');
		}
		Response::result($GLOBALS['connect']->findOrgFolderSco($orgFolderName));
	});
*/




// ---------------------- MATCH AND EXECUTE REQUESTED ROUTE ----------------------
	$match = $router->match();

	if($match && is_callable($match['target'])) {
		sanitizeInput();
		call_user_func_array($match['target'], $match['params']);
	} else {
		Response::error(404, $_SERVER["SERVER_PROTOCOL"] . " The requested resource could not be found.");
	}
	// ---------------------- /.MATCH AND EXECUTE REQUESTED ROUTE ----------------------


