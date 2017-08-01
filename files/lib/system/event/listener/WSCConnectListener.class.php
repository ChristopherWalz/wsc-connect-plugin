<?php
namespace wcf\system\event\listener;
use wcf\util\HTTPRequest;
use wcf\util\JSON;
use wcf\data\user\User;

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
	const API_URL = 'https://api.wsc-connect.com/notifications';

	/**
	 * @inheritDoc
	 */
	public function execute($eventObj, $className, $eventName, array &$parameters) {
		if (!WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET || !WSC_CONNECT_ENABLE_PUSH) {
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

			$data = [
				'appSecret' => WSC_CONNECT_APP_SECRET,
				'appID' => WSC_CONNECT_APP_ID,
				'notification' => $notification
			];

			$request = new HTTPRequest(self::API_URL, ['method' => 'POST', 'timeout' => 5], $data);

			try {
				$request->execute();
			}
			catch (\Exception $e) {
				// Catch any exception and ignore it for now. Users can still look up their notifications in the app.
			}
		}
	}
}
