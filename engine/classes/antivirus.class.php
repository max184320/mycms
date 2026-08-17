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
 File: antivirus.class.php
-----------------------------------------------------
 Use: Antivirus class
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

class antivirus
{
	public $bad_files       = array();
	public $snap_files      = array();
	public $track_files     = array();
	public $snap      		 = false;
	public $checked_folders = array();
	public $dir_split       = '/';

	public $cache_files       = array(
		"./engine/cache/system/storages.php",
		"./engine/cache/system/route.rules.php",
		"./engine/cache/system/xfields.php",
		"./engine/cache/system/userxfields.php",
		"./engine/data/config.php",
		"./engine/data/videoconfig.php",
		"./engine/data/socialconfig.php"
	);

	public $good_files       = array(
		"./.htaccess",
		"./backup/.htaccess",
		"./engine/.htaccess",
		"./public/.htaccess",
		"./engine/cache/system/storages.php",
		"./engine/cache/system/route.rules.php",
		"./engine/cache/system/xfields.php",
		"./engine/cache/system/userxfields.php",
		"./language/.htaccess",
		"./uploads/files/.htaccess",
		"./uploads/.htaccess",
		"./engine/ajax/newsletter_templates.php",
		"./engine/ajax/aiproxy.php",
		"./engine/ajax/quote.php",
		"./engine/ajax/vote.php",
		"./engine/ajax/feedback.php",
		"./engine/ajax/templates.php",
		"./engine/ajax/find_relates.php",
		"./engine/ajax/deletecomments.php",
		"./engine/ajax/controller.php",
		"./engine/ajax/calendar.php",
		"./engine/ajax/editcomments.php",
		"./engine/ajax/editnews.php",
		"./engine/ajax/favorites.php",
		"./engine/ajax/newsletter.php",
		"./engine/ajax/rating.php",
		"./engine/ajax/ratingcomments.php",
		"./engine/ajax/registration.php",
		"./engine/ajax/addcomments.php",
		"./engine/ajax/antivirus.php",
		"./engine/ajax/updates.php",
		"./engine/ajax/clean.php",
		"./engine/ajax/poll.php",
		"./engine/ajax/rss.php",
		"./engine/ajax/keywords.php",
		"./engine/ajax/pm.php",
		"./engine/ajax/upload.php",
		"./engine/ajax/profile.php",
		"./engine/ajax/find_tags.php",
		"./engine/ajax/search.php",
		"./engine/ajax/message.php",
		"./engine/ajax/adminfunction.php",
		"./engine/ajax/allvotes.php",
		"./engine/ajax/rebuild.php",
		"./engine/ajax/complaint.php",
		"./engine/ajax/comments.php",
		"./engine/ajax/replycomments.php",
		"./engine/ajax/twofactor.php",
		"./engine/ajax/plugins.php",
		"./engine/ajax/commentssubscribe.php",
		"./engine/data/config.php",
		"./engine/data/videoconfig.php",
		"./engine/data/socialconfig.php",
		"./engine/data/dbconfig.php",
		"./engine/skins/default.skin.php",
		"./engine/editor/fullnews.php",
		"./engine/editor/fullsite.php",
		"./engine/editor/newsletter.php",
		"./engine/editor/shortnews.php",
		"./engine/editor/shortsite.php",
		"./engine/editor/comments.php",
		"./engine/editor/static.php",
		"./engine/editor/pm.php",
		"./engine/ajax/emotions.php",
		"./engine/classes/sitemap/sitemap.php",
		"./engine/classes/tinify/tinify.php",
		"./engine/classes/images/adapters/imagick.adapter.php",
		"./engine/classes/images/adapters/gd.adapter.php",
		"./engine/classes/images/images.php",
		"./engine/classes/twofactor/twofactor.php",
		"./engine/classes/filesystem/adapters/sftp.adapter.php",
		"./engine/classes/filesystem/adapters/s3.adapter.php",
		"./engine/classes/filesystem/adapters/ftp.adapter.php",
		"./engine/classes/filesystem/adapters/webdav.adapter.php",
		"./engine/classes/filesystem/adapters/local.adapter.php",
		"./engine/classes/filesystem/filesystem.php",
		"./engine/classes/seo/seo.php",
		"./engine/classes/urls.class.php",
		"./engine/classes/userxfields.class.php",
		"./engine/classes/fastroute/fastroute.php",
		"./engine/classes/geoip/geo.class.php",
		"./engine/classes/geoip/ip2location.class.php",
		"./engine/classes/mail/language/phpmailer.lang-th.php",
		"./engine/classes/mail/language/phpmailer.lang-ku.php",
		"./engine/classes/mail/language/phpmailer.lang-ur.php",
		"./engine/classes/mail/language/phpmailer.lang-as.php",
		"./engine/classes/mail/language/phpmailer.lang-fi.php",
		"./engine/classes/mail/language/phpmailer.lang-es.php",
		"./engine/classes/mail/language/phpmailer.lang-bg.php",
		"./engine/classes/mail/language/phpmailer.lang-eo.php",
		"./engine/classes/mail/language/phpmailer.lang-zh.php",
		"./engine/classes/mail/language/phpmailer.lang-gl.php",
		"./engine/classes/mail/language/phpmailer.lang-az.php",
		"./engine/classes/mail/language/phpmailer.lang-it.php",
		"./engine/classes/mail/language/phpmailer.lang-lt.php",
		"./engine/classes/mail/language/phpmailer.lang-ca.php",
		"./engine/classes/mail/language/phpmailer.lang-hr.php",
		"./engine/classes/mail/language/phpmailer.lang-hi.php",
		"./engine/classes/mail/language/phpmailer.lang-fr.php",
		"./engine/classes/mail/language/phpmailer.lang-de.php",
		"./engine/classes/mail/language/phpmailer.lang-hy.php",
		"./engine/classes/mail/language/phpmailer.lang-nl.php",
		"./engine/classes/mail/language/phpmailer.lang-sr_latn.php",
		"./engine/classes/mail/language/phpmailer.lang-sk.php",
		"./engine/classes/mail/language/phpmailer.lang-pl.php",
		"./engine/classes/mail/language/phpmailer.lang-he.php",
		"./engine/classes/mail/language/phpmailer.lang-tl.php",
		"./engine/classes/mail/language/phpmailer.lang-si.php",
		"./engine/classes/mail/language/phpmailer.lang-hu.php",
		"./engine/classes/mail/language/phpmailer.lang-ru.php",
		"./engine/classes/mail/language/phpmailer.lang-pt_br.php",
		"./engine/classes/mail/language/phpmailer.lang-ar.php",
		"./engine/classes/mail/language/phpmailer.lang-ro.php",
		"./engine/classes/mail/language/phpmailer.lang-ko.php",
		"./engine/classes/mail/language/phpmailer.lang-ja.php",
		"./engine/classes/mail/language/phpmailer.lang-id.php",
		"./engine/classes/mail/language/phpmailer.lang-pt.php",
		"./engine/classes/mail/language/phpmailer.lang-be.php",
		"./engine/classes/mail/language/phpmailer.lang-fo.php",
		"./engine/classes/mail/language/phpmailer.lang-bn.php",
		"./engine/classes/mail/language/phpmailer.lang-fa.php",
		"./engine/classes/mail/language/phpmailer.lang-ba.php",
		"./engine/classes/mail/language/phpmailer.lang-et.php",
		"./engine/classes/mail/language/phpmailer.lang-sl.php",
		"./engine/classes/mail/language/phpmailer.lang-mn.php",
		"./engine/classes/mail/language/phpmailer.lang-el.php",
		"./engine/classes/mail/language/phpmailer.lang-ka.php",
		"./engine/classes/mail/language/phpmailer.lang-lv.php",
		"./engine/classes/mail/language/phpmailer.lang-vi.php",
		"./engine/classes/mail/language/phpmailer.lang-sv.php",
		"./engine/classes/mail/language/phpmailer.lang-uk.php",
		"./engine/classes/mail/language/phpmailer.lang-tr.php",
		"./engine/classes/mail/language/phpmailer.lang-af.php",
		"./engine/classes/mail/language/phpmailer.lang-da.php",
		"./engine/classes/mail/language/phpmailer.lang-sr.php",
		"./engine/classes/mail/language/phpmailer.lang-zh_cn.php",
		"./engine/classes/mail/language/phpmailer.lang-ms.php",
		"./engine/classes/mail/language/phpmailer.lang-cs.php",
		"./engine/classes/mail/language/phpmailer.lang-mg.php",
		"./engine/classes/mail/language/phpmailer.lang-nb.php",
		"./engine/classes/zipextract.class.php",
		"./engine/classes/memcache.class.php",
		"./engine/classes/redis.class.php",
		"./engine/classes/plugins.class.php",
		"./engine/classes/filesystem.class.php",
		"./engine/classes/mobiledetect/MobileDetect.php",
		"./engine/classes/htmlpurifier/HTMLPurifier.standalone.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Lexer/PH5P.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Filter/ExtractStyleBlocks.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Filter/YouTube.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Printer.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Interchange/Directive.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Interchange/Id.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Builder/ConfigSchema.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Builder/Xml.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Interchange.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Exception.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/ValidatorAtom.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/Validator.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/ConfigSchema/InterchangeBuilder.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Language/messages/en.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Language/messages/en-x-testmini.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Language/messages/en-x-test.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Language/classes/en-x-test.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Printer/CSSDefinition.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Printer/HTMLDefinition.php",
		"./engine/classes/htmlpurifier/standalone/HTMLPurifier/Printer/ConfigForm.php",
		"./engine/classes/stopspam.class.php",
		"./engine/classes/seo.class.php",
		"./engine/classes/mail/class.phpmailer.php",
		"./engine/classes/mail/exception.php",
		"./engine/classes/mail/smtp.php",
		"./engine/modules/main.php",
		"./engine/modules/vote.php",
		"./engine/modules/addnews.php",
		"./engine/modules/antibot.php",
		"./engine/modules/banned.php",
		"./engine/modules/calendar.php",
		"./engine/modules/comments.php",
		"./engine/modules/favorites.php",
		"./engine/modules/feedback.php",
		"./engine/modules/profile_innews.php",
		"./engine/modules/functions.php",
		"./engine/modules/lastcomments.php",
		"./engine/modules/findcomments.php",
		"./engine/modules/lostpassword.php",
		"./engine/modules/offline.php",
		"./engine/modules/pm.php",
		"./engine/modules/profile.php",
		"./engine/modules/register.php",
		"./engine/modules/search.php",
		"./engine/modules/show.related.php",
		"./engine/modules/show.custom.php",
		"./engine/modules/show.full.php",
		"./engine/modules/show.short.php",
		"./engine/modules/sitelogin.php",
		"./engine/modules/static.php",
		"./engine/modules/stats.php",
		"./engine/modules/topnews.php",
		"./engine/modules/addcomments.php",
		"./engine/modules/poll.php",
		"./engine/modules/cron.php",
		"./engine/modules/banners.php",
		"./engine/modules/rssinform.php",
		"./engine/modules/deletenews.php",
		"./engine/modules/tagscloud.php",
		"./engine/modules/changemail.php",
		"./engine/modules/links.php",
		"./engine/modules/social.php",
		"./engine/api/api.class.php",
		"./engine/inc/friendlyurl.php",
		"./engine/inc/iptools.php",
		"./engine/classes/mail.class.php",
		"./engine/inc/mass_user_actions.php",
		"./engine/inc/blockip.php",
		"./engine/inc/social.php",
		"./engine/inc/categories.php",
		"./engine/inc/plugins.php",
		"./engine/inc/dboption.php",
		"./engine/inc/dumper.php",
		"./engine/inc/editnews.php",
		"./engine/inc/editusers.php",
		"./engine/inc/editvote.php",
		"./engine/inc/email.php",
		"./engine/inc/files.php",
		"./engine/inc/include/functions.inc.php",
		"./engine/inc/help.php",
		"./engine/inc/main.php",
		"./engine/inc/videoconfig.php",
		"./engine/inc/tagscloud.php",
		"./engine/inc/complaint.php",
		"./engine/inc/links.php",
		"./engine/inc/redirects.php",
		"./engine/inc/timeout.php",
		"./engine/inc/metatags.php",
		"./engine/classes/xfields.class.php",
		"./engine/classes/social.class.php",
		"./engine/classes/thumb.class.php",
		"./engine/classes/comments.class.php",
		"./engine/classes/antivirus.class.php",
		"./engine/classes/uploads/upload.class.php",
		"./engine/inc/massactions.php",
		"./engine/classes/mysql.php",
		"./engine/inc/newsletter.php",
		"./engine/inc/options.php",
		"./engine/classes/parse.class.php",
		"./engine/inc/preview.php",
		"./engine/inc/static.php",
		"./engine/inc/storage.php",
		"./engine/classes/templates.class.php",
		"./engine/inc/templates.php",
		"./engine/inc/userfields.php",
		"./engine/inc/usergroup.php",
		"./engine/inc/wordfilter.php",
		"./engine/inc/xfields.php",
		"./engine/inc/addnews.php",
		"./engine/inc/comments.php",
		"./engine/inc/banners.php",
		"./engine/inc/clean.php",
		"./engine/inc/rss.php",
		"./engine/inc/question.php",
		"./engine/inc/mass_static_actions.php",
		"./engine/inc/lostpassword.php",
		"./engine/inc/twofactor.php",
		"./engine/inc/include/init.php",
		"./engine/classes/rss.class.php",
		"./engine/classes/recaptcha.php",
		"./engine/inc/search.php",
		"./engine/classes/download.class.php",
		"./engine/inc/cmoderation.php",
		"./engine/inc/rssinform.php",
		"./engine/inc/rebuild.php",
		"./engine/inc/logs.php",
		"./engine/classes/google.class.php",
		"./engine/inc/googlemap.php",
		"./engine/inc/check.php",
		"./engine/inc/upgrade.php",
		"./engine/inc/upgrade/19.1.php",
		"./engine/inc/upgrade/19.0.php",
		"./engine/inc/upgrade/18.1.php",
		"./engine/inc/upgrade/18.0.php",
		"./engine/inc/upgrade/17.3.php",
		"./engine/inc/upgrade/17.2.php",
		"./engine/inc/upgrade/17.1.php",
		"./engine/inc/upgrade/17.0.php",
		"./engine/inc/upgrade/16.1.php",
		"./engine/inc/upgrade/16.0.php",
		"./engine/inc/upgrade/15.3.php",
		"./engine/inc/upgrade/15.2.php",
		"./engine/inc/upgrade/15.1.php",
		"./engine/inc/upgrade/15.0.php",
		"./engine/inc/upgrade/14.3.php",
		"./engine/inc/upgrade/14.2.php",
		"./engine/inc/upgrade/14.1.php",
		"./engine/inc/upgrade/14.0.php",
		"./engine/inc/upgrade/13.3.php",
		"./engine/inc/upgrade/13.2.php",
		"./engine/inc/upgrade/13.1.php",
		"./engine/inc/upgrade/13.0.php",
		"./engine/inc/upgrade/12.1.php",
		"./engine/inc/upgrade/10.3.php",
		"./engine/inc/upgrade/10.1.php",
		"./engine/inc/upgrade/10.5.php",
		"./engine/inc/upgrade/11.1.php",
		"./engine/inc/upgrade/10.3.php",
		"./engine/inc/upgrade/7.3.php",
		"./engine/inc/upgrade/11.3.php",
		"./engine/inc/upgrade/9.0.php",
		"./engine/inc/upgrade/7.2.php",
		"./engine/inc/upgrade/12.0.php",
		"./engine/inc/upgrade/11.2.php",
		"./engine/inc/upgrade/7.0.php",
		"./engine/inc/upgrade/10.6.php",
		"./engine/inc/upgrade/9.7.php",
		"./engine/inc/upgrade/10.4.php",
		"./engine/inc/upgrade/9.8.php",
		"./engine/inc/upgrade/10.0.php",
		"./engine/inc/upgrade/8.3.php",
		"./engine/inc/upgrade/9.5.php",
		"./engine/inc/upgrade/7.5.php",
		"./engine/inc/upgrade/8.0.php",
		"./engine/inc/upgrade/10.2.php",
		"./engine/inc/upgrade/11.0.php",
		"./engine/inc/upgrade/8.5.php",
		"./engine/inc/upgrade/9.6.php",
		"./engine/inc/upgrade/8.2.php",
		"./engine/inc/upgrade/9.4.php",
		"./engine/inc/upgrade/9.2.php",
		"./engine/inc/upgrade/9.3.php",
		"./engine/preview.php",
		"./engine/init.php",
		"./engine/engine.php",
		"./engine/print.php",
		"./engine/rss.php",
		"./engine/download.php",
		"./engine/go.php",
		"./index.php",
		"./cron.php",
	);

	function __construct()
	{   global $config;

		if(@file_exists(ENGINE_DIR.'/data/snap.db')) {
  			$filecontents = file(ENGINE_DIR.'/data/snap.db');

		    foreach ($filecontents as $name => $value) {
	    	  $filecontents[$name] = explode("|", trim($value));
	    	    $this->track_files[$filecontents[$name][0]] = $filecontents[$name][1];
		    }
		    
			$this->snap = true;

		}

		$this->good_files[] = "./{$config['admin_path']}";

	}

	function scan_files($dir, $snap = false, $scanroot = false)
	{
		$this->checked_folders[] = $dir . $this->dir_split;

		if ($dh = @opendir($dir)) {
			while (false !== ($file = readdir($dh))) {
				if ($file == '.' or $file == '..' or $file == '.svn' or $file == '.DS_store') {
					continue;
				}

				if (is_dir($dir . $this->dir_split . $file)) {

					if ($dir == ROOT_DIR and !$scanroot) continue;

					$this->scan_files($dir . $this->dir_split . $file, $snap, $scanroot);
				} else {

					if ($this->snap or $snap) $templates = "|tpl|js|lng|htaccess|html";
					elseif (strpos($dir . '/', ROOT_DIR . '/templates/') !== 0) $templates = "|htaccess";
					else $templates = "";

					if (preg_match("#.*\.(php|cgi|pl|perl" . $templates . ")$#i", $file)) {

						$folder = str_replace(ROOT_DIR, ".", $dir);

						if ($folder == "./engine/cache/system/plugins") continue;

						if ($snap) {

							$file_crc = md5_file($dir . $this->dir_split . $file);

							$this->snap_files[] = array(
								'file_path' => $folder . $this->dir_split . $file,
								'file_crc' => $file_crc
							);
						} else {

							if ($this->snap) {

								$contin = false;
								$file_crc = md5_file($dir . $this->dir_split . $file);

								if ($folder == "./engine/cache/system" AND preg_match("#.*\.(js)$#i", $file) AND !preg_match("#.*\.(php)$#i", $file)) $contin = true;

								if ( (!isset($this->track_files[$folder . $this->dir_split . $file]) OR (isset($this->track_files[$folder . $this->dir_split . $file]) AND $this->track_files[$folder . $this->dir_split . $file] != $file_crc) ) AND !in_array($folder . $this->dir_split . $file, $this->cache_files) AND !$contin) {

									$file_date = date("d.m.Y H:i:s", filectime($dir . $this->dir_split . $file));
									$file_size = filesize($dir . $this->dir_split . $file);

									$this->bad_files[] = array(
										'file_path' => $folder . $this->dir_split . $file,
										'file_name' => $file,
										'file_date' => $file_date,
										'type' => 1,
										'file_size' => $file_size
									);
								}
							} else {

								if (!in_array($folder . $this->dir_split . $file, $this->good_files)) {
									$file_date = date("d.m.Y H:i:s", filectime($dir . $this->dir_split . $file));
									$file_size = filesize($dir . $this->dir_split . $file);

									$this->bad_files[] = array(
										'file_path' => $folder . $this->dir_split . $file,
										'file_name' => $file,
										'file_date' => $file_date,
										'type' => 0,
										'file_size' => $file_size
									);
								}
							}
						}
					}
				}
			}
		}
	}
}
