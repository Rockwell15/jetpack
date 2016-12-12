<?php

/*
 * Utility functions to generate data synced to wpcom
 */

class Jetpack_Sync_Functions {
	const HTTPS_CHECK_OPTION_PREFIX = 'jetpack_sync_https_history_';
	const HTTPS_CHECK_HISTORY = 5;

	public static function get_modules() {
		require_once( JETPACK__PLUGIN_DIR . 'class.jetpack-admin.php' );

		return Jetpack_Admin::init()->get_modules();
	}

	public static function get_taxonomies() {
		global $wp_taxonomies;

		return $wp_taxonomies;
	}

	public static function get_post_types() {
		global $wp_post_types;

		return $wp_post_types;
	}

	public static function get_post_type_features() {
		global $_wp_post_type_features;

		return $_wp_post_type_features;
	}

	public static function get_hosting_provider() {
		if ( defined( 'GD_SYSTEM_PLUGIN_DIR' ) || class_exists( '\\WPaaS\\Plugin' ) ) {
			return 'gd-managed-wp';
		}
		if ( defined( 'MM_BASE_DIR' ) ) {
			return 'bh';
		} 
		if ( defined( 'IS_PRESSABLE' ) ) {
			return 'pressable';
		} 
		if ( function_exists( 'is_wpe' ) || function_exists( 'is_wpe_snapshot' ) ) {
			return 'wpe';
		}
		return 'unknown';
	}

	public static function rest_api_allowed_post_types() {
		/** This filter is already documented in class.json-api-endpoints.php */
		return apply_filters( 'rest_api_allowed_post_types', array( 'post', 'page', 'revision' ) );
	}

	public static function rest_api_allowed_public_metadata() {
		/** This filter is documented in json-endpoints/class.wpcom-json-api-post-endpoint.php */
		return apply_filters( 'rest_api_allowed_public_metadata', array() );
	}

	/**
	 * Finds out if a site is using a version control system.
	 * @return bool
	 **/
	public static function is_version_controlled() {

		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
		}
		$updater = new WP_Automatic_Updater();

		return (bool) strval( $updater->is_vcs_checkout( $context = ABSPATH ) );
	}

	/**
	 * Returns true if the site has file write access false otherwise.
	 * @return bool
	 **/
	public static function file_system_write_access() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		require_once( ABSPATH . 'wp-admin/includes/template.php' );

		$filesystem_method = get_filesystem_method();
		if ( 'direct' === $filesystem_method  ) {
			return true;
		}

		ob_start();
		$filesystem_credentials_are_stored = request_filesystem_credentials( self_admin_url() );
		ob_end_clean();
		if ( $filesystem_credentials_are_stored ) {
			return true;
		}

		return false;
	}

	public static function home_url() {
		return self::get_protocol_normalized_url(
			'home_url',
			self::normalize_www_in_url( 'home', 'home_url' )
		);
	}

	public static function site_url() {
		return self::get_protocol_normalized_url(
			'site_url',
			self::normalize_www_in_url( 'siteurl', 'site_url' )
		);
	}

	public static function main_network_site_url() {
		return self::get_protocol_normalized_url( 'main_network_site_url', network_site_url() );
	}

	public static function get_protocol_normalized_url( $callable, $new_value ) {
		$option_key = self::HTTPS_CHECK_OPTION_PREFIX . $callable;

		$parsed_url = wp_parse_url( $new_value );
		if ( ! $parsed_url ) {
			return $new_value;
		}

		$scheme = $parsed_url['scheme'];
		$scheme_history = get_option( $option_key, array() );
		$scheme_history[] = $scheme;

		// Limit length to self::HTTPS_CHECK_HISTORY
		$scheme_history = array_slice( $scheme_history, ( self::HTTPS_CHECK_HISTORY * -1 ) );

		update_option( $option_key, $scheme_history );

		$forced_scheme =  in_array( 'https', $scheme_history ) ? 'https' : 'http';

		return set_url_scheme( $new_value, $forced_scheme );
	}

	public static function get_raw_url( $option_name ) {
		global $wpdb;

		$value = null;
		if ( 'home' == $option_name && Jetpack_Constants::is_defined( 'WP_HOME' ) ) {
			$value = Jetpack_Constants::get_constant( 'WP_HOME' );
		} else if ( 'siteurl' == $option_name && Jetpack_Constants::is_defined( 'WP_SITEURL' ) ) {
			$value = Jetpack_Constants::get_constant( 'WP_SITEURL' );
		} else {
			// Let's get the option from the database so that we can bypass filters. This will help
			// ensure that we get more uniform values.
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",
					$option_name
				)
			);
		}

		if ( is_ssl() ) {
			$scheme = 'https';
		} else {
			$scheme = parse_url( $value, PHP_URL_SCHEME );
		}

		$value = set_url_scheme( $value, $scheme );

		return $value;
	}

	public static function normalize_www_in_url( $option, $url_function ) {
		$url        = wp_parse_url( call_user_func( $url_function ) );
		$option_url = wp_parse_url( get_option( $option ) );

		if ( ! $option_url || ! $url ) {
			return $url;
		}

		if ( $url[ 'host' ] === "www.{$option_url[ 'host' ]}" ) {
			// remove www if not present in option URL
			$url[ 'host' ] = $option_url[ 'host' ];
		}
		if ( $option_url[ 'host' ] === "www.{$url[ 'host' ]}" ) {
			// add www if present in option URL
			$url[ 'host' ] = $option_url[ 'host' ];
		}

		$normalized_url = "{$url['scheme']}://{$url['host']}";
		if ( isset( $url['path'] ) ) {
			$normalized_url .= "{$url['path']}";
		}

		if ( isset( $url['query'] ) ) {
			$normalized_url .= "?{$url['query']}";
		}

		return $normalized_url;
	}

	public static function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		/** This filter is documented in wp-admin/includes/class-wp-plugins-list-table.php */
		return apply_filters( 'all_plugins', get_plugins() );
	}

	public static function wp_version() {
		global $wp_version;

		return $wp_version;
	}

	public static function site_icon_url() {
		if ( ! function_exists( 'get_site_icon_url' ) || ! has_site_icon() ) {
			return get_option( 'jetpack_site_icon_url' );
		}

		return get_site_icon_url();
	}
}
