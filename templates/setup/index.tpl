<h2>Spotify</h2>

<form action="javascript:;" method="post" id="frmSetupSpotify" onsubmit="return false;">
<input type="hidden" name="c" value="config">
<input type="hidden" name="a" value="handleSectionAction">
<input type="hidden" name="section" value="spotify">
<input type="hidden" name="action" value="saveJson">
<input type="hidden" name="_csrf_token" value="{$session.csrf_token}">

<fieldset>
	<legend>Authentication</legend>
	
	<b>Client ID:</b><br>
	<input type="text" name="consumer_key" value="{$credentials.consumer_key}" size="64" spellcheck="false"><br>
	<br>
	<b>Client Secret:</b><br>
	<input type="password" name="consumer_secret" value="{$credentials.consumer_secret}" size="64" spellcheck="false"><br>
	<br>
	<div class="status"></div>

	<button type="button" class="submit"><span class="glyphicons glyphicons-circle-ok" style="color:rgb(0,180,0);"></span> {'common.save_changes'|devblocks_translate|capitalize}</button>	
</fieldset>
</form>

<script type="text/javascript">
$(function() {
	$('#frmSetupSpotify BUTTON.submit')
		.click(function(e) {
			genericAjaxPost('frmSetupSpotify','',null,function(json) {
				$o = $.parseJSON(json);
				if(false == $o || false == $o.status) {
					Devblocks.showError('#frmSetupSpotify div.status', $o.error);
				} else {
					Devblocks.showSuccess('#frmSetupSpotify div.status', $o.message);
				}
			});
		})
	;
});
</script>