{capture assign='pageTitle'}{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang}{/capture}

{capture assign='contentTitle'}{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang}{/capture}

{include file='userMenuSidebar'}

{include file='header'}

<div class="section tabMenuContainer">
	<nav class="tabMenu">
		<ul>
			<li><a href="{$__wcf->getAnchor('status')}">{lang}wcf.user.wsc_connect.tab.status{/lang}</a></li>
			<li><a href="{$__wcf->getAnchor('thirdparty')}">{lang}wcf.user.wsc_connect.tab.thirdParty{/lang}</a></li>
		</ul>
	</nav>

	<div id="status" class="tabMenuContent">
		<div class="section">
			{if $__wcf->getUser()->wscConnectToken && !$logoutSuccess}
				<form method="post" action="{link controller='WSCConnectSettings'}{/link}">
					<div class="success">{lang device=$__wcf->getUser()->wscConnectLoginDevice lastLogin=$__wcf->getUser()->wscConnectLoginTime}wcf.user.wsc_connect.status.connected{/lang}</div>
					<div class="formSubmit">
						<input type="submit" value="{lang device=$__wcf->getUser()->wscConnectLoginDevice}wcf.user.wsc_connect.status.logout{/lang}" accesskey="s">
						{@SECURITY_TOKEN_INPUT_TAG}
					</div>
				</form>
			{else if $logoutSuccess}
				<div class="success">{lang}wcf.user.wsc_connect.status.logout.success{/lang}</div>
			{else}
				<div class="info">{lang}wcf.user.wsc_connect.status.notConnected{/lang}</div>
			{/if}
		</div>
	</div>

	<div id="thirdparty" class="tabMenuContent">
		<div class="section">
			{if $__wcf->getUser()->authData}
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
		</div>
	</div>
</div>

{include file='footer'}
