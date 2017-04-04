<?php
if(class_exists('Extension_PageMenuItem')):
class WgmSpotify_SetupMenuItem extends Extension_PageMenuItem {
	const POINT = 'wgm.spotify.setup.menu';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('extension', $this);
		$tpl->display('devblocks:wgm.spotify::setup/menu_item.tpl');
	}
};
endif;

if(class_exists('Extension_PageSection')):
class WgmSpotify_SetupSection extends Extension_PageSection {
	const ID = 'wgm.spotify.setup.page';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$visit = CerberusApplication::getVisit();
		
		$visit->set(ChConfigurationPage::ID, 'spotify');
		
		$credentials = DevblocksPlatform::getPluginSetting('wgm.spotify','credentials',false,true,true);
		$tpl->assign('credentials', $credentials);
		
		$tpl->display('devblocks:wgm.spotify::setup/index.tpl');
	}
	
	function saveJsonAction() {
		try {
			@$consumer_key = DevblocksPlatform::importGPC($_REQUEST['consumer_key'],'string','');
			@$consumer_secret = DevblocksPlatform::importGPC($_REQUEST['consumer_secret'],'string','');
			
			if(empty($consumer_key) || empty($consumer_secret))
				throw new Exception("Both the 'Client ID' and 'Client Secret' are required.");
			
			$credentials = [
				'consumer_key' => $consumer_key,
				'consumer_secret' => $consumer_secret,
			];
			DevblocksPlatform::setPluginSetting('wgm.spotify','credentials',$credentials,true,true);
			
			echo json_encode(array('status'=>true, 'message'=>'Saved!'));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array('status'=>false, 'error'=>$e->getMessage()));
			return;
		}
	}
};
endif;

class ServiceProvider_Spotify extends Extension_ServiceProvider implements IServiceProvider_OAuth, IServiceProvider_HttpRequestSigner {
	const ID = 'wgm.spotify.service.provider';

	function renderConfigForm(Model_ConnectedAccount $account) {
		$tpl = DevblocksPlatform::getTemplateService();
		$active_worker = CerberusApplication::getActiveWorker();
		
		$tpl->assign('account', $account);
		
		$params = $account->decryptParams($active_worker);
		$tpl->assign('params', $params);
		
		$tpl->display('devblocks:wgm.spotify::provider/spotify.tpl');
	}
	
	function saveConfigForm(Model_ConnectedAccount $account, array &$params) {
		@$edit_params = DevblocksPlatform::importGPC($_POST['params'], 'array', array());
		
		$active_worker = CerberusApplication::getActiveWorker();
		$encrypt = DevblocksPlatform::getEncryptionService();
		
		// Decrypt OAuth params
		if(isset($edit_params['params_json'])) {
			if(false == ($outh_params_json = $encrypt->decrypt($edit_params['params_json'])))
				return "The connected account authentication is invalid.";
				
			if(false == ($oauth_params = json_decode($outh_params_json, true)))
				return "The connected account authentication is malformed.";
			
			if(is_array($oauth_params))
			foreach($oauth_params as $k => $v)
				$params[$k] = $v;
		}
		
		return true;
	}
	
	private function _getAppKeys() {
		if(false == ($credentials = DevblocksPlatform::getPluginSetting('wgm.spotify','credentials',false,true,true)))
			return false;
		
		@$consumer_key = $credentials['consumer_key'];
		@$consumer_secret = $credentials['consumer_secret'];
		
		if(empty($consumer_key) || empty($consumer_secret))
			return false;
		
		return array(
			'key' => $consumer_key,
			'secret' => $consumer_secret,
		);
	}
	
	function oauthRender() {
		@$form_id = DevblocksPlatform::importGPC($_REQUEST['form_id'], 'string', '');
		
		// Store the $form_id in the session
		$_SESSION['oauth_form_id'] = $form_id;
		
		$url_writer = DevblocksPlatform::getUrlService();
		
		// [TODO] Report about missing app keys
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		$oauth = DevblocksPlatform::getOAuthService($app_keys['key'], $app_keys['secret']);
		
		// Persist the view_id in the session
		$_SESSION['oauth_state'] = CerberusApplication::generatePassword(24);
		
		// OAuth callback
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_Spotify::ID), true);

		// show_dialog=true
		
		$url = sprintf("%s?response_type=code&client_id=%s&redirect_uri=%s&scope=%s&state=%s",
			'https://accounts.spotify.com/authorize',
			rawurlencode($app_keys['key']),
			rawurlencode($redirect_url),
			rawurlencode('user-read-private user-read-email user-library-read user-top-read'), 
			rawurlencode($_SESSION['oauth_state'])
		);
		
		header('Location: ' . $url);
	}
	
	function oauthCallback() {
		@$form_id = $_SESSION['oauth_form_id'];
		@$oauth_state = $_SESSION['oauth_state'];
		unset($_SESSION['oauth_form_id']);
		
		@$code = DevblocksPlatform::importGPC($_REQUEST['code'], 'string', '');
		@$state = DevblocksPlatform::importGPC($_REQUEST['state'], 'string', '');
		@$error = DevblocksPlatform::importGPC($_REQUEST['error'], 'string', '');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::getUrlService();
		$encrypt = DevblocksPlatform::getEncryptionService();
		
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_Spotify::ID), true);
		
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		if(!empty($error))
			return false;
		
		// Get access token
		
		$url = 'https://accounts.spotify.com/api/token';
		$ch = DevblocksPlatform::curlInit($url);
		
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
			'code' => $code,
			'redirect_uri' => $redirect_url,
			'grant_type' => 'authorization_code',
			'client_id' => $app_keys['key'],
			'client_secret' => $app_keys['secret'],
		]));
		
		if(false == ($out = DevblocksPlatform::curlExec($ch)))
			return false;
		
		if(false == ($params = json_decode($out, true)) || !is_array($params) || !isset($params['access_token'])) {
			return false;
		}
		
		$label = 'Spotify';
		
		// Load their profile
		
		$url = 'https://api.spotify.com/v1/me';
		$ch = DevblocksPlatform::curlInit($url);
		$headers = [sprintf('Authorization: Bearer %s', $params['access_token'])];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
		if(false == ($out = DevblocksPlatform::curlExec($ch)))
			return false;
		
		curl_close($ch);
		
		if(false == ($json = json_decode($out, true)))
			return false;
		
		// Die with error
		if(!is_array($json) || !isset($json['display_name']))
			return false;
		
		$label = $json['display_name'];
		$params['label'] = $label;
		
		// Output
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('form_id', $form_id);
		$tpl->assign('label', $label);
		$tpl->assign('params_json', $encrypt->encrypt(json_encode($params)));
		$tpl->display('devblocks:cerberusweb.core::internal/connected_account/oauth_callback.tpl');
	}
	
	function authenticateHttpRequest(Model_ConnectedAccount $account, &$ch, &$verb, &$url, &$body, &$headers) {
		$credentials = $account->decryptParams();
		
		if(!isset($credentials['access_token']))
			return false;
			
		$headers[] = sprintf('Authorization: Bearer %s', $credentials['access_token']);
		return true;
	}
}