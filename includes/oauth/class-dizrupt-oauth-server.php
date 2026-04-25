<?php
/**
 * OAuth 2.1 Server — Orchestrator.
 *
 * Registers .well-known endpoints, REST API routes,
 * CORS handling, and initialises the Bearer token interceptor.
 *
 * @package dizrupt-mcp-abilities
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Dizrupt_OAuth_Server' ) ) {

	class Dizrupt_OAuth_Server {

		public static function init(): void {
			add_action( 'init', array( __CLASS__, 'handle_well_known' ), 1 );
			add_action( 'init', array( __CLASS__, 'handle_preflight' ), 1 );
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
			add_action( 'rest_api_init', array( __CLASS__, 'add_cors_filters' ) );
			Dizrupt_OAuth_Interceptor::init();
		}

		public static function handle_well_known(): void {
			if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
				return;
			}

			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$request_uri = strtok( $request_uri, '?' );

			$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
			$home_path = $home_path ?: '';
			$relative  = $home_path ? substr( $request_uri, strlen( $home_path ) ) : $request_uri;

			if ( '/.well-known/oauth-protected-resource' === $relative ) {
				self::send_protected_resource_metadata();
			}

			if ( '/.well-known/oauth-authorization-server' === $relative ) {
				self::send_authorization_server_metadata();
			}
		}

		private static function send_protected_resource_metadata(): void {
			self::send_cors_headers();
			wp_send_json(
				array(
					'resource'                 => home_url(),
					'authorization_servers'    => array( home_url() ),
					'bearer_methods_supported' => array( 'header' ),
					'scopes_supported'         => array( 'mcp:tools', 'mcp:read', 'mcp:write' ),
				)
			);
		}

		private static function send_authorization_server_metadata(): void {
			self::send_cors_headers();
			wp_send_json(
				array(
					'issuer'                                => home_url(),
					'authorization_endpoint'                => rest_url( 'dizrupt-auth/v1/authorize' ),
					'token_endpoint'                        => rest_url( 'dizrupt-auth/v1/token' ),
					'registration_endpoint'                 => rest_url( 'dizrupt-auth/v1/register' ),
					'revocation_endpoint'                   => rest_url( 'dizrupt-auth/v1/revoke' ),
					'scopes_supported'                      => array( 'mcp:tools', 'mcp:read', 'mcp:write' ),
					'response_types_supported'              => array( 'code' ),
					'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
					'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
					'code_challenge_methods_supported'      => array( 'S256' ),
				)
			);
		}

		/**
		 * Register OAuth REST API routes.
		 * All endpoints use __return_true — each implements its own security controls per OAuth 2.1 spec.
		 */
		public static function register_routes(): void {
			$ns = 'dizrupt-auth/v1';

			register_rest_route(
				$ns,
				'/register',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_register' ),
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				$ns,
				'/authorize',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( 'Dizrupt_OAuth_Authorize', 'handle_get' ),
						'permission_callback' => '__return_true',
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( 'Dizrupt_OAuth_Authorize', 'handle_post' ),
						'permission_callback' => '__return_true',
					),
				)
			);

			register_rest_route(
				$ns,
				'/token',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( 'Dizrupt_OAuth_Token', 'handle' ),
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				$ns,
				'/revoke',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( 'Dizrupt_OAuth_Token', 'handle_revoke' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		public static function handle_register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$body = $request->get_json_params();

			$client_name   = sanitize_text_field( $body['client_name'] ?? '' );
			$redirect_uris = $body['redirect_uris'] ?? array();
			$grant_types   = $body['grant_types'] ?? array( 'authorization_code', 'refresh_token' );
			$response_types = $body['response_types'] ?? array( 'code' );
			$auth_method   = sanitize_text_field( $body['token_endpoint_auth_method'] ?? 'none' );

			if ( empty( $client_name ) || empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
				return new WP_Error( 'invalid_client_metadata', 'client_name and redirect_uris are required.', array( 'status' => 400 ) );
			}

			foreach ( $redirect_uris as $uri ) {
				$uri = esc_url_raw( $uri );
				if ( empty( $uri ) || 0 !== strpos( $uri, 'https://' ) ) {
					return new WP_Error( 'invalid_redirect_uri', 'All redirect_uris must be valid HTTPS URLs.', array( 'status' => 400 ) );
				}
			}

			$redirect_uris = array_map( 'esc_url_raw', $redirect_uris );

			if ( ! in_array( $auth_method, array( 'none', 'client_secret_post' ), true ) ) {
				return new WP_Error( 'invalid_client_metadata', 'token_endpoint_auth_method must be "none" or "client_secret_post".', array( 'status' => 400 ) );
			}

			$client = Dizrupt_OAuth_DB::insert_client(
				array(
					'client_name'                => $client_name,
					'redirect_uris'              => $redirect_uris,
					'grant_types'                => $grant_types,
					'token_endpoint_auth_method' => $auth_method,
				)
			);

			if ( ! $client ) {
				return new WP_Error( 'server_error', 'Could not register client.', array( 'status' => 500 ) );
			}

			$response_data = array(
				'client_id'                  => $client['client_id'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'grant_types'                => $client['grant_types'],
				'response_types'             => $response_types,
				'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
			);

			if ( ! empty( $client['client_secret'] ) ) {
				$response_data['client_secret'] = $client['client_secret'];
			}

			return new WP_REST_Response( $response_data, 201 );
		}

		public static function handle_preflight(): void {
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) {
				return;
			}

			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

			$needs_cors = false !== strpos( $request_uri, 'dizrupt-auth' )
				|| false !== strpos( $request_uri, '.well-known/oauth' )
				|| false !== strpos( $request_uri, '/mcp/' );

			if ( ! $needs_cors ) {
				return;
			}

			self::send_cors_headers();
			status_header( 204 );
			exit;
		}

		public static function send_cors_headers(): void {
			$origin = sanitize_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

			$allowed_origins = array( 'https://claude.ai', 'https://claude.com' );

			if ( in_array( $origin, $allowed_origins, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
			}

			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Vary: Origin' );
		}

		public static function add_cors_filters(): void {
			add_filter(
				'rest_pre_serve_request',
				function ( $served, $result, $request ) {
					$route = $request->get_route();

					if ( 0 === strpos( $route, '/dizrupt-auth/' ) || 0 === strpos( $route, '/mcp/' ) ) {
						Dizrupt_OAuth_Server::send_cors_headers();
					}

					if ( '/dizrupt-auth/token' === $route || '/dizrupt-auth/revoke' === $route ) {
						header( 'Cache-Control: no-store' );
						header( 'Pragma: no-cache' );
					}

					return $served;
				},
				10,
				4
			);
		}
	}
}
