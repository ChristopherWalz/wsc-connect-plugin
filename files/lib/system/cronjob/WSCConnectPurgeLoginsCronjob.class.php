<?php
namespace wcf\system\cronjob;
use wcf\data\cronjob\Cronjob;
use wcf\system\WCF;
use wcf\system\database\util\PreparedStatementConditionBuilder;

/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
class WSCConnectPurgeLoginsCronjob extends AbstractCronjob {
	/**
	 * The duration in seconds until the failed login attempts get resetted
	 * @var	int
	 */
	const LOGIN_TIME_DURATION = 60;

	/**
	 * @inheritDoc
	 */
	public function execute(Cronjob $cronjob) {
		parent::execute($cronjob);

		if (!MODULE_WSC_CONNECT || !WSC_CONNECT_APP_ID || !WSC_CONNECT_APP_SECRET) {
			return;
		}
		
		// delete expired login attempts from database
		$condition = new PreparedStatementConditionBuilder();
		$condition->add('time < ?', [TIME_NOW - self::LOGIN_TIME_DURATION]);
		$sql = "DELETE FROM	wcf".WCF_N."_wsc_connect_login_attempts ".$condition;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($condition->getParameters());
	}
}
