<?php
/**
 * A/B Test Visual Editor
 *
 * Provides an iframe-based visual editor for point-and-click element
 * selection and inline editing (text, HTML, CSS) of A/B test variants.
 *
 * @package opti-behavior
 * @since   1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Visual_Editor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_hidden_page' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX for saving visual edits.
		add_action( 'wp_ajax_opti_behavior_ab_visual_save', array( $this, 'ajax_save_visual_changes' ) );

		// Native form submit preview flow that is resilient to popup blockers.
		add_action( 'admin_post_opti_behavior_ab_visual_preview', array( $this, 'handle_visual_preview' ) );

		// Inject helper script into frontend when in visual editor preview mode.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_inject_editor_helper' ) );
	}

	/**
	 * Register a hidden admin page for the visual editor.
	 *
	 * Not shown in the menu — accessed via ?page=opti-behavior-ab-visual-editor.
	 */
	public function register_hidden_page() {
		add_submenu_page(
			'', // Hidden from menu (empty string avoids null deprecation).
			__( 'Visual Editor', 'opti-behavior' ),
			__( 'Visual Editor', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-ab-visual-editor',
			array( $this, 'render_editor' )
		);
	}

	/**
	 * Enqueue assets for the visual editor page.
	 *
	 * @param string $hook_suffix Current page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'opti-behavior-ab-visual-editor' ) ) {
			return;
		}

		$version = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.2.8';

		// Enqueue WordPress Media Library for image picker.
		wp_enqueue_media();

		$ve_css_version = $version . '.' . filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/ab-test-visual-editor.css' );

		wp_enqueue_style(
			'opti-behavior-ab-visual-editor',
			plugins_url( 'assets/css/ab-test-visual-editor.css', dirname( __FILE__ ) ),
			array(),
			$ve_css_version
		);

		$ve_parent_version = $version . '.' . filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/ab-test-visual-editor.js' );

		wp_enqueue_script(
			'opti-behavior-ab-visual-editor',
			plugins_url( 'assets/js/ab-test-visual-editor.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			$ve_parent_version,
			true
		);

		wp_localize_script(
			'opti-behavior-ab-visual-editor',
			'optiBehaviorVisualEditor',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'admin_post_url'  => admin_url( 'admin-post.php' ),
				'nonce'           => wp_create_nonce( 'opti_behavior_ab_pro_nonce' ),
				'preview_nonce'   => wp_create_nonce( 'opti_behavior_ab_visual_preview' ),
				'strings'  => array(
					'select_element'  => __( 'Click on an element to select it', 'opti-behavior' ),
					'editing'         => __( 'Editing:', 'opti-behavior' ),
					'save'            => __( 'Save Changes', 'opti-behavior' ),
					'cancel'          => __( 'Cancel', 'opti-behavior' ),
					'text_tab'        => __( 'Text', 'opti-behavior' ),
					'html_tab'        => __( 'HTML', 'opti-behavior' ),
					'css_tab'         => __( 'CSS', 'opti-behavior' ),
					'hide_element'    => __( 'Hide Element', 'opti-behavior' ),
					'remove_change'   => __( 'Remove Change', 'opti-behavior' ),
					'changes_saved'   => __( 'Changes saved successfully!', 'opti-behavior' ),
					'no_changes'      => __( 'No changes to save.', 'opti-behavior' ),
					'loading_page'    => __( 'Loading page…', 'opti-behavior' ),
					'load_error'      => __( 'Could not load the target page. Make sure the URL is accessible.', 'opti-behavior' ),
					'preview_variant'  => __( 'Preview Variant', 'opti-behavior' ),
					'edit_mode'        => __( 'Edit Mode', 'opti-behavior' ),
					'image_tab'        => __( 'Image', 'opti-behavior' ),
					'upload_image'     => __( 'Upload / Media Library', 'opti-behavior' ),
					'select_image'     => __( 'Select Image', 'opti-behavior' ),
					'delete_el'        => __( 'Delete', 'opti-behavior' ),
					'duplicate_el'     => __( 'Duplicate', 'opti-behavior' ),
					'copy_el'          => __( 'Copy', 'opti-behavior' ),
					'paste_el'         => __( 'Paste', 'opti-behavior' ),
					'move_up'          => __( 'Move Up', 'opti-behavior' ),
					'move_down'        => __( 'Move Down', 'opti-behavior' ),
					'add_block'        => __( 'Add Block', 'opti-behavior' ),
					'insert_block'     => __( 'Insert Block', 'opti-behavior' ),
					'copied'           => __( 'Element copied!', 'opti-behavior' ),
					'pasted'           => __( 'Element pasted!', 'opti-behavior' ),
					'moved'            => __( 'Element moved!', 'opti-behavior' ),
					'duplicated'       => __( 'Element duplicated!', 'opti-behavior' ),
					'deleted'          => __( 'Element hidden!', 'opti-behavior' ),
					'no_element'       => __( 'Select an element first.', 'opti-behavior' ),
					'nothing_to_paste'         => __( 'Nothing to paste. Copy an element first.', 'opti-behavior' ),
					'changes_label'            => __( 'changes', 'opti-behavior' ),
					'reset_done'               => __( 'All changes reset.', 'opti-behavior' ),
					'reset_failed'             => __( 'Reset failed — try again.', 'opti-behavior' ),
					'block_inserted'           => __( 'Block inserted!', 'opti-behavior' ),
					'no_classes'               => __( '(no classes)', 'opti-behavior' ),
					'remove'                   => __( 'Remove', 'opti-behavior' ),
					'saving'                   => __( 'Saving\xe2\x80\xa6', 'opti-behavior' ),
					'error'                    => __( 'Error', 'opti-behavior' ),
					'network_error'            => __( 'Network error.', 'opti-behavior' ),
					'preview_form_unavailable' => __( 'Preview form is unavailable.', 'opti-behavior' ),
					'no_target_url'            => __( 'No target URL to preview.', 'opti-behavior' ),
					'preview_opened'           => __( 'Preview opened in a new tab.', 'opti-behavior' ),
					'preview_failed'           => __( 'Could not open preview. Please try again.', 'opti-behavior' ),
					'block_heading'            => __( 'Heading', 'opti-behavior' ),
					'block_paragraph'          => __( 'Paragraph', 'opti-behavior' ),
					'block_image'              => __( 'Image', 'opti-behavior' ),
					'block_button'             => __( 'Button', 'opti-behavior' ),
					'block_divider'            => __( 'Divider', 'opti-behavior' ),
					'block_spacer'             => __( 'Spacer', 'opti-behavior' ),
					'block_two_columns'        => __( 'Two Columns', 'opti-behavior' ),
					'block_cta_box'            => __( 'CTA Box', 'opti-behavior' ),
					'attr_default_option'      => __( '(default)', 'opti-behavior' ),
					'attr_new_tab'             => __( 'new tab', 'opti-behavior' ),
					'attr_descriptive_text'    => __( 'Descriptive text', 'opti-behavior' ),
					'attr_tooltip_hint'        => __( 'tooltip', 'opti-behavior' ),
				),
			)
		);
	}

	/**
	 * Render the full-page visual editor.
	 */
	public function render_editor() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'opti-behavior' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$test_id = 0;
		if ( isset( $_GET['test_id'] ) ) {
			$test_id = absint( $_GET['test_id'] );
		} elseif ( isset( $_GET['opti_ab_test_id'] ) ) {
			$test_id = absint( $_GET['opti_ab_test_id'] );
		}

		$variant_id = 0;
		if ( isset( $_GET['variant_id'] ) ) {
			$variant_id = absint( $_GET['variant_id'] );
		} elseif ( isset( $_GET['opti_ab_variant_id'] ) ) {
			$variant_id = absint( $_GET['opti_ab_variant_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $test_id || ! $variant_id ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'A/B Visual Editor', 'opti-behavior' ) . '</h1>';
			echo '<div class="notice notice-error opti-behavior-notice"><p>' . esc_html__( 'Missing test or variant ID.', 'opti-behavior' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			echo '<div class="notice notice-error opti-behavior-notice"><p>' . esc_html__( 'Test not found.', 'opti-behavior' ) . '</p></div>';
			return;
		}

		$builder_url = admin_url(
			add_query_arg(
				array(
					'page'         => 'opti-behavior-ab-testing',
					'view'         => 'builder',
					'test_id'      => $test_id,
					'builder_step' => 3,
				),
				'admin.php'
			)
		);

		$variant = Opti_Behavior_AB_Test_Database::get_variant( $variant_id );
		if ( ! $variant || (int) $variant->test_id !== (int) $test_id ) {
			echo '<div class="notice notice-error opti-behavior-notice"><p>' . esc_html__( 'Visual Editor variant not found for this test. It may have been removed or replaced by an older save.', 'opti-behavior' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $builder_url ) . '">' . esc_html__( 'Back to Builder', 'opti-behavior' ) . '</a></p></div>';
			return;
		}

		// Get the target URL.
		$target_url = $test->target_url;
		if ( ! $target_url && $test->target_post_id ) {
			$target_url = get_permalink( $test->target_post_id );
		}

		if ( ! $target_url ) {
			echo '<div class="notice notice-error opti-behavior-notice"><p>' . esc_html__( 'Test has no target URL.', 'opti-behavior' ) . '</p></div>';
			return;
		}

		// Add preview param so the editor helper JS loads in the iframe.
		$iframe_url = add_query_arg(
			array(
				'opti_ab_visual_editor' => '1',
				'opti_ab_test_id'       => $test_id,
				'opti_ab_variant_id'    => $variant_id,
			),
			$target_url
		);

		// Get variant data.
		$variant_data = json_decode( $variant->variant_data, true );
		if ( ! is_array( $variant_data ) ) {
			$variant_data = array();
		}
		$changes      = isset( $variant_data['changes'] ) ? $variant_data['changes'] : array();
		?>
		<div id="opti-ab-visual-editor" class="opti-ab-ve"
			data-test-id="<?php echo esc_attr( $test_id ); ?>"
			data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
			data-target-url="<?php echo esc_attr( $target_url ); ?>"
			data-changes="<?php echo esc_attr( wp_json_encode( $changes ) ); ?>">

			<form id="opti-ab-ve-preview-form" class="opti-ab-ve-preview-form" method="post" target="_blank" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;" aria-hidden="true">
				<input type="hidden" name="action" value="opti_behavior_ab_visual_preview">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'opti_behavior_ab_visual_preview' ) ); ?>">
				<input type="hidden" name="test_id" value="<?php echo esc_attr( $test_id ); ?>">
				<input type="hidden" name="variant_id" value="<?php echo esc_attr( $variant_id ); ?>">
				<input type="hidden" name="target_url" value="<?php echo esc_attr( $target_url ); ?>">
				<textarea name="changes" id="opti-ab-ve-preview-changes"></textarea>
			</form>

			<!-- Toolbar -->
			<div class="opti-ab-ve-toolbar">
				<div class="opti-ab-ve-toolbar__left">
					<a href="<?php echo esc_url( $builder_url ); ?>" class="opti-ab-ve-back">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
						<?php esc_html_e( 'Back to Builder', 'opti-behavior' ); ?>
					</a>
					<span class="opti-ab-ve-separator">|</span>
					<span class="opti-ab-ve-title">
						<?php echo esc_html( $test->name ); ?> — <?php echo $variant ? esc_html( $variant->name ) : ''; ?>
					</span>
				</div>
				<div class="opti-ab-ve-toolbar__center">
					<div class="opti-ab-ve-mode-toggle">
						<button type="button" class="opti-ab-ve-mode-btn active" data-mode="edit">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
							<?php esc_html_e( 'Edit', 'opti-behavior' ); ?>
						</button>
						<button type="button" class="opti-ab-ve-mode-btn" data-mode="preview">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
							<?php esc_html_e( 'Preview', 'opti-behavior' ); ?>
						</button>
					</div>
				</div>
				<div class="opti-ab-ve-toolbar__right">
					<span class="opti-ab-ve-changes-count" id="opti-ab-ve-changes-count">0 <?php esc_html_e( 'changes', 'opti-behavior' ); ?></span>
					<button type="button" class="opti-ab-ve-undo-btn" id="opti-ab-ve-undo" disabled title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
					</button>
					<button type="button" class="opti-ab-ve-redo-btn" id="opti-ab-ve-redo" disabled title="<?php esc_attr_e( 'Redo (Ctrl+Y)', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg>
					</button>
					<button type="button" class="opti-ab-ve-reset-btn" id="opti-ab-ve-reset" title="<?php esc_attr_e( 'Discard all changes and revert to the original', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
						<?php esc_html_e( 'Reset all', 'opti-behavior' ); ?>
					</button>
					<button type="button" class="button button-primary" id="opti-ab-ve-save">
						<?php esc_html_e( 'Save Changes', 'opti-behavior' ); ?>
					</button>
				</div>
			</div>

			<!-- Reset confirmation modal -->
			<div id="opti-ab-ve-reset-modal" class="opti-ab-ve-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="opti-ab-ve-reset-title">
				<div class="opti-ab-ve-modal">
					<div class="opti-ab-ve-modal__icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
					</div>
					<h2 id="opti-ab-ve-reset-title" class="opti-ab-ve-modal__title"><?php esc_html_e( 'Reset all changes?', 'opti-behavior' ); ?></h2>
					<p class="opti-ab-ve-modal__message">
						<?php esc_html_e( 'This will permanently discard every change you have made to this variant — text, styles, blocks, attributes, classes and JS — and revert the page to its original state.', 'opti-behavior' ); ?>
					</p>
					<p class="opti-ab-ve-modal__warning">
						<strong><?php esc_html_e( 'This action cannot be undone.', 'opti-behavior' ); ?></strong>
					</p>
					<div class="opti-ab-ve-modal__actions">
						<button type="button" id="opti-ab-ve-reset-cancel" class="opti-ab-ve-modal__btn-cancel">
							<?php esc_html_e( 'Cancel', 'opti-behavior' ); ?>
						</button>
						<button type="button" id="opti-ab-ve-reset-confirm" class="opti-ab-ve-modal__btn-danger">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
							<?php esc_html_e( 'Reset everything', 'opti-behavior' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Sidebar: Changes + Inspector + Add Block in a three-region flex column -->
			<div class="opti-ab-ve-sidebar" id="opti-ab-ve-sidebar">
				<!-- Top region: Changes list (collapsible, own scroll) -->
				<div class="opti-ab-ve-sidebar__top" id="opti-ab-ve-sidebar-top">
					<div class="opti-ab-ve-sidebar__header">
						<h3>
							<?php esc_html_e( 'Changes', 'opti-behavior' ); ?>
							<span class="opti-ab-ve-header-count" id="opti-ab-ve-header-count">0</span>
						</h3>
						<button type="button" id="opti-ab-ve-changes-toggle" class="opti-ab-ve-changes-toggle" title="<?php esc_attr_e( 'Collapse changes list', 'opti-behavior' ); ?>" aria-label="<?php esc_attr_e( 'Toggle changes list', 'opti-behavior' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
						</button>
					</div>
					<div class="opti-ab-ve-changes-list" id="opti-ab-ve-changes-list">
						<!-- Populated by JS -->
					</div>
				</div>

				<!-- Middle region: Inspector (element info + actions + edit tabs + panel), single scroll -->
				<div class="opti-ab-ve-sidebar__body" id="opti-ab-ve-sidebar-body">

				<!-- Element Info (shown when an element is selected) -->
				<div class="opti-ab-ve-element-info" id="opti-ab-ve-element-info" style="display:none;">
					<div class="opti-ab-ve-element-info__row">
						<span class="opti-ab-ve-element-info__label"><?php esc_html_e( 'Element:', 'opti-behavior' ); ?></span>
						<code id="opti-ab-ve-element-tag"></code>
					</div>
					<div class="opti-ab-ve-element-info__row" id="opti-ab-ve-element-origin-wrap" style="display:none;">
						<span class="opti-ab-ve-element-info__label"><?php esc_html_e( 'Source:', 'opti-behavior' ); ?></span>
						<span id="opti-ab-ve-element-origin" class="opti-ab-ve-origin-badge"></span>
					</div>
					<div class="opti-ab-ve-element-info__row" id="opti-ab-ve-element-type-wrap" style="display:none;">
						<span class="opti-ab-ve-element-info__label"><?php esc_html_e( 'Block:', 'opti-behavior' ); ?></span>
						<span id="opti-ab-ve-element-type"></span>
					</div>
					<!-- Breadcrumb: clickable ancestor chain -->
					<div class="opti-ab-ve-breadcrumb" id="opti-ab-ve-breadcrumb"></div>
				</div>

				<!-- Element Actions Toolbar — labeled, icon-distinct -->
				<div class="opti-ab-ve-actions" id="opti-ab-ve-actions" style="display:none;">
					<button type="button" class="opti-ab-ve-action-btn" data-action="select-parent" title="<?php esc_attr_e( 'Select parent element', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 11V5a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v6"/><path d="M4 11h16"/><path d="M6 11v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-8"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Parent', 'opti-behavior' ); ?></span>
					</button>
					<button type="button" class="opti-ab-ve-action-btn" data-action="select-child" title="<?php esc_attr_e( 'Select first child', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="13" width="10" height="6" rx="1"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Child', 'opti-behavior' ); ?></span>
					</button>
					<span class="opti-ab-ve-actions-sep"></span>
					<button type="button" class="opti-ab-ve-action-btn" data-action="duplicate" title="<?php esc_attr_e( 'Duplicate element in place', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16"/><path d="M4 12h16"/><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Duplicate', 'opti-behavior' ); ?></span>
					</button>
					<button type="button" class="opti-ab-ve-action-btn" data-action="copy" title="<?php esc_attr_e( 'Copy element to clipboard', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Copy', 'opti-behavior' ); ?></span>
					</button>
					<button type="button" class="opti-ab-ve-action-btn" data-action="paste" id="opti-ab-ve-paste-btn" title="<?php esc_attr_e( 'Paste after selected', 'opti-behavior' ); ?>" disabled>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 14h6"/><path d="m12 11 3 3-3 3"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Paste', 'opti-behavior' ); ?></span>
					</button>
					<span class="opti-ab-ve-actions-sep"></span>
					<button type="button" class="opti-ab-ve-action-btn" data-action="move-up" title="<?php esc_attr_e( 'Move up', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Up', 'opti-behavior' ); ?></span>
					</button>
					<button type="button" class="opti-ab-ve-action-btn" data-action="move-down" title="<?php esc_attr_e( 'Move down', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Down', 'opti-behavior' ); ?></span>
					</button>
					<button type="button" class="opti-ab-ve-action-btn opti-ab-ve-action-btn--danger" data-action="delete" title="<?php esc_attr_e( 'Hide element', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
						<span class="opti-ab-ve-action-lbl"><?php esc_html_e( 'Hide', 'opti-behavior' ); ?></span>
					</button>
				</div>

				<!-- Edit Panel — scrollable inspector with 7 tabs -->
				<div class="opti-ab-ve-edit-panel" id="opti-ab-ve-edit-panel" style="display:none;">
					<div class="opti-ab-ve-edit-tabs" role="tablist">
						<button type="button" class="opti-ab-ve-tab active" data-tab="design" title="<?php esc_attr_e( 'Visual style controls (colors, typography, spacing)', 'opti-behavior' ); ?>"><?php esc_html_e( 'Design', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="text" title="<?php esc_attr_e( 'Plain text content', 'opti-behavior' ); ?>"><?php esc_html_e( 'Text', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="html" title="<?php esc_attr_e( 'Inner HTML', 'opti-behavior' ); ?>"><?php esc_html_e( 'HTML', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="css" title="<?php esc_attr_e( 'Inline CSS overrides', 'opti-behavior' ); ?>"><?php esc_html_e( 'CSS', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="url" id="opti-ab-ve-url-tab" style="display:none;" title="<?php esc_attr_e( 'URL / href / src', 'opti-behavior' ); ?>"><?php esc_html_e( 'URL', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="attrs" title="<?php esc_attr_e( 'HTML attributes', 'opti-behavior' ); ?>"><?php esc_html_e( 'Attrs', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="classes" title="<?php esc_attr_e( 'CSS classes', 'opti-behavior' ); ?>"><?php esc_html_e( 'Classes', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="js" title="<?php esc_attr_e( 'Custom JavaScript on this element', 'opti-behavior' ); ?>"><?php esc_html_e( 'JS', 'opti-behavior' ); ?></button>
						<button type="button" class="opti-ab-ve-tab" data-tab="image" id="opti-ab-ve-image-tab" style="display:none;"><?php esc_html_e( 'Image', 'opti-behavior' ); ?></button>
					</div>

					<!-- Design tab: visual controls for colours, typography, spacing, border, effects -->
					<div class="opti-ab-ve-tab-content active" data-tab="design">
						<!-- Section: Colors -->
						<div class="opti-ab-ve-design-section">
							<div class="opti-ab-ve-design-section__title">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
								<?php esc_html_e( 'Colors', 'opti-behavior' ); ?>
							</div>
							<div class="opti-ab-ve-design-grid">
								<div class="opti-ab-ve-color-field" data-prop="color">
									<label><?php esc_html_e( 'Text', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-color-pick">
										<input type="color" class="opti-ab-ve-color-swatch" data-role="swatch" value="#000000">
										<input type="text" class="opti-ab-ve-input opti-ab-ve-color-hex" data-role="hex" placeholder="#000000">
										<button type="button" class="opti-ab-ve-color-clear" title="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>" aria-label="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>">&times;</button>
									</div>
								</div>
								<div class="opti-ab-ve-color-field" data-prop="background-color">
									<label><?php esc_html_e( 'Background', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-color-pick">
										<input type="color" class="opti-ab-ve-color-swatch" data-role="swatch" value="#ffffff">
										<input type="text" class="opti-ab-ve-input opti-ab-ve-color-hex" data-role="hex" placeholder="#ffffff">
										<button type="button" class="opti-ab-ve-color-clear" title="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>" aria-label="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>">&times;</button>
									</div>
								</div>
								<div class="opti-ab-ve-color-field" data-prop="border-color">
									<label><?php esc_html_e( 'Border', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-color-pick">
										<input type="color" class="opti-ab-ve-color-swatch" data-role="swatch" value="#000000">
										<input type="text" class="opti-ab-ve-input opti-ab-ve-color-hex" data-role="hex" placeholder="#000000">
										<button type="button" class="opti-ab-ve-color-clear" title="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>" aria-label="<?php esc_attr_e( 'Clear', 'opti-behavior' ); ?>">&times;</button>
									</div>
								</div>
							</div>
						</div>

						<!-- Section: Typography -->
						<div class="opti-ab-ve-design-section">
							<div class="opti-ab-ve-design-section__title">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>
								<?php esc_html_e( 'Typography', 'opti-behavior' ); ?>
							</div>
							<div class="opti-ab-ve-design-grid opti-ab-ve-design-grid--two">
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-fontsize"><?php esc_html_e( 'Font size', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-unit-wrap">
										<input type="number" id="opti-ab-ve-ds-fontsize" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="font-size" data-unit="px" min="1" max="300" placeholder="16">
										<span class="opti-ab-ve-unit">px</span>
									</div>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-fontweight"><?php esc_html_e( 'Weight', 'opti-behavior' ); ?></label>
									<select id="opti-ab-ve-ds-fontweight" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="font-weight">
										<option value="">—</option>
										<option value="300"><?php esc_html_e( 'Light', 'opti-behavior' ); ?> 300</option>
										<option value="400"><?php esc_html_e( 'Regular', 'opti-behavior' ); ?> 400</option>
										<option value="500"><?php esc_html_e( 'Medium', 'opti-behavior' ); ?> 500</option>
										<option value="600"><?php esc_html_e( 'Semi', 'opti-behavior' ); ?> 600</option>
										<option value="700"><?php esc_html_e( 'Bold', 'opti-behavior' ); ?> 700</option>
										<option value="800"><?php esc_html_e( 'Extra', 'opti-behavior' ); ?> 800</option>
										<option value="900"><?php esc_html_e( 'Black', 'opti-behavior' ); ?> 900</option>
									</select>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-lineheight"><?php esc_html_e( 'Line height', 'opti-behavior' ); ?></label>
									<input type="number" id="opti-ab-ve-ds-lineheight" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="line-height" step="0.05" min="0.5" max="4" placeholder="1.5">
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-letterspacing"><?php esc_html_e( 'Letter spacing', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-unit-wrap">
										<input type="number" id="opti-ab-ve-ds-letterspacing" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="letter-spacing" data-unit="px" step="0.1" min="-5" max="20" placeholder="0">
										<span class="opti-ab-ve-unit">px</span>
									</div>
								</div>
							</div>
							<div class="opti-ab-ve-design-grid opti-ab-ve-design-grid--two">
								<div class="opti-ab-ve-design-field">
									<label><?php esc_html_e( 'Align', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-btn-group" data-prop="text-align">
										<button type="button" class="opti-ab-ve-seg-btn" data-value="left" title="<?php esc_attr_e( 'Left', 'opti-behavior' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" x2="3" y1="6" y2="6"/><line x1="15" x2="3" y1="12" y2="12"/><line x1="17" x2="3" y1="18" y2="18"/></svg></button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="center" title="<?php esc_attr_e( 'Center', 'opti-behavior' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" x2="3" y1="6" y2="6"/><line x1="17" x2="7" y1="12" y2="12"/><line x1="19" x2="5" y1="18" y2="18"/></svg></button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="right" title="<?php esc_attr_e( 'Right', 'opti-behavior' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" x2="3" y1="6" y2="6"/><line x1="21" x2="9" y1="12" y2="12"/><line x1="21" x2="7" y1="18" y2="18"/></svg></button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="justify" title="<?php esc_attr_e( 'Justify', 'opti-behavior' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="6" y2="6"/><line x1="3" x2="21" y1="12" y2="12"/><line x1="3" x2="21" y1="18" y2="18"/></svg></button>
									</div>
								</div>
								<div class="opti-ab-ve-design-field">
									<label><?php esc_html_e( 'Transform', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-btn-group" data-prop="text-transform">
										<button type="button" class="opti-ab-ve-seg-btn" data-value="none" title="aA">aA</button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="uppercase" title="AA">AA</button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="lowercase" title="aa">aa</button>
										<button type="button" class="opti-ab-ve-seg-btn" data-value="capitalize" title="Aa">Aa</button>
									</div>
								</div>
							</div>
							<div class="opti-ab-ve-design-field">
								<label><?php esc_html_e( 'Style', 'opti-behavior' ); ?></label>
								<div class="opti-ab-ve-style-row">
									<label class="opti-ab-ve-style-toggle">
										<input type="checkbox" class="opti-ab-ve-design-toggle" data-prop="font-style" data-on="italic" data-off="normal">
										<span style="font-style:italic;">I</span>
									</label>
									<label class="opti-ab-ve-style-toggle">
										<input type="checkbox" class="opti-ab-ve-design-toggle" data-prop="text-decoration" data-on="underline" data-off="none">
										<span style="text-decoration:underline;">U</span>
									</label>
									<label class="opti-ab-ve-style-toggle">
										<input type="checkbox" class="opti-ab-ve-design-toggle" data-prop="text-decoration" data-on="line-through" data-off="none">
										<span style="text-decoration:line-through;">S</span>
									</label>
								</div>
							</div>
						</div>

						<!-- Section: Spacing -->
						<div class="opti-ab-ve-design-section">
							<div class="opti-ab-ve-design-section__title">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="2"/><rect width="12" height="12" x="6" y="6" rx="1"/></svg>
								<?php esc_html_e( 'Spacing', 'opti-behavior' ); ?>
							</div>
							<div class="opti-ab-ve-box-model">
								<div class="opti-ab-ve-box-model__label"><?php esc_html_e( 'Padding', 'opti-behavior' ); ?></div>
								<div class="opti-ab-ve-box-grid">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="padding-top" data-unit="px" placeholder="T" title="<?php esc_attr_e( 'Top', 'opti-behavior' ); ?>" min="0">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="padding-right" data-unit="px" placeholder="R" title="<?php esc_attr_e( 'Right', 'opti-behavior' ); ?>" min="0">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="padding-bottom" data-unit="px" placeholder="B" title="<?php esc_attr_e( 'Bottom', 'opti-behavior' ); ?>" min="0">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="padding-left" data-unit="px" placeholder="L" title="<?php esc_attr_e( 'Left', 'opti-behavior' ); ?>" min="0">
								</div>
							</div>
							<div class="opti-ab-ve-box-model">
								<div class="opti-ab-ve-box-model__label"><?php esc_html_e( 'Margin', 'opti-behavior' ); ?></div>
								<div class="opti-ab-ve-box-grid">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="margin-top" data-unit="px" placeholder="T" title="<?php esc_attr_e( 'Top', 'opti-behavior' ); ?>">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="margin-right" data-unit="px" placeholder="R" title="<?php esc_attr_e( 'Right', 'opti-behavior' ); ?>">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="margin-bottom" data-unit="px" placeholder="B" title="<?php esc_attr_e( 'Bottom', 'opti-behavior' ); ?>">
									<input type="number" class="opti-ab-ve-input opti-ab-ve-box-input opti-ab-ve-design-control" data-prop="margin-left" data-unit="px" placeholder="L" title="<?php esc_attr_e( 'Left', 'opti-behavior' ); ?>">
								</div>
							</div>
						</div>

						<!-- Section: Border -->
						<div class="opti-ab-ve-design-section">
							<div class="opti-ab-ve-design-section__title">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/></svg>
								<?php esc_html_e( 'Border & radius', 'opti-behavior' ); ?>
							</div>
							<div class="opti-ab-ve-design-grid opti-ab-ve-design-grid--two">
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-borderwidth"><?php esc_html_e( 'Width', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-unit-wrap">
										<input type="number" id="opti-ab-ve-ds-borderwidth" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="border-width" data-unit="px" min="0" max="20" placeholder="0">
										<span class="opti-ab-ve-unit">px</span>
									</div>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-borderstyle"><?php esc_html_e( 'Style', 'opti-behavior' ); ?></label>
									<select id="opti-ab-ve-ds-borderstyle" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="border-style">
										<option value="">—</option>
										<option value="solid">solid</option>
										<option value="dashed">dashed</option>
										<option value="dotted">dotted</option>
										<option value="double">double</option>
										<option value="none">none</option>
									</select>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-borderradius"><?php esc_html_e( 'Radius', 'opti-behavior' ); ?></label>
									<div class="opti-ab-ve-unit-wrap">
										<input type="number" id="opti-ab-ve-ds-borderradius" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="border-radius" data-unit="px" min="0" max="200" placeholder="0">
										<span class="opti-ab-ve-unit">px</span>
									</div>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-opacity"><?php esc_html_e( 'Opacity', 'opti-behavior' ); ?></label>
									<input type="range" id="opti-ab-ve-ds-opacity" class="opti-ab-ve-design-control" data-prop="opacity" min="0" max="1" step="0.05" value="1">
								</div>
							</div>
						</div>

						<!-- Section: Effects -->
						<div class="opti-ab-ve-design-section">
							<div class="opti-ab-ve-design-section__title">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11-6 6v3h9l3-3"/><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"/></svg>
								<?php esc_html_e( 'Effects', 'opti-behavior' ); ?>
							</div>
							<div class="opti-ab-ve-design-grid opti-ab-ve-design-grid--two">
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-shadow"><?php esc_html_e( 'Shadow', 'opti-behavior' ); ?></label>
									<select id="opti-ab-ve-ds-shadow" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="box-shadow">
										<option value="">—</option>
										<option value="0 2px 6px rgba(0,0,0,0.25)"><?php esc_html_e( 'Soft', 'opti-behavior' ); ?></option>
										<option value="0 4px 12px rgba(0,0,0,0.35)"><?php esc_html_e( 'Small', 'opti-behavior' ); ?></option>
										<option value="0 8px 20px rgba(0,0,0,0.45)"><?php esc_html_e( 'Medium', 'opti-behavior' ); ?></option>
										<option value="0 12px 30px rgba(0,0,0,0.55)"><?php esc_html_e( 'Large', 'opti-behavior' ); ?></option>
										<option value="0 20px 40px rgba(0,0,0,0.65)"><?php esc_html_e( 'XL', 'opti-behavior' ); ?></option>
										<option value="0 25px 50px -12px rgba(0,0,0,0.8)"><?php esc_html_e( '2XL', 'opti-behavior' ); ?></option>
										<option value="0 0 20px rgba(99,102,241,0.7)"><?php esc_html_e( 'Glow · Purple', 'opti-behavior' ); ?></option>
										<option value="0 0 20px rgba(220,38,38,0.7)"><?php esc_html_e( 'Glow · Red', 'opti-behavior' ); ?></option>
										<option value="0 0 20px rgba(251,191,36,0.8)"><?php esc_html_e( 'Glow · Gold', 'opti-behavior' ); ?></option>
										<option value="0 0 20px rgba(34,197,94,0.7)"><?php esc_html_e( 'Glow · Green', 'opti-behavior' ); ?></option>
										<option value="inset 0 2px 6px rgba(0,0,0,0.35)"><?php esc_html_e( 'Inset · Soft', 'opti-behavior' ); ?></option>
										<option value="inset 0 4px 12px rgba(0,0,0,0.45)"><?php esc_html_e( 'Inset · Deep', 'opti-behavior' ); ?></option>
										<option value="none"><?php esc_html_e( 'None (remove)', 'opti-behavior' ); ?></option>
									</select>
								</div>
								<div class="opti-ab-ve-design-field">
									<label for="opti-ab-ve-ds-cursor"><?php esc_html_e( 'Cursor', 'opti-behavior' ); ?></label>
									<select id="opti-ab-ve-ds-cursor" class="opti-ab-ve-input opti-ab-ve-design-control" data-prop="cursor">
										<option value="">—</option>
										<option value="pointer">pointer</option>
										<option value="default">default</option>
										<option value="text">text</option>
										<option value="not-allowed">not-allowed</option>
										<option value="grab">grab</option>
										<option value="help">help</option>
										<option value="wait">wait</option>
									</select>
								</div>
							</div>
						</div>

						<div class="opti-ab-ve-design-footer">
							<button type="button" id="opti-ab-ve-design-reset" class="button opti-ab-ve-design-reset-btn">
								<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
								<?php esc_html_e( 'Reset design', 'opti-behavior' ); ?>
							</button>
							<p class="opti-ab-ve-help"><?php esc_html_e( 'Changes here are applied as inline CSS. Leave a field empty to keep the original value.', 'opti-behavior' ); ?></p>
						</div>
					</div>

					<div class="opti-ab-ve-tab-content" data-tab="text">
						<textarea id="opti-ab-ve-text-editor" class="opti-ab-ve-textarea" rows="6" placeholder="<?php esc_attr_e( 'Enter new text content…', 'opti-behavior' ); ?>"></textarea>
						<p class="opti-ab-ve-help"><?php esc_html_e( 'Replaces only the visible text — keeps formatting and child elements if the tag has none.', 'opti-behavior' ); ?></p>
					</div>
					<div class="opti-ab-ve-tab-content" data-tab="html">
						<textarea id="opti-ab-ve-html-editor" class="opti-ab-ve-textarea opti-ab-ve-textarea--code" rows="10" placeholder="<?php esc_attr_e( 'Enter HTML…', 'opti-behavior' ); ?>"></textarea>
						<p class="opti-ab-ve-help"><?php esc_html_e( 'Replaces the inner HTML of the selected element.', 'opti-behavior' ); ?></p>
					</div>
					<div class="opti-ab-ve-tab-content" data-tab="css">
						<textarea id="opti-ab-ve-css-editor" class="opti-ab-ve-textarea opti-ab-ve-textarea--code" rows="6" placeholder="<?php esc_attr_e( 'e.g., color: red; font-size: 18px;', 'opti-behavior' ); ?>"></textarea>
						<p class="opti-ab-ve-help"><?php esc_html_e( 'CSS declarations are appended inline with !important flags preserved.', 'opti-behavior' ); ?></p>
					</div>

					<!-- URL tab: shown for <a>, <img>, <form>, <iframe>, <video>, <audio>, <source> -->
					<div class="opti-ab-ve-tab-content" data-tab="url">
						<div class="opti-ab-ve-field">
							<label for="opti-ab-ve-url-href"><?php esc_html_e( 'URL / href / src', 'opti-behavior' ); ?></label>
							<input type="text" id="opti-ab-ve-url-href" class="opti-ab-ve-input" placeholder="https://example.com/page">
						</div>
						<div class="opti-ab-ve-field" id="opti-ab-ve-url-target-wrap" style="display:none;">
							<label for="opti-ab-ve-url-target"><?php esc_html_e( 'Open in', 'opti-behavior' ); ?></label>
							<select id="opti-ab-ve-url-target" class="opti-ab-ve-input">
								<option value=""><?php esc_html_e( 'Same tab (default)', 'opti-behavior' ); ?></option>
								<option value="_blank"><?php esc_html_e( 'New tab (_blank)', 'opti-behavior' ); ?></option>
								<option value="_parent"><?php esc_html_e( 'Parent frame (_parent)', 'opti-behavior' ); ?></option>
								<option value="_top"><?php esc_html_e( 'Top frame (_top)', 'opti-behavior' ); ?></option>
							</select>
						</div>
						<div class="opti-ab-ve-field" id="opti-ab-ve-url-rel-wrap" style="display:none;">
							<label for="opti-ab-ve-url-rel"><?php esc_html_e( 'rel', 'opti-behavior' ); ?></label>
							<input type="text" id="opti-ab-ve-url-rel" class="opti-ab-ve-input" placeholder="noopener noreferrer">
						</div>
					</div>

					<!-- Attributes tab: dynamic form built per tagName -->
					<div class="opti-ab-ve-tab-content" data-tab="attrs">
						<div id="opti-ab-ve-attrs-form" class="opti-ab-ve-attrs-form">
							<!-- Populated dynamically based on selected element's tag -->
						</div>
						<div class="opti-ab-ve-attrs-footer">
							<label for="opti-ab-ve-attrs-custom-name"><?php esc_html_e( 'Custom attribute', 'opti-behavior' ); ?></label>
							<div class="opti-ab-ve-attrs-custom">
								<input type="text" id="opti-ab-ve-attrs-custom-name" class="opti-ab-ve-input" placeholder="data-analytics">
								<input type="text" id="opti-ab-ve-attrs-custom-value" class="opti-ab-ve-input" placeholder="value">
								<button type="button" class="button" id="opti-ab-ve-attrs-custom-add"><?php esc_html_e( 'Add', 'opti-behavior' ); ?></button>
							</div>
						</div>
					</div>

					<!-- Classes tab: chips -->
					<div class="opti-ab-ve-tab-content" data-tab="classes">
						<div class="opti-ab-ve-field">
							<label><?php esc_html_e( 'Current classes (click to remove)', 'opti-behavior' ); ?></label>
							<div id="opti-ab-ve-classes-current" class="opti-ab-ve-class-chips">
								<!-- Populated dynamically -->
							</div>
						</div>
						<div class="opti-ab-ve-field">
							<label for="opti-ab-ve-class-add-input"><?php esc_html_e( 'Add class', 'opti-behavior' ); ?></label>
							<div class="opti-ab-ve-class-add-row">
								<input type="text" id="opti-ab-ve-class-add-input" class="opti-ab-ve-input" placeholder="my-custom-class">
								<button type="button" class="button" id="opti-ab-ve-class-add-btn"><?php esc_html_e( 'Add', 'opti-behavior' ); ?></button>
							</div>
						</div>
					</div>

					<!-- JS tab: snippet runs against the selected element in the variant -->
					<div class="opti-ab-ve-tab-content" data-tab="js">
						<div class="opti-ab-ve-js-hint">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
							<span><?php esc_html_e( 'Runs once per visitor on page load. Receives el = the selected element.', 'opti-behavior' ); ?></span>
						</div>
						<textarea id="opti-ab-ve-js-editor" class="opti-ab-ve-textarea opti-ab-ve-textarea--code" rows="10" placeholder="// el.classList.add('promo');&#10;// el.addEventListener('click', function(){ /* … */ });"></textarea>
						<p class="opti-ab-ve-help"><?php esc_html_e( 'Your code is wrapped in: function(el){ /* your code */ }(document.querySelector(selector)). Do not include &lt;script&gt; tags.', 'opti-behavior' ); ?></p>
					</div>

					<div class="opti-ab-ve-tab-content" data-tab="image">
						<div class="opti-ab-ve-image-preview" id="opti-ab-ve-image-preview">
							<img id="opti-ab-ve-image-preview-img" src="" alt="" style="max-width:100%;border-radius:6px;">
						</div>
						<div class="opti-ab-ve-image-controls">
							<label class="opti-ab-ve-image-label"><?php esc_html_e( 'Image URL:', 'opti-behavior' ); ?></label>
							<input type="text" id="opti-ab-ve-image-url" class="opti-ab-ve-input" placeholder="https://...">
							<button type="button" class="button" id="opti-ab-ve-image-upload">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
								<?php esc_html_e( 'Upload / Media Library', 'opti-behavior' ); ?>
							</button>
						</div>
					</div>

					<div class="opti-ab-ve-edit-actions">
						<label class="opti-ab-ve-checkbox">
							<input type="checkbox" id="opti-ab-ve-hide-element">
							<?php esc_html_e( 'Hide Element', 'opti-behavior' ); ?>
						</label>
						<div>
							<button type="button" class="button" id="opti-ab-ve-cancel-edit"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
							<button type="button" class="button button-primary" id="opti-ab-ve-apply-edit"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
						</div>
					</div>
				</div>

				</div><!-- /.opti-ab-ve-sidebar__body -->

				<!-- Bottom region: pinned Add Block + Insert Block drawer -->
				<div class="opti-ab-ve-sidebar__bottom">
					<div class="opti-ab-ve-block-panel" id="opti-ab-ve-block-panel" style="display:none;">
						<div class="opti-ab-ve-block-panel__header">
							<h4><?php esc_html_e( 'Insert Block', 'opti-behavior' ); ?></h4>
							<button type="button" class="opti-ab-ve-block-panel__close" id="opti-ab-ve-block-panel-close">&times;</button>
						</div>
						<div class="opti-ab-ve-block-grid" id="opti-ab-ve-block-grid">
							<!-- Populated by JS -->
						</div>
					</div>
					<div class="opti-ab-ve-add-block-wrap">
						<button type="button" class="button opti-ab-ve-add-block-btn" id="opti-ab-ve-add-block-btn">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
							<?php esc_html_e( 'Add Block', 'opti-behavior' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Iframe Container -->
			<div class="opti-ab-ve-iframe-wrap" id="opti-ab-ve-iframe-wrap">
				<div class="opti-ab-ve-iframe-loading" id="opti-ab-ve-iframe-loading">
					<div class="opti-ab-spinner"></div>
					<p><?php esc_html_e( 'Loading page…', 'opti-behavior' ); ?></p>
				</div>
				<iframe id="opti-ab-ve-iframe" src="<?php echo esc_url( $iframe_url ); ?>" class="opti-ab-ve-iframe"></iframe>
			</div>
		</div>
		<?php
	}

	/**
	 * Inject a helper script into the frontend when visual editor preview is active,
	 * or when the goal element selector is open (`opti_ab_goal_selector=1`).
	 *
	 * This script enables element selection highlighting and cross-frame communication.
	 */
	public function maybe_inject_editor_helper() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_visual_editor = ! empty( $_GET['opti_ab_visual_editor'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_goal_selector = ! empty( $_GET['opti_ab_goal_selector'] );

		if ( ! $is_visual_editor && ! $is_goal_selector ) {
			return;
		}

		// Only for logged-in admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$version = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.2.8';
		$ve_version = $version . '.' . filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/ab-test-ve-helper.js' );

		wp_enqueue_script(
			'opti-behavior-ab-ve-helper',
			plugins_url( 'assets/js/ab-test-ve-helper.js', dirname( __FILE__ ) ),
			array(),
			$ve_version,
			true
		);

		wp_localize_script(
			'opti-behavior-ab-ve-helper',
			'optiBehaviorVEHelper',
			array(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'test_id'    => isset( $_GET['opti_ab_test_id'] ) ? absint( $_GET['opti_ab_test_id'] ) : 0,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'variant_id' => isset( $_GET['opti_ab_variant_id'] ) ? absint( $_GET['opti_ab_variant_id'] ) : 0,
				'strings'    => array(
					'goal_selector_instruction' => __( 'Click any element to use it as the goal target — hovering highlights elements', 'opti-behavior' ),
					'goal_selector_selected'    => __( 'Element selected! The selector has been copied \xe2\x80\x94 closing\xe2\x80\xa6', 'opti-behavior' ),
				),
			)
		);
	}

	/**
	 * AJAX: Save visual editor changes to the variant.
	 */
	public function ajax_save_visual_changes() {
		check_ajax_referer( 'opti_behavior_ab_pro_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id    = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$changes_json = isset( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : '[]';
		$changes      = json_decode( $changes_json, true );

		if ( ! $test_id || ! $variant_id || ! is_array( $changes ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'opti-behavior' ) ) );
		}

		$result = $this->save_visual_changes( $test_id, $variant_id, $changes );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Changes saved.', 'opti-behavior' ) ) );
	}

	/**
	 * Handle the native preview form submit.
	 */
	public function handle_visual_preview() {
		check_admin_referer( 'opti_behavior_ab_visual_preview', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'opti-behavior' ) );
		}

		$test_id    = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$changes_json = isset( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : '[]';
		$changes      = json_decode( $changes_json, true );

		if ( ! $test_id || ! $variant_id || ! is_array( $changes ) ) {
			wp_die( esc_html__( 'Invalid preview request.', 'opti-behavior' ) );
		}

		$result = $this->save_visual_changes( $test_id, $variant_id, $changes );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			wp_die( esc_html__( 'Test not found.', 'opti-behavior' ) );
		}

		$target_url = $test->target_url;
		if ( ! $target_url && $test->target_post_id ) {
			$target_url = get_permalink( $test->target_post_id );
		}
		if ( ! $target_url && ! empty( $_POST['target_url'] ) ) {
			$target_url = esc_url_raw( wp_unslash( $_POST['target_url'] ) );
		}
		if ( ! $target_url ) {
			wp_die( esc_html__( 'Test has no target URL.', 'opti-behavior' ) );
		}

		$preview_url = add_query_arg(
			array(
				'opti_ab_preview_test'    => $test_id,
				'opti_ab_preview_variant' => $variant_id,
				'opti_ab_cache_bust'      => time(),
			),
			$target_url
		);

		wp_safe_redirect( $preview_url );
		exit;
	}

	/**
	 * Persist visual editor changes for one variant.
	 *
	 * @param int   $test_id    A/B test ID.
	 * @param int   $variant_id Variant ID.
	 * @param array $changes    Visual editor change list.
	 * @return true|WP_Error
	 */
	private function save_visual_changes( $test_id, $variant_id, $changes ) {
		$variant = Opti_Behavior_AB_Test_Database::get_variant( $variant_id );

		if ( ! $variant || (int) $variant->test_id !== (int) $test_id ) {
			return new WP_Error( 'opti_behavior_ab_visual_variant_missing', __( 'Variant not found.', 'opti-behavior' ) );
		}

		$variant_data = json_decode( $variant->variant_data, true );
		if ( ! is_array( $variant_data ) ) {
			$variant_data = array();
		}

		$variant_data['changes'] = $changes;

		$updated = Opti_Behavior_AB_Test_Database::update_variant(
			$variant_id,
			array( 'variant_data' => wp_json_encode( $variant_data ) )
		);

		if ( ! $updated ) {
			return new WP_Error( 'opti_behavior_ab_visual_save_failed', __( 'Could not save changes.', 'opti-behavior' ) );
		}

		return true;
	}

}
