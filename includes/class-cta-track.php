<?php
/**
 * Public engagement counters for Guided CTAs.
 *
 * Registers a public REST route, action-center/v1/track, that the comment builder
 * calls when a supporter clicks the button that opens the real submission form
 * ("build"). It increments the _cta_builds post meta counter, reported publicly as
 * "comments written".
 *
 * This is intentionally a soft engagement metric, not a verified submission: the
 * government form lives on another site and cannot report back. The route is
 * unauthenticated because supporters are anonymous; a short per-IP cooldown reduces
 * accidental double counts. It never reads or stores anything about the visitor
 * beyond incrementing one integer.
 *
 * @package Action_Center
 */

defined( 'ABSPATH' ) || exit;

class CTA_Track {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			'action-center/v1',
			'/track',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_track' ],
				'args'                => [
					'id'    => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'event' => [
						'required'          => false,
						'type'              => 'string',
						'enum'              => [ 'build' ],
						'default'           => 'build',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	public function handle_track( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$event = (string) $request->get_param( 'event' );

		$post = get_post( $id );
		if ( ! $post || 'cta' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'cta_not_found', 'Action not found.', [ 'status' => 404 ] );
		}
		if ( 'guided' !== get_post_meta( $id, '_cta_format', true ) ) {
			return new WP_Error( 'cta_not_guided', 'This action does not accept tracking.', [ 'status' => 400 ] );
		}

		// Short per-visitor cooldown to reduce accidental double counts. Soft metric,
		// not an anti-abuse guarantee.
		$fingerprint = $this->visitor_fingerprint();
		$cooldown_key = 'cta_track_' . md5( $id . '|' . $event . '|' . $fingerprint );
		$counted      = false;

		if ( false === get_transient( $cooldown_key ) ) {
			$current = (int) get_post_meta( $id, '_cta_builds', true );
			update_post_meta( $id, '_cta_builds', $current + 1 );
			set_transient( $cooldown_key, 1, (int) apply_filters( 'cta_manager_track_cooldown', 3 ) );
			$counted = true;
		}

		return rest_ensure_response(
			[
				'counted' => $counted,
				'builds'  => (int) get_post_meta( $id, '_cta_builds', true ),
			]
		);
	}

	/**
	 * A coarse, non-identifying visitor fingerprint for the cooldown transient only.
	 * Hashed, never stored as-is, and never used for anything but rate limiting.
	 */
	private function visitor_fingerprint() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return $ip . '|' . $ua;
	}
}
