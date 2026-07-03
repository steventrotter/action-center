<?php
/**
 * Public JSON feed of active CTAs for external consumers.
 *
 * Registers GET /wp-json/action-center/v1/actions - public, no auth. Returns every
 * active, published CTA: items with a future deadline (urgency "now")
 * followed by ongoing items (urgency "ongoing"). Expired-deadline and
 * manually-ended CTAs are excluded. Capped at 20 items by default.
 *
 * Designed to be consumable by any external site or service:
 *
 * - Core item fields: id, title, summary, url, urgency, date, expires,
 *   image. Extra fields (organizations, types) are additive; minimal
 *   consumers can ignore them.
 * - Query params let any consumer filter server-side: ?limit=, ?urgency=,
 *   ?type=, ?org= (taxonomy slugs).
 * - Urgency is "now" for any item with a future deadline and "ongoing" for
 *   open-ended items; "soon" is accepted as a filter value but never emitted
 *   by default. The `cta_manager_feed_item` filter lets a site remap urgency
 *   (e.g. add a now/soon threshold) without touching core code.
 * - `cta_manager_feed_response` filters the whole payload before serving.
 *
 * @package Action_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves the /action-center/v1/actions REST route.
 */
class CTA_Feed {

	/**
	 * Feed format version.
	 */
	const FEED_VERSION = 1;

	/**
	 * Default and maximum number of items in the feed.
	 */
	const DEFAULT_ITEMS = 20;
	const MAX_ITEMS     = 50;

	/**
	 * Maximum title length in characters.
	 */
	const TITLE_MAX = 80;

	/**
	 * Maximum summary length in characters.
	 */
	const SUMMARY_MAX = 240;

	/**
	 * Hook route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the public feed route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'action-center/v1',
			'/actions',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_actions' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'limit'   => [
						'description'       => 'Maximum items to return (1-' . self::MAX_ITEMS . ', default ' . self::DEFAULT_ITEMS . ').',
						'type'              => 'integer',
						'default'           => self::DEFAULT_ITEMS,
						'minimum'           => 1,
						'maximum'           => self::MAX_ITEMS,
						'sanitize_callback' => 'absint',
					],
					'urgency' => [
						'description' => 'Only return items with this urgency.',
						'type'        => 'string',
						'enum'        => [ 'now', 'soon', 'ongoing' ],
					],
					'type'    => [
						'description'       => 'Only return items with this cta_type slug.',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
					'org'     => [
						'description'       => 'Only return items with this cta_org slug.',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);
	}

	/**
	 * Build the feed response.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_actions( WP_REST_Request $request ): WP_REST_Response {
		$limit   = min( self::MAX_ITEMS, max( 1, (int) $request['limit'] ) );
		$urgency = $request['urgency'];

		$tax_query = [];
		if ( ! empty( $request['type'] ) ) {
			$tax_query[] = [
				'taxonomy' => 'cta_type',
				'field'    => 'slug',
				'terms'    => $request['type'],
			];
		}
		if ( ! empty( $request['org'] ) ) {
			$tax_query[] = [
				'taxonomy' => 'cta_org',
				'field'    => 'slug',
				'terms'    => $request['org'],
			];
		}

		$items = [];

		// Deadline CTAs: future deadline, soonest first (mirrors the
		// Action Center listing's Urgent Actions query in CTA_Display::render_cta_list).
		if ( ! $urgency || 'ongoing' !== $urgency ) {
			$deadline_args = [
				'post_type'      => 'cta',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => '_cta_end',
				'meta_query'     => [
					[
						'key'     => '_cta_end',
						'value'   => current_time( 'mysql' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					],
				],
			];
			if ( ! empty( $tax_query ) ) {
				$deadline_args['tax_query'] = $tax_query;
			}
			$deadline_query = new WP_Query( $deadline_args );

			foreach ( $deadline_query->posts as $post ) {
				$items[] = $this->build_item( $post, 'now' );
			}
		}

		// Ongoing CTAs: ongoing, not ended, newest first.
		if ( ! $urgency || 'ongoing' === $urgency ) {
			$ongoing_args = [
				'post_type'      => 'cta',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'   => '_cta_ongoing',
						'value' => '1',
					],
					[
						'relation' => 'OR',
						[
							'key'     => '_cta_ended',
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => '_cta_ended',
							'value'   => '1',
							'compare' => '!=',
						],
					],
				],
			];
			if ( ! empty( $tax_query ) ) {
				$ongoing_args['tax_query'] = $tax_query;
			}
			$ongoing_query = new WP_Query( $ongoing_args );

			foreach ( $ongoing_query->posts as $post ) {
				$items[] = $this->build_item( $post, 'ongoing' );
			}
		}

		// Honor an explicit urgency filter even if a build_item filter remapped values.
		if ( $urgency ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $urgency ) {
						return $item['urgency'] === $urgency;
					}
				)
			);
		}

		$items = array_slice( $items, 0, $limit );

		$payload = [
			'version'      => self::FEED_VERSION,
			'organization' => get_bloginfo( 'name' ),
			'generated'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'items'        => $items,
		];

		/**
		 * Filter the full feed payload before it is served.
		 *
		 * @param array           $payload The response body.
		 * @param WP_REST_Request $request The request.
		 */
		$payload = apply_filters( 'cta_manager_feed_response', $payload, $request );

		$response = new WP_REST_Response( $payload );

		// Allow cross-origin reads so client-side widgets on other sites can consume the feed.
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Build one feed item from a CTA post.
	 *
	 * @param WP_Post $post    The CTA post.
	 * @param string  $urgency "now" or "ongoing".
	 * @return array
	 */
	private function build_item( WP_Post $post, string $urgency ): array {
		$item = [
			'id'      => 'cta-' . $post->ID,
			'title'   => $this->trim_plain( get_the_title( $post ), self::TITLE_MAX ),
			'summary' => $this->trim_plain( get_post_meta( $post->ID, '_cta_summary', true ), self::SUMMARY_MAX ),
			'url'     => get_permalink( $post ),
			'urgency' => $urgency,
			'date'    => get_the_date( 'Y-m-d', $post ),
		];

		// Expires: date portion of the stored datetime-local string ("2026-07-15T17:00").
		$end = get_post_meta( $post->ID, '_cta_end', true );
		if ( 'ongoing' !== $urgency && $end ) {
			$item['expires'] = substr( $end, 0, 10 );
		}

		$image = get_the_post_thumbnail_url( $post, 'medium' );
		if ( $image ) {
			$item['image'] = $image;
		}

		// Additive fields beyond the shared spec; consumers may ignore them.
		$item['organizations'] = $this->term_names( $post->ID, 'cta_org' );
		$item['types']         = $this->term_names( $post->ID, 'cta_type' );

		/**
		 * Filter a single feed item.
		 *
		 * Allows remapping urgency (e.g. introducing a now/soon threshold),
		 * adding fields, or overriding values per item.
		 *
		 * @param array   $item The feed item.
		 * @param WP_Post $post The source CTA post.
		 */
		return apply_filters( 'cta_manager_feed_item', $item, $post );
	}

	/**
	 * Get term names for a post as a plain array of strings.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private function term_names( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}

		return array_values( wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Flatten HTML to plain text and trim to a max length on a word boundary.
	 *
	 * @param string $text HTML or plain text.
	 * @param int    $max  Maximum length in characters.
	 * @return string
	 */
	private function trim_plain( $text, int $max ): string {
		$text = wp_strip_all_tags( (string) $text );
		$text = wp_specialchars_decode( $text, ENT_QUOTES );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$text = mb_substr( $text, 0, $max - 3 );
		// Back up to the last full word so we never cut mid-word.
		$last_space = mb_strrpos( $text, ' ' );
		if ( false !== $last_space ) {
			$text = mb_substr( $text, 0, $last_space );
		}

		return rtrim( $text, " \t.,;:" ) . '...';
	}
}
