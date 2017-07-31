<?php
namespace wcf\action;
use wcf\system\exception\AJAXException;
use wcf\util\StringUtil;
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
/**
 * @author 	Christopher Walz
 * @license	https://cwalz.de/index.php/TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectAPIAction extends AbstractAjaxAction {
	/**
	 * Each request has to have a valid user agent.
	 * @var	string
	 */
	const USER_AGENT = 'WSC-Connect API';

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
	private $guestTypes = ['apiUrlValidation'];

	/**
	 * @inheritDoc
	 */
	public function readParameters() {
		parent::readParameters();

		// check user agent
		if ($_SERVER['HTTP_USER_AGENT'] != self::USER_AGENT) {
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
			if ($appSecret !== WSC_CONNECT_APP_SECRET || $appID !== WSC_CONNECT_APP_ID) {
				throw new AJAXException('Wrong credentials', AJAXException::INSUFFICIENT_PERMISSIONS);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		parent::execute();

		// execute method if it exists
		if (method_exists($this, $this->type)) {
			$this->{$this->type}();
		} else {
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

	/**
	 * Trys to login with the given username and password
	 */
	private function login() {
		$username = (isset($_REQUEST['username'])) ? StringUtil::trim($_REQUEST['username']) : null;
		$password = (isset($_REQUEST['password'])) ? StringUtil::trim($_REQUEST['password']) : null;
		$thirdPartyLogin = (isset($_REQUEST['thirdPartyLogin'])) ? filter_var($_REQUEST['thirdPartyLogin'], FILTER_VALIDATE_BOOLEAN) : false;

		if ($username === null || $password === null) {
			throw new AJAXException('Missing parameters', AJAXException::MISSING_PARAMETERS);
		}

		// TODO count login requests and limit on 5/minute for the same account

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
			$wscConnectToken = StringUtil::getUUID();
			
			$userAction = new UserAction([new UserEditor($user->getDecoratedObject())], 'update', ['data' => [
				'wscConnectToken' => $wscConnectToken
			]]);
			$userAction->executeAction();
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

		$userAction = new UserAction([$userID], 'update', ['data' => [
			'wscConnectToken' => null
		]]);
		$userAction->executeAction();

		$this->sendJsonResponse([
			'success' => true
		]);
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

		if ($user->wscConnectToken != $this->wscConnectToken) {
			throw new AJAXException('Wrong user credentials.', AJAXException::INSUFFICIENT_PERMISSIONS);
		}

		WCF::getSession()->changeUser($user, true);

		$notifications = UserNotificationHandler::getInstance()->getMixedNotifications();
		$data = [];

		foreach ($notifications['notifications'] as $notification) {
			$data[] = $this->getNotification($notification);
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
	private function getNotification($notification) {
		return [
			'message' => $notification['event']->getMessage(),
			'avatar' => $notification['event']->getAuthor()->getAvatar()->getUrl(32),
			'time' => $notification['event']->getNotification()->time,
			'confirmed' => $notification['event']->isConfirmed(),
			'link' => ($notification['event']->isConfirmed()) ? $notification['event']->getLink() : LinkHandler::getInstance()->getLink('NotificationConfirm', ['id' => $notification['notificationID']])
		];
	}
}