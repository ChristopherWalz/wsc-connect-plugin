<?php

namespace wcf\system\event\listener;

use wcf\data\user\User;
use wcf\system\user\authentication\UserAuthenticationFactory;
use wcf\system\WCF;
use wcf\util\CryptoUtil;
use wcf\util\StringUtil;

/**
 * @author 	Florian Gail
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectLoginListener implements IParameterizedEventListener {
	public function execute($eventObj, $className, $eventName, array &$parameters) {
		if (WSC_CONNECT_SILENT_LOGIN && !empty($_REQUEST['wscConnectU'])) {
			// login with username and password -- no 3rdparty-logins possible
			if (!empty($_REQUEST['wscConnectP'])) {
				$username = StringUtil::trim($_REQUEST['wscConnectU']);
				$password = StringUtil::trim($_REQUEST['wscConnectP']);
				$uaf = UserAuthenticationFactory::getInstance()->getUserAuthentication()->loginManually($username, $password);
				if ($uaf->userID) {
					WCF::getSession()->changeUser($uaf);
				}
			}
			
			// login with username and accessToken
			if (!empty($_REQUEST['wscConnectAT'])) {
				$username = StringUtil::trim($_REQUEST['wscConnectU']);
				$token = StringUtil::trim($_REQUEST['wscConnectAT']);
				$user = User::getUserByUsername($username);
				
				if ($user->userID && CryptoUtil::secureCompare($user->accessToken, $token)) {
					WCF::getSession()->changeUser($user);
				}
			}
			
			// login with username and user's connect token
			if (!empty($_REQUEST['wscConnectT'])) {
				$username = StringUtil::trim($_REQUEST['wscConnectU']);
				$token = StringUtil::trim($_REQUEST['wscConnectT']);
				$user = User::getUserByUsername($username);
				
				if ($user->userID && CryptoUtil::secureCompare($user->wscConnectToken, $token)) {
					WCF::getSession()->changeUser($user);
				}
			}
		}
	}
}
