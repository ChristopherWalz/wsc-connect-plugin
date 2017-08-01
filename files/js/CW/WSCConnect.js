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
			this.cookieName = cookiePrefix + 'wscconnect';
			this.cookieTime = parseInt(cookieTime);

			if (Environment.platform() === 'android') {
				// check for existing cookie
				if (document.cookie.match(new RegExp('(^| )' + this.cookieName + '=([^;]+)')) === null) {
					var wscConnectInfo = elById('wscConnectInfo');
					var cookieNotice = elBySel('.cookiePolicyNotice');
					
					// move info up, if the cookie notice is displayed
					if (cookieNotice) {
						wscConnectInfo.style.bottom = cookieNotice.offsetHeight + 'px';

						// reset when closing the cookie notice
						elBySel('.cookiePolicyNoticeDismiss').addEventListener(WCF_CLICK_EVENT, function(event) {
							wscConnectInfo.style.bottom = 0;
						});
					}

					elShow(wscConnectInfo);

					wscConnectInfo.addEventListener(WCF_CLICK_EVENT, function(e) {
						e.preventDefault();
						
						this.setCookie();

						var href = elData(wscConnectInfo, 'href');
						window.location = href;
					}.bind(this));


					elById('wscConnectInfoClose').addEventListener(WCF_CLICK_EVENT, function(e) {
						e.stopPropagation();

						elRemove(wscConnectInfo);
						this.setCookie();
					}.bind(this));
				}
			}
		},

		setCookie: function () {
			var maxAge = 60*60*24*this.cookieTime;

			var date = new Date();
			date.setTime(date.getTime() + (this.cookieTime*24*60*60*1000));
			var expires = date.toUTCString();

			var url = window.location.hostname.replace('www.', '');
			document.cookie = this.cookieName + '=1; max-age=' + maxAge + '; expires=' + expires + '; path=/';		
		}
	};

	return new WSCConnect();
});
