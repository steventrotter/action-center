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
			'sanitize_callback' => 'sanitize_text_field',
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
			$url = isset( $item['url'] ) ? sanitize_text_field( $item['url'] ) : '';
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

	public function enqueue_scripts() {
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

		echo '<script>
			(function() {
				var cb         = document.getElementById("cta_ongoing");
				var deadlineRow = document.getElementById("cta-deadline-row");
				var endedRow    = document.getElementById("cta-ended-row");
				if (!cb) return;
				cb.addEventListener("change", function() {
					if (deadlineRow) deadlineRow.style.display = cb.checked ? "none" : "";
					if (endedRow)    endedRow.style.display    = cb.checked ? "" : "none";
				});
			})();
		</script>';
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

		echo '<div id="cta-steps-wrapper">';

		$index = 0;
		foreach ( $steps as $step ) {
			$editor_id = 'cta_step_editor_initial_' . $index;
			
			echo '<div class="cta-step-item">';
			echo '<p><strong>Step ' . ( $index + 1 ) . '</strong></p>';
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
			echo '<p><strong>Option ' . ( $index + 1 ) . '</strong></p>';
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

	public function save_meta_data( $post_id ) {

		if ( ! isset( $_POST['cta_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['cta_meta_box_nonce'], 'cta_meta_box' ) ) {
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
			$urls   = array_map( 'wp_unslash', $_POST['cta_links_url'] );
			$labels = isset( $_POST['cta_links_label'] ) && is_array( $_POST['cta_links_label'] )
				? array_map( 'wp_unslash', $_POST['cta_links_label'] )
				: [];
			$links  = [];
			foreach ( $urls as $i => $url ) {
				$url = sanitize_text_field( $url );
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
				? array_map( 'wp_unslash', $_POST['cta_files_label'] )
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
			$urls   = array_map( 'wp_unslash', $_POST['cta_videos_url'] );
			$labels = isset( $_POST['cta_videos_label'] ) && is_array( $_POST['cta_videos_label'] )
				? array_map( 'wp_unslash', $_POST['cta_videos_label'] )
				: [];
			$videos = [];
			foreach ( $urls as $i => $url ) {
				$url = sanitize_text_field( $url );
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
			$steps       = array_map( 'wp_unslash', $_POST['cta_steps'] );
			$clean_steps = array_map( 'wp_kses_post', array_filter( $steps ) );
			update_post_meta( $post_id, '_cta_steps', $clean_steps );
		} else {
			delete_post_meta( $post_id, '_cta_steps' );
		}

		if ( isset( $_POST['cta_sample_texts'] ) && is_array( $_POST['cta_sample_texts'] ) ) {
			$sample_texts       = array_map( 'wp_unslash', $_POST['cta_sample_texts'] );
			$clean_sample_texts = array_map( 'sanitize_textarea_field', array_filter( $sample_texts ) );
			update_post_meta( $post_id, '_cta_sample_texts', $clean_sample_texts );
		} else {
			delete_post_meta( $post_id, '_cta_sample_texts' );
		}

		if ( isset( $_POST['cta_legislator_url'] ) ) {
			$legislator_url = sanitize_text_field( wp_unslash( $_POST['cta_legislator_url'] ) );
			update_post_meta( $post_id, '_cta_legislator_url', $legislator_url );
		}

		if ( isset( $_POST['cta_button_text'] ) ) {
			$button_text = sanitize_text_field( wp_unslash( $_POST['cta_button_text'] ) );
			update_post_meta( $post_id, '_cta_button_text', $button_text );
		}
	}
}