{capture assign='pageTitle'}{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang}{/capture}

{capture assign='contentTitle'}{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang}{/capture}

{include file='userMenuSidebar'}

{include file='header'}

<section class="section">
	{if $__wcf->getUser()->authData}
		<h2 class="sectionTitle">{lang}wcf.user.wsc_connect.thirdParty.title{/lang}</h2>
		
		<dl>
			<dt><label>{lang}wcf.user.wsc_connect.thirdParty.label{/lang}</label></dt>
			<dd>
				<input type="text" disabled value="{$wscConnectThirdPartyToken}" class="long">
				<small>{lang}wcf.user.wsc_connect.thirdParty.description{/lang}</small>
			</dd>
		</dl>
	{else}
		<div class="info">{lang}wcf.user.wsc_connect.thirdParty.notConnected{/lang}</div>
	{/if}
</section>

{include file='footer'}
