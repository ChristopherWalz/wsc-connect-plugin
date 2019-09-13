{if !$__wcf->getUser()->wscConnectToken}
	{assign var=url value=$__wcf->getPath()|parse_url}

	<div id="wscConnectInfo" style="visibility: hidden;">
		<span class="icon icon16 fa-times" id="wscConnectInfoClose"></span>
		<img src="{@$__wcf->getPath()}images/wscconnect_small.png" alt="">
		<div class="textAndroid text" style="display: none;">{lang url=$url[host]}wcf.wsc_connect.info.android{/lang}</div>
		<div class="textIos text" style="display: none;">{lang url=$url[host]}wcf.wsc_connect.info.ios{/lang}</div>
		<div class="button">{lang}wcf.wsc_connect.download{/lang}</div>
	</div>
{/if}
