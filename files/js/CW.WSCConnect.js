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
		this.links = {
			android: 'https://play.google.com/store/apps/details?id=wscconnect.android&pcampaignid=wsc-plugin',
			ios: 'https://apps.apple.com/us/app/wsc-connect/id1462270360'
		};
		this.text = {
			android: WCF.Language.get('wcf.wsc_connect.info.android'),
			ios: WCF.Language.get('wcf.wsc_connect.info.ios')
		};

		var userAgent = window.navigator.userAgent.toLowerCase();
		if ((jQuery.browser.android || jQuery.browser.iOS) && 
			userAgent.indexOf('wsc-connect mobile browser') === -1 &&
			document.cookie.match(new RegExp('(^| )' + this.cookieName + '=([^;]+)')) === null) {

			var browser = (jQuery.browser.android) ? 'android' : 'ios';
			var wscConnectInfo = document.getElementById('wscConnectInfo');

			$('.text', $(wscConnectInfo)).text(this.text[browser]);

			wscConnectInfo.style.display = 'flex';

			wscConnectInfo.addEventListener("click", function(e) {
				e.preventDefault();
				
				this.setCookie();

				window.location = this.link[browser];
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
