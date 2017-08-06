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

{if $__wcf->getUser()->authData}
	<div class="container containerPadding marginTop">
		<fieldset>
			<legend><label>{lang}wcf.user.wsc_connect.thirdParty.label{/lang}</label></legend>
			
			<dl>
				<dt><label>{lang}wcf.user.wsc_connect.thirdParty.label{/lang}</label></dt>
				<dd>
					<input type="text" disabled value="{$wscConnectThirdPartyToken}" class="long">
					<small>{lang}wcf.user.wsc_connect.thirdParty.description{/lang}</small>
				</dd>
			</dl>

		</fieldset>
	</div>
{else}
	<div class="info">{lang}wcf.user.wsc_connect.thirdParty.notConnected{/lang}</div>
{/if}

{include file='footer'}

</body>
</html>

