ALTER TABLE wcf1_user ADD wscConnectToken CHAR(36) DEFAULT NULL;
ALTER TABLE wcf1_user ADD wscConnectThirdPartyToken CHAR(36) DEFAULT NULL;

DROP TABLE IF EXISTS wcf1_wsc_connect_notifications;
CREATE TABLE wcf1_wsc_connect_notifications (
	wscConnectNotificationID INT(10) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	data text NOT NULL 
);
