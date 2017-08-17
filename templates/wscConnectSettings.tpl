{include file='documentHeader'}
<head>
	<title>{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang} - {lang}wcf.user.menu.settings{/lang} - {PAGE_TITLE|language}</title>
	
	{include file='headInclude'}

</head>

<body id="tpl{$templateName|ucfirst}" data-template="{$templateName}" data-application="{$templateNameApplication}">

{include file='userMenuSidebar'}

{include file='header' sidebarOrientation='left'}

<header class="boxHeadline">
	<h1>{lang}wcf.user.menu.settings{/lang}: {lang}wcf.wsc_connect.wsc_connect{/lang}</h1>
</header>

{include file='userNotice'}


<div class="tabMenuContainer">
	<nav class="tabMenu">
		<ul>
			<li><a href="{$__wcf->getAnchor('status')}">{lang}wcf.wsc_connect.tab.status{/lang}</a></li>
			<li><a href="{$__wcf->getAnchor('thirdparty')}">{lang}wcf.wsc_connect.tab.thirdParty{/lang}</a></li>
		</ul>
	</nav>

	<div id="status" class="tabMenuContent">
		<div class="container containerPadding">
			{if $__wcf->getUser()->wscConnectToken && !$logoutSuccess}
				<form method="post" action="{link controller='WSCConnectSettings'}{/link}">
					<div class="success">{lang device=$__wcf->getUser()->wscConnectLoginDevice lastLogin=$__wcf->getUser()->wscConnectLoginTime}wcf.wsc_connect.status.connected{/lang}</div>
					<div class="formSubmit">
						<input type="submit" value="{lang device=$__wcf->getUser()->wscConnectLoginDevice}wcf.wsc_connect.status.logout{/lang}" accesskey="s">
						{@SECURITY_TOKEN_INPUT_TAG}
					</div>
				</form>
			{else if $logoutSuccess}
				<div class="success">{lang}wcf.wsc_connect.status.logout.success{/lang}</div>
			{else}
				<div class="info">{lang}wcf.wsc_connect.status.notConnected{/lang}</div>
			{/if}
		</div>
	</div>

	<div id="thirdparty" class="tabMenuContent">
		<div class="container containerPadding">
			{if $__wcf->getUser()->authData}
				<fieldset>					
					<dl>
						<dt><label>{lang}wcf.wsc_connect.thirdParty.label{/lang}</label></dt>
						<dd>
							<input type="text" disabled value="{$wscConnectThirdPartyToken}" class="long">
							<small>{lang}wcf.wsc_connect.thirdParty.description{/lang}</small>
						</dd>
					</dl>

				</fieldset>
			{else}
				<div class="info">{lang}wcf.wsc_connect.thirdParty.notConnected{/lang}</div>
			{/if}
		</div>
	</div>
</div>

{include file='footer'}

</body>
</html>
