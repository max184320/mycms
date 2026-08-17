<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group
-----------------------------------------------------
 https://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 This code is protected by copyright
=====================================================
 File: social.class.php
-----------------------------------------------------
 Use: Authorization through social networks
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

class AuthViaVK {
	private $social_config = array();
	private $return_domain = '';

	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}

	function get_url() {

		$_SESSION['state'] = bin2hex(random_bytes(16));
		$_SESSION['vkcode'] = generateCodeVerifier();
		$codeChallenge = generateCodeChallenge($_SESSION['vkcode']);

		$social_params = array(
			'client_id'     => $this->social_config['vkid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=vk",
			'scope' => 'email',
			'state' => $_SESSION['state'],
			'response_type' => 'code',
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => 'S256'
		);
		
		return 'https://id.vk.ru/authorize' . '?' . http_build_query($social_params, '', '&');
	}
	
    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['vkid'],
			'grant_type' => 'authorization_code',
			'code_verifier' => $_SESSION['vkcode'] ?? '',
			'device_id' => $_GET['device_id'] ?? '',
			'code' => $_GET['code'] ?? '',
			'state' => $_SESSION['state'] ?? '',
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=vk"
		);

		$token = json_decode(http_get_contents('https://id.vk.ru/oauth2/auth', $params), true);

		if (isset($token['access_token'])) {

			$params = array(
				'client_id'     => $this->social_config['vkid'],
				'access_token' => $token['access_token']
			);

			$user = json_decode(http_get_contents('https://id.vk.ru/oauth2/user_info', $params), true);

			if (isset($user['user']['user_id'])) {

	            $user = $user['user'];

				$user['email'] = $user['email'] ?? '';
				$user['nickname'] = $user['nickname'] ?? '';
				$user['first_name'] = $user['first_name'] ?? '';
				$user['last_name'] = $user['last_name'] ?? '';
				$user['avatar'] = $user['avatar'] ?? '';

				return array ('sid' => sha1 ('vkontakte'.$user['user_id']), 'nickname' => $user['nickname'], 'name' => $user['first_name'].' '.$user['last_name'], 'email' => $user['email'], 'avatar' => $user['avatar'], 'provider' => 'vkontakte' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class AuthViaGoogle {
	private $social_config = array();
	private $return_domain = '';

	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}

	function get_url() {

		$_SESSION['state'] = bin2hex(random_bytes(16));

		$social_params = array(
			'client_id'     => $this->social_config['googleid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=google",
			'scope'         => 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
			'state' => $_SESSION['state'],
			'response_type' => 'code'
		);

		return 'https://accounts.google.com/o/oauth2/auth' . '?' . http_build_query($social_params, '', '&');
	}

    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['googleid'],
			'client_secret' => $this->social_config['googlesecret'],
			'grant_type' 	=> 'authorization_code',
			'code' => $_GET['code'] ?? '',
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=google",

		);

		$token = json_decode(http_get_contents('https://accounts.google.com/o/oauth2/token', $params), true);

		if (isset($token['access_token'])) {

			$user = json_decode(http_get_contents('https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . urlencode($token['access_token'])), true);

			if (isset($user['id'])) {

				$user['email'] = $user['email'] ?? '';
				$user['name'] = $user['name'] ?? '';
				$user['given_name'] = $user['given_name'] ?? '';
				$user['family_name'] = $user['family_name'] ?? '';
				$user['picture'] = $user['picture'] ?? '';

				return array ('sid' => sha1 ('google'.$user['id']), 'nickname' => $user['name'], 'name' => $user['given_name'].' '.$user['family_name'], 'email' => $user['email'], 'avatar' => $user['picture'], 'provider' => 'Google' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class AuthViaMailru {
	private $social_config = array();
	private $return_domain = '';

	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}

	function get_url() {

		$_SESSION['state'] = bin2hex(random_bytes(16));

		$social_params = array(
			'client_id'     => $this->social_config['mailruid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=mailru",
			'scope'         => 'userinfo',
			'state' => $_SESSION['state'],
			'response_type' => 'code'
		);

		return 'https://oauth.mail.ru/login' . '?' . http_build_query($social_params, '', '&');
	}

    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['mailruid'],
			'client_secret' => $this->social_config['mailrusecret'],
			'grant_type' 	=> 'authorization_code',
			'code' => $_GET['code'] ?? '',
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=mailru",

		);

		$token = json_decode(http_get_contents('https://oauth.mail.ru/token', $params), true);

		if (isset($token['access_token'])) {

			$params = array(
				'access_token'  => $token['access_token']
			);

			$user = json_decode(http_get_contents('https://oauth.mail.ru/userinfo' . '?' . http_build_query($params)), true);

			if (isset($user['nickname']) AND $user['nickname'] AND isset($user['email']) AND $user['email']) {
				
				$uid = $user['nickname'].$user['email'];
				
				$user['email'] = $user['email'] ?? '';
				$user['name'] = $user['name'] ?? '';
				$user['image'] = $user['image'] ?? '';

				return array ('sid' => sha1 ('mailru'.$uid), 'nickname' => $user['nickname'], 'name' => $user['name'], 'email' => $user['email'], 'avatar' => $user['image'], 'provider' => 'Mail.Ru' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class AuthViaYandex {
	private $social_config = array();
	private $return_domain = '';

	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}
	
	function get_url() {
		$_SESSION['state'] = bin2hex(random_bytes(16));

		$social_params = array(
			'client_id'     => $this->social_config['yandexid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=yandex",
			'state' => $_SESSION['state'],
			'response_type' => 'code'
		);

		return 'https://oauth.yandex.ru/authorize' . '?' . http_build_query($social_params, '', '&');
	}

    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['yandexid'],
			'client_secret' => $this->social_config['yandexsecret'],
			'grant_type' 	=> 'authorization_code',
			'code' => $_GET['code'] ?? ''
		);

		$token = json_decode(http_get_contents('https://oauth.yandex.ru/token', $params), true);

		if (isset($token['access_token'])) {

			$params = array(
				'format'       => 'json',
				'oauth_token'  => $token['access_token']
			);

			$user = json_decode(http_get_contents('https://login.yandex.ru/info' . '?' . http_build_query($params)), true);

			if (isset($user['id'])) {
				
				if( $user['default_avatar_id'] ) {
					$user['avatar'] = "https://avatars.yandex.net/get-yapic/{$user['default_avatar_id']}/islands-200";
				} else $user['avatar'] = "";

				$user['display_name'] = $user['display_name'] ?? '';
				$user['real_name'] = $user['real_name'] ?? '';
				$user['default_email'] = $user['default_email'] ?? '';
				$user['avatar'] = $user['avatar'] ?? '';

				return array ('sid' => sha1 ('yandex'.$user['id']), 'nickname' => $user['display_name'], 'name' => $user['real_name'], 'email' => $user['default_email'], 'avatar' => $user['avatar'], 'provider' => 'Yandex' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class AuthViaFacebook {
	private $social_config = array();
	private $return_domain = '';
	
	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}

	function get_url() {
		$_SESSION['state'] = bin2hex(random_bytes(16));

		$social_params = array(
			'client_id'     => $this->social_config['fcid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=fc",
			'scope' => 'public_profile,email',
			'display' => 'popup',
			'state' => $_SESSION['state'],
			'response_type' => 'code'
		);

		return 'https://www.facebook.com/dialog/oauth' . '?' . http_build_query($social_params, '', '&');
	}

    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['fcid'],
			'client_secret' => $this->social_config['fcsecret'],
			'code' => $_GET['code'] ?? '',
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=fc"
		);

		$token = json_decode(http_get_contents('https://graph.facebook.com/oauth/access_token' . '?' . http_build_query($params)), true);

		if (isset($token['access_token'])) {

			$params = array('access_token' => $token['access_token'], 'fields' => "id,name,email,first_name,last_name,picture");

			$user = json_decode(http_get_contents('https://graph.facebook.com/me' . '?' . http_build_query($params)), true);

			if (isset($user['id'])) {

				$user['name'] = $user['name'] ?? '';
				$user['first_name'] = $user['first_name'] ?? '';
				$user['last_name'] = $user['last_name'] ?? '';
				$user['email'] = $user['email'] ?? '';

				return array ('sid' => sha1 ('facebook'.$user['id']), 'nickname' => $user['name'], 'name' => $user['first_name'].' '.$user['last_name'], 'email' => $user['email'], 'avatar' => "https://graph.facebook.com/".$user['id']."/picture?type=large", 'provider' => 'Facebook' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class AuthViaOdnoklassniki {
	
	private $social_config = array();
	private $return_domain = '';
	
	function __construct($social_config) {
		$this->social_config = $social_config;
		$this->return_domain = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
	}

	function get_url() {
		$_SESSION['state'] = bin2hex(random_bytes(16));

		$social_params = array(
			'client_id'     => $this->social_config['odid'],
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=od",
			'scope' => 'VALUABLE_ACCESS;GET_EMAIL',
			'state' => $_SESSION['state'],
			'response_type' => 'code'
		);

		return 'https://connect.ok.ru/oauth/authorize' . '?' . http_build_query($social_params, '', '&');
	}

    function get_user() {
		global $lang;

		$params = array(
			'client_id'     => $this->social_config['odid'],
			'client_secret' => $this->social_config['odsecret'],
			'grant_type' => 'authorization_code',
			'code' => $_GET['code'] ?? '',
			'redirect_uri'  => $this->return_domain . "index.php?do=auth-social&provider=od"
		);

		$token = json_decode(http_get_contents('https://api.ok.ru/oauth/token.do', $params), true);

		if (isset($token['access_token'])) {

			$sign = md5("application_key={$this->social_config['odpublic']}fields=name,first_name,last_name,email,pic_2format=jsonmethod=users.getCurrentUser" . md5("{$token['access_token']}{$this->social_config['odsecret']}"));

			$params = array(
				'method'          => 'users.getCurrentUser',
				'access_token'    => $token['access_token'],
				'application_key' => $this->social_config['odpublic'],
				'fields'       	  => 'name,first_name,last_name,email,pic_2',
				'format'          => 'json',
				'sig'             => $sign
			);

			$user = json_decode(http_get_contents('https://api.ok.ru/fb.do' . '?' . http_build_query($params)), true);

			if (isset($user['uid'])) {

				$user['name'] = $user['name'] ?? '';
				$user['first_name'] = $user['first_name'] ?? '';
				$user['last_name'] = $user['last_name'] ?? '';
				$user['email'] = $user['email'] ?? '';
				$user['pic_2'] = $user['pic_2'] ?? '';

				return array ('sid' => sha1 ('odnoklassniki'.$user['uid']), 'nickname' => $user['name'], 'name' => $user['first_name'].' '.$user['last_name'], 'email' => $user['email'], 'avatar' => $user['pic_2'], 'provider' => 'Odnoklassniki' );

			} else return $lang['social_err_3'];

		} else return $lang['social_err_1'];

    }

}

class SocialAuth {

	private $auth = null;
	private $social_config = array();
	private $social_user = array();

	public $provider = null;

    function __construct( $social_config, $provider ){
		
		if( !$provider ) {
			 return;
		}

		$this->social_config = $social_config;

        if ( ($provider == "vk" || $provider == "vkontakte") && $social_config['vk']) {

            $this->auth = new AuthViaVK($this->social_config);
			$this->provider = "vk";

        } elseif ( ($provider == "google" || $provider == "Google") && $social_config['google']) {

            $this->auth = new AuthViaGoogle($this->social_config);
			$this->provider = "google";

        } elseif ( ($provider == "mailru" || $provider == "Mail.Ru") && $social_config['mailru']) {

            $this->auth = new AuthViaMailru($this->social_config);
			$this->provider = "mailru";

        } elseif ( ($provider == "yandex" || $provider == "Yandex") && $social_config['yandex']) {
            $this->auth = new AuthViaYandex($this->social_config);
			$this->provider = "yandex";

        } elseif ( ($provider == "fc" || $provider == "Facebook") && $social_config['fc']) {
   	        $this->auth = new AuthViaFacebook($this->social_config);
			$this->provider = "fc";

        } elseif ( ($provider == "od" || $provider == "Odnoklassniki") && $social_config['od']) {
            $this->auth = new AuthViaOdnoklassniki($this->social_config);
			$this->provider = "od";

        } 

    }

    function getuser(){
		global $lang;

		if( $this->auth !== null ) {

			$user = $this->auth->get_user();

			if( is_array($user) ) {

				if( !$user['nickname'] ) {

					$user['nickname'] = $user['name'];

				}

				$user['email'] = sanitize_email($user['email']);
				$user['nickname'] = preg_replace("/[\||\'|\<|\>|\[|\]|\%|\"|\!|\?|\$|\@|\#|\/|\\\|\&\~\*\{\}\+]/", '', $user['nickname'] );
				$user['nickname'] = str_ireplace( ".php", "_disabled", $user['nickname'] );
				$user['nickname'] = str_replace('&', '', $user['nickname']);
				$user['nickname'] = trim( htmlspecialchars( $user['nickname'], ENT_QUOTES, 'UTF-8' ) );
				$user['name'] = trim( htmlspecialchars( $user['name'], ENT_QUOTES, 'UTF-8' ) );
				
				if (dle_strlen($user['nickname']) > 40) $user['nickname'] = dle_substr($user['nickname'], 0, 40);

			}
			unset($_SESSION['state']);
			$this->social_user = $user;

			return $user;

		} else return $lang['social_err_2'];

	}

	function getAuthUrl() {
		return $this->auth?->get_url() ?? '';
	}

	function RegisterUser() {
		global $db, $config, $lang;
		
		$password = md5(password_hash(UniqIDReal(11), PASSWORD_DEFAULT));
		$this->social_user['password'] = $password;

		$mail_registered = false;
		
		if( isset($this->social_user['email']) && $this->social_user['email'] ) {

			$email = $db->safesql($this->social_user['email']);
			$row = $db->super_query("SELECT email, name, user_id, user_group  FROM " . USERPREFIX . "_users WHERE email = '{$email}'");
			
			if ($row['user_id'] && ($row['user_group'] != 1 || ($row['user_group'] == 1 && $config['allow_admin_social']) ) ) {

				$mail_registered = true;
				$name = $row['name'];

				$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE sid='{$this->social_user['sid']}'");
				$db->query("INSERT INTO " . USERPREFIX . "_social_login (sid, uid, password, provider, wait, waitlogin) VALUES ('{$this->social_user['sid']}', '{$row['user_id']}', '{$password}', '{$this->social_user['provider']}', '1', '0')");
				
				$id = $db->insert_id();

				$link = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';

				$link = $link . "index.php?do=auth-social&action=approve&id={$id}&provider={$this->social_user['provider']}&key={$password}";

				$row = $db->super_query("SELECT * FROM " . PREFIX . "_email WHERE name='wait_mail' LIMIT 0,1");
				$mail = new dle_mail($config, $row['use_html']);

				$row['template'] = stripslashes($row['template']);
				$row['template'] = str_replace("{%username%}", $name, $row['template']);
				$row['template'] = str_replace("{%link%}", $link, $row['template']);
				$row['template'] = str_replace("{%ip%}", get_ip(), $row['template']);
				$row['template'] = str_replace("{%network%}", $this->social_user['provider'], $row['template']);

				$mail->send($email, $lang['wait_subj'], $row['template']);

				$this->social_user['wait_mail_approve'] = 1;

			}

		}

		if(!$mail_registered) {
			$db->query("INSERT INTO " . USERPREFIX . "_social_login (sid, uid, password, provider, wait, waitlogin) VALUES ('{$this->social_user['sid']}', '0', '{$password}', '{$this->social_user['provider']}', '0', '1')");
		}
		
		$_SESSION['social_auth'] = json_encode($this->social_user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		
		$this->Redirect();
	}

	function CheckUser() {
		global $db, $lang;
		
		$social_user = !empty($_POST['social_auth']) ? json_decode($_POST['social_auth'], true) : array();
		
		if (!empty($social_user) && is_array($social_user) && count($social_user) && isset($social_user['sid']) ) {
			
			$social_user['sid'] = $db->safesql($social_user['sid']);

			$row = $db->super_query("SELECT * FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");
			
			if (isset($row['id']) && $row['id'] && $row['password'] === $social_user['password']) {
				
				$name = strtr($_POST['name'] ?? '', array_flip(get_html_translation_table(HTML_ENTITIES, ENT_QUOTES, 'UTF-8')));
				$name = trim($name, chr(0xC2) . chr(0xA0));
				$name = preg_replace('#\s+#u', ' ', $name);

				$email = sanitize_email($_POST['email'] ?? '');

				$full_name = strtr($social_user['name'], array_flip(get_html_translation_table(HTML_ENTITIES, ENT_QUOTES, 'UTF-8')));
				$full_name = trim(htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'));

				if (dle_strlen($name) > 40) $name = dle_substr($name, 0, 40);

				if ($this->check_registration($name, $email)) {
					
					$social_user['nickname'] = strip_tags($name);
					$social_user['email'] = $email;
					$social_user['name'] = $full_name;
					$this->social_user = $social_user;

					$this->do_register_user($this->social_user);
				}

			}
		}

		$this->error($lang['social_auth_4']);
		die();

		
	}

	function AuthUser($member_id) {
		global $db, $config, $_TIME, $_IP;
		
		unset($_SESSION['state']);
		unset($_SESSION['social_auth']);

		session_regenerate_id();
		set_cookie("dle_user_id", $member_id['user_id'], 365);
		set_cookie("dle_password", md5($member_id['password']), 365);

		$_SESSION['dle_user_id'] = $member_id['user_id'];
		$_SESSION['dle_password'] = md5($member_id['password']);
		$_SESSION['member_lasttime'] = $member_id['lastdate'];

		if ($config['twofactor_auth'] && $member_id['twofactor_auth']) {
			$config['log_hash'] = 1;
		}

		if ($config['log_hash']) {

			$hash = UniqIDReal(32);

			$db->query("UPDATE LOW_PRIORITY " . USERPREFIX . "_users SET hash='{$hash}', lastdate='{$_TIME}', logged_ip='{$_IP}' WHERE user_id='{$member_id['user_id']}'");

			set_cookie("dle_hash", $hash, 365);
		} else $db->query("UPDATE LOW_PRIORITY " . USERPREFIX . "_users SET lastdate='{$_TIME}', logged_ip='{$_IP}' WHERE user_id='{$member_id['user_id']}'");

	}

	function AttachUser() {
		global $db, $member_id;
		
		$key = md5($member_id['password']);

		$db->query("INSERT INTO " . USERPREFIX . "_social_login (sid, uid, password, provider, wait, waitlogin) VALUES ('{$this->social_user['sid']}', '{$member_id['user_id']}', '{$key}', '{$this->social_user['provider']}', '0', '0')");
		$user = urlencode($member_id['name']);

		$this->Redirect( "{$_SERVER['SCRIPT_NAME']}?subaction=userinfo&user={$user}&id={$member_id['user_id']}&provider={$this->social_user['provider']}&action=attach" );
	}
		
	function do_register_user($social_user) {
		global $db, $config, $user_group;

		$add_time = time();
		$_IP = get_ip();
		if (intval($config['reg_group']) < 3) $config['reg_group'] = 4;

		$password = UniqIDReal(11);
		$hash = '';

		$password = password_hash($password, PASSWORD_DEFAULT);
		$key = md5($password);
		$password = $db->safesql($password);

		if ($config['log_hash']) {
			$hash = UniqIDReal(32);
		}

		$social_user['nickname'] = $db->safesql($social_user['nickname']);
		$social_user['email'] = $db->safesql($social_user['email']);
		$social_user['name'] = $db->safesql($social_user['name']);

		$db->query("INSERT INTO " . USERPREFIX . "_users (name, password, email, reg_date, lastdate, user_group, info, signature, fullname, favorites, xfields, hash, logged_ip) VALUES ('{$social_user['nickname']}', '{$password}', '{$social_user['email']}', '{$add_time}', '{$add_time}', '{$config['reg_group']}', '', '', '{$social_user['name']}', '', '', '{$hash}', '{$_IP}')");

		$id = $db->insert_id();

		$db->query("UPDATE " . USERPREFIX . "_social_login SET uid='{$id}', password='{$key}', waitlogin='0' WHERE sid='{$social_user['sid']}'");

		if (intval($user_group[$config['reg_group']]['max_foto']) > 0 && $social_user['avatar']) {

			$driver = DLEFiles::getDefaultStorage();
			$config['avatar_remote'] = intval($config['avatar_remote'] ?? -1);
			if ($config['avatar_remote'] > -1)  $driver = $config['avatar_remote'];

			DLEFiles::init($driver, $config['local_on_fail']);
			$thumb = new thumbnail($social_user['avatar']);

			if (!$thumb->error) {

				if (!$config['tinypng_avatar']) {
					$thumb->tinypng = false;
				}

				$thumb->tinypng_resize = true;
				$thumb->size_auto($user_group[$config['reg_group']]['max_foto']);

				$foto_name = $thumb->save("fotos/foto_" . $id . ".jpg");

				if ($foto_name && !$thumb->error) {

					if ($driver && !DLEFiles::$remote_error) {

						$foto_name = $db->safesql(DLEFiles::GetBaseURL() . "fotos/" . $foto_name);
					} else {

						if (strpos($config['http_home_url'], "//") === 0) $avatar_url = $config['http_home_url'];
						elseif (strpos($config['http_home_url'], "/") === 0) $avatar_url = "//" . $_SERVER['HTTP_HOST'] . $config['http_home_url'];
						else $avatar_url = $config['http_home_url'];

						$avatar_url = str_ireplace("https:", "", $avatar_url);
						$avatar_url = str_ireplace("http:", "", $avatar_url);

						$foto_name = $db->safesql($avatar_url . "uploads/fotos/" . $foto_name);
					}

					$db->query("UPDATE " . USERPREFIX . "_users SET foto='{$foto_name}' WHERE user_id = '{$id}'");
				}
			}
		}

		unset($_SESSION['social_auth']);
		unset($_SESSION['state']);
		unset($_SESSION['auth-referrer']);
		
		session_regenerate_id();

		set_cookie("dle_user_id", $id, 365);
		set_cookie("dle_password", $key, 365);

		$_SESSION['dle_user_id'] = $id;
		$_SESSION['dle_password'] = $key;
		$_SESSION['member_lasttime'] = $add_time;

		if ($config['log_hash']) {
			set_cookie("dle_hash", $hash, 365);
		}

		echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		die();
	}

	function check_registration($name, $email) {
		global $lang, $db, $banned_info, $config, $relates_word;

		$_IP = get_ip();

		if (empty($name) || preg_match("/[\||\'|\<|\>|\[|\]|\%|\"|\!|\?|\$|\@|\#|\/|\\\|\&\~\*\{\}\+]/", $name) || dle_strlen($name) > 40) {
			$this->error($lang['reg_err_4']);
		}
		if (!is_valid_email($email)) {
			$this->error($lang['reg_err_6']);
		}
		if (strpos(strtolower($name), '.php') !== false) {
			$this->error($lang['reg_err_4']);
		}

		if ($config['max_users'] > 0) {

			$row = $db->super_query("SELECT COUNT(*) as count FROM " . USERPREFIX . "_users");

			if ($row['count'] >= $config['max_users']) {
				$this->error($lang['reg_err_10']);
			}
		}

		if (isset($banned_info['name']) && is_array($banned_info['name']) && count($banned_info['name'])) foreach ($banned_info['name'] as $banned) {

			$banned['name'] = str_replace('\*', '.*', preg_quote(dle_strtolower($banned['name']), "#"));

			if ($banned['name'] && preg_match("#^{$banned['name']}$#iu", dle_strtolower($name))) {

				if ($banned['descr']) {
					$lang['reg_err_21'] = str_replace("{descr}", $lang['reg_err_22'], $lang['reg_err_21']);
					$lang['reg_err_21'] = str_replace("{descr}", $banned['descr'], $lang['reg_err_21']);
				} else
					$lang['reg_err_21'] = str_replace("{descr}", "", $lang['reg_err_21']);

				$this->error($lang['reg_err_21']);
			}
		}

		if (isset($banned_info['email']) && is_array($banned_info['email']) && count($banned_info['email'])) foreach ($banned_info['email'] as $banned) {

			$banned['email'] = str_replace('\*', '.*', preg_quote(dle_strtolower($banned['email']), "#"));

			if ($banned['email'] && preg_match("#^{$banned['email']}$#iu", dle_strtolower($email))) {

				if ($banned['descr']) {
					$lang['reg_err_23'] = str_replace("{descr}", $lang['reg_err_22'], $lang['reg_err_23']);
					$lang['reg_err_23'] = str_replace("{descr}", $banned['descr'], $lang['reg_err_23']);
				} else
					$lang['reg_err_23'] = str_replace("{descr}", "", $lang['reg_err_23']);

				$this->error($lang['reg_err_23']);
			}
		}
		$all_words = load_json(ENGINE_DIR . '/data/wordfilter.json');

		if (is_array($all_words) && count($all_words)) {

			foreach ($all_words as $value) {

				if ($value['use_case']) {
					$register = "";
				} else $register = "i";

				$register .= "u";

				if ($value['type']) {
					$find_text = "#(^|\b|\s|\<br \/\>)" . preg_quote($value['find'], "#") . "(\b|\s|!|\?|\.|,|$)#" . $register;
				} else {
					$find_text = "#(" . preg_quote($value['find'], "#") . ")#" . $register;
				}

				if (preg_match($find_text, $name)) {
					$this->error($lang['reg_err_4']);
				}
			}
		}
		
		$name = $db->safesql(trim(dle_strtolower($name)));
		$search_name = $db->safesql(strtr($name, $relates_word));

		$row = $db->super_query("SELECT user_id FROM " . USERPREFIX . "_users WHERE LOWER(name) REGEXP '^{$search_name}$' OR name = '{$name}'");

		if ( isset($row['user_id']) ) {
			$this->error($lang['reg_err_44']);
		}
		
		$email = $db->safesql($email);
		$row = $db->super_query("SELECT user_id FROM " . USERPREFIX . "_users WHERE email = '{$email}'");

		if (isset($row['user_id'])) {
			$this->error($lang['social_auth_5']);
		}

		if (!$config['reg_multi_ip']) {

			$row = $db->super_query("SELECT COUNT(*) as count FROM " . USERPREFIX . "_users WHERE logged_ip = '{$_IP}'");

			if ($row['count']) {
				$this->error($lang['reg_err_26']);
			}
		}

		return true;
	}

	function error($text) {
		
		header("Content-type: text/html; charset=utf-8");
		
		echo json_encode(array('error' => $text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		die();
	}

	function Redirect( $return_href = '' ) {
		global $lang;

		$popup = "<!DOCTYPE html><html><head><title>Redirect</title><meta charset=\"utf-8\"></head><body>{text}</body></html>";

		if( !$return_href ) {
			$force = 0;
			$return_href = $_SESSION['auth-referrer'] ?? $_SESSION['referrer'] ?? (substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/');
			$return_href = str_replace("&amp;", "&", $return_href);
		} else {
			$force = 1;
		}
		
		unset($_SESSION['auth-referrer']);
		
		$js_return_href = json_encode($return_href, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$js_popup = <<<HTML
<script>
var force = {$force};
var return_href = {$js_return_href};
if (window.opener && !window.opener.closed) {
    try {
		if (force) {
			window.opener.location.href = return_href;
		} else {
			window.opener.location.reload(true);
		}
        window.close();
    } catch (e) {
        window.location.href = return_href;
    }
} else {
    window.location.href = return_href;
}
</script>
HTML;

		echo str_replace("{text}", $lang['social_login_ok'] . $js_popup, $popup);
		die();
	}

}
