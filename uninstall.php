<?php
/**
 * Uninstall LLM OAuth Connector
 *
 * Fired when the plugin is uninstalled. Removes options only (no user data).
 * Transients expire automatically; tokens become invalid when options are removed.
 *
 * @package LLM_OAuth_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mo_llm_oauth_client_id' );
delete_option( 'mo_llm_oauth_client_secret' );
