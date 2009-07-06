<?php
/**
 * Plugin Name: Twitter Widget Pro
 * Plugin URI: http://xavisys.com/wordpress-twitter-widget/
 * Description: A widget that properly handles twitter feeds, including @username, #hashtag, and link parsing.  It can even display profile images for the users.  Requires PHP5.
 * Version: 1.4.3
 * Author: Aaron D. Campbell
 * Author URI: http://xavisys.com/
 * Text Domain: twitter-widget-pro
 */

define('TWP_VERSION', '1.4.3');

/*  Copyright 2006  Aaron D. Campbell  (email : wp_plugins@xavisys.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
/**
 * wpTwitterWidget is the class that handles ALL of the plugin functionality.
 * It helps us avoid name collisions
 * http://codex.wordpress.org/Writing_a_Plugin#Avoiding_Function_Name_Collisions
 */

class wpTwitterWidgetException extends Exception {}

class wpTwitterWidget
{
	/**
	 * @var string Stores the plugin file to test against on plugins page
	 */
	private $_pluginBasename;

	public function __construct() {}

	public function admin_menu() {
		add_options_page(__('Twitter Widget Pro', 'twitter-widget-pro'), __('Twitter Widget Pro', 'twitter-widget-pro'), 'manage_options', 'TwitterWidgetPro', array($this, 'options'));
	}

	public function init_locale(){
		$lang_dir = basename(dirname(__FILE__)) . '/languages';
		load_plugin_textdomain('twitter-widget-pro', 'wp-content/plugins/' . $lang_dir, $lang_dir);
	}

	/**
	 * This is used to display the options page for this plugin
	 */
	public function options() {
		//Get our options
		$o = get_option('twitter_widget_pro');
?>
		<div class="wrap">
			<h2><?php _e('Twitter Widget Pro Options', 'twitter-widget-pro') ?></h2>
			<form action="options.php" method="post" id="wp_twitter_widget_pro">
				<?php wp_nonce_field('update-options'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<a title="<?php _e('Click for Help!', 'twitter-widget-pro'); ?>" href="#" onclick="jQuery('#twp_user_agreed_to_send_system_information_help').toggle(); return false;">
								<?php _e('System Information:', 'twitter-widget-pro') ?>
							</a>
						</th>
						<td>
							<input type="hidden" name="twitter_widget_pro[user_agreed_to_send_system_information]" value="false" />
							<label for="twp_user_agreed_to_send_system_information"><input type="checkbox" name="twitter_widget_pro[user_agreed_to_send_system_information]" value="true" id="twp_user_agreed_to_send_system_information"<?php checked('true', $o['user_agreed_to_send_system_information']); ?> /> <?php _e('I agree to send anonymous system information', 'twitter-widget-pro'); ?></label><br />
							<small id="twp_user_agreed_to_send_system_information_help" style="display:none;">
								<?php _e('You can help by sending anonymous system information that will help Xavisys make better decisions about new features.', 'twitter-widget-pro'); ?><br />
								<?php _e('The information will be sent anonymously, but a unique identifier will be sent to prevent duplicate entries from the same installation.', 'twitter-widget-pro'); ?>
							</small>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="Submit" value="<?php _e('Update Options &raquo;', 'twitter-widget-pro'); ?>" />
				</p>
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="page_options" value="twitter_widget_pro" />
			</form>
		</div>
<?php
	}

	/**
	 * Pulls the JSON feed from Twitter and returns an array of objects
	 *
	 * @param array $widgetOptions - settings needed to get feed url, etc
	 * @return array
	 */
	private function _parseFeed($widgetOptions) {
		$feedUrl = $this->_getFeedUrl($widgetOptions);
		$resp = wp_remote_request($feedUrl, array('timeout' => $widgetOptions['fetchTimeOut']));

		if ( !is_wp_error($resp) && $resp['response']['code'] >= 200 && $resp['response']['code'] < 300 ) {
	        if (function_exists('json_decode')) {
	            return json_decode($resp['body']);
	        } else {
				require_once('json_decode.php');
	        	return Zend_Json_Decoder::decode($resp['body']);
			}
		} else {
			// Failed to fetch url;
			if (empty($widgetOptions['errmsg'])) {
				$widgetOptions['errmsg'] = __('Could not connect to Twitter', 'twitter-widget-pro');
			}
			throw new wpTwitterWidgetException($widgetOptions['errmsg']);
		}
	}

	/**
	 * Gets the URL for the desired feed.
	 *
	 * @param array $widgetOptions - settings needed such as username, feet type, etc
	 * @param string[optional] $type - 'rss' or 'json'
	 * @param bool[optional] $count - If true, it adds the count parameter to the URL
	 * @return string - Twitter feed URL
	 */
	private function _getFeedUrl($widgetOptions, $type = 'json', $count = true) {
		if (!in_array($type, array('rss', 'json'))) {
			$type = 'json';
		}
		$count = ($count)? sprintf('?count=%u', $widgetOptions['items']) : '';
		return sprintf('http://twitter.com/statuses/user_timeline/%1$s.%2$s%3$s', $widgetOptions['username'], $type, $count);
	}

	/**
	 * Replace @username with a link to that twitter user
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with @replies linked
	 */
	public function linkTwitterUsers($text) {
		$text = preg_replace('/(^|\s)@(\w*)/i', '$1<a href="http://twitter.com/$2" class="twitter-user">@$2</a>', $text);
		return $text;
	}

	/**
	 * Replace #hashtag with a link to search.twitter.com for that hashtag
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with #hashtags linked
	 */
	public function linkHashtags($text) {
		$text = preg_replace_callback('/(^|\s)(#\w*)/i', array($this, '_hashtagLink'), $text);
		return $text;
	}

	/**
	 * Replace #hashtag with a link to search.twitter.com for that hashtag
	 *
	 * @param array $matches - Tweet text
	 * @return string - Tweet text with #hashtags linked
	 */
	private function _hashtagLink($matches) {
		return "{$matches[1]}<a href='http://search.twitter.com/search?q="
				. urlencode($matches[2])
				. "' class='twitter-hashtag'>{$matches[2]}</a>";
	}

	/**
	 * Turn URLs into links
	 *
	 * @param string $text - Tweet text
	 * @return string - Tweet text with URLs repalced with links
	 */
	public function linkUrls($text) {
		/**
		 * match protocol://address/path/file.extension?some=variable&another=asf%
		 * $1 is a possible space, this keeps us from linking href="[link]" etc
		 * $2 is the whole URL
		 * $3 is protocol://
		 * $4 is the URL without the protocol://
		 * $5 is the URL parameters
		 */
		$text = preg_replace("/(^|\s)(([a-zA-Z]+:\/\/)([a-z][a-z0-9_\..-]*[a-z]{2,6})([a-zA-Z0-9~\/*-?&%]*))/i", "$1<a href=\"$2\">$2</a>", $text);

		/**
		 * match www.something.domain/path/file.extension?some=variable&another=asf%
		 * $1 is a possible space, this keeps us from linking href="[link]" etc
		 * $2 is the whole URL that was matched.  The protocol is missing, so we assume http://
		 * $3 is www.
		 * $4 is the URL matched without the www.
		 * $5 is the URL parameters
		 */
		$text = preg_replace("/(^|\s)(www\.([a-z][a-z0-9_\..-]*[a-z]{2,6})([a-zA-Z0-9~\/*-?&%]*))/i", "$1<a href=\"http://$2\">$2</a>", $text);

		return $text;
	}

	/**
	 * Gets tweets, from cache if possible
	 *
	 * @param array $widgetOptions - options needed to get feeds
	 * @return array - Array of objects
	 */
	private function _getTweets($widgetOptions) {
		$feedHash = sha1($this->_getFeedUrl($widgetOptions));
		$tweets = get_option("wptw-{$feedHash}");
		$cacheAge = get_option("wptw-{$feedHash}-time");
		//If we don't have cache or it's more than 5 minutes old
		if ( empty($tweets) || (time() - $cacheAge) > 300 ) {
			try {
				$tweets = $this->_parseFeed($widgetOptions);
				update_option("wptw-{$feedHash}", $tweets);
				update_option("wptw-{$feedHash}-time", time());
			} catch (wpTwitterWidgetException $e) {
				throw $e;
			}
		}
		return $tweets;
	}

	/**
	 * Displays the Twitter widget, with all tweets in an unordered list.
	 * Things are classed but not styled to allow easy styling.
	 *
	 * @param array $args - Widget Settings
	 * @param array|int $widget_args - Widget Number
	 */
	public function display($args, $widget_args = 1) {
		extract( $args, EXTR_SKIP );
		if ( is_numeric($widget_args) )
			$widget_args = array( 'number' => $widget_args );
		$widget_args = wp_parse_args( $widget_args, array( 'number' => -1 ) );
		extract( $widget_args, EXTR_SKIP );

		$options = get_option('widget_twitter');
		if ( !isset($options[$number]) ) {
			return;
		}

		// Validate our options
		$options[$number]['items'] = (int) $options[$number]['items'];
		if ( $options[$number]['items'] < 1 || 20 < $options[$number]['items'] ) {
			$options[$number]['items'] = 10;
		}
		if (!isset($options[$number]['showts'])) {
			$options[$number]['showts'] = 86400;
		}

		$options[$number]['hiderss'] = (isset($options[$number]['hiderss']) && $options[$number]['hiderss']);
		$options[$number]['avatar'] = (isset($options[$number]['avatar']) && $options[$number]['avatar']);
		$options[$number]['showXavisysLink'] = (!isset($options[$number]['showXavisysLink']) || $options[$number]['showXavisysLink'] != 'false');


		try {
			$tweets = $this->_getTweets($options[$number]);
			$tweets = array_slice($tweets, 0, $options[$number]['items']);
		} catch (wpTwitterWidgetException $e) {
			$tweets = $e;
		}

		echo $before_widget . '<div>';

		// If "hide rss" hasn't been checked, show the linked icon
		if (!$options[$number]['hiderss']) {
			if ( file_exists(dirname(__FILE__) . '/rss.png') ) {
				$icon = str_replace(ABSPATH, get_option('siteurl').'/', dirname(__FILE__)) . '/rss.png';
			} else {
				$icon = get_option('siteurl').'/wp-includes/images/rss.png';
			}
			$feedUrl = $this->_getFeedUrl($options[$number], 'rss', false);
			$before_title .= "<a class='twitterwidget' href='{$feedUrl}' title='" . attribute_escape(__('Syndicate this content', 'twitter-widget-pro')) ."'><img style='background:orange;color:white;border:none;' width='14' height='14' src='{$icon}' alt='RSS' /></a> ";
		}
		$twitterLink = 'http://twitter.com/' . $options[$number]['username'];
		$before_title .= "<a class='twitterwidget' href='{$twitterLink}' title='" . attribute_escape("Twitter: {$options[$number]['username']}") . "'>";
		$after_title = '</a>' . $after_title;
		if (empty($options[$number]['title'])) {
			$options[$number]['title'] = "Twitter: {$options[$number]['username']}";
		}
		echo $before_title . $options[$number]['title'] . $after_title;
		echo '<ul>';
		if (is_a($tweets, 'wpTwitterWidgetException')) {
			echo '<li class="wpTwitterWidgetError">' . $tweets->getMessage() . '</li>';
		} else if (count($tweets) == 0) {
			echo '<li class="wpTwitterWidgetEmpty">' . __('No Tweets Available', 'twitter-widget-pro') . '</li>';
		} else {
			if (!empty($tweets)  && $options[$number]['avatar']) {
				echo '<li>';
				echo $this->_getProfileImage($tweets[0]->user);
				echo '<div class="clear"></div>';
				echo '</li>';
			}
			foreach ($tweets as $tweet) {
				// Set our "ago" string which converts the date to "# ___(s) ago"
				$tweet->ago = $this->_timeSince(strtotime($tweet->created_at), $options[$number]['showts']);
?>
				<li>
					<span class="entry-content"><?php echo apply_filters( 'widget_twitter_content', $tweet->text ); ?></span>
					<span class="entry-meta">
						<span class="time-meta">
							<a href="http://twitter.com/<?php echo $tweet->user->screen_name; ?>/statuses/<?php echo $tweet->id; ?>">
								<?php echo $tweet->ago; ?>
							</a>
						</span>
						<span class="from-meta">
							<?php echo sprintf(__('from %s', 'twitter-widget-pro'), str_replace('&', '&amp;', $tweet->source)); ?>
						</span>
						<?php
						if (!empty($tweet->in_reply_to_screen_name)) {
							$rtLinkText = sprintf( __('in reply to %s', 'twitter-widget-pro'), $tweet->in_reply_to_screen_name );
							echo <<<replyTo
							<span class="in-reply-to-meta">
								<a href="http://twitter.com/{$tweet->in_reply_to_screen_name}/statuses/{$tweet->in_reply_to_status_id}" class="reply-to">
									{$rtLinkText}
								</a>
							</span>
replyTo;
						} ?>

					</span>
				</li>
<?php
			}
		}

		if ($options[$number]['showXavisysLink']) {
?>
				<li class="xavisys-link">
					<span class="xavisys-link-text">
						<?php echo sprintf(__('Powered by <a href="%s" title="Get Twitter Widget for your WordPress site">WordPress Twitter Widget Pro</a>', 'twitter-widget-pro'), 'http://xavisys.com/2008/04/wordpress-twitter-widget/' );?>
					</span>
				</li>
<?php
		}
		echo '</ul></div>' . $after_widget;
	}

	/**
	 * Returns the Twitter user's profile image, linked to that user's profile
	 *
	 * @param object $user - Twitter User
	 * @return string - Linked image (XHTML)
	 */
	private function _getProfileImage($user) {
		return <<<profileImage
	<a title="{$user->name}" href="http://twitter.com/{$user->screen_name}">
		<img alt="{$user->name}" src="{$user->profile_image_url}" />
	</a>
profileImage;
	}

	/**
	 * Returns the user's screen name as a link inside strong tags.
	 *
	 * @param object $user - Twitter user
	 * @return string - Username as link (XHTML)
	 */
	private function _getUserName($user) {
		return <<<profileImage
	<strong>
		<a title="{$user->name}" href="http://twitter.com/{$user->screen_name}">{$user->screen_name}</a>
	</strong>
profileImage;
	}

	/**
	 * Sets up admin forms to manage widgets
	 *
	 * @param array|int $widget_args - Widget Number
	 */
	public function control($widget_args) {
		global $wp_registered_widgets;
		static $updated = false;

		if ( is_numeric($widget_args) )
			$widget_args = array( 'number' => $widget_args );
		$widget_args = wp_parse_args( $widget_args, array( 'number' => -1 ) );
		extract( $widget_args, EXTR_SKIP );

		$options = get_option('widget_twitter');

		if ( !is_array($options) )
			$options = array();

		if ( !$updated && !empty($_POST['sidebar']) ) {
			$sidebar = (string) $_POST['sidebar'];

			$sidebars_widgets = wp_get_sidebars_widgets();
			if ( isset($sidebars_widgets[$sidebar]) )
				$this_sidebar =& $sidebars_widgets[$sidebar];
			else
				$this_sidebar = array();

			foreach ( $this_sidebar as $_widget_id ) {
				if ( array($this,'display') == $wp_registered_widgets[$_widget_id]['callback'] && isset($wp_registered_widgets[$_widget_id]['params'][0]['number']) ) {
					$widget_number = $wp_registered_widgets[$_widget_id]['params'][0]['number'];
					if ( !in_array( "twitter-$widget_number", $_POST['widget-id'] ) ) // the widget has been removed.
						unset($options[$widget_number]);
				}
			}

			foreach ( (array) $_POST['widget-twitter'] as $widget_number => $widget_twitter ) {
				if ( !isset($widget_twitter['username']) && isset($options[$widget_number]) ) // user clicked cancel
					continue;

				$widget_twitter['title'] = stripslashes($widget_twitter['title']);
				$widget_twitter['errmsg'] = stripslashes($widget_twitter['errmsg']);
				if ( !current_user_can('unfiltered_html') ) {
					$widget_twitter['title'] = strip_tags($widget_twitter['title']);
					$widget_twitter['errmsg'] = strip_tags($widget_twitter['errmsg']);
				}
				$options[$widget_number] = $widget_twitter;
			}

			update_option('widget_twitter', $options);
			$updated = true;
		}

		if ( -1 != $number ) {
			$options[$number]['number'] = $number;
			$options[$number]['title'] = attribute_escape($options[$number]['title']);
			$options[$number]['errmsg'] = attribute_escape($options[$number]['errmsg']);
			$options[$number]['fetchTimeOut'] = attribute_escape($options[$number]['fetchTimeOut']);
			$options[$number]['username'] = attribute_escape($options[$number]['username']);
			$options[$number]['hiderss'] = (bool) $options[$number]['hiderss'];
			$options[$number]['avatar'] = (bool) $options[$number]['avatar'];
			$options[$number]['showXavisysLink'] = (!isset($options[$number]['showXavisysLink']) || $options[$number]['showXavisysLink'] != 'false');
		}
		$this->_showForm($options[$number]);
	}

	/**
	 * Registers widget in such a way as to allow multiple instances of it
	 *
	 * @see wp-includes/widgets.php
	 */
	public function register() {
		if ( !$options = get_option('widget_twitter') )
			$options = array();
		$widget_ops = array('classname' => 'widget_twitter', 'description' => __('Follow a Twitter Feed', 'twitter-widget-pro'));
		$control_ops = array('width' => 400, 'height' => 350, 'id_base' => 'twitter');
		$name = __('Twitter Feed', 'twitter-widget-pro');

		$id = false;
		foreach ( array_keys($options) as $o ) {
			// Old widgets can have null values for some reason
			if ( !isset($options[$o]['title']) || !isset($options[$o]['username']) )
				continue;
			$id = "twitter-$o"; // Never never never translate an id
			wp_register_sidebar_widget($id, $name, array($this,'display'), $widget_ops, array( 'number' => $o ));
			wp_register_widget_control($id, $name, array($this,'control'), $control_ops, array( 'number' => $o ));
		}

		// If there are none, we register the widget's existance with a generic template
		if ( !$id ) {
			wp_register_sidebar_widget( 'twitter-1', $name, array($this,'display'), $widget_ops, array( 'number' => -1 ) );
			wp_register_widget_control( 'twitter-1', $name, array($this,'control'), $control_ops, array( 'number' => -1 ) );
		}
	}

	/**
	 * Displays the actualy for that populates the widget options box in the
	 * admin section
	 *
	 * @param array $args - Current widget settings and widget number, gets combind with defaults
	 */
	private function _showForm($args) {

		$defaultArgs = array(	'title'				=> '',
								'errmsg'			=> '',
								'fetchTimeOut'		=> '2',
								'username'			=> '',
								'hiderss'			=> false,
								'avatar'			=> false,
								'showXavisysLink'	=> true,
								'items'				=> 10,
								'showts'			=> 60 * 60 * 24,
								'number'			=> '%i%' );

		$args = wp_parse_args( $args, $defaultArgs );
		extract( $args );
?>
			<p>
				<label for="twitter-username-<?php echo $number; ?>"><?php _e('Twitter username:', 'twitter-widget-pro'); ?></label>
				<input class="widefat" id="twitter-username-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][username]" type="text" value="<?php echo $username; ?>" />
			</p>
			<p>
				<label for="twitter-title-<?php echo $number; ?>"><?php _e('Give the feed a title (optional):', 'twitter-widget-pro'); ?></label>
				<input class="widefat" id="twitter-title-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][title]" type="text" value="<?php echo $title; ?>" />
			</p>
			<p>
				<label for="twitter-items-<?php echo $number; ?>"><?php _e('How many items would you like to display?', 'twitter-widget-pro'); ?></label>
				<select id="twitter-items-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][items]">
					<?php
						for ( $i = 1; $i <= 20; ++$i ) {
							echo "<option value='$i' ", selected($items, $i), ">$i</option>";
						}
					?>
				</select>
			</p>
			<p>
				<label for="twitter-errmsg-<?php echo $number; ?>"><?php _e('What to display when Twitter is down (optional):', 'twitter-widget-pro'); ?></label>
				<input class="widefat" id="twitter-errmsg-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][errmsg]" type="text" value="<?php echo $errmsg; ?>" />
			</p>
			<p>
				<label for="twitter-fetchTimeOut-<?php echo $number; ?>"><?php _e('Number of seconds to wait for a response from Twitter (default 2):', 'twitter-widget-pro'); ?></label>
				<input class="widefat" id="twitter-fetchTimeOut-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][fetchTimeOut]" type="text" value="<?php echo $fetchTimeOut; ?>" />
			</p>
			<p>
				<label for="twitter-showts-<?php echo $number; ?>"><?php _e('Show date/time of Tweet (rather than 2 ____ ago):', 'twitter-widget-pro'); ?></label>
				<select id="twitter-showts-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][showts]">
					<option value="0" <?php echo selected($showts, '0'); ?>><?php _e('Always', 'twitter-widget-pro');?></a>
					<option value="3600" <?php echo selected($showts, '3600'); ?>><?php _e('If over an hour old', 'twitter-widget-pro');?></a>
					<option value="86400" <?php echo selected($showts, '86400'); ?>><?php _e('If over a day old', 'twitter-widget-pro');?></a>
					<option value="604800" <?php echo selected($showts, '604800'); ?>><?php _e('If over a week old', 'twitter-widget-pro');?></a>
					<option value="2592000" <?php echo selected($showts, '2592000'); ?>><?php _e('If over a month old', 'twitter-widget-pro');?></a>
					<option value="31536000" <?php echo selected($showts, '31536000'); ?>><?php _e('If over a year old', 'twitter-widget-pro');?></a>
					<option value="-1" <?php echo selected($showts, '-1'); ?>><?php _e('Never', 'twitter-widget-pro');?></a>
				</select>
			</p>
			<p>
				<label for="twitter-hiderss-<?php echo $number; ?>"><input class="checkbox" type="checkbox" id="twitter-hiderss-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][hiderss]"<?php checked($hiderss, true); ?> /> <?php _e('Hide RSS Icon and Link', 'twitter-widget-pro'); ?></label>
			</p>
			<p>
				<label for="twitter-avatar-<?php echo $number; ?>"><input class="checkbox" type="checkbox" id="twitter-avatar-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][avatar]"<?php checked($avatar, true); ?> /> <?php _e('Show Profile Image', 'twitter-widget-pro'); ?></label>
			</p>
			<p>
				<input type="hidden" name="widget-twitter[<?php echo $number; ?>][showXavisysLink]" value="false" />
				<label for="twitter-showXavisysLink-<?php echo $number; ?>"><input class="checkbox" type="checkbox" value="true" id="twitter-showXavisysLink-<?php echo $number; ?>" name="widget-twitter[<?php echo $number; ?>][showXavisysLink]"<?php checked($showXavisysLink, true); ?> /> <?php _e('Show Link to Twitter Widget Pro', 'twitter-widget-pro'); ?></label>
			</p>
<?php
	}

	/**
	 * Twitter displays all tweets that are less than 24 with something like
	 * "about 4 hours ago" and ones older than 24 hours with a time and date.
	 * This function allows us to simulate that functionality, but lets us
	 * choose where the dividing line is.
	 *
	 * @param int $startTimestamp - The timestamp used to calculate time passed
	 * @param int $max - Max number of seconds to conver to "ago" messages.  0 for all, -1 for none
	 * @return string
	 */
	private function _timeSince($startTimestamp, $max) {
	    // array of time period chunks
	    $chunks = array(
			'year'		=> 60 * 60 * 24 * 365,	// 31,536,000 seconds
			'month'		=> 60 * 60 * 24 * 7,	// 2,592,000 seconds
			'week'		=> 60 * 60 * 24 * 7,	// 604,800 seconds
			'day'		=> 60 * 60 * 24,		// 86,400 seconds
			'hour'		=> 60 * 60,				// 3600 seconds
			'minute'	=> 60,					// 60 seconds
			'second'	=> 1					// 1 second
	    );

	    $since = time() - $startTimestamp;

	    if ($max != '-1' && $since >= $max) {
			return date('h:i:s A F d, Y', $startTimestamp);
	    }

		foreach ( $chunks as $key => $seconds ) {
	        // finding the biggest chunk (if the chunk fits, break)
	        if (($count = floor($since / $seconds)) != 0) {
	            break;
	        }
		}

	    $messages = array(
			'year'		=> _n('about %s year ago', 'about %s years ago', $count, 'twitter-widget-pro'),
			'month'		=> _n('about %s month ago', 'about %s months ago', $count, 'twitter-widget-pro'),
			'week'		=> _n('about %s week ago', 'about %s weeks ago', $count, 'twitter-widget-pro'),
			'day'		=> _n('about %s day ago', 'about %s days ago', $count, 'twitter-widget-pro'),
			'hour'		=> _n('about %s hour ago', 'about %s hours ago', $count, 'twitter-widget-pro'),
			'minute'	=> _n('about %s minute ago', 'about %s minutes ago', $count, 'twitter-widget-pro'),
			'second'	=> _n('about %s second ago', 'about %s seconds ago', $count, 'twitter-widget-pro'),
	    );

	    return sprintf($messages[$key], $count);
	}

	public function activatePlugin() {
		// If the wga-id has not been generated, generate one and store it.
		$id = $this->get_id();
		$o = get_option('twitter_widget_pro');
		if (!isset($o['user_agreed_to_send_system_information'])) {
			$o['user_agreed_to_send_system_information'] = 'true';
			update_option('twitter_widget_pro', $o);
		}
	}

	public function get_id() {
		$id = get_option('twitter_widget_pro-id');
		if ($id === false) {
			$id = sha1( get_bloginfo('url') . mt_rand() );
			update_option('twitter_widget_pro-id', $id);
		}
		return $id;
	}
	/**
	 * if user agrees to send system information and the last sent info is
	 * outdated then send the stats
	 */
	public function sendSysInfo() {
		$o = get_option('twitter_widget_pro');
		if ($o['user_agreed_to_send_system_information'] == 'true') {
			$lastSent = get_option('twp-sysinfo');
            $sysinfo = $this->_get_sysinfo();
			if (serialize($lastSent) != serialize($sysinfo)) {
				$params = array(
					'method'	=> 'POST',
					'blocking'	=> false,
					'body'		=> $sysinfo,
				);
				$resp = wp_remote_request( 'http://xavisys.com/plugin-info.php', $params );
				update_option( 'twp-sysinfo', $sysinfo );
			}
		}
	}

	private function _get_sysinfo()
	{
		global $wpdb;
		$s = array();
		$s['plugin'] = 'Twitter Widget Pro';
		$s['id'] = $this->get_id();
		$s['version'] = TWP_VERSION;

		$s['php_version'] = phpversion();
		$s['mysql_version'] = @mysql_get_server_info($wpdb->dbh);
		$s['server_software'] = $_SERVER["SERVER_SOFTWARE"];
		$s['memory_limit'] = ini_get('memory_limit');

		return $s;
	}

	public function addSettingLink( $links, $file ){
		if ( empty($this->_pluginBasename) ) {
			$this->_pluginBasename = plugin_basename(__FILE__);
		}

		if ( $file == $this->_pluginBasename ) {
			// Add settings link to our plugin
			$link = '<a href="options-general.php?page=TwitterWidgetPro">' . __('Settings', 'twitter-widget-pro') . '</a>';
			array_unshift( $links, $link );
		}
		return $links;
	}
}
// Instantiate our class
$wpTwitterWidget = new wpTwitterWidget();

/**
 * Add filters and actions
 */
add_action( 'admin_menu', array($wpTwitterWidget,'admin_menu') );
add_filter( 'init', array( $wpTwitterWidget, 'init_locale') );
add_filter( 'admin_init', array( $wpTwitterWidget, 'sendSysInfo') );
add_action( 'widgets_init', array($wpTwitterWidget, 'register') );
add_filter( 'widget_twitter_content', array($wpTwitterWidget, 'linkTwitterUsers') );
add_filter( 'widget_twitter_content', array($wpTwitterWidget, 'linkUrls') );
add_filter( 'widget_twitter_content', array($wpTwitterWidget, 'linkHashtags') );
add_filter( 'widget_twitter_content', 'convert_chars' );
add_action( 'activate_twitter-widget-pro/wp-twitter-widget.php', array($wpTwitterWidget, 'activatePlugin') );
add_filter( 'plugin_action_links', array($wpTwitterWidget, 'addSettingLink'), 10, 2 );
