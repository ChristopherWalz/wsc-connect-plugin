<?php
namespace wcf\system\cronjob;
use wcf\data\cronjob\Cronjob;
use wcf\system\WCF;
use wcf\system\event\listener\WSCConnectListener;
use wcf\util\JSON;
use wcf\system\database\util\PreparedStatementConditionBuilder;

/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectNotificationsCronjob extends AbstractCronjob {
	/**
	 * @inheritDoc
	 */
	public function execute(Cronjob $cronjob) {
		parent::execute($cronjob);

		if (!MODULE_WSC_CONNECT || !WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET || WSC_CONNECT_PUSH_MODE != WSCConnectListener::PUSH_MODE_BATCH) {
			return;
		}
		
		WCF::getDB()->beginTransaction();
		/** @noinspection PhpUnusedLocalVariableInspection */
		$committed = false;
		try {
			$sql = "SELECT		*
				FROM		wcf".WCF_N."_wsc_connect_notifications
				FOR UPDATE";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute();
			
			$notifications = array();
			while ($row = $statement->fetchArray()) {
				$notifications[$row['wscConnectNotificationID']] = JSON::decode($row['data']);
			}
			
			if (empty($notifications)) {
				WCF::getDB()->commitTransaction();
				$committed = true;
				return;
			}

			// send notifications
			WSCConnectListener::sendNotification($notifications, true);

			// delete them from database
			$condition = new PreparedStatementConditionBuilder();
			$condition->add('wscConnectNotificationID IN (?)', array(array_keys($notifications)));
			$sql = "DELETE FROM	wcf".WCF_N."_wsc_connect_notifications ".$condition;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($condition->getParameters());

			WCF::getDB()->commitTransaction();
			$committed = true;
		}
		finally {
			if (!$committed) WCF::getDB()->rollBackTransaction();
		}
	}
}
