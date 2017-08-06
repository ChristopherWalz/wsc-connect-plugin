/**
 * @author 	Christopher Walz
 * @license	http://www.cwalz.de/forum/index.php?page=TermsOfLicense
 * @package	de.cwalz.wscConnect
 */
if (!CW) var CW = {};
CW.WSCConnect = Class.extend({
	cookeTime: 30,
	cookieName: 'wscconnect',

	init: function(cookiePrefix, cookieTime) {
		this.cookieName = cookiePrefix + 'wscconnect';
		this.cookieTime = parseInt(cookieTime);

		var userAgent = window.navigator.userAgent.toLowerCase();
		if (jQuery.browser.android && 
			userAgent.indexOf('wsc-connect mobile browser') === -1 &&
			document.cookie.match(new RegExp('(^| )' + this.cookieName + '=([^;]+)')) === null) {

			var wscConnectInfo = document.getElementById('wscConnectInfo');

			wscConnectInfo.style.display = 'block';

			wscConnectInfo.addEventListener("click", function(e) {
				e.preventDefault();
				
				this.setCookie();

				var href = $(wscConnectInfo).data('href');
				window.location = href;
			}.bind(this));


			document.getElementById('wscConnectInfoClose').addEventListener("click", function(e) {
				e.stopPropagation();

				$(wscConnectInfo).remove();
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
});
