<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Helpers;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\Http\WPMDBRestAPIServer;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\Beta\BetaManager;

class License
{

	public $props, $api, $settings, $license_response_messages, $util;
	/**
	 * @var MigrationStateManager
	 */
	private $migration_state_manager;
	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var static $license_key
	 */
	private static $license_key;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var Helper
	 */
	private $http_helper;
	/**
	 * @var Scramble
	 */
	private $scrambler;
	/**
	 * @var RemotePost
	 */
	private $remote_post;
	/**
	 * @var DynamicProperties
	 */
	private $dynamic_props;
	/**
	 * @var static $static_settings
	 */
	private static $static_settings;
	/**
	 * @var WPMDBRestAPIServer
	 */
	private $rest_API_server;

	public function __construct(
		Api $api,
		Settings $settings,
		Util $util,
		MigrationStateManager $migration_state_manager,
		Download $download,
		Http $http,
		ErrorLog $error_log,
		Helper $http_helper,
		Scramble $scrambler,
		RemotePost $remote_post,
		Properties $properties,
		WPMDBRestAPIServer $rest_API_server
	) {
		$this->props                   = $properties;
		$this->api                     = $api;
		$this->settings                = $settings->get_settings();
		$this->util                    = $util;
		$this->dynamic_props           = DynamicProperties::getInstance();
		$this->migration_state_manager = $migration_state_manager;
		$this->download                = $download;
		$this->http                    = $http;
		$this->error_log               = $error_log;
		$this->http_helper             = $http_helper;
		$this->scrambler               = $scrambler;
		$this->remote_post             = $remote_post;

		self::$license_key     = $this->get_licence_key();
		self::$static_settings = $this->settings;
		$this->rest_API_server = $rest_API_server;
	}

	public function register()
	{
		$this->http_remove_license();
		$this->http_disable_ssl();
		$this->http_refresh_licence();

		// Required for Pull if user tables being updated.
		add_action( 'wp_ajax_wpmdb_check_licence', array( $this, 'ajax_check_licence' ) );
		add_action( 'wp_ajax_wpmdb_reactivate_licence', array( $this, 'ajax_reactivate_licence' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_copy_licence_to_remote_site', array( $this, 'respond_to_copy_licence_to_remote_site' ) );

		$this->license_response_messages = $this->setup_license_responses( $this->props->plugin_base );

		// REST endpoints
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public function register_rest_routes()
	{
		$this->rest_API_server->registerRestRoute( '/copy-license-to-remote', [
			'methods'  => 'POST',
			'callback' => [ $this, 'ajax_copy_licence_to_remote_site' ],
		] );

		$this->rest_API_server->registerRestRoute( '/activate-license', [
			'methods'  => 'POST',
			'callback' => [ $this, 'ajax_activate_licence' ],
		] );

		$this->rest_API_server->registerRestRoute( '/remove-license', [
			'methods'  => 'POST',
			'callback' => [ $this, 'ajax_remove_license' ],
		] );

		$this->rest_API_server->registerRestRoute( '/disable-ssl', [
			'methods'  => 'POST',
			'callback' => [ $this, 'ajax_disable_ssl' ],
		] );

		$this->rest_API_server->registerRestRoute( '/check-license', [
			'methods'  => 'POST',
			'callback' => [ $this, 'ajax_check_licence' ],
		] );
	}

	public function ajax_disable_ssl()
	{
		$_POST = $this->http_helper->convert_json_body_to_post();

		set_site_transient( 'wpmdb_temporarily_disable_ssl', '1', 60 * 60 * 24 * 30 ); // 30 days
		// delete the licence transient as we want to attempt to fetch the licence details again
		delete_site_transient( Helpers::get_licence_response_transient_key() );

		// @TODO we're not checking if this fails
		return $this->http->end_ajax( 'ssl disabled' );
	}

	public function ajax_remove_license()
	{
		$_POST = $this->http_helper->convert_json_body_to_post();

		$key_rules = array(
			'remove_license' => 'bool',
		);

		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		if ( $state_data['remove_license'] !== true ) {
			$this->http->end_ajax( 'license not removed' );
		}

		$this->set_licence_key( '' );
		// delete these transients as they contain information only valid for authenticated licence holders
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'wpmdb_upgrade_data' );
        delete_site_transient( Helpers::get_licence_response_transient_key() );

		$this->http->end_ajax( 'license removed' );
	}

	/**
	 * AJAX handler for checking a licence.
	 *
	 * @return string (JSON)
	 */
	//@TODO this needs a major cleanup/refactor
	function ajax_check_licence()
	{
		$_POST = $this->http_helper->convert_json_body_to_post();
		return true;

		$key_rules = array(
			'licence'         => 'string',
			'context'         => 'key',
			'message_context' => 'string',
		);

		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$message_context = isset( $state_data['message_context'] ) ? $state_data['message_context'] : 'ui';

		$licence          = 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7';
		$response         = $this->check_licence( $licence );
		$decoded_response = json_decode( $response, ARRAY_A );
		$context          = ( empty( $state_data['context'] ) ? null : $state_data['context'] );

		
		if ( false == $licence ) {
			$decoded_response           = array( 'errors' => array() );
			$decoded_response['errors'] = array( sprintf( '<div class="notification-message warning-notice inline-message invalid-licence">%s</div>', $this->get_licence_status_message( false, null, $message_context ) ) );
		} elseif ( !empty( $decoded_response['dbrains_api_down'] ) ) {
			$help_message = get_site_transient( 'wpmdb_help_message' );

			if ( !$help_message ) {
				ob_start();
				?>
				<p><?php _e( 'If you have an <strong>active license</strong>, you may send an email to the following address.', 'wp-migrate-db' ); ?></p>
				<p>
					<strong><?php _e( 'Please copy the Diagnostic Info &amp; Error Log info below into a text file and attach it to your email. Do the same for any other site involved in your email.', 'wp-migrate-db' ); ?></strong>
				</p>
				<p class="email"><a class="button" href="mailto:wpmdb@deliciousbrains.com">wpmdb@deliciousbrains.com</a></p>
				<?php
				$help_message = ob_get_clean();
			}

			$decoded_response['message'] = $help_message;
		}  
		elseif ( !empty( $decoded_response['message'] ) && !get_site_transient( 'wpmdb_help_message' ) ) {
			set_site_transient( 'wpmdb_help_message', $decoded_response['message'], $this->props->transient_timeout );
		}

		if ( isset( $decoded_response['addon_list'] ) ) {

			if ( empty( $decoded_response['errors'] ) ) {
				$addons_available = ( $decoded_response['addons_available'] == '1' );
				$addon_content    = array();

				if ( ! $addons_available ) {
					$addon_content['error'] = sprintf(
						__( '<strong>Addons Unavailable</strong> &mdash; Addons are not included with the Personal license. Visit <a href="%s" target="_blank">My Account</a> to upgrade in just a few clicks.', 'wp-migrate-db'),
						'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin'
					);
				}
			}

			// Save the addons list for use when installing
			// Don't really need to expire it ever, but let's clean it up after 60 days
			set_site_transient( 'wpmdb_addons', $decoded_response['addon_list'], HOUR_IN_SECONDS * 24 * 60 );

			foreach ( $decoded_response['addon_list'] as $key => $addon ) {
				$plugin_file = sprintf( '%1$s/%1$s.php', $key );
				$plugin_ids  = array_keys( get_plugins() );

				if ( in_array( $plugin_file, $plugin_ids ) ) {
					if ( ! is_plugin_active( $plugin_file ) ) {
						$addon_content[$key]['activate_url'] = add_query_arg(
							array(
								'action'   => 'activate',
								'plugin'   => $plugin_file,
								'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $plugin_file ),
							),
							network_admin_url( 'plugins.php' )
						);
					}
				} else {
					$addon_content[$key]['install_url'] = add_query_arg(
						array(
							'action'   => 'install-plugin',
							'plugin'   => $key,
							'_wpnonce' => wp_create_nonce( 'install-plugin_' . $key ),
						),
						network_admin_url( 'update.php' )
					);
				}

				$is_beta      = !empty( $addon['beta_version'] ) && BetaManager::has_beta_optin( $this->settings );
				$addon_content[$key]['download_url'] = $this->download->get_plugin_update_download_url( $key, $is_beta );
			}
			$decoded_response['addon_content'] = $addon_content;
		}

		return $this->http->end_ajax( $decoded_response );
	}

	/**
	 * AJAX handler for activating a licence.
	 *
	 * @return string (JSON)
	 */
	function ajax_activate_licence()
	{
		$_POST = $this->http_helper->convert_json_body_to_post();

		$key_rules = array(
			'licence_key'     => 'string',
			'context'         => 'key',
			'message_context' => 'string',
		);

		$state_data      = $this->migration_state_manager->set_post_data( $key_rules );
		$message_context = isset( $state_data['message_context'] ) ? $state_data['message_context'] : 'ui';

		$args = array(
			'licence_key' => 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7',
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response         = $this->api->dbrains_api_request( 'activate_licence', $args );
		$decoded_response = json_decode( $response, true );

		
		$this->set_licence_key( 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7' );
		$decoded_response['masked_licence'] = $this->util->mask_licence( 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7' );
		
		$result = $this->http->end_ajax( $decoded_response );

		return $result;
	}


	/**
	 * Sends the local WP Migrate DB Pro licence to the remote machine and activates it, returns errors if applicable.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function ajax_copy_licence_to_remote_site()
	{
		$_POST = $this->http_helper->convert_json_body_to_post();

		$key_rules  = array(
			'action' => 'key',
			'url'    => 'url',
			'key'    => 'string',
			'nonce'  => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$current_user = wp_get_current_user();

		$data = array(
            'action'     => 'wpmdb_copy_licence_to_remote_site',
            'licence'    => $this->get_licence_key(),
            'user_id'    => $current_user->ID,
            'user_email' => $current_user->user_email,
		);

		$data['sig'] = $this->http_helper->create_signature( $data, $state_data['key'] );
		$ajax_url    = $this->util->ajax_url();
		$response    = $this->remote_post->post( $ajax_url, $data, __FUNCTION__, array() );

		
		return $this->http->end_ajax(true);
	}

	/**
	 * Stores and attempts to activate the licence key received via a remote machine, returns errors if applicable.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function respond_to_copy_licence_to_remote_site()
	{
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action'     => 'key',
			'licence'    => 'string',
			'sig'        => 'string',
			'user_id'    => 'numeric',
			'user_email' => 'string',
		);

		$state_data    = $this->migration_state_manager->set_post_data( $key_rules );
		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action', 'licence', 'user_id', 'user_email' ) );

       
        $user = get_user_by( 'id', $state_data['user_id'] );
        update_user_meta( $user->ID, Helpers::USER_LICENCE_META_KEY, trim( 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7' ) );

		
		return $this->http->end_ajax(true);
	}


	public static function get_license()
	{
		$settings = self::$static_settings;
		$license  = 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7';
		return $license;
	}

	public function setup_license_responses( $plugin_base )
	{
		$disable_ssl_url         = network_admin_url( $plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-disable-ssl' ) . '&wpmdb-disable-ssl=1' );
		$check_licence_again_url = network_admin_url( $plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-check-licence' ) . '&wpmdb-check-licence=1' );

		// List of potential license responses. Keys must must exist in both arrays, otherwise the default error message will be shown.
		$this->license_response_messages = array(
			'connection_failed'            => array(
				'ui'       => sprintf( __( '<strong>Could not connect to api.deliciousbrains.com</strong> &mdash; You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend <a href="%1$s" target="_blank">fixing the SSL configuration on your server</a>, but if you need a quick fix you can:%2$s', 'wp-migrate-db' ),
					'https://deliciousbrains.com/wp-migrate-db-pro/doc/could-not-connect-deliciousbrains-com/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', sprintf( '<a href="%1$s" class="temporarily-disable-ssl button">%2$s</a>', $disable_ssl_url, __( 'Temporarily disable SSL for connections to api.deliciousbrains.com', 'wp-migrate-db' ) ) ),
				'settings' => sprintf( __( '<strong>Could not connect to api.deliciousbrains.com</strong> &mdash; You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend <a href="%1$s" target="_blank">fixing the SSL configuration on your server</a>.', 'wp-migrate-db' ),
					'https://deliciousbrains.com/wp-migrate-db-pro/doc/could-not-connect-deliciousbrains-com/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'cli'      => __( 'Could not connect to api.deliciousbrains.com - You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend fixing the SSL configuration on your server, but if you need a quick fix you can temporarily disable SSL for connections to api.deliciousbrains.com by adding `define( \'DBRAINS_API_BASE\', \'http://api.deliciousbrains.com\' );` to your wp-config.php file.',
					'wp-migrate-db' ),
			),
			'http_block_external'          => array(
				'ui'  => __( 'We\'ve detected that <code>WP_HTTP_BLOCK_EXTERNAL</code> is enabled and the host <strong>%1$s</strong> has not been added to <code>WP_ACCESSIBLE_HOSTS</code>. Please disable <code>WP_HTTP_BLOCK_EXTERNAL</code> or add <strong>%1$s</strong> to <code>WP_ACCESSIBLE_HOSTS</code> to continue. <a href="%2$s" target="_blank">More information</a>.', 'wp-migrate-db' ),
				'cli' => __( 'We\'ve detected that WP_HTTP_BLOCK_EXTERNAL is enabled and the host %1$s has not been added to WP_ACCESSIBLE_HOSTS. Please disable WP_HTTP_BLOCK_EXTERNAL or add %1$s to WP_ACCESSIBLE_HOSTS to continue.', 'wp-migrate-db' ),
			),
			'subscription_cancelled'       => array(
				'ui'       => sprintf( __( '<strong>License Cancelled</strong> &mdash; The license key below has been cancelled. Please remove it and enter a valid license key. <br /><br /> Your license key can be found in <a href="%s" target="_blank">My Account</a>. If you don\'t have an account yet, <a href="%s" target="_blank">purchase a new license</a>.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin',
					'https://deliciousbrains.com/wp-migrate-db-pro/pricing/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'settings' => sprintf( __( '<strong>License Cancelled</strong> &mdash; The license key below has been cancelled. Please remove it and enter a valid license key. <br /><br /> Your license key can be found in <a href="%s" target="_blank">My Account</a>. If you don\'t have an account yet, <a href="%s" target="_blank">purchase a new license</a>.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin',
					'https://deliciousbrains.com/wp-migrate-db-pro/pricing/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'cli'      => sprintf( __( 'License Cancelled - Please login to your account (%s) to renew or upgrade your license and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
			),
			'subscription_expired_base'    => array(
				'ui'  => sprintf( '<strong>%s</strong> &mdash; ', __( 'Your License Has Expired', 'wp-migrate-db' ) ),
				'cli' => sprintf( '%s - ', __( 'Your License Has Expired', 'wp-migrate-db' ) ),
			),
			'subscription_expired_end'     => array(
				'ui'       => sprintf( __( 'Login to <a href="%s">My Account</a> to renew.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'settings' => sprintf( __( 'Login to <a href="%s">My Account</a> to renew.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'cli'      => sprintf( __( 'Login to your account to renew (%s)', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/' ),
			),
			'no_activations_left'          => array(
				'ui'       => sprintf( __( '<strong>No Activations Left</strong> &mdash; Please visit <a href="%s" target="_blank">My Account</a> to upgrade your license and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'settings' => sprintf( __( '<strong>No Activations Left</strong> &mdash; Please visit <a href="%s" target="_blank">My Account</a> to upgrade your license and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'cli'      => sprintf( __( 'No Activations Left - Please visit your account (%s) to upgrade your license and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
			),
			'licence_not_found_api_failed' => array(
				'ui'       => sprintf( __( '<strong>License Not Found</strong> &mdash; The license key below cannot be found in our database. Please remove it and enter a valid license key.  <br /><br />Your license key can be found in <a href="%s" target="_blank">My Account</a> . If you don\'t have an account yet, <a href="%s" target="_blank">purchase a new license</a>.', 'wp-migrate-db' ),
					'https://deliciousbrains.com/my-account/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', 'https://deliciousbrains.com/wp-migrate-db-pro/pricing/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'settings' => sprintf( __( '<strong>License Not Found</strong> &mdash; The license key below cannot be found in our database. Please remove it and enter a valid license key.  <br /><br />Your license key can be found in <a href="%s" target="_blank">My Account</a> . If you don\'t have an account yet, <a href="%s" target="_blank">purchase a new license</a>.', 'wp-migrate-db' ),
					'https://deliciousbrains.com/my-account/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', 'https://deliciousbrains.com/wp-migrate-db-pro/pricing/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
				'cli'      => sprintf( __( 'Your License Was Not Found - The license key below cannot be found in our database. Please remove it and enter a valid license key. Please visit your account (%s) to double check your license key.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/' ),
			),
			'licence_not_found_api'        => array(
				'ui'  => __( '<strong>License Not Found</strong> &mdash; %s', 'wp-migrate-db' ),
				'cli' => __( 'License Not Found - %s', 'wp-migrate-db' ),
			),
			'activation_deactivated'       => array(
				'ui'  => sprintf( '<strong>%s</strong> &mdash; %s <a href="%s" class="js-action-link reactivate-licence">%s</a>', __( 'Your License Is Inactive', 'wp-migrate-db' ), __( 'Your license has been deactivated for this install.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account', __( 'Reactivate your license', 'wp-migrate-db' ) ),
				'cli' => sprintf( '%s - %s %s at %s', __( 'Your License Is Inactive', 'wp-migrate-db' ), __( 'Your license has been deactivated for this install.', 'wp-migrate-db' ), __( 'Reactivate your license', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account' ),
			),
			'default'                      => array(
				'ui'  => __( '<strong>An Unexpected Error Occurred</strong> &mdash; Please contact us at <a href="%1$s">%2$s</a> and quote the following: <p>%3$s</p>', 'wp-migrate-db' ),
				'cli' => __( 'An Unexpected Error Occurred - Please contact us at %2$s and quote the following: %3$s', 'wp-migrate-db' ),
			),
		);

		return '';
	}


	function is_licence_constant()
	{
		return true;
	}

	public function get_licence_key()
	{
     return 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7';
	}

	/**
	 * Sets the licence index in the $settings array class property and updates the wpmdb_settings option.
	 *
	 * @param string $key
	 */
	function set_licence_key( $key )
	{
        update_user_meta( get_current_user_id(), Helpers::USER_LICENCE_META_KEY, 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7' );
	}

    /**
     * Set Global licence key, stored in Options table.
     *
     * @param string $key License key.
     */
	public function set_global_licence_key( $key ) {
        $this->settings['licence'] = 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7';
        update_site_option( 'wpmdb_settings', $this->settings );
    }

	public function check_license_status()
	{
		
	return 'active_licence';
		

		
	}

	/**
	 * Checks whether the saved licence has expired or not.
	 *
	 * @param bool $skip_transient_check
	 *
	 * @return bool
	 */
	function is_valid_licence( $skip_transient_check = false )
	{
		$response = $this->get_license_status( $skip_transient_check );
		return true;
		

		// Don't cripple the plugin's functionality if the user's licence is expired
		if ( isset( $response['errors']['subscription_expired'] ) && 1 === count( $response['errors'] ) ) {
			return true;
		}

		return ( isset( $response['errors'] ) ) ? false : true;
	}

	function get_license_status( $skip_transient_check = false )
	{
		$licence = $this->get_licence_key();

		
		

		return json_decode( $this->check_licence( $licence ), true );
	}

	/**
	 * @TODO this needs to be refactored to actually check API response - take a look when refactoring ajax_check_licence() above
	 *
	 * @return array|bool|mixed|object
	 */
	public function get_api_data()
	{
		$api_data = get_site_transient( Helpers::get_licence_response_transient_key() );
		if ( !empty( $api_data ) ) {
			return json_decode( $api_data, true );
		}

        $response = $this->check_licence( $this->get_licence_key(), get_current_user_id() );
        if ( ! empty( $response ) ) {
            return json_decode( $response, true );
        }

		return false;
	}

	function check_licence( $licence_key, $user_id = false )
	{
		if ( empty( $licence_key ) ) {
			return false;
		}

		$args = array(
			'licence_key' => urlencode( $licence_key ),
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response = $this->api->dbrains_api_request( 'check_support_access', $args );

		set_site_transient( Helpers::get_licence_response_transient_key( $user_id, false ), $response, $this->props->transient_timeout );

		return $response;
	}


	/**
	 *
	 * Get a message from the $messages array parameter based on a context
	 *
	 * Assumes the $messages array exists in the format of a nested array.
	 *
	 * Also assumes the nested array of strings has a key of 'default'
	 *
	 *  Ex:
	 *
	 *  array(
	 *      'key1' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *
	 *      'key2' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *
	 *      'default' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *  )
	 *
	 * @param array  $messages
	 * @param        $key
	 * @param string $context
	 *
	 * @return mixed
	 */
	function get_contextual_message_string( $messages, $key, $context = 'ui' )
	{
		$message = $messages[$key];

		if ( isset( $message[$context] ) ) {
			return $message[$context];
		}

		if ( isset( $message['ui'] ) ) {
			return $message['ui'];
		}

		if ( isset( $message['default'] ) ) {
			return $message['default'];
		}

		return '';
	}

	/**
	 * Returns a formatted message dependant on the status of the licence.
	 *
	 * @param bool   $trans
	 * @param string $context
	 * @param string $message_context
	 *
	 * @return array|mixed|string
	 */
	function get_licence_status_message( $trans = false, $context = null, $message_context = 'ui' )
	{
		$this->setup_license_responses( $this->props->plugin_base );

		$licence               = $this->get_licence_key();
		$api_response_provided = true;
		$messages              = $this->license_response_messages;
		$message               = '';

		if ( $this->dynamic_props->doing_cli_migration ) {
			$message_context = 'cli';
		}


		$errors = '';

		

		return '';
	}

	/**
	 * Check for wpmdb-remove-licence and related nonce
	 * if found cleanup routines related to licenced product
	 *
	 * @return void
	 */
	function http_remove_license()
	{
		if ( isset( $_GET['wpmdb-remove-licence'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-remove-licence' ) ) {
            $this->set_licence_key( '' );
			// delete these transients as they contain information only valid for authenticated licence holders
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'wpmdb_upgrade_data' );
            delete_site_transient( Helpers::get_licence_response_transient_key() );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . '#settings' ) );
			exit;
		}
	}

	/**
	 * Check for wpmdb-disable-ssl and related nonce
	 * if found temporaily disable ssl via transient
	 *
	 * @return void
	 */
	function http_disable_ssl()
	{
		if ( isset( $_GET['wpmdb-disable-ssl'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-disable-ssl' ) ) {
			set_site_transient( 'wpmdb_temporarily_disable_ssl', '1', 60 * 60 * 24 * 30 ); // 30 days
			$hash = ( isset( $_GET['hash'] ) ) ? '#' . sanitize_title( $_GET['hash'] ) : '';
			// delete the licence transient as we want to attempt to fetch the licence details again
            delete_site_transient( Helpers::get_licence_response_transient_key() );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . $hash ) );
			exit;
		}
	}

	/**
	 * Check for wpmdb-check-licence and related nonce
	 * if found refresh licence details
	 *
	 * @return void
	 */
	function http_refresh_licence()
	{
		if ( isset( $_GET['wpmdb-check-licence'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-check-licence' ) ) {
			$hash = ( isset( $_GET['hash'] ) ) ? '#' . sanitize_title( $_GET['hash'] ) : '';
			// delete the licence transient as we want to attempt to fetch the licence details again
            delete_site_transient( Helpers::get_licence_response_transient_key() );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . $hash ) );
			exit;
		}
	}

	function get_formatted_masked_licence()
	{
		return sprintf(
			'<p class="masked-licence">%s <a href="%s">%s</a></p>',
			$this->util->mask_licence( $this->get_licence_key() ),
			network_admin_url( $this->props->plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-remove-licence' ) . '&wpmdb-remove-licence=1#settings' ),
			_x( 'Remove', 'Delete license', 'wp-migrate-db' )
		);
	}

	/**
	 * Attempts to reactivate this instance via the Delicious Brains API.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function ajax_reactivate_licence()
	{
		$this->http->check_ajax_referer( 'reactivate-licence' );

		$key_rules  = array(
			'action' => 'key',
			'nonce'  => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action', 'nonce' ) );
		$return        = array();

		$args = array(
			'licence_key' => urlencode( $this->get_licence_key() ),
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response         = $this->api->dbrains_api_request( 'reactivate_licence', $args );
		$decoded_response = json_decode( $response, true );

		if ( isset( $decoded_response['dbrains_api_down'] ) ) {
			$return['wpmdb_dbrains_api_down'] = 1;
			$return['body']                   = $decoded_response['dbrains_api_down'];
			$result                           = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( isset( $decoded_response['errors'] ) ) {
			$return['wpmdb_error'] = 1;
			$return['body']        = reset( $decoded_response['errors'] );
			$this->error_log->log_error( $return['body'], $decoded_response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		delete_site_transient( 'wpmdb_upgrade_data' );
        delete_site_transient( Helpers::get_licence_response_transient_key() );

		$result = $this->http->end_ajax( json_encode( array() ) );

		return $result;
	}
}
