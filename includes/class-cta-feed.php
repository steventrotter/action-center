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
 *   modified, image. Extra fields (organizations, types) are additive; minimal
 *   consumers can ignore them.
 * - Query params let any consumer filter server-side: ?limit=, ?urgency=,
 *   ?type=, ?org= (taxonomy slugs), ?full=1.
 * - ?full=1 adds a `content` object per item carrying the body a detail
 *   screen needs: summary_html, steps, sample_texts, links, videos and
 *   button_text, plus a `guided` block when the action is a guided comment
 *   builder. Added 1.2.0 as a convenience so a consumer gets the whole action
 *   in one already-shaped, normalized call instead of assembling it from several
 *   core-REST meta reads (the `_cta_*` keys are show_in_rest and readable there
 *   for published CTAs; this is shape, not a privacy boundary). Everything in
 *   `content` is already public on the CTA's own detail page.
 * - `modified` is the post's last-modified time in UTC. A poller comparing it
 *   against what it saw last can tell a newly published action from an edited
 *   one without keeping a copy of the whole payload.
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
					'full'    => [
						'description'       => 'Include a `content` object per item with the full body: summary_html, steps, sample_texts, links, videos, button_text.',
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
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
		$full    = ! empty( $request['full'] );

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
				$items[] = $this->build_item( $post, 'now', $full );
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
				$items[] = $this->build_item( $post, 'ongoing', $full );
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
	 * @param bool    $full    Whether to attach the full `content` object.
	 * @return array
	 */
	private function build_item( WP_Post $post, string $urgency, bool $full = false ): array {
		$item = [
			'id'       => 'cta-' . $post->ID,
			'title'    => $this->trim_plain( get_the_title( $post ), self::TITLE_MAX ),
			'summary'  => $this->trim_plain( get_post_meta( $post->ID, '_cta_summary', true ), self::SUMMARY_MAX ),
			'url'      => get_permalink( $post ),
			'urgency'  => $urgency,
			'date'     => get_the_date( 'Y-m-d', $post ),
			// UTC, so a poller can compare without knowing the site's timezone.
			'modified' => get_post_modified_time( 'Y-m-d\TH:i:s\Z', true, $post ),
			// Action format: 'guided' actions carry an interactive comment builder.
			'format'   => ( 'guided' === get_post_meta( $post->ID, '_cta_format', true ) ) ? 'guided' : 'simple',
		];

		// Expires: date portion of the stored datetime-local string ("2026-07-15T17:00").
		$end = get_post_meta( $post->ID, '_cta_end', true );
		if ( 'ongoing' !== $urgency && $end ) {
			$item['expires'] = substr( $end, 0, 10 );
		}

		// `medium` is 300px wide and looks soft on a phone. `image` is now the
		// 768px size; `image_small` keeps the old one for consumers that were
		// relying on a thumbnail. WordPress falls back to the full-size URL
		// when the requested size was never generated for that attachment.
		$image = get_the_post_thumbnail_url( $post, 'medium_large' );
		if ( $image ) {
			$item['image'] = $image;
		}
		$image_small = get_the_post_thumbnail_url( $post, 'medium' );
		if ( $image_small ) {
			$item['image_small'] = $image_small;
		}

		// Additive fields beyond the shared spec; consumers may ignore them.
		$item['organizations'] = $this->term_names( $post->ID, 'cta_org' );
		$item['types']         = $this->term_names( $post->ID, 'cta_type' );

		if ( $full ) {
			$item['content'] = $this->build_content( $post );
		}

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
	 * Build the full body content for one CTA.
	 *
	 * Only attached when ?full=1. Mirrors the sections the single-CTA detail
	 * page renders, so a native client can show the same thing without an
	 * authenticated call: everything here is already public at the CTA's
	 * permalink.
	 *
	 * HTML is re-run through wp_kses_post on the way out. The meta sanitizers
	 * in CTA_Meta already did this on write, but this is a public endpoint and
	 * consumers will inject the result into a DOM, so it is cheap insurance
	 * against anything that predates those sanitizers.
	 *
	 * @param WP_Post $post The CTA post.
	 * @return array
	 */
	private function build_content( WP_Post $post ): array {
		$summary = (string) get_post_meta( $post->ID, '_cta_summary', true );

		// Matches the detail page: block-level HTML passes through, plain text
		// gets wpautop. Guarded because the helper lives in CTA_Display.
		if ( function_exists( 'cta_manager_render_content' ) ) {
			$summary_html = cta_manager_render_content( $summary );
		} else {
			$summary_html = wp_kses_post( $summary );
		}

		$steps = [];
		foreach ( $this->meta_array( $post->ID, '_cta_steps' ) as $step ) {
			$step = is_string( $step ) ? trim( $step ) : '';
			if ( '' !== $step ) {
				$steps[] = wp_kses_post( $step );
			}
		}

		$sample_texts = [];
		foreach ( $this->meta_array( $post->ID, '_cta_sample_texts' ) as $sample ) {
			$sample = is_string( $sample ) ? trim( $sample ) : '';
			if ( '' !== $sample ) {
				// Stored and displayed as plain text, never as HTML.
				$sample_texts[] = wp_strip_all_tags( $sample );
			}
		}

		$button_text = trim( (string) get_post_meta( $post->ID, '_cta_button_text', true ) );
		$format      = ( 'guided' === get_post_meta( $post->ID, '_cta_format', true ) ) ? 'guided' : 'simple';

		$content = [
			'summary_html' => $summary_html,
			'steps'        => $steps,
			'sample_texts' => $sample_texts,
			'links'        => $this->url_label_pairs( $post->ID, '_cta_links' ),
			'videos'       => $this->url_label_pairs( $post->ID, '_cta_videos' ),
			'button_text'  => '' !== $button_text ? $button_text : __( 'Learn More', 'action-center' ),
			'format'       => $format,
		];

		if ( 'guided' === $format ) {
			$points = [];
			foreach ( $this->meta_array( $post->ID, '_cta_talking_points' ) as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$points[] = [
					'label' => isset( $p['label'] ) ? wp_strip_all_tags( (string) $p['label'] ) : '',
					'text'  => isset( $p['text'] ) ? wp_strip_all_tags( (string) $p['text'] ) : '',
				];
			}
			$prompts = [];
			foreach ( $this->meta_array( $post->ID, '_cta_personal_prompts' ) as $pr ) {
				$pr = is_string( $pr ) ? trim( $pr ) : '';
				if ( '' !== $pr ) {
					$prompts[] = $pr;
				}
			}
			// Resolve the effective character limit and identity setting: a per-CTA
			// override falls back to the site default.
			$default_limit = (int) get_option( 'cta_manager_default_char_limit', 5000 );
			if ( 'custom' === get_post_meta( $post->ID, '_cta_char_limit_mode', true ) ) {
				$cl              = get_post_meta( $post->ID, '_cta_char_limit', true );
				$eff_char_limit  = ( '' === $cl ) ? $default_limit : (int) $cl;
			} else {
				$eff_char_limit = $default_limit;
			}
			$identity_mode = get_post_meta( $post->ID, '_cta_collect_identity', true );
			if ( 'on' === $identity_mode ) {
				$eff_identity = true;
			} elseif ( 'off' === $identity_mode ) {
				$eff_identity = false;
			} else {
				$eff_identity = (bool) (int) get_option( 'cta_manager_collect_identity', 1 );
			}
			$content['guided'] = [
				'talking_points'   => $points,
				'personal_prompts' => $prompts,
				'intro'            => (string) get_post_meta( $post->ID, '_cta_guided_intro', true ),
				'closing'          => (string) get_post_meta( $post->ID, '_cta_guided_closing', true ),
				'comment_url'      => (string) get_post_meta( $post->ID, '_cta_comment_url', true ),
				'char_limit'       => $eff_char_limit,
				'collect_identity' => $eff_identity,
				'agency'           => (string) get_post_meta( $post->ID, '_cta_agency', true ),
				'docket'           => (string) get_post_meta( $post->ID, '_cta_docket', true ),
				'goal'             => (int) get_post_meta( $post->ID, '_cta_comment_goal', true ),
				'builds'           => (int) get_post_meta( $post->ID, '_cta_builds', true ),
				'confirmed'        => (int) get_post_meta( $post->ID, '_cta_confirmed', true ),
				'show_counter'     => (bool) get_post_meta( $post->ID, '_cta_show_counter', true ),
			];
		}

		return $content;
	}

	/**
	 * Read an array-valued meta key, tolerating the empty string WordPress
	 * returns when the key has never been set.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array
	 */
	private function meta_array( int $post_id, string $key ): array {
		$value = get_post_meta( $post_id, $key, true );

		return is_array( $value ) ? $value : [];
	}

	/**
	 * Normalize a repeatable {url, label} meta field for output.
	 *
	 * Rows with no URL are dropped: they cannot be acted on and only pad the
	 * payload. A row with no label falls back to its URL, so a consumer always
	 * has something to render.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array
	 */
	private function url_label_pairs( int $post_id, string $key ): array {
		$out = [];

		foreach ( $this->meta_array( $post_id, $key ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}

			$url = esc_url_raw( (string) $row['url'] );
			if ( '' === $url ) {
				continue;
			}

			$label = isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : '';
			$label = trim( html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			$out[] = [
				'url'   => $url,
				'label' => '' !== $label ? $label : $url,
			];
		}

		return $out;
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
		// Full entity decode: WP texturizes titles into numeric entities
		// (e.g. &#8217; for apostrophes), which wp_specialchars_decode leaves alone.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
