<?php
/**
 * Gutenberg block registration and rendering.
 *
 * @package Calls_To_Action_Manager
 */

defined( 'ABSPATH' ) || exit;

class CTA_Blocks {

	public function __construct() {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Register Gutenberg blocks.
	 */
	public function register_blocks() {
		// Check if block registration function exists (WP 5.8+)
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register block script
		wp_register_script(
			'cta-manager-upcoming-ctas-editor-script',
			plugin_dir_url( __FILE__ ) . '../blocks/upcoming-ctas/block.js',
			[ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ],
			'1.0.0'
		);

		// Register block style
		wp_register_style(
			'cta-manager-upcoming-ctas-style',
			plugin_dir_url( __FILE__ ) . '../blocks/upcoming-ctas/block.css',
			[],
			'1.0.0'
		);

		register_block_type(
			plugin_dir_path( __FILE__ ) . '../blocks/upcoming-ctas',
			[
				'editor_script'   => 'cta-manager-upcoming-ctas-editor-script',
				'style'           => 'cta-manager-upcoming-ctas-style',
				'render_callback' => [ $this, 'render_upcoming_ctas_block' ],
			]
		);
	}

	/**
	 * Enqueue editor assets and pass taxonomy data.
	 */
	public function enqueue_editor_assets() {
		// Get taxonomy terms for the editor
		$types = get_terms(
			[
				'taxonomy'   => 'cta_type',
				'hide_empty' => false,
			]
		);

		$orgs = get_terms(
			[
				'taxonomy'   => 'cta_org',
				'hide_empty' => false,
			]
		);

		// Prepare data for JS
		$block_data = [
			'types' => ! is_wp_error( $types ) ? array_map(
				function( $term ) {
					return [
						'name' => $term->name,
						'slug' => $term->slug,
					];
				},
				$types
			) : [],
			'orgs'  => ! is_wp_error( $orgs ) ? array_map(
				function( $term ) {
					return [
						'name' => $term->name,
						'slug' => $term->slug,
					];
				},
				$orgs
			) : [],
		];

		wp_localize_script(
			'cta-manager-upcoming-ctas-editor-script',
			'ctaBlockData',
			$block_data
		);
	}

	/**
	 * Render the Upcoming CTAs block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public function render_upcoming_ctas_block( $attributes ) {
		$limit         = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 3;
		$cta_type      = isset( $attributes['ctaType'] ) ? sanitize_text_field( $attributes['ctaType'] ) : '';
		$cta_org       = isset( $attributes['ctaOrg'] ) ? sanitize_text_field( $attributes['ctaOrg'] ) : '';
		$show_deadline  = isset( $attributes['showDeadline'] ) ? (bool) $attributes['showDeadline'] : true;
		$action_mode    = isset( $attributes['actionMode'] ) ? sanitize_text_field( $attributes['actionMode'] ) : 'urgent';
		$show_view_more = isset( $attributes['showViewMore'] ) ? (bool) $attributes['showViewMore'] : true;

		// Shared tax query.
		$tax_query = [];
		if ( ! empty( $cta_type ) ) {
			$tax_query[] = [ 'taxonomy' => 'cta_type', 'field' => 'slug', 'terms' => $cta_type ];
		}
		if ( ! empty( $cta_org ) ) {
			$tax_query[] = [ 'taxonomy' => 'cta_org', 'field' => 'slug', 'terms' => $cta_org ];
		}

		// Base args shared by all modes.
		$base_args = [
			'post_type'      => 'cta',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		];
		if ( ! empty( $tax_query ) ) {
			$base_args['tax_query'] = $tax_query;
		}


		$base_args_all = array_merge( $base_args, [ 'posts_per_page' => -1 ] );

		// Args for urgent (deadlined) CTAs.
		$urgent_args = array_merge( $base_args, [
			'orderby'    => 'meta_value',
			'order'      => 'ASC',
			'meta_key'   => '_cta_end',
			'meta_type'  => 'DATETIME',
			'meta_query' => [
				[
					'key'     => '_cta_end',
					'value'   => current_time( 'mysql' ),
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			],
		] );

		// Args for ongoing CTAs (not ended).
		$ongoing_args = array_merge( $base_args, [
			'orderby'    => 'title',
			'order'      => 'ASC',
			'meta_query' => [
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
		] );

		// Determine which posts to display based on mode.
		// For 'all' and 'fallback' we collect post IDs manually so ordering
		// is predictable and posts without _cta_end are not dropped.
		$posts_to_show = [];

		if ( 'urgent' === $action_mode ) {
			$q = new WP_Query( $urgent_args );
			$posts_to_show = $q->posts;
		} elseif ( 'ongoing' === $action_mode ) {
			$q = new WP_Query( $ongoing_args );
			$posts_to_show = $q->posts;
		} elseif ( 'all' === $action_mode ) {
			// Run both queries and merge, urgent first then ongoing, up to $limit.
			$urgent_q  = new WP_Query( $urgent_args );
			$ongoing_q = new WP_Query( $ongoing_args );
			$merged    = array_merge( $urgent_q->posts, $ongoing_q->posts );
			// Deduplicate by ID in case a post somehow appears in both.
			$seen = [];
			foreach ( $merged as $post_obj ) {
				if ( ! isset( $seen[ $post_obj->ID ] ) ) {
					$seen[ $post_obj->ID ] = true;
					$posts_to_show[]       = $post_obj;
				}
			}
			$posts_to_show = array_slice( $posts_to_show, 0, $limit );
		} elseif ( 'fallback' === $action_mode ) {
			$urgent_q = new WP_Query( $urgent_args );
			if ( $urgent_q->have_posts() ) {
				$posts_to_show = $urgent_q->posts;
			} else {
				$ongoing_q     = new WP_Query( $ongoing_args );
				$posts_to_show = $ongoing_q->posts;
			}
		}

		// Check if there are more CTAs than shown (for the View More button).
		$has_more = false;
		if ( $show_view_more && ! empty( $posts_to_show ) ) {
			if ( 'urgent' === $action_mode ) {
				$count_q  = new WP_Query( array_merge( $urgent_args,  [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
				$has_more = $count_q->found_posts > count( $posts_to_show );
			} elseif ( 'ongoing' === $action_mode ) {
				$count_q  = new WP_Query( array_merge( $ongoing_args, [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
				$has_more = $count_q->found_posts > count( $posts_to_show );
			} elseif ( 'all' === $action_mode || 'fallback' === $action_mode ) {
				$count_u  = new WP_Query( array_merge( $urgent_args,  [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
				$count_o  = new WP_Query( array_merge( $ongoing_args, [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
				$total    = count( array_unique( array_merge( $count_u->posts, $count_o->posts ) ) );
				$has_more = $total > count( $posts_to_show );
			}
		}

		ob_start();

		echo '<div class="cta-upcoming-block">';
		echo '<header class="cta-upcoming-block__header">';
		echo '<h3 class="cta-upcoming-block__title">📢 Actions Needed</h3>';
		echo '</header>';

		if ( ! empty( $posts_to_show ) ) {
			echo '<div class="cta-upcoming-block__grid">';

			foreach ( $posts_to_show as $post ) {
				setup_postdata( $post );

				$is_ongoing  = (bool) get_post_meta( $post->ID, '_cta_ongoing', true );
				$end         = get_post_meta( $post->ID, '_cta_end', true );
				$end_display = ( ! $is_ongoing && $end ) ? date_i18n( 'M j, Y', strtotime( $end ) ) : '';

				$button_text = get_post_meta( $post->ID, '_cta_button_text', true );
				if ( $button_text === '' ) {
					$button_text = 'Learn More';
				}

				$card_class = 'cta-upcoming-card';
				if ( $is_ongoing ) {
					$card_class .= ' cta-upcoming-card--ongoing';
				}

				echo '<article class="' . esc_attr( $card_class ) . '">';
				echo '<h4 class="cta-upcoming-card__title"><a href="' . esc_url( get_permalink( $post->ID ) ) . '">' . esc_html( get_the_title( $post->ID ) ) . '</a></h4>';

				if ( $show_deadline ) {
					if ( $is_ongoing ) {
						echo '<p class="cta-upcoming-card__deadline"><strong>Deadline:</strong> Ongoing Action</p>';
					} elseif ( $end_display ) {
						echo '<p class="cta-upcoming-card__deadline"><strong>Deadline:</strong> ' . esc_html( $end_display ) . '</p>';
					}
				}

				echo '<a class="cta-upcoming-card__button" href="' . esc_url( get_permalink( $post->ID ) ) . '">' . esc_html( $button_text ) . ' →</a>';
				echo '</article>';
			}

			wp_reset_postdata();

			echo '</div>'; // .cta-upcoming-block__grid

			// View More button -- outside the grid, links to the Action Center page.
			if ( $has_more ) {
				$action_center_url = cta_manager_action_center_url();
				echo '<div class="cta-upcoming-block__view-more-wrap">';
				echo '<a class="cta-upcoming-block__view-more-btn" href="' . esc_url( $action_center_url ) . '">View More Actions &rarr;</a>';
				echo '</div>';
			}
		} else {
			echo '<p class="cta-upcoming-block__empty">No actions to display at this time.</p>';
		}

		echo '</div>';

		return ob_get_clean();
	}
}