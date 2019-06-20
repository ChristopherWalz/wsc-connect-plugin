<?php
namespace wcf\form;
use wcf\data\user\UserAction;
use wcf\data\user\UserEditor;
use wcf\system\exception\NamedUserException;
use wcf\system\menu\user\UserMenu;
use wcf\system\WCF;
use wcf\util\CryptoUtil;
use wcf\util\exception\CryptoException;

/**
 * @author 	Christopher Walz
 * @license	https://cwalz.de/index.php/TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectSettingsForm extends AbstractForm {
	/**
	 * @inheritDoc
	 */
	public $loginRequired = true;

	/**
	 * @inheritDoc
	 */
	public $templateName = 'wscConnectSettings';

	/**
	 * @inheritDoc
	 */
	public $neededModules = ['MODULE_WSC_CONNECT'];

	/**
	 * The generated or to be generated token for the 3rd party login
	 * @var	string
	 */
	private $wscConnectThirdPartyToken;

	/**
	 * Boolean, which indicates if the logout was successful
	 * @var	boolean
	 */
	private $logoutSuccess = false;

	/**
	 * @var array Logged in devices
	 */
	private $wscConnectLoginDevices = [];

	/**
	 * @inheritDoc
	 */
	public function readData() {
		parent::readData();

		// check if this is a third party login and if we need to generate a token
		if (WCF::getUser()->authData && !WCF::getUser()->wscConnectThirdPartyToken) {
			try {
				$this->wscConnectThirdPartyToken = bin2hex(CryptoUtil::randomBytes(18));
			} catch (CryptoException $e) {
				// show nicer exception
				throw new NamedUserException(WCF::getLanguage()->get('wcf.wsc_connect.crypto_exception'));
			}

			$userAction = new UserAction([new UserEditor(WCF::getUser())], 'update', ['data' => [
				'wscConnectThirdPartyToken' => $this->wscConnectThirdPartyToken
			]]);
			$userAction->executeAction();
		} else {
			$this->wscConnectThirdPartyToken = WCF::getUser()->wscConnectThirdPartyToken;
		}

		if (WCF::getUser()->wscConnectLoginDevices) {
			$this->wscConnectLoginDevices = json_decode(WCF::getUser()->wscConnectLoginDevices, true);

			// show newest logins at the top
			usort($this->wscConnectLoginDevices, function($a, $b) {
				return $b['time'] - $a['time'];
			});
		}
	}

	/**
	 * @inheritDoc
	 */
	public function save() {
		parent::save();

		$userAction = new UserAction([new UserEditor(WCF::getUser())], 'update', ['data' => [
			'wscConnectToken' => null,
			'wscConnectLoginDevices' => []
		]]);
		$userAction->executeAction();

		$this->logoutSuccess = true;

		$this->saved();
	}

	/**
	 * @inheritDoc
	 */
	public function show() {
		// set active tab
		UserMenu::getInstance()->setActiveMenuItem('wcf.user.menu.settings.wscConnect');

		parent::show();
	}

	/**
	 * @inheritDoc
	 */
	public function assignVariables() {
		parent::assignVariables();

		WCF::getTPL()->assign([
			'wscConnectThirdPartyToken' => $this->wscConnectThirdPartyToken,
			'logoutSuccess' => $this->logoutSuccess,
			'wscConnectLoginDevices' => $this->wscConnectLoginDevices
		]);
	}
}
