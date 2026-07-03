<?php
/**
 * Admin assets for CTA Manager.
 *
 * @package Calls_To_Action_Manager
 */

defined( 'ABSPATH' ) || exit;

class CTA_Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueue admin CSS/JS on CTA edit screens.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;

		if ( ( 'post-new.php' === $hook || 'post.php' === $hook ) && $post && 'cta' === $post->post_type ) {

			// Admin styles for meta boxes.
			wp_enqueue_style(
				'cta-admin',
				plugin_dir_url( __FILE__ ) . '../assets/admin.css',
				[],
				'1.0'
			);

			// Admin JS handling repeaters and rich editors.
			wp_enqueue_script(
				'cta-admin-js',
				plugin_dir_url( __FILE__ ) . '../assets/admin.js',
				[ 'jquery', 'wp-util', 'wp-editor', 'media-editor' ],
				'1.0',
				true
			);

			// Media library support for file uploads.
			wp_enqueue_media();
		}
	}
}
