<?php
/*
Plugin Name: Twitter Tools - mact.me URLs 
Plugin URI: https://github.com/danmactough/twitter-tools-mactme
Description: Use mact.me for URL shortening with Twitter Tools. This plugin relies on Twitter Tools, configure it on the Twitter Tools settings page.
Version: 2.4
Author: Dan MacTough
Author URI: http://yabfog.com/blog/
*/

// Based on Crowd Favorite's Bit.ly URLs tool.

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

load_plugin_textdomain('twitter-tools-mactme');

define('AKTT_MACTME_API_SHORTEN_URL', 'http://short.mact.me/shorten');
// define('AKTT_BITLY_API_SHORTEN_URL_JMP', 'http://api.j.mp/shorten');
// define('AKTT_BITLY_API_VERSION', '2.0.1');

function aktt_mactme_shorten_url($url) {
	$parts = parse_url($url);
	if (!in_array($parts['host'], array('mact.me'))) {
		$snoop = get_snoopy();
		$api = AKTT_MACTME_API_SHORTEN_URL.'?url='.urlencode($url);
		$key = get_option('aktt_mactme_api_key');
		if (!empty($key)) {
			$api .= '&apikey='.urlencode($key);
		}
		$snoop->agent = 'Twitter Tools http://alexking.org/projects/wordpress';
		$snoop->fetch($api);
		$result = json_decode($snoop->results);
		if (!empty($result->results->{$url}->shortUrl)) {
			$url = $result->results->{$url}->shortUrl;
		}
	}
	return $url;
}
add_filter('tweet_blog_post_url', 'aktt_mactme_shorten_url');

function aktt_mactme_shorten_tweet($tweet) {
	if (strpos($tweet->tw_text, 'http') !== false) {
		preg_match_all('$\b(https?|ftp|file)://[-A-Z0-9+&@#/%?=~_|!:,.;]*[-A-Z0-9+&@#/%=~_|]$i', $test, $urls);
		if (isset($urls[0]) && count($urls[0])) {
			foreach ($urls[0] as $url) {
// borrowed from WordPress's make_clickable code
				if ( in_array(substr($url, -1), array('.', ',', ';', ':', ')')) === true ) {
					$url = substr($url, 0, strlen($url)-1);
				}
				$tweet->tw_text = str_replace($url, aktt_mactme_shorten_url($url), $tweet->tw_text);
			}
		}
	}
	return $tweet;
}
add_filter('aktt_do_tweet', 'aktt_mactme_shorten_tweet');

function aktt_mactme_request_handler() {
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'aktt_mactme_update_settings':
				if (!wp_verify_nonce($_POST['_wpnonce'], 'aktt_mactme_save_settings')) {
					wp_die('Oops, please try again.');
				}
				aktt_mactme_save_settings();
				wp_redirect(admin_url('options-general.php?page=twitter-tools.php&updated=true'));
				die();
				break;
		}
	}
}
add_action('init', 'aktt_mactme_request_handler');

$aktt_mactme_settings = array(
	'aktt_mactme_api_key' => array(
		'type' => 'string',
		'label' => __('Mact.me API key', 'twitter-tools-mactme'),
		'default' => '',
		'help' => '',
	),
);

function aktt_mactme_setting($option) {
	$value = get_option($option);
	if (empty($value)) {
		global $aktt_mactme_settings;
		$value = $aktt_mactme_settings[$option]['default'];
	}
	return $value;
}

if (!function_exists('cf_settings_field')) {
	function cf_settings_field($key, $config) {
		$option = get_option($key);
		if (empty($option) && !empty($config['default'])) {
			$option = $config['default'];
		}
		$label = '<label for="'.$key.'">'.$config['label'].'</label>';
		$help = '<span class="help">'.$config['help'].'</span>';
		switch ($config['type']) {
			case 'select':
				$output = $label.'<select name="'.$key.'" id="'.$key.'">';
				foreach ($config['options'] as $val => $display) {
					$option == $val ? $sel = ' selected="selected"' : $sel = '';
					$output .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars($display).'</option>';
				}
				$output .= '</select>'.$help;
				break;
			case 'textarea':
				$output = $label.'<textarea name="'.$key.'" id="'.$key.'">'.htmlspecialchars($option).'</textarea>'.$help;
				break;
			case 'string':
			case 'int':
			default:
				$output = $label.'<input name="'.$key.'" id="'.$key.'" value="'.htmlspecialchars($option).'" />'.$help;
				break;
		}
		return '<div class="option">'.$output.'<div class="clear"></div></div>';
	}
}

function aktt_mactme_settings_form() {
	global $aktt_mactme_settings;

	print('
<div class="wrap">
	<h2>'.__('Mact.me for Twitter Tools', 'twitter-tools-mactme').'</h2>
	<form id="aktt_mactme_settings_form" class="aktt" name="aktt_mactme_settings_form" action="'.admin_url('options-general.php').'" method="post">
		<input type="hidden" name="cf_action" value="aktt_mactme_update_settings" />
		<fieldset class="options">
	');
	foreach ($aktt_mactme_settings as $key => $config) {
		echo cf_settings_field($key, $config);
	}
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" class="button-primary" value="'.__('Save Settings', 'twitter-tools-mactme').'" />
		</p>
		'.wp_nonce_field('aktt_mactme_save_settings', '_wpnonce', true, false).wp_referer_field(false).'
	</form>
</div>
	');
}
add_action('aktt_options_form', 'aktt_mactme_settings_form');

function aktt_mactme_save_settings() {
	if (!current_user_can('manage_options')) {
		return;
	}
	global $aktt_mactme_settings;
	foreach ($aktt_mactme_settings as $key => $option) {
		$value = '';
		switch ($option['type']) {
			case 'int':
				$value = intval($_POST[$key]);
				break;
			case 'select':
				$test = stripslashes($_POST[$key]);
				if (isset($option['options'][$test])) {
					$value = $test;
				}
				break;
			case 'string':
			case 'textarea':
			default:
				$value = stripslashes($_POST[$key]);
				break;
		}
		$value = trim($value);
		update_option($key, $value);
	}
}


if (!function_exists('get_snoopy')) {
	function get_snoopy() {
		include_once(ABSPATH.'/wp-includes/class-snoopy.php');
		return new Snoopy;
	}
}

?>