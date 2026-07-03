<?php
/**
 * Plugin Name: Action Center
 * Plugin URI: https://steventrotter.com/action-center
 * Description: Publish and display Calls to Action: an Action Center listing page, per-action detail pages, an embeddable block, a public JSON feed, and AI-assisted CTA creation through the WordPress MCP connector.
 * Version: 1.1.1
 * Author: Steven Trotter
 * Author URI: https://steventrotter.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: action-center
 */

defined( 'ABSPATH' ) || exit;

define( 'CTA_MANAGER_VERSION', '1.1.1' );

/**
 * Auto-updates from GitHub releases.
 *
 * Uses the Plugin Update Checker library (MIT, YahnisElsts/plugin-update-checker,
 * vendored in lib/) to poll this plugin's GitHub repository for new releases.
 * Every release must have an asset named exactly `action-center.zip` attached -
 * that is the file WordPress downloads when a site clicks Update.
 */
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	$action_center_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/steventrotter/action-center/',
		__FILE__,
		'action-center'
	);
	$action_center_update_checker->getVcsApi()->enableReleaseAssets( '/^action-center\.zip$/i' );
}

/**
 * URL of the Action Center listing page (the page holding [cta_list]).
 *
 * Uses the page chosen in Settings > Action Center; falls back to a page
 * with the slug "act-now", then the site front page.
 *
 * @return string
 */
function cta_manager_action_center_url() {
	$page_id = (int) get_option( 'cta_manager_action_center_page', 0 );
	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return get_permalink( $page_id );
	}
	$page = get_page_by_path( 'act-now' );
	if ( $page instanceof WP_Post ) {
		return get_permalink( $page );
	}
	return home_url( '/' );
}

/**
 * Register CTA custom post type and taxonomies.
 *
 * (Note: the CTA_Meta class also registers a CPT and taxonomies for REST/editor use.
 * This lightweight registration ensures front-end queries continue to work even if
 * the class is not loaded yet. Duplicate calls for the same CPT/taxonomies are ignored.)
 */
function cta_manager_register_post_type_basic() {

	if ( post_type_exists( 'cta' ) ) {
		return;
	}

	$labels = [
		'name'               => 'CTAs',
		'singular_name'      => 'CTA',
		'add_new'            => 'Add New CTA',
		'add_new_item'       => 'Add New CTA',
		'edit_item'          => 'Edit CTA',
		'new_item'           => 'New CTA',
		'all_items'          => 'All CTAs',
		'view_item'          => 'View CTA',
		'search_items'       => 'Search CTAs',
		'not_found'          => 'No CTAs found',
		'not_found_in_trash' => 'No CTAs found in Trash',
		'menu_name'          => 'CTAs',
	];

	$args = [
		'labels'             => $labels,
		'public'             => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		// Keep REST on here too. CTA_Meta re-registers this CPT at init priority 10 with
		// show_in_rest => true and normally wins, but this fallback runs at priority 0 and
		// would otherwise leave REST off if the class failed to load. Both must agree so
		// REST exposure (needed by the WordPress MCP connector) is not load-order dependent.
		'show_in_rest'       => true,
		'publicly_queryable' => true,
		'has_archive'        => false,
		'rewrite'            => [ 'slug' => 'cta' ],
		'supports'           => [ 'title' ],
		'menu_position'      => 20,
		'menu_icon'          => 'dashicons-megaphone',
	];

	register_post_type( 'cta', $args );

	// Organizations.
	if ( ! taxonomy_exists( 'cta_org' ) ) {
		register_taxonomy(
			'cta_org',
			'cta',
			[
				'label'             => 'Organizations',
				'public'            => true,
				'hierarchical'      => false,
				'show_ui'           => true,
				'rewrite'           => [ 'slug' => 'cta-org' ],
				'show_admin_column' => true,
			]
		);
	}

	// CTA Types.
	if ( ! taxonomy_exists( 'cta_type' ) ) {
		register_taxonomy(
			'cta_type',
			'cta',
			[
				'label'             => 'CTA Types',
				'public'            => true,
				'hierarchical'      => false,
				'show_ui'           => true,
				'rewrite'           => [ 'slug' => 'cta-type' ],
				'show_admin_column' => true,
			]
		);
	}
}
add_action( 'init', 'cta_manager_register_post_type_basic', 0 );

/**
 * Require class files.
 */
require_once __DIR__ . '/includes/class-cta-meta.php';
require_once __DIR__ . '/includes/class-cta-display.php';
require_once __DIR__ . '/includes/class-cta-assets.php';
require_once __DIR__ . '/includes/class-cta-blocks.php';
require_once __DIR__ . '/includes/class-cta-mcp.php';
require_once __DIR__ . '/includes/class-cta-feed.php';

/**
 * Bootstrap classes.
 */
new CTA_Meta();
new CTA_Display();
new CTA_Assets();
new CTA_Blocks();
new CTA_MCP();
new CTA_Feed();

/**
 * Add settings link on Plugins screen.
 */
function cta_manager_plugin_action_links( $links ) {
	$url          = admin_url( 'options-general.php?page=action-center-settings' );
	$settings_url = '<a href="' . esc_url( $url ) . '">Settings</a>';
	array_unshift( $links, $settings_url );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cta_manager_plugin_action_links' );

/**
 * Flush rewrite rules on activation (the CPT registers rewrite rules) and
 * again on deactivation to clean them up.
 */
function cta_manager_activate() {
	cta_manager_register_post_type_basic();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cta_manager_activate' );

function cta_manager_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cta_manager_deactivate' );