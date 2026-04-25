<?php
/**
 * OAuth 2.1 MCP Request Interceptor.
 *
 * Validates Bearer tokens on incoming MCP REST API requests
 * and sets the WordPress current user accordingly.
 * Returns 401 with discovery metadata when no token is present.
 *
 * @package dizrupt-mcp-abilities
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Dizrupt_OAuth_Interceptor' ) ) {

	class Dizrupt_OAuth_Interceptor {

		public static function init(): void {
			add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate' ), 5 );
		}

		/**
		 * Authenticate MCP requests via Bearer token.
		 *
		 * @param WP_Error|null|true $result Existing authentication result.
		 * @return WP_Error|null|true
		 */
		public static function authenticate( $result ) {
			// Another auth mechanism already resolved — do not interfere.
			if ( null !== $result ) {
				return $result;
			}

			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

			// Only intercept MCP adapter namespace requests.
			if ( false === strpos( $request_uri, '/mcp/' ) ) {
				return $result;
			}

			// Do NOT intercept our own OAuth endpoints.
			if ( false !== strpos( $request_uri, '/dizrupt-auth/' ) ) {
				return $result;
			}

			$auth_header = self::get_authorization_header();

			// No Authorization header — check if already authenticated via another mechanism.
			if ( empty( $auth_header ) ) {
				if ( is_user_logged_in() ) {
					Dizrupt_OAuth_Server::send_cors_headers();
					return $result;
				}

				Dizrupt_OAuth_Server::send_cors_headers();
				header(
					sprintf(
						'WWW-Authenticate: Bearer resource_metadata="%s"',
						esc_url( home_url( '/.well-known/oauth-protected-resource' ) )
					)
				);
				return new WP_Error(
					'rest_not_logged_in',
					'Authentication required. Use OAuth 2.1 Bearer token.',
					array( 'status' => 401 )
				);
			}

			if ( 0 !== strpos( $auth_header, 'Bearer ' ) ) {
				return new WP_Error(
					'rest_invalid_auth',
					'Authorization header must use Bearer scheme.',
					array( 'status' => 401 )
				);
			}

			$token      = substr( $auth_header, 7 );
			$token_hash = Dizrupt_OAuth_DB::hash_token( $token );
			$token_row  = Dizrupt_OAuth_DB::get_token_by_access_hash( $token_hash );

			if ( ! $token_row ) {
				return new WP_Error(
					'rest_invalid_token',
					'Invalid or expired access token.',
					array( 'status' => 401 )
				);
			}

			// Set WordPress current user to the token owner.
			// Same pattern as Application Passwords (wp-includes/user.php).
			wp_set_current_user( (int) $token_row['user_id'] );

			// Send CORS headers early — before mcp-adapter processes the request.
			Dizrupt_OAuth_Server::send_cors_headers();

			return true;
		}

		/**
		 * Retrieve the Authorization header from multiple server configurations.
		 * Apache mod_php, mod_cgi, and nginx expose it differently.
		 */
		private static function get_authorization_header(): string {
			if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			}

			if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
			}

			if ( function_exists( 'apache_request_headers' ) ) {
				$headers = apache_request_headers();
				if ( is_array( $headers ) ) {
					foreach ( $headers as $key => $value ) {
						if ( 'authorization' === strtolower( $key ) ) {
							return sanitize_text_field( $value );
						}
					}
				}
			}

			return '';
		}
	}
}
