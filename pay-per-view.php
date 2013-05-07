<?php
/*
Plugin Name: Pay Per View
Description: Allows protecting posts/pages until visitor pays a nominal price or subscribes to the website.
Plugin URI: http://premium.wpmudev.org/project/pay-per-view
Version: 1.4.1.2
Author: Hakan Evin (Incsub), Arnold Bailey (Incsub)
Author URI: http://premium.wpmudev.org/
TextDomain: ppw
Domain Path: /languages/
WDP ID: 261
*/

/*
Copyright 2007-2012 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

include_once 'ppw-uninstall.php';
register_uninstall_hook(  __FILE__ , "ppw_uninstall" );
register_activation_hook( __FILE__, array('PayPerView', 'install') );

if ( !class_exists( 'PayPerView' ) ) {

class PayPerView {

	var $version="1.4.1.2";

	/**
     * Constructor
     */
	function PayPerView() {
		$this->__construct();
	}
	function __construct() {
		// Plugin locations
		$this->plugin_name = "pay-per-view";
		$this->plugin_dir = plugin_dir_path(__FILE__);
		$this->plugin_url = plugin_dir_url(__FILE__);
		$this->page = 'settings_page_' . $this->plugin_name;

		$this->time_format			= get_option('time_format');
		$this->date_format			= get_option('date_format');
		$this->datetime_format		= $this->date_format . " " . $this->time_format;

		// We will need sessions
		if ( !session_id() )
			@session_start();

		// Read all options at once
		$this->options = get_option( 'ppw_options' );

		add_action( 'template_redirect', array(&$this, 'cachable'), 1 );	// Check if page can be cached
		add_action( 'plugins_loaded', array(&$this, 'localization') );		// Localize the plugin
		add_action( 'init', array( &$this, 'init' ) ); 						// Initial stuff
		add_action( 'init', array( &$this, 'initiate' ) ); 					// Initiate Paypal forms
		add_action( 'save_post', array( &$this, 'add_postmeta' ) ); 		// Calls post meta addition function on each save
		add_filter( 'the_content', array( &$this, 'content' ), 8 ); 		// Manipulate the content.
		add_filter( 'the_content', array($this, 'clear'), 130 );			// Clear if a shortcode is left
		add_action( 'wp_ajax_nopriv_ppw_paypal_ipn', array(&$this, 'handle_paypal_return')); // Send Paypal to IPN function
		add_action( 'wp_head', array(&$this, 'wp_head') ); 					//Print admin ajax on head

		// Admin side actions
		add_action( 'admin_menu', array( &$this, 'admin_init' ) ); 			// Creates admin settings window
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) ); 	// Warns admin
		add_action( 'add_meta_boxes', array( &$this, 'add_custom_box' ) ); 	// Add meta box to posts
		add_filter( 'plugin_row_meta', array(&$this,'set_plugin_meta'), 10, 2 );// Add settings link on plugin page
		add_action( 'admin_print_scripts', array(&$this,'admin_scripts'));
		add_action( 'admin_print_styles', array(&$this, 'admin_css') );

		// tinyMCE stuff
		add_action( 'wp_ajax_ppwTinymceOptions', array(&$this, 'tinymce_options') );
		add_action( 'admin_init', array(&$this, 'load_tinymce') );

		// Add/edit expiry date to user field
		add_action( 'show_user_profile', array(&$this, 'edit_profile') );
		add_action( 'edit_user_profile', array(&$this, 'edit_profile') );
		add_action( 'personal_options_update', array(&$this, 'save_profile') );
		add_action( 'edit_user_profile_update', array(&$this, 'save_profile') );

		// API login after the options have been initialized
		if (@$this->options['accept_api_logins']) {
			add_action('wp_ajax_nopriv_ppw_facebook_login', array($this, 'handle_facebook_login'));
			add_action('wp_ajax_nopriv_ppw_get_twitter_auth_url', array($this, 'handle_get_twitter_auth_url'));
			add_action('wp_ajax_nopriv_ppw_twitter_login', array($this, 'handle_twitter_login'));
			add_action('wp_ajax_nopriv_ppw_get_google_auth_url', array($this, 'handle_get_google_auth_url'));
			add_action('wp_ajax_nopriv_ppw_google_login', array($this, 'handle_google_login'));
			add_action('wp_ajax_nopriv_ppw_ajax_login', array($this, 'ajax_login'));

			// Google login stuff. New in V1.3
			if (!class_exists('LightOpenID'))
				include_once  $this->plugin_dir . '/includes/lightopenid/openid.php';
			$this->openid = new LightOpenID;

			$this->openid->identity = 'https://www.google.com/accounts/o8/id';
			$this->openid->required = array('namePerson/first', 'namePerson/last', 'namePerson/friendly', 'contact/email');
			if (!empty($_REQUEST['openid_ns'])) {
			$cache = $this->openid->getAttributes();
				if (isset($cache['namePerson/first']) || isset($cache['namePerson/last']) || isset($cache['contact/email'])) {
					$_SESSION['ppw_google_user_cache'] = $cache;
				}
			}
			if ( isset( $_SESSION['ppw_google_user_cache'] ) )
				$this->_google_user_cache = $_SESSION['ppw_google_user_cache'];
			else
				$this->_google_user_cache = '';
		}

		// Show DB results
		global $wpdb;
		$this->db = &$wpdb;
		// Our DB table name
		$this->table = $wpdb->prefix . "pay_per_view";
		// Clear errors at start
		$this->error = "";
		// By default assume that pages are cachable (Cache plugins are allowed)
		$this->is_cachable = true;
	}


	/**
	* Add Settings link to the plugin page
	* @ http://wpengineer.com/1295/meta-links-for-wordpress-plugins/
	*/
	function set_plugin_meta($links, $file) {
		// create link
		$plugin = plugin_basename(__FILE__);
		if ($file == $plugin) {
			return array_merge(
				$links,
				array( sprintf( '<a href="admin.php?page=%s">%s</a>', $this->plugin_name, __('Settings') ) )
			);
		}
		return $links;
	}

	/**
	 * Load css and javascript
	 * As of V1.3 this is called from "cachable" method, i.e. when it is required
	 */
	function load_scripts_styles() {
		// Prevent caching for this page
		if ( !defined( 'DONOTCACHEPAGE' ) )
			define( 'DONOTCACHEPAGE', true );

		if ( !current_theme_supports( 'pay_per_view_style' ) ) {
			$uploads = wp_upload_dir();
			if ( !$uploads['error'] && file_exists( $uploads['basedir'] . "/". $this->plugin_name .".css" ) )
				wp_enqueue_style( $this->plugin_name, $uploads['baseurl']. "/". $this->plugin_name .".css", array(), $this->version );
			else if ( file_exists( $this->plugin_dir. "/css/front.css" ) )
				wp_enqueue_style( $this->plugin_name, $this->plugin_url. "/css/front.css", array(), $this->version );
		}

		// Load the rest only if API use is selected
		if (!$this->options['accept_api_logins'])
			return false;
		wp_register_script('ppw_api_js', $this->plugin_url . '/js/ppw-api.js', array('jquery'), $this->version );
		wp_enqueue_script('ppw_api_js');
		wp_localize_script('ppw_api_js', 'l10nPpwApi', array(
			'facebook' => __('Login with Facebook', 'ppw'),
			'twitter' => __('Login with Twitter', 'ppw'),
			'google' => __('Login with Google', 'ppw'),
			'wordpress' => __('Login with WordPress', 'ppw'),
			'submit' => __('Submit', 'ppw'),
			'cancel' => __('Cancel', 'ppw'),
			'please_wait' => __('Please, wait...', 'ppw'),
		));
		if (!$this->options['facebook-no_init']) {
			add_action('wp_footer', create_function('', "echo '" .
			sprintf(
				'<div id="fb-root"></div><script type="text/javascript">
				window.fbAsyncInit = function() {
					FB.init({
					  appId: "%s",
					  status: true,
					  cookie: true,
					  xfbml: true
					});
				};
				// Load the FB SDK Asynchronously
				(function(d){
					var js, id = "facebook-jssdk"; if (d.getElementById(id)) {return;}
					js = d.createElement("script"); js.id = id; js.async = true;
					js.src = "//connect.facebook.net/en_US/all.js";
					d.getElementsByTagName("head")[0].appendChild(js);
				}(document));
				</script>',
				$this->options['facebook-app_id']
			) .
			"';"));
		}
    }

	/**
	 * Print ajax url
	 * @since 1.4.0
	 */
	function wp_head( ) {
		printf(
			'<script type="text/javascript">var _ppw_data={"ajax_url": "%s", "root_url": "%s"};</script>',
			admin_url('admin-ajax.php'), plugins_url('pay-per-view/images/')
		);
	}

	/**
	 * Login from front end
	 */
	function ajax_login( ) {

		header("Content-type: application/json");
		$user = wp_signon( );

		if ( !is_wp_error($user) ) {
			$reveal = 0;
			if ( get_user_meta( $user->ID, "ppw_subscribe", true) != '' OR $this->is_authorised() )
				$reveal = 1;

			die(json_encode(array(
				"status" => 1,
				"user_id"=>$user->ID,
				"reveal"=>$reveal
			)));
		}
		die(json_encode(array(
				"status" => 0,
				"error" => $user->get_error_message()
			)));
	}

	/**
	 * Handles Facebook user login and creation
	 * Modified from Events and Bookings by S H Mohanjith
	 */
	function handle_facebook_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);
		$fb_uid = @$_POST['user_id'];
		$token = @$_POST['token'];
		if (!$token) die(json_encode($resp));

		$request = new WP_Http;
		$result = $request->request(
			'https://graph.facebook.com/me?oauth_token=' . $token,
			array('sslverify' => false) // SSL certificate issue workaround
		);
		if (200 != $result['response']['code']) die(json_encode($resp)); // Couldn't fetch info

		$data = json_decode($result['body']);
		if (!$data->email) die(json_encode($resp)); // No email, can't go further

		$email = is_email($data->email);
		if (!$email) die(json_encode($resp)); // Wrong email

		$wp_user = get_user_by('email', $email);

		if (!$wp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$username = @$data->name
				? preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->name))
				: preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->first_name)) . '_' . preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->last_name))
			;

			$wp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wp_user)) die(json_encode($resp)); // Failure creating user
		} else {
			$wp_user = $wp_user->ID;
		}

		$user = get_userdata($wp_user);

		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Facebook, yay
		do_action('wp_login', $user->user_login);

		// Check if user has already subscribed or authorized. Does not include Admin!!
		$reveal = 0;
		if ( get_user_meta( $user->ID, "ppw_subscribe", true) != '' OR $this->is_authorised() )
			$reveal = 1;

		die(json_encode(array(
			"status" => 1,
			"user_id"=>$user->ID,
			"reveal"=>$reveal
		)));
	}

	/**
	 * Spawn a TwitterOAuth object.
	 */
	private function _get_twitter_object ($token=null, $secret=null) {
		// Make sure options are loaded and fresh
		if ( !$this->options['twitter-app_id'] )
			$this->options = get_option( 'ppw_options' );
		if (!class_exists('TwitterOAuth'))
			include WP_PLUGIN_DIR . '/pay-per-view/includes/twitteroauth/twitteroauth.php';
		$twitter = new TwitterOAuth(
			$this->options['twitter-app_id'],
			$this->options['twitter-app_secret'],
			$token, $secret
		);
		return $twitter;
	}

	/**
	 * Get OAuth request URL and token.
	 */
	function handle_get_twitter_auth_url () {
		header("Content-type: application/json");
		$twitter = $this->_get_twitter_object();
		$request_token = $twitter->getRequestToken($_POST['url']);
		echo json_encode(array(
			'url' => $twitter->getAuthorizeURL($request_token['oauth_token']),
			'secret' => $request_token['oauth_token_secret']
		));
		die;
	}

	/**
	 * Login or create a new user using whatever data we get from Twitter.
	 */
	function handle_twitter_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);
		$secret = @$_POST['secret'];
		$data_str = @$_POST['data'];
		$data_str = ('?' == substr($data_str, 0, 1)) ? substr($data_str, 1) : $data_str;
		$data = array();
		parse_str($data_str, $data);
		if (!$data) die(json_encode($resp));

		$twitter = $this->_get_twitter_object($data['oauth_token'], $secret);
		$access = $twitter->getAccessToken($data['oauth_verifier']);

		$twitter = $this->_get_twitter_object($access['oauth_token'], $access['oauth_token_secret']);
		$tw_user = $twitter->get('account/verify_credentials');

		// Have user, now register him/her
		$domain = preg_replace('/www\./', '', parse_url(site_url(), PHP_URL_HOST));
		$username = preg_replace('/[^_0-9a-z]/i', '_', strtolower($tw_user->name));
		$email = $username . '@twitter.' . $domain; //STUB email
		$wp_user = get_user_by('email', $email);

		if (!$wp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$count = 0;
			while (username_exists($username)) {
				$username .= rand(0,9);
				if (++$count > 10) break;
			}

			$wp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wp_user)) die(json_encode($resp)); // Failure creating user
		} else {
			$wp_user = $wp_user->ID;
		}

		$user = get_userdata($wp_user);
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Twitter, yay
		do_action('wp_login', $user->user_login);

		// Check if user has already subscribed
		$reveal = 0;
		if ( get_user_meta( $user->ID, "ppw_subscribe", true) != '' OR $this->is_authorised() )
			$reveal = 1;

		die(json_encode(array(
			"status" => 1,
			"user_id"=>$user->ID,
			"reveal"=>$reveal
		)));
	}
	/**
	 * Get OAuth request URL and token.
	 */
	function handle_get_google_auth_url () {
		header("Content-type: application/json");

		$this->openid->returnUrl = $_POST['url'];

		echo json_encode(array(
			'url' => $this->openid->authUrl()
		));
		exit();
	}

	/**
	 * Login or create a new user using whatever data we get from Google.
	 */
	function handle_google_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);

		$cache = $this->openid->getAttributes();

		if (isset($cache['namePerson/first']) || isset($cache['namePerson/last']) || isset($cache['namePerson/friendly']) || isset($cache['contact/email'])) {
			$this->_google_user_cache = $cache;
		}

		// Have user, now register him/her
		if ( isset( $this->_google_user_cache['namePerson/friendly'] ) )
			$username = $this->_google_user_cache['namePerson/friendly'];
		else
			$username = $this->_google_user_cache['namePerson/first'];
		$email = $this->_google_user_cache['contact/email'];
		$wordp_user = get_user_by('email', $email);

		if (!$wordp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$count = 0;
			while (username_exists($username)) {
				$username .= rand(0,9);
				if (++$count > 10) break;
			}

			$wordp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wordp_user))
				die(json_encode($resp)); // Failure creating user
			else {
				update_user_meta($wordp_user, 'first_name', $this->_google_user_cache['namePerson/first']);
				update_user_meta($wordp_user, 'last_name', $this->_google_user_cache['namePerson/last']);
			}
		}
		else {
			$wordp_user = $wordp_user->ID;
		}

		$user = get_userdata($wordp_user);
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Google, yay
		do_action('wp_login', $user->user_login);

	// Check if user has already subscribed
		$reveal = 0;
		if ( get_user_meta( $user->ID, "ppw_subscribe", true) != '' OR $this->is_authorised() )
			$reveal = 1;

		die(json_encode(array(
			"status" => 1,
			"user_id"=>$user->ID,
			"reveal"=>$reveal
		)));
	}

	/**
	 * Saves expiry date field on user profile
	 */
	function save_profile( $user_id ) {

		if ( !current_user_can('administrator') )
			return;

		if ( isset( $_POST["ppw_expiry"] ) )
			update_user_meta( $user_id, 'ppw_subscribe', trim( $_POST['ppw_expiry'] ) );
		if ( isset( $_POST["ppw_days"] ) )
			update_user_meta( $user_id, 'ppw_days', trim( $_POST['ppw_days'] ) );
	}

	/**
	 * Displays expiry date on the user profile
	 */
	function edit_profile( $current_user ) {
	?>
		<h3><?php _e("Pay Per View Subscription", "ppw"); ?></h3>

		<table class="form-table">
		<tr>
		<th><label for="address"><?php _e("Expires at"); ?></label></th>
		<td>
		<?php
			$expiry = get_user_meta( $current_user->ID, 'ppw_subscribe', true );
			$days = get_user_meta( $current_user->ID, 'ppw_days', true );
		?>
		<input type="text" name="ppw_expiry" value="<?php echo $expiry ?>" <?php if( !current_user_can('administrator') ) echo "readonly" ?> />
		</td>
		</tr>
		<tr>
		<th><label for="address"><?php _e("Recurring days"); ?></label></th>
		<td>
		<input type="text" name="ppw_days" value="<?php echo $days ?>" <?php if( !current_user_can('administrator') ) echo "readonly" ?> />
		</td>
		</tr>
		</table>
	<?php
	}

	/**
     * Installs database table
     */
	function install() {

		global $wpdb;

		$sql = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "pay_per_view" . "` (
		`transaction_ID` bigint(20) unsigned NOT NULL auto_increment,
		`transaction_post_ID` bigint(20) NOT NULL default '0',
		`transaction_user_ID` bigint(20) NOT NULL default '0',
		`transaction_content_ID` bigint(20) default '0',
		`transaction_paypal_ID` varchar(30) default NULL,
		`transaction_payment_type` varchar(20) default NULL,
		`transaction_stamp` bigint(35) NOT NULL default '0',
		`transaction_total_amount` bigint(20) default NULL,
		`transaction_currency` varchar(35) default NULL,
		`transaction_status` varchar(35) default NULL,
		`transaction_duedate` date default NULL,
		`transaction_gateway` varchar(50) default NULL,
		`transaction_note` text,
		`transaction_expires` datetime default NULL,
		PRIMARY KEY  (`transaction_ID`),
		KEY `transaction_gateway` (`transaction_gateway`),
		KEY `transaction_post_ID` (`transaction_post_ID`)
		);";

		$wpdb->query($sql);
	}

	/**
     * Localize the plugin
     */
	function localization() {
		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "languages" folder and name it "ppw-[value in wp-config].mo"
		load_plugin_textdomain( 'ppw', false, '/pay-per-view/languages/' );
	}

	/**
     * Provide options if asked outside the class
     */
	function get_options() {
		return $this->options;
	}

	/**
	 * Save a message to the log file
	 */
	function log( $message='' ) {
		// Don't give warning if folder is not writable
		@file_put_contents( WP_PLUGIN_DIR . "/pay-per-view/log.txt", $message . chr(10). chr(13), FILE_APPEND );
	}

	/**
	 * Checks if user is authorised by the admin
	 */
	function is_authorised() {

		if ( $this->options['authorized'] == 'true' && is_user_logged_in() && !current_user_can('administrator') ) {
			if ( $this->options['level'] == 'subscriber' && current_user_can( 'read' ) )
				return true;
			else if ( $this->options['level'] == 'contributor' && current_user_can( 'edit_posts' ) )
				return true;
			else if ( $this->options['level'] == 'author' && current_user_can( 'edit_published_posts' ) )
				return true;
			else if ( $this->options['level'] == 'editor' && current_user_can( 'edit_others_posts' ) )
				return true;
		}
		return false;
	}

	/**
	 * Check if page can be cached or not
	 *
	 */
	function cachable() {

		global $post;

		// If plugin is enabled for this post/page, it is not cachable
		if ( is_object( $post ) && is_singular() ) {
			$post_meta = get_post_meta( $post->ID, 'ppw_enable', true );

			if ( $post->post_type == 'page' )
				$default = $this->options["page_default"];
			else if ( $post->post_type == 'post' )
				$default = $this->options["post_default"];
			else if ( $post->post_type != 'attachment' )
				$default = $this->options["custom_default"]; // New in V1.2
			else
				$default = '';
			if ( $post_meta == 'enable' || ( $default == 'enable' && $post_meta != 'disable' ) )
				$this->is_cachable = false;
		}
		else if ( $this->options["multi"] && !is_home() )
			$this->is_cachable = false;

		if ( is_home() && $this->options["home"] )
			$this->is_cachable = false;

		// Load css files and scripts when they are neccesary
		if ( !$this->is_cachable ) {
			$this->load_scripts_styles( );
		}
	}

	/**
	 * Changes the content according to selected settings
	 *
	 */
	function content( $content, $force=false, $method='' ) {

		global $post;
		// Unsupported post type. Maybe a temporary page, like checkout of MarketPress
		if ( !is_object( $post ) && !$content )
			return;

		// If caching is allowed no need to continue
		if ( $this->is_cachable && !$force )
			return $this->clear($content);

		// Display the admin full content, if selected so
		if ( $this->options["admin"] == 'true' && current_user_can('administrator') )
			return $this->clear($content);

		// Display the bot full content, if selected so
		if ( $this->options["bot"] == 'true' && $this->is_bot() )
			return $this->clear($content);

		// Check if current user has been authorized to see full content
		if ( $this->is_authorised() )
			return $this->clear($content);

		// If user subscribed, show content
		if ( is_user_logged_in() && trim( get_user_meta( get_current_user_id(), "ppw_subscribe", true) ) != '' )
			return $this->clear($content);

		// Find method if it is not forced
		if ( !$method && is_object( $post ) )
			$method = get_post_meta( $post->ID, 'ppw_method', true );
		if ( !$method )
			$method = $this->options["method"]; // Apply default method, if there is none

		// If user paid, show content. 'Tool' option has its own logic
		if ( isset( $_COOKIE["pay_per_view"] ) && $method != 'tool' ) {
			// On some installations slashes are added while serializing. So get rid of them.
			$orders = unserialize( stripslashes( $_COOKIE["pay_per_view"] ) );
			if ( is_array( $orders ) ) {
				$found = false;
				// Let's first check if post ID matches. If not, we save to make DB calls which are expensive
				foreach ( $orders as $order ) {
					if ( is_object( $post ) && $post->ID == $order["post_id"] ) {
						$found = true;
						break;
					}
				}
				// If $found:true, user has a cookie which matches to the post.
				// But we have to be sure that visitor did not play with it.
				if ( $found ) {
					global $wpdb;
					$query = '';
					foreach ( $orders as $order ) {

						//Escape everything
						$query .= $wpdb->prepare(" SELECT * FROM " . $this->table .
						" WHERE transaction_post_ID=%d 
						AND transaction_paypal_ID=%s 
						AND ( transaction_status='Paid' OR transaction_status='Pending' )
						AND %d < transaction_stamp UNION", 
						$order['post_id'], 
						$order['order_id'],
						(time()-7200) ); // Give another 1 hour grace time
						
//						$query .= " SELECT * FROM " . $this->table .
//						" WHERE transaction_post_ID=".$order['post_id']."
//						AND transaction_paypal_ID='".$order['order_id']."' AND ( transaction_status='Paid' OR transaction_status='Pending' )
//						AND ". (time()-7200) . "<transaction_stamp UNION"; // Give another 1 hour grace time
					}
					
					$query = rtrim( $query, "UNION" ); // Get rid of the last UNION
					$result = $wpdb->get_results( $query );

					if ( $result ) // Visitor did paid for this content!
						return $this->clear($content);
				}
			}
		}
		// If we are here, it means content will be restricted.
		// Now prepare the restricted output
		if ( $method == "automatic" ) {
			$content = preg_replace( '%\[ppw(.*?)\](.*?)\[( *)\/ppw( *)\]%is', '$2', $content ); // Clean shortcode
			$temp_arr = explode( " ", $content );

			// If the article is shorter than excerpt, show full content
			if ( !$excerpt_len = get_post_meta( $post->ID, 'ppw_excerpt', true ) )
				$excerpt_len = $this->options["excerpt"];

			if ( count( $temp_arr ) <= $excerpt_len )
				return $this->clear($content);

			// Otherwise prepare excerpt
			$e = "";
			for ( $n=0; $n<$excerpt_len; $n++ ) {
				$e .= $temp_arr[$n] . " ";
			}
			// If a tag is broken, try to complete it within reasonable limits, i.e. in next 50 words
			if ( substr_count( $e, '<') != substr_count( $e, '>' ) ) {
				// Save existing excerpt
				$e_saved = $e;
				$found = false;
				for ( $n=$excerpt_len; $n<$excerpt_len+50; $n++ ) {
					if ( isset( $temp_arr[$n] ) ) {
						$e .= $temp_arr[$n] . " ";
						if ( substr_count( $e, '<') == substr_count( $e, '>' ) ) {
							$found = true;
							break;
						}
					}
				}
				// Revert back to original excerpt if a fix is not found
				if ( !$found )
					$e = $e_saved;
			}

			// Find the price
			if ( !$price = get_post_meta( $post->ID, "ppw_price", true ) )
				$price = $this->options["price"]; // Apply default price if it is not set for the post/page

			return $e . $this->mask( $price );
		}
		else if ( $method == "manual" ) {
			// Find the price
			if ( !$price = get_post_meta( $post->ID, "ppw_price", true ) )
				$price = $this->options["price"];
			return $post->post_excerpt . $this->mask( $price );
		}
		else if ( $method == "tool" ) {
			$contents = array();
			if ( preg_match_all( '%\[ppw( +)id="(.*?)"( +)description="(.*?)"( +)price="(.*?)"(.*?)\](.*?)\[( *)\/ppw( *)\]%is', $content, $matches, PREG_SET_ORDER ) ){
				if ( isset( $_COOKIE["pay_per_view"] ) ) {
					$orders = unserialize( stripslashes( $_COOKIE["pay_per_view"] ) );
					if ( is_array( $orders ) ) {
						foreach ( $orders as $order ) {
							if ( is_object( $post ) && $order["post_id"] == $post->ID )
								$contents[] = $order["content_id"]; // Take only values related to this post
						}
					}
				}
				// Prepare the content
				foreach ( $matches as $m ) {
					if ( in_array( $m[2], $contents ) ) // This is paid
						$content = str_replace( $m[0], $m[8] , $content );
					else
						$content = str_replace( $m[0], $this->mask( $m[6],$m[2],$m[4] ), $content );
				}
			}
			return $this->clear($content);
		}
		return $this->clear($content); // Script cannot come to this point, but just in case.
	}

	/**
	 * Try to clear remaining shortcodes
	 *
	 */
	function clear( $content ) {
		// Don't even try to touch an object, just in case
		if ( is_object( $content ) )
			return $content;
		else {
			$content = preg_replace( '%\[ppw(.*?)\]%is', '', $content );
			$content = preg_replace( '%\[\/ppw\]%is', '', $content );
			return $content;
		}
	}

	/**
	 *	Initiates an instance of the Paypal gateway
	 */
	function call_gateway() {
		include_once( WP_PLUGIN_DIR. "/pay-per-view/includes/paypal-express.php" );
		$P = new PPW_Gateway_Paypal_Express($_SESSION["ppw_post_id"]);
		return $P; // return Gateway object
	}

	/**
	 *	This just makes error call compatible to gateway class
	 */
	function error( $text ) {
		$this->error = $text;
	}

	/**
	 *	Prepare the mask/template which includes payment form(s)
	 *
	 */
	function mask( $price, $id=0, $description='') {
		global $post;

		$content = '';

		// User submitted to Paypal and connection OK. Let user confirm
		if ( isset( $_GET["ppw_confirm"] ) && ( $id == $_SESSION["ppw_content_id"] OR $id == 0) ) {
			$content .= '<div class="ppw_inner">';
			$content .= '<form method="post" action="">';
			$content .= '<input type="hidden" name="ppw_content_id" value="'.$_SESSION["ppw_content_id"].'" />';
			$content .= '<input type="hidden" name="ppw_post_id" value="'.$_SESSION["ppw_post_id"].'" />';
			$content .= '<input type="hidden" name="ppw_total_amt" value="'.$_SESSION["ppw_total_amt"].'" />';
			/* translators: First %s is total amount, the second one is currency */
			$content .= '<input type="submit" name="ppw_final_payment" value="'.sprintf(__('Confirm %s %s payment to see this content','ppw'),$_SESSION["ppw_total_amt"],$this->options["currency"]).'" />';
			$content .= '</form>';
			$content .= '</div>';
			$pp = $this->call_gateway();
			$content .= $pp->confirm_payment_form( array() );
			return $content . $this->error;
		}
		// display error, if there is
		if ( isset( $_POST["ppw_final_payment"] ) && $this->error != '' )
			return $content . $this->error;

		// Count how many payment options we have
		$n = 0;
		if ( $this->options["one_time"] )
			$n++;
		if ( $this->options["daily_pass"] )
			$n++;
		if ( $this->options["subscription"] )
			$n++;
		if ( $n == 0 )
			return $content; // No payment channels selected

		$content .= '<div class="ppw_form_container">';
		// One time view option. Redirection will be handled by Paypal Express gateway
		if ( $this->options["one_time"] ) {
			$content .= '<div class="ppw_inner ppw_inner'.$n.'">';
			$content .= '<form method="post" action="">';
			$content .= '<input type="hidden" name="ppw_content_id" value="'.$id.'" />';
			$content .= '<input type="hidden" name="ppw_post_id" value="'.$post->ID.'" />';
			$content .= '<input type="hidden" name="ppw_total_amt" value="'.$price.'" />';
			if ( trim( $description ) == '' )
				$description = 'content';
			$content .= '<input type="submit" class="ppw_submit_btn" name="ppw_otw_submit" value="'.str_replace( array("PRICE","DESCRIPTION"), array($price,$description), $this->options["one_time_description"] ) .'" />';
			$content .= '</form>';
			$content .= '</div>';
		}
		// For subscription options redirection will be handled by javascript or by the forms themselves
		if ( $this->options["daily_pass"] ) {
			if ( $this->options["one_time"] )
				$content .= '<div class="ppw_or">OR</div>';
			$content .= '<div class="ppw_inner ppw_inner'.$n.'">';
			$content .= '<div style="display:none"><a href="'.wp_login_url(get_permalink()).'" class="ppw_login_hidden" >&nbsp;</a></div>';
			$content .= $this->single_sub_button( ); // No recurring
			$content .= '</div>';
		}
		if ( $this->options["subscription"] ) {
			if ( $this->options["one_time"] OR $this->options["daily_pass"] )
				$content .= '<div class="ppw_or">OR</div>';
			$content .= '<div class="ppw_inner ppw_inner'.$n.'">';
			$content .= '<div style="display:none"><a href="'.wp_login_url(get_permalink()).'" class="ppw_login_hidden">&nbsp;</a></div>';
			$content .= $this->single_sub_button( true ); // Recurring
			$content .= '</div>';
		}
		$content .= '</div>';
		//generic error message context for plugins to hook into
		$content .= apply_filters( 'ppw_checkout_error_checkout', '' );
		return $content;
	}
	/**
	 *	Prepare the subscription forms
	 *  @subs: bool Means recurring
	 */
	function single_sub_button( $subs=false ) {

		// Let's be on the safe side and select a currency
		if(empty($this->options['currency']))
			$this->options['currency'] = 'USD';

		$form = '';

		global $post, $current_user;

		if ($this->options['gateways']['paypal-express']['mode'] == 'live') {
			$form .= '<form action="https://www.paypal.com/cgi-bin/webscr" method="post">';
		} else {
			$form .= '<form action="https://www.sandbox.paypal.com/cgi-bin/webscr" method="post">';
		}
		$form .= '<input type="hidden" name="business" value="' . esc_attr($this->options['gateways']['paypal-express']['merchant_email']) . '" />';
		$form .= '<input type="hidden" name="cmd" value="_xclick-subscriptions">';
		/* translators: %s refer to blog info */
		$form .= '<input type="hidden" name="item_name" value="' . sprintf( __('Subscription for %s','ppw'), get_bloginfo('name') ) . '" />';
		$form .= '<input type="hidden" name="item_number" value="' . __('Special offer','ppw') . '" />';
		$form .= '<input type="hidden" name="no_shipping" value="1" />';
		$form .= '<input type="hidden" name="currency_code" value="' . $this->options['currency'] .'" />';
		$form .= '<input type="hidden" name="t3" value="D" />';
		$form .= '<input type="hidden" name="return" value="' . get_permalink( $post->ID ) . '" />';
		$form .= '<input type="hidden" name="cancel_return" value="' . get_option('home') . '" />';
		$form .= '<input type="hidden" name="notify_url" value="' . admin_url('admin-ajax.php?action=ppw_paypal_ipn') . '" />';
		// No recurring, i.e. daily pass
		if ( !$subs ) {
			$form .= '<input type="hidden" name="a3" value="' . number_format($this->options['daily_pass_price'], 2) . '" />';
			$form .= '<input type="hidden" name="p3" value="' . $this->options['daily_pass_days'] . '" />';
			$form .= '<input type="hidden" name="src" value="0" />';
			$form .= '<input class="ppw_custom" type="hidden" name="custom" value="' . $post->ID .":".$current_user->ID . ":" . $this->options['daily_pass_days']. ":0" . '" />';
			$form .= '<input class="ppw_submit_btn';
			// Force login if user not logged in
			if ( !is_user_logged_in() )
				$form .= ' ppw_not_loggedin'; // Add a class to which javascipt is bound
			$form .= '" type="submit" name="submit_btn" value="'. str_replace(
				array("PRICE","DAY"), array($this->options["daily_pass_price"],$this->options["daily_pass_days"]),
				$this->options["daily_pass_description"]).'" />';

		}
		else {
			$form .= '<input type="hidden" name="a3" value="' . number_format($this->options['subscription_price'], 2) . '" />';
			$form .= '<input type="hidden" name="p3" value="' . $this->options['subscription_days'] . '" />';
			$form .= '<input type="hidden" name="src" value="1" />';
			$form .= '<input class="ppw_custom" type="hidden" name="custom" value="' . $post->ID .":".$current_user->ID . ":" . $this->options['subscription_days']. ":1" . '" />';
			$form .= '<input class="ppw_submit_btn ';
			if ( !is_user_logged_in() )
				$form .= ' ppw_not_loggedin';
			$form .= '" type="submit" name="submit_btn" value="'. str_replace(
				array("PRICE","DAY"), array($this->options["subscription_price"],$this->options["subscription_days"]),
				$this->options["subscription_description"]).'" />';
		}
		// They say Paypal uses this for tracking. I would prefer to remove it if it is not mandatory.
		$form .= '<img style="display:none" alt="" border="0" width="1" height="1" src="https://www.paypal.com/en_US/i/scr/pixel.gif" />';
		$form .= '</form>';

		return $form;
	}

	/**
	 *	Initiate Paypal Express Gateway upon click on the form
	 */
	function initiate() {
		// Initial submit of the purchase
		if ( isset( $_POST["ppw_otw_submit"] ) ) {
			// Save content and post ids
			$_SESSION["ppw_content_id"] = $_POST["ppw_content_id"];
			$_SESSION["ppw_post_id"] = $_POST["ppw_post_id"];
			$_SESSION["ppw_total_amt"] = $_POST["ppw_total_amt"];

			// Now start paypal API Call
			$pp = $this->call_gateway();
			$pp->process_payment_form( array() );
		}
		else if ( isset( $_POST["ppw_final_payment"] ) ) {
			$pp = $this->call_gateway();
			$pp->process_payment( array() );
		}
	}

	/**
	 *	IPN handling for daily pass and subscription selections
	 */
	function handle_paypal_return() {
		// PayPal IPN handling code
		$this->options = get_option( 'ppw_options' );

		if ((isset($_POST['payment_status']) || isset($_POST['txn_type'])) && isset($_POST['custom'])) {

			if ($this->options['gateways']['paypal-express']['mode'] == 'live') {
				$domain = 'https://www.paypal.com';
			} else {
				$domain = 'https://www.sandbox.paypal.com';
			}

			$req = 'cmd=_notify-validate';
			if (!isset($_POST)) $_POST = $HTTP_POST_VARS;
			foreach ($_POST as $k => $v) {
				if (get_magic_quotes_gpc()) $v = stripslashes($v);
				$req .= '&' . $k . '=' . $v;
			}

			$header = 'POST /cgi-bin/webscr HTTP/1.0' . "\r\n"
					. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
					. 'Content-Length: ' . strlen($req) . "\r\n"
					. "\r\n";

			@set_time_limit(60);
			if ($conn = @fsockopen($domain, 80, $errno, $errstr, 30)) {
				fputs($conn, $header . $req);
				socket_set_timeout($conn, 30);

				$response = '';
				$close_connection = false;
				while (true) {
					if (feof($conn) || $close_connection) {
						fclose($conn);
						break;
					}

					$st = @fgets($conn, 4096);
					if ($st === false) {
						$close_connection = true;
						continue;
					}

					$response .= $st;
				}

				$error = '';
				$lines = explode("\n", str_replace("\r\n", "\n", $response));
				// looking for: HTTP/1.1 200 OK
				if (count($lines) == 0) $error = 'Response Error: Header not found';
				else if (substr($lines[0], -7) != ' 200 OK') $error = 'Response Error: Unexpected HTTP response';
				else {
					// remove HTTP header
					while (count($lines) > 0 && trim($lines[0]) != '') array_shift($lines);

					// first line will be empty, second line will have the result
					if (count($lines) < 2) $error = 'Response Error: No content found in transaction response';
					else if (strtoupper(trim($lines[1])) != 'VERIFIED') $error = 'Response Error: Unexpected transaction response';
				}

				if ($error != '') {
					$this->log( $error );
					exit;
				}
			}

			// We are using server time. Not Paypal time.
			$timestamp = time();

			$new_status = false;
			// process PayPal response
			switch ($_POST['payment_status']) {
				case 'Partially-Refunded':
					break;

				case 'In-Progress':
					break;

				case 'Completed':
				case 'Processed':
					// case: successful payment
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];

					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);

					$this->record_transaction($user_id, $post_id, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], '');

					// Check if user already subscribed before. Practically this is impossible, but who knows?
					$expiry = get_user_meta( $user_id, "ppw_subscribe", true );
					// Let's be safe. Do not save user meta if new subscription points an earlier date
					if ( $expiry && strtotime( $expiry ) > time() + $days * 86400 ) {
					}
					else
						update_user_meta( $user_id, "ppw_subscribe", date( "Y-m-d H:i:s" , strtotime( "+{$days} day", strtotime( "now" ) ) ) );

					if ( $recurring )
						update_user_meta( $user_id, "ppw_days", $days );

					update_user_meta( $user_id, "ppw_recurring", $recurring );
					break;

				case 'Reversed':
					// case: charge back
					$note = __('Last transaction has been reversed. Reason: Payment has been reversed (charge back)', 'ppw');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);

					$this->record_transaction($user_id, $post_id, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
					// User cancelled subscription. So delete user meta.
					delete_user_meta( $user_id, "ppw_subscribe" );
					delete_user_meta( $user_id, "ppw_recurring" );
					delete_user_meta( $user_id, "ppw_days" );
					break;

				case 'Refunded':
					// case: refund
					$note = __('Last transaction has been reversed. Reason: Payment has been refunded', 'ppw');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);

					$this->record_transaction($user_id, $post_id, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
					// User cancelled subscription. So delete user meta.
					delete_user_meta( $user_id, "ppw_subscribe" );
					delete_user_meta( $user_id, "ppw_recurring" );
					delete_user_meta( $user_id, "ppw_days" );
					break;

				case 'Denied':
					// case: denied
					$note = __('Last transaction has been reversed. Reason: Payment Denied', 'ppw');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);

					$this->record_transaction($user_id, $post_id, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

					break;

				case 'Pending':
					// case: payment is pending
					$pending_str = array(
						'address' => __('Customer did not include a confirmed shipping address', 'ppw'),
						'authorization' => __('Funds not captured yet', 'ppw'),
						'echeck' => __('eCheck that has not cleared yet', 'ppw'),
						'intl' => __('Payment waiting for aproval by service provider', 'ppw'),
						'multi-currency' => __('Payment waiting for service provider to handle multi-currency process', 'ppw'),
						'unilateral' => __('Customer did not register or confirm his/her email yet', 'ppw'),
						'upgrade' => __('Waiting for service provider to upgrade the PayPal account', 'ppw'),
						'verify' => __('Waiting for service provider to verify his/her PayPal account', 'ppw'),
						'*' => ''
						);
					$reason = @$_POST['pending_reason'];
					$note = __('Last transaction is pending. Reason: ', 'ppw') . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);

					// Save transaction, but do not subscribe user.
					$this->record_transaction($user_id, $post_id, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

					break;

				default:
					// case: various error cases
			}

			//check for subscription details
			switch ($_POST['txn_type']) {
				case 'subscr_signup':
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);
					// No need to do anything here
				  	break;

				case 'subscr_cancel':
					// mark for removal
					list($post_id, $user_id, $days, $recurring) = explode(':', $_POST['custom']);
					// We just unmark recurring, sucription will end after ppw_subscribe expires
					delete_user_meta( $user_id, "ppw_recurring" );
					delete_user_meta( $user_id, "ppw_days" );

				  break;

				default:
			}
		} else {
			// Did not find expected POST variables. Possible access attempt from a non PayPal site.
			// This is IPN response, so echoing will not help. Let's log it.
			$this->log( 'Error: Missing POST variables. Identification is not possible.' );
			exit;
		}
	}

	/**
	 *	Custom box create call
	 *
	 */
	function add_custom_box( ) {
		$ppw_name = __('Pay Per View', 'ppw'); // For translation compatibility
		add_meta_box( 'ppw_metabox', $ppw_name, array( &$this, 'custom_box' ), 'post', 'side', 'high' );
		add_meta_box( 'ppw_metabox', __('Pay Per View', 'ppw'), array( &$this, 'custom_box' ), 'page', 'side', 'high' );

		// New in V1.2: Custom post type support
		$args = array(
			'public'   => true,
			'_builtin' => false
		);

		$post_types = get_post_types( $args );
		if ( is_array( $post_types ) ) {
			foreach ($post_types as $post_type ) {
				add_meta_box( 'ppw_metabox', $ppw_name, array( &$this, 'custom_box' ), $post_type, 'side', 'high' );
			}
		}
	}

	/**
	 *	Custom box html codes
	 *
	 */
	function custom_box(  ) {

		global $post;

		// Some wordings and vars that will be used
		$enabled_wording = __('Enabled','ppw');
		$disabled_wording = __('Disabled','ppw');
		$automatic_wording = __("Automatic excerpt","ppw");
		$manual_wording = __("Manual excerpt","ppw");
		$tool_wording = __("Use selection tool","ppw");

		if ( is_page() )
			$pp = __('page','ppw');
		else
			$pp = __('post','ppw');

		if ( $post->post_type == 'page' )
			$default = $this->options["page_default"];
		else if ( $post->post_type == 'post' )
			$default = $this->options["post_default"];
		else if ( $post->post_type != 'attachment' )
			$default = $this->options["custom_default"];
		else
			$default = '';

		$e = get_post_meta( $post->ID, 'ppw_enable', true );
		$eselect = $dselect = '';
		if ( $e == 'enable' )
			$eselect = ' selected="selected"';
		else if ( $e == 'disable' )
			$dselect = ' selected="selected"';

		$saved_method = get_post_meta( $post->ID, 'ppw_method', true );
		switch ( $saved_method ) {
			case "automatic":	$aselect = 'selected="selected"'; break;
			case "manual":		$mselect = 'selected="selected"'; break;
			case "tool":		$tselect = 'selected="selected"'; break;
			default:			$aselect = $mselect = $tselect = ''; break;
		}

		if ( $saved_method == "" )
			$method = $this->options["method"]; // Apply default method, if there is none
		else
			$method = $saved_method;
		switch ( $method ) {
			case 'automatic':	$eff_method = $automatic_wording; break;
			case 'manual':		$eff_method = $manual_wording; break;
			case 'tool':		$eff_method = $tool_wording; break;
		}

		if ( $e == 'enable' || ( $default == 'enable' && $e != 'disable' ) )
			$eff_status = "<span class='ppw_span green' id='ppw_eff_status'>&nbsp;" . $enabled_wording . "</span>";
		else
			$eff_status = "<span class='ppw_span red' id='ppw_eff_status'>&nbsp;" . $disabled_wording . "</span>";

		// Use nonce for verification
		wp_nonce_field( plugin_basename(__FILE__), 'ppw_nonce' );
		?>
		<style type="text/css">
		<!--
		#ppw_metabox label{
		float: left;
		padding-top:5px;
		}
		#ppw_metabox select{
		float: right;
		}
		#ppw_metabox input{
		float: right;
		width: 20%;
		text-align:right;
		}
		.ppw_clear{
		clear:both;
		margin:10px 0 10px 0;
		}
		.ppw_info{
		padding-top:5px;
		}
		.ppw_info span.wpmudev-help{
		margin-top:10px;
		}
		.ppw_span{float:right;font-weight:bold;padding-top:5px;padding-right:3px;}
		.red{color:red}
		.green{color:green}
		.ppw_border{border-top-color:white;border-bottom-color: #DFDFDF;border-style:solid;border-width:1px 0;}

		-->
		<?php if ( 'automatic' != $method ) echo '#ppw_excerpt{opacity:0.2}';?>
		</style>
		<?php
		echo '<select name="ppw_enable" id="ppw_enable">';
		echo '<option value="" >'. __("Follow global setting","ppw"). '</option>';
		echo '<option value="enable" '.$eselect.'>' . __("Always enabled","ppw"). '</option>';
		echo '<option value="disable" '.$dselect.'>' . __("Always disabled","ppw") . '</option>';
		echo '</select>';

		echo '<label for="ppw_enable">';
		_e('Enabled?', 'ppw');
		echo '</label>';
		/* translators: Both %s refer to post or page */
		echo '<div class="ppw_info">';
		echo $this->tips->add_tip( sprintf(__('Selects if Pay With a Like is enabled for this %s or not. If Follow global setting is selected, General Setting page selection will be valid. Always enabled and Always disabled selections will enable or disable Pay With a Like for this %s, respectively, overriding general setting.','ppw'),$pp,$pp));
		echo '</div>';
		echo '<div class="ppw_clear"></div>';

		echo "<label for='effective_status'>". __('Effective status','ppw'). "</label>";
		echo $eff_status;
		echo '<div class="ppw_info">';
		/* translators: %s refer to post or page */
		echo $this->tips->add_tip(sprintf(__('Effective status dynamically shows the final result of the setting that will be applied to this %s. Disabled means Pay With a Like will not work for this %s. It takes global settings into account and helps you to check if your intention will be correctly reflected to the settings after you save.','ppw'),$pp,$pp));
		echo '</div>';
		echo '<div class="ppw_clear ppw_border"></div>';

		echo '<select name="ppw_method" id="ppw_method">';
		echo '<option value="" >'. __("Follow global setting","ppw"). '</option>';
		echo '<option value="automatic" '.$aselect.'>'. $automatic_wording . '</option>';
		echo '<option value="manual" '.$mselect.'>' . $manual_wording . '</option>';
		echo '<option value="tool" '.$tselect.'>' . $tool_wording . '</option>';
		echo '</select>';
		echo '<label for="ppw_method">';
		_e('Method', 'ppw');
		echo '</label>';
		echo '<div class="ppw_info">';
		/* translators: First %s refer to post or page. Second %s is the url address of the icon */
		echo $this->tips->add_tip(sprintf(__('Selects the content protection method for this %s. If Follow Global Setting is selected, method selected in General Settings page will be applied. If you want to override general settings, select one of the other methods. With Use Selection Tool you need to select each content using the icon %s on the editor tool bar. For other methods refer to the settings page.','ppw'),$pp,"<img src='".$this->plugin_url."/images/menu_icon.png"."' />" ) );				 			 			 		 
		echo '</div>';
		echo '<div class="ppw_clear"></div>';

		echo "<label for='effective_method'>". __('Effective method','ppw'). ":</label>";
		echo "<span class='ppw_span' id='ppw_eff_method'>&nbsp;" . $eff_method . "</span>";
		echo '<div class="ppw_info">';
		/* translators: %s refer to post or page */
		echo $this->tips->add_tip(sprintf(__('Effective method dynamically shows the final result of the setting that will be applied to this %s. It takes global settings into account and helps you to check if your intention will be correctly reflected to the settings after you save.','ppw'),$pp));
		echo '</div>';
		echo '<div class="ppw_clear ppw_border"></div>';

		echo '<input type="text" name="ppw_excerpt" id="ppw_excerpt" value="'.get_post_meta( $post->ID, 'ppw_excerpt', true ).'" />';
		echo '<label for="ppw_excerpt">';
		_e('Excerpt length', 'ppw');
		echo '</label>';
		echo '<div class="ppw_info">';
		/* translators: %s refer to post or page */
		echo $this->tips->add_tip(sprintf(__('If you want to override the number of words that will be used as an excerpt for the unprotected content, enter it here. Please note that this value is only used when Automatic Excerpt method is applied to the %s.','ppw'),$pp ));
		echo '</div>';
		echo '<div class="ppw_clear ppw_border"></div>';

		echo '<input type="text" name="ppw_price" value="'.get_post_meta( $post->ID, 'ppw_price', true ).'" />';
		echo '<label for="ppw_price">';
		printf( __('Price (%s)', 'ppw'), $this->options["currency"] );
		echo '</label>';
		echo '<div class="ppw_info">';
		/* translators: %s refer to post or page */
		echo $this->tips->add_tip(sprintf(__('If you want to override the default price to reveal this %s, enter it here. This value is NOT used when Selection Tool method is applied to the %s.',"ppw"),$pp,$pp ));
		echo '</div>';
		echo '<div class="ppw_clear"></div>';

		?>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			var def = '<?php echo $default ?>';
			var def_method = '<?php echo $this->options["method"] ?>';
			$(document).bind('DOMSubtreeModified',function(){
				if ('<?php echo $method?>' != 'tool'){$('#content_paywithalike').css('opacity','0.2');}
			});
			$("select#ppw_enable").change(function() {
				var e = $('select#ppw_enable').val();
				if ( e == 'enable' || ( def == 'enable' && e != 'disable' ) ){
					$('#ppw_eff_status').html('&nbsp;<?php echo $enabled_wording?>').addClass('green').removeClass('red');
				}
				else { $('#ppw_eff_status').html('&nbsp;<?php echo $disabled_wording?>').addClass('red').removeClass('green'); }
			});


			$("select#ppw_method").change(function() {
				var m = $('select#ppw_method').val();
				if ( m == '' ) {m = def_method;}
				switch(m){
					case 'automatic':	$('#ppw_eff_method').html('&nbsp;<?php echo $automatic_wording?>');$('#content_paywithalike,#ppw_excerpt').css('opacity','0.2');$('#ppw_excerpt').css('opacity','1');break;
					case 'manual':		$('#ppw_eff_method').html('&nbsp;<?php echo $manual_wording?>');$('#content_paywithalike,#ppw_excerpt').css('opacity','0.2');break;
					case 'tool':		$('#ppw_eff_method').html('&nbsp;<?php echo $tool_wording?>');$('#content_paywithalike').css('opacity','1');$('#ppw_excerpt').css('opacity','0.2');break;
				}
			});

		});
		</script>
		<?php
	}

	/**
	 *	Saves post meta values
	 *
	 */
	function add_postmeta( $post_id ) {

		if ( !wp_verify_nonce( @$_POST['ppw_nonce'], plugin_basename(__FILE__) ) ) return $post_id;
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return $post_id;

		// Check permissions
		if ( 'page' == $_POST['post_type'] ) {
			if ( !current_user_can( 'edit_page', $post_id ) )
				return $post_id;
		}
		elseif ( !current_user_can( 'edit_post', $post_id ) )
			return $post_id;

		// Auth ok
		if ( isset( $_POST['ppw_enable'] ) ) {
			if ( $_POST['ppw_enable'] != '' )
				update_post_meta( $post_id, 'ppw_enable', $_POST['ppw_enable'] );
			else
				delete_post_meta( $post_id, 'ppw_enable' );
		}
		if ( isset( $_POST['ppw_method'] ) ) {
			if ( $_POST['ppw_method'] != '' )
				update_post_meta( $post_id, 'ppw_method', $_POST['ppw_method'] );
			else
				delete_post_meta( $post_id, 'ppw_method' );
		}
		if ( isset( $_POST['ppw_excerpt'] ) ) {
			if ( $_POST['ppw_excerpt'] != '' && is_numeric( $_POST['ppw_excerpt'] ) )
				update_post_meta( $post_id, 'ppw_excerpt', $_POST['ppw_excerpt'] );
			else
				delete_post_meta( $post_id, 'ppw_excerpt' );
		}
		if ( isset( $_POST['ppw_price'] ) ) {
			if ( $_POST['ppw_price'] != '' && is_numeric( $_POST['ppw_price'] ) )
				update_post_meta( $post_id, 'ppw_price', $_POST['ppw_price'] );
			else
				delete_post_meta( $post_id, 'ppw_price' );
		}
	}

  //enqeue js on product settings screen
	function admin_scripts() {
		wp_enqueue_script('jquery');
		wp_enqueue_script( 'jquery-colorpicker', $this->plugin_url . '/js/colorpicker.js', array('jquery'), $this->version);
	}

	//enqeue css on product settings screen
	function admin_css() {
		wp_enqueue_style( 'jquery-colorpicker-css', $this->plugin_url . '/css/colorpicker.css', false, $this->version);
	}

	/**
	 *	Add initial settings
	 *
	 */
	function init() {
		// Since wp-cron is not reliable, use this instead
		add_option( "ppw_last_update", time() );

		add_option( 'ppw_options', array(
										'post_default'				=> 'enable',
										'page_default'				=> '',
										'custom_default'			=> '',
										'method'					=> 'automatic',
										'excerpt'					=> 100,
										'price'						=> '0.25',
										'admin'						=> 'true',
										'home'						=> '',
										'multi'						=> 'true',
										'authorized'				=> '',
										'level'						=> 'editor',
										'bot'						=> '',
										'cookie'					=> 1,
										'one_time'					=> 'true',
										'one_time_description'		=> 'Pay only $PRICE to see this DESCRIPTION',
										'daily_pass'				=> 'true',
										'daily_pass_price'			=> '2.75',
										'daily_pass_days'			=> '1',
										'daily_pass_description'	=> 'Access all content for just $PRICE for DAY day',
										'subscription'				=> 'true',
										'subscription_price'		=> '11.55',
										'subscription_days'			=> '30',
										'subscription_description'	=> 'Subscribe for just $PRICE for DAY days',
										'currency'					=> 'USD',
										'admin_email'				=> get_option("admin_email"),
										'paypal_email'				=> '',
										'sandbox'					=> '',
										'accept_api_logins'			=> 'true',
										'facebook-no_init'			=> '',
										'facebook-app_id'			=> '',
										'twitter-app_id' 			=> '',
										'twitter-app_secret'		=> ''
										)
		);

		//  Run this code not before 30 min
		if ( ( time( ) - get_option( "ppw_last_update" ) ) < 1800 )
			return;
		$this->clear_subscriptions();
	}

	/**
	 *	If a subscription method is selected, but API login not, warn admin
	 *
	 */
	function admin_notices() {
		if ( ( $this->options["daily_pass"] OR $this->options["subscription"] ) && !$this->options["accept_api_logins"] ) {
			echo '<div class="error fade"><p>' .
				__("<b>[Pay Per View]</b> If you are using Daily Pass or Recurring Subscriptions, you need to enable and set API logins.", "ppw") .
			'</p></div>';
		}

		// Warn admin in case of default permalink.
		if ( !get_option( 'permalink_structure' ) )
			echo '<div class="error fade"><p>' .
				__("<b>[Pay Per View]</b> Plugin will not function correctly with default permalink structure. You need to use a pretty permalink structure.", "ppw") .
				'</p></div>';

	}

	/**
	 *	Clear expired subscriptions
	 *
	 */
	function clear_subscriptions() {
		update_option( "ppw_last_update", time() );
		global $wpdb;
		// Clear expired subscriptions
		$wpdb->query("
			DELETE subs FROM $wpdb->usermeta subs, $wpdb->usermeta recur
			WHERE subs.user_id = recur.user_id
			AND subs.meta_key='ppw_subscribe' && NOW() > subs.meta_value
			AND recur.meta_key='ppw_recurring' && recur.meta_value <> '1'
		" );
		// Adjust recurring subscriptions' expiry date
		$results = $wpdb->get_results("
			SELECT subs.user_id
			FROM $wpdb->usermeta subs, $wpdb->usermeta recur, $wpdb->usermeta days
			WHERE subs.user_id = recur.user_id
			AND subs.user_id = days.user_id
			AND subs.meta_key='ppw_subscribe' && NOW() > subs.meta_value
			AND recur.meta_key='ppw_recurring' && recur.meta_value = '1'
			AND days.meta_key='ppw_days' && days.meta_value <> ''
		" );

		if ( $results ) {
			foreach ( $results as $result ) {
				$days = get_user_meta( $result->user_id, "ppw_days", true );
				$date = get_user_meta( $result->user_id, "ppw_subscribe", true );
				// Write new expiry date
				if ( $days && $date )
					update_user_meta( $result->user_id, "ppw_subscribe", date( "Y-m-d H:i:s" , strtotime( "+{$days} day", strtotime( $date ) ) ) );
			}
		}
	}

	/**
	 *	Handles settings form data
	 *
	 */
	function admin_init() {

		if (!class_exists('WpmuDev_HelpTooltips'))
			require_once dirname(__FILE__) . '/includes/class_wd_help_tooltips.php';
		$this->tips = new WpmuDev_HelpTooltips();
		$this->tips->set_icon_url(plugins_url('pay-per-view/images/information.png'));

		add_menu_page(__('Pay Per View','ppw'), __('Pay Per View','ppw'), 'manage_options',  $this->plugin_name, array(&$this,'settings'),$this->plugin_url."/images/menu_icon.png");
		add_submenu_page($this->plugin_name, __('Transactions','ppw'), __('Transactions','ppw'), 'manage_options', "ppw_transactions", array(&$this,'transactions'));
		add_submenu_page($this->plugin_name, __('Customization','ppw'), __('Customization Help','ppw'), 'manage_options', "ppw_customization", array(&$this,'customization'));

		if ( isset($_POST["action_ppw"]) && !wp_verify_nonce($_POST['ppw_nonce'],'update_ppw_settings') ) {
			add_action( 'admin_notices', array( &$this, 'warning' ) );
			return;
		}

		if ( isset($_POST["action_ppw"]) ) {
			$this->options["post_default"]			= $_POST["post_default"];
			$this->options["page_default"]			= $_POST["page_default"];
			$this->options["custom_default"]		= $_POST["custom_default"];
			$this->options["method"]				= $_POST["ppw_method"];
			$this->options["excerpt"]				= $_POST["excerpt"];
			$this->options["price"]					= $_POST["price"];
			$this->options["home"]					= $_POST["home"];
			$this->options["multi"]					= $_POST["multi"];
			$this->options["admin"]					= $_POST["admin"];
			$this->options["authorized"]			= $_POST["authorized"];
			$this->options["level"]					= $_POST["level"];
			$this->options["bot"]					= $_POST["bot"];
			$this->options["cookie"]				= $_POST["cookie"];
			$this->options["one_time"]				= isset( $_POST["one_time"] );
			$this->options["one_time_description"]	= $_POST["one_time_description"];
			$this->options["daily_pass"]			= isset( $_POST["daily_pass"] );
			$this->options["daily_pass_price"]		= $_POST["daily_pass_price"];
			$this->options["daily_pass_days"]		= $_POST["daily_pass_days"];
			$this->options["daily_pass_description"]= $_POST["daily_pass_description"];
			$this->options["subscription"]			= $_POST["subscription"];
			$this->options["subscription_price"]	= $_POST["subscription_price"];
			$this->options["subscription_days"] 	= $_POST["subscription_days"];
			$this->options["subscription_description"]	= $_POST["subscription_description"];

			$this->options["accept_api_logins"]		= isset( $_POST["accept_api_logins"] );
			$this->options["facebook-no_init"]		= isset( $_POST["facebook-no_init"] );
			$this->options['facebook-app_id']		= trim( $_POST['facebook-app_id'] );
			$this->options['twitter-app_id']		= trim( $_POST['twitter-app_id'] );
			$this->options['twitter-app_secret']	= trim( $_POST['twitter-app_secret'] );

			// TODO: Shorten these
			$this->options['gateways']['paypal-express']['api_user'] = $_POST["ppw"]['gateways']['paypal-express']['api_user'];
			$this->options['gateways']['paypal-express']['api_pass'] = $_POST["ppw"]['gateways']['paypal-express']['api_pass'];
			$this->options['gateways']['paypal-express']['api_sig'] = $_POST["ppw"]['gateways']['paypal-express']['api_sig'];
			$this->options['gateways']['paypal-express']['currency'] = $_POST["ppw"]['gateways']['paypal-express']['currency'];
			$this->options['gateways']['paypal-express']['locale'] = $_POST["ppw"]['gateways']['paypal-express']['locale'];
			$this->options['gateways']['paypal-express']['mode'] = $_POST["ppw"]['gateways']['paypal-express']['mode'];
			$this->options['gateways']['paypal-express']['merchant_email'] = $_POST["ppw"]['gateways']['paypal-express']['merchant_email'];
			$this->options['gateways']['paypal-express']['header_img'] = $_POST["ppw"]['gateways']['paypal-express']['header_img'];
			$this->options['gateways']['paypal-express']['header_border'] = $_POST["ppw"]['gateways']['paypal-express']['header_border'];
			$this->options['gateways']['paypal-express']['header_back'] = $_POST["ppw"]['gateways']['paypal-express']['header_back'];
			$this->options['gateways']['paypal-express']['page_back'] = $_POST["ppw"]['gateways']['paypal-express']['page_back'];

			$this->options['currency'] = $this->options['gateways']['paypal-express']['currency'];

			if ( update_option( 'ppw_options', $this->options ) )
				add_action( 'admin_notices', array ( &$this, 'saved' ) );
		}
	}

	/**
	 *	Prints "saved" message on top of Admin page
	 */
	function saved( ) {
		echo '<div class="updated fade"><p><b>[Pay Per View]</b> Settings saved.</p></div>';
	}

	/**
	 *	Prints warning message on top of Admin page
	 */
	function warning( ) {
		echo '<div class="updated fade"><p><b>[Pay Per View] You are not authorised to do this.</b></p></div>';
	}

	/**
	 *	Admin settings HTML code
	 */
	function settings() {

		if (!current_user_can('manage_options')) {
			wp_die( __('You do not have sufficient permissions to access this page.') );
		}
	?>
		<div class="wrap">
		<div class="icon32" style="margin:8px 0 0 8px"><img src="<?php echo $this->plugin_url . '/images/general.png'; ?>" /></div>
        <h2><?php _e('General Settings', 'ppw'); ?></h2>
        <div id="poststuff" class="metabox-holder ppw-settings">

		<form method="post" action="" >
		<?php wp_nonce_field( 'update_ppw_settings', 'ppw_nonce' ); ?>

		<div class="postbox" id="ppw_global_postbox">
            <h3 class='hndle'><span><?php _e('Global Settings', 'ppw') ?></span></h3>
            <div class="inside">
              <span class="description"><?php _e('Pay Per View allows protecting posts/page content or parts of post/page content until visitor pays a nominal fee or subscribes to the website. These settings provide a quick way to set Pay Per View for your posts and pages. They can be overridden per post basis using post editor page.', 'ppw') ?></span>

				<table class="form-table">

					<tr valign="top">
						<th scope="row" ><?php _e('Protection for posts', 'ppw')?></th>
						<td colspan="2">
						<select name="post_default">
						<option value="" <?php if ( $this->options['post_default'] <> 'enable' ) echo "selected='selected'"?>><?php _e('Disabled for all posts', 'ppw')?></option>
						<option value="enable" <?php if ( $this->options['post_default'] == 'enable' ) echo "selected='selected'"?>><?php _e('Enabled for all posts', 'ppw')?></option>
						</select>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Protection for pages', 'ppw')?></th>
						<td colspan="2">
						<select name="page_default">
						<option value="" <?php if ( $this->options['page_default'] <> 'enable' ) echo "selected='selected'"?>><?php _e('Disabled for all pages', 'ppw')?></option>
						<option value="enable" <?php if ( $this->options['page_default'] == 'enable' ) echo "selected='selected'"?>><?php _e('Enabled for all pages', 'ppw')?></option>
						</select>
						</td>
					</tr>
					<?php
					$args = array(
						'public'   => true,
						'_builtin' => false
					);
					$post_types = get_post_types( $args, 'objects' );

					if ( is_array( $post_types ) && count( $post_types ) > 0 ) {
						$note = __("You have the following custom post type(s): ","ppw");
							foreach ( $post_types as $post_type )
								$note .= $post_type->labels->name . ", ";
						$note = rtrim( $note, ", " );
						$note .= __(' Note: See the below customization section for details.','ppw');
					}
					else $note = __("You don't have any custom post types. Changing this setting will have no effect.","ppw");
					?>

					<tr valign="top">
						<th scope="row" ><?php _e('Protection for custom post types', 'ppw')?></th>
						<td colspan="2">
						<select name="custom_default">
						<option value="" <?php if ( $this->options['custom_default'] <> 'enable' ) echo "selected='selected'"?>><?php _e('Disabled for all custom post types', 'ppw')?></option>
						<option value="enable" <?php if ( $this->options['custom_default'] == 'enable' ) echo "selected='selected'"?>><?php _e('Enabled for all custom post types', 'ppw')?></option>
						</select>
						<span class="description"><?php echo $note ?></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Public content selection method', 'ppw')?></th>
						<td colspan="2">
						<select name="ppw_method" id="ppw_method">
						<option value="automatic" <?php if ( $this->options['method'] == 'automatic' ) echo "selected='selected'"?>><?php _e('Automatic excerpt from the content', 'ppw')?></option>
						<option value="manual" <?php if ( $this->options['method'] == 'manual' ) echo "selected='selected'"?>><?php _e('Manual excerpt from post excerpt field', 'ppw')?></option>
						<option value="tool" <?php if ( $this->options['method'] == 'tool' ) echo "selected='selected'"?>><?php _e('Use selection tool', 'ppw')?></option>
						</select>
						<span class="description"><?php
							printf(__('Automatic excerpt selects the first %d words, number being adjustable from "excerpt length" field. Manual excerpt displays whatever included in the post excerpt field of the post. With selection tool, you can freely select part(s) of the content to be protected. Using the latter one may be a little bit sophisticated, but enables more than one part of the content to be protected.', 'ppw'),$this->options["excerpt"]);
						?></span>
						</td>
					</tr>

					<tr valign="top" id="excerpt_length" <?php if ( $this->options['method'] != 'automatic' ) echo 'style="display:none"'?>>
						<th scope="row" ><?php _e('Excerpt length (words)', 'ppw')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="excerpt" value="<?php echo $this->options["excerpt"] ?>" />
						<span class="description"><?php _e('Number of words of the post content that will be displayed publicly. Only effective if Automatic excerpt is selected.', 'ppw') ?></span></td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php printf(__('Unit price (%s)', 'ppw'),$this->options["currency"])?></th>
						<td colspan="2"><input type="text" style="width:50px" name="price" value="<?php echo $this->options["price"] ?>" />
						<span class="description"><?php _e('Default price per protected content.', 'ppw') ?></span></td>
					</tr>

				</table>
			</div>
			</div>

		<div class="postbox" id="ppw_accessibility_postbox">
            <h3 class='hndle'><span><?php _e('Accessibility Settings', 'ppw'); ?></span></h3>
            <div class="inside">

				<table class="form-table">

					<tr valign="top">
						<th scope="row" ><?php _e('Enable on the home page', 'ppw') ?></th>
						<td colspan="2">
						<select name="home">
						<option value="true" <?php if ( $this->options['home'] == 'true' ) echo "selected='selected'"?> ><?php _e('Yes','ppw')?></option>
						<option value="" <?php if ( $this->options['home'] <> 'true' ) echo "selected='selected'"?>><?php _e('No','ppw')?></option>
						</select>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Enable for multiple post pages', 'ppw') ?></th>
						<td colspan="2">
						<select name="multi">
						<option value="true" <?php if ( $this->options['multi'] == 'true' ) echo "selected='selected'"?> ><?php _e('Yes','ppw')?></option>
						<option value="" <?php if ( $this->options['multi'] <> 'true' ) echo "selected='selected'"?>><?php _e('No','ppw')?></option>
						</select>
						<span class="description"><?php _e('Enables the plugin for pages (except the home page) which contain content for more that one post/page, e.g. archive, category pages. Some themes use excerpts here so enabling plugin for these pages may cause strange output. ', 'ppw')?></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Admin sees full content','ppw')?></th>
						<td colspan="2">
						<select name="admin">
						<option value="true" <?php if ( $this->options['admin'] == 'true' ) echo "selected='selected'"?>><?php _e('Yes','ppw')?></option>
						<option value="" <?php if ( $this->options['admin'] <> 'true' ) echo "selected='selected'"?> ><?php _e('No','ppw')?></option>
						</select>
						<span class="description"><?php _e('You may want to select No for test purposes.','ppw')?></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Authorized users see full content','ppw')?></th>
						<td colspan="2">
						<select name="authorized" id="authorized">
						<option value="true" <?php if ( $this->options['authorized'] == 'true' ) echo "selected='selected'"?> ><?php _e('Yes','ppw')?></option>
						<option value="" <?php if ( $this->options['authorized'] <> 'true' ) echo "selected='selected'"?>><?php _e('No','ppw')?></option>
						</select>
						<span class="description"><?php _e('If Yes, authorized users will see the full content without the need to pay or subscribe. Admin setting is independent of this one.','ppw')?></span>
						</td>
					</tr>

					<tr valign="top" id="level" <?php if ( $this->options['authorized'] != 'true' ) echo 'style="display:none"'?>>
						<th scope="row" ><?php _e('User level where authorization starts','ppw')?></th>
						<td colspan="2">
						<select name="level">
						<option value="editor" <?php if ( $this->options['level'] == 'editor' ) echo "selected='selected'"?>><?php _e('Editor','ppw')?></option>
						<option value="author" <?php if ( $this->options['level'] == 'author' ) echo "selected='selected'"?>><?php _e('Author','ppw')?></option>
						<option value="contributor" <?php if ( $this->options['level'] == 'contributor' ) echo "selected='selected'"?>><?php _e('Contributor','ppw')?></option>
						<option value="subscriber" <?php if ( $this->options['level'] == 'subscriber' ) echo "selected='selected'"?>><?php _e('Subscriber','ppw')?></option>
						</select>
						<span class="description"><?php _e('If the above field is selected as yes, users having a higher level than this selection will see the full content.','ppw')?></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Search bots see full content','ppw')?></th>
						<td colspan="2">
						<select name="bot">
						<option value="true" <?php if ( $this->options['bot'] == 'true' ) echo "selected='selected'"?> ><?php _e('Yes','ppw')?></option>
						<option value="" <?php if ( $this->options['bot'] <> 'true' ) echo "selected='selected'"?>><?php _e('No','ppw')?></option>
						</select>
						<span class="description"><?php _e('You may want to enable this for SEO purposes. Warning: Your full content may be visible in search engine results.','ppw')?></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row" ><?php _e('Cookie validity time (hours)', 'ppw')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="cookie" value="<?php echo $this->options["cookie"] ?>" />
						<span class="description"><?php _e('Validity time of the cookie which lets visitor to be exempt from the protection after he/she liked. Tip: If you want the cookie to expire at the end of the session (when the browser closes), enter zero here.', 'ppw') ?></span></td>
					</tr>

				</table>
			</div>
			</div>

		<div class="postbox" id="ppw_payment_postbox">
            <h3 class='hndle'><span><?php _e('Payment Options', 'ppw') ?></span></h3>
            <div class="inside">

				<table class="form-table">

					<tr valign="top">
						<th scope="row" ><?php _e('One time view','ppw')?></th>
						<td colspan="2">
						<input type="checkbox" id="one_time" name="one_time" value="true" <?php if ($this->options["one_time"]) echo "checked='checked'"?> />
						<span class="description"><?php _e('Visitors pay per content they want to reveal. Price can be set globally from the above "unit price" field, or per post basis using the post editor. Does not require registration of the visitor.','ppw')?></span>
						</td>
					</tr>
					<?php
					if (!$this->options["one_time"]) $style='style="display:none"';
					else $style = '';
					?>


					<tr valign="top" class="one_time_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('One time view description', 'ppw')?></th>
						<td colspan="2"><input type="text" style="width:400px" name="one_time_description" value="<?php echo stripslashes($this->options["one_time_description"]) ?>" />
						<br /><span class="description"><?php _e('This text will be shown on the button. PRICE (case sensitive) will be replaced by its real value.
						DESCRIPTION (case sensitive) will be replaced by description field defined in Selection Tool, or the word "content" if it is not given.', 'ppw') ?></span></td>
					</tr>

					<tr valign="top" style="border-top:1px solid lightgrey">
						<th scope="row" ><?php _e('Daily Pass','ppw')?></th>
						<td colspan="2">
						<input type="checkbox" id="daily_pass" name="daily_pass" value="true" <?php if ($this->options["daily_pass"]) echo "checked='checked'"?> />
						<span class="description"><?php _e('Visitor pays a lumpsum fee and then he/she can view all the content on the website. Visitor is required to register to the website.','ppw')?></span>
						</td>
					</tr>
					<?php
					if (!$this->options["daily_pass"]) $style='style="display:none"';
					else $style = '';
					?>

					<tr valign="top" class="daily_pass_detail" <?php echo $style?>>
						<th scope="row" ><?php printf(__('Daily pass price (%s)', 'ppw'),$this->options["currency"])?></th>
						<td colspan="2"><input type="text" style="width:50px" name="daily_pass_price" value="<?php echo $this->options["daily_pass_price"] ?>" />
						<span class="description"><?php _e('Price that will be paid once which lets the visitor see full content during validity period.', 'ppw') ?></span></td>
					</tr>

					<tr valign="top" class="daily_pass_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Daily pass validity (days)', 'ppw')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="daily_pass_days" value="<?php echo $this->options["daily_pass_days"] ?>" />
						<span class="description"><?php _e('Daily pass will be valid for this period.', 'ppw') ?></span></td>
					</tr>

					<tr valign="top" class="daily_pass_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Daily pass description', 'ppw')?></th>
						<td colspan="2"><input type="text" style="width:400px" name="daily_pass_description" value="<?php echo stripslashes($this->options["daily_pass_description"]) ?>" />
						<br /><span class="description"><?php _e('This text will be shown on the button. PRICE and DAY (case sensitive) will be replaced by their real values.', 'ppw') ?></span></td>
					</tr>

					<tr valign="top" style="border-top:1px solid lightgrey">
						<th scope="row" >
						<?php _e('Recurring subscription','ppw')?>
						</th>
						<td colspan="2">
						<input type="checkbox" id="subscription" name="subscription" value="true" <?php if ($this->options["subscription"]) echo "checked='checked'"?> />
						<span class="description"><?php _e('Visitor subscribes to view all the content on the website. Visitor is required to register to the website.','ppw')?></span>
						</td>
					</tr>
					<?php
					if (!$this->options["subscription"]) $style='style="display:none"';
					else $style = '';
					?>

					<tr valign="top" class="subscription_detail" <?php echo $style?>>
						<th scope="row" ><?php printf(__('Subscription price (%s)', 'ppw'),$this->options["currency"])?></th>
						<td colspan="2">
							<input type="text" style="width:50px" id="subscription_price" name="subscription_price" value="<?php echo $this->options["subscription_price"] ?>" />
						</td>
					</tr>

					<tr valign="top" class="subscription_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Subscription period (days)', 'ppw')?></th>
						<td colspan="2">
							<input type="text" style="width:50px" id="subscription_days" name="subscription_days" value="<?php echo $this->options["subscription_days"] ?>" />
							<span class="description"><?php _e('Price is valid for this period and it will be renewed after it expires.', 'ppw') ?></span>
						</td>
					</tr>

					<tr valign="top" class="subscription_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Subscription description', 'ppw')?></th>
						<td colspan="2">
							<input type="text" style="width:400px" name="subscription_description" value="<?php echo stripslashes($this->options["subscription_description"]) ?>" />
							<br /><span class="description"><?php _e('This text will be shown on the button. PRICE and DAY (case sensitive) will be replaced by their real values.', 'ppw') ?></span>
						</td>
					</tr>


				</table>
			</div>
			</div>


		<div class="postbox" id="ppw_api_postbox">
            <h3 class='hndle'><span><?php _e('API Settings', 'ppw') ?></span></h3>
            <div class="inside">

				<table class="form-table">

					<tr valign="top">
						<th scope="row" ><?php _e('Accept API Logins','ppw')?></th>
						<td colspan="2">
						<input type="checkbox" id="accept_api_logins" name="accept_api_logins" value="true" <?php if ($this->options["accept_api_logins"]) echo "checked='checked'"?> />
						<span class="description"><?php _e('Enables login to website using Facebook and Twitter.','ppw')?></span>
						</td>
					</tr>
					<?php
					if (!$this->options["accept_api_logins"]) $style='style="display:none"';
					else $style='';
					?>

					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('My website already uses Facebook','ppw')?></th>
						<td colspan="2">
						<input type="checkbox" name="facebook-no_init" value="true" <?php if ($this->options["facebook-no_init"]) echo "checked='checked'"?> />
						<span class="description"><?php _e('By default, Facebook script will be loaded by the plugin. If you are already running Facebook scripts, to prevent any conflict, check this option.','ppw')?></span>
						</td>
					</tr>

					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Facebook App ID','ppw')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="facebook-app_id" value="<?php echo $this->options["facebook-app_id"] ?>" />
						<br /><span class="description"><?php printf(__("Enter your App ID number here. If you don't have a Facebook App yet, you will need to create one <a href='%s'>here</a>", 'ppw'), 'https://developers.facebook.com/apps')?></span>
						</td>
					</tr>

					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Twitter Consumer Key','ppw')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="twitter-app_id" value="<?php echo $this->options["twitter-app_id"] ?>" />
						<br /><span class="description"><?php printf(__('Enter your Twitter App ID number here. If you don\'t have a Twitter App yet, you will need to create one <a href="%s">here</a>', 'ppw'), 'https://dev.twitter.com/apps/new')?></span>
						</td>
					</tr>

					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Twitter Consumer Secret','ppw')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="twitter-app_secret" value="<?php echo $this->options["twitter-app_secret"] ?>" />
						<br /><span class="description"><?php _e('Enter your Twitter App ID Secret here.', 'ppw')?></span>
						</td>
					</tr>

				</table>
			</div>
			</div>


			<?php
			// Add Paypal Express Gateway Settings
			include_once( WP_PLUGIN_DIR. "/pay-per-view/includes/paypal-express.php" );
			$PPWPaypal = &new PPW_Gateway_Paypal_Express();
			$PPWPaypal->gateway_settings_box($this->options);
			?>
					<input type="hidden" name="action_ppw" value="update_per" />
					<p class="submit">
					<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
					</p>
			</form>

		</div>
		</div>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$("select#ppw_method").change(function() {
					if ( $('select#ppw_method').val() == "automatic" ) { $("#excerpt_length").show(); }
					else { $("#excerpt_length").hide(); }
				});
				$("select#authorized").change(function() {
					if ( $('select#authorized').val() == "true" ) { $("#level").show(); }
					else { $("#level").hide(); }
				});
				$("#one_time").change(function() {
					if ( $('#one_time').is(':checked')) { $(".one_time_detail").show(); }
					else { $(".one_time_detail").hide(); }
				});
				$("#daily_pass").change(function() {
					if ( $('#daily_pass').is(':checked')) { $(".daily_pass_detail").show(); }
					else { $(".daily_pass_detail").hide(); }
				});
				$("#subscription").change(function() {
					if ( $('#subscription').is(':checked')) { $(".subscription_detail").show(); }
					else { $(".subscription_detail").hide(); }
				});
				$("#accept_api_logins").change(function() {
					if ( $('#accept_api_logins').is(':checked')) { $(".api_detail").show(); }
					else { $(".api_detail").hide(); }
				});
			});
		</script>
		<?php
	}

	/**
	 *	Customization Instructions
	 *  @since 1.4.0
	 */
	function customization() {

		?>
		<div class="wrap">
		<div class="icon32" style="margin:8px 0 0 8px"><img src="<?php echo $this->plugin_url . '/images/general.png'; ?>" /></div>
        <h2><?php _e('Customization', 'ppw'); ?></h2>
        <div id="poststuff" class="metabox-holder ppw-settings">


			<div class="postbox" id="ppw_instructions">
				<h3 class='hndle'><span><?php _e('Customization Examples', 'ppw'); ?></span></h3>
				<div class="inside">
				<?php
					_e('For protecting html codes that you cannot add to post content, there is a template function <b>wpmudev_ppw_html</b>. This function replace all such codes with payment buttons and reveal them when payment is done. Add the following codes to the page template where you want the html codes to be displayed and modify as required. Also you need to use the bottom action function.', 'ppw');
					?>
					<br />
					<code>
					&lt;?php<br />
					if ( function_exists( 'wpmudev_ppw_html' ) ) {<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$html = '&lt;iframe width="560" height="315" src="http://www.youtube.com/embed/-uiN9z5tqhg" frameborder="0" allowfullscreen&gt;&lt;/iframe&gt;'; // html code to be protected (required)<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$id = 1; // An optional unique id if you are using the function more than once on a single page<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$description = 'video'; // Optional description of the protected content<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$price = '1.50'; // Optional price for a single view. If not set, price set in the post will be applied. If that is not set either, Unit Price in the Global Settings will be used.<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;echo wpmudev_ppw_html( $html, $id, $description, $price );<br />
					}<br />
					?&gt;
					</code>
					<br />
					<?php
					_e('Note: In this usage, enabled/disabled and method settings of the post has no significance. Such an html code will be fully protected. However, Accessibility Settings will be applied.', 'ppw');
					?>
					<br />
					<br />
					<?php
					_e('Some custom post types use templates which take the post content directly from the database. For such applications you may need to use <b>wpmudev_ppw</b> function to manage the content.', 'ppw');
					?>
					<br />
					<?php
					_e('Example: Suppose that the content of a post type is displayed like this: <code>&lt;?php echo custom_description(); ?&gt;</code>. Then edit that part of the template like this:','ppw');
					?>
					<br />
					<code>
					&lt;?php<br />
					if ( function_exists( 'wpmudev_ppw' ) )<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;echo wpmudev_ppw( custom_description() );<br />
					else<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;echo custom_description();<br />
					?&gt;
					</code>
					<br />
					<br />
					<?php
					_e( 'For both of the above usages you <b>must</b> create a function in your functions.php to call necessary css and js files. Here is an example:', 'ppw');
					?>
					<br />
					<code>
					&lt;?php<br />
					function my_ppv_customization( ) {<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;global $ppw, $post; <br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;if ( !is_object( $ppw ) || !is_object( $post ) ) return;<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;// Call this only for a post/page with ID 123. Change as required.<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;// If you omit this line, js and style files will be added to all of your pages and caching will be disabled. So it is recommended to keep and modify it for the pages you are using.<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;if ( $post->ID != 123 ) return;<br />
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$ppw->load_scripts_styles();<br />
					}<br />
					add_action( 'template_redirect', 'my_ppv_customization', 2 );<br />
					?&gt;
					</code>
					<br />
					<br />
					<?php
					$uploads = wp_upload_dir();
					$default_css = "/wp-content/plugins/pay-per-view/css/front.css";
					$custom_css = "/wp-content/uploads/pay-per-view.css";
					printf(__('If you want to apply your own styles copy contents of front.css to your theme css file and add this code inside functions.php of your theme:<code>add_theme_support( "pay_per_view_style" )</code> OR copy and rename the default css file <b>%s</b> as <b>%s</b> and edit this latter file. Then, your edited styles will not be affected from plugin updates.', 'ppw'), $default_css, $custom_css);
					?>
					<br />
				</div>
			</div>

		</div>
		</div>

	<?php
	}

	/**
	 *	Get transaction records
	 *  Modified from Membership plugin by Barry
	 */
	function get_transactions($type, $startat, $num) {

		switch($type) {

			case 'past':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE transaction_status NOT IN ('Pending', 'Future') ORDER BY transaction_ID DESC  LIMIT %d, %d", $startat, $num );
						break;
			case 'pending':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE transaction_status IN ('Pending') ORDER BY transaction_ID DESC LIMIT %d, %d", $startat, $num );
						break;
			case 'future':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE transaction_status IN ('Future') ORDER BY transaction_ID DESC LIMIT %d, %d", $startat, $num );
						break;

		}

		return $this->db->get_results( $sql );

	}

	/**
	 *	Find if a Paypal transaction is duplicate or not
	 */
	function duplicate_transaction($user_id, $sub_id, $amount, $currency, $timestamp, $paypal_ID, $status, $note,$content=0) {
		$sql = $this->db->prepare( "SELECT transaction_ID FROM {$this->table} WHERE transaction_post_ID = %d && transaction_user_ID = %d && transaction_paypal_ID = %s && transaction_stamp = %d LIMIT 1 ", $sub_id, $user_id, $paypal_ID, $timestamp );

		$trans = $this->db->get_var( $sql );
		if(!empty($trans)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 *	Save a Paypal transaction to the database
	 */
	function record_transaction($user_id, $sub_id, $amount, $currency, $timestamp, $paypal_ID, $status, $note, $content=0) {

		$data = array();
		$data['transaction_post_ID'] = $sub_id; // Post ID
		$data['transaction_user_ID'] = $user_id;
		$data['transaction_paypal_ID'] = $paypal_ID;
		$data['transaction_stamp'] = $timestamp;
		$data['transaction_currency'] = $currency;
		$data['transaction_status'] = $status;
		$data['transaction_total_amount'] = (int) round($amount * 100);
		$data['transaction_note'] = $note;
		$data['transaction_gateway'] = "PayPal Express";
		$data['transaction_content_ID'] = $content;

		$existing_id = $this->db->get_var( $this->db->prepare( "SELECT transaction_ID FROM {$this->table} WHERE transaction_paypal_ID = %s LIMIT 1", $paypal_ID ) );

		if(!empty($existing_id)) {
			// Update
			$this->db->update( $this->table, $data, array('transaction_ID' => $existing_id) );
		} else {
			// Insert
			$this->db->insert( $this->table, $data );
		}

	}

	function get_total() {
		return $this->db->get_var( "SELECT FOUND_ROWS();" );
	}

	function transactions() {

		global $page, $action, $type;

		wp_reset_vars( array('type') );

		if(empty($type)) $type = 'past';

		?>
		<div class='wrap'>
			<div class="icon32" style="margin:8px 0 0 8px"><img src="<?php echo $this->plugin_url . '/images/transactions.png'; ?>" /></div>
			<h2><?php echo __(' Pay Per View Transactions','ppw'); ?></h2>

			<ul class="subsubsub">
				<li><a href="<?php echo add_query_arg('type', 'past'); ?>" class="rbutton <?php if($type == 'past') echo 'current'; ?>"><?php  _e('Recent transactions', 'ppw'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'pending'); ?>" class="rbutton <?php if($type == 'pending') echo 'current'; ?>"><?php  _e('Pending transactions', 'ppw'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'future'); ?>" class="rbutton <?php if($type == 'future') echo 'current'; ?>"><?php  _e('Future transactions', 'ppw'); ?></a></li>
			</ul>

			<?php
				$this->mytransactions($type);

			?>
		</div> <!-- wrap -->
		<?php

	}

	function mytransactions($type = 'past') {

		if(empty($_GET['paged'])) {
			$paged = 1;
		} else {
			$paged = ((int) $_GET['paged']);
		}

		$startat = ($paged - 1) * 50;

		$transactions = $this->get_transactions($type, $startat, 50);
		$total = $this->get_total();

		$columns = array();

		$columns['subscription'] = __('Post','ppw');
		$columns['user'] = __('User','ppw');
		$columns['date'] = __('Date/Time','ppw');
		$columns['expiry'] = __('Expiry Date','ppw');
		$columns['amount'] = __('Amount','ppw');
		$columns['transid'] = __('Transaction id','ppw');
		$columns['status'] = __('Status','ppw');

		$trans_navigation = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'total' => ceil($total / 50),
			'current' => $paged
		));

		echo '<div class="tablenav">';
		if ( $trans_navigation ) echo "<div class='tablenav-pages'>$trans_navigation</div>";
		echo '</div>';
		?>

			<table cellspacing="0" class="widefat fixed">
				<thead>
				<tr>
				<?php
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</thead>

				<tfoot>
				<tr>
				<?php
					reset($columns);
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</tfoot>

				<tbody>
					<?php
					if($transactions) {
						foreach($transactions as $key => $transaction) {
							?>
							<tr valign="middle" class="alternate">
								<td class="column-subscription">
									<?php
										$post = get_post($transaction->transaction_post_ID);
										if ( $post )
											echo "<a href='". get_permalink($post->ID)."' >". $post->post_title."</a>";
									?>
								</td>
								<td class="column-user">
									<?php
										$user_info = get_userdata($transaction->transaction_user_ID);
										if ( $user_info )
											echo $user_info->user_login;
									?>
								</td>
								<td class="column-date">
									<?php
										echo date( $this->datetime_format, $transaction->transaction_stamp );

									?>
								</td>
								<td class="column-expiry">
								<?php
								echo get_user_meta( $transaction->transaction_user_ID, 'ppw_subscribe', true );
								?>
								</td>
								<td class="column-amount">
									<?php
										$amount = $transaction->transaction_total_amount / 100;

										echo $transaction->transaction_currency;
										echo "&nbsp;" . number_format($amount, 2, '.', ',');
									?>
								</td>
								<td class="column-transid">
									<?php
										if(!empty($transaction->transaction_paypal_ID)) {
											echo $transaction->transaction_paypal_ID;
										} else {
											echo __('None yet','ppw');
										}
									?>
								</td>
								<td class="column-transid">
									<?php
										if(!empty($transaction->transaction_status)) {
											echo $transaction->transaction_status;
										} else {
											echo __('None yet','ppw');
										}
									?>
								</td>
							</tr>
							<?php
						}
					} else {
						$columncount = count($columns);
						?>
						<tr valign="middle" class="alternate" >
							<td colspan="<?php echo $columncount; ?>" scope="row"><?php _e('No Transactions have been found, patience is a virtue.','ppw'); ?></td>
						</tr>
						<?php
					}
					?>

				</tbody>
			</table>
		<?php
	}
	/**
	 *	Adds tinyMCE editor to the post editor
	 *  Modified from Password Protect Selected Content by Aaron Edwards
	 */
	function load_tinymce() {
    if ( (current_user_can('edit_posts') || current_user_can('edit_pages')) && get_user_option('rich_editing') == 'true') {
   		add_filter( 'mce_external_plugins', array(&$this, 'tinymce_add_plugin') );
			add_filter( 'mce_buttons', array(&$this,'tinymce_register_button') );
			add_filter( 'mce_external_languages', array(&$this,'tinymce_load_langs') );
		}
	}

	/**
	 * TinyMCE dialog content
	 */
	function tinymce_options() {
		?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
		<html>
			<head>
				<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
				<script type="text/javascript" src="../wp-includes/js/tinymce/tiny_mce_popup.js?ver=327-1235"></script>
				<script type="text/javascript" src="../wp-includes/js/tinymce/utils/form_utils.js?ver=327-1235"></script>
				<script type="text/javascript" src="../wp-includes/js/tinymce/utils/editable_selects.js?ver=327-1235"></script>

				<script type="text/javascript" src="../wp-includes/js/jquery/jquery.js"></script>

				<script type="text/javascript">


          tinyMCEPopup.storeSelection();

					var insertPayperview = function (ed) {
						var description = jQuery.trim(jQuery('#ppw-description').val());
						var price = jQuery.trim(jQuery('#ppw-price').val());
						if (!price) {
						  jQuery('#ppw-error').show();
						  jQuery('#ppw-price').focus();
						  return false;
						}
						// Create unique ID from Unix timestamp
						var id = Math.round((new Date()).getTime() / 1000) -1330955000;
						tinyMCEPopup.restoreSelection();
						output = '[ppw id="'+id+'" description="'+description+'" price="'+price+'"]'+tinyMCEPopup.editor.selection.getContent()+'[/ppw]';

						tinyMCEPopup.execCommand('mceInsertContent', 0, output);
						tinyMCEPopup.editor.execCommand('mceRepaint');
						tinyMCEPopup.editor.focus();
						// Return
						tinyMCEPopup.close();
					};
				</script>
				<style type="text/css">
				td.info {
					vertical-align: top;
					color: #777;
				}
				</style>

				<title><?php _e("Pay Per View", 'ppw'); ?></title>
			</head>
			<body style="display: none">

				<form onsubmit="insertPayperview();return false;" action="#">

					<div id="general_panel" class="panel current">
						<div id="ppw-error" style="display: none;color:#C00;padding: 2px 0;"><?php _e("Please enter a value!", 'ppw'); ?></div>
							<fieldset>
						  <table border="0" cellpadding="4" cellspacing="0">
								<tr>
									<td><label for="chat_width"><?php _e("Description", 'ppw'); ?></label></td>
									<td>
										<input type="text" id="ppw-description" name="ppw-description" value="" class="size" size="30" />
									</td>
									<td class="info"><?php _e("Description for this selection.", 'ppw'); ?></td>
								</tr>
								<tr>
									<td><label for="chat_width"><?php _e("Price", 'ppw'); ?></label></td>
									<td>
										<input type="text" id="ppw-price" name="ppw-price" value="" class="size" size="15" />
									</td>
									<td class="info"><?php _e("Price for this selection.", 'ppw'); ?></td>
								</tr>
							</table>
						</fieldset>
					</div>

					<div class="mceActionPanel">
						<div style="float: left">
							<input type="button" id="cancel" name="cancel" value="<?php _e("Cancel", 'ppw'); ?>" onclick="tinyMCEPopup.close();" />
						</div>

						<div style="float: right">
							<input type="submit" id="insert" name="insert" value="<?php _e("Insert", 'ppw'); ?>" />
						</div>
					</div>
				</form>
			</body>
		</html>
		<?php
		exit(0);
	}

	/**
	 * @see		http://codex.wordpress.org/TinyMCE_Custom_Buttons
	 */
	function tinymce_register_button($buttons) {
		array_push($buttons, "separator", "payperview");
		return $buttons;
	}

	/**
	 * @see		http://codex.wordpress.org/TinyMCE_Custom_Buttons
	 */
	function tinymce_load_langs($langs) {
		$langs["payperview"] =  plugins_url('pay-per-view/tinymce/langs/langs.php');
		return $langs;
	}

	/**
	 * @see		http://codex.wordpress.org/TinyMCE_Custom_Buttons
	 */
	function tinymce_add_plugin($plugin_array) {
		$plugin_array['payperview'] = plugins_url('pay-per-view/tinymce/editor_plugin.js');
		return $plugin_array;
	}

	/**
	 *	check if visitor is a bot
	 *
	 */
	function is_bot(){
		$botlist = array("Teoma", "alexa", "froogle", "Gigabot", "inktomi",
		"looksmart", "URL_Spider_SQL", "Firefly", "NationalDirectory",
		"Ask Jeeves", "TECNOSEEK", "InfoSeek", "WebFindBot", "girafabot",
		"crawler", "www.galaxy.com", "Googlebot", "Scooter", "Slurp",
		"msnbot", "appie", "FAST", "WebBug", "Spade", "ZyBorg", "rabaz",
		"Baiduspider", "Feedfetcher-Google", "TechnoratiSnoop", "Rankivabot",
		"Mediapartners-Google", "Sogou web spider", "WebAlta Crawler","TweetmemeBot",
		"Butterfly","Twitturls","Me.dium","Twiceler");

		foreach($botlist as $bot){
			if( strpos($_SERVER['HTTP_USER_AGENT'],$bot)!== false )
			return true;	// Is a bot
		}

		return false;	// Not a bot
	}
}
}


$ppw = new PayPerView() ;
global $ppw;

if ( !function_exists( 'wpmudev_ppw' ) ) {
	function wpmudev_ppw( $content='', $force=false ) {
		global $ppw, $post;
		if ( $content )
			return $ppw->content( $content, $force );
		else
			return $ppw->content( $post->post_content, $force );
	}
}

if ( !function_exists( 'wpmudev_ppw_html' ) ) {
	// since 1.4.0
	function wpmudev_ppw_html( $html, $id=1, $description='', $price=0 ) {
		global $ppw, $post;

		if ( $html )
			$content = $html;
		else if ( is_object( $post ) )
			$content = $post->content;
		else
			return 'No html code or post content found';

		// Find the price
		if ( !$price && is_object( $post ) )
			$price = get_post_meta( $post->ID, "ppw_price", true );
		if ( !$price )
			$price = $ppw->options["price"]; // Apply default price if it is not set for the post/page

		return $ppw->content( '[ppw id="'.$id.'" description="'.$description.'" price="'.$price.'"]'. $content . '[/ppw]', true, 'tool' );
	}
}

///////////////////////////////////////////////////////////////////////////
/* -------------------- WPMU DEV Dashboard Notice -------------------- */
if ( !class_exists('WPMUDEV_Dashboard_Notice') ) {
	class WPMUDEV_Dashboard_Notice {

		var $version = '2.0';

		function WPMUDEV_Dashboard_Notice() {
			add_action( 'plugins_loaded', array( &$this, 'init' ) );
		}

		function init() {
			if ( !class_exists( 'WPMUDEV_Update_Notifications' ) && current_user_can( 'install_plugins' ) && is_admin() ) {
				remove_action( 'admin_notices', 'wdp_un_check', 5 );
				remove_action( 'network_admin_notices', 'wdp_un_check', 5 );
				if ( file_exists(WP_PLUGIN_DIR . '/wpmudev-updates/update-notifications.php') ) {
					add_action( 'all_admin_notices', array( &$this, 'activate_notice' ), 5 );
				} else {
					add_action( 'all_admin_notices', array( &$this, 'install_notice' ), 5 );
					add_filter( 'plugins_api', array( &$this, 'filter_plugin_info' ), 10, 3 );
				}
			}
		}

		function filter_plugin_info($res, $action, $args) {
			global $wp_version;
			$cur_wp_version = preg_replace('/-.*$/', '', $wp_version);

			if ( $action == 'plugin_information' && strpos($args->slug, 'install_wpmudev_dash') !== false ) {
				$res = new stdClass;
				$res->name = 'WPMU DEV Dashboard';
				$res->slug = 'wpmu-dev-dashboard';
				$res->version = '';
				$res->rating = 100;
				$res->homepage = 'http://premium.wpmudev.org/project/wpmu-dev-dashboard/';
				$res->download_link = "http://premium.wpmudev.org/wdp-un.php?action=install_wpmudev_dash";
				$res->tested = $cur_wp_version;

				return $res;
			}

			return false;
		}

		function auto_install_url() {
			$function = is_multisite() ? 'network_admin_url' : 'admin_url';
			return wp_nonce_url($function("update.php?action=install-plugin&plugin=install_wpmudev_dash"), "install-plugin_install_wpmudev_dash");
		}

		function activate_url() {
			$function = is_multisite() ? 'network_admin_url' : 'admin_url';
			return wp_nonce_url($function('plugins.php?action=activate&plugin=wpmudev-updates%2Fupdate-notifications.php'), 'activate-plugin_wpmudev-updates/update-notifications.php');
		}

		function install_notice() {
			echo '<div class="error fade"><p>' . sprintf(__('Easily get updates, support, and one-click WPMU DEV plugin/theme installations right from in your dashboard - <strong><a href="%s" title="Install Now &raquo;">install the free WPMU DEV Dashboard plugin</a></strong>. &nbsp;&nbsp;&nbsp;<small><a href="http://premium.wpmudev.org/wpmu-dev/update-notifications-plugin-information/">(find out more)</a></small>', 'wpmudev'), $this->auto_install_url()) . '</a></p></div>';
		}

		function activate_notice() {
			echo '<div class="updated fade"><p>' . sprintf(__('Updates, Support, Premium Plugins, Community - <strong><a href="%s" title="Activate Now &raquo;">activate the WPMU DEV Dashboard plugin now</a></strong>.', 'wpmudev'), $this->activate_url()) . '</a></p></div>';
		}

	}
	new WPMUDEV_Dashboard_Notice();
}