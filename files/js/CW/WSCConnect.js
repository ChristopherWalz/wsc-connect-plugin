/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
define(['WoltLabSuite/Core/Environment'], function(Environment) {
	"use strict";

	function WSCConnect() {}

	WSCConnect.prototype = {
		init: function(cookiePrefix, cookieTime) {
			this.links = {
				android: 'https://play.google.com/store/apps/details?id=wscconnect.android&pcampaignid=wsc-plugin',
				ios: 'https://apps.apple.com/us/app/wsc-connect/id1462270360'
			};
			this.cookieName = cookiePrefix + 'wscconnect';
			this.cookieTime = parseInt(cookieTime);

			var userAgent = window.navigator.userAgent.toLowerCase();
			if ((Environment.platform() === 'android' || Environment.platform() === 'ios') &&
				userAgent.indexOf('wsc-connect mobile browser') === -1 &&
				document.cookie.match(new RegExp('(^| )' + this.cookieName + '=([^;]+)')) === null) {

				var wscConnectInfo = elById('wscConnectInfo');
				var pageFooterStickyNotice = elBySel('.pageFooterStickyNotice');

				var platform = Environment.platform().charAt(0).toUpperCase() + Environment.platform().slice(1);
				elShow(elBySel('.text' + platform, wscConnectInfo));

				// move page footer up
				if (pageFooterStickyNotice) {
					pageFooterStickyNotice.style.bottom = wscConnectInfo.offsetHeight + 'px';
				}

				wscConnectInfo.style.visibility = 'visible';

				wscConnectInfo.addEventListener(WCF_CLICK_EVENT, function(e) {
					e.preventDefault();

					this.setCookie();

					window.location = this.links[Environment.platform()];
				}.bind(this));


				elById('wscConnectInfoClose').addEventListener(WCF_CLICK_EVENT, function(e) {
					e.stopPropagation();

					elRemove(wscConnectInfo);

					pageFooterStickyNotice.style.bottom = 0;

					this.setCookie();
				}.bind(this));
			}
		},

		setCookie: function () {
			var maxAge = 60*60*24*this.cookieTime;

			var date = new Date();
			date.setTime(date.getTime() + (this.cookieTime*24*60*60*1000));
			var expires = date.toUTCString();

			document.cookie = this.cookieName + '=1; max-age=' + maxAge + '; expires=' + expires + '; path=/';
		}
	};

	return new WSCConnect();
});
