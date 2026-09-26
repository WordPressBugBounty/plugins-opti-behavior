<?php
/**
 * Smart Insights shared page rules.
 *
 * One definition of "high bounce" and "low scroll" for a page, read by the
 * Insights cards (Basic Bounce Alert, Low Scroll Depth Page) AND the Page
 * X-Ray dossier, so the two screens can never disagree on the same page and
 * the same period (they used different thresholds: 100 visits + a site
 * baseline in Insights, 20 visits and an absolute limit in X-Ray).
 *
 * Pure: numbers in, verdict out.
 *
 * @package OptiBehavior
 * @since   1.9.1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_Smart_Insights_Page_Rules {

	/**
	 * Visits a page needs before its behaviour is judged (the observation
	 * gate of the card ranking uses the same 30).
	 */
	const MIN_VISITS = 30;

	/**
	 * Bounce: >= BOUNCE_ABSOLUTE %, or more than BOUNCE_GAP points above the
	 * site average when the site average is known.
	 */
	const BOUNCE_ABSOLUTE = 70;
	const BOUNCE_GAP      = 20;

	/**
	 * Scroll: under SCROLL_ABSOLUTE % AND more than SCROLL_GAP points under the
	 * site average; under SCROLL_ABSOLUTE % alone when no site average exists.
	 */
	const SCROLL_ABSOLUTE = 35;
	const SCROLL_GAP      = 20;

	/**
	 * Whether a number is usable.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function known( $value ) {
		return null !== $value && '' !== $value && is_numeric( $value );
	}

	/**
	 * High bounce verdict.
	 *
	 * @param int        $visits    Page visits.
	 * @param float|null $rate      Page bounce rate (0-100).
	 * @param float|null $site_rate Site bounce rate (0-100), null when unknown.
	 * @param int        $min       Minimum visits (defaults to MIN_VISITS).
	 * @return array { triggered, reason, gap, fallback }
	 */
	public static function bounce( $visits, $rate, $site_rate, $min = self::MIN_VISITS ) {
		$gap = self::known( $rate ) && self::known( $site_rate ) ? round( (float) $rate - (float) $site_rate, 2 ) : null;
		$out = array(
			'triggered' => false,
			'reason'    => '',
			'gap'       => $gap,
			'fallback'  => false,
		);
		if ( (int) $visits < (int) $min ) {
			$out['reason'] = 'insufficient_page_sessions';
		} elseif ( ! self::known( $rate ) ) {
			$out['reason'] = 'bounce_rate_unavailable';
		} elseif ( null !== $gap && $gap > self::BOUNCE_GAP ) {
			$out['triggered'] = true;
			$out['reason']    = 'site_baseline_rule';
		} elseif ( (float) $rate >= self::BOUNCE_ABSOLUTE ) {
			$out['triggered'] = true;
			$out['fallback']  = true;
			$out['reason']    = 'baseline_unavailable_or_absolute_rule';
		} else {
			$out['reason'] = 'bounce_rate_not_high_enough';
		}

		return $out;
	}

	/**
	 * Low scroll verdict.
	 *
	 * @param int        $visits      Page visits.
	 * @param float|null $scroll      Page average scroll depth (0-100).
	 * @param float|null $site_scroll Site average scroll depth, null when unknown.
	 * @param int        $min         Minimum visits (defaults to MIN_VISITS).
	 * @return array { triggered, reason, gap, fallback }
	 */
	public static function scroll( $visits, $scroll, $site_scroll, $min = self::MIN_VISITS ) {
		$known_site = self::known( $site_scroll ) && (float) $site_scroll > 0;
		$gap        = self::known( $scroll ) && $known_site ? round( (float) $scroll - (float) $site_scroll, 2 ) : null;
		$out        = array(
			'triggered' => false,
			'reason'    => '',
			'gap'       => $gap,
			'fallback'  => false,
		);
		if ( (int) $visits < (int) $min ) {
			$out['reason'] = 'insufficient_page_sessions';
			return $out;
		}
		if ( ! self::known( $scroll ) || (float) $scroll <= 0 ) {
			$out['reason'] = 'scroll_depth_unavailable';
			return $out;
		}
		$absolute = (float) $scroll < self::SCROLL_ABSOLUTE;
		$relative = null !== $gap && $gap < -self::SCROLL_GAP;
		if ( $absolute && $relative ) {
			$out['triggered'] = true;
			$out['reason']    = 'primary_rule';
		} elseif ( $absolute && ! $known_site ) {
			$out['triggered'] = true;
			$out['fallback']  = true;
			$out['reason']    = 'baseline_unavailable_fallback';
		} else {
			$out['reason'] = 'scroll_depth_not_low_enough';
		}

		return $out;
	}
}
