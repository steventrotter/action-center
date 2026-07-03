<?php
/**
 * Frontend display and admin settings.
 *
 * @package Calls_To_Action_Manager
 */

defined( 'ABSPATH' ) || exit;

class CTA_Display {

	public static $import_success = false;

	/**
	 * Get a short timezone label like 'PST' or 'UTC+02:00' from site settings.
	 */
	public static function get_timezone_abbr() {
		$tz_string = wp_timezone_string();
		if ( ! $tz_string ) {
			return '';
		}

		// If already an offset string like '+02:00', label it clearly.
		if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $tz_string ) ) {
			return 'UTC' . $tz_string;
		}

		try {
			$dtz = new DateTimeZone( $tz_string );
			$now = new DateTime( 'now', $dtz );
			return $now->format( 'T' ); // e.g., PST, PDT, CET.
		} catch ( Exception $e ) {
			return $tz_string;
		}
	}

	/**
	 * Get trimmed summary for cards without cutting words.
	 */
	protected static function get_trimmed_summary( $text ) {
		$text = trim( wp_strip_all_tags( $text ) );

		$length = (int) get_option( 'cta_manager_trim_length', 200 );
		if ( $length <= 0 || mb_strlen( $text, 'UTF-8' ) <= $length ) {
			return $text;
		}

		$breakpoint = mb_strpos( $text, ' ', $length, 'UTF-8' );
		if ( false === $breakpoint ) {
			$breakpoint = $length;
		}

		$trimmed = mb_substr( $text, 0, $breakpoint, 'UTF-8' );

		return rtrim( $trimmed ) . '...';
	}

	public function __construct() {
		add_shortcode( 'cta_list', [ $this, 'render_cta_list' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_styles' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_import_notice' ] );
	}

	public function enqueue_front_styles() {
		wp_enqueue_style(
			'cta-display',
			plugin_dir_url( __FILE__ ) . '../assets/cta-display.css',
			[],
			CTA_MANAGER_VERSION
		);
	}

	public function add_settings_page() {
		add_options_page(
			'Action Center',
			'Action Center',
			'manage_options',
			'action-center-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		if ( ! in_array( $tab, [ 'settings', 'documentation' ], true ) ) {
			$tab = 'settings';
		}
		$base_url = admin_url( 'options-general.php?page=action-center-settings' );

		echo '<div class="wrap"><h1>Action Center</h1>';

		echo '<nav class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base_url ) . '" class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '">Settings</a>';
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'documentation', $base_url ) ) . '" class="nav-tab' . ( 'documentation' === $tab ? ' nav-tab-active' : '' ) . '">Documentation</a>';
		echo '</nav>';

		if ( 'documentation' === $tab ) {
			$this->render_documentation_tab();
			echo '</div>';
			return;
		}

		// Save settings.
		if ( isset( $_POST['cta_manager_save_settings'] ) && check_admin_referer( 'cta_manager_settings_nonce' ) ) {
			$length = isset( $_POST['cta_manager_trim_length'] ) ? (int) $_POST['cta_manager_trim_length'] : 0;
			if ( $length < 0 ) {
				$length = 0;
			}
			update_option( 'cta_manager_trim_length', $length );

			$page_id = isset( $_POST['cta_manager_action_center_page'] ) ? (int) $_POST['cta_manager_action_center_page'] : 0;
			update_option( 'cta_manager_action_center_page', $page_id );

			$federal_url = isset( $_POST['cta_manager_legislator_federal_url'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_manager_legislator_federal_url'] ) ) : '';
			update_option( 'cta_manager_legislator_federal_url', $federal_url );

			$state_url = isset( $_POST['cta_manager_legislator_state_url'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_manager_legislator_state_url'] ) ) : '';
			update_option( 'cta_manager_legislator_state_url', $state_url );

			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		// Export.
		if ( isset( $_POST['cta_export'] ) && check_admin_referer( 'cta_export_nonce' ) ) {
			self::handle_export();
		}

		// Import.
		if (
			isset( $_POST['cta_import'] )
			&& check_admin_referer( 'cta_import_nonce' )
			&& ! empty( $_FILES['cta_import_file']['tmp_name'] )
		) {
			self::handle_import( $_FILES['cta_import_file']['tmp_name'] );
		}

		$trim_length        = (int) get_option( 'cta_manager_trim_length', 200 );
		$action_center_page = (int) get_option( 'cta_manager_action_center_page', 0 );
		$legislator_federal = get_option( 'cta_manager_legislator_federal_url', 'https://www.congress.gov/members/find-your-member' );
		$legislator_state   = get_option( 'cta_manager_legislator_state_url', '' );

		echo '<p>Use the shortcode <code>[cta_list]</code> to display active Calls to Action on any page or post. See the Documentation tab for everything else.</p>';

		echo '<hr><h2>Display Settings</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cta_manager_settings_nonce' );
		echo '<table class="form-table"><tbody>';
		echo '<tr>';
		echo '<th scope="row"><label for="cta_manager_action_center_page">Action Center page</label></th>';
		echo '<td>';
		wp_dropdown_pages(
			[
				'name'              => 'cta_manager_action_center_page',
				'id'                => 'cta_manager_action_center_page',
				'selected'          => $action_center_page,
				'show_option_none'  => 'Auto-detect (page with slug "act-now")',
				'option_none_value' => '0',
			]
		);
		echo '<p class="description">The page containing the <code>[cta_list]</code> shortcode. Used for "back to all actions" links and block "View More" buttons.</p>';
		echo '</td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="cta_manager_trim_length">Card description length</label></th>';
		echo '<td>';
		echo '<input name="cta_manager_trim_length" id="cta_manager_trim_length" type="number" min="0" step="10" value="' . esc_attr( $trim_length ) . '" class="small-text" /> ';
		echo '<span class="description">Maximum characters for card summaries. 0 = no trimming.</span>';
		echo '</td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="cta_manager_legislator_federal_url">Federal legislator lookup URL</label></th>';
		echo '<td>';
		echo '<input name="cta_manager_legislator_federal_url" id="cta_manager_legislator_federal_url" type="text" value="' . esc_attr( $legislator_federal ) . '" class="regular-text" />';
		echo '<p class="description">Shown on Contact Your Legislator CTAs. Default: congress.gov finder.</p>';
		echo '</td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="cta_manager_legislator_state_url">State legislator lookup URL</label></th>';
		echo '<td>';
		echo '<input name="cta_manager_legislator_state_url" id="cta_manager_legislator_state_url" type="text" value="' . esc_attr( $legislator_state ) . '" class="regular-text" />';
		echo '<p class="description">Shown on Contact Your Legislator CTAs. Set this to your state or regional legislator finder. Leave blank to hide the state link.</p>';
		echo '</td>';
		echo '</tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" name="cta_manager_save_settings" class="button button-primary">Save Settings</button></p>';
		echo '</form>';

		// Import/export forms.
		echo '<hr><h2>Import CTAs</h2>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'cta_import_nonce' );
		echo '<input type="file" name="cta_import_file" required />';
		echo ' <button type="submit" name="cta_import" class="button button-primary">Import</button>';
		echo '</form>';

		echo '<hr><h2>Export CTAs</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cta_export_nonce' );
		echo '<button type="submit" name="cta_export" class="button">Export Published CTAs</button>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Documentation tab: how to use the plugin, the feed, and AI-assisted creation.
	 */
	private function render_documentation_tab() {
		$feed_url = esc_url( rest_url( 'action-center/v1/actions' ) );

		echo '<div class="action-center-docs" style="max-width: 820px;">';

		echo '<h2>Getting started</h2>';
		echo '<p>Action Center manages Calls to Action (CTAs): petitions, public comment windows, letter-writing campaigns, volunteer asks - anything you want visitors to act on. Each CTA is created under the <strong>CTAs</strong> menu and has a title, a featured image, a "Why this Matters" summary, an optional deadline (or an Ongoing flag), Steps to Take, copy-paste Sample Texts, Related Links, Files, and Videos, plus Organization and CTA Type tags.</p>';
		echo '<p>Publish a CTA and it appears automatically on your Action Center listing page. When its deadline passes, it drops off the listing and its detail page shows an expired notice. Ongoing CTAs stay listed until you check "Mark as ended".</p>';

		echo '<h2>Displaying CTAs</h2>';
		echo '<p>Create a page (for example "Act Now") and add the shortcode <code>[cta_list]</code>. That page becomes your Action Center: it lists urgent actions with upcoming deadlines first, then ongoing actions, with filters by type and organization. Select this page under Settings so back-links and buttons point to it.</p>';
		echo '<p>To feature CTAs elsewhere (like your homepage), use the <strong>Upcoming CTAs</strong> block in the block editor. It shows current actions as cards with a View More button linking to your Action Center page.</p>';
		echo '<p>If a CTA is given the type "Contact Your Legislator" (term slug <code>contact-your-legislator</code>), its detail page automatically shows a Find Your Legislators section using the lookup URLs from Settings.</p>';

		echo '<h2>Public JSON feed</h2>';
		echo '<p>The plugin serves your active CTAs as a public JSON feed - no authentication needed. Other websites, apps, and partner tools can display your current actions from:</p>';
		echo '<p><code>' . $feed_url . '</code></p>';
		echo '<p>Each item carries: <code>id</code>, <code>title</code> (up to 80 characters), <code>summary</code> (plain text, up to 240 characters), <code>url</code> (the CTA detail page), <code>urgency</code> ("now" for deadline actions, "ongoing" for open-ended ones), <code>date</code> (published), <code>expires</code> (deadline date, when set), <code>image</code> (featured image, when set), and <code>organizations</code> / <code>types</code> arrays. Deadline actions come first (soonest deadline at the top), then ongoing actions (newest first), capped at 20 by default.</p>';
		echo '<p>Optional query parameters: <code>?limit=</code> (1-50), <code>?urgency=now</code> or <code>?urgency=ongoing</code>, <code>?type=</code> and <code>?org=</code> (taxonomy slugs). Expired and ended CTAs are excluded automatically, so consumers never need to filter them out. The feed sends <code>Access-Control-Allow-Origin: *</code>, so browser-based widgets can read it directly.</p>';

		echo '<h2>Creating CTAs with an AI assistant</h2>';
		echo '<p>Action Center registers a tool named <code>cta_manager_create_cta</code> with the <a href="https://github.com/Automattic/wordpress-mcp" target="_blank" rel="noopener noreferrer">WordPress MCP plugin</a> by Automattic. Once that plugin is installed and connected to an AI assistant such as Claude, the assistant can draft complete CTAs for you - title, summary, steps, sample texts, links, deadline, and tags - in a single step. New CTAs default to draft status so you always review before publishing.</p>';
		echo '<p>Setup: install and activate the WordPress MCP plugin, enable create tools under its settings, and connect your AI assistant to your site following that plugin\'s instructions. Then hand the assistant a link to any action page and a prompt like this:</p>';
		echo '<blockquote style="border-left: 4px solid #2271b1; margin: 1em 0; padding: 0.5em 1em; background: #f6f7f7;"><p>Here is a link to an action our supporters should know about: [paste URL]. Read the page and create a draft Call to Action on our site using the cta_manager_create_cta tool. Write a short "Why this Matters" summary in our voice, list ordered Steps to Take with the relevant links inline, include one or two sample messages supporters can copy, and add the source URL to Related Links. If the source gives a deadline, set it; otherwise mark the action as ongoing. Tag the host organization and pick a sensible CTA type. Give me the edit link when done.</p></blockquote>';
		echo '<p>The assistant replies with the draft\'s edit link; review it in wp-admin and publish. Sites without an AI assistant can ignore this section - the plugin works fully without it.</p>';

		echo '<h2>Updates</h2>';
		echo '<p>Action Center checks its <a href="https://github.com/steventrotter/action-center" target="_blank" rel="noopener noreferrer">GitHub repository</a> for new releases and offers them through the normal WordPress updates screen - no extra setup needed. Questions or problems? Write to the author via <a href="https://steventrotter.com" target="_blank" rel="noopener noreferrer">steventrotter.com</a>.</p>';

		echo '</div>';
	}

	public static function maybe_show_import_notice() {
		if ( self::$import_success ) {
			echo '<div class="notice notice-success is-dismissible"><p>✅ CTAs imported successfully.</p></div>';
		}
	}

	public static function handle_export() {
		$args  = [
			'post_type'      => 'cta',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		];
		$query = new WP_Query( $args );
		$data  = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$item = [
					'post_title'       => get_the_title(),
					'post_status'      => 'publish',
					'_cta_summary'     => get_post_meta( get_the_ID(), '_cta_summary', true ),
					'_cta_end'         => get_post_meta( get_the_ID(), '_cta_end', true ),
					'_cta_links'       => get_post_meta( get_the_ID(), '_cta_links', true ),
					'_cta_files'       => get_post_meta( get_the_ID(), '_cta_files', true ),
					'_cta_steps'       => get_post_meta( get_the_ID(), '_cta_steps', true ),
					'_cta_sample_text' => get_post_meta( get_the_ID(), '_cta_sample_text', true ),
					'_cta_button_text' => get_post_meta( get_the_ID(), '_cta_button_text', true ),
					'tax_input'        => [
						'cta_org'  => wp_get_object_terms(
							get_the_ID(),
							'cta_org',
							[ 'fields' => 'names' ]
						),
						'cta_type' => wp_get_object_terms(
							get_the_ID(),
							'cta_type',
							[ 'fields' => 'names' ]
						),
						'cta_tag'  => wp_get_object_terms(
							get_the_ID(),
							'cta_tag',
							[ 'fields' => 'names' ]
						),
					],
				];
				$data[] = $item;
			}
			wp_reset_postdata();
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="cta-export.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
		exit;
	}

	public static function handle_import( $file_path ) {
		$json  = file_get_contents( $file_path );
		$items = json_decode( $json, true );

		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$post_id = wp_insert_post(
				[
					'post_title'  => isset( $item['post_title'] ) ? $item['post_title'] : '',
					'post_status' => 'publish',
					'post_type'   => 'cta',
				]
			);

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			$meta_keys = [
				'_cta_summary',
				'_cta_end',
				'_cta_links',
				'_cta_files',
				'_cta_steps',
				'_cta_sample_text',
				'_cta_button_text',
			];

			foreach ( $meta_keys as $key ) {
				if ( isset( $item[ $key ] ) ) {
					update_post_meta( $post_id, $key, $item[ $key ] );
				}
			}

			if ( ! empty( $item['tax_input']['cta_org'] ) ) {
				wp_set_object_terms( $post_id, $item['tax_input']['cta_org'], 'cta_org' );
			}
			if ( ! empty( $item['tax_input']['cta_type'] ) ) {
				wp_set_object_terms( $post_id, $item['tax_input']['cta_type'], 'cta_type' );
			}
			if ( ! empty( $item['tax_input']['cta_tag'] ) ) {
				wp_set_object_terms( $post_id, $item['tax_input']['cta_tag'], 'cta_tag' );
			}
		}

		self::$import_success = true;
	}

	/**
	 * Shortcode: [cta_list]
	 */
	public function render_cta_list() {

		$current_type = isset( $_GET['cta_type'] ) ? sanitize_text_field( wp_unslash( $_GET['cta_type'] ) ) : '';
		$current_org  = isset( $_GET['cta_org'] ) ? sanitize_text_field( wp_unslash( $_GET['cta_org'] ) ) : '';

		$paged = max( 1, (int) get_query_var( 'paged', 1 ) );

		// Build shared tax query.
		$tax_query = [];
		if ( $current_type ) {
			$tax_query[] = [
				'taxonomy' => 'cta_type',
				'field'    => 'slug',
				'terms'    => $current_type,
			];
		}
		if ( $current_org ) {
			$tax_query[] = [
				'taxonomy' => 'cta_org',
				'field'    => 'slug',
				'terms'    => $current_org,
			];
		}

		// Query: Urgent Actions (have a future deadline).
		$urgent_args = [
			'post_type'      => 'cta',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'paged'          => $paged,
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
			$urgent_args['tax_query'] = $tax_query;
		}
		$urgent_query = new WP_Query( $urgent_args );

		// Query: Ongoing Actions (marked as ongoing, not ended, no deadline).
		$ongoing_args = [
			'post_type'      => 'cta',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
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

		ob_start();

		// Filters UI.
		echo '<div id="cta-filters" class="cta-filters cta-filters--archive">';

		$types = get_terms( [ 'taxonomy' => 'cta_type', 'hide_empty' => true ] );
		if ( ! empty( $types ) && ! is_wp_error( $types ) ) {
			echo '<div class="cta-filters__group cta-filters__group--type">';
			echo '<label for="cta-filter-type" class="cta-filters__label">Filter by Type</label>';
			echo '<select id="cta-filter-type" class="cta-filters__select cta-filters__select--type">';
			echo '<option value="">All types</option>';
			foreach ( $types as $type ) {
				$selected = ( $current_type === $type->slug ) ? ' selected' : '';
				echo '<option value="' . esc_attr( $type->slug ) . '"' . $selected . '>' . esc_html( $type->name ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		$org_terms = get_terms( [ 'taxonomy' => 'cta_org', 'hide_empty' => true ] );
		if ( ! empty( $org_terms ) && ! is_wp_error( $org_terms ) ) {
			echo '<div class="cta-filters__group cta-filters__group--org">';
			echo '<label for="cta-filter-org" class="cta-filters__label">Filter by Organization</label>';
			echo '<select id="cta-filter-org" class="cta-filters__select cta-filters__select--org">';
			echo '<option value="">All organizations</option>';
			foreach ( $org_terms as $org ) {
				$selected = ( $current_org === $org->slug ) ? ' selected' : '';
				echo '<option value="' . esc_attr( $org->slug ) . '"' . $selected . '>' . esc_html( $org->name ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		echo '</div>';

		// ---- Urgent Actions section ----
		echo '<section class="cta-section-group cta-section-group--urgent">';
		echo '<h2 class="cta-section-group__title">Urgent Actions</h2>';
		echo '<p class="cta-section-group__description">These actions have upcoming deadlines. Act soon!</p>';
		echo '<div id="cta-list" class="cta-list cta-list--archive cta-list--grid">';

		if ( $urgent_query->have_posts() ) {
			while ( $urgent_query->have_posts() ) {
				$urgent_query->the_post();
				echo self::render_cta_card( get_the_ID() );
			}
			wp_reset_postdata();
		} else {
			echo '<p class="cta-list__empty">No urgent actions match those filters right now.</p>';
		}

		echo '</div>';

		// Pagination for urgent actions.
		if ( $urgent_query->max_num_pages > 1 ) {
			$params = [];
			if ( $current_type ) $params['cta_type'] = $current_type;
			if ( $current_org )  $params['cta_org']  = $current_org;
			$links = paginate_links( [
				'base'      => trailingslashit( get_pagenum_link( 1 ) ) . '%_%',
				'format'    => 'page/%#%/',
				'current'   => $paged,
				'total'     => $urgent_query->max_num_pages,
				'type'      => 'list',
				'add_args'  => $params,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			] );
			if ( $links ) {
				echo '<nav class="cta-pagination" aria-label="Urgent Actions">' . $links . '</nav>';
			}
		}

		echo '</section>';

		// ---- Ongoing Actions section ----
		if ( $ongoing_query->have_posts() ) {
			echo '<section class="cta-section-group cta-section-group--ongoing">';
			echo '<h2 class="cta-section-group__title">Ongoing Actions</h2>';
			echo '<p class="cta-section-group__description">These actions have no current deadline but still need your support.</p>';
			echo '<div class="cta-list cta-list--archive cta-list--grid">';

			while ( $ongoing_query->have_posts() ) {
				$ongoing_query->the_post();
				echo self::render_cta_card( get_the_ID() );
			}
			wp_reset_postdata();

			echo '</div>';
			echo '</section>';
		}
		?>
		<script>
			(function() {
				function updateCTAQuery() {
					var baseUrl = window.location.href.split('?')[0];
					var params  = new URLSearchParams(window.location.search);

					var typeSelect = document.getElementById('cta-filter-type');
					var orgSelect  = document.getElementById('cta-filter-org');

					if (typeSelect) {
						if (typeSelect.value) { params.set('cta_type', typeSelect.value); }
						else { params.delete('cta_type'); }
					}
					if (orgSelect) {
						if (orgSelect.value) { params.set('cta_org', orgSelect.value); }
						else { params.delete('cta_org'); }
					}

					var qs = params.toString();
					window.location.href = qs ? baseUrl + '?' + qs : baseUrl;
				}

				var typeSel = document.getElementById('cta-filter-type');
				var orgSel  = document.getElementById('cta-filter-org');
				if (typeSel) typeSel.addEventListener('change', updateCTAQuery);
				if (orgSel)  orgSel.addEventListener('change', updateCTAQuery);
			})();
		</script>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render a single CTA card. Used by both the shortcode and block.
	 */
	public static function render_cta_card( $post_id ) {
		$summary_raw = get_post_meta( $post_id, '_cta_summary', true );
		$summary     = esc_html( self::get_trimmed_summary( $summary_raw ) );

		$end         = get_post_meta( $post_id, '_cta_end', true );
		$ongoing     = (bool) get_post_meta( $post_id, '_cta_ongoing', true );
		$ended       = (bool) get_post_meta( $post_id, '_cta_ended', true );
		$end_display = '';
		if ( ! $ongoing && $end ) {
			$tz_abbr     = self::get_timezone_abbr();
			$end_display = date_i18n( 'F j, Y \a\t g:ia', strtotime( $end ) );
			if ( $tz_abbr ) {
				$end_display .= ' ' . $tz_abbr;
			}
		}

		$button_text = get_post_meta( $post_id, '_cta_button_text', true );
		if ( $button_text === '' ) {
			$button_text = 'Learn More';
		}

		$classes = 'cta-card cta-card--archive';
		if ( $ongoing ) {
			$classes .= ' cta-card--ongoing';
		}

		ob_start();

		echo '<article class="' . esc_attr( $classes ) . '" data-cta-id="' . esc_attr( $post_id ) . '">';
		echo '<div class="cta-card__body">';
		echo '<h3 class="cta-card__title"><a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></h3>';
		if ( $summary ) {
			echo '<p class="cta-card__summary">' . $summary . '</p>';
		}
		if ( $ongoing && ! $ended ) {
			echo '<p class="cta-card__deadline"><strong>Deadline:</strong><br>Ongoing Action</p>';
		} elseif ( $end_display ) {
			echo '<p class="cta-card__deadline"><strong>Deadline:</strong><br>' . esc_html( $end_display ) . '</p>';
		}
		echo '</div>';
		echo '<p class="cta-card__actions"><a class="cta-btn cta-card__button" href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( $button_text ) . '</a></p>';
		echo '</article>';

		return ob_get_clean();
	}
}

/**
 * Hook into single CTA templates to replace content layout.
 */
function cta_manager_template_include( $template ) {
	if ( is_singular( 'cta' ) ) {
		add_filter( 'the_content', 'cta_manager_single_cta_content' );
	}
	return $template;
}
add_filter( 'template_include', 'cta_manager_template_include' );

/**
 * Single CTA content layout.
 */
/**
 * Smart content renderer -- applies wpautop() only to plain text content.
 * Content saved via wp_editor() already contains <p> tags and should not be
 * passed through wpautop() or it will be double-wrapped / mangled.
 * Content saved before wp_editor() was added (plain text with newlines) needs
 * wpautop() to get paragraph breaks.
 */
function cta_manager_render_content( $text ) {
	if ( empty( $text ) ) {
		return '';
	}
	// If content already has block-level HTML, output as-is
	if ( preg_match( '/<(p|ul|ol|h[1-6]|blockquote|div)[^>]*>/i', $text ) ) {
		return wp_kses_post( $text );
	}
	// Plain text -- convert newlines to paragraphs
	return wp_kses_post( wpautop( $text ) );
}

function cta_manager_single_cta_content( $content ) {
	if ( ! is_singular( 'cta' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$summary          = get_post_meta( get_the_ID(), '_cta_summary', true );
	$end              = get_post_meta( get_the_ID(), '_cta_end', true );
	$ongoing          = (bool) get_post_meta( get_the_ID(), '_cta_ongoing', true );
	$links            = get_post_meta( get_the_ID(), '_cta_links', true );
	$files            = get_post_meta( get_the_ID(), '_cta_files', true );
	$steps            = get_post_meta( get_the_ID(), '_cta_steps', true );
	$sample_texts     = get_post_meta( get_the_ID(), '_cta_sample_texts', true );
	$videos           = get_post_meta( get_the_ID(), '_cta_videos', true );
	$legislator_url   = get_post_meta( get_the_ID(), '_cta_legislator_url', true );

	$tz_abbr     = CTA_Display::get_timezone_abbr();
	$end_display = ( ! $ongoing && $end ) ? date_i18n( 'F j, Y \a\t g:ia', strtotime( $end ) ) : '';
	if ( $end_display && $tz_abbr ) {
		$end_display .= ' ' . $tz_abbr;
	}

	// Check if CTA has expired or been manually ended.
	$ended      = (bool) get_post_meta( get_the_ID(), '_cta_ended', true );
	$is_expired = false;
	if ( $ongoing && $ended ) {
		$is_expired = true;
	} elseif ( ! $ongoing && $end ) {
		$end_timestamp = strtotime( $end );
		$now_timestamp = current_time( 'timestamp' );
		if ( $now_timestamp > $end_timestamp ) {
			$is_expired = true;
		}
	}

	$types = get_the_terms( get_the_ID(), 'cta_type' );
	$orgs  = get_the_terms( get_the_ID(), 'cta_org' );

	// Check if this CTA is tagged as "Contact Your Legislator" (by term slug).
	$is_legislator_cta = false;
	if ( ! empty( $types ) && ! is_wp_error( $types ) ) {
		foreach ( $types as $type_term ) {
			if ( 'contact-your-legislator' === $type_term->slug ) {
				$is_legislator_cta = true;
				break;
			}
		}
	}

	$act_now_url = cta_manager_action_center_url();

	ob_start();

	echo '<article id="cta-single" class="cta-single cta-single--detail" data-cta-id="' . esc_attr( get_the_ID() ) . '">';

	echo '<p class="cta-back-link"><a href="' . esc_url( $act_now_url ) . '">&larr; View All Calls to Action</a></p>';

	// Show expired message if deadline has passed
	if ( $is_expired ) {
		if ( $ongoing && $ended ) {
			echo '<div class="cta-expired-notice" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1.5rem; margin: 2rem 0;">';
			echo '<h2 style="margin-top: 0; color: #856404;">This Action Has Ended</h2>';
			echo '<p style="margin-bottom: 0;">This ongoing action is no longer active. Please <a href="' . esc_url( $act_now_url ) . '">view current calls to action</a> to find opportunities to take action today.</p>';
			echo '</div>';
		} else {
			echo '<div class="cta-expired-notice" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1.5rem; margin: 2rem 0;">';
			echo '<h2 style="margin-top: 0; color: #856404;">This Call to Action Has Expired</h2>';
			echo '<p style="margin-bottom: 0;">The deadline for this action has passed. Please <a href="' . esc_url( $act_now_url ) . '">view current calls to action</a> to find opportunities to take action today.</p>';
			echo '</div>';
		}
	}

	echo '<header class="cta-single__header">';

	if ( $ongoing && ! $ended ) {
		echo '<p class="cta-single__deadline"><strong>Deadline:</strong> Ongoing Action</p>';
	} elseif ( $end_display ) {
		echo '<p class="cta-single__deadline"><strong>Deadline:</strong> ' . esc_html( $end_display ) . '</p>';
	}

	echo '</header>';

	if ( $summary ) {
		echo '<section class="cta-section cta-section--summary">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Why this Matters</h2>';
		echo '<div class="cta-card cta-card--summary">';
		echo '<div class="cta-section__body">';
		echo cta_manager_render_content( $summary );
		echo '</div>';
		echo '</div>';
		echo '</section>';
	}

	if ( ! empty( $videos ) && is_array( $videos ) ) {
		echo '<section class="cta-section cta-section--videos">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Related Videos</h2>';
		echo '<div class="cta-videos-list">';
		foreach ( $videos as $video ) {
			$url   = is_array( $video ) ? ( $video['url'] ?? '' ) : $video;
			$label = is_array( $video ) ? ( $video['label'] ?? '' ) : '';
			if ( ! $url ) {
				continue;
			}
			// Convert YouTube watch, short, and Shorts URLs to embed URLs.
			$embed_url = '';
			if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_\-]{11})#', $url, $m ) ) {
				$embed_url = 'https://www.youtube-nocookie.com/embed/' . $m[1];
			}
			if ( ! $embed_url ) {
				// Not a recognisable YouTube URL -- skip silently.
				continue;
			}
			echo '<div class="cta-video-item">';
			if ( $label ) {
				echo '<p class="cta-video-item__label"><strong>' . esc_html( $label ) . '</strong></p>';
			}
			echo '<div class="cta-video-item__embed">';
			echo '<iframe src="' . esc_url( $embed_url ) . '" title="' . esc_attr( $label ?: 'Video' ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
			echo '</div>';
			echo '<p class="cta-video-item__fallback"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Player not working? Watch on YouTube.</a></p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</section>';
	}

	if ( ! empty( $files ) && is_array( $files ) ) {
		echo '<section class="cta-section cta-section--files">';
		echo '<details class="cta-accordion">';
		echo '<summary class="cta-accordion__trigger cta-section__title cta-section__title--spaced">Related Files</summary>';
		echo '<ul class="cta-files-list">';
		foreach ( $files as $file ) {
			$id    = is_array( $file ) ? (int) ( $file['id'] ?? 0 ) : (int) $file;
			$label = is_array( $file ) ? ( $file['label'] ?? '' ) : '';
			if ( ! $id ) {
				continue;
			}
			$attachment = get_post( $id );
			if ( ! $attachment ) {
				continue;
			}
			if ( ! $label ) {
				$label = get_the_title( $id );
			}
			$url = wp_get_attachment_url( $id );
			echo '<li class="cta-files-list__item"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
		echo '</details>';
		echo '</section>';
	}

	if ( ! empty( $steps ) && is_array( $steps ) ) {
		echo '<section id="cta-steps" class="cta-section cta-section--steps cta-steps-section">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Steps to Take</h2>';
		echo '<div class="cta-steps-grid cta-steps-grid--detail">';
		$step_number = 1;
		foreach ( $steps as $step_html ) {
			if ( trim( $step_html ) === '' ) {
				continue;
			}
			echo '<div class="cta-step-card cta-step-card--detail">';
			echo '<div class="cta-step-number cta-step-card__label">Step ' . intval( $step_number ) . '</div>';
			echo '<div class="cta-step-body cta-step-card__body">' . cta_manager_render_content( $step_html ) . '</div>';
			echo '</div>';
			$step_number++;
		}
		echo '</div>';
		echo '</section>';
	}

	if ( ! empty( $links ) && is_array( $links ) ) {
		$links = array_filter( $links );
	}

	if ( ! empty( $links ) ) {
		echo '<section class="cta-section cta-section--links">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Related Links</h2>';
		echo '<ul class="cta-links-list">';
		foreach ( $links as $link ) {
			$url   = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
			$label = is_array( $link ) ? ( $link['label'] ?? '' ) : '';
			if ( ! $url ) {
				continue;
			}
			if ( ! $label ) {
				$label = preg_replace( '#^https?://#', '', $url );
			}
			echo '<li class="cta-links-list__item"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
		echo '</section>';
	}

	if ( ! empty( $sample_texts ) && is_array( $sample_texts ) ) {
		echo '<section id="cta-sample-text" class="cta-section cta-section--sample-text">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Sample Text</h2>';

		$option_number = 1;
		foreach ( $sample_texts as $sample_text ) {
			if ( trim( $sample_text ) === '' ) {
				continue;
			}

			$textarea_id = 'cta-sample-text-area-' . $option_number;

			echo '<div class="cta-card cta-card--sample-text">';

			if ( count( $sample_texts ) > 1 ) {
				echo '<h3 class="cta-sample-text__option-title">Option ' . $option_number . '</h3>';
			}

			echo '<div class="cta-sample-text__body">';
			echo '<textarea id="' . esc_attr( $textarea_id ) . '" class="cta-sample-text__textarea" readonly>';
			echo esc_textarea( wp_strip_all_tags( $sample_text ) );
			echo '</textarea>';
			echo '</div>';

			echo '<p class="cta-sample-text__copy-row"><a href="#" class="cta-sample-text__copy-link" data-target="' . esc_attr( $textarea_id ) . '">Copy to Clipboard</a></p>';

			echo '</div>';
			$option_number++;
		}

		echo '</section>';
	}

	// Legislator lookup section -- auto-injected for Contact Your Legislator CTA type.
	if ( $is_legislator_cta ) {
		$federal_url   = get_option( 'cta_manager_legislator_federal_url', 'https://www.congress.gov/members/find-your-member' );
		$state_url     = get_option( 'cta_manager_legislator_state_url', '' );
		$default_links = [
			[
				'url'   => $federal_url,
				'label' => 'Find Your Federal Legislators (Congress.gov)',
			],
			[
				'url'   => $state_url,
				'label' => 'Find Your State Legislators',
			],
		];

		echo '<section class="cta-section cta-section--legislators">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Find Your Legislators</h2>';
		echo '<div class="cta-card cta-card--legislators">';
		echo '<p class="cta-legislators__intro">Not sure who your legislators are or how to reach them? Use the links below to find your representatives and their contact information.</p>';

		if ( $legislator_url ) {
			// Custom override URL provided on this CTA -- show it instead of defaults.
			echo '<p><a class="cta-legislators__link" href="' . esc_url( $legislator_url ) . '" target="_blank" rel="noopener noreferrer">Find Your Legislators &rarr;</a></p>';
		} else {
			echo '<ul class="cta-legislators__list">';
			foreach ( $default_links as $leg_link ) {
				if ( empty( $leg_link['url'] ) ) {
					continue;
				}
				echo '<li><a class="cta-legislators__link" href="' . esc_url( $leg_link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $leg_link['label'] ) . ' &rarr;</a></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
		echo '</section>';
	}

	// Type / Organizations pills at bottom, linking back to Act Now with filters.
	if ( ( ! empty( $types ) && ! is_wp_error( $types ) ) || ( ! empty( $orgs ) && ! is_wp_error( $orgs ) ) ) {
		echo '<section class="cta-section cta-section--meta">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Find more actions like this</h2>';
		echo '<p class="cta-meta__intro">Select a tag below to browse other calls to action with the same type or organization.</p>';
		echo '<dl class="cta-meta cta-meta--pills cta-meta--bottom">';

		if ( ! empty( $types ) && ! is_wp_error( $types ) ) {
			echo '<div class="cta-meta__group cta-meta__group--type"><dt>Filter by type</dt><dd>';
			$type_links = [];
			foreach ( $types as $term ) {
				$url = add_query_arg(
					[
						'cta_type' => $term->slug,
					],
					cta_manager_action_center_url()
				);
				$aria         = 'Browse all ' . $term->name . ' actions';
				$type_links[] = '<a class="cta-pill" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $term->name ) . '</a>';
			}
			echo wp_kses_post( implode( ' ', $type_links ) );
			echo '</dd></div>';
		}

		if ( ! empty( $orgs ) && ! is_wp_error( $orgs ) ) {
			echo '<div class="cta-meta__group cta-meta__group--org"><dt>Filter by organization</dt><dd>';
			$org_links = [];
			foreach ( $orgs as $term ) {
				$url = add_query_arg(
					[
						'cta_org' => $term->slug,
					],
					cta_manager_action_center_url()
				);
				$aria        = 'Browse all ' . $term->name . ' actions';
				$org_links[] = '<a class="cta-pill" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $term->name ) . '</a>';
			}
			echo wp_kses_post( implode( ' ', $org_links ) );
			echo '</dd></div>';
		}

		echo '</dl>';
		echo '</section>';
	}

	echo '</article>';

	$output = ob_get_clean();

	return $output;
}

/**
 * Frontend script for Sample Text copy link.
 */
function cta_manager_sample_text_script() {
	if ( ! is_singular( 'cta' ) ) {
		return;
	}
	?>
	<script>
		(function() {
			document.addEventListener('click', function(e) {
				var link = e.target.closest('.cta-sample-text__copy-link');
				if (!link) return;

				e.preventDefault();
				var id = link.getAttribute('data-target');
				var ta = document.getElementById(id);
				if (!ta) return;

				ta.focus();
				ta.select();

				try {
					var ok = document.execCommand('copy');
					if (ok) {
						link.textContent = 'Copied!';
						setTimeout(function() {
							link.textContent = 'Copy to Clipboard';
						}, 1500);
					}
				} catch (err) {}
			});
		})();
	</script>
	<?php
}
add_action( 'wp_footer', 'cta_manager_sample_text_script' );