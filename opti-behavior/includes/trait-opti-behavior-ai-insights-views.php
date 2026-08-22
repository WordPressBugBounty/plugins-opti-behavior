<?php
/**
 * Roadmap Views Trait
 *
 * Provides rendering methods for the Roadmap page.
 *
 * @package Opti_Behavior
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roadmap Views Trait
 *
 * Provides methods for rendering the Opti-Behavior roadmap page.
 *
 * @since 1.0.0
 */
trait Opti_Behavior_AI_Insights_Views_Trait {

	/**
	 * Render Roadmap page.
	 *
	 * @since 1.0.0
	 */
	public function render_ai_insights() {
		?>
		<div class="wrap opti-behavior-ai-insights-page">
			<?php $this->render_pro_trial_banner(); ?>
			<?php $this->render_ai_insights_header(); ?>
			<?php if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); } ?>
			<?php $this->render_ai_insights_content(); ?>
		</div>
		<?php
	}

	/**
	 * Render Roadmap header.
	 *
	 * @since 1.0.0
	 */
	private function render_ai_insights_header() {
		?>
		<div class="dashboard-header roadmap-dashboard-header">
			<div class="dashboard-title-section">
				<div class="dashboard-icon" aria-hidden="true"><i data-lucide="map"></i></div>
				<div class="dashboard-title-text">
					<h1 class="dashboard-title"><?php esc_html_e( 'Roadmap', 'opti-behavior' ); ?></h1>
					<div class="dashboard-subtitle">
						<span class="subtitle-text"><?php esc_html_e( 'Validated product direction for Opti-Behavior', 'opti-behavior' ); ?></span>
					</div>
				</div>
			</div>
			<div class="dashboard-controls roadmap-header-actions">
				<span class="roadmap-phase-badge"><?php esc_html_e( 'Phase 2 validated', 'opti-behavior' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Roadmap content.
	 *
	 * @since 1.0.0
	 */
	private function render_ai_insights_content() {
		$support_url = 'https://optiuser.com/contact-support/';
		?>
		<div class="ai-insights-content">
			<!-- Hero Section -->
			<div class="ai-hero-section">
				<div class="ai-hero-icon">
					<svg width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
						<circle cx="60" cy="60" r="50" fill="url(#gradient1)" opacity="0.2"/>
						<circle cx="60" cy="60" r="40" fill="url(#gradient2)" opacity="0.3"/>
						<path d="M60 30 L70 50 L90 50 L75 65 L80 85 L60 72 L40 85 L45 65 L30 50 L50 50 Z" fill="url(#gradient3)"/>
						<defs>
							<linearGradient id="gradient1" x1="10" y1="10" x2="110" y2="110">
								<stop offset="0%" stop-color="#10b981"/>
								<stop offset="100%" stop-color="#3b82f6"/>
							</linearGradient>
							<linearGradient id="gradient2" x1="20" y1="20" x2="100" y2="100">
								<stop offset="0%" stop-color="#8b5cf6"/>
								<stop offset="100%" stop-color="#ec4899"/>
							</linearGradient>
							<linearGradient id="gradient3" x1="30" y1="30" x2="90" y2="90">
								<stop offset="0%" stop-color="#fbbf24"/>
								<stop offset="100%" stop-color="#f59e0b"/>
							</linearGradient>
						</defs>
					</svg>
				</div>
				<h2 class="ai-hero-title"><?php esc_html_e( 'Opti-Behavior Roadmap: AI Integration is the validated current phase', 'opti-behavior' ); ?></h2>
				<p class="ai-hero-description">
					<?php esc_html_e( 'This page tracks the planned evolution of Opti-Behavior. Phase 1 established the analytics foundation, and Phase 2 now validates AI-powered analysis that turns behavior data into clearer optimization guidance.', 'opti-behavior' ); ?>
				</p>
			</div>

			<!-- Features Grid -->
			<div class="ai-features-grid">
				<div class="ai-feature-card ai-feature-card--direction">
					<div class="feature-icon"><i data-lucide="target"></i></div>
					<h3 class="feature-title"><?php esc_html_e( 'Validated Direction', 'opti-behavior' ); ?></h3>
					<p class="feature-description"><?php esc_html_e( 'The roadmap highlights the capabilities currently being validated before they become broader product features.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ai-feature-card ai-feature-card--ai">
					<div class="feature-icon"><i data-lucide="brain-circuit"></i></div>
					<h3 class="feature-title"><?php esc_html_e( 'AI Integration', 'opti-behavior' ); ?></h3>
					<p class="feature-description"><?php esc_html_e( 'Phase 2 focuses on AI models that can identify behavior patterns, prioritize opportunities, and explain the signals behind each insight.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ai-feature-card ai-feature-card--safety">
					<div class="feature-icon"><i data-lucide="shield-check"></i></div>
					<h3 class="feature-title"><?php esc_html_e( 'Safe Rollout', 'opti-behavior' ); ?></h3>
					<p class="feature-description"><?php esc_html_e( 'New roadmap phases are validated against real analytics workflows before they are presented as production-ready capabilities.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ai-feature-card ai-feature-card--impact">
					<div class="feature-icon"><i data-lucide="trending-up"></i></div>
					<h3 class="feature-title"><?php esc_html_e( 'Optimization Impact', 'opti-behavior' ); ?></h3>
					<p class="feature-description"><?php esc_html_e( 'Future phases will focus on turning validated AI findings into stronger conversion, engagement, and revenue recommendations.', 'opti-behavior' ); ?></p>
				</div>
			</div>

			<!-- What We Analyze Section -->
			<div class="ai-analyze-section">
				<div class="analyze-section-heading">
					<div>
						<span class="analyze-kicker"><?php esc_html_e( 'Current validation workstream', 'opti-behavior' ); ?></span>
						<h2 class="section-title"><?php esc_html_e( 'Phase 2 validation focus', 'opti-behavior' ); ?></h2>
					</div>
					<span class="analyze-status"><i data-lucide="activity"></i><?php esc_html_e( 'Validated now', 'opti-behavior' ); ?></span>
				</div>
				<div class="analyze-grid">
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="bar-chart-3"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Traffic Patterns', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="shopping-cart"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Sales Funnels', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="clock"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Engagement Time', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="mouse-pointer-click"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Click Behavior', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="smartphone"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Device Performance', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="globe"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Geographic Trends', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="refresh-cw"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'User Journeys', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="circle-dollar-sign"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Revenue Impact', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="target"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Conversion Goals', 'opti-behavior' ); ?></span>
					</div>
					<div class="analyze-item">
						<span class="analyze-icon"><i data-lucide="users"></i></span>
						<span class="analyze-text"><?php esc_html_e( 'Audience Segments', 'opti-behavior' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Support Contact Section -->
			<div class="ai-feedback-section">
				<div class="feedback-card">
					<div class="feedback-icon"><i data-lucide="messages-square"></i></div>
					<h2 class="feedback-title"><?php esc_html_e( 'Questions about the roadmap?', 'opti-behavior' ); ?></h2>
					<p class="feedback-description">
						<?php esc_html_e( 'Use the OptiUser support page to ask questions, share feedback, or discuss roadmap priorities with the support team.', 'opti-behavior' ); ?>
					</p>
					<div class="feedback-actions">
						<a class="feedback-btn primary feedback-support-link" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">
							<span><i data-lucide="external-link"></i></span>
							<?php esc_html_e( 'Contact support', 'opti-behavior' ); ?>
						</a>
					</div>
					<p class="feedback-note">
						<?php esc_html_e( 'The support form now opens directly on optiuser.com.', 'opti-behavior' ); ?>
					</p>
				</div>
			</div>

			<!-- Timeline Section -->
			<div class="ai-timeline-section">
				<h2 class="section-title"><?php esc_html_e( 'Development Roadmap', 'opti-behavior' ); ?></h2>
				<div class="timeline">
					<div class="timeline-item completed">
						<div class="timeline-marker"><i data-lucide="check"></i></div>
						<div class="timeline-content">
							<span class="phase-status phase-status-completed"><?php esc_html_e( 'Completed', 'opti-behavior' ); ?></span>
							<h3><?php esc_html_e( 'Phase 1: Foundation', 'opti-behavior' ); ?></h3>
							<p><?php esc_html_e( 'Advanced analytics data collection and processing infrastructure is in place.', 'opti-behavior' ); ?></p>
						</div>
					</div>
					<div class="timeline-item active current" aria-current="step">
						<div class="timeline-marker"><i data-lucide="brain-circuit"></i></div>
						<div class="timeline-content">
							<span class="phase-status phase-status-current"><?php esc_html_e( 'Current validated phase', 'opti-behavior' ); ?></span>
							<h3><?php esc_html_e( 'Phase 2: AI Integration', 'opti-behavior' ); ?></h3>
							<p><?php esc_html_e( 'Machine learning models for pattern recognition, insight explanation, and behavior signal prioritization are now being validated.', 'opti-behavior' ); ?></p>
						</div>
					</div>
					<div class="timeline-item">
						<div class="timeline-marker"><i data-lucide="clock-3"></i></div>
						<div class="timeline-content">
							<span class="phase-status phase-status-planned"><?php esc_html_e( 'Planned', 'opti-behavior' ); ?></span>
							<h3><?php esc_html_e( 'Phase 3: Auto-Optimization', 'opti-behavior' ); ?></h3>
							<p><?php esc_html_e( 'Automated fixes and intelligent recommendation workflows will follow after AI validation.', 'opti-behavior' ); ?></p>
						</div>
					</div>
					<div class="timeline-item">
						<div class="timeline-marker"><i data-lucide="rocket"></i></div>
						<div class="timeline-content">
							<span class="phase-status phase-status-planned"><?php esc_html_e( 'Planned', 'opti-behavior' ); ?></span>
							<h3><?php esc_html_e( 'Phase 4: Launch', 'opti-behavior' ); ?></h3>
							<p><?php esc_html_e( 'A full AI-powered analytics suite with predictive capabilities remains the launch target.', 'opti-behavior' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

}

