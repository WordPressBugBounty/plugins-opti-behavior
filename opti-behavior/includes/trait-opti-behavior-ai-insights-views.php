<?php
/**
 * "How it works" Views Trait (menu slug opti-behavior-ai-insights, formerly Roadmap).
 *
 * Onboarding page for a user who just installed the plugin and never saw the
 * sales site: what the plugin does (the seven collectors feeding Smart
 * Insights, animated), the four-step loop, the first days, Free / Pro modules,
 * what's new, the roadmap and support. Every figure comes from the site itself
 * (Opti_Behavior_Smart_Insights_Sensors::get_summary(), the same numbers as the
 * Smart Insights "How it works" strip) and the menu severity.
 *
 * @package Opti_Behavior
 * @version 1.9.1.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "How it works" Views Trait.
 *
 * @since 1.0.0
 */
trait Opti_Behavior_AI_Insights_Views_Trait {

	/**
	 * Render the "How it works" page.
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
	 * Render the shared dashboard header.
	 *
	 * @since 1.0.0
	 */
	private function render_ai_insights_header() {
		?>
		<div class="dashboard-header roadmap-dashboard-header">
			<div class="dashboard-title-section">
				<div class="dashboard-icon" aria-hidden="true"><i data-lucide="compass"></i></div>
				<div class="dashboard-title-text">
					<h1 class="dashboard-title"><?php esc_html_e( 'How it works', 'opti-behavior' ); ?></h1>
					<div class="dashboard-subtitle">
						<span class="subtitle-text"><?php esc_html_e( 'Start here: understand Opti-Behavior in two minutes', 'opti-behavior' ); ?></span>
					</div>
				</div>
			</div>
			<div class="dashboard-controls roadmap-header-actions">
				<a class="roadmap-phase-badge" href="#ob-hiw-news" style="text-decoration:none">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version, e.g. 1.9.1.6. */
							__( 'What’s new in %s', 'opti-behavior' ),
							OPTI_BEHAVIOR_HEATMAP_VERSION
						)
					);
					?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Everything the page shows, read once: sensor rows, loop counts, severity.
	 *
	 * @return array
	 */
	private function get_how_it_works_data() {
		$data = array(
			'sensors'     => array(),
			'open'        => 0,
			'in_progress' => 0,
			'resolved'    => 0,
			'confirmed'   => 0,
			'severity'    => array(
				'level'    => 'green',
				'critical' => 0,
				'warning'  => 0,
			),
		);

		if ( class_exists( 'Opti_Behavior_Smart_Insights_Sensors' ) ) {
			try {
				$summary = Opti_Behavior_Smart_Insights_Sensors::get_summary();
				foreach ( array( 'sensors', 'open', 'in_progress', 'resolved', 'confirmed' ) as $key ) {
					if ( isset( $summary[ $key ] ) ) {
						$data[ $key ] = $summary[ $key ];
					}
				}
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		if ( method_exists( $this, 'get_smart_insights_menu_severity_impl' ) ) {
			$data['severity'] = $this->get_smart_insights_menu_severity_impl();
		}

		return $data;
	}

	/**
	 * Latest release notes, read from the plugin's readme.txt changelog.
	 *
	 * Takes the first `= x.y.z - date =` block under `== Changelog ==`: every
	 * `* **Label:** text` bullet becomes one item (label kept apart so it can be
	 * shown as a translated chip), wrapped continuation lines are joined, other
	 * lines are kept as notes. Markdown is flattened (`**`, backticks). Nothing
	 * to maintain on release: writing the changelog updates the page.
	 *
	 * @return array{version:string,date:string,items:array,notes:array}
	 */
	private function get_how_it_works_news() {
		static $news = null;
		if ( null !== $news ) {
			return $news;
		}

		$news = array(
			'version' => '',
			'date'    => '',
			'items'   => array(),
			'notes'   => array(),
		);

		$file = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'readme.txt';
		if ( ! is_readable( $file ) ) {
			return $news;
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $lines ) ) {
			return $news;
		}

		$flatten  = function ( $text ) {
			return trim( preg_replace( '/\s+/', ' ', str_replace( array( '**', '`' ), '', (string) $text ) ) );
		};
		$in_log   = false;
		$in_block = false;
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( ! $in_log ) {
				$in_log = (bool) preg_match( '/^==\s*Changelog\s*==$/i', $line );
				continue;
			}
			if ( preg_match( '/^==\s*[^=]/', $line ) ) {
				break; // Next readme section.
			}
			if ( preg_match( '/^=\s*([0-9][0-9.]*)\s*(?:-\s*(.+?))?\s*=$/', $line, $m ) ) {
				if ( $in_block ) {
					break; // Only the latest release.
				}
				$in_block        = true;
				$news['version'] = $m[1];
				$news['date']    = isset( $m[2] ) ? trim( $m[2] ) : '';
				continue;
			}
			if ( ! $in_block || '' === trim( $line ) ) {
				continue;
			}
			if ( preg_match( '/^\*\s+(?:\*\*([^*]+?):?\*\*:?\s*)?(.*)$/', $line, $m ) ) {
				$news['items'][] = array(
					'label' => trim( (string) $m[1], " :\t" ),
					'text'  => $flatten( $m[2] ),
				);
			} elseif ( ! empty( $news['items'] ) && preg_match( '/^\s+\S/', $line ) ) {
				$last                              = count( $news['items'] ) - 1;
				$news['items'][ $last ]['text'] = $flatten( $news['items'][ $last ]['text'] . ' ' . $line );
			} else {
				$news['notes'][] = $flatten( $line );
			}
		}

		return $news;
	}

	/**
	 * Translated chip for a changelog label, with its colour class.
	 *
	 * @param string $label Label as written in the changelog ("New", "Fix"...).
	 * @return array{0:string,1:string} Text, CSS modifier.
	 */
	private function get_how_it_works_news_chip( $label ) {
		$key   = strtolower( $label );
		$chips = array(
			'new'         => array( __( 'New', 'opti-behavior' ), 'is-new' ),
			'feature'     => array( __( 'New', 'opti-behavior' ), 'is-new' ),
			'fix'         => array( __( 'Fix', 'opti-behavior' ), 'is-fix' ),
			'changed'     => array( __( 'Changed', 'opti-behavior' ), 'is-changed' ),
			'improved'    => array( __( 'Improved', 'opti-behavior' ), 'is-changed' ),
			'performance' => array( __( 'Performance', 'opti-behavior' ), 'is-perf' ),
			'security'    => array( __( 'Security', 'opti-behavior' ), 'is-security' ),
			'privacy'     => array( __( 'Privacy', 'opti-behavior' ), 'is-security' ),
		);

		return isset( $chips[ $key ] ) ? $chips[ $key ] : array( $label, 'is-other' );
	}

	/**
	 * Lucide icon (inline SVG) for a collector.
	 *
	 * @param string $id Sensor id.
	 * @return string SVG markup (static, trusted).
	 */
	private function get_how_it_works_icon( $id ) {
		$paths = array(
			'analytics'  => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/>',
			'heatmaps'   => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
			'recordings' => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
			'forms'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/>',
			'funnels'    => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
			'errors'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'journeys'   => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
			'bulb'       => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
			'check'      => '<path d="M20 6 9 17l-5-5"/>',
			'play'       => '<polygon points="6 3 20 12 6 21 6 3"/>',
		);
		$size  = 'bulb' === $id ? 30 : 18;

		return '<svg aria-hidden="true" focusable="false" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. ( isset( $paths[ $id ] ) ? $paths[ $id ] : '' ) . '</svg>';
	}

	/**
	 * Render the page body.
	 *
	 * @since 1.0.0
	 */
	private function render_ai_insights_content() {
		$support_url = 'https://optiuser.com/contact-support/';
		$pro_url     = 'https://optiuser.com/pro';
		$data        = $this->get_how_it_works_data();
		$sensors     = $data['sensors'];
		$states      = array(
			'active'       => class_exists( 'Opti_Behavior_Smart_Insights_Sensors' ) ? Opti_Behavior_Smart_Insights_Sensors::STATE_ACTIVE : 'active',
			'locked'       => 'locked',
			'no_data'      => 'no_data',
			'needs_update' => 'needs_update',
		);
		$url         = function ( $slug, $extra = '' ) {
			return admin_url( 'admin.php?page=' . $slug . $extra );
		};
		// Links to optiuser.com: utm_source = this site's host only (no path, no
		// user data), so the optiuser.com dashboard lists which site sent the
		// visit in its "UTM source" filter; sent only when the admin clicks.
		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$utm       = function ( $target, $content ) use ( $site_host ) {
			return add_query_arg(
				array(
					'utm_source'   => rawurlencode( '' !== $site_host ? $site_host : 'unknown-site' ),
					'utm_medium'   => 'opti-behavior-plugin',
					'utm_campaign' => 'how-it-works',
					'utm_content'  => $content,
				),
				$target
			);
		};

		// Hub layout: position (percent of the 720 x 600 hub) and wire (same box, SVG units).
		$layout = array(
			'analytics'  => array( 21, 11.5, 'M160 70 C 230 110, 270 160, 305 190' ),
			'heatmaps'   => array( 50, 6.5, 'M360 42 L 360 140' ),
			'recordings' => array( 79, 11.5, 'M565 70 C 490 110, 450 160, 416 190' ),
			'forms'      => array( 15, 38.5, 'M120 230 L 262 232' ),
			'funnels'    => array( 85, 38.5, 'M600 230 L 458 232' ),
			'errors'     => array( 17, 65, 'M170 378 C 230 350, 272 322, 300 292' ),
			'journeys'   => array( 83, 65, 'M550 378 C 490 350, 448 322, 420 292' ),
		);
		$slugs   = class_exists( 'Opti_Behavior_Smart_Insights_Sensors' ) ? Opti_Behavior_Smart_Insights_Sensors::get_definitions() : array();

		$analytics    = isset( $sensors['analytics'] ) ? $sensors['analytics'] : array( 'state' => 'no_data', 'volume' => null );
		$sessions     = isset( $analytics['volume'] ) ? (int) $analytics['volume'] : 0;
		$tracking_ok  = $states['active'] === $analytics['state'] && $sessions > 0;
		$collecting   = 0;
		$any_locked   = false;
		foreach ( $sensors as $row ) {
			if ( $states['active'] === $row['state'] || $states['needs_update'] === $row['state'] ) {
				++$collecting;
			}
			if ( $states['locked'] === $row['state'] ) {
				$any_locked = true;
			}
		}
		$open        = (int) $data['open'];
		$in_progress = (int) $data['in_progress'];
		$resolved    = (int) $data['resolved'];
		$confirmed   = (int) $data['confirmed'];
		$severity    = $data['severity'];
		$funnels     = isset( $sensors['funnels']['volume'] ) ? (int) $sensors['funnels']['volume'] : 0;
		$heat_ready  = isset( $sensors['heatmaps']['state'] ) && $states['active'] === $sensors['heatmaps']['state'];

		// First days checklist: done / doing / todo, computed from the same data.
		$checklist = array(
			array(
				'state' => $tracking_ok ? 'done' : 'doing',
				'title' => __( 'First visitors recorded', 'opti-behavior' ),
				'text'  => $tracking_ok
					? sprintf(
						/* translators: %s: number of visitor sessions in the last 30 days. */
						_n( '%s session in the last 30 days.', '%s sessions in the last 30 days.', $sessions, 'opti-behavior' ),
						number_format_i18n( $sessions )
					)
					: __( 'Open your site in a private window: your visit shows up here within a minute.', 'opti-behavior' ),
				'link'  => array( $url( 'opti-behavior-analytics' ), __( 'See your traffic', 'opti-behavior' ) ),
			),
			array(
				'state' => $heat_ready ? 'done' : ( $tracking_ok ? 'doing' : 'todo' ),
				'title' => __( 'First heatmap ready', 'opti-behavior' ),
				'text'  => __( 'Every tracked page gets a click, scroll and attention map.', 'opti-behavior' ),
				'link'  => array( $url( 'opti-behavior-heatmaps' ), __( 'Open heatmaps', 'opti-behavior' ) ),
			),
			array(
				'state' => ( $open + $resolved ) > 0 ? 'done' : ( $tracking_ok ? 'doing' : 'todo' ),
				'title' => __( 'First Smart Insight', 'opti-behavior' ),
				'text'  => __( 'Usually after about 100 visits on a page.', 'opti-behavior' ),
				'link'  => array( $url( 'opti-behavior-smart-insights' ), __( 'Open Smart Insights', 'opti-behavior' ) ),
			),
			array(
				'state' => $funnels > 0 ? 'done' : 'todo',
				'title' => __( 'Track your key path as a funnel', 'opti-behavior' ),
				'text'  => __( 'The steps visitors take before they buy or sign up.', 'opti-behavior' ),
				'link'  => array( $url( 'opti-behavior-funnels' ), __( 'Create a funnel', 'opti-behavior' ) ),
			),
			array(
				'state' => $confirmed > 0 ? 'done' : ( ( $resolved + $in_progress ) > 0 ? 'doing' : 'todo' ),
				'title' => __( 'Fix one problem and prove it', 'opti-behavior' ),
				'text'  => __( 'Mark a problem as fixed: the plugin measures the result for you.', 'opti-behavior' ),
				'link'  => array( $url( 'opti-behavior-ab-testing' ), __( 'Open A/B Testing', 'opti-behavior' ) ),
			),
		);
		$done = count( wp_list_filter( $checklist, array( 'state' => 'done' ) ) );

		$level_class = array(
			'red'    => 'is-bad',
			'yellow' => 'is-warn',
			'green'  => 'is-good',
		);
		if ( 'red' === $severity['level'] ) {
			$understand = sprintf(
				/* translators: %d: number of open Critical Smart Insights. */
				_n( '%d critical problem to fix', '%d critical problems to fix', $severity['critical'], 'opti-behavior' ),
				$severity['critical']
			);
		} elseif ( 'yellow' === $severity['level'] || $open > 0 ) {
			// Same count as the menu lightbulb tooltip when it is yellow.
			$to_review  = 'yellow' === $severity['level'] ? (int) $severity['warning'] : $open;
			$understand = sprintf(
				/* translators: %d: number of open Smart Insights. */
				_n( '%d problem to review', '%d problems to review', $to_review, 'opti-behavior' ),
				$to_review
			);
		} else {
			$understand = __( 'No open problem', 'opti-behavior' );
		}
		?>
		<div class="ai-insights-content ob-hiw">

			<section class="ob-hiw-hero" aria-labelledby="ob-hiw-hero-title">
				<div>
					<?php if ( $tracking_ok ) : ?>
						<span class="ob-hiw-eyebrow"><i aria-hidden="true"></i><?php esc_html_e( 'Tracking is on · your data stays on your server', 'opti-behavior' ); ?></span>
					<?php else : ?>
						<span class="ob-hiw-eyebrow is-waiting"><i aria-hidden="true"></i><?php esc_html_e( 'Waiting for your first visitors · your data stays on your server', 'opti-behavior' ); ?></span>
					<?php endif; ?>
					<h2 id="ob-hiw-hero-title">
						<?php esc_html_e( 'See why visitors leave,', 'opti-behavior' ); ?>
						<em><?php esc_html_e( 'and what to fix first.', 'opti-behavior' ); ?></em>
					</h2>
					<p class="ob-hiw-lead"><?php esc_html_e( 'Seven collectors watch every visit on your own WordPress site. Smart Insights connects what they see, finds the problem that costs you the most, and tells you exactly what to change.', 'opti-behavior' ); ?></p>
					<div class="ob-hiw-cta">
						<a class="ob-hiw-btn ob-hiw-btn-primary" href="<?php echo esc_url( $url( 'opti-behavior-smart-insights' ) ); ?>">
							<?php echo $this->get_how_it_works_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<?php esc_html_e( 'Open Smart Insights', 'opti-behavior' ); ?>
						</a>
						<button type="button" class="ob-hiw-btn ob-hiw-btn-ghost" data-ob-hiw-replay>
							<?php echo $this->get_how_it_works_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<?php esc_html_e( 'Replay the animation', 'opti-behavior' ); ?>
						</button>
					</div>
					<div class="ob-hiw-trust">
						<span><?php echo $this->get_how_it_works_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'Runs on your server', 'opti-behavior' ); ?></span>
						<span><?php echo $this->get_how_it_works_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'No third-party tracking', 'opti-behavior' ); ?></span>
						<span><?php echo $this->get_how_it_works_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'GDPR consent built in', 'opti-behavior' ); ?></span>
					</div>
				</div>

				<div class="ob-hiw-hub-wrap">
					<div class="ob-hiw-hub" role="group" aria-labelledby="ob-hiw-hub-cap" data-ob-hiw-hub>
						<p id="ob-hiw-hub-cap" class="screen-reader-text"><?php esc_html_e( 'Your seven collectors send what they see to Smart Insights, which ranks the problems. Example, on a shop, a course, a blog or a download page: the main button is below the first screen; suggested fix: move it into the first screen; result after the fix: more visitors click it.', 'opti-behavior' ); ?></p>
						<svg class="ob-hiw-wires" viewBox="0 0 720 600" aria-hidden="true" focusable="false">
							<ellipse class="ob-hiw-orbit" cx="360" cy="235" rx="300" ry="195"/>
							<?php foreach ( $layout as $id => $pos ) : ?>
								<?php $off = ! isset( $sensors[ $id ] ) || in_array( $sensors[ $id ]['state'], array( $states['locked'], $states['no_data'] ), true ); ?>
								<path class="ob-hiw-wire<?php echo $off ? ' is-off' : ''; ?>" id="ob-hiw-w-<?php echo esc_attr( $id ); ?>" data-spark d="<?php echo esc_attr( $pos[2] ); ?>"/>
								<circle class="ob-hiw-spark" data-for="ob-hiw-w-<?php echo esc_attr( $id ); ?>" r="3.2" cx="0" cy="0"/>
							<?php endforeach; ?>
							<path class="ob-hiw-wire" d="M360 445 L 360 372"/>
						</svg>

						<?php foreach ( $layout as $id => $pos ) : ?>
							<?php
							if ( ! isset( $sensors[ $id ] ) ) {
								continue;
							}
							$row    = $sensors[ $id ];
							$locked = $states['locked'] === $row['state'];
							$slug   = isset( $slugs[ $id ]['slug'] ) ? $slugs[ $id ]['slug'] : 'opti-behavior-analytics';
							// "no insight yet" says when the first one comes (tooltip: the card keeps its size).
							$hint = ( isset( $row['insights'] ) && 0 === (int) $row['insights'] && $states['active'] === $row['state'] )
								? __( 'The first insight usually comes after about 100 visits on a page. Nothing to set up.', 'opti-behavior' )
								: '';
							?>
							<a class="ob-hiw-node is-<?php echo esc_attr( $row['state'] ); ?><?php echo '' !== $hint ? ' has-hint' : ''; ?>" href="<?php echo esc_url( $url( $slug ) ); ?>"<?php echo '' !== $hint ? ' title="' . esc_attr( $hint ) . '"' : ''; ?> style="--c:var(--c-<?php echo esc_attr( $id ); ?>);left:<?php echo esc_attr( $pos[0] ); ?>%;top:<?php echo esc_attr( $pos[1] ); ?>%">
								<span class="ob-hiw-ic"><?php echo $this->get_how_it_works_icon( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
								<span>
									<b>
										<?php echo esc_html( Opti_Behavior_Smart_Insights_Sensors::label( $id ) ); ?>
										<?php if ( $locked ) : ?>
											<em class="ob-hiw-lk">PRO</em>
										<?php endif; ?>
									</b>
									<small><?php echo esc_html( Opti_Behavior_Smart_Insights_Sensors::status_text( $id, $row ) ); ?></small>
								</span>
							</a>
						<?php endforeach; ?>

						<div class="ob-hiw-core" aria-hidden="true">
							<div class="ob-hiw-in">
								<span class="ob-hiw-bulb"><?php echo $this->get_how_it_works_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
								<h3>Smart Insights</h3>
								<p><?php esc_html_e( 'scores every signal, ranks what to fix first', 'opti-behavior' ); ?></p>
							</div>
						</div>
						<div class="ob-hiw-story" aria-hidden="true">
							<span class="ob-hiw-chip" data-k="problem" data-ob-hiw-chip>
								<?php
								/* translators: %s: label of the page's main button, e.g. "Add to cart". */
								echo esc_html( sprintf( __( 'Problem: “%s” is below the first screen', 'opti-behavior' ), __( 'Add to cart', 'opti-behavior' ) ) );
								?>
							</span>
						</div>
						<div class="ob-hiw-site" aria-hidden="true">
							<div class="ob-hiw-bar"><i></i><i></i><i></i><span><?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?><b data-ob-hiw-path>/shop/product</b></span></div>
							<div class="ob-hiw-body">
								<div class="ob-hiw-l1"></div><div class="ob-hiw-l2"></div><div class="ob-hiw-l3"></div>
								<div class="ob-hiw-fold"><?php esc_html_e( 'first screen ends here', 'opti-behavior' ); ?></div>
								<div class="ob-hiw-pay" data-ob-hiw-pay><?php esc_html_e( 'Add to cart', 'opti-behavior' ); ?></div>
							</div>
						</div>
						<div class="ob-hiw-src" aria-hidden="true">
							<span>Google</span><span><?php esc_html_e( 'Ads', 'opti-behavior' ); ?></span><span><?php esc_html_e( 'Social', 'opti-behavior' ); ?></span><span><?php esc_html_e( 'Email', 'opti-behavior' ); ?></span><span><?php esc_html_e( 'Direct', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="ob-hiw-hub-foot">
						<span><?php esc_html_e( 'Live numbers from your site. The page examples are illustrations.', 'opti-behavior' ); ?></span>
						<button type="button" data-ob-hiw-pause aria-pressed="false"><?php esc_html_e( 'Pause animation', 'opti-behavior' ); ?></button>
					</div>
				</div>
			</section>

			<?php
			if ( class_exists( 'Opti_Behavior_Setup_Guide' ) ) {
				Opti_Behavior_Setup_Guide::render_card();
			}
			?>

			<section class="ob-hiw-section" aria-labelledby="ob-hiw-steps-title">
				<span class="ob-hiw-kicker"><?php esc_html_e( 'The loop', 'opti-behavior' ); ?></span>
				<h2 class="ob-hiw-title" id="ob-hiw-steps-title"><?php esc_html_e( 'Four steps, then repeat', 'opti-behavior' ); ?></h2>
				<p class="ob-hiw-sub"><?php esc_html_e( 'You don’t need to read every report. Opti-Behavior does the watching; you make the decisions.', 'opti-behavior' ); ?></p>
				<ol class="ob-hiw-steps">
					<li class="ob-hiw-step ob-hiw-card" style="--sc:#2563eb">
						<span class="ob-hiw-n" aria-hidden="true">1</span>
						<h3><?php esc_html_e( 'Collect', 'opti-behavior' ); ?></h3>
						<p><?php esc_html_e( 'Every visit is recorded on your server: pages, clicks, scroll, forms, errors and paths.', 'opti-behavior' ); ?></p>
						<span class="ob-hiw-state <?php echo $collecting > 0 ? 'is-good' : ''; ?>"><i aria-hidden="true"></i>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of modules collecting data, 2: total number of modules. */
									__( '%1$d of %2$d modules collecting', 'opti-behavior' ),
									$collecting,
									count( $sensors )
								)
							);
							?>
						</span>
						<a class="ob-hiw-go" href="<?php echo esc_url( $url( 'opti-behavior-analytics' ) ); ?>"><?php esc_html_e( 'See your traffic', 'opti-behavior' ); ?> →</a>
					</li>
					<li class="ob-hiw-step ob-hiw-card" style="--sc:#7c3aed">
						<span class="ob-hiw-n" aria-hidden="true">2</span>
						<h3><?php esc_html_e( 'Understand', 'opti-behavior' ); ?></h3>
						<p><?php esc_html_e( 'Smart Insights compares pages, devices and sources, and ranks problems by the visits they cost.', 'opti-behavior' ); ?></p>
						<span class="ob-hiw-state <?php echo esc_attr( isset( $level_class[ $severity['level'] ] ) ? $level_class[ $severity['level'] ] : '' ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $understand ); ?></span>
						<a class="ob-hiw-go" href="<?php echo esc_url( $url( 'opti-behavior-smart-insights' ) ); ?>"><?php esc_html_e( 'Open Smart Insights', 'opti-behavior' ); ?> →</a>
					</li>
					<li class="ob-hiw-step ob-hiw-card" style="--sc:#d97706">
						<span class="ob-hiw-n" aria-hidden="true">3</span>
						<h3><?php esc_html_e( 'Fix', 'opti-behavior' ); ?></h3>
						<p><?php esc_html_e( 'Each problem comes with its cause, its page and one concrete action. Page X-Ray shows it on the page itself.', 'opti-behavior' ); ?></p>
						<span class="ob-hiw-state <?php echo $in_progress > 0 ? 'is-warn' : ''; ?>"><i aria-hidden="true"></i>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of Smart Insights marked "in progress". */
									_n( '%s fix in progress', '%s fixes in progress', $in_progress, 'opti-behavior' ),
									number_format_i18n( $in_progress )
								)
							);
							?>
						</span>
						<a class="ob-hiw-go" href="<?php echo esc_url( $url( 'opti-behavior-smart-insights', '&tab=pages' ) ); ?>"><?php esc_html_e( 'Open Page X-Ray', 'opti-behavior' ); ?> →</a>
					</li>
					<li class="ob-hiw-step ob-hiw-card" style="--sc:#16a34a">
						<span class="ob-hiw-n" aria-hidden="true">4</span>
						<h3><?php esc_html_e( 'Prove', 'opti-behavior' ); ?></h3>
						<p><?php esc_html_e( 'Mark a problem as fixed: the plugin measures before and after, or runs an A/B test, and tells you if it worked.', 'opti-behavior' ); ?></p>
						<span class="ob-hiw-state <?php echo $confirmed > 0 ? 'is-good' : ''; ?>"><i aria-hidden="true"></i>
							<?php
							echo esc_html(
								$confirmed > 0
									? sprintf(
										/* translators: %s: number of fixes measured as improved. */
										_n( '%s fix confirmed', '%s fixes confirmed', $confirmed, 'opti-behavior' ),
										number_format_i18n( $confirmed )
									)
									: __( 'Nothing measured yet', 'opti-behavior' )
							);
							?>
						</span>
						<a class="ob-hiw-go" href="<?php echo esc_url( $url( 'opti-behavior-ab-testing' ) ); ?>"><?php esc_html_e( 'Open A/B Testing', 'opti-behavior' ); ?> →</a>
					</li>
				</ol>
			</section>

			<div class="ob-hiw-two">
				<section class="ob-hiw-card" aria-labelledby="ob-hiw-days-title">
					<span class="ob-hiw-kicker"><?php esc_html_e( 'Your first days', 'opti-behavior' ); ?></span>
					<h2 class="ob-hiw-title" id="ob-hiw-days-title"><?php esc_html_e( 'What happens after installing', 'opti-behavior' ); ?></h2>
					<div class="ob-hiw-progress">
						<div class="ob-hiw-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( count( $checklist ) ); ?>" aria-valuenow="<?php echo esc_attr( $done ); ?>" aria-label="<?php esc_attr_e( 'Setup progress', 'opti-behavior' ); ?>">
							<div class="ob-hiw-fill" style="width:<?php echo esc_attr( (string) round( 100 * $done / count( $checklist ) ) ); ?>%"></div>
						</div>
						<b>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: steps done, 2: total steps. */
									__( '%1$d of %2$d done', 'opti-behavior' ),
									$done,
									count( $checklist )
								)
							);
							?>
						</b>
					</div>
					<ul class="ob-hiw-check">
						<?php
						$marks  = array(
							'done'  => '✓',
							'doing' => '…',
							'todo'  => '○',
						);
						$status = array(
							'done'  => __( 'Done', 'opti-behavior' ),
							'doing' => __( 'In progress', 'opti-behavior' ),
							'todo'  => __( 'To do', 'opti-behavior' ),
						);
						foreach ( $checklist as $item ) :
							?>
							<li class="is-<?php echo esc_attr( $item['state'] ); ?>">
								<span class="ob-hiw-mk" aria-hidden="true"><?php echo esc_html( $marks[ $item['state'] ] ); ?></span>
								<span><b><?php echo esc_html( $item['title'] ); ?> <span class="screen-reader-text">(<?php echo esc_html( $status[ $item['state'] ] ); ?>)</span></b><small><?php echo esc_html( $item['text'] ); ?></small></span>
								<?php if ( 'done' !== $item['state'] ) : ?>
									<a class="ob-hiw-go" href="<?php echo esc_url( $item['link'][0] ); ?>"><?php echo esc_html( $item['link'][1] ); ?></a>
								<?php else : ?>
									<span></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="ob-hiw-card" aria-labelledby="ob-hiw-mods-title">
					<span class="ob-hiw-kicker"><?php esc_html_e( 'What’s inside', 'opti-behavior' ); ?></span>
					<h2 class="ob-hiw-title" id="ob-hiw-mods-title"><?php esc_html_e( 'Each module answers one question', 'opti-behavior' ); ?></h2>
					<div class="ob-hiw-mods">
						<?php
						// free = fully free, mixed = free with Pro extras, pro = Pro only
						// (the Pro feature guard's protected features + the Pro funnel
						// autopilot / recipes). Keep in sync with Opti_Behavior_Pro_Feature_Guard::protected_features().
						$questions = array(
							'analytics'  => array( '--c-analytics', __( 'Who comes, and from where?', 'opti-behavior' ), 'free', '' ),
							'heatmaps'   => array( '--c-heatmaps', __( 'Where do they click and stop?', 'opti-behavior' ), 'mixed', __( 'Pro adds scroll, movement and attention maps.', 'opti-behavior' ) ),
							'funnels'    => array( '--c-funnels', __( 'Where do they leave the path?', 'opti-behavior' ), 'mixed', __( 'Pro adds instant history and funnels built from your real paths.', 'opti-behavior' ) ),
							'insights'   => array( '--c-forms', __( 'What should I fix first?', 'opti-behavior' ), 'mixed', __( 'Pro adds advanced signals from recordings, forms, errors and journeys.', 'opti-behavior' ) ),
							'recordings' => array( '--c-recordings', __( 'What exactly did they do?', 'opti-behavior' ), 'pro', '' ),
							'forms'      => array( '--c-forms', __( 'Which field makes them quit?', 'opti-behavior' ), 'pro', '' ),
							'errors'     => array( '--c-errors', __( 'What breaks for them?', 'opti-behavior' ), 'pro', '' ),
							'journeys'   => array( '--c-journeys', __( 'Which path leads to a sale?', 'opti-behavior' ), 'pro', '' ),
						);
						foreach ( $questions as $id => $q ) :
							$name = 'insights' === $id ? 'Smart Insights' : Opti_Behavior_Smart_Insights_Sensors::label( $id );
							?>
							<div class="ob-hiw-mod" style="--mc:var(<?php echo esc_attr( $q[0] ); ?>)">
								<span class="ob-hiw-dot" aria-hidden="true"></span>
								<span>
									<b>
										<?php echo esc_html( $name ); ?>
										<?php if ( 'pro' !== $q[2] ) : ?>
											<span class="ob-hiw-tag is-free"><?php esc_html_e( 'FREE', 'opti-behavior' ); ?></span>
										<?php endif; ?>
										<?php if ( 'free' !== $q[2] ) : ?>
											<span class="ob-hiw-tag is-pro"<?php echo '' !== $q[3] ? ' title="' . esc_attr( $q[3] ) . '"' : ''; ?>><?php echo 'mixed' === $q[2] ? '+ PRO' : 'PRO'; ?></span>
										<?php endif; ?>
										<?php if ( '' !== $q[3] ) : ?>
											<span class="screen-reader-text"><?php echo esc_html( $q[3] ); ?></span>
										<?php endif; ?>
									</b>
									<small><?php echo esc_html( $q[1] ); ?></small>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( $any_locked ) : ?>
						<p style="margin:14px 0 0"><a class="ob-hiw-btn ob-hiw-btn-ghost" href="<?php echo esc_url( $utm( $pro_url, 'see-what-pro-adds' ) ); ?>" target="_blank" rel="noopener" referrerpolicy="strict-origin-when-cross-origin"><?php esc_html_e( 'See what Pro adds', 'opti-behavior' ); ?> →<span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span></a></p>
					<?php endif; ?>
				</section>
			</div>

			<div class="ob-hiw-three">
				<section class="ob-hiw-card" id="ob-hiw-news" aria-labelledby="ob-hiw-news-title">
					<span class="ob-hiw-kicker">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: plugin version. */
								__( 'What’s new · %s', 'opti-behavior' ),
								'' !== $this->get_how_it_works_news()['version'] ? $this->get_how_it_works_news()['version'] : OPTI_BEHAVIOR_HEATMAP_VERSION
							)
						);
						?>
					</span>
					<h2 class="ob-hiw-title" id="ob-hiw-news-title"><?php esc_html_e( 'Latest improvements', 'opti-behavior' ); ?></h2>
					<?php
					// The latest release only, first items that fit: the card keeps the
					// height of its neighbours. Everything else lives in readme.txt.
					$news = $this->get_how_it_works_news();
					if ( empty( $news['items'] ) ) :
						?>
						<p class="ob-hiw-sub"><?php esc_html_e( 'The release notes are not available.', 'opti-behavior' ); ?></p>
					<?php else : ?>
						<ul class="ob-hiw-news">
							<?php foreach ( array_slice( $news['items'], 0, 3 ) as $item ) : ?>
								<?php $chip = $this->get_how_it_works_news_chip( $item['label'] ); ?>
								<li>
									<?php if ( '' !== $item['label'] ) : ?>
										<span class="ob-hiw-news-chip <?php echo esc_attr( $chip[1] ); ?>"><?php echo esc_html( $chip[0] ); ?></span>
									<?php endif; ?>
									<?php echo esc_html( $item['text'] ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php
					$changelog_url = $utm( 'https://optiuser.com/opti-behavior/changelog/', 'changelog-' . ( '' !== $news['version'] ? $news['version'] : OPTI_BEHAVIOR_HEATMAP_VERSION ) );
					?>
					<a class="ob-hiw-go ob-hiw-news-all" href="<?php echo esc_url( $changelog_url ); ?>" target="_blank" rel="noopener" referrerpolicy="strict-origin-when-cross-origin">
						<?php esc_html_e( 'View all changes', 'opti-behavior' ); ?> →<span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span>
					</a>
				</section>

				<section class="ob-hiw-card ai-timeline-section" aria-labelledby="ob-hiw-road-title">
					<span class="ob-hiw-kicker"><?php esc_html_e( 'Roadmap', 'opti-behavior' ); ?></span>
					<h2 class="ob-hiw-title" id="ob-hiw-road-title"><?php esc_html_e( 'Where we’re going', 'opti-behavior' ); ?></h2>
					<?php
					$phases = array(
						array( 'completed', __( 'Phase 1: Foundation', 'opti-behavior' ), __( 'Completed', 'opti-behavior' ), __( 'Heatmaps, recordings, funnels and analytics, collected on your own server.', 'opti-behavior' ) ),
						array( 'completed', __( 'Phase 2: AI Integration', 'opti-behavior' ), __( 'Completed', 'opti-behavior' ), __( 'Smart Insights finds the problems and ranks them by the visits they cost.', 'opti-behavior' ) ),
						array( 'active current', __( 'Phase 3: Suggested Fixes', 'opti-behavior' ), __( 'In progress', 'opti-behavior' ), __( 'A concrete fix for each problem, applied only after you approve it.', 'opti-behavior' ) ),
						array( '', __( 'Phase 4: Launch', 'opti-behavior' ), __( 'Planned', 'opti-behavior' ), __( 'Predictions that warn you before a page starts losing visitors.', 'opti-behavior' ) ),
					);
					?>
					<ol class="timeline-list">
						<?php foreach ( $phases as $phase ) : ?>
							<li class="timeline-item <?php echo esc_attr( $phase[0] ); ?>"<?php echo false !== strpos( $phase[0], 'current' ) ? ' aria-current="step"' : ''; ?>>
								<span class="ob-hiw-m" aria-hidden="true"></span>
								<span>
									<b><?php echo esc_html( $phase[1] ); ?></b>
									<span class="ob-hiw-phase-status"><?php echo esc_html( $phase[2] ); ?></span>
									<small><?php echo esc_html( $phase[3] ); ?></small>
								</span>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>

				<section class="ob-hiw-card ob-hiw-help" aria-labelledby="ob-hiw-help-title">
					<span class="ob-hiw-kicker"><?php esc_html_e( 'Need a hand?', 'opti-behavior' ); ?></span>
					<h2 class="ob-hiw-title" id="ob-hiw-help-title"><?php esc_html_e( 'Talk to a human', 'opti-behavior' ); ?></h2>
					<p class="ob-hiw-sub" style="margin-bottom:14px"><?php esc_html_e( 'Stuck on setup, or not sure what a result means? Our team is here to help.', 'opti-behavior' ); ?></p>
					<a class="ob-hiw-btn ob-hiw-btn-primary feedback-support-link" href="<?php echo esc_url( $utm( $support_url, 'contact-support' ) ); ?>" target="_blank" rel="noopener" referrerpolicy="strict-origin-when-cross-origin"><?php esc_html_e( 'Contact support', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span></a>
				</section>
			</div>
		</div>
		<?php
	}
}
