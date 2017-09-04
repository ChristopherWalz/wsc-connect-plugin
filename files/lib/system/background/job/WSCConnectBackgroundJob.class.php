<?php
namespace wcf\system\background\job;
use wcf\util\HTTPRequest;
use wcf\system\event\listener\WSCConnectListener;

/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectBackgroundJob extends AbstractBackgroundJob {
	/**
	 * Holds the notification data
	 */
	private $notification;

	/**
	 * Creates the job using the given the email and the destination mailbox.
	 * 
	 * @param	array		$notification
	 */
	public function __construct($notification) {
		$this->notification = $notification;
	}

	/**
	 * Increase timeout, in case the API is down/busy
	 * 
	 * @return	int	5, 10 and 20 seconds
	 */
	public function retryAfter() {
		switch ($this->getFailures()) {
			case 1:
				return 5;
			case 2:
				return 10;
			case 3:
				return 20;
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function perform() {
		$data = [
			'appSecret' => WSC_CONNECT_APP_SECRET,
			'appID' => WSC_CONNECT_APP_ID,
			'notification' => $this->notification,
			'batch' => false
		];

		$request = new HTTPRequest(WSCConnectListener::API_URL, ['method' => 'POST', 'timeout' => 5], $data);
		// exception will be caught
		$request->execute();
	}
}
