<?php
/**
 * Custom MCP tool for the WordPress MCP connector.
 *
 * Registers `cta_manager_create_cta`, a single "create" tool that builds a complete CTA
 * (title + every _cta_* meta field + cta_org / cta_type taxonomy terms) in one call.
 *
 * Why this exists: the WordPress MCP connector's generic CPT tools (wp_add_cpt /
 * wp_update_cpt) can set only title/content/status, and wp_update_post rejects a
 * `cta` ID ("Invalid post ID"). So there was no way to populate a CTA's meta or
 * taxonomies through the connector. This tool closes that gap so a draft CTA can be
 * created end-to-end remotely, then reviewed and published from wp-admin.
 *
 * Requires the Automattic "WordPress MCP" plugin. If that plugin is inactive the
 * `wordpress_mcp_init` hook never fires and this class does nothing.
 *
 * @package Action_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and implements the cta_manager_create_cta MCP tool.
 */
class CTA_MCP {

	/**
	 * Hook registration onto the MCP init action.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', [ $this, 'register_tools' ] );
	}

	/**
	 * Register the cta_manager_create_cta tool with the MCP connector.
	 */
	public function register_tools(): void {
		if ( ! class_exists( '\Automattic\WordpressMcp\Core\RegisterMcpTool' ) ) {
			return;
		}

		$url_label_items = [
			'type'       => 'array',
			'items'      => [
				'type'       => 'object',
				'properties' => [
					'url'   => [ 'type' => 'string' ],
					'label' => [ 'type' => 'string' ],
				],
				'required'   => [ 'url' ],
			],
		];

		new \Automattic\WordpressMcp\Core\RegisterMcpTool(
			[
				'name'                => 'cta_manager_create_cta',
				'description'         => 'Create a complete Call to Action (CTA) for the Action Center plugin. Sets the title, every CTA content field (summary, deadline or ongoing flag, steps, sample texts, related links, videos, files, button label, legislator URL) and the Organization / CTA Type taxonomy terms in a single call. Defaults to draft status for review before publishing. Returns the new CTA id and its wp-admin edit URL.',
				'type'                => 'create',
				'permission_callback' => [ $this, 'permission_callback' ],
				'callback'            => [ $this, 'create_cta' ],
				'inputSchema'         => [
					'type'       => 'object',
					'properties' => [
						'title'          => [
							'type'        => 'string',
							'description' => 'CTA title (post title).',
						],
						'status'         => [
							'type'        => 'string',
							'enum'        => [ 'draft', 'pending', 'publish' ],
							'description' => 'Post status. Defaults to draft so the CTA can be reviewed before going live.',
							'default'     => 'draft',
						],
						'summary'        => [
							'type'        => 'string',
							'description' => 'The "Why this Matters" body. HTML allowed (paragraphs, links, lists).',
						],
						'ongoing'        => [
							'type'        => 'boolean',
							'description' => 'True for an ongoing action with no deadline. When true, any deadline is ignored.',
							'default'     => false,
						],
						'deadline'       => [
							'type'        => 'string',
							'description' => 'Deadline as a datetime-local string, e.g. "2026-07-15T17:00". Ignored when ongoing is true.',
						],
						'button_text'    => [
							'type'        => 'string',
							'description' => 'Card button label. Defaults to "Learn More" on the front end when blank.',
						],
						'legislator_url' => [
							'type'        => 'string',
							'description' => 'Optional legislator lookup URL override. Only relevant for the "Contact Your Legislator" CTA type.',
						],
						'steps'          => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Ordered "Steps to Take". Each item may contain simple HTML.',
						],
						'sample_texts'   => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Copy-to-clipboard sample text options. Plain text, one per item.',
						],
						'links'          => array_merge(
							$url_label_items,
							[ 'description' => 'Related links. Array of { url, label } objects.' ]
						),
						'videos'         => array_merge(
							$url_label_items,
							[ 'description' => 'Related YouTube videos. Array of { url, label } objects (watch, Shorts or youtu.be URLs).' ]
						),
						'files'          => [
							'type'        => 'array',
							'description' => 'Related files. Array of { id, label } objects where id is a WP media attachment ID.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'id'    => [ 'type' => 'integer' ],
									'label' => [ 'type' => 'string' ],
								],
								'required'   => [ 'id' ],
							],
						],
						'organizations'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Organization names (cta_org taxonomy). Terms are created if they do not exist.',
						],
						'types'          => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'CTA Type names (cta_type taxonomy). Terms are created if they do not exist.',
						],
					],
					'required'   => [ 'title' ],
				],
			]
		);
	}

	/**
	 * Permission check: must be able to edit posts (matches the meta auth_callback).
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Create the CTA from the tool arguments.
	 *
	 * Relies on the sanitize_callback registered for each _cta_* meta key
	 * (see CTA_Meta::register_meta_fields) - update_post_meta runs sanitize_meta,
	 * so arrays and HTML are cleaned identically to a meta-box save.
	 *
	 * @param array $args Tool arguments.
	 * @return array Result payload.
	 */
	public function create_cta( $args ) {
		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		if ( '' === $title ) {
			return [
				'success' => false,
				'error'   => 'A title is required to create a CTA.',
			];
		}

		$allowed_status = [ 'draft', 'pending', 'publish' ];
		$status         = isset( $args['status'] ) && in_array( $args['status'], $allowed_status, true )
			? $args['status']
			: 'draft';

		$post_id = wp_insert_post(
			[
				'post_type'   => 'cta',
				'post_title'  => $title,
				'post_status' => $status,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return [
				'success' => false,
				'error'   => $post_id->get_error_message(),
			];
		}

		// Scalar meta.
		if ( isset( $args['summary'] ) ) {
			update_post_meta( $post_id, '_cta_summary', wp_kses_post( $args['summary'] ) );
		}
		if ( ! empty( $args['button_text'] ) ) {
			update_post_meta( $post_id, '_cta_button_text', sanitize_text_field( $args['button_text'] ) );
		}
		if ( ! empty( $args['legislator_url'] ) ) {
			update_post_meta( $post_id, '_cta_legislator_url', sanitize_text_field( $args['legislator_url'] ) );
		}

		// Deadline vs ongoing. Ongoing wins and clears any deadline.
		$ongoing = ! empty( $args['ongoing'] );
		if ( $ongoing ) {
			update_post_meta( $post_id, '_cta_ongoing', 1 );
			delete_post_meta( $post_id, '_cta_end' );
		} else {
			update_post_meta( $post_id, '_cta_ongoing', 0 );
			if ( ! empty( $args['deadline'] ) ) {
				update_post_meta( $post_id, '_cta_end', sanitize_text_field( $args['deadline'] ) );
			}
		}
		// New CTAs are never pre-marked as ended.
		update_post_meta( $post_id, '_cta_ended', 0 );

		// Array meta. The registered sanitize_callbacks re-clean each on write.
		if ( isset( $args['links'] ) && is_array( $args['links'] ) ) {
			update_post_meta( $post_id, '_cta_links', $args['links'] );
		}
		if ( isset( $args['videos'] ) && is_array( $args['videos'] ) ) {
			update_post_meta( $post_id, '_cta_videos', $args['videos'] );
		}
		if ( isset( $args['files'] ) && is_array( $args['files'] ) ) {
			update_post_meta( $post_id, '_cta_files', $args['files'] );
		}
		if ( isset( $args['steps'] ) && is_array( $args['steps'] ) ) {
			update_post_meta( $post_id, '_cta_steps', $args['steps'] );
		}
		if ( isset( $args['sample_texts'] ) && is_array( $args['sample_texts'] ) ) {
			update_post_meta( $post_id, '_cta_sample_texts', $args['sample_texts'] );
		}

		// Taxonomies. wp_set_object_terms creates missing terms when passed names.
		if ( ! empty( $args['organizations'] ) && is_array( $args['organizations'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $args['organizations'] ), 'cta_org' );
		}
		if ( ! empty( $args['types'] ) && is_array( $args['types'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $args['types'] ), 'cta_type' );
		}

		return [
			'success'  => true,
			'post_id'  => $post_id,
			'status'   => get_post_status( $post_id ),
			'title'    => $title,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'view_url' => get_permalink( $post_id ),
			'message'  => sprintf( 'CTA "%s" created as %s (ID %d).', $title, get_post_status( $post_id ), $post_id ),
		];
	}
}
