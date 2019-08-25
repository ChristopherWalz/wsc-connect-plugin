{if !$__wcf->getUser()->wscConnectToken}
	{assign var=url value=$__wcf->getPath()|parse_url}
	<script data-relocate="true">
		require(['Language'], function (Language) {
			Language.addObject({
				'wcf.wsc_connect.info.ios': '{lang url=$url[host]}wcf.wsc_connect.info.ios{/lang}',
				'wcf.wsc_connect.info.android': '{lang url=$url[host]}wcf.wsc_connect.info.android{/lang}'
			});
		});
	</script>

	<div id="wscConnectInfo" style="display: none;">
		<span class="icon icon16 fa-times" id="wscConnectInfoClose"></span>
		<img src="{@$__wcf->getPath()}images/wscconnect_small.png" alt="">
		<div class="text"></div>
		<div class="button">{lang}wcf.wsc_connect.download{/lang}</div>
	</div>
{/if}
