<?php
	/**
	 * Class to create rooms in a Shared Meetings folder and create/add users as host.
	 *
	 *
	 * "For the folders, the difference between the User Meetings folders and the Shared Meetings folder is a
	 * matter of management. The access to and function of the room is not changed by where it lives on the server.
	 * What is affected is who has access to the server side functions of that meeting room. So if multiple people
	 * need to have access to the room (say you use it for weekly staff meetings) and there are a variety of
	 * individuals who may be hosting the room, then it should live in the Shared Meetings folder (preferably in
	 * a sub folder for organization) where all the individuals who may need access to the settings behind the scenes
	 * can get to it."
	 *
	 * http://forums.adobe.com/message/4325107
	 *
	 * @author Simon Skrodal
	 * @since  July 2015
	 */


	// Some calls take a long while so increase timeout limit from def. 30
	set_time_limit(300);	// 5 mins
	// Have experienced fatal error - allowed memory size of 128M exhausted - thus increase
	ini_set('memory_limit', '350M');

	class AdobeConnect {
		private $DEBUG = false;

		protected $config, $apiurl, $sessioncookie;
		protected $orgFolderSco = NULL;


		function __construct($config) {
			$this->config        = $config;
			$this->sessioncookie = NULL;
			$this->apiurl        = $this->config['connect-api-base'];
		}

		/** PUBLIC SCOPE **/

		public function getConnectVersion() {
			$apiCommonInfo = $this->callConnectApi(array('action' => 'common-info'), false);
			return array('status' => true, 'version' => (string)$apiCommonInfo->common->version);
		}

		// ---------------------------- FOLDER SEARCH ----------------------------

		/**
		 * Accessible by route. Returns all subfolders in folder "$org".
		 *
		 * @param $org
		 *
		 * @return array (with folders)
		 */
		public function getOrgFolderNav($org) {
			// Get SCO-ID of requested folder name. Will exit on errors/not found.
			$orgFolderSco = $this->_findInSharedMeetingsOrgFolderSco($org);
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			// Lists all of subfolders of org folder (filter limits response to folders only)
			$orgFolderNav = $this->callConnectApi(array(
				'action'      => 'sco-expanded-contents',
				'sco-id'      => $orgFolderSco,
				'filter-type' => 'folder',
				'sort-depth'  => 'desc'
			));
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);

			// 1. Check status
			if(strcasecmp((string)$orgFolderNav->status['code'], "ok") !== 0) {
				Response::error(400, 'Transaction with Adobe Connect failed: ' . (string)$orgFolderNav->status['subcode']);
			}
			// 2. Adobe returns an object if only a single sco (folder) was found, and an array otherwise.
			$orgFolderNav = $this->_responseToArray($orgFolderNav->{'expanded-scos'}->sco);

			// NOTE: We add sessioncookie here so that it can be reused in subsequent calls
			return (array('status' => true, 'data' => $orgFolderNav, 'token' => $this->sessioncookie));
		}

		/**
		 * Check to see if folder with name /Shared Meetings/{org} exist on the server.
		 * The function will exit with error headers if no sco was found.
		 *
		 * @param $org
		 *
		 * @return string (sco-id of requested folder)
		 */
		private function _findInSharedMeetingsOrgFolderSco($org) {
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			// Beware! Despite docs, search is not unique (will return any folder with 'query' as part of the name)
			$orgFolder = $this->callConnectApi(array(
				'action'           => 'sco-search-by-field',
				'query'            => $org,
				'field'            => 'name',
				'filter-folder-id' => $this->config['connect-shared-folder-id'],
				'filter-type'      => 'folder'
			));
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			$this->_logger(json_encode($orgFolder), __LINE__, __FUNCTION__);

			// 1. Check status
			if(strcasecmp((string)$orgFolder->status['code'], "ok") !== 0) {
				Response::error(400, 'Transaction with Adobe Connect failed: ' . (string)$orgFolder->status['subcode']);
			}
			// 2. Check content
			if(empty($orgFolder->{'sco-search-by-field-info'})) {
				Response::error(404, 'Could not find folder ' . $org . ' in the Shared Meetings folder of Adobe Connect.');
			}
			// 3. Adobe returns an object if only a single sco was found, and an array otherwise.
			$orgFolder = $this->_responseToArray($orgFolder->{'sco-search-by-field-info'}->sco);
			// 4. Ensure we have at least one folder
			if(count($orgFolder) === 0) {
				Response::error(404, 'Could not find folder ' . $org . ' in the Shared Meetings folder of Adobe Connect.');
			}

			// 5. Loop folders and narrow down to a single folder where name==org
			if(count($orgFolder) > 1) {
				foreach($orgFolder as $index => $folder) {
					// Delete if folder name is not exactly the same as org name
					if(strcasecmp($folder->name, $org) !== 0) {
						unset($orgFolder[$index]);
					}
				}
			}

			// 6. Grab sco-id of the org folder in 'Shared Meetings'
			$this->orgFolderSco = $orgFolder[0]['sco-id'];

			// Here's the SCO-ID of the folder we were looking for!
			return (string)$orgFolder[0]['sco-id'];
		}

		// ---------------------------- ./ FOLDER SEARCH ----------------------------


		// ---------------------------- ./ CREATE ROOMS/USERS ----------------------------

		/**
		 * Creates/checks rooms for all unique room IDs POSTED in the CSV.
		 *
		 * When complete, an object with metadata per room and associated users are returned
		 * to the client. This datastructure can be used in a new call to createUsers($postData).
		 *
		 * @param $postData
		 *
		 * @return array
		 */
		public function createRooms($postData) {
			// Get/set POST values
			$csv            = isset($postData['csv_data']) ? $postData['csv_data'] : false;
			$orgName        = isset($postData['user_org_shortname']) ? $postData['user_org_shortname'] : false;
			$roomFolderSco  = isset($postData['room_folder_sco']) ? $postData['room_folder_sco'] : false;
			$roomNamePrefix = isset($postData['room_name_prefix']) ? $postData['room_name_prefix'] : false;
			$token          = isset($postData['token']) ? $postData['token'] : false;
			// Check that all required data is here
			if(!$csv || !$orgName || !$roomFolderSco || !$roomNamePrefix) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// Use sessioncookie passed from client
			if($token !== false) {
				$this->sessioncookie = $token;
			}
			// To be sent back to client...
			$responseObj = array();
			// Pointer
			$currentRoom = false;
			// Loop all room-user pairs in the CSV
			foreach($csv as $room) {
				// Must be two columns only for each entry
				if(sizeof($room) !== 2) {
					Response::error(400, 'Malformed data structure. Cannot continue.');
				}
				// If we hit a new room id, check/make room on server
				if($currentRoom !== $room[0]) {
					// Update pointer
					$currentRoom = $room[0];
					// Function will check if room already exist and return new room or existing room metadata
					$createRoomResponse                = $this->_createMeetingRoom($roomFolderSco, $roomNamePrefix, $currentRoom);
					$responseObj[$currentRoom]['room'] = $createRoomResponse;
				}
				// Add user to array of current room
				$responseObj[$currentRoom]['users'][] = $room[1];
			}

			// Done :-)
			return ($responseObj);
		}

		/**
		 * Check if room exists, otherwise create it.
		 *
		 * Returns metadata of existing or newly created room.
		 *
		 * @param      $roomFolderSCO
		 * @param      $roomNamePrefix
		 * @param      $roomName
		 * @param bool $description
		 *
		 * @return SimpleXMLElement
		 */
		private function _createMeetingRoom($roomFolderSCO, $roomNamePrefix, $roomName, $description = false) {
			// Check if room (or folder with same name) exists. False or metadata from server
			$roomExistsResponse = $this->_checkMeetingRoomExists($roomFolderSCO, $roomNamePrefix, $roomName);
			// If we got metadata, no need to create room - just return the data
			if($roomExistsResponse !== false) {
				return $roomExistsResponse;
			}

			// Default room description if none given
			$description = !$description ? 'Autogenerert med tjeneste ConnectImport' : $description;

			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);

			// Room does not exist already - make folder to contain the room in the first instance:
			$apiCreateMeetingRoomFolderResult = $this->callConnectApi(
				array(
					'action'      => 'sco-update',
					'type'        => 'folder',
					'name'        => $roomNamePrefix . $roomName,
					'description' => $description,
					'folder-id'   => $roomFolderSCO
				)
			);
			// Folder creation fail.
			$statusCode = (string)$apiCreateMeetingRoomFolderResult->status['code'];
			if(strcasecmp($statusCode, "ok") !== 0) {
				Response::error(400, 'Failed to create room folder ' . $roomNamePrefix . $roomName . ': ' . (string)$apiCreateMeetingRoomFolderResult->status->{$statusCode}['subcode']);
			}

			// Get the SCO-ID for our newly created folder
			$newFolderId = (string)$apiCreateMeetingRoomFolderResult->sco['sco-id'];

			// Important: replace any spaces in prefix with '-'. 
			// Reason: The prefix also makes part of the generated URL - Connect API does not like spaces
			$roomNamePrefixURL = str_replace(' ', '-', $roomNamePrefix);
			// And take care of any other special chars 
			$roomNamePrefixURL = htmlspecialchars($roomNamePrefixURL);
			
			error_log($roomNamePrefixURL);

			// Create room in newly created folder
			$apiCreateMeetingRoomResult = $this->callConnectApi(
				array(
					'action'      => 'sco-update',
					'type'        => 'meeting',
					'name'        => $roomNamePrefix . $roomName,
					'description' => $description,
					'folder-id'   => $newFolderId, // $roomFolderSCO,
					'date-begin'  => '2015-07-01T09:00', // Dates are irrelevant
					'date-end'    => '2015-07-01T17:00',
					'url-path'    => $roomNamePrefixURL . $roomName,
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// If we get caught here, might as well exit (room exists check already done, so must be something else)
			$statusCode = (string)$apiCreateMeetingRoomResult->status['code'];
			if(strcasecmp($statusCode, "ok") !== 0) {
				Response::error(400, 'Failed to create room ' . $roomNamePrefix . $roomName . ': ' . (string)$apiCreateMeetingRoomResult->status->{$statusCode}['subcode']);
			}

			// Done :-)
			return array(
				'id'          => (string)$apiCreateMeetingRoomResult->sco['sco-id'],
				'folder_id'   => (string)$apiCreateMeetingRoomResult->sco['folder-id'],
				'name'        => (string)$apiCreateMeetingRoomResult->sco->name,
				'description' => (string)$apiCreateMeetingRoomResult->sco->description,
				'url_path'    => $this->config['connect-service-url'] . (string)$apiCreateMeetingRoomResult->sco->{'url-path'},
				'autocreated' => "true"
			);
		}


		/**
		 * Check if a room already exists. Return false if not, metadata if so.
		 *
		 * @param $roomFolderSCO
		 * @param $roomNamePrefix
		 * @param $roomName
		 *
		 * @return bool|SimpleXMLElement[]
		 */
		private function _checkMeetingRoomExists($roomFolderSCO, $roomNamePrefix, $roomName) {
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			$apiRoomSearchResult = $this->callConnectApi(
				array(
					'action'      => 'sco-search-by-field',
					'query'       => $roomNamePrefix . $roomName, // Combined makes room name
					'field'       => 'name',
					'filter-type' => 'meeting'
					//'filter-folder-id' => $roomFolderSCO // Only return rooms from this folder
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error
			if(strcasecmp((string)$apiRoomSearchResult->status['code'], "ok") !== 0) {
				Response::error(400, 'Unexpected response from Adobe Connect: Search for meeting room failed: ' . (string)$apiRoomSearchResult->status['subcode']);
			}
			// Ok search, but room does not exist (judged by missing sco)
			if(!isset($apiRoomSearchResult->{'sco-search-by-field-info'}->sco['sco-id'])) {
				return false;
			}

			// Room already exist - return response
			return array(
				'id'          => (string)$apiRoomSearchResult->{'sco-search-by-field-info'}->sco['sco-id'],
				'folder_id'   => (string)$apiRoomSearchResult->{'sco-search-by-field-info'}->sco['folder-id'],
				'name'        => (string)$apiRoomSearchResult->{'sco-search-by-field-info'}->sco->name,
				'description' => (string)$apiRoomSearchResult->{'sco-search-by-field-info'}->sco->description,
				'url_path'    => $this->config['connect-service-url'] . (string)$apiRoomSearchResult->{'sco-search-by-field-info'}->sco->{'url-path'},
				'autocreated' => "false"
			);
		}
// ---------------------------- ./ CREATE ROOMS ----------------------------


// ----------------------------  CREATE USERS ----------------------------


		/**
		 *
		 * @param $postData
		 *
		 * @return array
		 */
		public function createUsers($postData) {
			// Get/set POST values
			$orgName = isset($postData['user_org_shortname']) ? $postData['user_org_shortname'] : false;
			$data    = isset($postData['data']) ? $postData['data'] : false;
			$token   = isset($postData['token']) ? $postData['token'] : false;
			// Check that all required data is here
			if(!$data || !$orgName) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// Use sessioncookie passed from client
			if($token !== false) {
				$this->sessioncookie = $token;
			}
			// To be sent back to client...
			$responseObj = array();
			// Loop all object entries (one per room)
			foreach($data as $index => $roomAndUsers) {
				$roomSco   = $roomAndUsers['room']['id'];
				$folderSco = $roomAndUsers['room']['folder_id'];
				// Sanity check
				if(!isset($roomSco)) {
					Response::error(400, 'Missing SCO-ID of meeting room from POST data. Cannot continue without required data...');
				}
				// Store room details for response ()
				$responseObj[$index]['room'] = $roomAndUsers['room'];
				// Loop users associated with this room
				foreach($roomAndUsers['users'] as $user) {
					// Create or fetch user
					$apiUserMetaResult = $this->_createUserAccount($user);
					// Set as host in room ("true"/"false")
					$apiUserMetaResult['host'] = $this->_setUserAsHost($roomSco, $folderSco, $apiUserMetaResult['id']);
					// Store metadata from find/create user
					$responseObj[$index]['users'][] = $apiUserMetaResult;
				}
			}

			// Done :-)
			return ($responseObj);
		}

		/**
		 * Check if user exists, otherwise create it.
		 *
		 * Returns metadata of existing or newly created user.
		 *
		 * @param             $userName
		 * @param bool|string $firstName
		 * @param bool|string $lastName
		 *
		 * @return bool|SimpleXMLElement|SimpleXMLElement[]
		 */
		private function _createUserAccount($userName, $firstName = false, $lastName = false) {
			// Before we create, check if user already exists
			$userExists = $this->_checkUserExists($userName);
			// If we got metadata, no need to create user - just return the data
			if($userExists !== false) {
				return $userExists;
			}
			// Generates a random 20 char passwd for new users (Feide Auth on AC service disregards this, but API insists on a PW)
			$randomPassword = bin2hex(openssl_random_pseudo_bytes(10));
			// Default first/last names if none given (Connect requires that these be set)
			$defaultNames = explode('@', $userName);
			$defaultNames = $defaultNames[0];
			$firstName    = !$firstName ? $defaultNames : $firstName;
			$lastName     = !$lastName ? $defaultNames : $lastName;
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			// Note:
			//  - Combined max length of firstname/lastname is 60 chars.
			//    See http://help.adobe.com/en_US/connect/8.0/webservices/WS5b3ccc516d4fbf351e63e3d11a171dd0f3-7ff8_SP1.html
			$apiCreateUserResponse = $this->callConnectApi(
				array(
					'action'       => 'principal-update',
					'first-name'   => $firstName,
					'last-name'    => $lastName,
					'login'        => $userName,
					'password'     => $randomPassword,
					'type'         => 'user',
					'send-email'   => 'false',
					'has-children' => '0'
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error (we have already checked if user exists, so this is a more serious error).
			if(strcasecmp((string)$apiCreateUserResponse->status['code'], "ok") !== 0) {
				Response::error(400, 'Failed to create user ' . $userName . ': ' . (string)$apiCreateUserResponse->status['subcode']);
			}
			// If all ok, get the user's newly created Principal ID
			$userPrincipalID = (string)$apiCreateUserResponse->principal['principal-id'];
			// Another sanity check...
			if(!$userPrincipalID) {
				Response::error(400, 'Failed to create user; principal-id not found for user ' . $userName);
			}

			// Done :-)
			return array(
				'id'          => $userPrincipalID,
				'username'    => (string)$apiCreateUserResponse->principal->login,
				'autocreated' => "true"
			);
		}


		/**
		 * Check if a user exists. Returns false if not, otherwise user metadata.
		 *
		 * @param $userName
		 *
		 * @return bool|SimpleXMLElement[]
		 */
		private function _checkUserExists($userName) {
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			// Lookup account info for requested user
			$apiUserInfoResponse = $this->callConnectApi(
				array(
					'action'       => 'principal-list',
					'filter-login' => $userName
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error
			if(strcasecmp((string)$apiUserInfoResponse->status['code'], "ok") !== 0) {
				Response::error(400, 'User lookup failed: ' . $userName . ': ' . (string)$apiUserInfoResponse->status['subcode']);
			}
			// Ok search, but user does not exist (judged by missing metadata)
			if(!isset($apiUserInfoResponse->{'principal-list'}->principal)) {
				return false;
			}

			// Done :-)
			return array(
				'id'          => (string)$apiUserInfoResponse->{'principal-list'}->principal['principal-id'],
				'username'    => (string)$apiUserInfoResponse->{'principal-list'}->principal->login,
				'autocreated' => "false"
			);
		}

		/**
		 * Sets user as host for room and adds manage permissions to parent folder.
		 *
		 * @param $roomSco
		 * @param $folderSco
		 * @param $userSco
		 *
		 * @return SimpleXMLElement
		 */
		private function _setUserAsHost($roomSco, $folderSco, $userSco) {
			// NOTE!!!!!
			// You can specify multiple trios of principal-id, acl-id, and permission-id on one call to permissions-update.
			// http://help.adobe.com/en_US/connect/9.0/webservices/WS5b3ccc516d4fbf351e63e3d11a171ddf77-7fca_SP1.html
			// TODO: MUST POST VALUES for trios to work - look into this if jobs take too long

			// Meeting host
			$apiUserSetHostResponse = $this->callConnectApi(
				array(
					'action'        => 'permissions-update',
					'principal-id'  => $userSco, // HOST for ROOM
					'acl-id'        => $roomSco,
					'permission-id' => 'host'
				)
			);

			// Set user as folder manager (being host is not sufficient to access recordings etc in Connect Central)
			$apiUserSetFolderManagerResponse = $this->callConnectApi(
				array(
					'action'        => 'permissions-update',
					'principal-id'  => $userSco, // MANAGER for FOLDER
					'acl-id'        => $folderSco,
					'permission-id' => 'manage'
				)
			);

			// If setting user as host failed, return false and deal with in calling function
			if(strcasecmp((string)$apiUserSetHostResponse->status['code'], "ok") !== 0) {
				$this->_logger("Failed to set user as host: " . json_encode($apiUserSetHostResponse), __LINE__, __FUNCTION__);

				return "false";
			}

			return "true";
		}

// ---------------------------- ./ CREATE USERS ----------------------------


		// ---------------------------- UTILS ----------------------------


		/**
		 * Utility function for AC API calls.
		 */
		protected function callConnectApi($params = array(), $requireSession = true) {

			if($requireSession) {
				$params['session'] = $this->getSessionAuthCookie();
			}

			$url = $this->apiurl . http_build_query($params);
			$xml = false;
			try {
				$xml = simplexml_load_file($url);
			} catch(Exception $e) {
				$this->_logger('Failed to get XML', __LINE__, __FUNCTION__);
				$this->_logger(json_encode($e), __LINE__, __FUNCTION__);
				Response::error(400, 'API request failed. Could be that the service is unavailable (503)');
			}

			if(!$xml) {
				Response::error(400, 'API request failed. Could be that the service is unavailable (503)');
			}
			$this->_logger('Got XML response', __LINE__, __FUNCTION__);
			$this->_logger(json_encode($xml), __LINE__, __FUNCTION__);

			return $xml;
		}

		/**
		 * Authenticate API user on AC service and grab returned cookie. If auth already in place, return cookie.
		 *
		 * @throws Exception
		 * @return array
		 */
		protected function getSessionAuthCookie() {
			if($this->sessioncookie !== NULL) {
				$this->_logger('Have cookie, reusing', __LINE__, __FUNCTION__);

				return $this->sessioncookie;
			}

			$url  = $this->apiurl . 'action=login&login=' . $this->config['connect-api-userid'] . '&password=' . $this->config['connect-api-passwd'];
			$auth = get_headers($url, 1);

			if(!isset($auth['Set-Cookie'])) {
				$this->_logger('********** getSessionAuthCookie failed!', __LINE__, __FUNCTION__);
				Response::error(401, 'Error when authenticating to the Adobe Connect API using client API credentials. Set-Cookie not present in response.');
			}

			// Extract session cookie
			$acSessionCookie = substr($auth['Set-Cookie'], strpos($auth['Set-Cookie'], '=') + 1);
			$acSessionCookie = substr($acSessionCookie, 0, strpos($acSessionCookie, ';'));

			$this->sessioncookie = $acSessionCookie;
			$this->_logger('Returning new cookie', __LINE__, __FUNCTION__);

			return $this->sessioncookie;
		}

		private function _responseToArray($response) {
			$newArr = Array();
			foreach($response as $child) {
				$newArr[] = $child;
			}

			return $newArr;
		}


		private function _logger($text, $line, $function) {
			if($this->DEBUG) {
				error_log($function . '(' . $line . '): ' . $text);
			}
		}

		// ---------------------------- ./UTILS ----------------------------

		// UNUSED

		/*
		public function getMeetingRoomParticipants($meetingroom) {
			$scoid = $this->findMeetingRoomID($meetingroom);
			if($scoid === NULL) {
				return NULL;
			}
			$result = $this->callConnectApi(array('action' => 'meeting-usermanager-user-list', 'sco-id' => $scoid));

			return $result->{"meeting-usermanager-user-list"};
		}
		*/

		/*
		// Probably not necessary, but keep it for now...
		private function _findSharedMeetingsFolder() {
			$sharedMeetingFolder = $this->callConnectApi(array('action' => 'sco-info', 'sco-id' => $this->config['connect-shared-folder-id']), true);
			//
			if($sharedMeetingFolder->status['code'] != 'ok') {
				return false;
			}

			// If folder was found return status with folder details directly from AC
			return true;
		}
		*/
	}



