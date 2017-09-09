<?php
namespace wcf\action;

require_once WCF_DIR . 'lib/system/api/wsc-connect/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\ExpiredException;

use wcf\system\exception\AJAXException;
use wcf\util\StringUtil;
use wcf\util\CryptoUtil;
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
	public $guestTypes = ['apiUrlValidation', 'loginCookie'];

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

		// check for JWT token in authorization header
		if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
			list($type, $token) = explode(" ", $_SERVER["HTTP_AUTHORIZATION"], 2);
			if (strcasecmp($type, "Bearer") == 0) {
				$key = file_get_contents(WCF_DIR . 'wsc_connect/key.pub');

				try {
					$this->decodedJWTToken = JWT::decode($token, $key, array('RS256'));

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

	private function getConversations() {
		// conversation package not installed, return empty array
		if (PackageCache::getInstance()->getPackageID('com.woltlab.wcf.conversation') === null) {
			$this->sendJsonResponse([]);
			return;
		}

		$conversations = [];
		$userID = (isset($this->decodedJWTToken->userID)) ? intval($this->decodedJWTToken->userID) : 0;

		$sqlSelect = '  , (SELECT participantID FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipantID
				, (SELECT username FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipant';

		$objectList = new \wcf\data\conversation\UserConversationList($userID);
		$objectList->sqlSelects .= $sqlSelect;
		$objectList->sqlOrderBy = 'lastPostTime DESC';
		$objectList->sqlLimit = 10;
		$objectList->readObjects();

		foreach ($objectList as $conversation) {
			$conversations[] = $this->conversationToArray($conversation, $userID);
		}

		$this->sendJsonResponse($conversations);
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
		$thirdPartyLogin = (isset($_REQUEST['thirdPartyLogin'])) ? filter_var($_REQUEST['thirdPartyLogin'], FILTER_VALIDATE_BOOLEAN) : false;

		if ($username === null || $password === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		// prevent brute forcing
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("user = ?", array($username));
		$conditions->add("attempts >= ?", array(self::MAX_LOGIN_ATTEMPTS));

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

			$conditions->add("username = ?", array($username));
			$conditions->add("wscConnectThirdPartyToken = ?", array($password));

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
				'wscConnectLoginTime' => TIME_NOW
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

		$this->sendJsonResponse([
			'success' => $loginSuccess,
			'userID' => ($user !== null) ? $user->userID : 0,
			'username' => ($user !== null) ? $user->username : '',
			'avatar' => ($user !== null) ? $user->getAvatar()->getUrl(32) : '',
			'wscConnectToken' => $wscConnectToken
		]);
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
		$userID = (isset($_REQUEST['userID'])) ? intval($_REQUEST['userID']) : 0;

		if ($userID === 0 || $this->wscConnectToken === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		$user = new User($userID);

		if (!CryptoUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
			throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		WCF::getSession()->changeUser($user, true);

		$notifications = UserNotificationHandler::getInstance()->getMixedNotifications();
		$data = [];

		foreach ($notifications['notifications'] as $notification) {
			$data[] = $this->notificationToArray($notification);
		}

		$this->sendJsonResponse([
			'notifications' => $data
		]);
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
			'link' => ($notification['event']->isConfirmed()) ? $notification['event']->getLink() : LinkHandler::getInstance()->getLink('NotificationConfirm', ['id' => $notification['notificationID']])
		];
	}

	/**
	 * Returns a valid conversation array
	 *
	 * @return	array
	 */
	private function conversationToArray($conversation, $currentUserID) {
		if (!($conversation instanceof \wcf\data\conversation\ViewableConversation)) {
			$conversation = new \wcf\data\conversation\ViewableConversation($conversation);
		}

		$array = [];
		$array['conversationID'] = $conversation->conversationID;
		$array['title'] = $conversation->getTitle();
		$array['unread'] = $conversation->isNew();
		$array['link'] = $conversation->getLink();
		$array['time'] = $conversation->lastPostTime;
		$array['participants'] = '';

		$i = 0;
		$count = count($conversation->getParticipantSummary());
		foreach ($conversation->getParticipantSummary() as $participant) {
			$i++;
			$array['participants'] .= $participant->username;

			if ($i < $count) {
				$array['participants'] .= ', ';
			}
		}

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
	 * Wrap method in a public method, so it's accessible through event listeners
	 */
	public function sendJsonResponse(array $data) {
		parent::sendJsonResponse($data);
	}
}
