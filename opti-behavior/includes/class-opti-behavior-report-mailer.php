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

		// Generate email content
		$subject = $this->get_subject( $report, $schedule );
		$html_body = $this->render_html( $report, $schedule );
		$text_body = $this->render_text( $report, $schedule );

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

			$sent = false;

			if ( 'smtp' === $email_method ) {
				// Send via custom SMTP
				$smtp_result = self::send_via_smtp( $email, $subject, $html_body, $from_name, $from_email, $options, true );
				$sent = ( true === $smtp_result );
				if ( ! $sent ) {
					/* translators: 1: email address, 2: error message */
					$errors[] = sprintf( __( 'SMTP error for %1$s: %2$s', 'opti-behavior' ), $email, $smtp_result );
				}
			} else {
				// Send via wp_mail
				add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
				$sent = wp_mail( $email, $subject, $html_body, $headers );
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
	 * Send email via custom SMTP using WordPress bundled PHPMailer.
	 *
	 * @param string $to_email   Recipient email.
	 * @param string $subject    Email subject.
	 * @param string $body       Email body (HTML or plain text).
	 * @param string $from_name  Sender name.
	 * @param string $from_email Sender email.
	 * @param array  $options    Plugin options containing SMTP config.
	 * @param bool   $is_html    Whether body is HTML.
	 * @return true|string True on success, error message on failure.
	 */
	public static function send_via_smtp( $to_email, $subject, $body, $from_name, $from_email, $options, $is_html = false ) {
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
		$subject = sprintf(
			/* translators: 1: site name, 2: report name, 3: period */
			__( '[%1$s] %2$s - %3$s', 'opti-behavior' ),
			$report['site_name'],
			$schedule['name'],
			$report['period_label']
		);

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
    <title>' . esc_html( $schedule['name'] ) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f4f4f5;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">
                                ' . esc_html( $schedule['name'] ) . '
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0; font-size: 14px;">
                                ' . esc_html( $report['period_label'] ) . ' | ' . esc_html( $report['site_name'] ) . '
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
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>';

			$kpi_items = array(
				'visitors'         => array( 'label' => __( 'Visitors', 'opti-behavior' ), 'icon' => '&#128100;' ),
				'sessions'         => array( 'label' => __( 'Sessions', 'opti-behavior' ), 'icon' => '&#128203;' ),
				'pageviews'        => array( 'label' => __( 'Page Views', 'opti-behavior' ), 'icon' => '&#128196;' ),
				'bounce_rate'      => array( 'label' => __( 'Bounce Rate', 'opti-behavior' ), 'icon' => '&#8617;', 'suffix' => '%' ),
				'avg_session_time' => array( 'label' => __( 'Avg. Time', 'opti-behavior' ), 'icon' => '&#9201;', 'formatted' => true ),
				'avg_scroll_depth' => array( 'label' => __( 'Scroll Depth', 'opti-behavior' ), 'icon' => '&#8595;', 'suffix' => '%' ),
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
					$change_text = '<span style="color: ' . $color . '; font-size: 12px;">' . $arrow . ' ' . $change['value'] . '%</span>';
				}

				if ( 3 === $count ) {
					$html .= '</tr><tr>';
				}

				$html .= '
                                    <td width="33%" style="padding: 10px; text-align: center; vertical-align: top;">
                                        <div style="background: #f9fafb; border-radius: 8px; padding: 15px;">
                                            <div style="font-size: 11px; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">
                                                ' . $config['icon'] . ' ' . esc_html( $config['label'] ) . '
                                            </div>
                                            <div style="font-size: 24px; font-weight: 700; color: #18181b;">
                                                ' . esc_html( $value ) . '
                                            </div>
                                            ' . $change_text . '
                                        </div>
                                    </td>';
				$count++;
			}

			$html .= '
                                </tr>
                            </table>
                        </td>
                    </tr>';
		}

		// Top Pages Section
		if ( ! empty( $report['sections']['top_pages'] ) ) {
			$html .= '
                    <!-- Top Pages -->
                    <tr>
                        <td style="padding: 0 30px 30px;">
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">&#128200; ' . esc_html__( 'Top Pages', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Page', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Views', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Avg. Time', 'opti-behavior' ) . '</th>
                                </tr>';

			foreach ( array_slice( $report['sections']['top_pages'], 0, 5 ) as $page ) {
				$html .= '
                                <tr>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; font-size: 14px; color: #18181b;">
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
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">&#128279; ' . esc_html__( 'Top Referrers', 'opti-behavior' ) . '</h2>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Source', 'opti-behavior' ) . '</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; color: #71717a; text-transform: uppercase;">' . esc_html__( 'Visits', 'opti-behavior' ) . '</th>
                                </tr>';

			foreach ( array_slice( $report['sections']['top_referrers'], 0, 5 ) as $ref ) {
				$html .= '
                                <tr>
                                    <td style="padding: 12px; border-top: 1px solid #e4e4e7; font-size: 14px; color: #18181b;">
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
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">&#10024; ' . esc_html__( 'Smart Insights', 'opti-behavior' ) . '</h2>
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
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">&#9888; ' . esc_html__( 'JavaScript Errors', 'opti-behavior' ) . '</h2>
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
                            <h2 style="margin: 0 0 15px; font-size: 18px; color: #18181b;">&#9889; ' . esc_html__( 'Web Vitals', 'opti-behavior' ) . '</h2>
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

		// Footer
		$html .= '
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f9fafb; padding: 20px 30px; text-align: center; border-top: 1px solid #e4e4e7;">
                            <p style="margin: 0 0 10px; font-size: 12px; color: #71717a;">
                                ' . esc_html__( 'This report was automatically generated by Opti-Behavior', 'opti-behavior' ) . '
                            </p>
                            <p style="margin: 0; font-size: 12px;">
                                <a href="' . esc_url( admin_url( 'admin.php?page=opti-behavior-settings&tab=reports' ) ) . '" style="color: #4f46e5; text-decoration: none;">
                                    ' . esc_html__( 'Manage report settings', 'opti-behavior' ) . '
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
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
						$change = ' (' . $arrow . $kpis[ $key ]['change']['value'] . '%)';
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

		$text .= str_repeat( '=', 50 ) . "\n";
		$text .= __( 'Generated by Opti-Behavior', 'opti-behavior' ) . "\n";
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
