DROP TABLE IF EXISTS wcf1_wsc_connect_notifications;
CREATE TABLE wcf1_wsc_connect_notifications (
	wscConnectNotificationID INT(10) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	data text NOT NULL 
);

DROP TABLE IF EXISTS wcf1_wsc_connect_login_attempts;
CREATE TABLE wcf1_wsc_connect_login_attempts (
	user VARCHAR(191) NOT NULL PRIMARY KEY,
	attempts TINYINT(3) NOT NULL,
	time INT(10) NOT NULL
);
