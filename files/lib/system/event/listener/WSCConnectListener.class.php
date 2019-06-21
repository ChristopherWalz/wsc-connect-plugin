<?php
namespace wcf\system\event\listener;
use wcf\action\WSCConnectAPIAction;
use wcf\util\HTTPRequest;
use wcf\util\JSON;
use wcf\util\PasswordUtil;
use wcf\data\user\User;
use wcf\system\WCF;

/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectListener implements IParameterizedEventListener {
	/**
	 * The API-URL to logout users out
	 * @var	string
	 */
	const API_LOGOUT_URL = 'https://api.wsc-connect.com/logout-user';

	/**
	 * The API-URL to push the notifications to.
	 * @var	string
	 */
	const API_URL = 'https://api.wsc-connect.com/notifications';

	/**
	 * Immediately push mode option
	 * @var	string
	 */
	const PUSH_MODE_IMMEDIATELY = 'immediately';

	/**
	 * Batch push mode option
	 * @var	string
	 */
	const PUSH_MODE_BATCH = 'batch';

	/**
	 * Disabled push mode option
	 * @var	string
	 */
	const PUSH_MODE_DISABLE = 'disable';

	/**
	 * @inheritDoc
	 */
	public function execute($eventObj, $className, $eventName, array &$parameters) {
		if (!MODULE_WSC_CONNECT || !WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET || WSC_CONNECT_PUSH_MODE == self::PUSH_MODE_DISABLE) {
			return;
		}

		$users = array();
		foreach ($parameters['recipientIDs'] as $userID) {
			$user = new User($userID);
			if ($user->wscConnectToken) {
				$users[$userID] = $user->wscConnectPublicKey;
			}
		}

		if (!empty($users)) {
			$eventID = 0;
			if ($parameters['notificationObject']) {
				$eventID = $parameters['notificationObject']->getObjectID();
			}

			$userData = array();

			foreach ($users as $userID => $wscConnectPublicKey) {
				$decodedPublicKeys = json_decode($wscConnectPublicKey, true);
				// check for valid json
				if ($decodedPublicKeys === null) {
					$decodedPublicKeys = array(
						array(
							'deviceID' => '',
							'key' => $wscConnectPublicKey
						)
					);
				}

				$secret = PasswordUtil::getRandomPassword(16);
				foreach ($decodedPublicKeys as $publicKey) {
					if (is_array($publicKey)) {
						$key = $publicKey['key'];
						$deviceID = $publicKey['deviceID'];
					} else {
						$key = $publicKey;
						$deviceID = '';
					}

					$userData[] = array(
						'userID' => $userID,
						'message' => ($key !== null) ? WSCConnectAPIAction::encryptString($parameters['event']->getMessage(), $key, $secret) : $parameters['event']->getMessage(),
						'link' => ($key !== null) ? WSCConnectAPIAction::encryptString($parameters['event']->getLink(), $key, $secret) : $parameters['event']->getLink(),
						'deviceID' => $deviceID
					);
				}
			}

			$notification = array(
				'userData' => $userData,
				'authorID' => $parameters['event']->getAuthor()->userID,
				'time' => TIME_NOW,
				'eventHash' => $parameters['event']->getEventHash(),
				'eventName' => $parameters['eventName'],
				'eventID' => $eventID
			);

			switch (WSC_CONNECT_PUSH_MODE) {
				case self::PUSH_MODE_IMMEDIATELY:
					self::sendNotification($notification);
				break;

				case self::PUSH_MODE_BATCH:
					$this->saveNotification($notification);
				break;
			}
		}
	}

	/**
	 * Sends this notification/notifications to the API
	 * @param 	array 	$notification
	 * @param 	boolean	$batch
	 */
	public static function sendNotification($notification, $batch = false) {
		$data = array(
			'appSecret' => WSC_CONNECT_APP_SECRET,
			'appID' => WSC_CONNECT_APP_ID,
			'notification' => $notification,
			'batch' => $batch
		);

		$request = new HTTPRequest(self::API_URL, array('method' => 'POST', 'timeout' => 5), $data);

		try {
			$request->execute();
		}
		catch (\Exception $e) {
			// Catch any exception and ignore it for now. Users can still look up their notifications in the app.
		}
	}

	/**
	 * Saves this notification in the database and to send it later
	 * @param 	array 	$notification
	 */
	private function saveNotification($notification) {
		$encodedData = JSON::encode($notification);

		$sql = "INSERT INTO	wcf".WCF_N."_wsc_connect_notifications
						(data)
			VALUES			(?)";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($encodedData));
	}
}
