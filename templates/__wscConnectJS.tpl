{if !$__wcf->getUser()->wscConnectToken}
	require(['CW/WSCConnect'], function(WSCConnect) {
		WSCConnect.init('{COOKIE_PREFIX}', {WSC_CONNECT_INFO_TIME});
	});
{/if}