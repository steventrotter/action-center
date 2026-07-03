<?php
/**
 * Uninstall cleanup for Action Center.
 *
 * Deletes everything the plugin created: all CTA posts (and their post
 * meta), all cta_org / cta_type taxonomy terms, and all plugin options.
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation.
 *
 * @package Action_Center
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete all CTA posts (any status) and their meta.
$cta_ids = get_posts(
	[
		'post_type'      => 'cta',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);
foreach ( $cta_ids as $cta_id ) {
	wp_delete_post( $cta_id, true );
}

// Delete all terms in the plugin's taxonomies. The taxonomies are not
// registered during uninstall, so register minimal stubs first so the
// term API can see them.
register_taxonomy( 'cta_org', 'cta' );
register_taxonomy( 'cta_type', 'cta' );
foreach ( [ 'cta_org', 'cta_type' ] as $cta_taxonomy ) {
	$terms = get_terms(
		[
			'taxonomy'   => $cta_taxonomy,
			'hide_empty' => false,
		]
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, $cta_taxonomy );
		}
	}
}

// Delete plugin options.
delete_option( 'cta_manager_trim_length' );
delete_option( 'cta_manager_legislator_federal_url' );
delete_option( 'cta_manager_legislator_state_url' );
delete_option( 'cta_manager_action_center_page' );

flush_rewrite_rules();
