<?php
/**
 * OAuth 2.1 Token Endpoint.
 *
 * Handles authorization code exchange, refresh token flow,
 * token revocation, and PKCE verification.
 *
 * @package dizrupt-mcp-abilities
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Dizrupt_OAuth_Token' ) ) {

	class Dizrupt_OAuth_Token {

		public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$grant_type = sanitize_text_field( $request->get_param( 'grant_type' ) ?? '' );

			Dizrupt_OAuth_DB::cleanup_expired_codes();

			return match ( $grant_type ) {
				'authorization_code' => self::handle_authorization_code( $request ),
				'refresh_token'      => self::handle_refresh_token( $request ),
				default              => self::oauth_error( 'unsupported_grant_type', 'grant_type must be "authorization_code" or "refresh_token".' ),
			};
		}

		private static function handle_authorization_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$code          = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
			$redirect_uri  = esc_url_raw( $request->get_param( 'redirect_uri' ) ?? '' );
			$client_id     = sanitize_text_field( $request->get_param( 'client_id' ) ?? '' );
			$code_verifier = sanitize_text_field( $request->get_param( 'code_verifier' ) ?? '' );

			if ( empty( $code ) || empty( $client_id ) || empty( $code_verifier ) ) {
				return self::oauth_error( 'invalid_request', 'Missing required parameters: code, client_id, code_verifier.' );
			}

			// Generic error per RFC 6749 to prevent state enumeration.
			$grant_error = 'The provided authorization grant is invalid, expired, or revoked.';

			$code_row = Dizrupt_OAuth_DB::get_code( $code );
			if ( ! $code_row ) {
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			// If already used — revoke all tokens for this client (RFC 6749 §4.1.2).
			if ( 1 === (int) $code_row['used'] ) {
				Dizrupt_OAuth_DB::revoke_all_for_client( $code_row['client_id'] );
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			if ( strtotime( $code_row['expires_at'] ) < time() ) {
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			// Mark used immediately — single-use enforcement.
			Dizrupt_OAuth_DB::mark_code_used( $code );

			if ( ! hash_equals( $code_row['client_id'], $client_id ) ) {
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			if ( ! hash_equals( $code_row['redirect_uri'], $redirect_uri ) ) {
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			if ( ! self::verify_pkce( $code_verifier, $code_row['code_challenge'], $code_row['code_challenge_method'] ) ) {
				return self::oauth_error( 'invalid_grant', $grant_error );
			}

			$token_data = Dizrupt_OAuth_DB::insert_token(
				array(
					'client_id' => $client_id,
					'user_id'   => (int) $code_row['user_id'],
					'scope'     => $code_row['scope'],
				)
			);

			if ( ! $token_data ) {
				return self::oauth_error( 'server_error', 'Could not generate tokens.', 500 );
			}

			return new WP_REST_Response(
				array(
					'access_token'  => $token_data['access_token'],
					'token_type'    => 'Bearer',
					'expires_in'    => $token_data['expires_in'],
					'refresh_token' => $token_data['refresh_token'],
					'scope'         => $token_data['scope'],
				),
				200
			);
		}

		private static function handle_refresh_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$refresh_token = sanitize_text_field( $request->get_param( 'refresh_token' ) ?? '' );
			$client_id     = sanitize_text_field( $request->get_param( 'client_id' ) ?? '' );

			if ( empty( $refresh_token ) || empty( $client_id ) ) {
				return self::oauth_error( 'invalid_request', 'Missing required parameters: refresh_token, client_id.' );
			}

			$refresh_hash = Dizrupt_OAuth_DB::hash_token( $refresh_token );
			$token_row    = Dizrupt_OAuth_DB::get_token_by_refresh_hash( $refresh_hash );

			if ( ! $token_row ) {
				return self::oauth_error( 'invalid_grant', 'The provided refresh token is invalid, expired, or revoked.' );
			}

			if ( ! hash_equals( $token_row['client_id'], $client_id ) ) {
				return self::oauth_error( 'invalid_grant', 'The provided refresh token is invalid, expired, or revoked.' );
			}

			// Revoke old pair (rotation).
			Dizrupt_OAuth_DB::revoke_token_by_refresh_hash( $refresh_hash );

			$new_tokens = Dizrupt_OAuth_DB::insert_token(
				array(
					'client_id' => $client_id,
					'user_id'   => (int) $token_row['user_id'],
					'scope'     => $token_row['scope'],
				)
			);

			if ( ! $new_tokens ) {
				return self::oauth_error( 'server_error', 'Could not generate tokens.', 500 );
			}

			return new WP_REST_Response(
				array(
					'access_token'  => $new_tokens['access_token'],
					'token_type'    => 'Bearer',
					'expires_in'    => $new_tokens['expires_in'],
					'refresh_token' => $new_tokens['refresh_token'],
					'scope'         => $new_tokens['scope'],
				),
				200
			);
		}

		public static function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
			$token      = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
			$token_hint = sanitize_text_field( $request->get_param( 'token_type_hint' ) ?? '' );

			if ( empty( $token ) ) {
				return new WP_REST_Response( null, 200 );
			}

			$hash = Dizrupt_OAuth_DB::hash_token( $token );

			if ( 'refresh_token' === $token_hint ) {
				Dizrupt_OAuth_DB::revoke_token_by_refresh_hash( $hash );
			} else {
				$revoked = Dizrupt_OAuth_DB::revoke_token_by_access_hash( $hash );
				if ( ! $revoked ) {
					Dizrupt_OAuth_DB::revoke_token_by_refresh_hash( $hash );
				}
			}

			return new WP_REST_Response( null, 200 );
		}

		/**
		 * Verify PKCE code_verifier against stored code_challenge (RFC 7636 S256).
		 */
		public static function verify_pkce( string $code_verifier, string $stored_challenge, string $method ): bool {
			if ( 'S256' !== $method ) {
				return false;
			}

			$computed = rtrim(
				strtr(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					base64_encode( hash( 'sha256', $code_verifier, true ) ),
					'+/',
					'-_'
				),
				'='
			);

			return hash_equals( $stored_challenge, $computed );
		}

		private static function oauth_error( string $code, string $description, int $status = 400 ): WP_Error {
			return new WP_Error( $code, $description, array( 'status' => $status ) );
		}
	}
}
