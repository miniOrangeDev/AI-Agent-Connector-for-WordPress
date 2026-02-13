<?php
/**
 * Plugin Name: LLM OAuth Connector
 * Plugin URI:  https://miniorange.com/
 * Description: A lightweight, standalone OAuth 2.0/2.1 Authorization Code Grant server with PKCE support for LLMs like Cursor and ChatGPT.
 * Version:     0.1.0
 * Author:      miniOrange
 * License:     Expat
 * License URI: https://plugins.miniorange.com/mit-license
 * Text Domain: llm-oauth-connector
 *
 * @package llm-oauth-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LLM OAuth Connector class.
 */
class MO_LLM_OAuth_Connector {

	/**
	 * Namespace for the REST API.
	 *
	 * @var string
	 */
	private $namespace = 'llm-oauth/v1';

	/**
	 * Constructor for LLM OAuth Connector.
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'mo_llm_activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'mo_llm_deactivate' ) );

		add_action( 'init', array( $this, 'mo_llm_add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'mo_llm_register_query_vars' ) );

		add_action( 'parse_request', array( $this, 'mo_llm_handle_discovery_endpoint' ), 0 );

		add_action( 'template_redirect', array( $this, 'mo_llm_handle_authorize_flow' ) );

		add_action( 'rest_api_init', array( $this, 'mo_llm_register_token_endpoint' ) );

		add_filter( 'determine_current_user', array( $this, 'mo_llm_authenticate_request' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'mo_llm_check_auth_errors' ) );

		add_action( 'admin_menu', array( $this, 'mo_llm_add_admin_menu' ) );

		add_action(
			'rest_api_init',
			function () {
				add_filter( 'rest_pre_dispatch', array( $this, 'mo_llm_rest_pre_dispatch' ), 10, 1 );
			}
		);

		add_filter( 'login_redirect', array( $this, 'mo_llm_handle_login_redirect' ), 10 );

		add_filter( 'admin_email_check_interval', '__return_false' );
	}

	/**
	 * Handle login redirect.
	 *
	 * @param string $redirect_to The redirect URL.
	 * @return string The redirect URL.
	 */
	public function mo_llm_handle_login_redirect( $redirect_to ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_REQUEST['redirect_to'] is validated by wp_validate_redirect().
		if ( empty( $_REQUEST['redirect_to'] ) ) {
			return $redirect_to;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- $_REQUEST['redirect_to'] is validated by wp_validate_redirect().
		$target = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		if ( ! $target || strpos( $target, 'llm-oauth/authorize' ) === false ) {
			return $redirect_to;
		}
		$target_allowed = wp_validate_redirect( $target, $redirect_to );
		if ( $target_allowed === $redirect_to ) {
			return $redirect_to;
		}
		return $target_allowed;
	}

	/**
	 * Handle REST pre-dispatch.
	 *
	 * @param mixed $result The result of the previous filter.
	 * @return mixed The result of the previous filter.
	 */
	public function mo_llm_rest_pre_dispatch( $result ) {
		if ( is_user_logged_in() ) {
			return $result;
		}

		$token = null;

		$auth_header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER['HTTP_AUTHORIZATION'] is validated by wp_unslash().
			$auth_header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] is validated by wp_unslash().
			$auth_header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( is_string( $auth_header ) && preg_match( '/Bearer\s+([A-Za-z0-9\-_.~+\/=]+)/', $auth_header, $m ) ) {
			$token = substr( $m[1], 0, 256 );
			$token = sanitize_text_field( $token );
		}

		if ( empty( $token ) ) {
			return $result;
		}
		$token_data = get_transient( 'llm_access_token_' . $token );

		if ( ! $token_data ) {
			return $result;
		}

		$uid = (int) $token_data['user_id'];

		if ( ! $uid ) {
			return $result;
		}

		wp_set_current_user( $uid );

		return $result;
	}

	/**
	 * Activate: Generate keys and ensure rewrites work immediately
	 */
	public function mo_llm_activate() {
		if ( ! get_option( 'mo_llm_oauth_client_id' ) ) {
			update_option( 'mo_llm_oauth_client_id', 'llm_ci_' . bin2hex( random_bytes( 16 ) ) );
			update_option( 'mo_llm_oauth_client_secret', 'llm_sk_' . bin2hex( random_bytes( 32 ) ) );
		}

		$this->mo_llm_add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate: Clean up rewrite rules
	 */
	public function mo_llm_deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Add Custom Rewrite Rules
	 */
	public function mo_llm_add_rewrite_rules() {
		add_rewrite_rule( '^llm-oauth/authorize/?$', 'index.php?llm_oauth=authorize', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?llm_oauth_discovery=1', 'top' );
	}

	/**
	 * Register the query vars so WordPress recognizes them
	 *
	 * @param array $vars The query vars.
	 * @return array The query vars.
	 */
	public function mo_llm_register_query_vars( $vars ) {
		$vars[] = 'llm_oauth';
		$vars[] = 'llm_oauth_discovery';
		return $vars;
	}

	/**
	 * Handle OAuth authorize request and consent UI.
	 */
	public function mo_llm_handle_authorize_flow() {
		if ( get_query_var( 'llm_oauth' ) !== 'authorize' ) {
			return;
		}

		if ( ! is_user_logged_in() ) {

			$path = '/llm-oauth/authorize';

			if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER['REQUEST_URI'] is validated by wp_unslash().
				$raw_path = wp_unslash( $_SERVER['REQUEST_URI'] );
				$path     = esc_url_raw( $raw_path );
			}

			$current_url = home_url( $path );
			$login_url   = wp_login_url( $current_url );

			wp_safe_redirect( $login_url );
			exit;
		}

		$client_id     = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$response_type = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : 'code';

		$code_challenge        = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) ) : '';

		$stored_id = get_option( 'mo_llm_oauth_client_id' );

		if ( $client_id !== $stored_id ) {
			wp_die( 'Invalid request.', 'OAuth Error', array( 'response' => 400 ) );
		}

		if ( 'code' !== $response_type ) {
			wp_die( 'Invalid request.', 'OAuth Error', array( 'response' => 400 ) );
		}

		if ( empty( $redirect_uri ) ) {
			wp_die( 'Invalid request.', 'OAuth Error', array( 'response' => 400 ) );
		}

		if ( ! empty( $code_challenge ) ) {
			if ( empty( $code_challenge_method ) || 'S256' !== $code_challenge_method ) {
				wp_die( 'Invalid request.', 'OAuth Error', array( 'response' => 400 ) );
			}
		}

		if ( isset( $_POST['llm_oauth_approve'] ) && check_admin_referer( 'llm_oauth_approve' ) ) {
			$code    = bin2hex( random_bytes( 20 ) );
			$user_id = get_current_user_id();

			$code_data = array(
				'user_id'      => $user_id,
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
				'created_at'   => time(),
			);

			if ( ! empty( $code_challenge ) ) {
				$code_data['code_challenge']        = $code_challenge;
				$code_data['code_challenge_method'] = $code_challenge_method;
			}

			set_transient( 'llm_code_' . $code, $code_data, 5 * 60 );

			$redirect_to = add_query_arg( 'code', $code, $redirect_uri );

			if ( isset( $_GET['state'] ) ) {
				$redirect_to = add_query_arg( 'state', sanitize_text_field( wp_unslash( $_GET['state'] ) ), $redirect_to );
			}

            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth redirect required (ChatGPT PKCE flow)
			wp_redirect( $redirect_to );
			exit;
		}

		?>
		<!DOCTYPE html>
		<html>
		<head>
			<title>Authorize LLM</title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: #f0f0f1; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
				.card { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
				h2 { margin-top: 0; color: #1d2327; }
				.btn { display: inline-block; text-decoration: none; font-size: 14px; line-height: 2; height: 32px; padding: 0 12px; cursor: pointer; border-width: 1px; border-style: solid; -webkit-appearance: none; border-radius: 3px; white-space: nowrap; box-sizing: border-box; }
				.btn-primary { background: #2271b1; border-color: #2271b1; color: #fff; width: 100%; margin-top: 20px; font-size: 16px; height: 40px; }
				.btn-primary:hover { background: #135e96; border-color: #135e96; }
				.user-info { background: #f6f7f7; padding: 10px; border-radius: 4px; margin: 20px 0; }
			</style>
		</head>
		<body>
			<div class="card">
				<h2>Authorize LLM Access</h2>
				<p>LLM is requesting access to your WordPress site.</p>
				<div class="user-info">
					Logged in as: <strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong>
				</div>
				<form method="post">
					<?php wp_nonce_field( 'llm_oauth_approve' ); ?>
					<button type="submit" name="llm_oauth_approve" value="1" class="btn btn-primary">Authorize Access</button>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Register token endpoint. Route: POST /wp-json/llm-oauth/v1/token
	 */
	public function mo_llm_register_token_endpoint() {
		register_rest_route(
			$this->namespace,
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mo_llm_handle_token_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle discovery endpoint.
	 */
	public function mo_llm_handle_discovery_endpoint() {
		$uri = '';

		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		if ( false === strpos( $uri, '.well-known/oauth-authorization-server' ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-cache' );

		echo wp_json_encode(
			array(
				'issuer'                                => rtrim( home_url(), '/' ),
				'authorization_endpoint'                => home_url( '/llm-oauth/authorize' ),
				'token_endpoint'                        => rest_url( 'llm-oauth/v1/token' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
				'authorization_response_iss_parameter_supported' => true,
				'scopes_supported'                      => array( 'basic' ),
			)
		);
		exit;
	}

	/**
	 * Handle token exchange (authorization_code and refresh_token grants).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error Token response or error.
	 */
	public function mo_llm_handle_token_request( $request ) {
		$params = $request->get_params();

		$client_id     = isset( $params['client_id'] ) ? sanitize_text_field( $params['client_id'] ) : '';
		$client_secret = isset( $params['client_secret'] ) ? sanitize_text_field( $params['client_secret'] ) : '';
		$code          = isset( $params['code'] ) ? sanitize_text_field( $params['code'] ) : '';
		$grant_type    = isset( $params['grant_type'] ) ? sanitize_text_field( $params['grant_type'] ) : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? esc_url_raw( $params['redirect_uri'] ) : '';
		$code_verifier = isset( $params['code_verifier'] ) ? sanitize_text_field( $params['code_verifier'] ) : '';

		$stored_id     = get_option( 'mo_llm_oauth_client_id' );
		$stored_secret = get_option( 'mo_llm_oauth_client_secret' );

		if ( $client_id !== $stored_id ) {
			return new WP_Error( 'invalid_client', 'Invalid request.', array( 'status' => 401 ) );
		}

		if ( 'refresh_token' === $grant_type ) {

			$refresh_token = isset( $params['refresh_token'] ) ? sanitize_text_field( $params['refresh_token'] ) : '';

			if ( empty( $refresh_token ) ) {
				return new WP_Error( 'invalid_request', 'Missing refresh_token', array( 'status' => 400 ) );
			}

			$stored = get_transient( 'llm_refresh_token_' . $refresh_token );

			if ( ! $stored ) {
				return new WP_Error( 'invalid_grant', 'Invalid refresh token', array( 'status' => 400 ) );
			}

			$user_id   = $stored['user_id'];
			$client_id = $stored['client_id'];

			delete_transient( 'llm_refresh_token_' . $refresh_token );

			$access_token      = bin2hex( random_bytes( 40 ) );
			$refresh_token_new = bin2hex( random_bytes( 40 ) );

			set_transient(
				'llm_access_token_' . $access_token,
				array(
					'user_id'    => $user_id,
					'client_id'  => $client_id,
					'created_at' => time(),
				),
				86400
			);

			set_transient(
				'llm_refresh_token_' . $refresh_token_new,
				array(
					'user_id'    => $user_id,
					'client_id'  => $client_id,
					'created_at' => time(),
				),
				30 * 24 * 60 * 60
			);

			return array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => 86400,
				'refresh_token' => $refresh_token_new,
				'scope'         => 'basic',
			);
		}
		if ( 'authorization_code' !== $grant_type ) {
			return new WP_Error( 'unsupported_grant_type', 'Unsupported grant type', array( 'status' => 400 ) );
		}

		$code_data = get_transient( 'llm_code_' . $code );

		if ( ! $code_data ) {
			return new WP_Error( 'invalid_code', 'Authorization code is invalid or expired', array( 'status' => 400 ) );
		}

		if ( empty( $redirect_uri ) || rtrim( $redirect_uri, '/' ) !== rtrim( $code_data['redirect_uri'], '/' ) ) {
			return new WP_Error( 'invalid_grant', 'Redirect URI mismatch', array( 'status' => 400 ) );
		}

		if ( isset( $code_data['code_challenge'] ) ) {
			if ( empty( $code_verifier ) ) {
				return new WP_Error( 'invalid_request', 'code_verifier is required', array( 'status' => 400 ) );
			}

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for PKCE S256 per RFC 7636
			$computed_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

			if ( $computed_challenge !== $code_data['code_challenge'] ) {
				return new WP_Error( 'invalid_grant', 'PKCE verification failed', array( 'status' => 400 ) );
			}
		} elseif ( empty( $client_secret ) || $client_secret !== $stored_secret ) {
				return new WP_Error( 'invalid_client', 'Invalid request.', array( 'status' => 401 ) );
		}

		$access_token  = bin2hex( random_bytes( 40 ) );
		$refresh_token = bin2hex( random_bytes( 40 ) );
		$user_id       = $code_data['user_id'];

		set_transient(
			'llm_access_token_' . $access_token,
			array(
				'user_id'    => $user_id,
				'client_id'  => $client_id,
				'created_at' => time(),
			),
			24 * 60 * 60
		);

		set_transient(
			'llm_refresh_token_' . $refresh_token,
			array(
				'user_id'    => $user_id,
				'client_id'  => $client_id,
				'created_at' => time(),
			),
			30 * 24 * 60 * 60
		);

		delete_transient( 'llm_code_' . $code );

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => 86400,
			'refresh_token' => $refresh_token,
			'scope'         => 'basic',
		);
	}

	/**
	 * Authenticate REST requests via Bearer token.
	 *
	 * @param int|false $user_id Current user ID from previous authentication.
	 * @return int|false User ID if token valid, else passed $user_id.
	 */
	public function mo_llm_authenticate_request( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$token = null;

		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field(
				wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] )
			);
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field(
				wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
			);
		}

		if ( $auth_header && preg_match( '/Bearer\s+([A-Za-z0-9\-_.~+\/=]+)/', $auth_header, $m ) ) {
			$token = substr( $m[1], 0, 256 );
		}

		if ( empty( $token ) ) {
			return $user_id;
		}
		$token_data = get_transient( 'llm_access_token_' . $token );

		if ( ! $token_data || empty( $token_data['user_id'] ) ) {
			return $user_id;
		}

		$uid = (int) $token_data['user_id'];

		wp_set_current_user( $uid );

		return $uid;
	}

	/**
	 * REST auth errors callback (no-op; errors handled in authenticate_request).
	 *
	 * @param WP_Error|mixed $result Result from previous filter.
	 * @return WP_Error|mixed
	 */
	public function mo_llm_check_auth_errors( $result ) {
		return $result;
	}

	/**
	 * Register admin settings page.
	 */
	public function mo_llm_add_admin_menu() {
		add_options_page( 'LLM OAuth Connector', 'LLM OAuth Connector', 'manage_options', 'llm-oauth-connector', array( $this, 'mo_llm_render_admin_page' ) );
	}

	/**
	 * Render admin settings page ( client credentials).
	 */
	public function mo_llm_render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'llm-oauth-connector' ), '', array( 'response' => 403 ) );
		}

		$client_id     = get_option( 'mo_llm_oauth_client_id' );
		$client_secret = get_option( 'mo_llm_oauth_client_secret' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'LLM OAuth Connector', 'llm-oauth-connector' ); ?></h1>
			<div class="notice notice-warning inline">
				<p><strong><?php echo esc_html__( 'Important:', 'llm-oauth-connector' ); ?></strong> <?php echo esc_html__( 'If the Authorization URL below gives a 404 error, go to', 'llm-oauth-connector' ); ?> <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php echo esc_html__( 'Settings &rarr; Permalinks', 'llm-oauth-connector' ); ?></a> <?php echo esc_html__( 'and click "Save Changes" to refresh the rewrite rules.', 'llm-oauth-connector' ); ?></p>
			</div>
			
			<div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">

				<h2><?php echo esc_html__( 'Client Credentials', 'llm-oauth-connector' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Client ID', 'llm-oauth-connector' ); ?></th>
						<td>
							<code style="background:#f0f0f0; padding: 8px 12px; border-radius: 3px; display: inline-block; font-size: 13px; word-break: break-all;"><?php echo esc_html( $client_id ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Client Secret', 'llm-oauth-connector' ); ?></th>
						<td>
							<code style="background:#f0f0f0; padding: 8px 12px; border-radius: 3px; display: inline-block; font-size: 13px; word-break: break-all;"><?php echo esc_html( $client_secret ); ?></code>
							<br>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
}

new MO_LLM_OAuth_Connector();