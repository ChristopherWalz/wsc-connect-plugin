{if !$__wcf->getUser()->userID || !$__wcf->getUser()->wscConnectToken}
	{assign var='link' value='https://play.google.com/store/apps/details?id=wscconnect.android&pcampaignid=wsc-plugin'}
	{assign var='text' value='wcf.wsc_connect.info'}
{else}
	{assign var='link' value='https://www.wsc-connect.com/apps/'|concat:WSC_CONNECT_APP_ID}
	{assign var='text' value='wcf.wsc_connect.info.registered'}
{/if}
<div id="wscConnectInfo" class="info" data-href="{$link}" style="display: none;">
	<span class="icon icon24 fa-times" id="wscConnectInfoClose"></span>
	{lang name=PAGE_TITLE|language}{$text}{/lang} 
</div>
