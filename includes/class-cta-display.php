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
	}

	public function enqueue_front_styles() {
		wp_enqueue_style(
			'cta-display',
			plugin_dir_url( __FILE__ ) . '../assets/cta-display.css',
			[],
			CTA_MANAGER_VERSION
		);

		// Guided comment builder assets, only on a single guided CTA.
		if ( is_singular( 'cta' ) ) {
			$post_id = get_queried_object_id();
			if ( $post_id && 'guided' === get_post_meta( $post_id, '_cta_format', true ) ) {
				wp_enqueue_style(
					'cta-comment-builder',
					plugin_dir_url( __FILE__ ) . '../assets/comment-builder.css',
					[ 'cta-display' ],
					CTA_MANAGER_VERSION
				);
				wp_enqueue_script(
					'cta-comment-builder',
					plugin_dir_url( __FILE__ ) . '../assets/comment-builder.js',
					[],
					CTA_MANAGER_VERSION,
					true
				);
				wp_localize_script(
					'cta-comment-builder',
					'CTA_BUILDER',
					[ 'trackUrl' => esc_url_raw( rest_url( 'action-center/v1/track' ) ) ]
				);
			}
		}
	}

	public function add_settings_page() {
		add_options_page(
			'Fernwood Action Center',
			'Fernwood Action Center',
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

		// Export must run before any output so its download headers are honored.
		if ( isset( $_POST['cta_export'] ) && check_admin_referer( 'cta_export_nonce' ) ) {
			self::handle_export(); // Sends headers and exits.
		}

		// Process settings save and import before output; collect notices to show below.
		$notices = [];

		if ( isset( $_POST['cta_manager_save_settings'] ) && check_admin_referer( 'cta_manager_settings_nonce' ) ) {
			$length = isset( $_POST['cta_manager_trim_length'] ) ? (int) $_POST['cta_manager_trim_length'] : 0;
			if ( $length < 0 ) {
				$length = 0;
			}
			update_option( 'cta_manager_trim_length', $length );

			$page_id = isset( $_POST['cta_manager_action_center_page'] ) ? (int) $_POST['cta_manager_action_center_page'] : 0;
			update_option( 'cta_manager_action_center_page', $page_id );

			$federal_url = isset( $_POST['cta_manager_legislator_federal_url'] ) ? esc_url_raw( wp_unslash( $_POST['cta_manager_legislator_federal_url'] ) ) : '';
			update_option( 'cta_manager_legislator_federal_url', $federal_url );

			$state_url = isset( $_POST['cta_manager_legislator_state_url'] ) ? esc_url_raw( wp_unslash( $_POST['cta_manager_legislator_state_url'] ) ) : '';
			update_option( 'cta_manager_legislator_state_url', $state_url );

			$default_char_limit = isset( $_POST['cta_manager_default_char_limit'] ) ? absint( $_POST['cta_manager_default_char_limit'] ) : 5000;
			update_option( 'cta_manager_default_char_limit', $default_char_limit );

			update_option( 'cta_manager_collect_identity', empty( $_POST['cta_manager_collect_identity'] ) ? 0 : 1 );

			$notices[] = [ 'success', 'Settings saved.' ];
		}

		if (
			isset( $_POST['cta_import'] )
			&& check_admin_referer( 'cta_import_nonce' )
			&& ! empty( $_FILES['cta_import_file']['tmp_name'] )
		) {
			$imported = self::handle_import( $_FILES['cta_import_file']['tmp_name'] );
			if ( $imported > 0 ) {
				$notices[] = [ 'success', sprintf( _n( '%d CTA imported as a draft for review.', '%d CTAs imported as drafts for review.', $imported, 'action-center' ), $imported ) ];
			} else {
				$notices[] = [ 'error', 'Import failed. Check that the file is a valid Fernwood Action Center export within the size limit.' ];
			}
		}

		echo '<div class="wrap"><h1>Fernwood Action Center</h1>';

		echo '<nav class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base_url ) . '" class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '">Settings</a>';
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'documentation', $base_url ) ) . '" class="nav-tab' . ( 'documentation' === $tab ? ' nav-tab-active' : '' ) . '">Documentation</a>';
		echo '</nav>';

		foreach ( $notices as $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		if ( 'documentation' === $tab ) {
			$this->render_documentation_tab();
			echo '</div>';
			return;
		}

		$trim_length        = (int) get_option( 'cta_manager_trim_length', 200 );
		$action_center_page = (int) get_option( 'cta_manager_action_center_page', 0 );
		$legislator_federal = get_option( 'cta_manager_legislator_federal_url', 'https://www.congress.gov/members/find-your-member' );
		$legislator_state   = get_option( 'cta_manager_legislator_state_url', '' );
		$collect_identity   = (int) get_option( 'cta_manager_collect_identity', 1 );
		$default_char_limit = (int) get_option( 'cta_manager_default_char_limit', 5000 );

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
		echo '<tr>';
		echo '<th scope="row"><label for="cta_manager_default_char_limit">Default comment character limit</label></th>';
		echo '<td>';
		echo '<input name="cta_manager_default_char_limit" id="cta_manager_default_char_limit" type="number" min="0" step="100" value="' . esc_attr( $default_char_limit ) . '" class="small-text" /> ';
		echo '<span class="description">The limit new Guided actions start with, counted like the government form (regulations.gov allows 5000). Each action can override it. 0 = no limit.</span>';
		echo '</td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row">Default supporter identity</th>';
		echo '<td>';
		echo '<label for="cta_manager_collect_identity"><input name="cta_manager_collect_identity" id="cta_manager_collect_identity" type="checkbox" value="1"' . checked( $collect_identity, 1, false ) . ' /> Ask supporters for their name, city, and state, and add them to the comment</label>';
		echo '<p class="description">The default for new Guided actions; each action can override it. Turn it off when the comment becomes part of a public record (for example federal comments on regulations.gov): supporters still enter their contact details on the submission form, but their name and location stay out of the public comment. Leave it on for local actions that ask for it.</p>';
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
		echo '<p>Fernwood Action Center manages Calls to Action (CTAs): petitions, public comment windows, letter-writing campaigns, volunteer asks - anything you want visitors to act on. Each CTA is created under the <strong>CTAs</strong> menu and has a title, a featured image, a "Why this Matters" summary, an optional deadline (or an Ongoing flag), Steps to Take, copy-paste Sample Texts, Related Links, Files, and Videos, plus Organization and CTA Type tags.</p>';
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
		echo '<p>Fernwood Action Center registers a tool named <code>cta_manager_create_cta</code> with the <a href="https://github.com/Automattic/wordpress-mcp" target="_blank" rel="noopener noreferrer">WordPress MCP plugin</a> by Automattic. Once that plugin is installed and connected to an AI assistant such as Claude, the assistant can draft complete CTAs for you - title, summary, steps, sample texts, links, deadline, and tags - in a single step. New CTAs default to draft status so you always review before publishing.</p>';
		echo '<p>Setup: install and activate the WordPress MCP plugin, enable create tools under its settings, and connect your AI assistant to your site following that plugin\'s instructions. Then hand the assistant a link to any action page and a prompt like this:</p>';
		echo '<blockquote style="border-left: 4px solid #2271b1; margin: 1em 0; padding: 0.5em 1em; background: #f6f7f7;"><p>Here is a link to an action our supporters should know about: [paste URL]. Read the page and create a draft Call to Action on our site using the cta_manager_create_cta tool. Write a short "Why this Matters" summary in our voice, list ordered Steps to Take with the relevant links inline, include one or two sample messages supporters can copy, and add the source URL to Related Links. If the source gives a deadline, set it; otherwise mark the action as ongoing. Tag the host organization and pick a sensible CTA type. Give me the edit link when done.</p></blockquote>';
		echo '<p>The assistant replies with the draft\'s edit link; review it in wp-admin and publish. Sites without an AI assistant can ignore this section - the plugin works fully without it.</p>';

		echo '<h2>Updates</h2>';
		echo '<p>Fernwood Action Center checks its <a href="https://github.com/steventrotter/action-center" target="_blank" rel="noopener noreferrer">GitHub repository</a> for new releases and offers them through the normal WordPress updates screen - no extra setup needed. Questions or problems? Write to the author via <a href="https://steventrotter.com" target="_blank" rel="noopener noreferrer">steventrotter.com</a>.</p>';

		echo '</div>';
	}

	/**
	 * Content meta keys that round-trip through export/import. Engagement counters
	 * (_cta_builds, _cta_confirmed) are deliberately excluded: they are per-site and
	 * should not be carried across an import.
	 *
	 * @return string[]
	 */
	public static function export_meta_keys() {
		return [
			'_cta_summary',
			'_cta_end',
			'_cta_ongoing',
			'_cta_ended',
			'_cta_links',
			'_cta_files',
			'_cta_steps',
			'_cta_sample_texts',
			'_cta_videos',
			'_cta_button_text',
			'_cta_legislator_url',
			'_cta_format',
			'_cta_talking_points',
			'_cta_personal_prompts',
			'_cta_guided_intro',
			'_cta_guided_closing',
			'_cta_comment_url',
			'_cta_agency',
			'_cta_docket',
			'_cta_comment_goal',
		];
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
					'post_title'  => get_the_title(),
					'post_status' => 'publish',
					'tax_input'   => [
						'cta_org'  => wp_get_object_terms( get_the_ID(), 'cta_org', [ 'fields' => 'names' ] ),
						'cta_type' => wp_get_object_terms( get_the_ID(), 'cta_type', [ 'fields' => 'names' ] ),
					],
				];
				foreach ( self::export_meta_keys() as $key ) {
					$item[ $key ] = get_post_meta( get_the_ID(), $key, true );
				}
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
		// Only accept a genuine PHP upload, and cap its size.
		if ( ! is_uploaded_file( $file_path ) ) {
			return 0;
		}
		if ( filesize( $file_path ) > (int) apply_filters( 'cta_manager_import_max_bytes', 5 * MB_IN_BYTES ) ) {
			return 0;
		}

		$json  = file_get_contents( $file_path );
		$items = json_decode( $json, true );

		if ( ! is_array( $items ) ) {
			return 0;
		}

		$imported  = 0;
		$meta_keys = self::export_meta_keys();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// Imported CTAs arrive as drafts for review; they are never auto-published.
			$post_id = wp_insert_post(
				[
					'post_title'  => isset( $item['post_title'] ) ? sanitize_text_field( $item['post_title'] ) : '',
					'post_status' => 'draft',
					'post_type'   => 'cta',
				]
			);

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			foreach ( $meta_keys as $key ) {
				if ( isset( $item[ $key ] ) ) {
					// update_post_meta runs each key's registered sanitize_callback.
					update_post_meta( $post_id, $key, $item[ $key ] );
				}
			}

			if ( ! empty( $item['tax_input']['cta_org'] ) ) {
				wp_set_object_terms( $post_id, $item['tax_input']['cta_org'], 'cta_org' );
			}
			if ( ! empty( $item['tax_input']['cta_type'] ) ) {
				wp_set_object_terms( $post_id, $item['tax_input']['cta_type'], 'cta_type' );
			}

			$imported++;
		}

		self::$import_success = true;
		return $imported;
	}

	/**
	 * Shortcode: [cta_list]
	 */
	public function render_cta_list() {

		$current_type = isset( $_GET['cta_type'] ) ? sanitize_text_field( wp_unslash( $_GET['cta_type'] ) ) : '';
		$current_org  = isset( $_GET['cta_org'] ) ? sanitize_text_field( wp_unslash( $_GET['cta_org'] ) ) : '';

		// On a static Page holding the shortcode, WordPress paginates with the
		// 'page' query var, not 'paged'; read both so page 2+ is reachable either way.
		$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

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
			'posts_per_page' => (int) apply_filters( 'cta_manager_ongoing_limit', 50 ),
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

		// Single constrained wrapper so the filters, section headings, and card grids
		// all share one content width and one top offset. Padding (not margin) keeps
		// the top gap from collapsing into a full-bleed page header above the shortcode.
		echo '<div class="cta-archive">';

		// Filters UI. A real GET form so the filters work without JavaScript and do
		// not change context on select (WCAG 2.2 3.2.2); the script below upgrades it
		// to auto-apply on change and hides the submit button when JS is available.
		$types     = get_terms( [ 'taxonomy' => 'cta_type', 'hide_empty' => true ] );
		$org_terms = get_terms( [ 'taxonomy' => 'cta_org', 'hide_empty' => true ] );
		$has_types = ! empty( $types ) && ! is_wp_error( $types );
		$has_orgs  = ! empty( $org_terms ) && ! is_wp_error( $org_terms );

		if ( $has_types || $has_orgs ) {
			echo '<form id="cta-filters" class="cta-filters cta-filters--archive" method="get" action="">';

			if ( $has_types ) {
				echo '<div class="cta-filters__group cta-filters__group--type">';
				echo '<label for="cta-filter-type" class="cta-filters__label">Filter by Type</label>';
				echo '<select name="cta_type" id="cta-filter-type" class="cta-filters__select cta-filters__select--type">';
				echo '<option value="">All types</option>';
				foreach ( $types as $type ) {
					$selected = ( $current_type === $type->slug ) ? ' selected' : '';
					echo '<option value="' . esc_attr( $type->slug ) . '"' . $selected . '>' . esc_html( $type->name ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
			}

			if ( $has_orgs ) {
				echo '<div class="cta-filters__group cta-filters__group--org">';
				echo '<label for="cta-filter-org" class="cta-filters__label">Filter by Organization</label>';
				echo '<select name="cta_org" id="cta-filter-org" class="cta-filters__select cta-filters__select--org">';
				echo '<option value="">All organizations</option>';
				foreach ( $org_terms as $org ) {
					$selected = ( $current_org === $org->slug ) ? ' selected' : '';
					echo '<option value="' . esc_attr( $org->slug ) . '"' . $selected . '>' . esc_html( $org->name ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
			}

			echo '<div class="cta-filters__group cta-filters__group--submit">';
			echo '<button type="submit" class="cta-btn cta-filters__submit">Apply Filters</button>';
			echo '</div>';

			echo '</form>';
		}

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

		echo '</div>'; // .cta-archive

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
	$format           = get_post_meta( get_the_ID(), '_cta_format', true );

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

	// Guided actions (when not expired) render as a two-part flow instead of the
	// classic linear layout: info + builder first, then a submit hand-off and
	// follow-up steps after the supporter copies their comment.
	$guided_active = ( 'guided' === $format && ! $is_expired );

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

	if ( $summary && ! $guided_active ) {
		echo '<section class="cta-section cta-section--summary">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Why this Matters</h2>';
		echo '<div class="cta-card cta-card--summary">';
		echo '<div class="cta-section__body">';
		echo cta_manager_render_content( $summary );
		echo '</div>';
		echo '</div>';
		echo '</section>';
	}

	if ( $guided_active ) {

		// Guided: the full two-part flow (summary, collapsed "learn more", builder,
		// then the submit hand-off and follow-up steps) is rendered by one function.
		echo cta_manager_render_guided_action(
			get_the_ID(),
			[
				'summary' => $summary,
				'videos'  => $videos,
				'files'   => $files,
				'links'   => $links,
				'steps'   => $steps,
			]
		);

	} else {

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
				// Not a recognizable YouTube URL -- skip silently.
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

	if ( 'guided' !== $format && ! empty( $sample_texts ) && is_array( $sample_texts ) ) {
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

			echo '<p class="cta-sample-text__copy-row"><button type="button" class="cta-btn cta-sample-text__copy-link" data-target="' . esc_attr( $textarea_id ) . '">Copy to Clipboard</button></p>';

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

	} // end classic (non-guided) linear layout.

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
			var live = null;
			function announce(msg) {
				if (!live) {
					live = document.createElement('div');
					live.setAttribute('aria-live', 'polite');
					live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';
					document.body.appendChild(live);
				}
				live.textContent = '';
				window.setTimeout(function() { live.textContent = msg; }, 30);
			}

			function fallbackCopy(ta, done) {
				ta.focus();
				ta.select();
				try {
					if (document.execCommand('copy')) { done(); }
				} catch (err) {}
			}

			document.addEventListener('click', function(e) {
				var btn = e.target.closest('.cta-sample-text__copy-link');
				if (!btn) return;

				e.preventDefault();
				var id = btn.getAttribute('data-target');
				var ta = document.getElementById(id);
				if (!ta) return;

				var done = function() {
					btn.textContent = 'Copied!';
					announce('Sample text copied to your clipboard.');
					window.setTimeout(function() { btn.textContent = 'Copy to Clipboard'; }, 1500);
				};

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(ta.value).then(done, function() { fallbackCopy(ta, done); });
				} else {
					fallbackCopy(ta, done);
				}
			});
		})();
	</script>
	<?php
}
add_action( 'wp_footer', 'cta_manager_sample_text_script' );

/**
 * Render a Guided action as a two-part flow.
 *
 * Part 1 shows the "Why this Matters" summary, an optional collapsed "learn more"
 * section (videos, files, links), and the comment builder. When the supporter clicks
 * "Copy my comment and open the form", comment-builder.js copies the draft and swaps
 * to Part 2: a confirmation with the copied comment, a button that opens the real
 * submission form, and the follow-up "More Ways to Help" steps. The only tracked
 * event is the click of that open-the-form button, reported as comments written.
 *
 * All dynamic text is escaped; the assembled draft is built client-side from these
 * escaped values.
 *
 * @param int   $post_id CTA post ID.
 * @param array $content Optional pre-fetched content: summary, videos, files, links, steps.
 * @return string HTML.
 */
function cta_manager_render_guided_action( $post_id, $content = [] ) {
	$points       = get_post_meta( $post_id, '_cta_talking_points', true );
	$prompts      = get_post_meta( $post_id, '_cta_personal_prompts', true );
	$intro        = get_post_meta( $post_id, '_cta_guided_intro', true );
	$closing      = get_post_meta( $post_id, '_cta_guided_closing', true );
	$url          = get_post_meta( $post_id, '_cta_comment_url', true );
	$goal         = (int) get_post_meta( $post_id, '_cta_comment_goal', true );
	$builds       = (int) get_post_meta( $post_id, '_cta_builds', true );
	$show_counter = (bool) get_post_meta( $post_id, '_cta_show_counter', true );

	// Identity fields: per-CTA override ('on'/'off') falls back to the site default.
	$identity_mode = get_post_meta( $post_id, '_cta_collect_identity', true );
	if ( 'on' === $identity_mode ) {
		$collect_identity = 1;
	} elseif ( 'off' === $identity_mode ) {
		$collect_identity = 0;
	} else {
		$collect_identity = (int) get_option( 'cta_manager_collect_identity', 1 );
	}

	// Character limit: per-CTA custom value falls back to the site default. 0 = no limit.
	$default_limit = (int) get_option( 'cta_manager_default_char_limit', 5000 );
	if ( 'custom' === get_post_meta( $post_id, '_cta_char_limit_mode', true ) ) {
		$cl         = get_post_meta( $post_id, '_cta_char_limit', true );
		$char_limit = ( '' === $cl ) ? $default_limit : (int) $cl;
	} else {
		$char_limit = $default_limit;
	}

	$points  = is_array( $points ) ? $points : [];
	$prompts = is_array( $prompts ) ? $prompts : [];

	$summary = isset( $content['summary'] ) ? $content['summary'] : '';
	$videos  = ( isset( $content['videos'] ) && is_array( $content['videos'] ) ) ? $content['videos'] : [];
	$files   = ( isset( $content['files'] ) && is_array( $content['files'] ) ) ? $content['files'] : [];
	$links   = ( isset( $content['links'] ) && is_array( $content['links'] ) ) ? array_filter( $content['links'] ) : [];
	$steps   = ( isset( $content['steps'] ) && is_array( $content['steps'] ) ) ? $content['steps'] : [];

	$has_builder = ! empty( $points ) || ! empty( $prompts );

	ob_start();

	echo '<div class="cta-guided"';
	echo ' data-cta-id="' . esc_attr( $post_id ) . '"';
	echo ' data-comment-url="' . esc_url( $url ) . '"';
	echo ' data-goal="' . esc_attr( $goal ) . '"';
	echo ' data-char-limit="' . esc_attr( $char_limit ) . '"';
	echo ' data-intro="' . esc_attr( $intro ) . '"';
	echo ' data-closing="' . esc_attr( $closing ) . '">';

	// ============================ PART 1 ============================
	echo '<div class="cta-guided__part1">';

	// Why this Matters.
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

	// Optional public counter (off unless enabled per CTA).
	if ( $show_counter && $has_builder && ( $goal > 0 || $builds > 0 ) ) {
		echo '<div class="cta-builder__progress">';
		echo '<div class="cta-builder__stats">';
		echo '<span class="cta-builder__stat"><b class="cta-builder__written">' . esc_html( $builds ) . '</b> comments written</span>';
		echo '</div>';
		if ( $goal > 0 ) {
			$pct = min( 100, (int) round( $builds / $goal * 100 ) );
			echo '<div class="cta-builder__track" role="img" aria-label="' . esc_attr( $builds . ' of ' . $goal . ' toward goal' ) . '"><span class="cta-builder__fill" style="width:' . esc_attr( $pct ) . '%;"></span></div>';
			echo '<p class="cta-builder__goalnum"><b class="cta-builder__written">' . esc_html( $builds ) . '</b> / ' . esc_html( $goal ) . ' goal</p>';
		}
		echo '</div>';
	}

	// Collapsed "learn more": videos, files, and links tucked away so they never
	// crowd out the ask.
	$has_more = ! empty( $videos ) || ! empty( $files ) || ! empty( $links );
	if ( $has_more ) {
		echo '<details class="cta-guided__moreinfo">';
		echo '<summary class="cta-guided__moreinfo-summary">Would you like to learn even more before taking action?</summary>';
		echo '<div class="cta-guided__moreinfo-body">';

		if ( ! empty( $videos ) ) {
			foreach ( $videos as $video ) {
				$vurl   = is_array( $video ) ? ( $video['url'] ?? '' ) : $video;
				$vlabel = is_array( $video ) ? ( $video['label'] ?? '' ) : '';
				if ( ! $vurl ) {
					continue;
				}
				$embed_url = '';
				if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_\-]{11})#', $vurl, $m ) ) {
					$embed_url = 'https://www.youtube-nocookie.com/embed/' . $m[1];
				}
				if ( ! $embed_url ) {
					continue;
				}
				echo '<div class="cta-video-item">';
				if ( $vlabel ) {
					echo '<p class="cta-video-item__label"><strong>' . esc_html( $vlabel ) . '</strong></p>';
				}
				echo '<div class="cta-video-item__embed">';
				echo '<iframe src="' . esc_url( $embed_url ) . '" title="' . esc_attr( $vlabel ?: 'Video' ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
				echo '</div>';
				echo '<p class="cta-video-item__fallback"><a href="' . esc_url( $vurl ) . '" target="_blank" rel="noopener noreferrer">Player not working? Watch on YouTube.</a></p>';
				echo '</div>';
			}
		}

		$more_links = [];
		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				$fid    = is_array( $file ) ? (int) ( $file['id'] ?? 0 ) : (int) $file;
				$flabel = is_array( $file ) ? ( $file['label'] ?? '' ) : '';
				if ( ! $fid || ! get_post( $fid ) ) {
					continue;
				}
				if ( ! $flabel ) {
					$flabel = get_the_title( $fid );
				}
				$more_links[] = [ 'url' => wp_get_attachment_url( $fid ), 'label' => $flabel ];
			}
		}
		if ( ! empty( $links ) ) {
			foreach ( $links as $link ) {
				$lurl   = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
				$llabel = is_array( $link ) ? ( $link['label'] ?? '' ) : '';
				if ( ! $lurl ) {
					continue;
				}
				if ( ! $llabel ) {
					$llabel = preg_replace( '#^https?://#', '', $lurl );
				}
				$more_links[] = [ 'url' => $lurl, 'label' => $llabel ];
			}
		}
		if ( ! empty( $more_links ) ) {
			echo '<ul class="cta-guided__links">';
			foreach ( $more_links as $ml ) {
				if ( empty( $ml['url'] ) ) {
					continue;
				}
				echo '<li><a href="' . esc_url( $ml['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $ml['label'] ) . '</a></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
		echo '</details>';
	}

	if ( $has_builder ) {
		echo '<section class="cta-builder">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Build Your Comment</h2>';
		echo '<div class="cta-builder__card">';

		if ( ! empty( $points ) ) {
			echo '<fieldset class="cta-builder__points"><legend class="cta-builder__legend">Choose the points that matter to you</legend>';
			$i = 0;
			foreach ( $points as $p ) {
				$label = is_array( $p ) ? ( $p['label'] ?? '' ) : '';
				$text  = is_array( $p ) ? ( $p['text'] ?? '' ) : '';
				$plain = trim( wp_strip_all_tags( (string) $text ) );
				if ( '' === $label && '' === $plain ) {
					continue;
				}
				$pid = 'cta-tp-' . $post_id . '-' . $i;
				echo '<label class="cta-builder__point" for="' . esc_attr( $pid ) . '">';
				echo '<input type="checkbox" id="' . esc_attr( $pid ) . '" class="cta-builder__check" data-text="' . esc_attr( $plain ) . '">';
				echo '<span class="cta-builder__point-text">';
				if ( '' !== $label ) {
					echo '<strong>' . esc_html( $label ) . '.</strong> ';
				}
				echo esc_html( $plain );
				echo '</span></label>';
				$i++;
			}
			echo '</fieldset>';
		}

		if ( ! empty( $prompts ) ) {
			$j = 0;
			foreach ( $prompts as $prompt ) {
				$prompt = trim( (string) $prompt );
				if ( '' === $prompt ) {
					continue;
				}
				$fid = 'cta-pp-' . $post_id . '-' . $j;
				echo '<p class="cta-builder__field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $prompt ) . '</label>';
				echo '<textarea id="' . esc_attr( $fid ) . '" class="cta-builder__prompt" rows="3"></textarea></p>';
				$j++;
			}
		}

		if ( $collect_identity ) {
			echo '<p class="cta-builder__field"><label for="cta-name-' . esc_attr( $post_id ) . '">Your full name</label><input type="text" id="cta-name-' . esc_attr( $post_id ) . '" class="cta-builder__name" autocomplete="name"></p>';
			echo '<div class="cta-builder__id">';
			echo '<p class="cta-builder__field"><label for="cta-city-' . esc_attr( $post_id ) . '">Your city</label><input type="text" id="cta-city-' . esc_attr( $post_id ) . '" class="cta-builder__city" autocomplete="address-level2"></p>';
			echo '<p class="cta-builder__field"><label for="cta-state-' . esc_attr( $post_id ) . '">Your state</label><input type="text" id="cta-state-' . esc_attr( $post_id ) . '" class="cta-builder__state" autocomplete="address-level1" placeholder="Oregon"></p>';
			echo '</div>';
			echo '<p class="cta-builder__note">Your name, city, and state appear in the comment and become part of the public record. A comment signed by a real person from a real place is far harder to set aside than an anonymous form letter.</p>';
		} else {
			echo '<p class="cta-builder__note">Your comment stays anonymous here. If the submission form asks for your name or contact details, you enter those on the form itself - they are not added to your public comment.</p>';
		}

		echo '<div class="cta-builder__draftwrap">';
		echo '<label class="cta-builder__lead" for="cta-draft-' . esc_attr( $post_id ) . '">Your draft comment. Edit it freely before you send it: it is yours to change.</label>';
		echo '<textarea id="cta-draft-' . esc_attr( $post_id ) . '" class="cta-builder__draft" spellcheck="true"></textarea>';
		echo '<p class="cta-builder__tools"><button type="button" class="cta-builder__reset">Reset to suggested wording</button> <span class="cta-builder__wc" aria-live="polite"></span></p>';
		echo '</div>';

		echo '<button type="button" class="cta-btn cta-btn--full cta-builder__go">Copy my comment and open the form</button>';

		echo '</div>'; // card
		echo '</section>';
	} elseif ( ! empty( $steps ) ) {

		// Guided format but no builder configured: fall back to showing the steps inline.
		echo '<section id="cta-steps" class="cta-section cta-section--steps cta-steps-section">';
		echo '<h2 class="cta-section__title cta-section__title--spaced">Steps to Take</h2>';
		echo '<div class="cta-steps-grid cta-steps-grid--detail">';
		$sn = 1;
		foreach ( $steps as $step_html ) {
			if ( trim( $step_html ) === '' ) {
				continue;
			}
			echo '<div class="cta-step-card cta-step-card--detail">';
			echo '<div class="cta-step-number cta-step-card__label">Step ' . intval( $sn ) . '</div>';
			echo '<div class="cta-step-body cta-step-card__body">' . cta_manager_render_content( $step_html ) . '</div>';
			echo '</div>';
			$sn++;
		}
		echo '</div>';
		echo '</section>';
	}

	echo '</div>'; // part1

	// ============================ PART 2 ============================
	if ( $has_builder ) {
		echo '<div class="cta-guided__part2" hidden>';

		echo '<section class="cta-section">';
		echo '<div class="cta-card cta-guided__ready">';
		echo '<div class="cta-guided__ready-head"><span class="cta-guided__badge" aria-hidden="true">&#10003;</span><h2 class="cta-section__title" style="margin:0;">Your Comment Is Copied</h2></div>';
		echo '<p class="cta-guided__ready-lead">Open the comment form, paste your comment into the box, and click the submission site\'s Submit button. That last step happens on their site, so it only counts if you finish it there.</p>';
		echo '<div class="cta-guided__final" aria-label="Your comment"></div>';
		if ( $url ) {
			echo '<a class="cta-btn cta-btn--full cta-builder__openform" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Open the comment form to submit</a>';
		} else {
			echo '<p class="cta-guided__nourl">Add the submission form URL to this action to enable the submit button.</p>';
		}
		echo '</div>';
		echo '</section>';

		if ( ! empty( $steps ) ) {
			echo '<section class="cta-section cta-guided__more">';
			echo '<h2 class="cta-section__title cta-section__title--spaced">More Ways to Help</h2>';
			echo '<div class="cta-guided__steps">';
			$sn = 1;
			foreach ( $steps as $step_html ) {
				if ( trim( $step_html ) === '' ) {
					continue;
				}
				echo '<div class="cta-guided__step">';
				echo '<span class="cta-guided__step-n" aria-hidden="true">' . intval( $sn ) . '</span>';
				echo '<div class="cta-guided__step-body">' . cta_manager_render_content( $step_html ) . '</div>';
				echo '</div>';
				$sn++;
			}
			echo '</div>';
			echo '</section>';
		}

		echo '<p class="cta-guided__backline"><button type="button" class="cta-builder__back">&larr; Back to my comment</button></p>';

		echo '</div>'; // part2
	}

	echo '</div>'; // cta-guided

	return ob_get_clean();
}
