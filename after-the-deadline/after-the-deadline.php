<?php
/*
   Plugin Name: TinyMCE Spellcheck
   Plugin URI:  http://www.afterthedeadline.com
   Description: Adds a contextual spell, style, and grammar checker to WordPress. Write better and spend less time editing. Visit <a href="profile.php">your profile</a> to configure. See the <a href="http://en.support.wordpress.com/proofreading/">Proofreading Support</a> page for help.
   Author:      Raphael Mudge (forked by Matthew Muro)
   Version:     1.0
   Author URI:  http://blog.afterthedeadline.com

   Credits:
   - API Key configuration code adapted from Akismet plugin
   - AtD_http_post adopted from Akismet...
*/

/*
 *  Make sure some useful constants are defined.  I'd say this is for pre-2.6 compatability but AtD requires WP 2.8+
 */
if ( ! defined( 'WP_CONTENT_URL' ) )
      define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
if ( ! defined( 'WP_CONTENT_DIR' ) )
      define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
if ( ! defined( 'WP_PLUGIN_URL' ) )
      define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
      define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

/*
 * post an admin message if fsockopen is disabled
 */
if ( !function_exists('fsockopen') ) {

        function AtD_warning_fsockopen() {
                echo "<div id='atd-warning-fsockopen' class='updated fade'><p><strong>" . __("After the Deadine can't connect to the proofreading service.</strong> Contact your system administrator and ask them to allow the use of <em>fsockopen</em> from PHP.", 'after-the-deadline') . "</p></div>";
        }
        add_action( 'admin_notices', 'AtD_warning_fsockopen' );
}

/*
 *  Load necessary include files
 */
include( 'config-options.php' );
include( 'config-unignore.php' );
include( 'utils.php' );
include( 'proxy.php' );

/*
 * Display the AtD configuration options (or not supported if the language id is not English [1])
 */
function AtD_config() {
	AtD_display_options_form();
	AtD_display_unignore_form();
}

/*
 *  Code to update the toolbar with the AtD Button and Install the AtD TinyMCE Plugin
 */
function AtD_addbuttons() {

	/* Don't bother doing this stuff if the current user lacks permissions */
	if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') )
		return;

	/* Add only in Rich Editor mode w/ Blog language ID set to English */
	if ( get_user_option('rich_editing') == 'true' ) {
		add_filter( 'mce_external_plugins', 'add_AtD_tinymce_plugin' );
		add_filter( 'mce_buttons', 'register_AtD_button' );
	}

	add_action( 'personal_options_update', 'AtD_process_options_update' );
	add_action( 'personal_options_update', 'AtD_process_unignore_update' );
	add_action( 'profile_personal_options', 'AtD_config' );
}

/*
 * Hook into the TinyMCE buttons and replace the current spellchecker
 */
function register_AtD_button( $buttons ) {

	/* kill the spellchecker.. don't need no steenkin PHP spell checker */
	foreach ( $buttons as $key => $button ) {
		if ( $button == 'spellchecker' ) {
			$buttons[$key] = 'AtD';
			return $buttons;
		}
	}

	/* hrm... ok add us last plz */
	array_push( $buttons, 'separator', 'AtD' );
	return $buttons;
}

/*
 * Load the TinyMCE plugin : editor_plugin.js (wp2.5)
 */
function add_AtD_tinymce_plugin( $plugin_array ) {
	$plugin_array['AtD'] = WP_PLUGIN_URL . '/after-the-deadline/tinymce/editor_plugin.js';
	return $plugin_array;
}

/*
 * Update the TinyMCE init block with AtD specific settings
 */
function AtD_change_mce_settings( $init_array ) {

        /* grab our user and validate their existence */
        $user = wp_get_current_user();
        if ( ! $user || $user->ID == 0 )
                return;

	$init_array['atd_rpc_url']        = admin_url() . 'admin-ajax.php?action=proxy_atd&url=';
	$init_array['atd_ignore_rpc_url'] = admin_url() . 'admin-ajax.php?action=atd_ignore&phrase=';
	$init_array['atd_rpc_id']         = 'WPORG-' . md5(get_bloginfo('wpurl'));
	$init_array['atd_theme']          = 'wordpress';
	$init_array['atd_ignore_enable']  = 'true';
	$init_array['atd_strip_on_get']   = 'true';
	$init_array['atd_ignore_strings'] = str_replace( '"', '', AtD_get_setting( $user->ID, 'AtD_ignored_phrases' ) );
	$init_array['atd_show_types']     = AtD_get_setting( $user->ID, 'AtD_options' );
	$init_array['gecko_spellcheck']   = 'false';

	return $init_array;
}

/*
 * Sanitizes AtD AJAX data to acceptable chars, caller needs to make sure ' is escaped
 */
function AtD_sanitize( $untrusted ) {
        return preg_replace( '/[^a-zA-Z0-9\-\',_ ]/i', "", $untrusted );
}

/*
 * AtD HTML Editor Stuff
 */

function AtD_settings() {
        $user = wp_get_current_user();

        header( 'Content-Type: text/javascript' );

        /* set the RPC URL for AtD */
	echo "AtD.rpc = '" . admin_url() . "admin-ajax.php?action=proxy_atd&url=';\n";

        /* set the API key for AtD */
        echo "AtD.api_key = 'WPORG-" . md5(get_bloginfo('wpurl')) . "';\n";

        /* set the ignored phrases for AtD */
        echo "AtD.setIgnoreStrings('" . str_replace( "'", "\\'", AtD_get_setting( $user->ID, 'AtD_ignored_phrases' ) ) . "');\n";

        /* honor the types we want to show */
        echo "AtD.showTypes('" . str_replace( "'", "\\'", AtD_get_setting( $user->ID, 'AtD_options' ) ) ."');\n";

        /* this is not an AtD/jQuery setting but I'm putting it in AtD to make it easy for the non-viz plugin to find it */
	echo "AtD.rpc_ignore = '" . admin_url() . "admin-ajax.php?action=atd_ignore&phrase=';\n";

        die;
}

function AtD_load_javascripts() {
        global $pagenow;

	if ( AtD_should_load_on_page() ) {
		wp_enqueue_script( 'AtD_core', WP_PLUGIN_URL . '/after-the-deadline/atd.core.js', array() );
	        wp_enqueue_script( 'AtD_quicktags', WP_PLUGIN_URL . '/after-the-deadline/atd-nonvis-editor-plugin.js', array('quicktags') );
        	wp_enqueue_script( 'AtD_jquery', WP_PLUGIN_URL . '/after-the-deadline/jquery.atd.js', array('jquery') );
        	wp_enqueue_script( 'AtD_settings', admin_url() . 'admin-ajax.php?action=atd_settings', array('AtD_jquery') );
		wp_enqueue_script( 'AtD_autoproofread', WP_PLUGIN_URL . '/after-the-deadline/atd-autoproofread.js', array('AtD_jquery') );
	}
}

/* Spits out user options for auto-proofreading on publish/update */
function AtD_load_submit_check_javascripts() {
	global $pagenow;

	$user = wp_get_current_user();
	if ( ! $user || $user->ID == 0 )
		return;

	if ( AtD_should_load_on_page() ) {
		$atd_check_when = AtD_get_setting( $user->ID, 'AtD_check_when' );
		if ($atd_check_when) {
			$check_when = '';
			/* Set up the options in json */
			foreach( explode( ',', $atd_check_when ) as $option ) {
				$check_when .= ($check_when ? ', ' : '') . $option . ': true';
			}
			echo '<script type="text/javascript">' . "\n";
			echo 'AtD_check_when = { ' . $check_when . ' };' . "\n";
			echo '</script>' . "\n";
		}
	}
}

function AtD_is_allowed() {
        $user = wp_get_current_user();
        if ( ! $user || $user->ID == 0 )
                return;

        if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') )
                return;

        return 1;
}

function AtD_load_css() {
	if ( AtD_should_load_on_page() )
	        wp_enqueue_style( 'AtD_style', WP_PLUGIN_URL . '/after-the-deadline/atd.css', null, '1.0', 'screen' );
}

/* Helper used to check if javascript should be added to page. Helps avoid bloat in admin */
function AtD_should_load_on_page() {
	global $pagenow;

	$pages = array('post.php', 'post-new.php', 'page.php', 'page-new.php', 'admin.php', 'profile.php');

	if( in_array($pagenow, $pages) )
		return true;

	return false;
}

function AtD_is_plugin_available( $plugin_slug = '', $plugin_name = '', $link_class = '', $link_id = '' ) {
	if ( empty( $plugin_slug ) )
		return;

	if ( empty( $plugin_name ) )
		$plugin_name = __( 'Activate Plugin', 'after-the-deadline' );

	$action = '';

	if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_slug ) ) {
		$plugins = get_plugins( '/' . $plugin_slug );

		if ( ! empty( $plugins ) ) {
			$keys = array_keys( $plugins );
			$plugin_file = $plugin_slug . '/' . $keys[0];
			$action = '<a 	id="' . esc_attr( $link_id ) . '"
							class="' . esc_attr( $link_class ) . '"
							href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . $plugin_file . '&from=plugins' ), 'activate-plugin_' . $plugin_file ) ) .
							'"title="' . esc_attr__( 'Activate Plugin', 'stats' ) . '"">' . esc_attr( $plugin_name ) . '</a>';
		}
	}

	if ( empty( $action ) && function_exists( 'is_main_site' ) && is_main_site() ) {
			$action = '<a 	id="' . esc_attr( $link_id ) . '"
							class="thickbox ' . esc_attr( $link_class ) . '"
							href="' . esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_slug .
							'&from=plugins&TB_iframe=true&width=600&height=550' ) ) . '" title="' .
							esc_attr__( 'Install Plugin', 'stats' ) . '">' . esc_attr( $plugin_name ) . '</a>';
	}

	return $action;
}

function AtD_link_plugin_meta( $links, $file ) {
	$plugin = plugin_basename( __FILE__ );

	// create link
	if ( $file == $plugin ) {
		if ( file_exists( WP_PLUGIN_DIR . '/jetpack' ) )
			$message = __( 'Enable Jetpack Now!', 'after-the-deadline' );
		else
			$message = __( 'Get Jetpack Now!', 'after-the-deadline' );

		$install_link = AtD_is_plugin_available( 'jetpack', $message );

		if ( empty( $install_link ) )
			$install_link = sprintf( '<a href="%1$s">%2$s</a>', 'http://downloads.wordpress.org/plugin/jetpack.latest-stable.zip', __( 'Get Jetpack Now!', 'after-the-deadline' ) );

		return array_merge(
							$links,
							array( $install_link )
						);
	}

	return $links;
}

function AtD_admin_styles() {
	wp_enqueue_style( 'jetpack-upgrade', plugins_url( '_inc/jetpack-upgrade.css', __FILE__ ), false, '20120601' );
	wp_enqueue_script( 'thickbox' );
	wp_enqueue_style( 'thickbox' );
}

function AtD_display_jetpack_nag() {
	static $shown = false;
	if ( $shown ) {
		return;
	}

	if ( ! class_exists( 'Jetpack' ) ) {
		$shown = true;

		if ( file_exists( WP_PLUGIN_DIR . '/jetpack' ) )
			$message = __( 'Enable Jetpack Now!', 'after-the-deadline' );
		else
			$message = __( 'Get Jetpack Now!', 'after-the-deadline' );

		$install_link = AtD_is_plugin_available( 'jetpack', $message, 'button-primary', 'wpcom-connect' );

		if ( empty( $install_link ) )
			$install_link = sprintf( '<a id="wpcom-connect" class="button-primary" href="%1$s">%2$s</a>', 'http://downloads.wordpress.org/plugin/jetpack.latest-stable.zip', __( 'Get Jetpack Now!', 'after-the-deadline' ) );

		?>

					<div id="message" class="updated jetpack-upgrade-message jpu-connect">
						<div class="squeezer">
							<h4>
								<?php printf( __( 'Future upgrades to After the Deadline will only be available in <a href="%1$s" target="_blank">Jetpack</a>. Jetpack connects your blog to the WordPress.com cloud, <a href="%2$s" target="_blank">enabling awesome features</a>.', 'after-the-deadline' ), 'http://jetpack.me/', 'http://jetpack.me/faq/' ); ?>
							</h4>

							<p class="submit"><?php echo $install_link ?></p>
						</div>
					</div>

		<?php
	}
}

if ( ! class_exists( 'Jetpack' ) ) {
	add_action( "admin_print_styles", 'AtD_admin_styles' );
	add_filter( 'plugin_row_meta'   , 'AtD_link_plugin_meta', 10, 2 );
}

/* add some vars into the AtD plugin */
add_filter( 'tiny_mce_before_init', 'AtD_change_mce_settings' );

/* load some stuff for non-visual editor */
add_action( 'admin_print_scripts', 'AtD_load_javascripts' );
add_action( 'admin_print_scripts', 'AtD_load_submit_check_javascripts' );
add_action( 'admin_print_styles', 'AtD_load_css' );

/* init process for button control */
add_action( 'init', 'AtD_addbuttons' );

/* setup hooks for our PHP functions we want to make available via an AJAX call */
add_action( 'wp_ajax_proxy_atd', 'AtD_redirect_call' );
add_action( 'wp_ajax_atd_ignore', 'AtD_ignore_call' );
add_action( 'wp_ajax_atd_settings', 'AtD_settings' );

/* load and install the localization stuff */
include( 'atd-l10n.php' );
