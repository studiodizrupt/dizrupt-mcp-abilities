<?php
/**
 * OAuth 2.1 Authorization Endpoint.
 *
 * Handles the authorization page display and form processing.
 * Validates client, redirect_uri, PKCE, user session, and generates
 * authorization codes.
 *
 * @package dizrupt-mcp-abilities
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Dizrupt_OAuth_Authorize' ) ) {

	class Dizrupt_OAuth_Authorize {

		/**
		 * GET /authorize — validate params, check login, render consent page.
		 */
		public static function handle_get( WP_REST_Request $request ) {
			$params = self::extract_params( $request );

			if ( 'code' !== $params['response_type'] ) {
				return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.', array( 'status' => 400 ) );
			}

			$client = Dizrupt_OAuth_DB::get_client_by_id( $params['client_id'] );
			if ( ! $client ) {
				return new WP_Error( 'invalid_client', 'Unknown client_id.', array( 'status' => 400 ) );
			}

			if ( ! in_array( $params['redirect_uri'], $client['redirect_uris'], true ) ) {
				return new WP_Error( 'invalid_redirect_uri', 'redirect_uri does not match registered URIs.', array( 'status' => 400 ) );
			}

			if ( 'S256' !== $params['code_challenge_method'] || empty( $params['code_challenge'] ) ) {
				return new WP_Error( 'invalid_request', 'PKCE with S256 is required.', array( 'status' => 400 ) );
			}

			// WordPress REST API strips cookie auth when there is no wp_rest nonce.
			// Restore user from logged_in cookie for this browser-based redirect flow.
			self::restore_user_from_cookie();

			if ( ! is_user_logged_in() ) {
				$current_url = rest_url( 'dizrupt-auth/v1/authorize' ) . '?' . http_build_query( $params );
				wp_safe_redirect( wp_login_url( $current_url ) );
				exit;
			}

			self::render_authorize_page( $client, $params );
			exit;
		}

		/**
		 * POST /authorize — process form submission (approve or deny).
		 */
		public static function handle_post( WP_REST_Request $request ) {
			self::restore_user_from_cookie();

			$nonce = sanitize_text_field( $request->get_param( 'dizrupt_oauth_nonce' ) ?? '' );
			if ( ! wp_verify_nonce( $nonce, 'dizrupt_oauth_authorize' ) ) {
				return new WP_Error( 'invalid_nonce', 'Security check failed.', array( 'status' => 403 ) );
			}

			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'unauthorized', 'User must be logged in.', array( 'status' => 401 ) );
			}

			$action                = sanitize_text_field( $request->get_param( 'action' ) ?? '' );
			$client_id             = sanitize_text_field( $request->get_param( 'client_id' ) ?? '' );
			$redirect_uri          = esc_url_raw( $request->get_param( 'redirect_uri' ) ?? '' );
			$scope                 = sanitize_text_field( $request->get_param( 'scope' ) ?? '' );
			$state                 = sanitize_text_field( $request->get_param( 'state' ) ?? '' );
			$code_challenge        = sanitize_text_field( $request->get_param( 'code_challenge' ) ?? '' );
			$code_challenge_method = sanitize_text_field( $request->get_param( 'code_challenge_method' ) ?? '' );

			$client = Dizrupt_OAuth_DB::get_client_by_id( $client_id );
			if ( ! $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
				return new WP_Error( 'invalid_client', 'Invalid client or redirect URI.', array( 'status' => 400 ) );
			}

			if ( 'authorize' !== $action ) {
				$deny_url = add_query_arg(
					array(
						'error'             => 'access_denied',
						'error_description' => 'User denied the authorization request.',
						'state'             => $state,
					),
					$redirect_uri
				);
				// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				wp_redirect( $deny_url );
				exit;
			}

			$code = Dizrupt_OAuth_DB::insert_code(
				array(
					'client_id'             => $client_id,
					'user_id'               => get_current_user_id(),
					'redirect_uri'          => $redirect_uri,
					'scope'                 => $scope,
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
				)
			);

			if ( ! $code ) {
				return new WP_Error( 'server_error', 'Could not generate authorization code.', array( 'status' => 500 ) );
			}

			$success_url = add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			wp_redirect( $success_url );
			exit;
		}

		/**
		 * Restore current user from logged_in cookie for browser-based OAuth flow.
		 * The REST API intentionally strips cookie auth when wp_rest nonce is absent (CSRF protection).
		 */
		private static function restore_user_from_cookie(): void {
			if ( is_user_logged_in() ) {
				return;
			}
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		private static function extract_params( WP_REST_Request $request ): array {
			return array(
				'response_type'         => sanitize_text_field( $request->get_param( 'response_type' ) ?? '' ),
				'client_id'             => sanitize_text_field( $request->get_param( 'client_id' ) ?? '' ),
				'redirect_uri'          => esc_url_raw( $request->get_param( 'redirect_uri' ) ?? '' ),
				'scope'                 => sanitize_text_field( $request->get_param( 'scope' ) ?? 'mcp:tools' ),
				'state'                 => sanitize_text_field( $request->get_param( 'state' ) ?? '' ),
				'code_challenge'        => sanitize_text_field( $request->get_param( 'code_challenge' ) ?? '' ),
				'code_challenge_method' => sanitize_text_field( $request->get_param( 'code_challenge_method' ) ?? '' ),
			);
		}

		private static function render_authorize_page( array $client, array $params ): void {
			$current_user = wp_get_current_user();
			$site_name    = get_bloginfo( 'name' );
			$site_icon    = get_site_icon_url( 64 );
			$nonce        = wp_create_nonce( 'dizrupt_oauth_authorize' );
			$form_action  = rest_url( 'dizrupt-auth/v1/authorize' );

			$scope_labels = array(
				'mcp:tools' => __( 'Use MCP tools to manage content', 'dizrupt-mcp-abilities' ),
				'mcp:read'  => __( 'Read content from your site', 'dizrupt-mcp-abilities' ),
				'mcp:write' => __( 'Create and modify content on your site', 'dizrupt-mcp-abilities' ),
			);

			$requested_scopes = array_map( 'trim', explode( ' ', $params['scope'] ) );

			Dizrupt_OAuth_Server::send_cors_headers();
			status_header( 200 );
			header( 'Content-Type: text/html; charset=utf-8' );

			?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( sprintf( __( 'Authorize %s', 'dizrupt-mcp-abilities' ), $client['client_name'] ) . ' — ' . $site_name ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( DIZRUPT_MCP_URL . 'assets/css/oauth-authorize.css?v=' . DIZRUPT_MCP_VERSION ); ?>" />
</head>
<body>
	<div class="dizrupt-oauth-card">
		<div class="dizrupt-oauth-header">
			<?php if ( $site_icon ) : ?>
				<img src="<?php echo esc_url( $site_icon ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" />
			<?php endif; ?>
			<h1><?php echo esc_html( $site_name ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: client application name */
					esc_html__( 'The application %s is requesting access to your site.', 'dizrupt-mcp-abilities' ),
					'<strong>' . esc_html( $client['client_name'] ) . '</strong>'
				);
				?>
			</p>
		</div>

		<div class="dizrupt-oauth-scopes">
			<h3><?php esc_html_e( 'Requested permissions', 'dizrupt-mcp-abilities' ); ?></h3>
			<ul>
				<?php foreach ( $requested_scopes as $scope ) : ?>
					<li><?php echo esc_html( $scope_labels[ $scope ] ?? $scope ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<p class="dizrupt-oauth-user">
			<?php
			printf(
				/* translators: %s: current user display name */
				esc_html__( 'Logged in as %s', 'dizrupt-mcp-abilities' ),
				'<strong>' . esc_html( $current_user->display_name ) . '</strong>'
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $form_action ); ?>">
			<input type="hidden" name="dizrupt_oauth_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $params['client_id'] ); ?>" />
			<input type="hidden" name="redirect_uri" value="<?php echo esc_url( $params['redirect_uri'] ); ?>" />
			<input type="hidden" name="scope" value="<?php echo esc_attr( $params['scope'] ); ?>" />
			<input type="hidden" name="state" value="<?php echo esc_attr( $params['state'] ); ?>" />
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $params['code_challenge'] ); ?>" />
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $params['code_challenge_method'] ); ?>" />

			<div class="dizrupt-oauth-actions">
				<button type="submit" name="action" value="deny" class="dizrupt-oauth-btn-deny">
					<?php esc_html_e( 'Deny', 'dizrupt-mcp-abilities' ); ?>
				</button>
				<button type="submit" name="action" value="authorize" class="dizrupt-oauth-btn-authorize">
					<?php esc_html_e( 'Authorize', 'dizrupt-mcp-abilities' ); ?>
				</button>
			</div>
		</form>
	</div>
</body>
</html>
			<?php
		}
	}
}
