<?php
namespace wcf\action;
use wcf\system\exception\AJAXException;
use wcf\util\StringUtil;
use wcf\util\PasswordUtil;
use wcf\data\user\User;
use wcf\data\user\UserProfile;
use wcf\data\user\UserAction;
use wcf\data\user\UserEditor;
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
	public $userAgents = array('WSC-Connect API', 'WSC-Connect Mobile Browser 1.0');

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
	 * Method types in this array do not need to deliver a valid appID/appSecret. Used for validation of this installation.
	 * @var	array
	 */
	public $guestTypes = array('apiUrlValidation', 'loginCookie');

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

		if ($this->type === '' || (($appSecret === null || $appID === null) && !$guestCall)) {
			throw new AJAXException('Missing parameters', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		// skip check if this is a guest call
		if (!$guestCall) {
			if (!WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET) {
				throw new AJAXException('API disabled');
			}

			// check app id and app secret
			if (!PasswordUtil::secureCompare($appSecret, WSC_CONNECT_APP_SECRET) || !PasswordUtil::secureCompare($appID, WSC_CONNECT_APP_ID)) {
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
			$parameters['your.package.name'] = array(
				'object' => $yourListenerObject,
				'types' => array('myAwesomeMethod1', 'myAwesomeMethod2')
			);
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
		$this->sendJsonResponse(array(
			'success' => true,
			'apiUrl' => LinkHandler::getInstance()->getLink('WSCConnectAPI', array('appendSession' => false))
		));
	}

	/**
	 * Validates the correct plugin installation and option setup
	 */
	private function apiDataValidation() {
		$this->sendJsonResponse(array(
			'success' => true,
			'appID' => WSC_CONNECT_APP_ID
		));
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
			if (!PasswordUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
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
			$wscConnectToken = PasswordUtil::getRandomPassword(36);
			
			$userAction = new UserAction(array(new UserEditor($user->getDecoratedObject())), 'update', array('data' => array(
				'wscConnectToken' => $wscConnectToken,
				'wscConnectLoginDevice' => $device,
				'wscConnectLoginTime' => TIME_NOW
			)));
			$userAction->executeAction();
		} else {
			// log failed login attempt
			$sql = "INSERT INTO			wcf".WCF_N."_wsc_connect_login_attempts
								(user, attempts, time)
				VALUES				(?, ?, ?)
				ON DUPLICATE KEY UPDATE		attempts=attempts+1,
											time = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array(
				$username,
				1,
				TIME_NOW,
				TIME_NOW
			));
		}

		$this->sendJsonResponse(array(
			'success' => $loginSuccess,
			'userID' => ($user !== null) ? $user->userID : 0,
			'username' => ($user !== null) ? $user->username : '',
			'avatar' => ($user !== null) ? $user->getAvatar()->getUrl(32) : '',
			'wscConnectToken' => $wscConnectToken
		));
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

		if (!PasswordUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
			throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		$userAction = new UserAction(array(new UserEditor($user)), 'update', array('data' => array(
			'wscConnectToken' => null,
			'wscConnectLoginDevice' => null,
			'wscConnectLoginTime' => 0
		)));
		$userAction->executeAction();

		$this->sendJsonResponse(array(
			'success' => true
		));
	}

	/**
	 * Fetches the latest mixed notifications of the given user
	 */
	private function notifications() {
		$userID = (isset($_REQUEST['userID'])) ? intval($_REQUEST['userID']) : 0;

		if ($userID === 0 || $this->wscConnectToken === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		$user = new User($userID);

		if (!PasswordUtil::secureCompare($user->wscConnectToken, $this->wscConnectToken)) {
			throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		WCF::getSession()->changeUser($user, true);

		$notifications = UserNotificationHandler::getInstance()->getMixedNotifications();
		$data = array();

		foreach ($notifications['notifications'] as $notification) {
			$data[] = $this->getNotification($notification);
		}

		$this->sendJsonResponse(array(
			'notifications' => $data
		));
	}

	/**
	 * Returns a valid notification array
	 * 
	 * @return	array
	 */
	private function getNotification($notification) {
		return array(
			'message' => $notification['event']->getMessage(),
			'avatar' => $notification['event']->getAuthor()->getAvatar()->getUrl(32),
			'time' => $notification['event']->getNotification()->time,
			'confirmed' => $notification['event']->isConfirmed(),
			'link' => ($notification['event']->isConfirmed()) ? $notification['event']->getLink() : LinkHandler::getInstance()->getLink('NotificationConfirm', array('id' => $notification['notificationID']))
		);
	}

	/**
	 * Wrap method in a public method, so it's accessible through event listeners
	 */
	public function sendJsonResponse(array $data) {
		parent::sendJsonResponse($data);
	}
}
