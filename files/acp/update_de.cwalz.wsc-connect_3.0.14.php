<?php
use wcf\system\WCF;

// get all logged in users
$sql = 'SELECT userID, wscConnectLoginDevice, wscConnectLoginTime
		FROM wcf' . WCF_N . '_user
		WHERE wscConnectLoginDevice IS NOT NULL
		AND wscConnectLoginTime IS NOT NULL';
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute();

// update sql
$sql = 'UPDATE wcf' . WCF_N . '_user
			SET wscConnectLoginDevices = ?
			WHERE userID = ?';
$updateStatement = WCF::getDB()->prepareStatement($sql);

while ($row = $statement->fetchArray()) {
	$devices = [
		[
			'deviceID' => 'transfer',
			'device' => $row['wscConnectLoginDevice'],
			'time' => $row['wscConnectLoginTime']
		]
	];

	// transfer old columns to new column in correct format
	$updateStatement->execute([json_encode($devices), $row['userID']]);
}