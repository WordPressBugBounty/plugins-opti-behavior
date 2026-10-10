<?php
/**
 * Pro feature page seen in Free (Session Recordings, User Journeys, Errors
 * Tracking, Form Analytics): one template for the 4 pages.
 *
 * Information page only: no Pro code, no Pro screen, no data of this site.
 * The preview is a local picture labelled "Example data"; nothing remote is
 * loaded while the page renders.
 *
 * Included (never require_once: tests render it several times) by
 * admin/{recordings,user-journey,errors,form-analytics}-upgrade-page.php,
 * which set $opti_behavior_pro_page:
 *   placement utm_medium of the trial page link (pro-page-recordings, …)
 *   icon     Lucide icon of the header
 *   title    page title (h1)
 *   promise  header subtitle
 *   image    file name in assets/images/pro-preview/
 *   caption  caption of the preview
 *   cases    3 sentences "What you'll find out"
 *   compare  rows array( feature, in Free (bool), in Pro (bool) )
 *   faq      rows array( question, answer ); the install question is added here
 *   cta_text sentence above the Download button
 *
 * @package OptiBehavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $opti_behavior_pro_page ) || ! is_array( $opti_behavior_pro_page ) ) {
	return;
}

$opti_behavior_pp = wp_parse_args(
	$opti_behavior_pro_page,
	array(
		'placement' => 'pro-page',
		'icon'     => 'sparkles',
		'title'    => '',
		'promise'  => '',
		'image'    => '',
		'caption'  => '',
		'cases'    => array(),
		'compare'  => array(),
		'faq'      => array(),
		'cta_text' => '',
	)
);

$opti_behavior_pp['faq'][] = array(
	__( 'How do I install Pro?', 'opti-behavior' ),
	__( 'Download it from optiuser.com, then upload it in WordPress (Plugins › Add New › Upload Plugin). The Free plugin never installs Pro by itself.', 'opti-behavior' ),
);

// The single Pro trial page (OPT-764): a plain link, nothing is sent from
// WordPress, the browser opens the page on click.
$opti_behavior_download_url = class_exists( 'Opti_Behavior_Trial_Invite' )
	? Opti_Behavior_Trial_Invite::link_url( $opti_behavior_pp['placement'] )
	: '';

$opti_behavior_pp_image = ( '' !== $opti_behavior_pp['image'] && defined( 'OPTI_BEHAVIOR_HEATMAP_ASSETS_URL' ) )
	? OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/pro-preview/' . $opti_behavior_pp['image']
	: '';
?>

<div class="wrap opti-behavior-recordings-upgrade-page ob-pro-page">
	<!-- Header -->
	<div class="recordings-header">
		<div class="recordings-header-content">
			<div class="recordings-title-section">
				<div class="recordings-icon"><i data-lucide="<?php echo esc_attr( $opti_behavior_pp['icon'] ); ?>"></i></div>
				<div class="recordings-title-text">
					<h1 class="recordings-title"><?php echo esc_html( $opti_behavior_pp['title'] ); ?></h1>
					<div class="recordings-subtitle"><?php echo esc_html( $opti_behavior_pp['promise'] ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<div class="ob-pro-page__body">
		<?php /* CTA first (OPT-758): visible without scrolling, before the example preview. */ ?>
		<div class="upgrade-card ob-pro-cta" id="ob-pro-cta">
			<h2 class="upgrade-title"><?php esc_html_e( 'Try Pro free for 6 months', 'opti-behavior' ); ?></h2>

			<div class="upgrade-cta">
				<div class="free-badge">
					<i data-lucide="gift"></i>
					<span><?php esc_html_e( '6-Month Free Trial!', 'opti-behavior' ); ?></span>
				</div>

				<p class="cta-text"><?php echo esc_html( $opti_behavior_pp['cta_text'] ); ?></p>

				<div class="ob-pro-cta__actions">
					<a href="<?php echo esc_url( $opti_behavior_download_url ); ?>" class="button button-primary button-hero opti-behavior-download-cta" target="_blank" rel="noopener noreferrer">
						<i data-lucide="download"></i>
						<?php esc_html_e( 'Download Pro — Free for 6 Months', 'opti-behavior' ); ?>
					</a>
					<a href="https://demo.optiuser.com/?demo_login=go" class="ob-pro-demo-btn is-pulse" target="_blank" rel="noopener">
						<i data-lucide="play-circle"></i>
						<?php esc_html_e( 'See it live in the demo', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span>
					</a>
				</div>

				<?php
				if ( class_exists( 'Opti_Behavior_Trial_Invite' ) ) {
					Opti_Behavior_Trial_Invite::render_info_lines( 'download' );
				}
				?>

				<p class="help-text">
					<?php esc_html_e( 'Need help installing? Visit our documentation or contact support.', 'opti-behavior' ); ?>
				</p>
			</div>
		</div>

		<?php if ( '' !== $opti_behavior_pp_image ) : ?>
		<figure class="ob-pro-page__section ob-pro-preview">
			<div class="ob-pro-preview__frame">
				<span class="ob-pro-preview__chip"><i data-lucide="flask-conical"></i><?php esc_html_e( 'Example data', 'opti-behavior' ); ?></span>
				<img class="ob-pro-preview__img" src="<?php echo esc_url( $opti_behavior_pp_image ); ?>" width="960" height="440" alt="<?php echo esc_attr( $opti_behavior_pp['caption'] . ' ' . __( 'Example data.', 'opti-behavior' ) ); ?>" decoding="async">
			</div>
			<figcaption class="ob-pro-preview__caption"><?php echo esc_html( $opti_behavior_pp['caption'] ); ?></figcaption>
		</figure>
		<?php endif; ?>

		<section class="ob-pro-page__section" aria-labelledby="ob-pro-cases-title">
			<h2 class="ob-pro-page__section-title" id="ob-pro-cases-title"><i data-lucide="search"></i><?php esc_html_e( 'What you\'ll find out', 'opti-behavior' ); ?></h2>
			<ol class="ob-pro-cases">
				<?php foreach ( (array) $opti_behavior_pp['cases'] as $opti_behavior_pp_case ) : ?>
				<li class="ob-pro-case"><?php echo esc_html( $opti_behavior_pp_case ); ?></li>
				<?php endforeach; ?>
			</ol>
		</section>

		<section class="ob-pro-page__section" aria-labelledby="ob-pro-compare-title">
			<h2 class="ob-pro-page__section-title" id="ob-pro-compare-title"><i data-lucide="columns-2"></i><?php esc_html_e( 'Free and Pro', 'opti-behavior' ); ?></h2>
			<table class="ob-pro-compare">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Feature', 'opti-behavior' ); ?></th>
						<th scope="col" class="ob-pro-compare__plan"><?php esc_html_e( 'Free', 'opti-behavior' ); ?></th>
						<th scope="col" class="ob-pro-compare__plan is-pro"><?php esc_html_e( 'Pro', 'opti-behavior' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $opti_behavior_pp['compare'] as $opti_behavior_pp_row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $opti_behavior_pp_row[0] ); ?></th>
						<?php foreach ( array( 1 => 'ob-pro-compare__plan', 2 => 'ob-pro-compare__plan is-pro' ) as $opti_behavior_pp_col => $opti_behavior_pp_class ) : ?>
						<td class="<?php echo esc_attr( $opti_behavior_pp_class ); ?>">
							<?php if ( ! empty( $opti_behavior_pp_row[ $opti_behavior_pp_col ] ) ) : ?>
							<span class="ob-pro-compare__yes" aria-hidden="true">&#10003;</span><span class="screen-reader-text"><?php esc_html_e( 'Included', 'opti-behavior' ); ?></span>
							<?php else : ?>
							<span class="ob-pro-compare__no" aria-hidden="true">&ndash;</span><span class="screen-reader-text"><?php esc_html_e( 'Not included', 'opti-behavior' ); ?></span>
							<?php endif; ?>
						</td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<section class="ob-pro-page__section" aria-labelledby="ob-pro-faq-title">
			<h2 class="ob-pro-page__section-title" id="ob-pro-faq-title"><i data-lucide="circle-help"></i><?php esc_html_e( 'Questions', 'opti-behavior' ); ?></h2>
			<div class="ob-pro-faq">
				<?php foreach ( $opti_behavior_pp['faq'] as $opti_behavior_pp_faq ) : ?>
				<details class="ob-pro-faq__item">
					<summary><?php echo esc_html( $opti_behavior_pp_faq[0] ); ?></summary>
					<p><?php echo esc_html( $opti_behavior_pp_faq[1] ); ?></p>
				</details>
				<?php endforeach; ?>
			</div>
		</section>
	</div>
</div>
