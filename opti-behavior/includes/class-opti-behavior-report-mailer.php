<?php
/**
 * Report Mailer Class
 *
 * Handles email delivery for scheduled reports including
 * template rendering and sending via wp_mail().
 *
 * @package opti-behavior
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report Mailer Class
 *
 * Sends scheduled report emails.
 *
 * @since 1.1.0
 */
class Opti_Behavior_Report_Mailer {

	/**
	 * "See what Pro adds" link of the Free footer line (OPT-1517): no site, address or id in it.
	 */
	const PRO_LINE_URL = 'https://optiuser.com/opti-behavior/pro-trial/?utm_source=report-email';

	/**
	 * Core instance.
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Send a report email.
	 *
	 * @param array $schedule Schedule data (includes recipients).
	 * @param array $report   Report data from generator.
	 * @return array Result with success status and counts.
	 */
	public function send( $schedule, $report ) {
		$recipients = $schedule['recipients'] ?? array();

		$result = array(
			'success'    => false,
			'partial'    => false,
			'sent_count' => 0,
			'total'      => count( $recipients ),
			'error'      => null,
		);

		if ( empty( $recipients ) ) {
			$result['error'] = __( 'No recipients specified', 'opti-behavior' );
			return $result;
		}

		// Generate email content (bodies are rendered per recipient: the footer names that address only).
		$subject = $this->get_subject( $report, $schedule );

		// Determine email method
		$options = $this->core->get_options();
		$email_method = isset( $options['email_method'] ) ? $options['email_method'] : 'wp_mail';

		// Get from info
		$from_name = ! empty( $options['reports_from_name'] ) ? $options['reports_from_name'] : get_bloginfo( 'name' );
		$from_email = ! empty( $options['reports_from_email'] ) ? $options['reports_from_email'] : get_option( 'admin_email' );

		// Get headers for wp_mail
		$headers = $this->get_headers();

		$errors = array();
		$sent_count = 0;

		foreach ( $recipients as $email ) {
			$email = sanitize_email( $email );
			if ( ! is_email( $email ) ) {
				/* translators: %s: email address */
			$errors[] = sprintf( __( 'Invalid email: %s', 'opti-behavior' ), $email );
				continue;
			}

			// One render per recipient (OPT-1517): the footer line names this address, never the others.
			$recipient_schedule              = $schedule;
			$recipient_schedule['recipient'] = $email;
			$html_body = $this->render_html( $report, $recipient_schedule );
			$text_body = self::plain_text_body( $this->render_text( $report, $recipient_schedule ) );

			$sent = false;

			if ( 'smtp' === $email_method ) {
				// Send via custom SMTP
				$smtp_result = self::send_via_smtp( $email, $subject, $html_body, $from_name, $from_email, $options, true, $text_body );
				$sent = ( true === $smtp_result );
				if ( ! $sent ) {
					/* translators: 1: email address, 2: error message */
					$errors[] = sprintf( __( 'SMTP error for %1$s: %2$s', 'opti-behavior' ), $email, $smtp_result );
				}
			} else {
				// Send via wp_mail, with the plain text part (multipart/alternative) for this send only.
				$alt = function ( $phpmailer ) use ( $text_body ) {
					if ( is_object( $phpmailer ) && '' !== $text_body ) {
						$phpmailer->AltBody = $text_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
					}
				};
				add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
				add_action( 'phpmailer_init', $alt );
				$sent = wp_mail( $email, $subject, $html_body, $headers );
				remove_action( 'phpmailer_init', $alt );
				remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

				if ( ! $sent ) {
					/* translators: %s: email address */
					$errors[] = sprintf( __( 'Failed to send to: %s', 'opti-behavior' ), $email );
				}
			}

			if ( $sent ) {
				$sent_count++;
			}
		}

		$result['sent_count'] = $sent_count;
		$result['success']    = ( $sent_count === count( $recipients ) );
		$result['partial']    = ( $sent_count > 0 && $sent_count < count( $recipients ) );
		$result['error']      = ! empty( $errors ) ? implode( '; ', $errors ) : null;

		return $result;
	}

	/**
	 * Sober Pro line above the footer, Free only (OPT-1517): '' when the report is Pro.
	 *
	 * @param array $report Report data.
	 * @return string Plain text (escape when printed).
	 */
	public static function pro_line( $report ) {
		if ( ! empty( $report['is_pro'] ) ) {
			return '';
		}
		return __( 'Recordings, form analytics and JavaScript errors are in Opti-Behavior Pro.', 'opti-behavior' );
	}

	/**
	 * One line of Pro figures of the period (OPT-1553): "Pro this period: 12
	 * recordings · 3 abandoned forms · 5 JavaScript errors". A figure the
	 * generator could not read is left out; none = ''.
	 *
	 * @param array $figures { recordings?, forms_abandoned?, js_errors? }.
	 * @return string Plain text (escape when printed).
	 */
	public static function pro_figures_line( $figures ) {
		$figures = is_array( $figures ) ? $figures : array();
		$parts   = array();
		if ( isset( $figures['recordings'] ) ) {
			$n = (int) $figures['recordings'];
			/* translators: %s: number of session recordings */
			$parts[] = sprintf( _n( '%s recording', '%s recordings', $n, 'opti-behavior' ), number_format_i18n( $n ) );
		}
		if ( isset( $figures['forms_abandoned'] ) ) {
			$n = (int) $figures['forms_abandoned'];
			/* translators: %s: number of abandoned forms */
			$parts[] = sprintf( _n( '%s abandoned form', '%s abandoned forms', $n, 'opti-behavior' ), number_format_i18n( $n ) );
		}
		if ( isset( $figures['js_errors'] ) ) {
			$n = (int) $figures['js_errors'];
			/* translators: %s: number of JavaScript errors */
			$parts[] = sprintf( _n( '%s JavaScript error', '%s JavaScript errors', $n, 'opti-behavior' ), number_format_i18n( $n ) );
		}
		if ( ! $parts ) {
			return '';
		}

		/* translators: %s: list of figures, e.g. "12 recordings · 3 abandoned forms · 5 JavaScript errors" */
		return sprintf( __( 'Pro this period: %s', 'opti-behavior' ), implode( ' · ', $parts ) );
	}

	/**
	 * "Why you receive this report" footer line of ONE recipient (OPT-1517).
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data; `recipient` = the address of this send (set by send()).
	 * @return string Plain text (escape when printed), '' without a valid recipient.
	 */
	public static function recipient_line( $report, $schedule ) {
		$email = isset( $schedule['recipient'] ) ? sanitize_email( (string) $schedule['recipient'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		$site = ! empty( $report['site_name'] ) ? (string) $report['site_name'] : (string) get_bloginfo( 'name' );
		$name = isset( $schedule['name'] ) ? (string) $schedule['name'] : '';
		return sprintf(
			/* translators: 1: site name, 2: recipient e-mail address, 3: report schedule name */
			__( 'You receive this report because %1$s added %2$s to “%3$s”. To stop it, ask the site administrator.', 'opti-behavior' ),
			wp_specialchars_decode( $site, ENT_QUOTES ),
			$email,
			$name
		);
	}

	/**
	 * Brand line of every HTML e-mail header: plugin icon on a white tile + "Opti-Behavior" in text (OPT-1503, design OPT-1501), "by OptiUser" below (OPT-1557).
	 *
	 * The icon is served by the plugin on this site (never another domain); the name stays readable when images are blocked.
	 *
	 * @param int $icon Icon size in px: 28 by default, 48 in the scheduled report header (design OPT-1519).
	 * @return string Table markup with inline styles, printed above the header title.
	 */
	public static function brand_header_html( $icon = 28 ) {
		$font = "font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;";
		$logo = defined( 'OPTI_BEHAVIOR_HEATMAP_ASSETS_URL' ) ? OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/report-icon.png' : plugins_url( 'assets/images/report-icon.png', dirname( __FILE__ ) );
		$icon = 48 === (int) $icon ? 48 : 28;
		$tile = 48 === $icon ? 64 : 40;
		$alt  = 48 === $icon ? 'font-size: 10px; line-height: 12px;' : 'font-size: 8px; line-height: 9px;';

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 0 auto 18px auto;"><tr>'
			. '<td class="ob-logo" bgcolor="#ffffff" width="' . $tile . '" height="' . $tile . '" align="center" valign="middle" style="background-color: #ffffff; width: ' . $tile . 'px; height: ' . $tile . 'px; border-radius: ' . ( 48 === $icon ? 14 : 10 ) . 'px;">'
			. '<img src="' . esc_url( $logo ) . '" width="' . $icon . '" height="' . $icon . '" alt="' . esc_attr__( 'Opti-Behavior', 'opti-behavior' ) . '" style="display: block; width: ' . $icon . 'px; height: ' . $icon . 'px; border: 0; outline: none; text-decoration: none; color: #4f46e5; ' . $alt . ' ' . $font . '" /></td>'
			. '<td valign="middle" style="padding-left: ' . ( 48 === $icon ? 12 : 10 ) . 'px; color: #ffffff; font-size: 16px; line-height: 20px; font-weight: 700; letter-spacing: 0.01em; ' . $font . '">' . esc_html__( 'Opti-Behavior', 'opti-behavior' )
			. '<br /><span style="font-size: 12px; line-height: 16px; font-weight: 500; letter-spacing: 0; color: #e0e7ff;">' . esc_html__( 'by OptiUser', 'opti-behavior' ) . '</span></td>'
			. '</tr></table>';
	}

	/**
	 * Brand line of every e-mail footer (OPT-1557): "Report made with Opti-Behavior by OptiUser · optiuser.com · Contact: optiuser.com/contact-support/",
	 * both links with the report UTM. Inline styles, printed inside a footer paragraph.
	 *
	 * @param string $campaign utm_campaign (email-report, shop-report).
	 * @param string $color    Link colour.
	 * @return string Escaped markup.
	 */
	public static function brand_footer_line( $campaign, $color = '#4f46e5' ) {
		$link = 'color: ' . esc_attr( $color ) . '; text-decoration: none; font-weight: 500;';

		return esc_html__( 'Report made with Opti-Behavior by OptiUser', 'opti-behavior' )
			. ' · <a href="' . esc_url( Opti_Behavior_Report::brand_url( $campaign ) ) . '" style="' . $link . '">optiuser.com</a>'
			. ' · ' . esc_html__( 'Contact:', 'opti-behavior' ) . ' <a href="' . esc_url( Opti_Behavior_Report::brand_url( $campaign, true ) ) . '" style="' . $link . '">optiuser.com/contact-support/</a>';
	}

	/**
	 * Send email via custom SMTP using WordPress bundled PHPMailer.
	 *
	 * @param string $to_email   Recipient email.
	 * @param string $subject    Email subject.
	 * @param string $body       Email body (HTML or plain text).
	 * @param string $from_name  Sender name.
	 * @param string $from_email Sender email.
	 * @param array  $options    Plugin options containing SMTP config.
	 * @param bool   $is_html    Whether body is HTML.
	 * @param string $alt_body   Plain text part of an HTML e-mail ('' = none).
	 * @return true|string True on success, error message on failure.
	 */
	public static function send_via_smtp( $to_email, $subject, $body, $from_name, $from_email, $options, $is_html = false, $alt_body = '' ) {
		// WordPress bundles PHPMailer since WP 5.5
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );

		try {
			$phpmailer->isSMTP();
			$phpmailer->Host       = ! empty( $options['smtp_host'] ) ? $options['smtp_host'] : 'localhost';
			$phpmailer->Port       = ! empty( $options['smtp_port'] ) ? (int) $options['smtp_port'] : 587;
			$phpmailer->SMTPAuth   = true;
			$phpmailer->Username   = ! empty( $options['smtp_username'] ) ? $options['smtp_username'] : '';
			$phpmailer->Password   = ! empty( $options['smtp_password'] ) ? $options['smtp_password'] : '';

			$encryption = ! empty( $options['smtp_encryption'] ) ? $options['smtp_encryption'] : 'tls';
			if ( 'tls' === $encryption ) {
				$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
			} elseif ( 'ssl' === $encryption ) {
				$phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
			} else {
				$phpmailer->SMTPSecure = '';
				$phpmailer->SMTPAutoTLS = false;
			}

			$phpmailer->setFrom( $from_email, $from_name );
			$phpmailer->addAddress( $to_email );
			$phpmailer->Subject = $subject;
			$phpmailer->CharSet = 'UTF-8';

			if ( $is_html ) {
				$phpmailer->isHTML( true );
				$phpmailer->Body = $body;
				if ( '' !== (string) $alt_body ) {
					$phpmailer->AltBody = (string) $alt_body;
				}
			} else {
				$phpmailer->Body = $body;
			}

			$phpmailer->send();
			return true;
		} catch ( \PHPMailer\PHPMailer\Exception $opti_behavior_err ) {
			return $opti_behavior_err->getMessage();
		}
	}

	/**
	 * Plain text part of the report e-mail (OPT-1512).
	 *
	 * The text template prints through esc_html(), so "&" or an apostrophe would arrive as "&amp;" / "&#039;"
	 * in a plain text body: decode the entities back to characters.
	 *
	 * @param string $text Rendered text template.
	 * @return string
	 */
	public static function plain_text_body( $text ) {
		return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . "\n";
	}

	/**
	 * Set HTML content type for wp_mail.
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Get email subject line.
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data.
	 * @return string
	 */
	private function get_subject( $report, $schedule ) {
		$subject = self::summary_subject( $report );

		// KPI section off or no visit in the period: the old subject (never "0 visits").
		if ( '' === $subject ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: report name, 3: period */
				__( '[%1$s] %2$s - %3$s', 'opti-behavior' ),
				$report['site_name'],
				$schedule['name'],
				$report['period_label']
			);
		}

		/**
		 * Filter the report email subject.
		 *
		 * @param string $subject  Email subject.
		 * @param array  $report   Report data.
		 * @param array  $schedule Schedule data.
		 */
		return apply_filters( 'opti_behavior_report_subject', $subject, $report, $schedule );
	}

	/**
	 * Subject that says what happened in the period (OPT-1513).
	 *
	 * With open Smart Insights: "[Site] 1,234 visits (+12% vs previous 7 days) · 3 issues to review".
	 * Without: "[Site] 1,234 visits (+12%) · Pricing was your top page".
	 *
	 * @param array $report Report data from the generator.
	 * @return string Plain text subject, '' when the KPI section is off or the period has no visit.
	 */
	public static function summary_subject( $report ) {
		$short = isset( $report['site'] ) && is_array( $report['site'] ) ? $report['site'] : null;
		if ( $short ) {
			// Short version of the PDF (OPT-1553): its visits, its change, its count of open issues.
			$sessions = array(
				'value'  => (int) ( $short['visits'] ?? 0 ),
				'change' => self::change_of_tile( $short['tiles'][0] ?? array() ),
			);
		} else {
			$sessions = $report['sections']['kpis']['sessions'] ?? null;
		}
		if ( ! is_array( $sessions ) ) {
			return '';
		}
		$count = (int) ( $sessions['value'] ?? 0 );
		if ( $count <= 0 ) {
			return '';
		}

		$site   = self::plain_text( $report['site_name'] ?? '' );
		$visits = sprintf(
			/* translators: %s: number of visits */
			_n( '%s visit', '%s visits', $count, 'opti-behavior' ),
			number_format_i18n( $count )
		);
		$change = self::subject_change( $sessions['change'] ?? array() );
		$issues = $short ? (int) ( $short['issues_total'] ?? 0 ) : (int) ( $report['sections']['smart_insights']['count'] ?? 0 );

		if ( $issues > 0 ) {
			$days = self::period_days( $report );
			if ( $days > 0 ) {
				$change = sprintf(
					/* translators: 1: change of the visits ("+12%", "-5%" or "new"), 2: number of days of the report period */
					_n( '%1$s vs previous %2$s day', '%1$s vs previous %2$s days', $days, 'opti-behavior' ),
					$change,
					number_format_i18n( $days )
				);
			}
			$tail = sprintf(
				/* translators: %s: number of open Smart Insights */
				_n( '%s issue to review', '%s issues to review', $issues, 'opti-behavior' ),
				number_format_i18n( $issues )
			);
		} else {
			$top  = self::top_page_label( $report );
			$tail = '' === $top ? '' : sprintf(
				/* translators: %s: title of the most viewed page, cut at 40 characters */
				__( '%s was your top page', 'opti-behavior' ),
				$top
			);
		}

		if ( '' === $tail ) {
			return sprintf(
				/* translators: e-mail subject. 1: site name, 2: "1,234 visits", 3: change of the visits ("+12%", "-5%" or "new") */
				__( '[%1$s] %2$s (%3$s)', 'opti-behavior' ),
				$site,
				$visits,
				$change
			);
		}

		return sprintf(
			/* translators: e-mail subject. 1: site name, 2: "1,234 visits", 3: change of the visits ("+12% vs previous 7 days"), 4: "3 issues to review" or "Pricing was your top page" */
			__( '[%1$s] %2$s (%3$s) · %4$s', 'opti-behavior' ),
			$site,
			$visits,
			$change,
			$tail
		);
	}

	/**
	 * Hidden preheader of the HTML e-mail (OPT-1513): the inbox preview line.
	 *
	 * @param array $report Report data from the generator.
	 * @return string Plain text, at most 140 characters; '' = print nothing.
	 */
	public static function preheader_text( $report ) {
		if ( isset( $report['site'] ) && is_array( $report['site'] ) ) {
			// Short version of the PDF (OPT-1553): what to do about its costliest issue.
			$first = $report['site']['issues'][0] ?? null;
			if ( ! is_array( $first ) ) {
				return '';
			}
			$text = self::plain_text( $first['action'] ?? '' );

			return self::cut( '' !== $text ? $text : self::plain_text( $first['headline'] ?? '' ), 140 );
		}
		$first = $report['sections']['smart_insights']['insights'][0] ?? null;
		if ( ! is_array( $first ) ) {
			return '';
		}
		$text = self::plain_text( $first['recommended_action'] ?? '' );
		if ( '' === $text ) {
			$text = self::plain_text( $first['interpretation'] ?? '' );
		}

		return self::cut( $text, 140 );
	}

	/**
	 * Change descriptor of a tile of the e-mail summary (OPT-1553): no change
	 * (too few visits before) = "new", like a previous period at 0.
	 *
	 * @param array $tile Tile { change: float|null }.
	 * @return array { value, direction }
	 */
	private static function change_of_tile( $tile ) {
		$value = is_array( $tile ) && isset( $tile['change'] ) && is_numeric( $tile['change'] ) ? (float) $tile['change'] : null;
		if ( null === $value ) {
			return array(
				'value'     => null,
				'direction' => 'new',
			);
		}

		return array(
			'value'     => abs( $value ),
			'direction' => $value > 0 ? 'up' : ( $value < 0 ? 'down' : 'neutral' ),
		);
	}

	/**
	 * "+12%", "-5%", "0%" or "new" for the visits change of the subject.
	 *
	 * @param array $change Change descriptor of the generator (value, direction).
	 * @return string
	 */
	private static function subject_change( $change ) {
		$direction = is_array( $change ) ? ( $change['direction'] ?? 'neutral' ) : 'neutral';
		if ( 'new' === $direction ) {
			return __( 'new', 'opti-behavior' );
		}
		$value = number_format_i18n( round( abs( (float) ( $change['value'] ?? 0 ) ) ) );
		if ( '0' === $value || 'neutral' === $direction ) {
			return '0%';
		}

		return ( 'down' === $direction ? '-' : '+' ) . $value . '%';
	}

	/**
	 * Number of days of the report period (both ends counted). Also the
	 * "vs previous n days" line under the key figures of both e-mail views (OPT-1514).
	 *
	 * @param array $report Report data.
	 * @return int 0 when unknown.
	 */
	public static function period_days( $report ) {
		// An empty date would read as "today" below.
		if ( empty( $report['period_start'] ) || empty( $report['period_end'] ) ) {
			return 0;
		}
		$start = strtotime( (string) ( $report['period_start'] ?? '' ) . ' 00:00:00 UTC' );
		$end   = strtotime( (string) ( $report['period_end'] ?? '' ) . ' 00:00:00 UTC' );
		if ( false === $start || false === $end || $end < $start ) {
			return 0;
		}

		return (int) round( ( $end - $start ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Longest "What it means / What to do" sentence under an e-mail block (OPT-1648).
	 */
	const HINT_MAX_CHARS = 140;

	/**
	 * Name inside a block sentence, cut like the block tables.
	 */
	const HINT_NAME_CHARS = 40;

	/**
	 * One sentence under an e-mail block, built ONLY from the figures that block
	 * prints (OPT-1648, style of design OPT-1645). Pure: no query, no option.
	 * No figure, figure 0 or under the threshold of the existing code = null.
	 *
	 * Rules and their source:
	 * - kpis: Sessions change >= Smart_Insights_Outcome_Evaluator::MIN_RELATIVE_CHANGE (10 %), previous period known.
	 * - top_pages: page 1 share of the Page Views tile >= Report_Site::SPLIT_LABEL_MIN (15 %), Report_Site::share().
	 * - heatmap: the Report_Signals heatmap sentence (same msgids) on the most clicked row.
	 * - funnels: the Report_Signals funnel sentence on the funnel with the lowest rate (entries > 0).
	 * - performance: the first vital over the "good" value of the block, its Performance screen tooltip.
	 * - errors / broken_links: unresolved / open > 0.
	 * - form_analytics: the listed form with the lowest conversion, under 50 % (most visitors leave).
	 * - ab_testing: the sentence of the Report_Signals A/B block (OPT-1650), from its own figures.
	 * - shop: the product losing the most visits (lost > 0), else failed payments > 0 (OPT-1735).
	 * Other blocks (Smart Insights cards carry their own action, referrers, traffic, countries,
	 * recordings, friction, journeys): null.
	 *
	 * @param string $key     Block key (section key of $report['sections']).
	 * @param array  $section The block data, as printed.
	 * @param array  $context { days: report days, pageviews: Page Views tile value }.
	 * @return array|null { kind: means|do, text }
	 */
	public static function block_hint( $key, $section, $context = array() ) {
		if ( ! is_array( $section ) || empty( $section ) ) {
			return null;
		}
		$context = is_array( $context ) ? $context : array();
		$text    = '';
		$kind    = 'do';

		switch ( (string) $key ) {
			case 'kpis':
				$change = isset( $section['sessions']['change'] ) && is_array( $section['sessions']['change'] ) ? $section['sessions']['change'] : array();
				$dir    = (string) ( $change['direction'] ?? '' );
				$value  = isset( $change['value'] ) && is_numeric( $change['value'] ) ? abs( (float) $change['value'] ) : 0.0;
				$days   = (int) ( $context['days'] ?? 0 );
				if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Outcome_Evaluator' ) || $days <= 0 || ! in_array( $dir, array( 'up', 'down' ), true ) ) {
					break;
				}
				if ( $value < 100 * Opti_Behavior_Smart_Insights_Outcome_Evaluator::MIN_RELATIVE_CHANGE ) {
					break;
				}
				$pct  = number_format_i18n( $value, ( floor( $value ) === $value ) ? 0 : 1 );
				$text = 'up' === $dir
					/* translators: 1: change in percent (number), 2: number of days of the report period. */
					? sprintf( _n( 'Visits up %1$s%% vs the previous %2$s day: check Top Referrers for the cause.', 'Visits up %1$s%% vs the previous %2$s days: check Top Referrers for the cause.', $days, 'opti-behavior' ), $pct, number_format_i18n( $days ) )
					/* translators: 1: change in percent (number), 2: number of days of the report period. */
					: sprintf( _n( 'Visits down %1$s%% vs the previous %2$s day: check Top Referrers for the cause.', 'Visits down %1$s%% vs the previous %2$s days: check Top Referrers for the cause.', $days, 'opti-behavior' ), $pct, number_format_i18n( $days ) );
				$kind = 'means';
				break;

			case 'top_pages':
				$first = isset( $section[0] ) && is_array( $section[0] ) ? $section[0] : array();
				$views = (int) ( $first['views'] ?? 0 );
				$total = (int) ( $context['pageviews'] ?? 0 );
				$name  = self::hint_name( $first['title'] ?? '' );
				if ( ! class_exists( 'Opti_Behavior_Report_Site' ) || $views <= 0 || $total <= 0 || '' === $name ) {
					break;
				}
				$share = Opti_Behavior_Report_Site::share( $views, $total );
				if ( $share < Opti_Behavior_Report_Site::SPLIT_LABEL_MIN ) {
					break;
				}
				/* translators: 1: share of page views in percent (number), 2: page title. */
				$text = sprintf( __( '%1$s%% of page views land on %2$s: it is your real home page.', 'opti-behavior' ), number_format_i18n( $share ), $name );
				$kind = 'means';
				break;

			case 'heatmap_summary':
				$top  = isset( $section['top_clicked'][0] ) && is_array( $section['top_clicked'][0] ) ? $section['top_clicked'][0] : array();
				$page = self::hint_name( $top['title'] ?? '' );
				$el   = self::hint_name( $top['element'] ?? '' );
				if ( (int) ( $section['total_clicks'] ?? 0 ) <= 0 || (int) ( $top['clicks'] ?? 0 ) <= 0 || '' === $page ) {
					break;
				}
				$text = '' !== $el
					/* translators: 1: clicked element, 2: page title. */
					? sprintf( __( 'Most clicks landed on %1$s on %2$s: check it is the action you expect.', 'opti-behavior' ), $el, $page )
					/* translators: %s: page title. */
					: sprintf( __( 'Most clicks landed on %s: open its heatmap to see what visitors click.', 'opti-behavior' ), $page );
				break;

			case 'funnels':
				$low = null;
				foreach ( (array) ( $section['funnels'] ?? array() ) as $f ) {
					if ( ! is_array( $f ) || (int) ( $f['total_entries'] ?? 0 ) <= 0 || '' === self::hint_name( $f['name'] ?? '' ) ) {
						continue;
					}
					if ( null === $low || (float) ( $f['conversion_rate'] ?? 0 ) < (float) ( $low['conversion_rate'] ?? 0 ) ) {
						$low = $f;
					}
				}
				if ( null === $low ) {
					break;
				}
				$rate = (float) ( $low['conversion_rate'] ?? 0 );
				/* translators: 1: funnel name, 2: completion rate (number). */
				$text = sprintf( __( '%1$s: %2$s %% complete it. Look at the step where most visitors stop.', 'opti-behavior' ), self::hint_name( $low['name'] ), number_format_i18n( $rate, ( floor( $rate ) === $rate ) ? 0 : 1 ) );
				break;

			case 'performance':
				// Same "good" values as the Web Vitals tiles of the e-mail; text = the Performance screen tooltip.
				$vitals = array(
					'lcp' => array( 2.5, __( 'Largest Contentful Paint — how long until the page\'s main content becomes visible. Good: under 2.5s.', 'opti-behavior' ) ),
					'inp' => array( 200, __( 'Interaction to Next Paint (INP; legacy FID as fallback) — how quickly the page reacts to clicks and typing. Good: 200ms or less.', 'opti-behavior' ) ),
					'cls' => array( 0.1, __( 'Cumulative Layout Shift — how much the page content jumps around while loading. Good: under 0.1.', 'opti-behavior' ) ),
				);
				foreach ( $vitals as $vk => $vital ) {
					if ( isset( $section[ $vk ] ) && is_numeric( $section[ $vk ] ) && (float) $section[ $vk ] > $vital[0] ) {
						$text = $vital[1];
						$kind = 'means';
						break;
					}
				}
				break;

			case 'errors':
				$open = (int) ( $section['unresolved'] ?? 0 );
				if ( $open > 0 ) {
					/* translators: %s: number of unresolved JavaScript errors. */
					$text = sprintf( _n( '%s error is still unresolved: open the Errors screen and fix the most frequent first.', '%s errors are still unresolved: open the Errors screen and fix the most frequent first.', $open, 'opti-behavior' ), number_format_i18n( $open ) );
				}
				break;

			case 'broken_links':
				$open = (int) ( $section['open'] ?? 0 );
				if ( $open > 0 ) {
					/* translators: %s: number of open broken links. */
					$text = sprintf( _n( '%s broken link is still open: fix it on its source page, listed on the Errors screen.', '%s broken links are still open: fix them on their source page, listed on the Errors screen.', $open, 'opti-behavior' ), number_format_i18n( $open ) );
				}
				break;

			case 'form_analytics':
				$low = null;
				foreach ( (array) ( $section['top_forms'] ?? array() ) as $form ) {
					if ( ! is_array( $form ) ) {
						continue;
					}
					// Same name as the table row: form name, else its id.
					$form['form_name'] = ! empty( $form['form_name'] ) ? $form['form_name'] : ( $form['form_id'] ?? '' );
					if ( (int) ( $form['form_views'] ?? 0 ) <= 0 || '' === self::hint_name( $form['form_name'] ) ) {
						continue;
					}
					if ( null === $low || (float) ( $form['conversion_rate'] ?? 0 ) < (float) ( $low['conversion_rate'] ?? 0 ) ) {
						$low = $form;
					}
				}
				if ( null === $low || (float) ( $low['conversion_rate'] ?? 0 ) >= 50 ) {
					break;
				}
				$rate = (float) ( $low['conversion_rate'] ?? 0 );
				/* translators: 1: form name, 2: share of form views that end in a submission (number). */
				$text = sprintf( __( '%1$s: %2$s%% of views end in a submission. Open Form Analytics to see which field stops visitors.', 'opti-behavior' ), self::hint_name( $low['form_name'] ), number_format_i18n( $rate, ( floor( $rate ) === $rate ) ? 0 : 1 ) );
				break;

			case 'shop':
				// Shop block (OPT-1735): product losing the most visits, else failed payments.
				$top    = isset( $section['products'][0] ) && is_array( $section['products'][0] ) ? $section['products'][0] : array();
				$name   = self::hint_name( $top['name'] ?? '' );
				$failed = (int) ( $section['totals']['failed'] ?? 0 );
				if ( (int) ( $top['lost'] ?? 0 ) > 0 && '' !== $name ) {
					/* translators: 1: product name, 2: product page views without an add to cart. */
					$text = sprintf( _n( '%1$s: %2$s product page view without an add to cart. Check its price, photos and button.', '%1$s: %2$s product page views without an add to cart. Check its price, photos and button.', (int) $top['lost'], 'opti-behavior' ), $name, number_format_i18n( (int) $top['lost'] ) );
				} elseif ( $failed > 0 ) {
					/* translators: %s: number of failed payments. */
					$text = sprintf( _n( '%s failed payment: open the Shop screen to see which payment method fails.', '%s failed payments: open the Shop screen to see which payment method fails.', $failed, 'opti-behavior' ), number_format_i18n( $failed ) );
				}
				break;

			case 'ab_testing':
				// The engine block (signal_block()): its sentence is built from its figures only.
				if ( 'ab_testing' === ( $section['id'] ?? '' ) && 'data' === ( $section['state'] ?? '' ) && ! empty( $section['figures'] ) ) {
					$text = (string) ( $section['sentence'] ?? '' );
				}
				break;
		}

		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		if ( mb_strlen( $text ) > self::HINT_MAX_CHARS ) {
			$text = mb_substr( $text, 0, self::HINT_MAX_CHARS - 1 ) . '…';
		}

		return array(
			'kind' => $kind,
			'text' => $text,
		);
	}

	/**
	 * One block of the report signals engine (OPT-1596), by id, as validated
	 * there: $report['site']['signals'] (generator get_site_summary()).
	 *
	 * @param array  $report Report data.
	 * @param string $id     Signal id (Report_Signals::ORDER).
	 * @return array|null
	 */
	public static function signal_block( $report, $id ) {
		$signals = isset( $report['site']['signals'] ) && is_array( $report['site']['signals'] ) ? $report['site']['signals'] : array();
		foreach ( $signals as $block ) {
			if ( is_array( $block ) && ( $block['id'] ?? '' ) === $id ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Label of a block sentence ("What it means:" / "What to do:").
	 *
	 * @param array $hint block_hint() answer.
	 * @return string
	 */
	public static function hint_label( $hint ) {
		return 'means' === ( $hint['kind'] ?? '' ) ? __( 'What it means:', 'opti-behavior' ) : __( 'What to do:', 'opti-behavior' );
	}

	/**
	 * HTML line of a block sentence, style `.ob-mean` of design OPT-1645 ('' = none).
	 *
	 * @param array|null $hint block_hint() answer.
	 * @return string
	 */
	public static function hint_html( $hint ) {
		if ( empty( $hint['text'] ) ) {
			return '';
		}
		return '<p class="ob-mean" style="margin: 14px 0 0 0; padding: 10px 14px; font-size: 13px; line-height: 1.5; color: #3f3f46; background-color: #f9fafb; border-left: 3px solid #4f46e5; border-radius: 0 8px 8px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Arial, sans-serif;"><strong style="color: #4338ca;">'
			. esc_html( self::hint_label( $hint ) ) . '</strong> ' . esc_html( $hint['text'] ) . '</p>';
	}

	/**
	 * Text line of a block sentence ('' = none).
	 *
	 * @param array|null $hint block_hint() answer.
	 * @return string
	 */
	public static function hint_text( $hint ) {
		if ( empty( $hint['text'] ) ) {
			return '';
		}
		return '  ' . self::hint_label( $hint ) . ' ' . $hint['text'] . "\n";
	}

	/**
	 * Name inside a block sentence: tags and line breaks removed, cut at HINT_NAME_CHARS.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private static function hint_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $name ) ) );
		return '' === $name ? '' : mb_strimwidth( $name, 0, self::HINT_NAME_CHARS, '...' );
	}

	/**
	 * Title of the most viewed page, cut at 40 characters ('' when none).
	 *
	 * @param array $report Report data.
	 * @return string
	 */
	private static function top_page_label( $report ) {
		if ( isset( $report['site']['pages'][0] ) && is_array( $report['site']['pages'][0] ) ) {
			return self::cut( self::plain_text( $report['site']['pages'][0]['title'] ?? '' ), 40 );
		}
		$row = $report['sections']['top_pages'][0] ?? null;
		if ( ! is_array( $row ) ) {
			return '';
		}
		$title = self::plain_text( $row['title'] ?? '' );
		if ( '' === $title ) {
			$title = self::plain_text( (string) wp_parse_url( (string) ( $row['url'] ?? '' ), PHP_URL_PATH ) );
		}

		return self::cut( $title, 40 );
	}

	/**
	 * Entities decoded, tags removed, spaces folded.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	private static function plain_text( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Cut a text at $max characters, "…" included.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…' : $text;
		}

		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Get email headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		$options = $this->core->get_options();

		$from_name = ! empty( $options['reports_from_name'] )
			? $options['reports_from_name']
			: get_bloginfo( 'name' );

		$from_email = ! empty( $options['reports_from_email'] )
			? $options['reports_from_email']
			: get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		/**
		 * Filter the report email headers.
		 *
		 * @param array $headers Email headers.
		 */
		return apply_filters( 'opti_behavior_report_email_headers', $headers );
	}

	/**
	 * Render HTML email template.
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data.
	 * @return string
	 */
	public function render_html( $report, $schedule ) {
		ob_start();

		// Try to load custom template first
		$template = locate_template( 'opti-behavior/emails/report-email-html.php' );
		if ( ! $template ) {
			$template = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'views/emails/report-email-html.php';
		}

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			// Fallback to inline template
			echo wp_kses_post( $this->get_fallback_html_template( $report, $schedule ) );
		}

		$html = ob_get_clean();

		/**
		 * Filter the report email HTML content.
		 *
		 * @param string $html     Email HTML.
		 * @param array  $report   Report data.
		 * @param array  $schedule Schedule data.
		 */
		return apply_filters( 'opti_behavior_report_content', $html, $report, $schedule );
	}

	/**
	 * Render plain text email template.
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data.
	 * @return string
	 */
	public function render_text( $report, $schedule ) {
		ob_start();

		$template = locate_template( 'opti-behavior/emails/report-email-text.php' );
		if ( ! $template ) {
			$template = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'views/emails/report-email-text.php';
		}

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo esc_html( $this->get_fallback_text_template( $report, $schedule ) );
		}

		return ob_get_clean();
	}

	/**
	 * Get fallback HTML template.
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data.
	 * @return string
	 */
	private function get_fallback_html_template( $report, $schedule ) {
		$kpis = $report['sections']['kpis'] ?? array();

		$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>' . esc_html( $schedule['name'] ) . '</title>
    <style type="text/css">
        @media only screen and (max-width: 620px) {
            .ob-card > tbody > tr > td { padding-left: 16px !important; padding-right: 16px !important; }
            .ob-kpi { width: 50% !important; }
        }
    </style>
    <style type="text/css">
        @media (prefers-color-scheme: dark) {
            .ob-bg, body { background-color: #09090b !important; }
            .ob-card { background-color: #18181b !important; }
            .ob-logo { background-color: #ffffff !important; }
            .ob-card [style*="background: #f9fafb"], .ob-card [style*="background: #faf5ff"] { background: #27272a !important; border-color: #3f3f46 !important; }
            .ob-card [style*="color: #18181b"], .ob-card [style*="color: #581c87"] { color: #fafafa !important; }
            .ob-card [style*="color: #3f3f46"], .ob-card [style*="color: #52525b"], .ob-card [style*="color: #71717a"], .ob-card [style*="color: #6b7280"] { color: #a1a1aa !important; }
            .ob-card [style*="border-top: 1px solid #e4e4e7"], .ob-card [style*="border: 1px solid #e4e4e7"] { border-color: #3f3f46 !important; }
        }
    </style>
</head>
<body class="ob-bg" style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f4f4f5;">
    <table class="ob-bg" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 20px 8px;">
        <tr>
            <td align="center">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table class="ob-card" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <!-- Header: site + period as the title, schedule name as a small line (design OPT-1519) -->
                    <tr>
                        <td bgcolor="#4f46e5" style="background-color: #4f46e5; background-image: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 30px; text-align: center;">
                            ' . self::brand_header_html( 48 ) . '
                            <h1 style="color: #ffffff; margin: 0 0 6px; font-size: 24px; line-height: 1.25; font-weight: 700; word-break: break-word;">
                                ' . esc_html( $report['site_name'] ) . '
                            </h1>
                            <p style="color: #e0e7ff; margin: 0; font-size: 15px; font-weight: 500;">
                                ' . esc_html( $report['period_label'] ) . '
                            </p>
                            <p style="color: #e0e7ff; margin: 10px 0 0; font-size: 13px; word-break: break-word;">
                                ' . esc_html( $schedule['name'] ) . '
                            </p>
                        </td>
                    </tr>';

		// KPIs Section
		if ( ! empty( $kpis ) ) {
			$html .= '
                    <!-- KPIs -->
                    <tr>
                        <td style="padding: 30px;">
                            <h2 style="margin: 0 0 20px; font-size: 18px; color: #18181b;">' . esc_html__( 'Key Metrics', 'opti-behavior' ) . '</h2>
                            <div style="font-size: 0; line-height: 0;">
                                <!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->';

			$kpi_items = array(
				'visitors'         => array( 'label' => __( 'Visitors', 'opti-behavior' ) ),
				'sessions'         => array( 'label' => __( 'Sessions', 'opti-behavior' ) ),
				'pageviews'        => array( 'label' => __( 'Page Views', 'opti-behavior' ) ),
				'bounce_rate'      => array( 'label' => __( 'Bounce Rate', 'opti-behavior' ), 'suffix' => '%' ),
				'avg_session_time' => array( 'label' => __( 'Avg. Time', 'opti-behavior' ), 'formatted' => true ),
				'avg_scroll_depth' => array( 'label' => __( 'Scroll Depth', 'opti-behavior' ), 'suffix' => '%' ),
			);

			$count = 0;
			foreach ( $kpi_items as $key => $config ) {
				if ( ! isset( $kpis[ $key ] ) ) {
					continue;
				}

				$kpi = $kpis[ $key ];
				$value = isset( $config['formatted'] ) && $config['formatted'] && isset( $kpi['formatted'] )
					? $kpi['formatted']
					: $kpi['value'] . ( $config['suffix'] ?? '' );

				$change = $kpi['change'] ?? array();
				$change_text = '';
				if ( ! empty( $change['value'] ) ) {
					$arrow = 'up' === $change['direction'] ? '&#9650;' : ( 'down' === $change['direction'] ? '&#9660;' : '' );
					$color = true === $change['positive'] ? '#16a34a' : ( false === $change['positive'] ? '#dc2626' : '#71717a' );
					$change_text = '<span style="color: ' . $color . '; font-size: 12px;">' . $arrow . ' ' . esc_html( Opti_Behavior_Report_Generator::change_amount_text( $change ) ) . '</span>';
				}

				// 3 tiles per row, 2 on a phone (design OPT-1519).
				if ( 3 === $count ) {
					$html .= '<!--[if mso]></tr><tr><![endif]-->';
				}

				$html .= '
                                    <!--[if mso]><td width="33%" valign="top"><![endif]-->
                                    <div class="ob-kpi" style="display: inline-block; width: 33.33%; box-sizing: border-box; padding: 6px; text-align: center; vertical-align: top; font-size: 14px; line-height: 1.4;">
                                        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 15px 8px;">
                                            <div style="font-size: 11px; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">
                                                ' . esc_html( $config['label'] ) . '
                                            </div>
                                            <div style="font-size: 24px; font-weight: 700; color: #18181b;">
                                                ' . esc_html( $value ) . '
                                            </div>
                                            ' . $change_text . '
                                        </div>
                                    </div>
                                    <!--[if mso]></td><![endif]-->';
				$count++;
			}

			$html .= '
                                <!--[if mso]></tr></table><![endif]-->
                            </div>
                        </td>
                    </tr>';
		}

		// Top Pages Section
		if ( ! empty( $report['sections']['top_pages'] ) ) {
			$html .= '
                    <!-- Top Pages -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">' . esc_html__( 'Top Pages', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Page', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Views', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Avg. Time', 'opti-behavior' ) . '</th>
                                </tr>';

			foreach ( array_slice( $report['sections']['top_pages'], 0, 5 ) as $page ) {
				$html .= '
                                <tr>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; font-size: 14px; color: #18181b; word-break: break-word;">
                                        ' . esc_html( mb_strimwidth( $page['title'], 0, 40, '...' ) ) . '
                                    </td>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; text-align: right; font-size: 14px; color: #18181b; font-weight: 600;">
                                        ' . number_format( $page['views'] ) . '
                                    </td>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; text-align: right; font-size: 14px; color: #71717a;">
                                        ' . esc_html( $page['avg_time_formatted'] ) . '
                                    </td>
                                </tr>';
			}

			$html .= '
                            </table>
                        </td>
                    </tr>';
		}

		// Top Referrers Section
		if ( ! empty( $report['sections']['top_referrers'] ) ) {
			$html .= '
                    <!-- Top Referrers -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">' . esc_html__( 'Top Referrers', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Source', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Visits', 'opti-behavior' ) . '</th>
                                </tr>';

			foreach ( array_slice( $report['sections']['top_referrers'], 0, 5 ) as $ref ) {
				$html .= '
                                <tr>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; font-size: 14px; color: #18181b; word-break: break-word;">
                                        ' . esc_html( $ref['source'] ) . '
                                    </td>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; text-align: right; font-size: 14px; color: #18181b; font-weight: 600;">
                                        ' . number_format( $ref['visits'] ) . '
                                    </td>
                                </tr>';
			}

			$html .= '
                            </table>
                        </td>
                    </tr>';
		}

		if ( ! empty( $report['sections']['smart_insights'] ) ) {
			$smart_insights = $report['sections']['smart_insights'];
			/* translators: %d: number of active Smart Insights detected for the report period. */
			$smart_insights_count_text = sprintf( __( '%d active insight(s) detected for this report period.', 'opti-behavior' ), (int) ( $smart_insights['count'] ?? 0 ) );
			$html .= '
                    <!-- Smart Insights -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">' . esc_html__( 'Smart Insights', 'opti-behavior' ) . '</h2>
                            <p style="margin: 0 0 12px; font-size: 13px; color: #52525b;">' . esc_html( $smart_insights_count_text ) . '</p>';

			if ( ! empty( $smart_insights['insights'] ) ) {
				foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $insight ) {
					$html .= '
                            <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; padding: 14px; margin-bottom: 10px;">
                                <div style="font-size: 14px; font-weight: 700; color: #581c87;">' . esc_html( $insight['title'] ?? __( 'Smart Insight', 'opti-behavior' ) ) . '</div>
                                <div style="font-size: 12px; color: #52525b; margin-top: 4px;">' . esc_html( $insight['entity_label'] ?? '' ) . '</div>
                                <div style="font-size: 12px; color: #52525b; margin-top: 6px;">' . esc_html__( 'Priority:', 'opti-behavior' ) . ' ' . esc_html( $insight['priority'] ?? __( 'Normal', 'opti-behavior' ) ) . '</div>';
					if ( ! empty( $insight['interpretation'] ) ) {
						$html .= '<div style="font-size: 13px; color: #3f3f46; margin-top: 8px;">' . esc_html( $insight['interpretation'] ) . '</div>';
					}
					if ( ! empty( $insight['recommended_action'] ) ) {
						$html .= '<div style="font-size: 12px; color: #166534; margin-top: 8px;">' . esc_html__( 'Next action:', 'opti-behavior' ) . ' ' . esc_html( $insight['recommended_action'] ) . '</div>';
					}
					$html .= '</div>';
				}
			} else {
				$html .= '<div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; font-size: 13px; color: #6b7280;">' . esc_html( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ) . '</div>';
			}

			$html .= '
                        </td>
                    </tr>';
		}

		// Pro Sections
		if ( $report['is_pro'] ) {
			// Errors Section
			if ( ! empty( $report['sections']['errors'] ) ) {
				$errors = $report['sections']['errors'];
				$html .= '
                    <!-- Errors -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">' . esc_html__( 'JavaScript Errors', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 15px; text-align: center;">
                                        <div style="font-size: 32px; font-weight: 700; color: #dc2626;">' . number_format( $errors['total'] ) . '</div>
                                        <div style="font-size: 12px; color: #991b1b;">' . sprintf(
											/* translators: %s: number of unresolved errors */
											esc_html__( 'Total Errors (%s unresolved)', 'opti-behavior' ),
											number_format( $errors['unresolved'] )
										) . '</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
			}

			// Performance Section
			if ( ! empty( $report['sections']['performance'] ) ) {
				$perf = $report['sections']['performance'];
				$html .= '
                    <!-- Performance -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">' . esc_html__( 'Web Vitals', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="25%" style="padding: 5px; text-align: center;">
                                        <div style="background: #f9fafb; border-radius: 8px; padding: 10px;">
                                            <div style="font-size: 10px; color: #71717a;">LCP</div>
                                            <div style="font-size: 18px; font-weight: 700; color: #18181b;">' . $perf['lcp'] . 's</div>
                                        </div>
                                    </td>
                                    <td width="25%" style="padding: 5px; text-align: center;">
                                        <div style="background: #f9fafb; border-radius: 8px; padding: 10px;">
                                            <div style="font-size: 10px; color: #71717a;">FID</div>
                                            <div style="font-size: 18px; font-weight: 700; color: #18181b;">' . $perf['fid'] . 'ms</div>
                                        </div>
                                    </td>
                                    <td width="25%" style="padding: 5px; text-align: center;">
                                        <div style="background: #f9fafb; border-radius: 8px; padding: 10px;">
                                            <div style="font-size: 10px; color: #71717a;">CLS</div>
                                            <div style="font-size: 18px; font-weight: 700; color: #18181b;">' . $perf['cls'] . '</div>
                                        </div>
                                    </td>
                                    <td width="25%" style="padding: 5px; text-align: center;">
                                        <div style="background: #f9fafb; border-radius: 8px; padding: 10px;">
                                            <div style="font-size: 10px; color: #71717a;">INP</div>
                                            <div style="font-size: 18px; font-weight: 700; color: #18181b;">' . $perf['inp'] . 'ms</div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
			}
		}

		// Pro line (Free only) + footer (OPT-1517).
		$pro_line = self::pro_line( $report );
		if ( '' !== $pro_line ) {
			$html .= '
                    <tr>
                        <td style="padding: 0 30px 20px; text-align: center; font-size: 13px; color: #71717a;">
                            ' . esc_html( $pro_line ) . ' <a href="' . esc_url( self::PRO_LINE_URL ) . '" style="color: #4f46e5; text-decoration: none;">' . esc_html__( 'See what Pro adds', 'opti-behavior' ) . '</a>
                        </td>
                    </tr>';
		}
		$recipient_line = self::recipient_line( $report, $schedule );
		$html .= '
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f9fafb; padding: 20px 30px; text-align: center; border-top: 1px solid #e4e4e7;">
                            <p style="margin: 0 0 10px; font-size: 12px; color: #71717a;">
                                ' . esc_html__( 'This report was automatically generated by Opti-Behavior', 'opti-behavior' ) . '
                            </p>' . ( '' !== $recipient_line ? '
                            <p style="margin: 0 0 10px; font-size: 12px; color: #71717a;">' . esc_html( $recipient_line ) . '</p>' : '' ) . '
                            <p style="margin: 0 0 10px; font-size: 12px; color: #71717a;">' . self::brand_footer_line( 'email-report' ) . '</p>
                            <p style="margin: 0; font-size: 12px;">
                                <a href="' . esc_url( admin_url( 'admin.php?page=opti-behavior-settings&tab=reports' ) ) . '" style="color: #4f46e5; text-decoration: none;">
                                    ' . esc_html__( 'Manage report settings', 'opti-behavior' ) . '
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>';

		return $html;
	}

	/**
	 * Get fallback plain text template.
	 *
	 * @param array $report   Report data.
	 * @param array $schedule Schedule data.
	 * @return string
	 */
	private function get_fallback_text_template( $report, $schedule ) {
		$text = $schedule['name'] . "\n";
		$text .= $report['period_label'] . ' | ' . $report['site_name'] . "\n";
		$text .= str_repeat( '=', 50 ) . "\n\n";

		// KPIs
		if ( ! empty( $report['sections']['kpis'] ) ) {
			$text .= strtoupper( __( 'Key Metrics', 'opti-behavior' ) ) . "\n";
			$text .= str_repeat( '-', 30 ) . "\n";

			$kpis = $report['sections']['kpis'];
			$kpi_labels = array(
				'visitors'         => __( 'Visitors', 'opti-behavior' ),
				'sessions'         => __( 'Sessions', 'opti-behavior' ),
				'pageviews'        => __( 'Page Views', 'opti-behavior' ),
				'bounce_rate'      => __( 'Bounce Rate', 'opti-behavior' ),
				'avg_session_time' => __( 'Avg. Session Time', 'opti-behavior' ),
				'avg_scroll_depth' => __( 'Avg. Scroll Depth', 'opti-behavior' ),
			);

			foreach ( $kpi_labels as $key => $label ) {
				if ( isset( $kpis[ $key ] ) ) {
					$value = isset( $kpis[ $key ]['formatted'] ) ? $kpis[ $key ]['formatted'] : $kpis[ $key ]['value'];
					$suffix = in_array( $key, array( 'bounce_rate', 'avg_scroll_depth' ), true ) ? '%' : '';
					$change = '';
					if ( ! empty( $kpis[ $key ]['change']['value'] ) ) {
						$arrow = 'up' === $kpis[ $key ]['change']['direction'] ? '+' : '-';
						$change = ' (' . $arrow . Opti_Behavior_Report_Generator::change_amount_text( $kpis[ $key ]['change'] ) . ')';
					}
					$text .= sprintf( "%-20s %s%s%s\n", $label . ':', $value, $suffix, $change );
				}
			}
			$text .= "\n";
		}

		// Top Pages
		if ( ! empty( $report['sections']['top_pages'] ) ) {
			$text .= strtoupper( __( 'Top Pages', 'opti-behavior' ) ) . "\n";
			$text .= str_repeat( '-', 30 ) . "\n";
			foreach ( array_slice( $report['sections']['top_pages'], 0, 5 ) as $i => $page ) {
				$text .= sprintf(
					/* translators: 1: rank number, 2: page title, 3: view count */
					__( '%1$d. %2$s - %3$s views', 'opti-behavior' ) . "\n",
					$i + 1,
					$page['title'],
					number_format( $page['views'] )
				);
			}
			$text .= "\n";
		}

		// Top Referrers
		if ( ! empty( $report['sections']['top_referrers'] ) ) {
			$text .= strtoupper( __( 'Top Referrers', 'opti-behavior' ) ) . "\n";
			$text .= str_repeat( '-', 30 ) . "\n";
			foreach ( array_slice( $report['sections']['top_referrers'], 0, 5 ) as $i => $ref ) {
				$text .= sprintf(
					/* translators: 1: rank number, 2: referrer source, 3: visit count */
					__( '%1$d. %2$s - %3$s visits', 'opti-behavior' ) . "\n",
					$i + 1,
					$ref['source'],
					number_format( $ref['visits'] )
				);
			}
			$text .= "\n";
		}

		if ( ! empty( $report['sections']['smart_insights'] ) ) {
			$smart_insights = $report['sections']['smart_insights'];
			$text .= strtoupper( __( 'Smart Insights', 'opti-behavior' ) ) . "\n";
			$text .= str_repeat( '-', 30 ) . "\n";
			$text .= sprintf(
				/* translators: %d: number of active insights */
				__( '%d active insight(s) detected for this report period.', 'opti-behavior' ) . "\n\n",
				(int) ( $smart_insights['count'] ?? 0 )
			);

			if ( ! empty( $smart_insights['insights'] ) ) {
				foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $i => $insight ) {
					$text .= sprintf( "%d. %s\n", $i + 1, $insight['title'] ?? __( 'Smart Insight', 'opti-behavior' ) );
					if ( ! empty( $insight['entity_label'] ) ) {
						/* translators: %s: entity label */
						$text .= sprintf( '   ' . __( 'Entity: %s', 'opti-behavior' ) . "\n", $insight['entity_label'] );
					}
					/* translators: %s: insight priority */
					$text .= sprintf( '   ' . __( 'Priority: %s', 'opti-behavior' ) . "\n", $insight['priority'] ?? __( 'Normal', 'opti-behavior' ) );
					if ( ! empty( $insight['interpretation'] ) ) {
						$text .= sprintf( "   %s\n", $insight['interpretation'] );
					}
					if ( ! empty( $insight['recommended_action'] ) ) {
						/* translators: %s: recommended action */
						$text .= sprintf( '   ' . __( 'Next action: %s', 'opti-behavior' ) . "\n", $insight['recommended_action'] );
					}
					$text .= "\n";
				}
			} else {
				$text .= ( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ) . "\n\n";
			}
		}

		$pro_line = self::pro_line( $report );
		if ( '' !== $pro_line ) {
			$text .= $pro_line . "\n" . __( 'See what Pro adds', 'opti-behavior' ) . ': ' . self::PRO_LINE_URL . "\n\n";
		}
		$text .= str_repeat( '=', 50 ) . "\n";
		$text .= __( 'Generated by Opti-Behavior', 'opti-behavior' ) . "\n";
		$recipient_line = self::recipient_line( $report, $schedule );
		if ( '' !== $recipient_line ) {
			$text .= $recipient_line . "\n";
		}
		$text .= Opti_Behavior_Report::brand_footer_text( 'email-report' ) . "\n";
		$text .= admin_url( 'admin.php?page=opti-behavior-settings&tab=reports' ) . "\n";

		return $text;
	}

	/**
	 * Send a test email to verify configuration.
	 *
	 * @param string $email    Email address.
	 * @param array  $schedule Schedule data.
	 * @return bool|WP_Error
	 */
	public function send_test( $email, $schedule = array() ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid email address', 'opti-behavior' ) );
		}

		// Create sample schedule if not provided
		if ( empty( $schedule ) ) {
			$schedule = array(
				'name'                      => __( 'Test Report', 'opti-behavior' ),
				'report_period'             => 'last7days',
				'include_kpis'              => 1,
				'include_top_pages'         => 1,
				'include_top_referrers'     => 1,
				'include_traffic_breakdown' => 1,
				'include_smart_insights'    => 1,
				'include_heatmap_summary'   => 1,
				'include_funnels'           => 1,
				'include_geographic'        => 1,
				'include_recordings_stats'  => 1,
				'include_errors'            => 1,
				'include_friction'          => 1,
				'include_performance'       => 1,
				'include_broken_links'      => 1,
				'include_user_journeys'     => 1,
				'include_form_analytics'    => 1,
				'is_test_report'            => 1,
			);
		} else {
			$schedule['is_test_report'] = 1;
		}

		// Generate report
		$generator = new Opti_Behavior_Report_Generator( $this->core, $schedule );
		$report = $generator->generate();

		// Add the test email to schedule recipients
		$schedule['recipients'] = array( $email );

		// Send
		$result = $this->send( $schedule, $report );

		if ( $result['success'] ) {
			return true;
		}

		return new WP_Error( 'send_failed', $result['error'] ?? __( 'Failed to send test email', 'opti-behavior' ) );
	}
}
