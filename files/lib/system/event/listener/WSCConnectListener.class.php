<?php
namespace wcf\system\event\listener;
use wcf\action\WSCConnectAPIAction;
use wcf\data\package\Package;
use wcf\data\user\User;
use wcf\system\background\BackgroundQueueHandler;
use wcf\system\background\job\WSCConnectBackgroundJob;
use wcf\system\WCF;
use wcf\util\CryptoUtil;
use wcf\util\HTTPRequest;
use wcf\util\JSON;

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
	 * Immediately push mode option in the background thread
	 * @var	string
	 */
	const PUSH_MODE_IMMEDIATELY_BACKGROUND = 'immediatelyBackground';

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

		// for WSC >= 5.2 there will be two events triggered 'fireEvent' and 'createdNotification'. We only want to proceed with 'fireEvent' when it's not a WSC 5.2 or higher
		if ($eventName === 'fireEvent' && Package::compareVersion(WCF_VERSION, '5.2', '>=')) {
			return;
		}

		// Check if the users are logged in via the app. This could save as the HTTPRequest.
		$users = [];
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

			$userData = [];
			foreach ($users as $userID => $wscConnectPublicKey) {
				$decodedPublicKeys = json_decode($wscConnectPublicKey, true);

				// check for valid json
				if ($decodedPublicKeys === null) {
					$decodedPublicKeys = [
						[
							'deviceID' => '',
							'key' => $wscConnectPublicKey
						]
					];
				}

				$secret = bin2hex(CryptoUtil::randomBytes(8));
				foreach ($decodedPublicKeys as $publicKey) {
					if (is_array($publicKey)) {
						$key = $publicKey['key'];
						$deviceID = $publicKey['deviceID'];
					} else {
						$key = $publicKey;
						$deviceID = '';
					}

					$userData[] = [
						'userID' => $userID,
						'message' => ($key !== null) ? WSCConnectAPIAction::encryptString($parameters['event']->getMessage(), $key, $secret) : $parameters['event']->getMessage(),
						'link' => ($key !== null) ? WSCConnectAPIAction::encryptString($parameters['event']->getLink(), $key, $secret) : $parameters['event']->getLink(),
						'deviceID' => $deviceID
					];
				}
			}

			$notification = [
				'userData' => $userData,
				'authorID' => $parameters['event']->getAuthor()->userID,
				'time' => TIME_NOW,
				'eventHash' => $parameters['event']->getEventHash(),
				'eventName' => $parameters['eventName'],
				'eventID' => $eventID
			];

			switch (WSC_CONNECT_PUSH_MODE) {
				case self::PUSH_MODE_IMMEDIATELY_BACKGROUND:
					$this->sendNotificationBackground($notification);
				break;

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
		$data = [
			'appSecret' => WSC_CONNECT_APP_SECRET,
			'appID' => WSC_CONNECT_APP_ID,
			'notification' => $notification,
			'batch' => $batch
		];

		$request = new HTTPRequest(self::API_URL, ['method' => 'POST', 'timeout' => 5], $data);

		try {
			$request->execute();
		}
		catch (\Exception $e) {
			// Catch any exception and ignore it for now. Users can still look up their notifications in the app.
		}
	}

	/**
	 * Sends this notification in the background
	 * @param 	array 	$notification
	 */
	private function sendNotificationBackground($notification) {
		BackgroundQueueHandler::getInstance()->enqueueIn(new WSCConnectBackgroundJob($notification));
		BackgroundQueueHandler::getInstance()->forceCheck();
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
		$statement->execute([$encodedData]);
	}
}
