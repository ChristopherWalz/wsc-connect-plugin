<?php
namespace wcf\form;
use wcf\util\StringUtil;
use wcf\system\menu\user\UserMenu;
use wcf\data\user\UserProfile;
use wcf\data\user\UserAction;
use wcf\data\user\UserEditor;
use wcf\system\WCF;

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
	 * @inheritDoc
	 */
	public function readData() {
		parent::readData();

		// check if this is athird party login and if we need to generate a token
		if (WCF::getUser()->authData && !WCF::getUser()->wscConnectThirdPartyToken) {
			$this->wscConnectThirdPartyToken = StringUtil::getUUID();
			
			$userAction = new UserAction([new UserEditor(WCF::getUser())], 'update', ['data' => [
				'wscConnectThirdPartyToken' => $this->wscConnectThirdPartyToken
			]]);
			$userAction->executeAction();
		} else {
			$this->wscConnectThirdPartyToken = WCF::getUser()->wscConnectThirdPartyToken;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function save() {
		parent::save();
		
		$userAction = new UserAction([new UserEditor(WCF::getUser())], 'update', ['data' => [
			'wscConnectToken' => null,
			'wscConnectLoginDevice' => null,
			'wscConnectLoginTime' => 0
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
			'logoutSuccess' => $this->logoutSuccess
		]);
	}
}
