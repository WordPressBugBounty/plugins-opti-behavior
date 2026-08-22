<?php
/**
 * Heatmap Parser Class
 *
 * Parses rrweb session recording events to extract heatmap data.
 * Processes click, mouse movement, and scroll events from recordings.
 *
 * @package OptiBehaviorPro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Opti_Behavior_Heatmap_Parser', false ) ) {
	return;
}

/**
 * Class Opti_Behavior_Heatmap_Parser
 *
 * Extracts coordinate data from rrweb events for heatmap visualization.
 */
class Opti_Behavior_Heatmap_Parser {

	/**
	 * RRWeb event types
	 */
	const EVENT_TYPE_DOM_CONTENT_LOADED = 0;
	const EVENT_TYPE_LOAD               = 1;
	const EVENT_TYPE_FULL_SNAPSHOT      = 2;
	const EVENT_TYPE_INCREMENTAL        = 3;
	const EVENT_TYPE_META               = 4;
	const EVENT_TYPE_CUSTOM             = 5;
	const EVENT_TYPE_PLUGIN             = 6;

	/**
	 * RRWeb incremental source types
	 */
	const SOURCE_MUTATION          = 0;
	const SOURCE_MOUSE_MOVE        = 1;
	const SOURCE_MOUSE_INTERACTION = 2;
	const SOURCE_SCROLL            = 3;
	const SOURCE_VIEWPORT_RESIZE   = 4;
	const SOURCE_INPUT             = 5;
	const SOURCE_TOUCH_MOVE        = 6;

	/**
	 * Mouse interaction types
	 */
	const INTERACTION_MOUSE_UP      = 0;
	const INTERACTION_MOUSE_DOWN    = 1;
	const INTERACTION_CLICK         = 2;
	const INTERACTION_CONTEXT_MENU  = 3;
	const INTERACTION_DBL_CLICK     = 4;
	const INTERACTION_FOCUS         = 5;
	const INTERACTION_BLUR          = 6;
	const INTERACTION_TOUCH_START   = 7;
	const INTERACTION_TOUCH_END     = 8;

	/**
	 * Debug logging helper - only logs if debug mode is enabled.
	 *
	 * @param string $message Message to log.
	 * @param string $level Log level: 'debug', 'info', 'warning', 'error'.
	 * @return void
	 */
	private function debug_log( $message, $level = 'debug' ) {
		$core = Opti_Behavior_Heatmap_Core::get_instance();
		if ( $core ) {
			$debug_manager = $core->get_debug_manager();
			if ( $debug_manager ) {
				$debug_manager->log( $message, $level, 'heatmap-parser' );
			}
		}
	}

	/**
	 * Parse recording events to extract heatmap data.
	 *
	 * @param array  $events Array of rrweb events.
	 * @param string $type Heatmap type: 'click', 'move', 'scroll', or 'all'.
	 * @return array Parsed heatmap data with coordinates.
	 */
	public function parse_events( $events, $type = 'all' ) {
		if ( empty( $events ) || ! is_array( $events ) ) {
			return array(
				'clicks'  => array(),
				'moves'   => array(),
				'scrolls' => array(),
				'viewport' => array( 'width' => 1920, 'height' => 1080 ),
			);
		}

		// CRITICAL: Sort events by timestamp FIRST
		// rrweb events may not be in chronological order in the array
		// We need scroll events to be processed BEFORE mouse events that happen after them
		// This ensures current_scroll_x/y is updated before applying to mouse move coordinates
		usort( $events, function( $a, $b ) {
			$ts_a = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
			$ts_b = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
			return $ts_a - $ts_b;
		} );

		$clicks   = array();
		$moves    = array();
		$scrolls  = array();
		$viewport = array( 'width' => 1920, 'height' => 1080 );

		// Track viewport dimensions for normalization
		$current_viewport = $viewport;

		// Track current scroll position to convert rrweb viewport coordinates to document coordinates
		// rrweb stores clientX/clientY (viewport-relative) but classic heatmap uses pageX/pageY (document-relative)
		// pageX = clientX + scrollX, pageY = clientY + scrollY
		$current_scroll_x = 0;
		$current_scroll_y = 0;

		foreach ( $events as $event ) {
			// Skip invalid events.
			if ( ! isset( $event['type'] ) ) {
				continue;
			}

			// Extract viewport dimensions from meta events.
			if ( self::EVENT_TYPE_META === $event['type'] && isset( $event['data']['width'], $event['data']['height'] ) ) {
				$viewport = array(
					'width'  => (int) $event['data']['width'],
					'height' => (int) $event['data']['height'],
				);
				$current_viewport = $viewport; // Update current viewport for normalization
				continue;
			}

			// Parse custom click_metadata events (enhanced click tracking from session recorder)
			// These events are MORE ACCURATE than rrweb CLICK events because they filter out
			// accidental mouse interactions, drawings, and other non-click events.
			// Use ONLY these events for heatmap click counting (not rrweb MOUSE_INTERACTION clicks).
			if ( self::EVENT_TYPE_CUSTOM === $event['type'] ) {
				$data = isset( $event['data'] ) ? $event['data'] : array();
				$timestamp = isset( $event['timestamp'] ) ? $event['timestamp'] : 0;

				// Parse enhanced click metadata events with scroll offset for accurate positioning
				if ( ( 'click' === $type || 'all' === $type ) && isset( $data['tag'] ) && 'click_metadata' === $data['tag'] ) {
					$click_data = $this->parse_custom_click_event( $data, $timestamp, $current_viewport, $current_scroll_x, $current_scroll_y );
					if ( ! empty( $click_data ) ) {
						$clicks[] = $click_data;
					}
				}
				continue;
			}

			// Only process incremental snapshot events.
			if ( self::EVENT_TYPE_INCREMENTAL !== $event['type'] ) {
				continue;
			}

			$data = isset( $event['data'] ) ? $event['data'] : array();
			if ( empty( $data['source'] ) ) {
				continue;
			}

			$timestamp = isset( $event['timestamp'] ) ? $event['timestamp'] : 0;

			// DISABLED: rrweb CLICK events capture ALL mouse interactions including accidental clicks,
			// mouse drawings, and other non-click events. This causes over-counting (55 vs 42 clicks).
			// Instead, we use custom click_metadata events which accurately track only real user clicks.
			// if ( ( 'click' === $type || 'all' === $type ) && self::SOURCE_MOUSE_INTERACTION === $data['source'] ) {
			// 	$click_data = $this->parse_click_event( $data, $timestamp, $current_viewport, $current_scroll_x, $current_scroll_y );
			// 	if ( ! empty( $click_data ) ) {
			// 		$clicks[] = $click_data;
			// 	}
			// }

			// Parse mouse move events with scroll offset for document coordinates.
			if ( ( 'move' === $type || 'all' === $type ) && self::SOURCE_MOUSE_MOVE === $data['source'] ) {
				$move_data = $this->parse_move_event( $data, $timestamp, $current_scroll_x, $current_scroll_y );
				if ( ! empty( $move_data ) ) {
					$moves = array_merge( $moves, $move_data );
				}
			}

			// Parse touch move events (mobile) with scroll offset.
			if ( ( 'move' === $type || 'all' === $type ) && self::SOURCE_TOUCH_MOVE === $data['source'] ) {
				$touch_data = $this->parse_touch_move_event( $data, $timestamp, $current_scroll_x, $current_scroll_y );
				if ( ! empty( $touch_data ) ) {
					$moves = array_merge( $moves, $touch_data );
				}
			}

			// Parse scroll events and update current scroll position
			if ( self::SOURCE_SCROLL === $data['source'] ) {
				$scroll_data = $this->parse_scroll_event( $data, $timestamp );
				if ( ! empty( $scroll_data ) ) {
					// Update current scroll position for coordinate conversion
					$current_scroll_x = $scroll_data['x'];
					$current_scroll_y = $scroll_data['y'];

					if ( 'scroll' === $type || 'all' === $type ) {
						$scrolls[] = $scroll_data;
					}
				}
			}
		}

		return array(
			'clicks'   => $clicks,
			'moves'    => $moves,
			'scrolls'  => $scrolls,
			'viewport' => $viewport,
		);
	}

	/**
	 * Parse click event data.
	 *
	 * @param array $data Event data.
	 * @param int   $timestamp Event timestamp.
	 * @param array $viewport Viewport dimensions for normalization.
	 * @param int   $scroll_x Current horizontal scroll position.
	 * @param int   $scroll_y Current vertical scroll position.
	 * @return array|null Click coordinates or null.
	 */
	private function parse_click_event( $data, $timestamp, $viewport = array(), $scroll_x = 0, $scroll_y = 0 ) {
		// Check if it's a click or touch interaction.
		// NOTE: We exclude DBLCLICK to avoid double-counting since each double-click generates:
		// - 2 CLICK events (one for each click)
		// - 1 DBLCLICK event (redundant)
		// Counting DBLCLICK would triple-count double-clicks
		$is_click = isset( $data['type'] ) && (
			self::INTERACTION_CLICK === $data['type'] ||
			self::INTERACTION_TOUCH_END === $data['type']
		);

		if ( ! $is_click ) {
			return null;
		}

		if ( ! isset( $data['x'], $data['y'] ) ) {
			return null;
		}

		// Default viewport if not provided
		if ( empty( $viewport ) ) {
			$viewport = array( 'width' => 1920, 'height' => 1080 );
		}

		// CRITICAL FIX: rrweb stores viewport coordinates (clientX, clientY)
		// Classic heatmap uses document coordinates (pageX, pageY)
		// Convert: pageX = clientX + scrollX, pageY = clientY + scrollY
		$x_viewport = (int) $data['x'];
		$y_viewport = (int) $data['y'];

		// Add scroll offset to convert viewport coordinates to document coordinates
		$x = $x_viewport + (int) $scroll_x;
		$y = $y_viewport + (int) $scroll_y;

		$viewport_width = (int) $viewport['width'];
		$viewport_height = (int) $viewport['height'];

		// Return document coordinates WITHOUT normalization
		// The renderer will get actual document dimensions from the iframe and handle normalization
		return array(
			'x'              => $x,
			'y'              => $y,
			'viewport_width'  => $viewport_width,
			'viewport_height' => $viewport_height,
			'timestamp'      => $timestamp,
			'type'           => $data['type'],
		);
	}

	/**
	 * Parse custom click_metadata event (enhanced click tracking).
	 *
	 * @param array $data Event data.
	 * @param int   $timestamp Event timestamp.
	 * @return array|null Click coordinates or null.
	 */
	private function parse_custom_click_event( $data, $timestamp, $viewport = array(), $scroll_x = 0, $scroll_y = 0 ) {
		// Check if payload has coordinates
		if ( ! isset( $data['payload'] ) || ! is_array( $data['payload'] ) ) {
			return null;
		}

		$payload = $data['payload'];

		// Extract x, y coordinates from the click metadata
		if ( ! isset( $payload['x'], $payload['y'] ) ) {
			return null;
		}

		// CRITICAL: click_metadata events now use document coordinates (pageX, pageY)
		// to match the free heatmap tracker. They already include scroll offset,
		// so we DON'T need to add it again (that would double-count the scroll)
		$x = (int) $payload['x'];
		$y = (int) $payload['y'];

		// Default viewport if not provided
		if ( empty( $viewport ) ) {
			$viewport = array( 'width' => 1920, 'height' => 1080 );
		}

		$viewport_width = (int) $viewport['width'];
		$viewport_height = (int) $viewport['height'];

		return array(
			'x'              => $x,
			'y'              => $y,
			'viewport_width'  => $viewport_width,
			'viewport_height' => $viewport_height,
			'timestamp'      => $timestamp,
			'type'           => 'custom_click', // Mark as custom to differentiate
		);
	}

	/**
	 * Parse mouse move event data.
	 *
	 * @param array $data Event data.
	 * @param int   $timestamp Event timestamp.
	 * @return array Array of move coordinates.
	 */
	private function parse_move_event( $data, $timestamp, $scroll_x = 0, $scroll_y = 0 ) {
		$positions = isset( $data['positions'] ) ? $data['positions'] : array();
		if ( empty( $positions ) ) {
			return array();
		}

		$moves = array();
		foreach ( $positions as $pos ) {
			if ( isset( $pos['x'], $pos['y'] ) ) {
				// CRITICAL: rrweb stores viewport coordinates (clientX, clientY)
				// Convert to document coordinates: pageX = clientX + scrollX, pageY = clientY + scrollY
				$x_viewport = (int) $pos['x'];
				$y_viewport = (int) $pos['y'];

				$moves[] = array(
					'x'         => $x_viewport + (int) $scroll_x,
					'y'         => $y_viewport + (int) $scroll_y,
					'timestamp' => $timestamp + ( isset( $pos['timeOffset'] ) ? $pos['timeOffset'] : 0 ),
				);
			}
		}

		return $moves;
	}

	/**
	 * Parse touch move event data (mobile).
	 *
	 * @param array $data Event data.
	 * @param int   $timestamp Event timestamp.
	 * @return array Array of touch move coordinates.
	 */
	private function parse_touch_move_event( $data, $timestamp, $scroll_x = 0, $scroll_y = 0 ) {
		$positions = isset( $data['positions'] ) ? $data['positions'] : array();
		if ( empty( $positions ) ) {
			return array();
		}

		$moves = array();
		foreach ( $positions as $pos ) {
			if ( isset( $pos['x'], $pos['y'] ) ) {
				// CRITICAL: rrweb stores viewport coordinates (clientX, clientY)
				// Convert to document coordinates: pageX = clientX + scrollX, pageY = clientY + scrollY
				$x_viewport = (int) $pos['x'];
				$y_viewport = (int) $pos['y'];

				$moves[] = array(
					'x'         => $x_viewport + (int) $scroll_x,
					'y'         => $y_viewport + (int) $scroll_y,
					'timestamp' => $timestamp + ( isset( $pos['timeOffset'] ) ? $pos['timeOffset'] : 0 ),
				);
			}
		}

		return $moves;
	}

	/**
	 * Parse scroll event data.
	 *
	 * @param array $data Event data.
	 * @param int   $timestamp Event timestamp.
	 * @return array|null Scroll position or null.
	 */
	private function parse_scroll_event( $data, $timestamp ) {
		if ( ! isset( $data['x'], $data['y'] ) ) {
			return null;
		}

		// Check if coordinates are already normalized (0-1 range)
		// New recordings will have x_normalized/y_normalized fields
		$is_normalized = isset( $data['x_normalized'] ) && isset( $data['y_normalized'] );

		if ( $is_normalized ) {
			// Store normalized coordinates (0-1 range) along with absolute for backward compatibility
			return array(
				'x'            => (int) $data['x'],
				'y'            => (int) $data['y'],
				'x_normalized' => (float) $data['x_normalized'],
				'y_normalized' => (float) $data['y_normalized'],
				'page_width'   => isset( $data['page_width'] ) ? (int) $data['page_width'] : 0,
				'page_height'  => isset( $data['page_height'] ) ? (int) $data['page_height'] : 0,
				'timestamp'    => $timestamp,
			);
		} else {
			// Legacy data - absolute coordinates only
			return array(
				'x'         => (int) $data['x'],
				'y'         => (int) $data['y'],
				'timestamp' => $timestamp,
			);
		}
	}

	/**
	 * Aggregate parsed data into heatmap format.
	 *
	 * @param array $parsed_data Parsed event data.
	 * @param string $type Heatmap type.
	 * @param array $viewport Viewport dimensions with 'width' and 'height'.
	 * @return array Aggregated coordinates with intensity.
	 */
	public function aggregate_data( $parsed_data, $type = 'click', $viewport = array() ) {
		// Default viewport if not provided
		if ( empty( $viewport ) ) {
			$viewport = array( 'width' => 1920, 'height' => 1080 );
		}

		// Map type to correct data key
		$type_map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls', // Attention heatmap uses scroll/viewposition data to show dwell time zones
		);

		$data_key = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type . 's';

		if ( ! isset( $parsed_data[ $data_key ] ) ) {
			// Log for debugging
			$this->debug_log( '[Opti-Behavior-Heatmap] No data found for key: ' . $data_key . ' in aggregate_data' );
			$this->debug_log( '[Opti-Behavior-Heatmap] Available keys: ' . implode( ', ', array_keys( $parsed_data ) ) );
			return array();
		}

		$coordinates = $parsed_data[ $data_key ];
		if ( empty( $coordinates ) ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] Empty coordinates for type: ' . $type );
			return array();
		}

		// Special handling for scroll heatmap
		if ( $type === 'scroll' ) {
			$scroll_result = $this->aggregate_scroll_data( $coordinates, $viewport );
			// aggregate_scroll_data may return array with coordinates + dimensions, or just coordinates array
			return $scroll_result;
		}

		// Special handling for move heatmap - show trajectories
		if ( $type === 'move' ) {
			return $this->aggregate_move_data( $coordinates, $viewport );
		}

		// Special handling for attention heatmap - use scroll data with Gaussian distribution
		if ( $type === 'attention' ) {
			return $this->aggregate_scroll_data( $coordinates, $viewport );
		}

		// Group coordinates by raw document pixel positions (10px grid)
		// Now using document coordinates (with scroll offset), not viewport coordinates
		$grouped = array();
		foreach ( $coordinates as $coord ) {
			// Use raw pixel coordinates
			$x = isset( $coord['x'] ) ? (int) $coord['x'] : 0;
			$y = isset( $coord['y'] ) ? (int) $coord['y'] : 0;

			// Round to nearest 10px grid for grouping nearby clicks
			$grid_size = 10;
			$grid_x = round( $x / $grid_size ) * $grid_size;
			$grid_y = round( $y / $grid_size ) * $grid_size;
			$key = $grid_x . '_' . $grid_y;

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'x'         => $grid_x,
					'y'         => $grid_y,
					'intensity' => 0,
				);
			}

			++$grouped[ $key ]['intensity'];
		}

		// Convert to array and normalize intensity.
		$result = array_values( $grouped );
		if ( empty( $result ) ) {
			return array();
		}

		$max = max( array_column( $result, 'intensity' ) );

		if ( $max > 0 ) {
			foreach ( $result as &$point ) {
				$point['value'] = $point['intensity'];
				unset( $point['intensity'] );
			}
		}

		// Return aggregated data with raw document coordinates
		return $result;
	}

	/**
	 * Aggregate scroll data for scroll depth heatmap.
	 * Creates a gradient heatmap showing how far users scroll.
	 * Uses full-width horizontal bands for complete coverage.
	 * Supports normalized coordinates (0-1) for device-independent aggregation.
	 *
	 * @param array $scroll_data Scroll event data.
	 * @param array $viewport Viewport dimensions with 'width' and 'height'.
	 * @return array Aggregated scroll heatmap data.
	 */
	private function aggregate_scroll_data( $scroll_data, $viewport = array() ) {
		if ( empty( $scroll_data ) ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] Scroll aggregate: No scroll data provided' );
			return array();
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Scroll aggregate: Processing ' . count( $scroll_data ) . ' scroll events' );

		// Get viewport dimensions from parameter or use defaults
		$viewport_width = isset( $viewport['width'] ) ? (int) $viewport['width'] : 1920;
		$viewport_height = isset( $viewport['height'] ) ? (int) $viewport['height'] : 1080;

		$this->debug_log( '[Opti-Behavior-Heatmap] Using viewport dimensions: ' . $viewport_width . 'x' . $viewport_height );

		// Check if we have normalized data (modern approach) or legacy absolute coordinates.
		// Scan ANY element, not just [0]: mixed legacy/normalized batches (e.g. the
		// first point being a synthetic/legacy entry) would otherwise misclassify.
		$has_normalized = false;
		foreach ( $scroll_data as $scroll_point ) {
			if ( isset( $scroll_point['y_normalized'] ) ) {
				$has_normalized = true;
				break;
			}
		}

		if ( $has_normalized ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] Using NORMALIZED coordinate system (0-1)' );
			return $this->aggregate_normalized_scroll_data( $scroll_data, $viewport );
		}

		// Legacy approach for backward compatibility with old data
		$this->debug_log( '[Opti-Behavior-Heatmap] Using LEGACY absolute coordinate system' );

		// Find the maximum scroll depth AND maximum X coordinate
		$max_scroll_y = 0;
		$max_scroll_x = 0;
		foreach ( $scroll_data as $scroll ) {
			if ( isset( $scroll['y'] ) && $scroll['y'] > $max_scroll_y ) {
				$max_scroll_y = $scroll['y'];
			}
			if ( isset( $scroll['x'] ) && $scroll['x'] > $max_scroll_x ) {
				$max_scroll_x = $scroll['x'];
			}
		}

		// Use the greater of viewport width or max X coordinate to ensure full coverage
		$effective_width = max( $viewport_width, $max_scroll_x );

		$this->debug_log( '[Opti-Behavior-Heatmap] Scroll aggregate: Max scroll Y position: ' . $max_scroll_y );

		// If max scroll is negative (invalid), return empty
		// Note: Y=0 is valid (top of page), so we use < 0 instead of <= 0
		if ( $max_scroll_y < 0 ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] Scroll aggregate: Max scroll is negative (invalid)' );
			return array();
		}

		// Calculate the total page height (scroll position + viewport)
		$page_height = $max_scroll_y + $viewport_height;

		// Create a scroll depth visualization with FULL-WIDTH horizontal bands
		$result = array();

		// Calculate number of bands dynamically based on page height
		// Use 1 band per 20 pixels for better precision and granularity
		// Min 50 bands (for very short pages), Max 300 bands (for very long pages)
		$pixels_per_band = 20;
		$bands = max( 50, min( 300, intval( $page_height / $pixels_per_band ) ) );

		$points_per_band = 50; // Many points across width for complete coverage

		$this->debug_log( '[Opti-Behavior-Heatmap] Page height: ' . $page_height . 'px, Using ' . $bands . ' bands (~' . round($page_height / $bands) . 'px per band)' );

		// Track TIME SPENT in each band (in milliseconds)
		$band_time_spent = array();
		for ( $band = 0; $band <= $bands; $band++ ) {
			$band_time_spent[ $band ] = 0;
		}

		// Sort scroll events by timestamp to calculate dwell time
		usort( $scroll_data, function( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		// Calculate time spent viewing each band using Gaussian distribution
		for ( $i = 0; $i < count( $scroll_data ); $i++ ) {
			$current_scroll = $scroll_data[ $i ];
			$scroll_y = isset( $current_scroll['y'] ) ? $current_scroll['y'] : 0;
			$current_time = isset( $current_scroll['timestamp'] ) ? $current_scroll['timestamp'] : 0;

			// Calculate dwell time (time until next scroll event or default 1 second)
			$dwell_time = 1000; // Default 1 second in milliseconds
			if ( $i < count( $scroll_data ) - 1 ) {
				$next_time = isset( $scroll_data[ $i + 1 ]['timestamp'] ) ? $scroll_data[ $i + 1 ]['timestamp'] : $current_time;
				$dwell_time = $next_time - $current_time;

				// Cap maximum dwell time at 30 seconds (user might have left tab open)
				$dwell_time = min( $dwell_time, 30000 );
			}

			// Log scroll events for debugging
			// A breakaway Y is already the viewport BOTTOM edge (scrollTop + windowHeight),
			// so the actually-visible region is [scroll_y - viewport_height, scroll_y].
			$visible_top = $scroll_y - $viewport_height;

			$this->debug_log( '[Opti-Behavior-Heatmap] Scroll event ' . ($i+1) . ': Y=' . $scroll_y . ', dwell_time=' . round($dwell_time/1000, 2) . 's, visible: Y=' . $visible_top . ' to Y=' . $scroll_y );

			// Calculate the center of the visible viewport (Y is the bottom edge).
			$viewport_center = $scroll_y - ( $viewport_height / 2 );

			// Distribute this dwell time using Gaussian distribution
			// Bands in the center of viewport get more weight, edges get less
			for ( $band = 0; $band <= $bands; $band++ ) {
				$y_position = ( $band / $bands ) * $page_height;

				// Check if this band is visible in the current scroll position.
				// Do NOT add a second viewport height: scroll_y is already the bottom edge.
				if ( $y_position >= $visible_top && $y_position <= $scroll_y ) {
					// Calculate Gaussian weight based on distance from viewport center
					$distance_from_center = abs( $y_position - $viewport_center );

					// Gaussian function: weight = e^(-(distance^2) / (2 * sigma^2))
					// sigma = viewport_height / 4 means edges get ~10% weight
					$sigma = $viewport_height / 4;
					$gaussian_weight = exp( -( pow( $distance_from_center, 2 ) / ( 2 * pow( $sigma, 2 ) ) ) );

					// Apply weighted time to this band
					// Center bands get close to 100% of dwell_time, edges get ~10%
					$band_time_spent[ $band ] += $dwell_time * $gaussian_weight;
				}
			}
		}

		// Find maximum time spent to normalize values
		$max_time_spent = max( $band_time_spent );
		if ( $max_time_spent <= 0 ) {
			$max_time_spent = 1; // Avoid division by zero
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Max time spent in any band: ' . $max_time_spent . 'ms (' . round( $max_time_spent / 1000, 2 ) . 's)' );
		$this->debug_log( '[Opti-Behavior-Heatmap] Effective width for heatmap: ' . $effective_width );

		// Generate heatmap points ONLY for bands that have viewing time
		// This creates clear visual differences between viewed and non-viewed areas
		$bands_with_time = 0;
		foreach ( $band_time_spent as $band => $time_spent ) {
			// Skip bands with no viewing time - they should have no color
			if ( $time_spent <= 0 ) {
				continue;
			}

			$bands_with_time++;
			$y_position = ( $band / $bands ) * $page_height;

			// Calculate intensity based on time spent (normalized 0-100)
			// More time = hotter color (red), less time = cooler color (blue)
			$time_percentage = ( $time_spent / $max_time_spent ) * 100;

			// Normalize to a value between 1-100 for heatmap visualization
			// Minimum of 1 ensures bands with any viewing time have visible color
			$value = max( 1, min( 100, intval( $time_percentage ) ) );

			// Create DENSE horizontal band coverage
			// Distribute many points across the ENTIRE effective width to ensure no gaps
			for ( $i = 0; $i < $points_per_band; $i++ ) {
				// Evenly distribute points across the full effective width (covers entire page)
				$x_position = ( $i / max( 1, $points_per_band - 1 ) ) * $effective_width;

				// Add minimal randomness to avoid visible grid while maintaining coverage
				$x_jitter = wp_rand( -5, 5 );
				$y_jitter = wp_rand( -3, 3 );

				$result[] = array(
					'x' => max( 0, min( $effective_width, intval( $x_position + $x_jitter ) ) ),
					'y' => max( 0, intval( $y_position + $y_jitter ) ),
					'value' => $value
				);
			}
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Bands with viewing time: ' . $bands_with_time . ' out of ' . $bands . ' total bands (' . round(($bands_with_time / $bands) * 100, 1) . '%)' );
		$this->debug_log( '[Opti-Behavior-Heatmap] Scroll heatmap: Generated ' . count( $result ) . ' points for max scroll depth ' . $max_scroll_y . ' (page height: ' . $page_height . ')' );

		return $result;
	}

	/**
	 * Aggregate NORMALIZED scroll data for device-independent scroll depth heatmap.
	 * Uses normalized coordinates (0-1 range) to combine data from all devices.
	 * Renders on a reference page dimension for consistent visualization.
	 *
	 * @param array $scroll_data Scroll event data with normalized coordinates.
	 * @param array $viewport Viewport dimensions with 'width' and 'height'.
	 * @return array Aggregated scroll heatmap data with absolute coordinates for rendering.
	 */
	private function aggregate_normalized_scroll_data( $scroll_data, $viewport = array() ) {
		if ( empty( $scroll_data ) ) {
			return array();
		}

		// Extract reference page dimensions from the first scroll event with page dimensions
		$reference_width = 1920;  // Default reference width
		$reference_height = 5000; // Default reference height

		foreach ( $scroll_data as $scroll ) {
			if ( isset( $scroll['page_width'] ) && $scroll['page_width'] > 0 ) {
				$reference_width = max( $reference_width, (int) $scroll['page_width'] );
			}
			if ( isset( $scroll['page_height'] ) && $scroll['page_height'] > 0 ) {
				$reference_height = max( $reference_height, (int) $scroll['page_height'] );
			}
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Reference dimensions for rendering: ' . $reference_width . 'x' . $reference_height );

		// Get viewport dimensions from parameter or use defaults
		$viewport_width = isset( $viewport['width'] ) ? (int) $viewport['width'] : 1920;
		$viewport_height = isset( $viewport['height'] ) ? (int) $viewport['height'] : 1080;

		// Convert normalized coordinates (0-1) to reference page coordinates
		// This creates a UNIFIED coordinate system for all devices
		$denormalized_scrolls = array();
		foreach ( $scroll_data as $scroll ) {
			if ( ! isset( $scroll['y_normalized'] ) ) {
				continue;
			}

			// Denormalize: Y_render = Y_norm * H_reference
			$y_absolute = $scroll['y_normalized'] * $reference_height;
			$x_absolute = isset( $scroll['x_normalized'] ) ? $scroll['x_normalized'] * $reference_width : 0;

			$denormalized_scrolls[] = array(
				'x'         => (int) $x_absolute,
				'y'         => (int) $y_absolute,
				'timestamp' => isset( $scroll['timestamp'] ) ? $scroll['timestamp'] : 0,
			);
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Denormalized ' . count( $denormalized_scrolls ) . ' scroll events to reference dimensions' );

		// Find max scroll depth in the denormalized (reference) coordinate system
		$max_scroll_y = 0;
		foreach ( $denormalized_scrolls as $scroll ) {
			if ( $scroll['y'] > $max_scroll_y ) {
				$max_scroll_y = $scroll['y'];
			}
		}

		if ( $max_scroll_y <= 0 ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] No valid scroll depth found after denormalization' );
			return array();
		}

		// Use the reference height as the total page height
		// This ensures the heatmap covers the FULL normalized page
		$page_height = $reference_height;
		$effective_width = $reference_width;

		$this->debug_log( '[Opti-Behavior-Heatmap] Max denormalized scroll Y: ' . $max_scroll_y . ', Page height: ' . $page_height );

		// Create scroll depth visualization with bands
		$result = array();
		$pixels_per_band = 20;
		$bands = max( 50, min( 300, intval( $page_height / $pixels_per_band ) ) );
		$points_per_band = 50;

		$this->debug_log( '[Opti-Behavior-Heatmap] Using ' . $bands . ' bands for page height ' . $page_height );

		// Track time spent in each band
		$band_time_spent = array_fill( 0, $bands + 1, 0 );

		// Sort by timestamp
		usort( $denormalized_scrolls, function( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		// Calculate time spent viewing each band using Gaussian distribution
		for ( $i = 0; $i < count( $denormalized_scrolls ); $i++ ) {
			$current_scroll = $denormalized_scrolls[ $i ];
			$scroll_y = $current_scroll['y'];
			$current_time = $current_scroll['timestamp'];

			// Calculate dwell time
			$dwell_time = 1000; // Default 1 second
			if ( $i < count( $denormalized_scrolls ) - 1 ) {
				$next_time = $denormalized_scrolls[ $i + 1 ]['timestamp'];
				$dwell_time = min( $next_time - $current_time, 30000 ); // Cap at 30 seconds
			}

			// Calculate viewport center
			$viewport_center = $scroll_y + ( $viewport_height / 2 );

			// Distribute dwell time using Gaussian distribution
			for ( $band = 0; $band <= $bands; $band++ ) {
				$y_position = ( $band / $bands ) * $page_height;

				// Check if band is visible in current scroll position
				if ( $y_position >= $scroll_y && $y_position <= ( $scroll_y + $viewport_height ) ) {
					$distance_from_center = abs( $y_position - $viewport_center );
					$sigma = $viewport_height / 4;
					$gaussian_weight = exp( -( pow( $distance_from_center, 2 ) / ( 2 * pow( $sigma, 2 ) ) ) );
					$band_time_spent[ $band ] += $dwell_time * $gaussian_weight;
				}
			}
		}

		// Find max time spent
		$max_time_spent = max( $band_time_spent );
		if ( $max_time_spent <= 0 ) {
			$max_time_spent = 1;
		}

		// Generate heatmap points
		$bands_with_time = 0;
		foreach ( $band_time_spent as $band => $time_spent ) {
			if ( $time_spent <= 0 ) {
				continue;
			}

			$bands_with_time++;
			$y_position = ( $band / $bands ) * $page_height;
			$time_percentage = ( $time_spent / $max_time_spent ) * 100;
			$value = max( 1, min( 100, intval( $time_percentage ) ) );

			// Create horizontal band coverage
			for ( $i = 0; $i < $points_per_band; $i++ ) {
				$x_position = ( $i / max( 1, $points_per_band - 1 ) ) * $effective_width;
				$x_jitter = wp_rand( -5, 5 );
				$y_jitter = wp_rand( -3, 3 );

				$result[] = array(
					'x'     => max( 0, min( $effective_width, intval( $x_position + $x_jitter ) ) ),
					'y'     => max( 0, intval( $y_position + $y_jitter ) ),
					'value' => $value,
				);
			}
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] NORMALIZED scroll heatmap: Generated ' . count( $result ) . ' points covering ' . $bands_with_time . ' bands' );

		// Return coordinates plus reference dimensions for frontend rendering
		return array(
			'coordinates'      => $result,
			'reference_width'  => $reference_width,
			'reference_height' => $reference_height,
		);
	}

	/**
	 * Aggregate move data for mouse movement trajectory heatmap.
	 * Returns line segments (trajectories) that can be drawn as connected paths.
	 * Each trajectory is a continuous sequence of mouse positions from one session.
	 * Also calculates dwell-time spots for areas where the mouse stayed longer.
	 *
	 * @param array $move_data Mouse move event data with session info.
	 * @param array $viewport Viewport dimensions with 'width' and 'height'.
	 * @return array Array with 'trajectories' (line segments), 'coordinates' (heat points), and 'dwell_spots' (dwell-time heat).
	 */
	public function aggregate_move_data( $move_data, $viewport = array() ) {
		if ( empty( $move_data ) ) {
			$this->debug_log( '[Opti-Behavior-Heatmap] Move aggregate: No move data provided' );
			return array(
				'trajectories' => array(),
				'coordinates'  => array(),
				'dwell_spots'  => array(),
			);
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Move aggregate: Processing ' . count( $move_data ) . ' move events' );

		// Sort by timestamp to maintain trajectory order
		usort( $move_data, function( $a, $b ) {
			$time_a = isset( $a['timestamp'] ) ? $a['timestamp'] : 0;
			$time_b = isset( $b['timestamp'] ) ? $b['timestamp'] : 0;
			return $time_a - $time_b;
		} );

		// Build trajectories as connected line segments
		// A trajectory is a sequence of points that should be drawn as a continuous line
		$trajectories  = array();
		$current_path  = array();
		$prev_point    = null;
		$density_map   = array(); // For heat overlay
		$dwell_map     = array(); // For dwell-time heat spots

		// Track previous timestamp for dwell time calculation
		$prev_timestamp = null;

		foreach ( $move_data as $move ) {
			if ( ! isset( $move['x'], $move['y'] ) ) {
				continue;
			}

			$current_point = array(
				'x' => (int) $move['x'],
				'y' => (int) $move['y'],
			);

			$current_timestamp = isset( $move['timestamp'] ) ? (int) $move['timestamp'] : 0;

			// Calculate dwell time for the previous point
			if ( $prev_point !== null && $prev_timestamp !== null && $current_timestamp > $prev_timestamp ) {
				$dwell_time = $current_timestamp - $prev_timestamp;

				// Cap dwell time at 5 seconds (5000ms) to avoid skewing from pauses
				$dwell_time = min( $dwell_time, 5000 );

				// Only count dwell time if mouse stayed in same area (within 30px)
				$dx = $current_point['x'] - $prev_point['x'];
				$dy = $current_point['y'] - $prev_point['y'];
				$distance = sqrt( $dx * $dx + $dy * $dy );

				// If mouse stayed relatively still (within 50px), count as dwell time
				if ( $distance <= 50 ) {
					// Add dwell time to the previous point's grid cell
					$grid_size = 20; // Larger grid for dwell spots (20px cells)
					$grid_x = round( $prev_point['x'] / $grid_size ) * $grid_size;
					$grid_y = round( $prev_point['y'] / $grid_size ) * $grid_size;
					$key = $grid_x . '_' . $grid_y;

					if ( ! isset( $dwell_map[ $key ] ) ) {
						$dwell_map[ $key ] = array(
							'x'          => $grid_x,
							'y'          => $grid_y,
							'dwell_time' => 0,
						);
					}
					$dwell_map[ $key ]['dwell_time'] += $dwell_time;
				}
			}

			// Check if this point continues the current trajectory or starts a new one
			if ( $prev_point !== null ) {
				$dx       = $current_point['x'] - $prev_point['x'];
				$dy       = $current_point['y'] - $prev_point['y'];
				$distance = sqrt( $dx * $dx + $dy * $dy );

				// If jump is too large, end current trajectory and start new one
				if ( $distance >= 800 ) {
					// Save current trajectory if it has at least 2 points
					if ( count( $current_path ) >= 2 ) {
						$trajectories[] = $current_path;
					}
					$current_path = array();
				}
			}

			// Add point to current trajectory
			$current_path[] = $current_point;

			// Also add to density map for heat overlay
			$grid_size = 10;
			$grid_x    = round( $current_point['x'] / $grid_size ) * $grid_size;
			$grid_y    = round( $current_point['y'] / $grid_size ) * $grid_size;
			$key       = $grid_x . '_' . $grid_y;

			if ( ! isset( $density_map[ $key ] ) ) {
				$density_map[ $key ] = array(
					'x'     => $grid_x,
					'y'     => $grid_y,
					'count' => 0,
				);
			}
			$density_map[ $key ]['count']++;

			$prev_point = $current_point;
			$prev_timestamp = $current_timestamp;
		}

		// Don't forget the last trajectory
		if ( count( $current_path ) >= 2 ) {
			$trajectories[] = $current_path;
		}

		// Find max density for normalization
		$max_density = 0;
		foreach ( $density_map as $cell ) {
			if ( $cell['count'] > $max_density ) {
				$max_density = $cell['count'];
			}
		}

		if ( $max_density === 0 ) {
			$max_density = 1;
		}

		// Generate heat points as fallback/overlay
		$coordinates = array();
		foreach ( $density_map as $cell ) {
			$value         = max( 1, min( 100, intval( ( $cell['count'] / $max_density ) * 100 ) ) );
			$coordinates[] = array(
				'x'     => $cell['x'],
				'y'     => $cell['y'],
				'value' => $value,
			);
		}

		// Generate dwell-time heat spots
		$dwell_spots = $this->calculate_dwell_spots( $dwell_map );

		$total_points = 0;
		foreach ( $trajectories as $traj ) {
			$total_points += count( $traj );
		}

		$this->debug_log( '[Opti-Behavior-Heatmap] Move heatmap: Generated ' . count( $trajectories ) . ' trajectories with ' . $total_points . ' total points' );
		$this->debug_log( '[Opti-Behavior-Heatmap] Move heatmap: Generated ' . count( $dwell_spots ) . ' dwell-time spots' );

		return array(
			'trajectories' => $trajectories,
			'coordinates'  => $coordinates,
			'dwell_spots'  => $dwell_spots,
		);
	}

	/**
	 * Calculate dwell-time spots from dwell map.
	 * Normalizes dwell times to values 0-100 for heatmap.js visualization.
	 *
	 * @param array $dwell_map Map of grid positions to dwell times.
	 * @return array Array of dwell spots with x, y, and value for heatmap.js.
	 */
	private function calculate_dwell_spots( $dwell_map ) {
		if ( empty( $dwell_map ) ) {
			return array();
		}

		// Find max dwell time for normalization
		$max_dwell = 0;
		foreach ( $dwell_map as $cell ) {
			if ( $cell['dwell_time'] > $max_dwell ) {
				$max_dwell = $cell['dwell_time'];
			}
		}

		if ( $max_dwell === 0 ) {
			return array();
		}

		// Generate normalized dwell spots
		$dwell_spots = array();
		foreach ( $dwell_map as $cell ) {
			// Normalize to 0-100 range for heatmap.js
			$value = max( 1, min( 100, intval( ( $cell['dwell_time'] / $max_dwell ) * 100 ) ) );

			// Only include spots with significant dwell time (at least 1% of max)
			if ( $value >= 1 ) {
				$dwell_spots[] = array(
					'x'     => $cell['x'],
					'y'     => $cell['y'],
					'value' => $value,
				);
			}
		}

		return $dwell_spots;
	}

	/**
	 * Calculate scroll depth percentage.
	 *
	 * @param array $scroll_data Scroll event data.
	 * @param int   $page_height Total page height.
	 * @return array Scroll depth distribution.
	 */
	public function calculate_scroll_depth( $scroll_data, $page_height = 5000 ) {
		if ( empty( $scroll_data ) ) {
			return array();
		}

		// Get max scroll position.
		$max_scroll = max( array_column( $scroll_data, 'y' ) );
		$percentage = min( 100, ( $max_scroll / $page_height ) * 100 );

		// Create depth buckets (0-10%, 10-20%, etc.).
		$buckets = array_fill( 0, 10, 0 );
		foreach ( $scroll_data as $scroll ) {
			$depth = ( $scroll['y'] / $page_height ) * 100;
			$bucket_index = min( 9, floor( $depth / 10 ) );
			++$buckets[ $bucket_index ];
		}

		return array(
			'max_depth'   => round( $percentage, 1 ),
			'distribution' => $buckets,
		);
	}

	/**
	 * Detect rage clicks (3+ clicks in same area within 2 seconds).
	 *
	 * @param array $click_data Click event data.
	 * @return array Array of rage click locations.
	 */
	public function detect_rage_clicks( $click_data ) {
		if ( empty( $click_data ) ) {
			return array();
		}

		$rage_clicks = array();
		$radius      = 100; // 100px radius.
		$time_window = 2000; // 2 seconds in milliseconds.

		for ( $i = 0; $i < count( $click_data ) - 2; $i++ ) {
			$first = $click_data[ $i ];
			$count = 1;

			for ( $j = $i + 1; $j < count( $click_data ); $j++ ) {
				$current = $click_data[ $j ];

				// Check if within time window.
				if ( $current['timestamp'] - $first['timestamp'] > $time_window ) {
					break;
				}

				// Check if within proximity.
				$distance = sqrt(
					pow( $current['x'] - $first['x'], 2 ) +
					pow( $current['y'] - $first['y'], 2 )
				);

				if ( $distance <= $radius ) {
					++$count;
				}
			}

			// Rage click detected.
			if ( $count >= 3 ) {
				$rage_clicks[] = array(
					'x'     => $first['x'],
					'y'     => $first['y'],
					'count' => $count,
				);
			}
		}

		return $rage_clicks;
	}
}
