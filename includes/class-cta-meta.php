<?php
/**
 * Admin meta boxes and CPT/Taxonomy registration for CTA Manager.
 *
 * @package Calls_To_Action_Manager
 */

defined( 'ABSPATH' ) || exit;

class CTA_Meta {

	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_taxonomies' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'add_meta_boxes', [ $this, 'cleanup_meta_boxes' ], 20 );
		add_action( 'save_post_cta', [ $this, 'save_meta_data' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_notices', [ $this, 'admin_version_banner' ] );
		add_filter( 'get_user_option_closedpostboxes_cta', [ $this, 'default_collapsed_boxes' ] );
	}

	/**
	 * Collapse the CTA content meta boxes by default so the edit screen opens calm
	 * rather than as a wall of open panels. Applies only until the user sets their
	 * own open/closed state, which WordPress then stores and this no longer overrides.
	 *
	 * @param mixed $closed Stored closed-box list, or false when the user has none.
	 * @return array
	 */
	public function default_collapsed_boxes( $closed ) {
		if ( false !== $closed ) {
			return $closed;
		}
		// Summary stays open; the rest collapse so the screen opens calm.
		return [
			'cta_dates_box',
			'cta_guided_box',
			'cta_links_box',
			'cta_files_box',
			'cta_steps_box',
			'cta_sample_text_box',
			'cta_videos_box',
		];
	}

	public function register_post_type() {
		$labels = [
			'name'                  => 'CTAs',
			'singular_name'         => 'CTA',
			'menu_name'             => 'CTAs',
			'name_admin_bar'        => 'CTA',
			'add_new'               => 'Add New',
			'add_new_item'          => 'Add New CTA',
			'edit_item'             => 'Edit CTA',
			'new_item'              => 'New CTA',
			'view_item'             => 'View CTA',
			'view_items'            => 'View CTAs',
			'search_items'          => 'Search CTAs',
			'not_found'             => 'No CTAs found',
			'not_found_in_trash'    => 'No CTAs found in Trash',
			'all_items'             => 'All CTAs',
			'archives'              => 'CTA Archives',
			'attributes'            => 'CTA Attributes',
			'insert_into_item'      => 'Insert into CTA',
			'uploaded_to_this_item' => 'Uploaded to this CTA',
			'filter_items_list'     => 'Filter CTAs list',
			'items_list'            => 'CTAs list',
			'items_list_navigation' => 'CTAs list navigation',
		];

		register_post_type(
			'cta',
			[
				'label'           => 'CTAs',
				'labels'          => $labels,
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'menu_position'   => 20,
				'supports'        => [ 'title', 'thumbnail' ],
				'taxonomies'      => [ 'cta_type', 'cta_org' ],
				'has_archive'     => true,
				'show_in_rest'    => true,
			]
		);
	}

	public function register_taxonomies() {

		// Organizations.
		register_taxonomy(
			'cta_org',
			'cta',
			[
				'label'             => 'Organizations',
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			]
		);

		// CTA Types (hierarchical).
		register_taxonomy(
			'cta_type',
			'cta',
			[
				'label'  => 'CTA Types',
				'labels' => [
					'name'          => 'CTA Types',
					'singular_name' => 'CTA Type',
					'search_items'  => 'Search CTA Types',
					'all_items'     => 'All CTA Types',
					'edit_item'     => 'Edit CTA Type',
					'update_item'   => 'Update CTA Type',
					'add_new_item'  => 'Add New CTA Type',
					'new_item_name' => 'New CTA Type Name',
					'menu_name'     => 'CTA Types',
				],
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			]
		);
	}

	/**
	 * Expose the CTA meta fields to the REST API so CTAs can be created and edited
	 * programmatically (e.g. via the WordPress MCP connector), not only through the
	 * classic meta boxes. Sanitization mirrors save_meta_data(). Underscore-prefixed
	 * (protected) meta keys are reachable in REST only when registered with
	 * show_in_rest + an auth_callback, which is what this provides.
	 */
	public function register_meta_fields() {
		$can_edit = function () {
			return current_user_can( 'edit_posts' );
		};

		// Scalar fields.
		register_post_meta( 'cta', '_cta_summary', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'wp_kses_post',
		] );
		register_post_meta( 'cta', '_cta_end', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_post_meta( 'cta', '_cta_ongoing', [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'absint',
		] );
		register_post_meta( 'cta', '_cta_ended', [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'absint',
		] );
		register_post_meta( 'cta', '_cta_legislator_url', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'esc_url_raw',
		] );
		register_post_meta( 'cta', '_cta_button_text', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_text_field',
		] );

		// Repeatable { url, label } pairs: _cta_links and _cta_videos.
		$url_label_schema = [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'url'   => [ 'type' => 'string' ],
					'label' => [ 'type' => 'string' ],
				],
			],
		];
		register_post_meta( 'cta', '_cta_links', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_url_label_pairs' ],
			'show_in_rest'      => [ 'schema' => $url_label_schema ],
		] );
		register_post_meta( 'cta', '_cta_videos', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_url_label_pairs' ],
			'show_in_rest'      => [ 'schema' => $url_label_schema ],
		] );

		// Repeatable file attachments: _cta_files ({ id, label }).
		register_post_meta( 'cta', '_cta_files', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_file_pairs' ],
			'show_in_rest'      => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'    => [ 'type' => 'integer' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
			],
		] );

		// Repeatable strings: _cta_steps (HTML allowed) and _cta_sample_texts (plain).
		$string_list_schema = [
			'type'  => 'array',
			'items' => [ 'type' => 'string' ],
		];
		register_post_meta( 'cta', '_cta_steps', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_steps' ],
			'show_in_rest'      => [ 'schema' => $string_list_schema ],
		] );
		register_post_meta( 'cta', '_cta_sample_texts', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_sample_texts' ],
			'show_in_rest'      => [ 'schema' => $string_list_schema ],
		] );

		// Action format: 'simple' (classic display) or 'guided' (comment builder).
		register_post_meta( 'cta', '_cta_format', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'simple',
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_format' ],
		] );

		// Guided: talking points the supporter can select. Each is { label, text }.
		register_post_meta( 'cta', '_cta_talking_points', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_talking_points' ],
			'show_in_rest'      => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'label' => [ 'type' => 'string' ],
							'text'  => [ 'type' => 'string' ],
						],
					],
				],
			],
		] );

		// Guided: personal prompt questions shown as free-text fields.
		register_post_meta( 'cta', '_cta_personal_prompts', [
			'type'              => 'array',
			'single'            => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => [ $this, 'sanitize_sample_texts' ],
			'show_in_rest'      => [ 'schema' => $string_list_schema ],
		] );

		// Guided: the intro line and closing line that bracket the assembled comment.
		register_post_meta( 'cta', '_cta_guided_intro', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
		register_post_meta( 'cta', '_cta_guided_closing', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_textarea_field',
		] );

		// Guided: where the finished comment is submitted, plus context and goal.
		register_post_meta( 'cta', '_cta_comment_url', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'esc_url_raw',
		] );

		// Guided: character limit override. Mode 'default' follows the site setting;
		// 'custom' uses _cta_char_limit (counted like the government form; 0 = no limit).
		register_post_meta( 'cta', '_cta_char_limit_mode', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'default',
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, [ 'default', 'custom' ], true ) ? $v : 'default';
			},
		] );
		register_post_meta( 'cta', '_cta_char_limit', [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 5000,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'absint',
		] );

		// Guided: identity-fields override. 'default' follows the site setting;
		// 'on' asks for name/city/state and adds them to the comment; 'off' does not.
		register_post_meta( 'cta', '_cta_collect_identity', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'default',
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, [ 'default', 'on', 'off' ], true ) ? $v : 'default';
			},
		] );
		register_post_meta( 'cta', '_cta_agency', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_post_meta( 'cta', '_cta_docket', [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_post_meta( 'cta', '_cta_comment_goal', [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => 'absint',
		] );

		// Guided: whether to show the public "comments written" counter on this action.
		// Off by default; enabled per CTA.
		register_post_meta( 'cta', '_cta_show_counter', [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'auth_callback'     => $can_edit,
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		] );

		// Guided: soft engagement counters. Written by the public tracking route,
		// readable in REST but never writable through it (auth_callback denies).
		$deny_rest_write = function () {
			return false;
		};
		register_post_meta( 'cta', '_cta_builds', [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'auth_callback'     => $deny_rest_write,
			'sanitize_callback' => 'absint',
		] );
		register_post_meta( 'cta', '_cta_confirmed', [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'auth_callback'     => $deny_rest_write,
			'sanitize_callback' => 'absint',
		] );
	}

	/** Sanitize the action format to a known value. */
	public function sanitize_format( $value ) {
		return in_array( $value, [ 'simple', 'guided' ], true ) ? $value : 'simple';
	}

	/** Sanitize a list of { label, text } talking points (text may contain inline HTML). */
	public function sanitize_talking_points( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
			$text  = isset( $item['text'] ) ? wp_kses_post( $item['text'] ) : '';
			if ( '' === $label && '' === $text ) {
				continue;
			}
			$out[] = [
				'label' => $label,
				'text'  => $text,
			];
		}
		return array_values( $out );
	}

	/** Sanitize a list of { url, label } pairs (used by _cta_links and _cta_videos). */
	public function sanitize_url_label_pairs( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = [
				'url'   => $url,
				'label' => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
			];
		}
		return $out;
	}

	/** Sanitize a list of { id, label } file references (used by _cta_files). */
	public function sanitize_file_pairs( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}
			$out[] = [
				'id'    => $id,
				'label' => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
			];
		}
		return $out;
	}

	/** Sanitize the action-steps list (HTML allowed per step). */
	public function sanitize_steps( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$steps = array_filter( array_map( 'strval', $value ) );
		return array_values( array_map( 'wp_kses_post', $steps ) );
	}

	/** Sanitize the sample-texts list (plain multi-line text per item). */
	public function sanitize_sample_texts( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$texts = array_filter( array_map( 'strval', $value ) );
		return array_values( array_map( 'sanitize_textarea_field', $texts ) );
	}

	public function add_meta_boxes() {

		add_meta_box(
			'cta_format_box',
			'Action Format',
			[ $this, 'render_format_box' ],
			'cta',
			'side',
			'high'
		);

		add_meta_box(
			'cta_summary_box',
			'Summary',
			[ $this, 'render_summary_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_dates_box',
			'Deadline',
			[ $this, 'render_dates_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_guided_box',
			'Guided Comment Builder',
			[ $this, 'render_guided_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_links_box',
			'Related Links',
			[ $this, 'render_links_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_files_box',
			'Related Files',
			[ $this, 'render_files_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_steps_box',
			'Steps to Take',
			[ $this, 'render_steps_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_sample_text_box',
			'Sample Text Options',
			[ $this, 'render_sample_text_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_videos_box',
			'Related Videos',
			[ $this, 'render_videos_box' ],
			'cta',
			'normal',
			'default'
		);

		add_meta_box(
			'cta_legislator_box',
			'Legislator Lookup URL',
			[ $this, 'render_legislator_box' ],
			'cta',
			'side',
			'default'
		);

		add_meta_box(
			'cta_button_text_box',
			'Button Text',
			[ $this, 'render_button_text_box' ],
			'cta',
			'side',
			'default'
		);
	}

	public function cleanup_meta_boxes( $post_type ) {
		if ( 'cta' !== $post_type ) {
			return;
		}

		remove_meta_box( 'postcustom', 'cta', 'normal' );
		remove_meta_box( 'commentstatusdiv', 'cta', 'normal' );
		remove_meta_box( 'commentsdiv', 'cta', 'normal' );
		remove_meta_box( 'authordiv', 'cta', 'normal' );
		remove_meta_box( 'revisionsdiv', 'cta', 'normal' );
		remove_meta_box( 'categorydiv', 'cta', 'side' );
		remove_meta_box( 'tagsdiv-post_tag', 'cta', 'side' );
	}

	public function enqueue_scripts( $hook = '' ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'cta' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_editor();
	}

	public function admin_version_banner() {
		$screen = get_current_screen();
		if ( isset( $screen->post_type ) && 'cta' === $screen->post_type ) {
			echo '<div class="notice notice-info"><p>Calls to Action Manager Plugin.</p></div>';
		}
	}


	public function render_summary_box( $post ) {
		wp_nonce_field( 'cta_meta_box', 'cta_meta_box_nonce' );
		$summary = get_post_meta( $post->ID, '_cta_summary', true );
		
		$editor_id = 'cta_summary_editor';
		
		echo '<div class="cta-summary-editor-wrapper">';
		
		wp_editor(
			$summary,
			$editor_id,
			[
				'textarea_name' => 'cta_summary',
				'textarea_rows' => 10,
				'media_buttons' => false,
				'teeny'         => false,
				'tinymce'       => [
					'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink',
					'toolbar2' => '',
					'height'   => 300,
				],
				'quicktags'     => true,
			]
		);
		
		echo '</div>';
	}

	public function render_dates_box( $post ) {
		$end     = get_post_meta( $post->ID, '_cta_end', true );
		$ongoing = (bool) get_post_meta( $post->ID, '_cta_ongoing', true );

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="cta_ongoing" id="cta_ongoing" value="1"' . checked( $ongoing, true, false ) . '> ';
		echo '<strong>Ongoing Action</strong> &mdash; no expiry date';
		echo '</label>';
		echo '</p>';
		echo '<p class="description" style="margin-bottom:0.75rem;">Check this box if the action has no deadline. It will appear in a separate Ongoing Actions section on the Action Center page.</p>';

		$ended = (bool) get_post_meta( $post->ID, '_cta_ended', true );

		echo '<div id="cta-ended-row"' . ( $ongoing ? '' : ' style="display:none;"' ) . ' style="margin-top:0.75rem;">';
		echo '<label>';
		echo '<input type="checkbox" name="cta_ended" id="cta_ended" value="1"' . checked( $ended, true, false ) . '> ';
		echo '<strong>Mark as ended</strong> &mdash; hides from Action Center and blocks';
		echo '</label>';
		echo '</div>';

		echo '<div id="cta-deadline-row"' . ( $ongoing ? ' style="display:none;"' : '' ) . '>';
		echo '<label>Deadline Date &amp; Time<br>';
		echo '<input type="datetime-local" name="cta_end" id="cta_end" value="' . esc_attr( $end ) . '" style="width:100%;"></label>';
		echo '</div>';
		// Ongoing/deadline row toggle is handled in assets/admin.js (enqueued).
	}

	public function render_links_box( $post ) {
		$links = get_post_meta( $post->ID, '_cta_links', true );
		if ( ! is_array( $links ) ) {
			$links = [];
		}

		echo '<p class="description">Add URLs and optional display names. Drag rows to reorder.</p>';
		echo '<div id="cta-links-wrapper" class="cta-sortable-wrapper">';
		foreach ( $links as $link ) {
			$url   = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
			$label = is_array( $link ) ? ( $link['label'] ?? '' ) : '';
			echo '<div class="cta-link-row cta-sortable-row">';
			echo '<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>';
			echo '<div class="cta-sortable-fields">';
			echo '<input type="text" name="cta_links_url[]" placeholder="https://..." value="' . esc_attr( $url ) . '" class="cta-link-url">';
			echo '<input type="text" name="cta_links_label[]" placeholder="Display name (optional)" value="' . esc_attr( $label ) . '" class="cta-link-label">';
			echo '</div>';
			echo '<button type="button" class="button cta-remove-link">Remove</button>';
			echo '</div>';
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-link">Add Link</button></p>';
	}

	public function render_files_box( $post ) {
		$files = get_post_meta( $post->ID, '_cta_files', true );
		if ( ! is_array( $files ) ) {
			$files = [];
		}

		echo '<p class="description">Upload or select files (PDFs, docs, etc.). Add optional display names and drag rows to reorder.</p>';

		echo '<div id="cta-files-wrapper" class="cta-sortable-wrapper">';

		foreach ( $files as $file ) {
			// Support both old format (plain int) and new format (array with id + label).
			$id    = is_array( $file ) ? (int) ( $file['id'] ?? 0 ) : (int) $file;
			$label = is_array( $file ) ? ( $file['label'] ?? '' ) : '';

			if ( ! $id ) {
				continue;
			}

			$attachment = get_post( $id );
			if ( ! $attachment ) {
				continue;
			}

			$title = get_the_title( $id );
			$url   = wp_get_attachment_url( $id );

			echo '<div class="cta-file-row cta-sortable-row" data-id="' . esc_attr( $id ) . '">';
			echo '<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>';
			echo '<div class="cta-sortable-fields">';
			echo '<input type="hidden" name="cta_files_id[]" value="' . esc_attr( $id ) . '">';
			echo '<span class="cta-file-row__label">' . esc_html( $title ) . '</span> ';
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">View</a>';
			echo '<input type="text" name="cta_files_label[]" placeholder="Display name (optional)" value="' . esc_attr( $label ) . '" class="cta-file-label">';
			echo '</div>';
			echo '<button type="button" class="button cta-remove-file">Remove</button>';
			echo '</div>';
		}

		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-file">Add File</button></p>';
	}

	public function render_steps_box( $post ) {
		$steps = get_post_meta( $post->ID, '_cta_steps', true );
		if ( ! is_array( $steps ) || empty( $steps ) ) {
			$steps = [ '' ];
		}

		// This help text is rewritten by admin.js to match the chosen Action Format:
		// for Simple the steps are the action; for Guided they become follow-up actions
		// shown after the supporter submits their comment.
		echo '<p class="description" id="cta-steps-help">For a Simple action, these steps are the action: they walk a supporter through what to do.</p>';

		echo '<div id="cta-steps-wrapper">';

		$index = 0;
		foreach ( $steps as $step ) {
			$editor_id = 'cta_step_editor_initial_' . $index;
			
			echo '<div class="cta-step-item">';
			echo '<p><strong>Step ' . esc_html( $index + 1 ) . '</strong></p>';
			echo '<div class="cta-step-editor-wrapper">';
			
			wp_editor(
				$step,
				$editor_id,
				[
					'textarea_name' => 'cta_steps[]',
					'textarea_rows' => 8,
					'media_buttons' => false,
					'teeny'         => false,
					'tinymce'       => [
						'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink',
						'toolbar2' => '',
						'height'   => 250,
					],
					'quicktags'     => false,
				]
			);
			
			echo '</div>';
			echo '<p><button type="button" class="button cta-remove-step">Remove Step</button></p>';
			echo '</div>';
			$index++;
		}

		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-step">Add Another Step</button></p>';
	}

	public function render_sample_text_box( $post ) {
		$sample_texts = get_post_meta( $post->ID, '_cta_sample_texts', true );
		if ( ! is_array( $sample_texts ) || empty( $sample_texts ) ) {
			$sample_texts = [ '' ];
		}

		echo '<p class="description">Add one or more sample text options for public comments or emails. Users can choose which option to copy.</p>';

		echo '<div id="cta-sample-texts-wrapper">';

		$index = 0;
		foreach ( $sample_texts as $sample ) {
			echo '<div class="cta-sample-text-item">';
			echo '<p><strong>Option ' . esc_html( $index + 1 ) . '</strong></p>';
			echo '<textarea name="cta_sample_texts[]" rows="6" style="width:100%;">' . esc_textarea( $sample ) . '</textarea>';
			echo '<p><button type="button" class="button cta-remove-sample-text">Remove Option</button></p>';
			echo '</div>';
			$index++;
		}

		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-sample-text">Add Another Option</button></p>';
	}

	public function render_videos_box( $post ) {
		$videos = get_post_meta( $post->ID, '_cta_videos', true );
		if ( ! is_array( $videos ) ) {
			$videos = [];
		}

		echo '<p class="description">Paste YouTube URLs (standard videos or Shorts). They will appear as embedded videos on the details page. Drag rows to reorder.</p>';
		echo '<div id="cta-videos-wrapper" class="cta-sortable-wrapper">';
		foreach ( $videos as $video ) {
			$url   = is_array( $video ) ? ( $video['url'] ?? '' ) : $video;
			$label = is_array( $video ) ? ( $video['label'] ?? '' ) : '';
			echo '<div class="cta-video-row cta-sortable-row">';
			echo '<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>';
			echo '<div class="cta-sortable-fields">';
			echo '<input type="text" name="cta_videos_url[]" placeholder="https://www.youtube.com/watch?v=..." value="' . esc_attr( $url ) . '" class="cta-video-url">';
			echo '<input type="text" name="cta_videos_label[]" placeholder="Display name (optional)" value="' . esc_attr( $label ) . '" class="cta-video-label">';
			echo '</div>';
			echo '<button type="button" class="button cta-remove-video">Remove</button>';
			echo '</div>';
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-video">Add Video</button></p>';
	}

	public function render_legislator_box( $post ) {
		$url = get_post_meta( $post->ID, '_cta_legislator_url', true );
		echo '<p class="description">Only needed for CTAs with the <strong>Contact Your Legislator</strong> type. Leave blank to use the default federal and state lookup links from Settings > Action Center. Fill in to override with a specific URL.</p>';
		echo '<p><label>Custom Lookup URL<br>';
		echo '<input type="text" name="cta_legislator_url" value="' . esc_attr( $url ) . '" style="width:100%;" placeholder="https://..."></label></p>';
	}

	public function render_button_text_box( $post ) {
		$button_text = get_post_meta( $post->ID, '_cta_button_text', true );
		echo '<p><label>Button Text<br>';
		echo '<input type="text" name="cta_button_text" value="' . esc_attr( $button_text ) . '" style="width:100%;"></label></p>';
		echo '<p class="description">Leave empty to use the default "Learn More".</p>';
	}

	public function render_format_box( $post ) {
		$format = get_post_meta( $post->ID, '_cta_format', true );
		if ( ! in_array( $format, [ 'simple', 'guided' ], true ) ) {
			$format = 'simple';
		}
		echo '<p><label for="cta_format"><strong>How this action behaves</strong></label></p>';
		echo '<select name="cta_format" id="cta_format" style="width:100%;">';
		echo '<option value="simple"' . selected( $format, 'simple', false ) . '>Simple (classic display)</option>';
		echo '<option value="guided"' . selected( $format, 'guided', false ) . '>Guided (comment builder)</option>';
		echo '</select>';
		echo '<p class="description">Simple shows the summary, steps, and sample text as usual. Guided adds an interactive builder that helps supporters compose and edit a comment, then hands off to submit. Choosing Guided reveals the Guided Comment Builder fields and hides Sample Text Options.</p>';
	}

	public function render_guided_box( $post ) {
		$points  = get_post_meta( $post->ID, '_cta_talking_points', true );
		$prompts = get_post_meta( $post->ID, '_cta_personal_prompts', true );
		$intro   = get_post_meta( $post->ID, '_cta_guided_intro', true );
		$closing = get_post_meta( $post->ID, '_cta_guided_closing', true );
		$url     = get_post_meta( $post->ID, '_cta_comment_url', true );
		$char_limit_mode = get_post_meta( $post->ID, '_cta_char_limit_mode', true );
		$char_limit_mode = in_array( $char_limit_mode, [ 'default', 'custom' ], true ) ? $char_limit_mode : 'default';
		$char_limit = get_post_meta( $post->ID, '_cta_char_limit', true );
		$char_limit = ( '' === $char_limit ) ? 5000 : (int) $char_limit;
		$identity_mode = get_post_meta( $post->ID, '_cta_collect_identity', true );
		$identity_mode = in_array( $identity_mode, [ 'default', 'on', 'off' ], true ) ? $identity_mode : 'default';
		$agency  = get_post_meta( $post->ID, '_cta_agency', true );
		$docket  = get_post_meta( $post->ID, '_cta_docket', true );
		$goal    = (int) get_post_meta( $post->ID, '_cta_comment_goal', true );
		$show_counter = (bool) get_post_meta( $post->ID, '_cta_show_counter', true );

		if ( ! is_array( $points ) || empty( $points ) ) {
			$points = [ [ 'label' => '', 'text' => '' ] ];
		}
		if ( ! is_array( $prompts ) || empty( $prompts ) ) {
			$prompts = [ '' ];
		}

		echo '<p class="description">These fields build the interactive comment tool for supporters. Talking points become checkboxes that assemble into a draft the supporter can edit; personal prompts are their own free-text fields.</p>';

		// Talking points.
		echo '<h4 style="margin-bottom:0.25rem;">Talking Points</h4>';
		echo '<div id="cta-tp-wrapper" class="cta-sortable-wrapper">';
		foreach ( $points as $point ) {
			$label = is_array( $point ) ? ( $point['label'] ?? '' ) : '';
			$text  = is_array( $point ) ? ( $point['text'] ?? '' ) : '';
			$this->render_tp_row( $label, $text );
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-tp">Add Talking Point</button></p>';

		// Personal prompts.
		echo '<h4 style="margin-bottom:0.25rem;">Personal Prompts</h4>';
		echo '<p class="description" style="margin-top:0;">Questions that invite the supporter to add their own words (for example, "Why does this place matter to you?").</p>';
		echo '<div id="cta-pp-wrapper">';
		foreach ( $prompts as $prompt ) {
			echo '<div class="cta-pp-row" style="margin-bottom:0.5rem;">';
			echo '<input type="text" name="cta_personal_prompts[]" value="' . esc_attr( $prompt ) . '" style="width:calc(100% - 90px);" placeholder="Why does this place matter to you?">';
			echo ' <button type="button" class="button cta-remove-pp">Remove</button>';
			echo '</div>';
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="cta-add-pp">Add Prompt</button></p>';

		// Framing + submission.
		echo '<h4 style="margin-bottom:0.25rem;">Comment Framing</h4>';
		echo '<p><label>Opening line<br><textarea name="cta_guided_intro" rows="2" style="width:100%;" placeholder="I am writing to urge you to...">' . esc_textarea( $intro ) . '</textarea></label></p>';
		echo '<p><label>Closing line<br><textarea name="cta_guided_closing" rows="2" style="width:100%;" placeholder="Thank you for considering my comment.">' . esc_textarea( $closing ) . '</textarea></label></p>';

		echo '<h4 style="margin-bottom:0.25rem;">Submission &amp; Goal</h4>';
		echo '<p><label>Where the comment is submitted (URL)<br><input type="url" name="cta_comment_url" value="' . esc_attr( $url ) . '" style="width:100%;" placeholder="https://www.regulations.gov/commenton/..."></label></p>';
		echo '<p style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">';
		echo '<label style="flex:0 0 auto;">Comment character limit<br><select name="cta_char_limit_mode">';
		echo '<option value="default"' . selected( $char_limit_mode, 'default', false ) . '>Use site default</option>';
		echo '<option value="custom"' . selected( $char_limit_mode, 'custom', false ) . '>Custom</option>';
		echo '</select></label>';
		echo '<label style="flex:0 0 auto;">Custom limit<br><input type="number" name="cta_char_limit" value="' . esc_attr( $char_limit ) . '" min="0" step="100" style="width:130px;"></label>';
		echo '</p>';
		echo '<p class="description" style="margin-top:0;">Counted the way the government form counts them (regulations.gov allows 5000). "Use site default" follows Settings; "Custom" uses the number here. The builder shows a live character count and will not hand off a longer comment. Set the custom limit to 0 for no limit.</p>';
		echo '<p><label>Ask for name, city, and state<br><select name="cta_collect_identity">';
		echo '<option value="default"' . selected( $identity_mode, 'default', false ) . '>Use site default</option>';
		echo '<option value="on"' . selected( $identity_mode, 'on', false ) . '>Yes - add to the comment</option>';
		echo '<option value="off"' . selected( $identity_mode, 'off', false ) . '>No - keep the comment anonymous</option>';
		echo '</select></label></p>';
		echo '<p class="description" style="margin-top:0;">Federal comments (regulations.gov) become public record, so most keep this off and let supporters enter contact details on the form itself. A local action that needs a name and place can turn it on. "Use site default" follows Settings.</p>';
		echo '<p style="display:flex;gap:1rem;flex-wrap:wrap;">';
		echo '<label style="flex:1;min-width:150px;">Agency<br><input type="text" name="cta_agency" value="' . esc_attr( $agency ) . '" style="width:100%;" placeholder="USDA Forest Service"></label>';
		echo '<label style="flex:1;min-width:150px;">Docket<br><input type="text" name="cta_docket" value="' . esc_attr( $docket ) . '" style="width:100%;" placeholder="FS-2025-0001"></label>';
		echo '<label style="flex:1;min-width:100px;">Goal<br><input type="number" name="cta_comment_goal" value="' . esc_attr( $goal ? $goal : '' ) . '" min="0" style="width:100%;" placeholder="500"></label>';
		echo '</p>';
		echo '<p><label><input type="checkbox" name="cta_show_counter" value="1"' . checked( $show_counter, true, false ) . '> Show a public counter of comments written on this action</label></p>';
		echo '<p class="description" style="margin-top:0;">Off by default. When on, the action shows a running "comments written" count (and a goal meter if a goal is set). It counts each supporter who clicks through to the submission form; it is not a count of verified submissions.</p>';

		// Row template + add/remove behavior.
		echo '<template id="cta-tp-template">';
		$this->render_tp_row( '', '' );
		echo '</template>';
		// Talking-point / personal-prompt add/remove is handled in assets/admin.js (enqueued).
	}

	/** Render a single talking-point editor row (used for existing rows and the JS template). */
	private function render_tp_row( $label, $text ) {
		echo '<div class="cta-tp-row" style="border:1px solid #dcdcde;border-radius:6px;padding:0.6rem;margin-bottom:0.6rem;">';
		echo '<p style="margin:0 0 0.4rem;"><input type="text" name="cta_tp_label[]" value="' . esc_attr( $label ) . '" style="width:100%;" placeholder="Short label, e.g. Protects our drinking water"></p>';
		echo '<textarea name="cta_tp_text[]" rows="3" style="width:100%;" placeholder="The paragraph this point adds to the comment. Links inline are fine.">' . esc_textarea( $text ) . '</textarea>';
		echo '<p style="margin:0.4rem 0 0;"><button type="button" class="button cta-remove-tp">Remove Point</button></p>';
		echo '</div>';
	}

	public function save_meta_data( $post_id ) {

		if ( ! isset( $_POST['cta_meta_box_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cta_meta_box_nonce'] ) ), 'cta_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['cta_summary'] ) ) {
			update_post_meta(
				$post_id,
				'_cta_summary',
				wp_kses_post( wp_unslash( $_POST['cta_summary'] ) )
			);
		}

		$ongoing = ! empty( $_POST['cta_ongoing'] ) ? 1 : 0;
		update_post_meta( $post_id, '_cta_ongoing', $ongoing );

		$ended = ! empty( $_POST['cta_ended'] ) ? 1 : 0;
		update_post_meta( $post_id, '_cta_ended', $ended );

		if ( ! $ongoing && isset( $_POST['cta_end'] ) ) {
			update_post_meta(
				$post_id,
				'_cta_end',
				sanitize_text_field( wp_unslash( $_POST['cta_end'] ) )
			);
		} elseif ( $ongoing ) {
			// Clear the deadline when marked ongoing so it never expires out of queries.
			delete_post_meta( $post_id, '_cta_end' );
		}

		if ( isset( $_POST['cta_links_url'] ) && is_array( $_POST['cta_links_url'] ) ) {
			$urls   = array_map( 'esc_url_raw', wp_unslash( $_POST['cta_links_url'] ) );
			$labels = isset( $_POST['cta_links_label'] ) && is_array( $_POST['cta_links_label'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['cta_links_label'] ) )
				: [];
			$links  = [];
			foreach ( $urls as $i => $url ) {
				$url = esc_url_raw( $url );
				if ( $url ) {
					$links[] = [
						'url'   => $url,
						'label' => sanitize_text_field( $labels[ $i ] ?? '' ),
					];
				}
			}
			update_post_meta( $post_id, '_cta_links', $links );
		} else {
			delete_post_meta( $post_id, '_cta_links' );
		}

		if ( isset( $_POST['cta_files_id'] ) && is_array( $_POST['cta_files_id'] ) ) {
			$ids    = array_map( 'intval', wp_unslash( $_POST['cta_files_id'] ) );
			$labels = isset( $_POST['cta_files_label'] ) && is_array( $_POST['cta_files_label'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['cta_files_label'] ) )
				: [];
			$files  = [];
			foreach ( $ids as $i => $id ) {
				if ( $id ) {
					$files[] = [
						'id'    => $id,
						'label' => sanitize_text_field( $labels[ $i ] ?? '' ),
					];
				}
			}
			update_post_meta( $post_id, '_cta_files', $files );
		} else {
			delete_post_meta( $post_id, '_cta_files' );
		}

		if ( isset( $_POST['cta_videos_url'] ) && is_array( $_POST['cta_videos_url'] ) ) {
			$urls   = array_map( 'esc_url_raw', wp_unslash( $_POST['cta_videos_url'] ) );
			$labels = isset( $_POST['cta_videos_label'] ) && is_array( $_POST['cta_videos_label'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['cta_videos_label'] ) )
				: [];
			$videos = [];
			foreach ( $urls as $i => $url ) {
				$url = esc_url_raw( $url );
				if ( $url ) {
					$videos[] = [
						'url'   => $url,
						'label' => sanitize_text_field( $labels[ $i ] ?? '' ),
					];
				}
			}
			update_post_meta( $post_id, '_cta_videos', $videos );
		} else {
			delete_post_meta( $post_id, '_cta_videos' );
		}

		if ( isset( $_POST['cta_steps'] ) && is_array( $_POST['cta_steps'] ) ) {
			$steps       = array_map( 'wp_kses_post', wp_unslash( $_POST['cta_steps'] ) );
			$clean_steps = array_map( 'wp_kses_post', array_filter( $steps ) );
			update_post_meta( $post_id, '_cta_steps', $clean_steps );
		} else {
			delete_post_meta( $post_id, '_cta_steps' );
		}

		if ( isset( $_POST['cta_sample_texts'] ) && is_array( $_POST['cta_sample_texts'] ) ) {
			$sample_texts       = array_map( 'sanitize_textarea_field', wp_unslash( $_POST['cta_sample_texts'] ) );
			$clean_sample_texts = array_map( 'sanitize_textarea_field', array_filter( $sample_texts ) );
			update_post_meta( $post_id, '_cta_sample_texts', $clean_sample_texts );
		} else {
			delete_post_meta( $post_id, '_cta_sample_texts' );
		}

		if ( isset( $_POST['cta_legislator_url'] ) ) {
			$legislator_url = esc_url_raw( wp_unslash( $_POST['cta_legislator_url'] ) );
			update_post_meta( $post_id, '_cta_legislator_url', $legislator_url );
		}

		if ( isset( $_POST['cta_button_text'] ) ) {
			$button_text = sanitize_text_field( wp_unslash( $_POST['cta_button_text'] ) );
			update_post_meta( $post_id, '_cta_button_text', $button_text );
		}

		$this->save_guided_data( $post_id );
	}

	/**
	 * Save the Guided (comment builder) fields. Counters (_cta_builds, _cta_confirmed)
	 * are deliberately NOT touched here; they are owned by the public tracking route.
	 */
	private function save_guided_data( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified in save_meta_data() before this method runs.

		$format = isset( $_POST['cta_format'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_format'] ) ) : 'simple';
		update_post_meta( $post_id, '_cta_format', $this->sanitize_format( $format ) );

		// Talking points: parallel label[] and text[] arrays.
		if ( isset( $_POST['cta_tp_label'] ) && is_array( $_POST['cta_tp_label'] ) ) {
			$labels = array_map( 'sanitize_text_field', wp_unslash( $_POST['cta_tp_label'] ) );
			$texts  = isset( $_POST['cta_tp_text'] ) && is_array( $_POST['cta_tp_text'] )
				? array_map( 'wp_kses_post', wp_unslash( $_POST['cta_tp_text'] ) )
				: [];
			$points = [];
			foreach ( $labels as $i => $label ) {
				$label = sanitize_text_field( $label );
				$text  = wp_kses_post( $texts[ $i ] ?? '' );
				if ( '' === $label && '' === $text ) {
					continue;
				}
				$points[] = [
					'label' => $label,
					'text'  => $text,
				];
			}
			update_post_meta( $post_id, '_cta_talking_points', $points );
		} else {
			delete_post_meta( $post_id, '_cta_talking_points' );
		}

		if ( isset( $_POST['cta_personal_prompts'] ) && is_array( $_POST['cta_personal_prompts'] ) ) {
			$prompts = array_map( 'sanitize_text_field', wp_unslash( $_POST['cta_personal_prompts'] ) );
			$prompts = array_map( 'sanitize_text_field', array_filter( $prompts ) );
			update_post_meta( $post_id, '_cta_personal_prompts', array_values( $prompts ) );
		} else {
			delete_post_meta( $post_id, '_cta_personal_prompts' );
		}

		$scalars = [
			'cta_guided_intro'   => [ '_cta_guided_intro', 'sanitize_textarea_field' ],
			'cta_guided_closing' => [ '_cta_guided_closing', 'sanitize_textarea_field' ],
			'cta_comment_url'    => [ '_cta_comment_url', 'esc_url_raw' ],
			'cta_agency'         => [ '_cta_agency', 'sanitize_text_field' ],
			'cta_docket'         => [ '_cta_docket', 'sanitize_text_field' ],
		];
		foreach ( $scalars as $field => $spec ) {
			if ( isset( $_POST[ $field ] ) ) {
				$clean = call_user_func( $spec[1], wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the per-field callback in $scalars.
				update_post_meta( $post_id, $spec[0], $clean );
			}
		}

		if ( isset( $_POST['cta_comment_goal'] ) ) {
			update_post_meta( $post_id, '_cta_comment_goal', absint( wp_unslash( $_POST['cta_comment_goal'] ) ) );
		}

		$char_limit_mode = isset( $_POST['cta_char_limit_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_char_limit_mode'] ) ) : 'default';
		update_post_meta( $post_id, '_cta_char_limit_mode', in_array( $char_limit_mode, [ 'default', 'custom' ], true ) ? $char_limit_mode : 'default' );
		if ( isset( $_POST['cta_char_limit'] ) ) {
			update_post_meta( $post_id, '_cta_char_limit', absint( wp_unslash( $_POST['cta_char_limit'] ) ) );
		}

		$identity_mode = isset( $_POST['cta_collect_identity'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_collect_identity'] ) ) : 'default';
		update_post_meta( $post_id, '_cta_collect_identity', in_array( $identity_mode, [ 'default', 'on', 'off' ], true ) ? $identity_mode : 'default' );

		update_post_meta( $post_id, '_cta_show_counter', empty( $_POST['cta_show_counter'] ) ? 0 : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}