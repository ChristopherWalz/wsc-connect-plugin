<?php
namespace wcf\system\event\listener;
use wcf\util\HTTPRequest;
use wcf\util\JSON;
use wcf\data\user\User;
use wcf\system\WCF;

/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectListener implements IParameterizedEventListener {
	/**
	 * The API-URL to push the notifications to.
	 * @var	string
	 */
	const API_URL = 'http://localhost:1337/notifications';

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

		$userIDs = [];

		// Check if the users are logged in via the app. This could save as the HTTPRequest.
		foreach ($parameters['recipientIDs'] as $userID) {
			$user = new User($userID);

			if ($user->wscConnectToken) {
				$userIDs[] = $userID;
			}
		}


		if (!empty($userIDs)) {
			$notification = [
				'userIDs' => $userIDs,
				'message' => $parameters['event']->getMessage(),
				'avatar' => $parameters['event']->getAuthor()->getAvatar()->getUrl(32),
				'time' => TIME_NOW,
				'confirmed' => $parameters['event']->isConfirmed(),
				'link' => $parameters['event']->getLink()
			];

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
