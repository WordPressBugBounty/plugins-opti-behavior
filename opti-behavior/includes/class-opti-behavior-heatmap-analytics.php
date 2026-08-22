<?php
/**
 * Analytics Class
 *
 * Handles data processing and analytics.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Class
 *
 * Provides analytics and data processing functionality.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database queries required for analytics plugin functionality. Custom tables used for high-volume event tracking. Caching not appropriate for real-time analytics data.
class Opti_Behavior_Heatmap_Analytics {

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Get list items for heatmap list table.
	 *
	 * @since 1.0.0
	 * @param array $param Parameters (event, search, pagenum, orderby, order).
	 * @return array List items.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function get_list_items( $param ) {
		global $wpdb;

		// Validate and whitelist ORDER BY and ORDER direction
		$param['order']   = strtolower( $param['order'] );
		$param['orderby'] = strtolower( $param['orderby'] );

		// Whitelist for ORDER direction (safe hardcoded values)
		$allowed_order = array( 'desc', 'asc' );
		$param['order'] = in_array( $param['order'], $allowed_order, true ) ? $param['order'] : 'asc';

		// Whitelist for ORDER BY columns (safe hardcoded values)
		$allowed_orderby = array( 'page', 'click_pc', 'breakaway_pc', 'attention_pc', 'click_mobile', 'breakaway_mobile', 'attention_mobile' );
		$param['orderby'] = in_array( $param['orderby'], $allowed_orderby, true ) ? $param['orderby'] : 'page';

		// Replace 'page' with actual column name (safe replacement)
		$param['orderby'] = str_replace( 'page', 'p.url2', $param['orderby'] );

		// Escape table prefixes for WordPress Plugin Check compliance
		$events_table = esc_sql( $wpdb->prefix . 'optibehavior_events' );
		$pages_table = esc_sql( $wpdb->prefix . 'optibehavior_pages' );

		// Build WHERE clause (safe - contains only table names and placeholders)
		$where = '';
		if ( $param['search'] ) {
			$where = " WHERE page_id2 IN ( SELECT DISTINCT page_id2 FROM {$events_table} WHERE page_id IN ( SELECT id FROM {$pages_table} WHERE title LIKE %s OR url LIKE %s ) ) ";
		}

		// Build ORDER BY clause with whitelisted values (safe - values are validated and whitelisted)
		$order_by_clause = $param['orderby'] . ' ' . $param['order'];

		// Build SQL query with whitelisted ORDER BY and ORDER values
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are escaped, orderby/order are validated and whitelisted
		$sql = "SELECT
					s.page_id2 AS id,
					p.url2 AS url,
					p.title,
					s.click_pc,
					s.breakaway_pc,
					s.attention_pc,
					s.click_mobile,
					s.breakaway_mobile,
					s.attention_mobile
				FROM (SELECT
						page_id2,
						COUNT( event = 16 OR NULL ) AS click_pc,
						COUNT( event = 32 OR NULL ) AS breakaway_pc,
						COUNT( event = 48 OR NULL ) AS attention_pc,
						COUNT( event = 17 OR NULL ) AS click_mobile,
						COUNT( event = 33 OR NULL ) AS breakaway_mobile,
						COUNT( event = 49 OR NULL ) AS attention_mobile
						FROM {$events_table}
						{$where}
						GROUP BY page_id2
					) as s
				LEFT JOIN {$pages_table} AS p ON p.id = s.page_id2
				WHERE (click_pc OR breakaway_pc OR attention_pc OR click_mobile OR breakaway_mobile OR attention_mobile)
				ORDER BY {$order_by_clause}, p.url ASC
				LIMIT %d OFFSET %d";

		// Build prepare arguments
		$prepare_args = array();
		if ( $param['search'] ) {
			$prepare_args[] = $param['search_title'];
			$prepare_args[] = $param['search_url'];
		}
		$prepare_args[] = Opti_Behavior_Heatmap_Core::LIST_PER_PAGE;
		$prepare_args[] = Opti_Behavior_Heatmap_Core::LIST_PER_PAGE * ( max( $param['pagenum'], 1 ) - 1 );

		// Execute query with dynamic parameters
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is built with escaped table names and whitelisted values, all user inputs properly parameterized
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );

		// Debug logging
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'get_list_items SQL query: ' . $wpdb->last_query, 'debug', 'analytics' );
		$debug_manager->log( 'get_list_items returned ' . count( $rows ) . ' rows', 'debug', 'analytics' );
		if ( ! empty( $rows ) ) {
			$debug_manager->log( 'First row data: ' . wp_json_encode( $rows[0] ), 'debug', 'analytics' );
		}

		if ( ! $rows ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Get total items count for heatmap list table.
	 *
	 * @since 1.0.0
	 * @param array $param Parameters (event, search, pagenum).
	 * @return int Total items count.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function get_list_total_items( $param ) {
		global $wpdb;

		if ( ! $param['search'] ) {
			$query = "SELECT COUNT( DISTINCT page_id2 ) FROM {$wpdb->prefix}optibehavior_events";
		} else {
			$query = $wpdb->prepare(
				"SELECT COUNT( DISTINCT page_id2 ) FROM {$wpdb->prefix}optibehavior_events AS e INNER JOIN ( SELECT id FROM {$wpdb->prefix}optibehavior_pages WHERE title LIKE %s OR url LIKE %s ) AS p ON ( e.page_id2 = p.id )",
				$param['search_title'],
				$param['search_url']
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL with proper prepare
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Get click heatmap data.
	 *
	 * @since 1.0.0
	 * @param int $page_id Page ID.
	 * @param int $event_id Event ID.
	 * @return array Click heatmap data.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function get_click_heatmap( $page_id, $event_id ) {
		global $wpdb;

		$options = $this->core->get_options();
		$ar = $this->core->get_ar();
		$accuracy = $options['accuracy'];

		// Fix: Default to accuracy level 1 if not set
		if ( empty( $accuracy ) || ! in_array( $accuracy, array( 1, 2 ) ) ) {
			$accuracy = 1; // Default to standard accuracy (600-2560 for PC, 280-768 for mobile)
		}

		// Debug logging
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'get_click_heatmap: page_id=' . $page_id . ', event_id=' . $event_id, 'debug', 'analytics' );
		$debug_manager->log( 'get_click_heatmap: accuracy=' . $accuracy . ' (fixed from: ' . $options['accuracy'] . ')', 'debug', 'analytics' );

		$device_index = ( $event_id === Opti_Behavior_Heatmap_Core::CLICK_MOBILE ) ? Opti_Behavior_Heatmap_Core::FROM_MOBILE : Opti_Behavior_Heatmap_Core::FROM_PC;
		$width_range = $ar[ $device_index ][ $accuracy ];

		$debug_manager->log( 'get_click_heatmap: device_index=' . $device_index . ', width_range=' . $width_range[0] . '-' . $width_range[1], 'debug', 'analytics' );

		// Check storage mode
		$file_storage = $this->core->get_file_storage();
		$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );

		if ( $storage_settings['storage_mode'] === 'file' && $file_storage ) {
			// Read from file storage
			$debug_manager->log( 'get_click_heatmap: Reading from file storage', 'debug', 'analytics' );
			$events = $file_storage->read_events( $page_id, $event_id, 0 );
			$debug_manager->log( 'get_click_heatmap: Found ' . count( $events ) . ' events from file storage', 'debug', 'analytics' );

			// Filter by width range
			$filtered_events = array();
			foreach ( $events as $event ) {
				$width = isset( $event['width'] ) ? intval( $event['width'] ) : 0;
				if ( $width >= $width_range[0] && $width <= $width_range[1] ) {
					$filtered_events[] = $event;
				}
			}

			$debug_manager->log( 'get_click_heatmap: Filtered to ' . count( $filtered_events ) . ' events within width range', 'debug', 'analytics' );

			// Fallback: if nothing found within width accuracy, use all events
			if ( empty( $filtered_events ) ) {
				$filtered_events = $events;
				$debug_manager->log( 'get_click_heatmap: Fallback (no width filter) using all ' . count( $filtered_events ) . ' events', 'debug', 'analytics' );
			}

			// Convert to objects for compatibility with database results
			$results = array();
			foreach ( $filtered_events as $event ) {
				$obj = new stdClass();
				$obj->x = isset( $event['x'] ) ? $event['x'] : 0;
				$obj->y = isset( $event['y'] ) ? $event['y'] : 0;
				$obj->width = isset( $event['width'] ) ? $event['width'] : 0;
				$obj->height = isset( $event['height'] ) ? $event['height'] : 0;
				$obj->x_normalized = isset( $event['x_normalized'] ) ? $event['x_normalized'] : 0;
				$obj->y_normalized = isset( $event['y_normalized'] ) ? $event['y_normalized'] : 0;
				$obj->viewport_width = isset( $event['viewport_width'] ) ? $event['viewport_width'] : 0;
				$obj->viewport_height = isset( $event['viewport_height'] ) ? $event['viewport_height'] : 0;
				$obj->element_tag = isset( $event['element_tag'] ) ? $event['element_tag'] : '';
				$obj->element_id = isset( $event['element_id'] ) ? $event['element_id'] : '';
				$obj->element_class = isset( $event['element_class'] ) ? $event['element_class'] : '';
				$results[] = $obj;
			}
		} else {
			// Read from database
			$debug_manager->log( 'get_click_heatmap: Reading from database', 'debug', 'analytics' );

			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT x, y, width, height, x_normalized, y_normalized, viewport_width, viewport_height,
						element_tag, element_id, element_class
				FROM {$wpdb->prefix}optibehavior_events
				WHERE page_id2 = %d AND event = %d
				AND width BETWEEN %d AND %d
				ORDER BY insert_at DESC",
				$page_id,
				$event_id,
				$width_range[0],
				$width_range[1]
			) );

			$debug_manager->log( 'get_click_heatmap: SQL query: ' . $wpdb->last_query, 'debug', 'analytics' );
			$debug_manager->log( 'get_click_heatmap: Found ' . count( $results ) . ' raw events', 'debug', 'analytics' );

			// Fallback: if nothing found within width accuracy, try without width constraint
			if ( empty( $results ) ) {
				$results = $wpdb->get_results( $wpdb->prepare(
					"SELECT x, y, width, height, x_normalized, y_normalized, viewport_width, viewport_height,
							element_tag, element_id, element_class
					FROM {$wpdb->prefix}optibehavior_events
					WHERE page_id2 = %d AND event = %d
					ORDER BY insert_at DESC",
					$page_id,
					$event_id
				) );
				$debug_manager->log( 'get_click_heatmap: Fallback (no width filter) found ' . count( $results ) . ' events', 'debug', 'analytics' );
			}
		}

		$processed = $this->process_click_data( $results );
		$debug_manager->log( 'get_click_heatmap: Processed data: ' . wp_json_encode( $processed ), 'debug', 'analytics' );

		return $processed;
	}

	/**
	 * Process click data for visualization.
	 *
	 * @since 1.0.0
	 * @param array $raw_data Raw click data.
	 * @return array Processed click data.
	 */
	private function process_click_data( $raw_data ) {
		$processed = array();
		
		foreach ( $raw_data as $click ) {
			$processed[] = array(
				'x'               => (int) $click->x,
				'y'               => (int) $click->y,
				'width'           => (int) $click->width,
				'height'          => (int) $click->height,
				'x_normalized'    => (float) $click->x_normalized,
				'y_normalized'    => (float) $click->y_normalized,
				'viewport_width'  => (int) $click->viewport_width,
				'viewport_height' => (int) $click->viewport_height,
				'element_tag'     => $click->element_tag,
				'element_id'      => $click->element_id,
				'element_class'   => $click->element_class,
			);
		}

		return $processed;
	}

	/**
	 * Get heatmap URL for specific item and event.
	 *
	 * @since 1.0.0
	 * @param object $item List item.
	 * @param string $event_name Event name.
	 * @return string Heatmap URL.
	 */
	public function get_heatmap_url( $item, $event_name ) {
		$base_url = $item->url;
		
		// Add heatmap parameters
		$params = array(
			'opti-behavior' => $event_name,
			'page_id'        => $item->id,
		);

		return add_query_arg( $params, $base_url );
	}

	/**
	 * Get or create page ID.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @param string $title Page title.
	 * @return int Page ID.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function get_or_create_page_id( $url, $title ) {
		global $wpdb;

		$options = $this->core->get_options();
		$database = $this->core->get_database();

		// Build URL2 for comparison
		$database = $this->core->get_database();
		$query_filter = $database->make_url_filter( true );
		$fragment_filter = $options['keep_url_hash'];
		$url2 = $database->rebuild_url( $url, $query_filter, $fragment_filter );

		// Get proper title if empty or "Untitled Page"
		$proper_title = $this->get_proper_page_title( $url, $title );

		// Detect taxonomy information
		$taxonomy_info = $this->detect_taxonomy_info( $url );

		// Check if page exists by url2 (normalized URL)
		$page_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}optibehavior_pages WHERE url2 = %s LIMIT 1",
			$url2
		) );

		// Fallback: check by original URL if url2 didn't match
		if ( ! $page_id ) {
			$page_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}optibehavior_pages WHERE url = %s LIMIT 1",
				$url
			) );
		}

		if ( $page_id ) {
			// Update existing page with proper title, url2, and taxonomy info
			$wpdb->update(
				"{$wpdb->prefix}optibehavior_pages",
				array(
					'url2'        => $url2,
					'title'       => $proper_title,
					'post_id'     => $taxonomy_info['post_id'],
					'term_id'     => $taxonomy_info['term_id'],
					'taxonomy'    => $taxonomy_info['taxonomy'],
					'object_type' => $taxonomy_info['object_type'],
					'update_at'   => current_time( 'mysql' ),
				),
				array( 'id' => $page_id ),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $page_id;
		}

		// Create new page with proper title and taxonomy info
		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_pages",
			array(
				'url'         => $url,
				'url2'        => $url2,
				'title'       => $proper_title,
				'post_id'     => $taxonomy_info['post_id'],
				'term_id'     => $taxonomy_info['term_id'],
				'taxonomy'    => $taxonomy_info['taxonomy'],
				'object_type' => $taxonomy_info['object_type'],
				'insert_at'   => current_time( 'mysql' ),
				'update_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Detect taxonomy information from URL
	 *
	 * @since 3.0.8
	 * @param string $url Page URL.
	 * @return array Array with post_id, term_id, taxonomy, and object_type.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function detect_taxonomy_info( $url ) {
		global $wpdb;

		$result = array(
			'post_id'     => null,
			'term_id'     => null,
			'taxonomy'    => null,
			'object_type' => null,
		);

		// Parse URL
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

		// Check for search page
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_params );
			if ( isset( $query_params['s'] ) ) {
				$result['taxonomy'] = 'search';
				$result['object_type'] = 'search';
				return $result;
			}
		}

		if ( empty( $path ) ) {
			$result['object_type'] = 'home';
			return $result;
		}

		// Handle special system pages
		if ( strpos( $path, 'wp-login' ) !== false ) {
			$result['object_type'] = 'system';
			return $result;
		}

		if ( strpos( $path, 'wp-admin' ) !== false ) {
			$result['object_type'] = 'system';
			return $result;
		}

		// Remove WordPress base path if present
		$site_url = get_site_url();
		$site_path = wp_parse_url( $site_url, PHP_URL_PATH );
		if ( $site_path && strpos( $path, ltrim( $site_path, '/' ) ) === 0 ) {
			$path = substr( $path, strlen( ltrim( $site_path, '/' ) ) );
			$path = ltrim( $path, '/' );
		}

		// Split path into parts
		$path_parts = explode( '/', $path );

		if ( empty( $path_parts ) || empty( $path_parts[0] ) ) {
			$result['object_type'] = 'home';
			return $result;
		}

		// Check for author archives
		if ( $path_parts[0] === 'author' && isset( $path_parts[1] ) ) {
			$author_nicename = $path_parts[1];
			$author = $wpdb->get_row( $wpdb->prepare(
				"SELECT ID, user_nicename FROM {$wpdb->users} WHERE user_nicename = %s LIMIT 1",
				$author_nicename
			) );

			if ( $author ) {
				$result['post_id'] = (int) $author->ID;
				$result['taxonomy'] = 'author';
				$result['object_type'] = 'author';
			}
			return $result;
		}

		// Check for date archives (year, month, day)
		if ( is_numeric( $path_parts[0] ) && strlen( $path_parts[0] ) === 4 ) {
			$result['taxonomy'] = 'date';
			$result['object_type'] = 'date_archive';
			return $result;
		}

		// Check for category archives
		if ( $path_parts[0] === 'category' && isset( $path_parts[1] ) ) {
			$slug = $path_parts[1];
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'category'
				LIMIT 1",
				$slug
			) );

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'category';
				$result['object_type'] = 'term';
			}
			return $result;
		}

		// Check for tag archives
		if ( $path_parts[0] === 'tag' && isset( $path_parts[1] ) ) {
			$slug = $path_parts[1];
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'post_tag'
				LIMIT 1",
				$slug
			) );

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'post_tag';
				$result['object_type'] = 'term';
			}
			return $result;
		}
		// Check for custom post type archives
		$post_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		foreach ( $post_types as $post_type ) {
			if ( isset( $post_type->rewrite['slug'] ) && $path_parts[0] === $post_type->rewrite['slug'] ) {
				$result['taxonomy'] = $post_type->name;
				$result['object_type'] = 'post_type_archive';
				return $result;
			}
		}


		// Check for product category (WooCommerce)
		if ( ( $path_parts[0] === 'product-category' && isset( $path_parts[1] ) ) ||
			 ( $path_parts[0] === 'product_cat' && isset( $path_parts[1] ) ) ) {
			$slug = $path_parts[1];
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'product_cat'
				LIMIT 1",
				$slug
			) );

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'product_cat';
				$result['object_type'] = 'term';
			}
			return $result;
		}

		// Check for product tag (WooCommerce)
		if ( $path_parts[0] === 'product-tag' && isset( $path_parts[1] ) ) {
			$slug = $path_parts[1];
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'product_tag'
				LIMIT 1",
				$slug
			) );

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'product_tag';
				$result['object_type'] = 'term';
			}
			return $result;
		}

		// Check for custom taxonomy by trying to match slug in any taxonomy
		$slug = end( $path_parts );
		if ( ! empty( $slug ) ) {
			// Try to find as a term in any custom taxonomy
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.slug = %s
				AND tt.taxonomy NOT IN ('nav_menu', 'link_category', 'post_format')
				ORDER BY CASE
					WHEN tt.taxonomy = 'category' THEN 1
					WHEN tt.taxonomy = 'post_tag' THEN 2
					WHEN tt.taxonomy LIKE %s THEN 3
					ELSE 4
				END
				LIMIT 1",
				$slug,
				'product%'
			) );

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = $term->taxonomy;
				$result['object_type'] = 'term';
				return $result;
			}

			// Try to find as a post/page/product
			$post = $wpdb->get_row( $wpdb->prepare(
				"SELECT ID, post_type
				FROM {$wpdb->posts}
				WHERE post_name = %s
				AND post_status = 'publish'
				ORDER BY CASE
					WHEN post_type = 'page' THEN 1
					WHEN post_type = 'post' THEN 2
					WHEN post_type = 'product' THEN 3
					ELSE 4
				END
				LIMIT 1",
				$slug
			) );

			if ( $post ) {
				$result['post_id'] = (int) $post->ID;
				$result['object_type'] = 'post';
				return $result;
			}
		}

		// Check if page was not found (404)
		if ( empty( $result['post_id'] ) && empty( $result['term_id'] ) && $result['object_type'] === null ) {
			$result['object_type'] = '404';
			return $result;
		}

		// Default: unknown
		$result['object_type'] = 'other';
		return $result;
	}

	/**
	 * Get proper page title for a URL.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @param string $fallback_title Fallback title.
	 * @return string Proper page title.
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function get_proper_page_title( $url, $fallback_title ) {
		// If we have a good title already, use it
		if ( ! empty( $fallback_title ) && $fallback_title !== 'Untitled Page' ) {
			return $fallback_title;
		}

		global $wpdb;

		// Extract path from URL
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

		// Check for search page
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_params );
			if ( isset( $query_params['s'] ) ) {
				$result['taxonomy'] = 'search';
				$result['object_type'] = 'search';
				return $result;
			}
		}

		if ( empty( $path ) ) {
			return __( 'Home Page', 'opti-behavior' );
		}

		// Remove language prefix (ru/, en/, etc.)
		$clean_path = $path;
		if ( preg_match( '/^(ru|en|fr|de|es)\/(.+)/', $path, $matches ) ) {
			$clean_path = $matches[2];
		}

		// Get the final slug
		$path_parts = explode( '/', $clean_path );
		$slug = end( $path_parts );

		if ( empty( $slug ) ) {
			return 'Page';
		}

		// Try to find WordPress post by slug
		$post = $wpdb->get_row( $wpdb->prepare(
			"SELECT post_title FROM {$wpdb->prefix}posts
			WHERE post_name = %s
			AND post_status = 'publish'
			AND post_type IN ('post', 'page', 'product')
			ORDER BY post_type = 'page' DESC
			LIMIT 1",
			$slug
		) );

		if ( $post && ! empty( $post->post_title ) ) {
			return $post->post_title;
		}

		// Generate readable title from slug
		$generated_title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

		// Add context based on URL
		if ( strpos( $path, 'indicators' ) !== false ) {
			$generated_title .= ' - Trading Indicator';
		} elseif ( strpos( $path, 'brokers' ) !== false ) {
			$generated_title .= ' - Forex Broker';
		}

		return $generated_title;
	}
}
