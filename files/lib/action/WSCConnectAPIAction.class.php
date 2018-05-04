<?php
namespace wcf\action;

require_once WCF_DIR . 'lib/system/api/wsc-connect/autoload.php';
use function base64_encode;
use \Firebase\JWT\JWT;
use \Firebase\JWT\ExpiredException;

use wcf\system\message\censorship\Censorship;
use wcf\system\exception\AJAXException;
use wcf\util\StringUtil;
use wcf\util\CryptoUtil;
use wcf\util\MessageUtil;
use wcf\util\exception\CryptoException;
use wcf\data\user\User;
use wcf\data\user\UserProfile;
use wcf\data\user\UserAction;
use wcf\data\user\UserEditor;
use wcf\data\package\PackageCache;
use wcf\system\user\authentication\UserAuthenticationFactory;
use wcf\system\user\authentication\EmailUserAuthentication;
use wcf\system\exception\UserInputException;
use wcf\system\WCF;
use wcf\system\user\notification\UserNotificationHandler;
use wcf\system\request\LinkHandler;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\event\EventHandler;

/**
 * @author 	Christopher Walz
 * @license	https://cwalz.de/index.php/TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectAPIAction extends AbstractAjaxAction {
	/**
	 * Each request has to have a valid user agent.
	 * @var	array
	 */
	public $userAgents = ['WSC-Connect API', 'WSC-Connect Mobile Browser 1.0'];

	/**
	 * The maximum failed login attempts
	 * @var	int
	 */
	const MAX_LOGIN_ATTEMPTS = 5;

	/**
	 * A valid request type
	 * @var	string
	 */
	private $type;

	/**
	 * The users connect token, necessary for some actions.
	 * @var	string
	 */
	private $wscConnectToken;

	/**
	 * The users decoded jwt token. If this is necessary for the type of action, provide it within the Bearer authorization header. An exception is thrown in case it is given in the request and not valid.
	 * @var	stdClass
	 */
	private $decodedJWTToken;

	/**
	 * Method types in this array do not need to deliver a valid appID/appSecret. Used for validation of this installation.
	 * @var	array
	 */
	public $guestTypes = ['apiUrlValidation', 'loginCookie', 'getAvailableTabs'];

	/**
	 * @inheritDoc
	 */
	public function readParameters() {
		parent::readParameters();

		if (!MODULE_WSC_CONNECT) {
			throw new AJAXException('API disabled');
		}

		// check user agent
		if (!in_array($_SERVER['HTTP_USER_AGENT'], $this->userAgents)) {
			throw new AJAXException('Access not allowed', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		$this->type = (isset($_REQUEST['type'])) ? StringUtil::trim($_REQUEST['type']) : '';
		$this->wscConnectToken = (isset($_REQUEST['wscConnectToken'])) ? StringUtil::trim($_REQUEST['wscConnectToken']) : null;
		$appSecret = (isset($_REQUEST['appSecret'])) ? StringUtil::trim($_REQUEST['appSecret']) : null;
		$appID = (isset($_REQUEST['appID'])) ? StringUtil::trim($_REQUEST['appID']) : null;
		$guestCall = in_array($this->type, $this->guestTypes);

		// fix for apache, which occasionally removes the HTTP_AUTH* headers
		$authHeader = "";
		if (!empty($_SERVER["HTTP_AUTHORIZATION"])) {
			$authHeader = $_SERVER["HTTP_AUTHORIZATION"];
		} else if (!empty($_SERVER["HTTP_X_AUTHORIZATION"])) {
			$authHeader = $_SERVER["HTTP_X_AUTHORIZATION"];
		}

		// check for JWT token in authorization header
		if (!empty($authHeader)) {
			list($type, $token) = explode(" ", $authHeader, 2);
			if (strcasecmp($type, "Bearer") == 0) {
				$key = file_get_contents(WCF_DIR . 'wsc_connect/key.pub');

				try {
					$this->decodedJWTToken = JWT::decode($token, $key, ['RS256']);

					// only allow access tokens
					if ($this->decodedJWTToken->tokenType !== 'access') {
						throw new \Exception();
					}

					$guestCall = true;
				// we have to distinguish between those two, so the client knows when to refresh the token
				} catch (ExpiredException $e) {
					throw new AJAXException('JWT expired', AJAXException::SESSION_EXPIRED);
				} catch (\Exception $e) {
					throw new AJAXException('JWT error');
				}
			}
		}

		if ($this->type === '' || (($appSecret === null || $appID === null) && !$guestCall)) {
			throw new AJAXException('Missing parameters', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		// skip check if this is a guest call
		if (!$guestCall) {
			if (!WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET) {
				throw new AJAXException('API disabled');
			}

			// check app id and app secret
			if (!CryptoUtil::secureCompare($appSecret, WSC_CONNECT_APP_SECRET) || !CryptoUtil::secureCompare($appID, WSC_CONNECT_APP_ID)) {
				throw new AJAXException('Wrong credentials', AJAXException::INSUFFICIENT_PERMISSIONS);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		parent::execute();

		/* Extend this parameter to add your own methods to the API. The format should look like this:
			$parameters['your.package.name'] = [
				'object' => $yourListenerObject,
				'types' => ['myAwesomeMethod1', 'myAwesomeMethod2']
			];
		*/
		$parameters = [];

		EventHandler::getInstance()->fireAction($this, 'beforeTypeCheck', $parameters);

		$executed = false;

		// execute method if it exists
		if (method_exists($this, $this->type)) {
			$this->{$this->type}();
			$executed = true;
			$this->executed();
		} else if (!empty($parameters)) {
			foreach ($parameters as $identifier) {
				$key = array_search($this->type, $identifier['types']);

				if ($key !== false) {
					call_user_func([$identifier['object'], $identifier['types'][$key]]);
					$executed = true;
					$this->executed();
					break;
				}
			}
		}

		if (!$executed) {
			throw new AJAXException('Bad type', AJAXException::MISSING_PARAMETERS);
		}
	}

	/**
	 * Returns the available tabs to use
	 */
	private function getAvailableTabs() {
		$tabs = ['webview', 'notifications', 'messages'];

		if (PackageCache::getInstance()->getPackageID('com.woltlab.wcf.conversation') !== null) {
			$tabs[] = 'conversations';
		}

		EventHandler::getInstance()->fireAction($this, 'availableTabs', $tabs);

		$this->sendJsonResponse($tabs);
	}

	/**
	 * Validates the correct plugin installation
	 */
	private function apiUrlValidation() {
		$this->sendJsonResponse([
			'success' => true,
			'apiUrl' => LinkHandler::getInstance()->getLink('WSCConnectAPI')
		]);
	}

	/**
	 * Validates the correct plugin installation and option setup
	 */
	private function apiDataValidation() {
		$this->sendJsonResponse([
			'success' => true,
			'appID' => WSC_CONNECT_APP_ID
		]);
	}

	/**
	 * Adds a message to an existing conversation
	 */
	private function addConversationMessage() {
		$this->validateConversationPackage();

		$conversationID = (isset($_REQUEST['id'])) ? intval($_REQUEST['id']) : 0;
		$message = (isset($_REQUEST['message'])) ? StringUtil::trim($_REQUEST['message']) : null;
		$userID = (isset($this->decodedJWTToken->userID)) ? intval($this->decodedJWTToken->userID) : 0;

		if ($message === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		$conversation = \wcf\data\conversation\Conversation::getUserConversation($conversationID, $userID);

		if ($conversation === null) {
			throw new AJAXException('Conversation not found', AJAXException::ILLEGAL_LINK);
		}

		$user = new User($userID);
		WCF::getSession()->changeUser($user, true);

		if (!$conversation->canRead() || $conversation->isClosed) {
			throw new AJAXException('Access not allowed', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		if (ENABLE_CENSORSHIP) {
			$result = Censorship::getInstance()->test($message);
			if ($result) {
				throw new AJAXException('censorship', AJAXException::BAD_PARAMETERS, null, array_keys($result));
			}
		}

		$maxTextLength = WCF::getSession()->getPermission('user.conversation.maxLength');
		if (mb_strlen($message) > $maxTextLength) {
			throw new AJAXException('length', AJAXException::BAD_PARAMETERS, null, intval($maxTextLength));
		}

		// save conversation message
		$data = [
			'time' => TIME_NOW,
			'userID' => $user->userID,
			'username' => $user->username,
			'message' => $message,
			'conversationID' => $conversation->conversationID
		];

		$messageData = [
			'data' => $data,
			'attachmentHandler' => null,
			'conversation' => $conversation
		];

		$objectAction = new \wcf\data\conversation\message\ConversationMessageAction([], 'create', $messageData);
		$resultValues = $objectAction->executeAction();
		$message = $resultValues['returnValues'];

		// mark conversation as read
		$objectAction = new \wcf\data\conversation\ConversationAction([$conversation], 'markAsRead');
		$objectAction->executeAction();

		$this->sendJsonResponse($this->conversationMessageToArray($message));
	}

	/**
	 * Returns an array of messages for the given conversation
	 */
	private function getConversationMessages() {
		$this->validateConversationPackage();

		$offset = (isset($_REQUEST['offset'])) ? intval($_REQUEST['offset']) : 0;
		$limit = (isset($_REQUEST['limit'])) ? intval($_REQUEST['limit']) : 20;
		$conversationID = (isset($_REQUEST['id'])) ? intval($_REQUEST['id']) : 0;
		$userID = (isset($this->decodedJWTToken->userID)) ? intval($this->decodedJWTToken->userID) : 0;
		$conversation = \wcf\data\conversation\Conversation::getUserConversation($conversationID, $userID);

		if ($conversation === null) {
			throw new AJAXException('Conversation not found', AJAXException::ILLEGAL_LINK);
		}

		$user = new User($userID);
		WCF::getSession()->changeUser($user, true);

		if (!$conversation->canRead()) {
			throw new AJAXException('Access not allowed', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		// get messages
		$objectList = new \wcf\data\conversation\message\ViewableConversationMessageList();
		$objectList->getConditionBuilder()->add('conversation_message.conversationID = ?', [$conversation->conversationID]);
		$objectList->setConversation($conversation);
		$objectList->sqlOffset = $offset;
		$objectList->sqlLimit = $limit;
		$objectList->readObjects();

		$messages = [];
		foreach ($objectList as $message) {
			$messages[] = $this->conversationMessageToArray($message);
		}

		// mark conversation as read
		$objectAction = new \wcf\data\conversation\ConversationAction([$conversation], 'markAsRead');
		$objectAction->executeAction();

		$this->sendJsonResponse($messages);
	}

	/**
	 * Returns a single message
	 */
	private function getConversationMessage() {
		$this->validateConversationPackage();

		$messageID = (isset($_REQUEST['id'])) ? intval($_REQUEST['id']) : 0;
		$userID = (isset($this->decodedJWTToken->userID)) ? intval($this->decodedJWTToken->userID) : 0;
		$message = \wcf\data\conversation\message\ViewableConversationMessage::getViewableConversationMessage($messageID);

		if ($message === null) {
			throw new AJAXException('Message not found', AJAXException::ILLEGAL_LINK);
		}

		$user = new User($userID);
		WCF::getSession()->changeUser($user, true);

		$conversation = $message->getConversation();
		if (!$conversation->canRead()) {
			throw new AJAXException('Access not allowed', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		$this->sendJsonResponse($this->conversationMessageToArray($message, true));
	}

	/**
	 * Returns an array of conversations
	 */
	private function getConversations() {
		$this->validateConversationPackage();

		$conversations = [];
		$userID = (isset($this->decodedJWTToken->userID)) ? intval($this->decodedJWTToken->userID) : 0;
		$offset = (isset($_REQUEST['offset'])) ? intval($_REQUEST['offset']) : 0;
		$limit = (isset($_REQUEST['limit'])) ? intval($_REQUEST['limit']) : 20;

		$sqlSelect = '  , (SELECT participantID FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipantID
				, (SELECT username FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipant';

		$objectList = new \wcf\data\conversation\UserConversationList($userID);
		$objectList->sqlSelects .= $sqlSelect;
		$objectList->sqlOrderBy = 'lastPostTime DESC';
		$objectList->sqlOffset = $offset;
		$objectList->sqlLimit = $limit;
		$objectList->readObjects();

		foreach ($objectList as $conversation) {
			$conversations[] = $this->conversationToArray($conversation, $userID);
		}

		$this->sendJsonResponse($conversations);
	}

	private function validateConversationPackage() {
		// conversation package not installed, throw exception
		if (PackageCache::getInstance()->getPackageID('com.woltlab.wcf.conversation') === null) {
			throw new AJAXException('package', AJAXException::ILLEGAL_LINK);
		}
	}

	private function loginCookie() {
		$username = (isset($_REQUEST['username'])) ? mb_strtolower(StringUtil::trim($_REQUEST['username'])) : null;
		$password = (isset($_REQUEST['password'])) ? StringUtil::trim($_REQUEST['password']) : null;

		// do the login again
		$loginSuccess = true;
		try {
			$user = UserAuthenticationFactory::getInstance()->getUserAuthentication()->loginManually($username, $password);
		}
		catch (UserInputException $e) {
			if ($e->getField() == 'username') {
				try {
					$user = EmailUserAuthentication::getInstance()->loginManually($username, $password);
				}
				catch (UserInputException $e2) {
					$loginSuccess = false;
				}
			}
			else {
				$loginSuccess = false;
			}
		}

		if ($loginSuccess) {
			// this is important, otherwise people could try to login all the time
			if (!CryptoUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
				throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
			}

			// success, set cookies and change user
			UserAuthenticationFactory::getInstance()->getUserAuthentication()->storeAccessData($user, $username, $password);
			WCF::getSession()->changeUser($user);

			$this->sendJsonResponse([
				'success' => true
			]);
		} else {
			$this->sendJsonResponse([
				'success' => false
			]);
		}
	}

	/**
	 * Trys to login with the given username and password
	 */
	private function login() {
		$username = (isset($_REQUEST['username'])) ? mb_strtolower(StringUtil::trim($_REQUEST['username'])) : null;
		$password = (isset($_REQUEST['password'])) ? StringUtil::trim($_REQUEST['password']) : null;
		$device = (isset($_REQUEST['device'])) ? StringUtil::trim($_REQUEST['device']) : '';
		$publicKey = (!empty($_REQUEST['publicKey'])) ? StringUtil::trim($_REQUEST['publicKey']) : null;
		$thirdPartyLogin = (isset($_REQUEST['thirdPartyLogin'])) ? filter_var($_REQUEST['thirdPartyLogin'], FILTER_VALIDATE_BOOLEAN) : false;

		if ($username === null || $password === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		// prevent brute forcing
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("user = ?", [$username]);
		$conditions->add("attempts >= ?", [self::MAX_LOGIN_ATTEMPTS]);

		$sql = "SELECT user
				FROM wcf".WCF_N."_wsc_connect_login_attempts
				".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute($conditions->getParameters());

		// max attempts reached
		if ($statement->fetchColumn()) {
			throw new AJAXException('Max login attempts reached. Try again later.', AJAXException::MISSING_PARAMETERS);
		}

		$loginSuccess = true;
		$user = null;

		if ($thirdPartyLogin) {
			$conditions = new PreparedStatementConditionBuilder();

			$conditions->add("username = ?", [$username]);
			$conditions->add("wscConnectThirdPartyToken = ?", [$password]);

			$sql = "SELECT *
					FROM wcf".WCF_N."_user
					".$conditions;
			$statement = WCF::getDB()->prepareStatement($sql, 1);
			$statement->execute($conditions->getParameters());
			$user = $statement->fetchObject(User::class);

			if ($user === null) {
				$loginSuccess = false;
			}
		} else {
			try {
				$user = UserAuthenticationFactory::getInstance()->getUserAuthentication()->loginManually($username, $password);
			}
			catch (UserInputException $e) {
				if ($e->getField() == 'username') {
					try {
						$user = EmailUserAuthentication::getInstance()->loginManually($username, $password);
					}
					catch (UserInputException $e2) {
						$loginSuccess = false;
					}
				}
				else {
					$loginSuccess = false;
				}
			}
		}

		$wscConnectToken = '';

		if ($loginSuccess) {
			$user = new UserProfile($user);
			try {
				$wscConnectToken = bin2hex(CryptoUtil::randomBytes(18));
			} catch (CryptoException $e) {
				// can not proceed from here
				throw new AJAXException('Can not generate a secure hash.');
			}

			$userAction = new UserAction([new UserEditor($user->getDecoratedObject())], 'update', ['data' => [
				'wscConnectToken' => $wscConnectToken,
				'wscConnectLoginDevice' => $device,
				'wscConnectLoginTime' => TIME_NOW,
				'wscConnectPublicKey' => $publicKey
			]]);
			$userAction->executeAction();
		} else {
			// log failed login attempt
			$sql = "INSERT INTO			wcf".WCF_N."_wsc_connect_login_attempts
								(user, attempts, time)
				VALUES				(?, ?, ?)
				ON DUPLICATE KEY UPDATE		attempts=attempts+1,
											time = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([
				$username,
				1,
				TIME_NOW,
				TIME_NOW
			]);
		}

		$package = PackageCache::getInstance()->getPackageByIdentifier('de.cwalz.wscConnect');
		$this->sendJsonResponse([
			'success' => $loginSuccess,
			'userID' => ($user !== null) ? $user->userID : 0,
			'username' => ($user !== null) ? $user->username : '',
			'avatar' => ($user !== null) ? $user->getAvatar()->getUrl(32) : '',
			'wscConnectToken' => $wscConnectToken,
			'pluginVersion' => $package->packageVersion
		]);
	}
	
	/**
	 * Returns the encrypted string with the given public key. We cannot use openssl_public_encrypt directly on the string, because of the length                   limitation of the method.
	 *
	 * @param $string string the string to encrypt
	 * @param $publicKey string the public key to encrypt the secret
	 * @param $secret string a 8 byte random secret to encrypt the message
	 * @return array
	 * @throws CryptoException
	 */
	public static function encryptString($string, $publicKey, $secret) {
		// encrypt the actual message with openssl_encrypt using the given secret
		$iv = bin2hex(CryptoUtil::randomBytes(8));
		$encryptedString = openssl_encrypt($string, 'AES-128-CBC', $secret, OPENSSL_RAW_DATA, $iv);

		// encrypt the given secret and iv with the public key using openssl_public_encrypt
		openssl_public_encrypt($secret, $encryptedSecret, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
		openssl_public_encrypt($iv, $encryptedIv, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
		
		// return all three base64 encoded parameters, encrypted message, encrypted secret and the encrypted iv
		return [
			'string' => base64_encode($encryptedString),
			'secret' => base64_encode($encryptedSecret),
			'iv' => base64_encode($encryptedIv)
		];
	}

	/**
	 * Removes the connect token for the given user
	 */
	private function logout() {
		$userID = (isset($_REQUEST['userID'])) ? intval($_REQUEST['userID']) : 0;

		if ($userID === 0 || $this->wscConnectToken === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		$user = new User($userID);

		if (!CryptoUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
			throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		$userAction = new UserAction([new UserEditor($user)], 'update', ['data' => [
			'wscConnectToken' => null,
			'wscConnectLoginDevice' => null,
			'wscConnectLoginTime' => 0
		]]);
		$userAction->executeAction();

		$this->sendJsonResponse([
			'success' => true
		]);
	}

	/**
	 * Wrapper method, to keep the API working. TODO Remove later.
	 */
	private function notifications() {
		$this->getNotifications();
	}

	/**
	 * Fetches the latest mixed notifications of the given user
	 */
	private function getNotifications() {
		// support new direct get method from the app
		if (isset($this->decodedJWTToken->userID)) {
			$userID = intval($this->decodedJWTToken->userID);
			$user = new User($userID);
		} else if (isset($_REQUEST['userID'])) {
			$userID = intval($_REQUEST['userID']);

			if ($userID == 0 || $this->wscConnectToken === null) {
				throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
			}

			$user = new User($userID);

			if (!CryptoUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
				throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
			}
		}

		WCF::getSession()->changeUser($user, true);

		$notifications = UserNotificationHandler::getInstance()->getMixedNotifications();
		$data = [];

		foreach ($notifications['notifications'] as $notification) {
			$data[] = $this->notificationToArray($notification);
		}

		// support new direct get method from the app
		$response = $data;
		if (!isset($this->decodedJWTToken->userID)) {
			$response  = [
				'notifications' => $response
			];
		}

		$this->sendJsonResponse($response);
	}

	/**
	 * Returns a valid notification array
	 *
	 * @return	array
	 */
	private function notificationToArray($notification) {
		return [
			'message' => $notification['event']->getMessage(),
			'avatar' => $notification['event']->getAuthor()->getAvatar()->getUrl(32),
			'time' => $notification['event']->getNotification()->time,
			'confirmed' => $notification['event']->isConfirmed(),
			'link' => ($notification['event']->isConfirmed()) ? MessageUtil::stripCrap($notification['event']->getLink()) : MessageUtil::stripCrap(LinkHandler::getInstance()->getLink('NotificationConfirm', ['id' => $notification['notificationID']]))
		];
	}

	/**
	 * Returns a valid conversation array
	 *
	 * @return	array
	 */
	private function conversationToArray($conversation, $currentUserID) {
		if (!($conversation instanceof \wcf\data\conversation\ViewableConversation)) {
			$conversation = \wcf\data\conversation\ViewableConversation::getViewableConversation($conversation);
		}

		$array = [];
		$array['conversationID'] = $conversation->conversationID;
		$array['title'] = $conversation->getTitle();
		$array['unread'] = $conversation->isNew();
		$array['link'] = MessageUtil::stripCrap($conversation->getLink());
		$array['time'] = $conversation->lastPostTime;
		$array['participants'] = implode(", ", $conversation->getDecoratedObject()->getParticipantNames());
		$array['isNew'] = $conversation->getDecoratedObject()->isNew();
		$array['isClosed'] = boolval($conversation->isClosed);

		if ($conversation->userID === $currentUserID) {
			if ($conversation->participants > 1) {
				$avatar = null;
			} else {
				$avatar = $conversation->getOtherParticipantProfile()->getAvatar()->getUrl(24);
			}
		} else {
			$avatar = $conversation->getUserProfile()->getAvatar()->getUrl(24);
		}

		$array['avatar'] = $avatar;

		return $array;
	}

	/**
	 * Returns a valid conversation message array
	 *
	 * @return	array
	 */
	private function conversationMessageToArray($message, $htmlMessage = false) {
		if (!($message instanceof \wcf\data\conversation\message\ViewableConversationMessage)) {
			$message = \wcf\data\conversation\message\ViewableConversationMessage::getViewableConversationMessage($message->messageID);
		}

		$array = [];
		$array['messageID'] = $message->messageID;
		$array['message'] = ($htmlMessage) ? $message->getFormattedMessage() : $message->getSimplifiedFormattedMessage();
		$array['time'] = $message->time;
		$array['username'] = $message->getUserProfile()->username;
		$array['avatar'] = $message->getUserProfile()->getAvatar()->getUrl(24);

		return $array;
	}

	/**
	 * Wrap method in a public method, so it's accessible through event listeners
	 */
	public function sendJsonResponse(array $data) {
		parent::sendJsonResponse($data);
	}
}
