<?php
/**
 * Heatmap page-type filter + archive-page heatmap prune (1.9.5).
 *
 * Problem: every tag, category, author, date, search and paginated archive
 * URL got its own heatmap (one heatmap_pages row + one data directory). Sites
 * with a large archive tree ended up with thousands of near-empty heatmaps,
 * a slow Heatmaps list and a large DB / uploads folder.
 *
 *  1. Ingest filter — {@see self::should_skip_heatmap_url()} is called by
 *     Opti_Behavior_Heatmap_Storage::save_heatmap_data(). When "Non-Singular
 *     Pages" is "Posts & Pages Only" (report_non_singular = 0, the default
 *     since 1.9.5) heatmap points of archive URLs are dropped. Sessions, page
 *     views, UTM and A/B tracking are NOT affected: the tracker still loads on
 *     every page. The decision is URL-structural only (no DB query on the
 *     beacon path) and anything it cannot classify is kept.
 *  2. Migration — once per site ({@see self::MIGRATED_OPTION}) switches the
 *     setting to "Posts & Pages Only", shows an admin notice with an undo and
 *     schedules the prune when heatmaps already exist.
 *  3. Prune — cursor-resumable, time-boxed cron ticks over heatmap_pages.
 *     Archive URLs with fewer than {@see self::KEEP_SESSIONS} sessions are
 *     archived: the row snapshot is appended to a manifest, the data dir is
 *     moved to `_orphaned/{hash}-{YmdHis}` (aged out later by
 *     Opti_Behavior_Heatmap_Orphan_Purge) and the mapping row is deleted.
 *     "Restore" replays the manifest (dirs back + rows re-inserted).
 *
 * Version-skew safety (Free and Pro at different versions): no schema change,
 * no column or table rename. Pro of any version reads heatmap_pages by page_id
 * and already handles a page without mapping row; Pro writes heatmap points
 * through the Free storage class, so the ingest filter covers it without a Pro
 * update. Every entry point catches Throwable and fails open (tracking keeps
 * working, nothing is archived).
 *
 * @package OptiBehavior
 * @since   1.9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Heatmap_Page_Type_Prune
 */
class Opti_Behavior_Heatmap_Page_Type_Prune {

	/** Autoloaded flag: the one-time migration already ran on this site. */
	const MIGRATED_OPTION = 'opti_behavior_page_type_prune_migrated';

	/** State option (not autoloaded). */
	const STATE_OPTION = 'opti_behavior_page_type_prune_state';

	/** Prune tick (one-off, schedules its own continuation). */
	const HOOK = 'opti_behavior_page_type_prune_run';

	/** Restore tick. */
	const RESTORE_HOOK = 'opti_behavior_page_type_prune_restore';

	/** "Delete archived data now" tick. */
	const PURGE_HOOK = 'opti_behavior_page_type_prune_purge';

	/** admin-post action prefix (also the nonce action). */
	const ACTION_PREFIX = 'opti_behavior_page_prune_';

	/** Advisory lock shared by the prune, restore and purge ticks. */
	const LOCK_NAME = 'opti_behavior_page_type_prune';

	/** Manifest inside `_orphaned/`; the PHP guard line blocks web reads. */
	const MANIFEST_FILE = 'page-type-prune-manifest.php';

	/** First line of the manifest. */
	const MANIFEST_HEADER = "<?php exit; ?>\n";

	/** Archive pages with at least this many sessions are kept. */
	const KEEP_SESSIONS = 100;

	/** Rows read per query. */
	const BATCH_SIZE = 200;

	/** Seconds a tick may run before scheduling a continuation. */
	const TIME_BUDGET = 20;

	/** Passes allowed for rows whose session aggregates were not computed yet. */
	const MAX_PASSES = 3;

	/** Archive dir name written by the prune: {url_hash}-{YmdHis}. */
	const ARCHIVE_NAME_PATTERN = '/^[a-f0-9]{32}-\d{14}$/';

	/** Settings card anchor. */
	const CARD_ID = 'opti-behavior-page-type-prune-card';

	/** @var bool */
	private static $hooks_registered = false;

	/**
	 * Wire cron, migration, notice and admin-post handlers.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_action( self::HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( self::RESTORE_HOOK, array( __CLASS__, 'run_restore_tick' ) );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'run_purge_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 30 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'current_screen', array( __CLASS__, 'maybe_heal_schedule' ) );
		foreach ( array( 'keep_all', 'dismiss', 'restore', 'optimize', 'continue', 'purge' ) as $command ) {
			add_action( 'admin_post_' . self::ACTION_PREFIX . $command, array( __CLASS__, 'handle_admin_action' ) );
		}
	}

	// ─── Setting ────────────────────────────────────────────────────────────

	/**
	 * Whether "Non-Singular Pages" is "Track All Pages". Missing key = no
	 * (default since 1.9.5).
	 *
	 * @return bool
	 */
	public static function tracks_all_pages() {
		$options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		if ( ! is_array( $options ) || ! isset( $options['report_non_singular'] ) ) {
			return false;
		}
		return 1 === (int) $options['report_non_singular'];
	}

	/**
	 * Persist the setting. Goes through the core Options object when present so
	 * the request's in-memory copy stays in sync (a later Options::save() in the
	 * same request would otherwise write the old value back).
	 *
	 * @param bool $track_all Track all pages.
	 * @return void
	 */
	private static function set_track_all( $track_all ) {
		$value = $track_all ? 1 : 0;
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) && method_exists( 'Opti_Behavior_Heatmap_Core', 'get_instance' ) ) {
			$core    = Opti_Behavior_Heatmap_Core::get_instance();
			$options = ( $core && method_exists( $core, 'get_options' ) ) ? $core->get_options() : null;
			if ( $options instanceof ArrayAccess ) {
				$options['report_non_singular'] = $value;
				return;
			}
		}
		$raw                        = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		$raw                        = is_array( $raw ) ? $raw : array();
		$raw['report_non_singular'] = $value;
		update_option( 'opti_behavior_heatmap_option', maybe_serialize( $raw ) );
	}

	/**
	 * Settings form saved the "Non-Singular Pages" radio with a new value.
	 *
	 * @param bool $track_all New value.
	 * @return void
	 */
	public static function note_user_choice( $track_all ) {
		try {
			$state                   = self::read_fresh_state();
			$state['user_choice_at'] = current_time( 'mysql' );
			if ( $track_all && in_array( $state['status'], array( 'pending', 'running' ), true ) ) {
				$state['status'] = 'cancelled';
				wp_clear_scheduled_hook( self::HOOK );
			}
			self::save_state( $state );
		} catch ( \Throwable $e ) {
			// Never break the settings save.
			unset( $e );
		}
	}

	// ─── URL classification ─────────────────────────────────────────────────

	/**
	 * Classify a URL: 'keep' or the archive kind (search, paginated, date,
	 * author, category, tag, taxonomy, post_type_archive). Structural only — no
	 * DB query — and anything unknown is 'keep'.
	 *
	 * @param string $url       Absolute URL or path.
	 * @param bool   $use_query Also look at the query string (beacon path). The
	 *                          prune passes false: url_hash ignores the query, so
	 *                          a row's stored url may carry the query of any
	 *                          request that hit the same path (e.g. home + ?s=).
	 * @return string
	 */
	public static function classify_url( $url, $use_query = true ) {
		$reason = self::classify_url_structure( (string) $url, (bool) $use_query );

		/**
		 * Filter the heatmap page-type decision for a URL.
		 *
		 * @since 1.9.5
		 * @param string $reason 'keep' or the archive kind.
		 * @param string $url    URL being classified.
		 */
		$filtered = apply_filters( 'opti_behavior_heatmap_page_type', $reason, (string) $url );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : 'keep';
	}

	/**
	 * @param string $url       URL.
	 * @param bool   $use_query Inspect the query string.
	 * @return string
	 */
	private static function classify_url_structure( $url, $use_query ) {
		if ( '' === $url ) {
			return 'keep';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return 'keep';
		}

		$path      = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path ) {
			$prefix = '/' . $home_path;
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				$path = substr( $path, strlen( $prefix ) );
			}
		}
		$path = strtolower( trim( (string) $path, '/' ) );
		if ( 0 === strpos( $path, 'index.php' ) ) {
			$path = trim( substr( $path, strlen( 'index.php' ) ), '/' );
		}

		if ( $use_query && ! empty( $parts['query'] ) ) {
			$query = array();
			parse_str( (string) $parts['query'], $query );
			if ( isset( $query['s'] ) && '' !== $query['s'] ) {
				return 'search';
			}
			if ( isset( $query['paged'] ) && (int) $query['paged'] > 1 ) {
				return 'paginated';
			}
			if ( '' === $path ) {
				// Plain permalinks.
				if ( ! empty( $query['cat'] ) || ! empty( $query['category_name'] ) ) {
					return 'category';
				}
				if ( ! empty( $query['tag'] ) ) {
					return 'tag';
				}
				if ( ! empty( $query['author'] ) || ! empty( $query['author_name'] ) ) {
					return 'author';
				}
				if ( ! empty( $query['m'] ) || ! empty( $query['year'] ) ) {
					return 'date';
				}
			}
		}

		if ( '' === $path ) {
			return 'keep';
		}

		$segments = explode( '/', $path );
		$count    = count( $segments );

		if ( $count >= 2 && 'page' === $segments[ $count - 2 ] && ctype_digit( $segments[ $count - 1 ] ) ) {
			return 'paginated';
		}

		// Date archives: /2024/, /2024/05/, /2024/05/17/ (all numeric). A post
		// under a date permalink (/2024/05/my-post/) has a non-numeric segment.
		if ( $count <= 3 && preg_match( '/^\d{4}$/', $segments[0] ) ) {
			$all_numeric = true;
			for ( $i = 1; $i < $count; $i++ ) {
				if ( ! preg_match( '/^\d{1,2}$/', $segments[ $i ] ) ) {
					$all_numeric = false;
					break;
				}
			}
			if ( $all_numeric ) {
				return 'date';
			}
		}

		global $wp_rewrite;
		$author_base = ( $wp_rewrite && ! empty( $wp_rewrite->author_base ) ) ? (string) $wp_rewrite->author_base : 'author';
		if ( self::path_is_under( $path, $author_base ) ) {
			return 'author';
		}

		$category_base = trim( (string) get_option( 'category_base' ), '/' );
		if ( self::path_is_under( $path, '' !== $category_base ? $category_base : 'category' ) ) {
			return 'category';
		}
		$tag_base = trim( (string) get_option( 'tag_base' ), '/' );
		if ( self::path_is_under( $path, '' !== $tag_base ? $tag_base : 'tag' ) ) {
			return 'tag';
		}

		$wc_permalinks = get_option( 'woocommerce_permalinks', array() );
		$wc_permalinks = is_array( $wc_permalinks ) ? $wc_permalinks : array();
		$product_cat   = ! empty( $wc_permalinks['category_base'] ) ? trim( (string) $wc_permalinks['category_base'], '/' ) : 'product-category';
		$product_tag   = ! empty( $wc_permalinks['tag_base'] ) ? trim( (string) $wc_permalinks['tag_base'], '/' ) : 'product-tag';
		if ( self::path_is_under( $path, $product_cat ) ) {
			return 'category';
		}
		if ( self::path_is_under( $path, $product_tag ) ) {
			return 'tag';
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ) {
				continue;
			}
			$slug = ( is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['slug'] ) ) ? trim( (string) $taxonomy->rewrite['slug'], '/' ) : '';
			if ( '' !== $slug && self::path_is_under( $path, $slug ) ) {
				return 'taxonomy';
			}
		}

		foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $post_type ) {
			// The WooCommerce shop (product archive) is a page that matters.
			if ( empty( $post_type->has_archive ) || 'product' === $post_type->name ) {
				continue;
			}
			if ( is_string( $post_type->has_archive ) ) {
				$slug = $post_type->has_archive;
			} elseif ( is_array( $post_type->rewrite ) && ! empty( $post_type->rewrite['slug'] ) ) {
				$slug = $post_type->rewrite['slug'];
			} else {
				$slug = $post_type->name;
			}
			if ( strtolower( trim( (string) $slug, '/' ) ) === $path ) {
				return 'post_type_archive';
			}
		}

		return 'keep';
	}

	/**
	 * Whether $path is strictly below $base ("tag/x" under "tag", not "tag").
	 *
	 * @param string $path Lowercase path without slashes at the ends.
	 * @param string $base Base slug.
	 * @return bool
	 */
	private static function path_is_under( $path, $base ) {
		$base = strtolower( trim( (string) $base, '/' ) );
		return '' !== $base && strlen( $path ) > strlen( $base ) + 1 && 0 === strpos( $path, $base . '/' );
	}

	/**
	 * Ingest filter used by Opti_Behavior_Heatmap_Storage::save_heatmap_data().
	 * Fails open.
	 *
	 * @param string $url Page URL of the heatmap points.
	 * @return bool True when the points must be dropped.
	 */
	public static function should_skip_heatmap_url( $url ) {
		try {
			if ( '' === (string) $url || self::tracks_all_pages() ) {
				return false;
			}
			static $memo = array();
			$key = (string) $url;
			if ( ! isset( $memo[ $key ] ) ) {
				if ( count( $memo ) > 200 ) {
					$memo = array();
				}
				$memo[ $key ] = 'keep' !== self::classify_url( $key, true );
			}
			return $memo[ $key ];
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Prune decision: path-only classification, then a real post/page at that
	 * URL always wins.
	 *
	 * @param string $url Stored row URL.
	 * @return string
	 */
	public static function classify_for_prune( $url ) {
		$reason = self::classify_url( (string) $url, false );
		if ( 'keep' === $reason || in_array( $reason, array( 'paginated', 'date' ), true ) ) {
			return $reason;
		}
		$clean = strtok( (string) $url, '?#' );
		if ( is_string( $clean ) && '' !== $clean && function_exists( 'url_to_postid' ) && url_to_postid( $clean ) > 0 ) {
			return 'keep';
		}
		return $reason;
	}

	// ─── Migration ──────────────────────────────────────────────────────────

	/**
	 * One-time migration. Runs on admin screens / cron / WP-CLI only (never on
	 * frontend beacons), exactly once thanks to add_option().
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		try {
			if ( defined( 'OPTI_BEHAVIOR_SKIP_PAGE_PRUNE_MIGRATION' ) && OPTI_BEHAVIOR_SKIP_PAGE_PRUNE_MIGRATION ) {
				return;
			}
			if ( get_option( self::MIGRATED_OPTION ) ) {
				return;
			}
			if ( wp_doing_ajax() || ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
				return;
			}
			// add_option() fails when another request created it first.
			if ( ! add_option( self::MIGRATED_OPTION, (string) time(), '', 'yes' ) ) {
				return;
			}
			self::run_migration();
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		}
	}

	/**
	 * @return void
	 */
	private static function run_migration() {
		$options  = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		$previous = ( is_array( $options ) && isset( $options['report_non_singular'] ) ) ? (int) $options['report_non_singular'] : null;
		$has_data = self::has_heatmap_rows();

		self::set_track_all( false );

		$state              = self::read_fresh_state();
		$state['migration'] = array(
			'at'       => current_time( 'mysql' ),
			'previous' => $previous,
			'had_data' => $has_data,
		);
		if ( $has_data ) {
			$state['notice'] = 'show';
			if ( self::auto_prune_enabled() ) {
				self::reset_pass( $state );
				$state['status'] = 'pending';
				self::save_state( $state );
				self::schedule( self::HOOK, 2 * MINUTE_IN_SECONDS );
				return;
			}
		}
		self::save_state( $state );
	}

	/**
	 * @return bool
	 */
	private static function auto_prune_enabled() {
		/**
		 * Disable the automatic archive-page heatmap prune after the 1.9.5
		 * migration (the admin can still start it from the settings card).
		 *
		 * @since 1.9.5
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'opti_behavior_auto_prune_heatmaps', true );
	}

	// ─── Prune tick ─────────────────────────────────────────────────────────

	/**
	 * One time-boxed prune tick.
	 *
	 * @return array Tick summary.
	 */
	public static function run_tick() {
		$summary = array(
			'scanned'  => 0,
			'archived' => 0,
			'kept'     => 0,
			'popular'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'status'   => '',
			'notes'    => array(),
		);
		$lock = null;

		try {
			$state             = self::read_fresh_state();
			$summary['status'] = $state['status'];
			if ( ! in_array( $state['status'], array( 'pending', 'running' ), true ) ) {
				return $summary;
			}

			if ( self::tracks_all_pages() ) {
				if ( ! empty( $state['migration'] ) && empty( $state['user_choice_at'] ) ) {
					// The migration's value was overwritten by something other than
					// the admin (e.g. an old in-memory options copy): re-apply.
					self::set_track_all( false );
				} else {
					$state['status'] = 'cancelled';
					self::save_state( $state );
					$summary['status'] = 'cancelled';
					return $summary;
				}
			}

			if ( self::other_job_active() ) {
				$state['last_defer'] = array(
					'at'     => current_time( 'mysql' ),
					'reason' => 'rebuild_or_restore',
				);
				self::save_state( $state );
				self::schedule( self::HOOK, 10 * MINUTE_IN_SECONDS );
				$summary['notes'][] = __( 'Deferred: a heatmap registry rebuild or archive restore is running.', 'opti-behavior' );
				self::report_to_registry( $summary, true );
				return $summary;
			}

			$lock = self::acquire_lock();
			if ( false === $lock ) {
				self::schedule( self::HOOK, MINUTE_IN_SECONDS );
				return $summary;
			}

			global $wpdb;
			$table   = $wpdb->prefix . 'optibehavior_heatmap_pages';
			$columns = self::table_columns( $table );
			$storage = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;
			if ( empty( $columns ) || ! $storage || ! method_exists( $storage, 'archive_hash_dir_to_orphaned' ) ) {
				$state['status']      = 'done';
				$state['finished_at'] = current_time( 'mysql' );
				self::save_state( $state );
				$summary['status'] = 'done';
				return $summary;
			}

			$select = 'id, page_id, url_hash, url, created_at, click_count, move_count, scroll_count';
			foreach ( array( 'agg_sessions', 'agg_canonical_sessions', 'agg_synced_at' ) as $column ) {
				if ( isset( $columns[ $column ] ) ) {
					$select .= ', ' . $column;
				}
			}
			$threshold = max( 1, (int) apply_filters( 'opti_behavior_page_prune_keep_sessions', self::KEEP_SESSIONS ) );
			$deadline  = microtime( true ) + self::time_budget();
			$expected  = $state['status'];

			if ( 'pending' === $state['status'] && 0 === (int) $state['cursor'] ) {
				self::reset_pass( $state );
			}
			$state['status']     = 'running';
			$state['last_run']   = current_time( 'mysql' );
			$state['last_defer'] = array();
			if ( empty( $state['started_at'] ) ) {
				$state['started_at'] = current_time( 'mysql' );
			}
			if ( ! self::commit_tick_state( $state, $expected ) ) {
				$summary['status'] = $state['status'];
				return $summary;
			}

			$removed_any = false;
			$pass_done   = false;
			while ( microtime( true ) < $deadline ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table, columns from INFORMATION_SCHEMA allow-list.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $state['cursor'], self::BATCH_SIZE ) );
				if ( empty( $rows ) ) {
					$pass_done = true;
					break;
				}

				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) {
						break;
					}
					$state['cursor'] = (int) $row->id;
					++$summary['scanned'];

					$outcome = self::prune_row( $row, $columns, $threshold, $storage, $table );
					if ( 'stop' === $outcome ) {
						$summary['notes'][] = __( 'The archive manifest could not be written (uploads folder not writable). Nothing else was archived; retrying in one hour.', 'opti-behavior' );
						$state['last_error'] = 'manifest not writable';
						$state['cursor']     = max( 0, (int) $row->id - 1 );
						$state['status']     = 'pending';
						self::schedule( self::HOOK, HOUR_IN_SECONDS );
						++$summary['failed'];
						break 2;
					}
					$map = array(
						'archived' => array( 'archived', 'archived' ),
						'kept'     => array( 'kept', 'pass_kept' ),
						'popular'  => array( 'popular', 'pass_popular' ),
						'deferred' => array( 'deferred', 'pass_deferred' ),
						'failed'   => array( 'failed', 'failed' ),
					);
					if ( isset( $map[ $outcome ] ) ) {
						++$summary[ $map[ $outcome ][0] ];
						$state[ $map[ $outcome ][1] ] = (int) $state[ $map[ $outcome ][1] ] + 1;
					}
					if ( 'archived' === $outcome ) {
						$removed_any = true;
					}
				}

				if ( ! self::commit_tick_state( $state, $expected ) ) {
					break; // Admin changed the job (restore / keep all / purge).
				}
			}

			if ( $removed_any && function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}

			if ( 'running' === $state['status'] ) {
				if ( $pass_done ) {
					self::finish_pass( $state, $summary );
				} else {
					self::schedule( self::HOOK, MINUTE_IN_SECONDS );
				}
			}
			self::commit_tick_state( $state, $expected );
			$summary['status'] = $state['status'];
			self::report_to_registry( $summary, 'running' === $state['status'] || 'pending' === $state['status'] );
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		} finally {
			self::release_lock( $lock );
		}

		return $summary;
	}

	/**
	 * Decide and act on one heatmap_pages row.
	 *
	 * @param object                        $row       Row.
	 * @param array                         $columns   Existing columns (flipped).
	 * @param int                           $threshold Keep-sessions threshold.
	 * @param Opti_Behavior_Heatmap_Storage $storage   Storage.
	 * @param string                        $table     Table name.
	 * @return string archived|kept|popular|deferred|failed|stop
	 */
	private static function prune_row( $row, array $columns, $threshold, $storage, $table ) {
		global $wpdb;

		$reason = self::classify_for_prune( (string) $row->url );
		if ( 'keep' === $reason ) {
			return 'kept';
		}

		if ( ! isset( $columns['agg_sessions'] ) ) {
			return 'deferred'; // Old schema: traffic unknown — keep.
		}
		$sessions = 0;
		foreach ( array( 'agg_sessions', 'agg_canonical_sessions' ) as $column ) {
			if ( isset( $row->$column ) ) {
				$sessions = max( $sessions, (int) $row->$column );
			}
		}
		if ( $sessions >= $threshold ) {
			return 'popular';
		}
		$synced = isset( $columns['agg_synced_at'] ) && ! empty( $row->agg_synced_at );
		if ( ! $synced ) {
			return 'deferred'; // Session count not computed yet — retry next pass.
		}

		$hash = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) $row->url_hash ) );
		if ( 32 !== strlen( $hash ) ) {
			return 'failed';
		}
		$dest = $hash . '-' . gmdate( 'YmdHis' );

		// Manifest first: whatever happens next, a restore can find the data.
		$entry = array(
			'h' => $hash,
			'd' => $dest,
			'p' => (int) $row->page_id,
			'u' => (string) $row->url,
			'c' => (string) $row->created_at,
			'k' => (int) $row->click_count,
			'm' => (int) $row->move_count,
			's' => (int) $row->scroll_count,
			'r' => $reason,
			't' => time(),
		);
		if ( ! self::append_manifest( $entry ) ) {
			return 'stop';
		}

		$moved = $storage->archive_hash_dir_to_orphaned( $hash, $dest );
		if ( false === $moved ) {
			return 'failed'; // Row and data untouched.
		}

		$deleted = $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; single-row delete by primary key, nothing cached.
		if ( false === $deleted ) {
			if ( '' !== $moved ) {
				$orphan_dir = self::orphan_dir();
				self::move_dir_back( $orphan_dir . $moved, self::data_dir() . $hash );
			}
			return 'failed';
		}

		wp_cache_delete( 'opti_url_hash_' . (int) $row->page_id, 'opti-behavior' );
		wp_cache_delete( 'opti_url_hashes_' . (int) $row->page_id, 'opti-behavior' );
		return 'archived';
	}

	/**
	 * End of one full pass over the table.
	 *
	 * @param array $state   State (by ref).
	 * @param array $summary Summary (by ref).
	 * @return void
	 */
	private static function finish_pass( array &$state, array &$summary ) {
		$state['passes'] = (int) $state['passes'] + 1;
		$state['cursor'] = 0;

		if ( (int) $state['pass_deferred'] > 0 && $state['passes'] < self::MAX_PASSES ) {
			$state['status']       = 'pending';
			$state['next_pass_at'] = time() + DAY_IN_SECONDS;
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_single_event( time() + DAY_IN_SECONDS, self::HOOK );
			/* translators: %d: number of archive-page heatmaps whose session count is not computed yet */
			$summary['notes'][] = sprintf( __( '%d archive-page heatmap(s) wait for their session count — checked again tomorrow.', 'opti-behavior' ), (int) $state['pass_deferred'] );
		} else {
			$state['status']       = 'done';
			$state['finished_at']  = current_time( 'mysql' );
			$state['next_pass_at'] = 0;
		}

		if ( (int) $state['archived'] > 0 ) {
			if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
				$core     = Opti_Behavior_Heatmap_Core::get_instance();
				$database = ( $core && method_exists( $core, 'get_database' ) ) ? $core->get_database() : null;
				if ( $database && method_exists( $database, 'purge_orphaned_heatmap_daily_rows' ) ) {
					$database->purge_orphaned_heatmap_daily_rows();
				}
			}
			if ( class_exists( 'Opti_Behavior_DB_Schema_Migration' ) && method_exists( 'Opti_Behavior_DB_Schema_Migration', 'request_optimize' ) ) {
				global $wpdb;
				Opti_Behavior_DB_Schema_Migration::request_optimize( array( $wpdb->prefix . 'optibehavior_heatmap_pages', $wpdb->prefix . 'optibehavior_heatmap_daily' ) );
			}
		}
	}

	/**
	 * Reset per-pass counters.
	 *
	 * @param array $state State (by ref).
	 * @return void
	 */
	private static function reset_pass( array &$state ) {
		$state['cursor']        = 0;
		$state['pass_kept']     = 0;
		$state['pass_popular']  = 0;
		$state['pass_deferred'] = 0;
		$state['last_error']    = '';
	}

	// ─── Restore / purge ticks ──────────────────────────────────────────────

	/**
	 * Replay the manifest: move archived dirs back, re-insert mapping rows.
	 *
	 * @return array Summary.
	 */
	public static function run_restore_tick() {
		$summary = array(
			'restored' => 0,
			'missing'  => 0,
			'status'   => '',
		);
		$lock = null;

		try {
			$state             = self::read_fresh_state();
			$summary['status'] = $state['status'];
			if ( 'restoring' !== $state['status'] ) {
				return $summary;
			}
			$lock = self::acquire_lock();
			if ( false === $lock ) {
				self::schedule( self::RESTORE_HOOK, MINUTE_IN_SECONDS );
				return $summary;
			}

			global $wpdb;
			$table      = $wpdb->prefix . 'optibehavior_heatmap_pages';
			$has_table  = ! empty( self::table_columns( $table ) );
			$data_dir   = self::data_dir();
			$orphan_dir = self::orphan_dir();
			$page_ids   = array();
			$expected   = $state['status'];
			$deadline   = microtime( true ) + self::time_budget();

			$result = self::iterate_manifest(
				(int) $state['offset'],
				$deadline,
				function ( array $entry ) use ( &$summary, &$page_ids, $data_dir, $orphan_dir, $table, $has_table, $wpdb ) {
					$hash = isset( $entry['h'] ) ? strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) $entry['h'] ) ) : '';
					$name = isset( $entry['d'] ) ? (string) $entry['d'] : '';
					if ( 32 !== strlen( $hash ) || ! preg_match( self::ARCHIVE_NAME_PATTERN, $name ) || 0 !== strpos( $name, $hash ) ) {
						++$summary['missing'];
						return;
					}
					$source = $orphan_dir . $name;
					if ( is_link( $source ) || ! is_dir( $source ) ) {
						++$summary['missing']; // Already purged or already restored.
						return;
					}
					if ( ! self::move_dir_back( $source, $data_dir . $hash ) ) {
						++$summary['missing'];
						return;
					}
					if ( $has_table ) {
						$now = current_time( 'mysql' );
						// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table.
						$wpdb->query(
							$wpdb->prepare(
								"INSERT IGNORE INTO {$table} (page_id, url_hash, url, created_at, updated_at, click_count, move_count, scroll_count, last_data_at) VALUES (%d, %s, %s, %s, %s, %d, %d, %d, %s)",
								isset( $entry['p'] ) ? (int) $entry['p'] : 0,
								$hash,
								isset( $entry['u'] ) ? (string) $entry['u'] : '',
								! empty( $entry['c'] ) ? (string) $entry['c'] : $now,
								$now,
								isset( $entry['k'] ) ? (int) $entry['k'] : 0,
								isset( $entry['m'] ) ? (int) $entry['m'] : 0,
								isset( $entry['s'] ) ? (int) $entry['s'] : 0,
								$now
							)
						);
						// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
					}
					$page_ids[] = isset( $entry['p'] ) ? (int) $entry['p'] : 0;
					++$summary['restored'];
				}
			);

			$state['offset']   = (int) $result['offset'];
			$state['restored'] = (int) $state['restored'] + $summary['restored'];
			$state['last_run'] = current_time( 'mysql' );

			$page_ids = array_values( array_unique( array_filter( $page_ids ) ) );
			if ( $page_ids && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
				$storage = Opti_Behavior_Heatmap_Storage::get_instance();
				if ( $storage && method_exists( $storage, 'mark_pages_stale' ) ) {
					$storage->mark_pages_stale( $page_ids );
				}
			}
			if ( $summary['restored'] > 0 && function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}

			if ( ! empty( $result['eof'] ) ) {
				$state['status']      = 'restored';
				$state['finished_at'] = current_time( 'mysql' );
				$state['offset']      = 0;
				self::delete_manifest();
			} else {
				self::schedule( self::RESTORE_HOOK, MINUTE_IN_SECONDS );
			}
			self::commit_tick_state( $state, $expected );
			$summary['status'] = $state['status'];
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		} finally {
			self::release_lock( $lock );
		}

		return $summary;
	}

	/**
	 * "Delete archived data now": remove every archive dir listed in the manifest.
	 *
	 * @return array Summary.
	 */
	public static function run_purge_tick() {
		$summary = array(
			'purged' => 0,
			'status' => '',
		);
		$lock = null;

		try {
			$state             = self::read_fresh_state();
			$summary['status'] = $state['status'];
			if ( 'purging' !== $state['status'] ) {
				return $summary;
			}
			$lock = self::acquire_lock();
			if ( false === $lock ) {
				self::schedule( self::PURGE_HOOK, MINUTE_IN_SECONDS );
				return $summary;
			}

			$orphan_dir = self::orphan_dir();
			$expected   = $state['status'];
			$result     = self::iterate_manifest(
				(int) $state['offset'],
				microtime( true ) + self::time_budget(),
				function ( array $entry ) use ( &$summary, $orphan_dir ) {
					$name = isset( $entry['d'] ) ? (string) $entry['d'] : '';
					if ( self::delete_archive_dir( $orphan_dir, $name ) ) {
						++$summary['purged'];
					}
				}
			);

			$state['offset']   = (int) $result['offset'];
			$state['purged']   = (int) $state['purged'] + $summary['purged'];
			$state['last_run'] = current_time( 'mysql' );
			if ( ! empty( $result['eof'] ) ) {
				$state['status']      = 'purged';
				$state['finished_at'] = current_time( 'mysql' );
				$state['offset']      = 0;
				self::delete_manifest();
			} else {
				self::schedule( self::PURGE_HOOK, MINUTE_IN_SECONDS );
			}
			self::commit_tick_state( $state, $expected );
			$summary['status'] = $state['status'];
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		} finally {
			self::release_lock( $lock );
		}

		return $summary;
	}

	// ─── Admin UI ───────────────────────────────────────────────────────────

	/**
	 * admin-post handler for every card / notice button.
	 *
	 * @return void
	 */
	public static function handle_admin_action() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( 0 !== strpos( $action, self::ACTION_PREFIX ) ) {
			wp_die( esc_html__( 'Invalid request.', 'opti-behavior' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}
		check_admin_referer( $action );
		$command = substr( $action, strlen( self::ACTION_PREFIX ) );

		try {
			$state      = self::read_fresh_state();
			$run_inline = false;
			switch ( $command ) {
				case 'keep_all':
					self::set_track_all( true );
					$state['user_choice_at'] = current_time( 'mysql' );
					$state['notice']         = 'dismissed';
					self::start_restore( $state, 'cancelled' );
					break;
				case 'dismiss':
					$state['notice'] = 'dismissed';
					break;
				case 'restore':
					self::start_restore( $state, $state['status'] );
					break;
				case 'optimize':
				case 'continue':
					if ( ! self::tracks_all_pages() && ! in_array( $state['status'], array( 'restoring', 'purging' ), true ) ) {
						if ( 'optimize' === $command || ! in_array( $state['status'], array( 'pending', 'running' ), true ) ) {
							self::reset_pass( $state );
							$state['passes'] = 0;
						}
						$state['status']       = 'pending';
						$state['next_pass_at'] = 0;
						self::save_state( $state );
						wp_clear_scheduled_hook( self::HOOK );
						// Process one batch in this request: works even where WP-Cron
						// never fires. The tick schedules its own continuation.
						self::run_tick();
						$run_inline = true;
					}
					break;
				case 'purge':
					if ( self::manifest_has_entries() && ! in_array( $state['status'], array( 'running', 'restoring' ), true ) ) {
						wp_clear_scheduled_hook( self::HOOK );
						$state['status'] = 'purging';
						$state['offset'] = 0;
						self::schedule( self::PURGE_HOOK, 0 );
					}
					break;
			}
			if ( ! $run_inline ) {
				self::save_state( $state );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		}

		wp_safe_redirect( add_query_arg( 'opti_page_prune', $command, self::settings_url() ) . '#' . self::CARD_ID );
		exit;
	}

	/**
	 * @param array  $state          State (by ref).
	 * @param string $status_if_none Status when there is nothing to restore.
	 * @return void
	 */
	private static function start_restore( array &$state, $status_if_none ) {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
		if ( self::manifest_has_entries() ) {
			$state['status']   = 'restoring';
			$state['offset']   = 0;
			$state['restored'] = 0;
			self::schedule( self::RESTORE_HOOK, 0 );
			return;
		}
		if ( in_array( $state['status'], array( 'pending', 'running' ), true ) ) {
			$state['status'] = $status_if_none;
		}
	}

	/**
	 * One-time admin notice after the migration.
	 *
	 * @return void
	 */
	public static function render_notice() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$state = self::get_state();
			if ( 'show' !== $state['notice'] ) {
				return;
			}
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = $screen ? (string) $screen->id : '';
			if ( false === strpos( $screen_id, 'opti-behavior' ) && ! in_array( $screen_id, array( 'dashboard', 'plugins' ), true ) ) {
				return;
			}
			$days = self::archive_retention_days();
			?>
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Opti-Behavior: heatmaps now focus on the pages that matter.', 'opti-behavior' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'Heatmaps are recorded for posts, pages, products, the home page and the shop. Heatmaps of tag, category, author, date, search and paginated archive pages are archived in the background; archive pages with 100 or more sessions are kept. Visits on every page are still counted.', 'opti-behavior' ); ?>
					<?php
					if ( $days > 0 ) {
						/* translators: %d: number of days archived heatmap data stays restorable */
						echo esc_html( sprintf( __( 'Archived heatmaps can be restored for %d days.', 'opti-behavior' ), $days ) );
					} else {
						esc_html_e( 'Archived heatmaps stay restorable until you delete them.', 'opti-behavior' );
					}
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::settings_url() . '#' . self::CARD_ID ); ?>"><?php esc_html_e( 'View progress', 'opti-behavior' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'keep_all' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Track heatmaps on all pages again and restore the archived heatmaps?', 'opti-behavior' ) ); ?>');"><?php esc_html_e( 'Keep tracking all pages', 'opti-behavior' ); ?></a>
					<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( self::action_url( 'dismiss' ) ); ?>"><?php esc_html_e( 'Dismiss', 'opti-behavior' ); ?></a>
				</p>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		}
	}

	/**
	 * Status card on Settings → Data Collection (under "Non-Singular Pages").
	 *
	 * @return void
	 */
	public static function render_card() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$state        = self::get_state();
			$status       = (string) $state['status'];
			$tracks_all   = self::tracks_all_pages();
			$has_manifest = self::manifest_has_entries();
			$days         = self::archive_retention_days();
			$date_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$writes       = $tracks_all ? null : self::recent_archive_writes( $state );
			$cron_note    = $tracks_all ? '' : self::cron_note( $state, $date_format );

			$labels = array(
				'idle'      => __( 'No cleanup has run on this site.', 'opti-behavior' ),
				'pending'   => __( 'Queued — runs in the background in small batches.', 'opti-behavior' ),
				'running'   => __( 'Running in the background.', 'opti-behavior' ),
				'done'      => __( 'Finished.', 'opti-behavior' ),
				'cancelled' => __( 'Stopped — heatmaps are tracked on all pages.', 'opti-behavior' ),
				'restoring' => __( 'Restoring archived heatmaps in the background.', 'opti-behavior' ),
				'restored'  => __( 'Archived heatmaps restored.', 'opti-behavior' ),
				'purging'   => __( 'Deleting archived heatmap data in the background.', 'opti-behavior' ),
				'purged'    => __( 'Archived heatmap data deleted.', 'opti-behavior' ),
			);
			$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['idle'];
			if ( 'pending' === $status && ! empty( $state['next_pass_at'] ) ) {
				/* translators: %s: date and time of the next pass */
				$label = sprintf( __( 'Waiting for session counts — next check on %s.', 'opti-behavior' ), wp_date( $date_format, (int) $state['next_pass_at'] ) );
			}
			if ( ! empty( $state['finished_at'] ) && in_array( $status, array( 'done', 'restored', 'purged' ), true ) ) {
				$label .= ' ' . mysql2date( $date_format, (string) $state['finished_at'] );
			}
			?>
			<div id="<?php echo esc_attr( self::CARD_ID ); ?>" class="setting-item opti-behavior-page-prune-card" style="display:block;border-left:3px solid #6366f1;padding-left:16px">
				<div class="setting-label">
					<span class="label-text"><?php esc_html_e( 'Archive-page heatmap cleanup', 'opti-behavior' ); ?></span>
					<span class="label-description">
						<?php esc_html_e( 'Archives heatmaps of tag, category, author, date, search and paginated pages (100+ sessions are kept). Visit statistics are never touched.', 'opti-behavior' ); ?>
						<?php
						if ( $days > 0 ) {
							/* translators: %d: number of days */
							echo esc_html( sprintf( __( 'Archived data stays restorable for %d days, then is deleted automatically.', 'opti-behavior' ), $days ) );
						}
						?>
					</span>
				</div>
				<p style="margin:8px 0"><strong><?php echo esc_html( $label ); ?></strong></p>
				<?php if ( 'idle' !== $status ) : ?>
					<p style="margin:4px 0">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: archived heatmaps, 2: kept popular archive pages, 3: waiting for session count */
								__( 'Archived: %1$d · Kept (100+ sessions): %2$d · Waiting for session count: %3$d', 'opti-behavior' ),
								(int) $state['archived'],
								(int) $state['pass_popular'],
								(int) $state['pass_deferred']
							)
						);
						if ( (int) $state['restored'] > 0 ) {
							/* translators: %d: restored heatmaps */
							echo ' · ' . esc_html( sprintf( __( 'Restored: %d', 'opti-behavior' ), (int) $state['restored'] ) );
						}
						?>
					</p>
				<?php endif; ?>
				<?php if ( $tracks_all ) : ?>
					<p style="margin:4px 0"><?php esc_html_e( '"Track All Pages" is selected: archive-page heatmaps are recorded. Choose "Posts & Pages Only" and save to enable the cleanup.', 'opti-behavior' ); ?></p>
				<?php endif; ?>
				<?php if ( is_array( $writes ) && $writes['count'] > 0 ) : ?>
					<div class="notice notice-warning inline" style="margin:8px 0"><p>
						<?php
						$latest = $writes['examples'][0];
						echo esc_html(
							sprintf(
								/* translators: 1: number of archive-page heatmaps, 2: switch date, 3: latest URL, 4: latest write date */
								__( '%1$d archive-page heatmap(s) still received new data after the switch to "Posts & Pages Only" on %2$s (latest: %3$s, %4$s). This server is not running the updated heatmap filter — usually PHP OPcache still serves the old plugin files. Clear OPcache or restart PHP (or re-install the plugin from Plugins → Add New → Upload), then reload this page.', 'opti-behavior' ),
								(int) $writes['count'],
								mysql2date( $date_format, $writes['since'] ),
								(string) $latest->url,
								mysql2date( $date_format, (string) $latest->last_data_at )
							)
						);
						?>
					</p></div>
				<?php elseif ( is_array( $writes ) ) : ?>
					<p style="margin:4px 0;color:#15803d">
						<?php
						/* translators: %s: switch date */
						echo esc_html( sprintf( __( 'Heatmap filter active: no archive-page heatmap received data since %s.', 'opti-behavior' ), mysql2date( $date_format, $writes['since'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $cron_note ) : ?>
					<p style="margin:4px 0;color:#b45309"><?php echo esc_html( $cron_note ); ?></p>
				<?php endif; ?>
				<p style="margin:8px 0 0">
					<?php if ( ! $tracks_all && in_array( $status, array( 'pending', 'running' ), true ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'continue' ) ); ?>"><?php esc_html_e( 'Continue now', 'opti-behavior' ); ?></a>
					<?php endif; ?>
					<?php if ( ! $tracks_all && ! in_array( $status, array( 'restoring', 'purging' ), true ) ) : ?>
						<?php // Also offered while pending/running: `optimize` restarts the pass from cursor 0 (see handle_admin_action()), which is the only way to recover a stalled or half-finished run without WP-Cron. ?>
						<a class="button" href="<?php echo esc_url( self::action_url( 'optimize' ) ); ?>"><?php esc_html_e( 'Run cleanup now', 'opti-behavior' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_manifest && ! in_array( $status, array( 'restoring', 'purging' ), true ) ) : ?>
						<a class="button" href="<?php echo esc_url( self::action_url( 'restore' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Restore every archived heatmap?', 'opti-behavior' ) ); ?>');"><?php esc_html_e( 'Restore archived heatmaps', 'opti-behavior' ); ?></a>
					<?php endif; ?>
					<?php if ( ! in_array( $status, array( 'restoring', 'purging' ), true ) ) : ?>
						<?php // Opt-out lives on the card too, and independently of the current tracking mode: the one-time notice is dismissable (and gone after the first dismiss), so this is the only durable way back to full tracking + a restore of everything the prune archived. Idempotent when the site already tracks all pages. ?>
						<a class="button opti-behavior-page-prune-keep-all" href="<?php echo esc_url( self::action_url( 'keep_all' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Track heatmaps on all pages again and restore the archived heatmaps?', 'opti-behavior' ) ); ?>');"><?php esc_html_e( 'Keep tracking all pages', 'opti-behavior' ); ?></a>
					<?php endif; ?>
					<?php if ( 'dismissed' !== (string) $state['notice'] ) : ?>
						<?php // The migration notice can also be silenced from the card, so a user who never saw (or already navigated past) the one-shot notice is not stuck with it. ?>
						<a class="button-link opti-behavior-page-prune-dismiss" style="margin-left:8px" href="<?php echo esc_url( self::action_url( 'dismiss' ) ); ?>"><?php esc_html_e( 'Dismiss', 'opti-behavior' ); ?></a>
					<?php endif; ?>
					<?php if ( $has_manifest && in_array( $status, array( 'done', 'cancelled', 'pending' ), true ) ) : ?>
						<a class="button button-link-delete" href="<?php echo esc_url( self::action_url( 'purge' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete the archived heatmap data? This cannot be undone.', 'opti-behavior' ) ); ?>');"><?php esc_html_e( 'Delete archived data now', 'opti-behavior' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		}
	}

	/**
	 * Archive-page heatmaps that received data after the migration switched the
	 * setting — should be none when the ingest filter is live on this server.
	 *
	 * @param array $state State.
	 * @return array|null { since, count, examples[] } or null when unknown.
	 */
	private static function recent_archive_writes( array $state ) {
		global $wpdb;
		$since = ( ! empty( $state['migration'] ) && is_array( $state['migration'] ) && ! empty( $state['migration']['at'] ) ) ? (string) $state['migration']['at'] : '';
		if ( '' === $since ) {
			return null;
		}
		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		if ( ! isset( self::table_columns( $table )['last_data_at'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table; indexed on last_data_at.
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT url, last_data_at FROM {$table} WHERE last_data_at > %s ORDER BY last_data_at DESC LIMIT 500", $since ) );
		$count    = 0;
		$examples = array();
		foreach ( (array) $rows as $row ) {
			if ( 'keep' === self::classify_url( (string) $row->url, false ) ) {
				continue;
			}
			++$count;
			if ( count( $examples ) < 3 ) {
				$examples[] = $row;
			}
		}
		return array(
			'since'    => $since,
			'count'    => $count,
			'examples' => $examples,
		);
	}

	/**
	 * Why a queued cleanup is not progressing ('' when it is fine).
	 *
	 * @param array  $state       State.
	 * @param string $date_format Date format.
	 * @return string
	 */
	private static function cron_note( array $state, $date_format ) {
		if ( ! in_array( $state['status'], array( 'pending', 'running' ), true ) ) {
			return '';
		}
		if ( ! empty( $state['last_defer']['at'] ) ) {
			/* translators: %s: date of the last attempt */
			return sprintf( __( 'Waiting for a heatmap registry rebuild or archive restore to finish (last attempt %s). Retries every 10 minutes.', 'opti-behavior' ), mysql2date( $date_format, (string) $state['last_defer']['at'] ) );
		}
		// A batch ran in the last 10 minutes: it is progressing. While a cron
		// batch runs WordPress has already removed its event, so "no event"
		// alone is not a problem.
		if ( self::ran_recently( $state ) ) {
			return '';
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && $next >= time() - 10 * MINUTE_IN_SECONDS ) {
			return '';
		}
		return __( 'No batch ran in the last 10 minutes and WP-Cron has not started the next one (WP-Cron looks disabled or blocked on this site). The next batch was queued again; click "Continue now" to run it from this page, and repeat until it says Finished.', 'opti-behavior' );
	}

	/**
	 * Whether a batch ran in the last 10 minutes.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function ran_recently( array $state ) {
		return ! empty( $state['last_run'] ) && (int) mysql2date( 'U', (string) $state['last_run'] ) > time() - 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * Self-heal on Opti-Behavior admin screens: a job left pending/running
	 * without its cron event (batch killed by a server time limit, event lost)
	 * gets its next batch queued again.
	 *
	 * @param WP_Screen|mixed $screen Current screen.
	 * @return void
	 */
	public static function maybe_heal_schedule( $screen ) {
		try {
			$screen_id = ( is_object( $screen ) && isset( $screen->id ) ) ? (string) $screen->id : '';
			if ( false === strpos( $screen_id, 'opti-behavior' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$state = self::get_state();
			$hooks = array(
				'pending'   => self::HOOK,
				'running'   => self::HOOK,
				'restoring' => self::RESTORE_HOOK,
				'purging'   => self::PURGE_HOOK,
			);
			if ( ! isset( $hooks[ $state['status'] ] ) ) {
				return;
			}
			$hook = $hooks[ $state['status'] ];
			if ( wp_next_scheduled( $hook ) || self::ran_recently( $state ) ) {
				return;
			}
			$at = ( self::HOOK === $hook && ! empty( $state['next_pass_at'] ) && (int) $state['next_pass_at'] > time() ) ? (int) $state['next_pass_at'] : time();
			wp_schedule_single_event( $at, $hook );
		} catch ( \Throwable $e ) {
			self::note_error( $e );
		}
	}

	/**
	 * Seconds one tick may work: the constant / filter, capped well below the
	 * PHP time limit so a batch always saves its cursor and queues the next one.
	 *
	 * @return int
	 */
	private static function time_budget() {
		$budget    = max( 3, (int) apply_filters( 'opti_behavior_page_prune_time_budget', self::TIME_BUDGET ) );
		$php_limit = (int) ini_get( 'max_execution_time' );
		if ( $php_limit > 0 ) {
			$budget = min( $budget, max( 3, $php_limit - 12 ) );
		}
		return $budget;
	}

	/**
	 * Lines for the Cleanup Tasks panel.
	 *
	 * @return string[]
	 */
	public static function describe() {
		$state = self::get_state();
		return array(
			/* translators: 1: status, 2: archived count */
			sprintf( __( 'Status: %1$s — %2$d archive-page heatmap(s) archived so far.', 'opti-behavior' ), $state['status'], (int) $state['archived'] ),
		);
	}

	// ─── Internals ──────────────────────────────────────────────────────────

	/**
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array_merge(
			array(
				'status'        => 'idle',
				'notice'        => '',
				'cursor'        => 0,
				'passes'        => 0,
				'archived'      => 0,
				'failed'        => 0,
				'pass_kept'     => 0,
				'pass_popular'  => 0,
				'pass_deferred' => 0,
				'restored'      => 0,
				'purged'        => 0,
				'offset'        => 0,
				'next_pass_at'  => 0,
				'last_defer'    => array(),
				'started_at'    => '',
				'last_run'      => '',
				'finished_at'   => '',
				'last_error'    => '',
			),
			$state
		);
	}

	/**
	 * State bypassing the per-request option cache (another request may have
	 * changed it while a tick runs).
	 *
	 * @return array
	 */
	private static function read_fresh_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );
		return self::get_state();
	}

	/**
	 * @param array $state State.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Save a tick's state without clobbering an admin action taken meanwhile.
	 *
	 * @param array  $state    State (by ref).
	 * @param string $expected Status this tick last saw in the DB (by ref).
	 * @return bool False when an admin action changed the job status.
	 */
	private static function commit_tick_state( array &$state, &$expected ) {
		$fresh = self::read_fresh_state();
		foreach ( array( 'notice', 'user_choice_at' ) as $key ) {
			if ( array_key_exists( $key, $fresh ) ) {
				$state[ $key ] = $fresh[ $key ];
			}
		}
		$interrupted = ( $fresh['status'] !== $expected );
		if ( $interrupted ) {
			foreach ( array( 'status', 'offset', 'restored', 'purged' ) as $key ) {
				$state[ $key ] = $fresh[ $key ];
			}
		}
		self::save_state( $state );
		$expected = $state['status'];
		return ! $interrupted;
	}

	/**
	 * @param \Throwable $e Error.
	 * @return void
	 */
	private static function note_error( $e ) {
		try {
			$state               = self::read_fresh_state();
			$state['last_error'] = substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 500 );
			self::save_state( $state );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * @param string $hook  Hook.
	 * @param int    $delay Seconds.
	 * @return void
	 */
	private static function schedule( $hook, $delay ) {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), $hook );
		}
	}

	/**
	 * @return bool
	 */
	private static function other_job_active() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) && method_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild', 'is_rebuild_active' ) && Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active() ) {
			return true;
		}
		if ( class_exists( 'Opti_Behavior_Heatmap_Orphan_Restore' ) && method_exists( 'Opti_Behavior_Heatmap_Orphan_Restore', 'is_recovery_active' ) && Opti_Behavior_Heatmap_Orphan_Restore::is_recovery_active() ) {
			return true;
		}
		return false;
	}

	/**
	 * @return string|false Lock state, or false when another tick holds it.
	 */
	private static function acquire_lock() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) ) {
			return 'none';
		}
		$lock = Opti_Behavior_Heatmap_DB_Lock::acquire( self::LOCK_NAME, 0 );
		return Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock ? false : $lock;
	}

	/**
	 * @param mixed $lock Value from acquire_lock().
	 * @return void
	 */
	private static function release_lock( $lock ) {
		if ( class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) && Opti_Behavior_Heatmap_DB_Lock::ACQUIRED === $lock ) {
			Opti_Behavior_Heatmap_DB_Lock::release( self::LOCK_NAME, $lock );
		}
	}

	/**
	 * @param string $table Full table name.
	 * @return array Column names (flipped); empty when the table is missing.
	 */
	private static function table_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $table ) );
		return is_array( $columns ) ? array_flip( $columns ) : array();
	}

	/**
	 * @return bool
	 */
	private static function has_heatmap_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		if ( empty( self::table_columns( $table ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table.
		return (bool) $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" );
	}

	/**
	 * @return string Heatmap data root (trailing slash) or ''.
	 */
	private static function data_dir() {
		$upload = wp_upload_dir( null, false );
		return empty( $upload['basedir'] ) ? '' : trailingslashit( $upload['basedir'] ) . 'opti-behavior-data/';
	}

	/**
	 * @return string `_orphaned/` root (trailing slash) or ''.
	 */
	private static function orphan_dir() {
		$dir = self::data_dir();
		return '' === $dir ? '' : $dir . '_orphaned/';
	}

	/**
	 * @return string
	 */
	public static function manifest_path() {
		$dir = self::orphan_dir();
		return '' === $dir ? '' : $dir . self::MANIFEST_FILE;
	}

	/**
	 * @return bool
	 */
	public static function manifest_has_entries() {
		$path = self::manifest_path();
		return '' !== $path && is_file( $path ) && filesize( $path ) > strlen( self::MANIFEST_HEADER );
	}

	/**
	 * @param array $entry Manifest entry.
	 * @return bool
	 */
	private static function append_manifest( array $entry ) {
		$path = self::manifest_path();
		if ( '' === $path ) {
			return false;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$line = wp_json_encode( $entry );
		if ( ! is_string( $line ) ) {
			return false;
		}
		$prefix = is_file( $path ) ? '' : self::MANIFEST_HEADER;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append with LOCK_EX; WP_Filesystem cannot append and is not guaranteed on cron.
		return false !== @file_put_contents( $path, $prefix . $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * @return void
	 */
	private static function delete_manifest() {
		$path = self::manifest_path();
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Walk manifest entries from a byte offset until EOF or the deadline.
	 *
	 * @param int      $offset   Byte offset.
	 * @param float    $deadline microtime deadline.
	 * @param callable $callback Receives each decoded entry.
	 * @return array { offset:int, eof:bool }
	 */
	private static function iterate_manifest( $offset, $deadline, $callback ) {
		$path = self::manifest_path();
		if ( '' === $path || ! is_file( $path ) ) {
			return array(
				'offset' => 0,
				'eof'    => true,
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed line read.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return array(
				'offset' => (int) $offset,
				'eof'    => false,
			);
		}
		$position = max( (int) $offset, strlen( self::MANIFEST_HEADER ) );
		fseek( $handle, $position );
		$eof = false;
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$position = (int) ftell( $handle );
			$entry    = json_decode( trim( $line ), true );
			if ( is_array( $entry ) ) {
				call_user_func( $callback, $entry );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'offset' => $position,
			'eof'    => $eof,
		);
	}

	/**
	 * Move an archived dir back to its live location. When live data exists
	 * again (tracking switched back on), merge session files without
	 * overwriting any live file.
	 *
	 * @param string $source Archive dir.
	 * @param string $target Live dir (no trailing slash).
	 * @return bool
	 */
	private static function move_dir_back( $source, $target ) {
		$source = rtrim( $source, '/\\' );
		$target = rtrim( $target, '/\\' );
		if ( ! is_dir( $source ) ) {
			return false;
		}
		if ( ! file_exists( $target ) ) {
			return @rename( $source, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem move.
		}
		$complete = true;
		foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
			$from = $source . '/' . $folder;
			if ( ! is_dir( $from ) ) {
				continue;
			}
			$to = $target . '/' . $folder;
			if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
				$complete = false;
				continue;
			}
			foreach ( (array) glob( $from . '/*' ) as $file ) {
				if ( ! is_string( $file ) || is_link( $file ) || ! is_file( $file ) ) {
					continue;
				}
				$destination = $to . '/' . basename( $file );
				if ( file_exists( $destination ) || ! @rename( $file, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
					$complete = false;
				}
			}
		}
		if ( $complete ) {
			self::delete_tree( $source, 0 );
		}
		return true;
	}

	/**
	 * Delete one prune archive dir (name + realpath containment checked).
	 *
	 * @param string $orphan_dir `_orphaned/` root.
	 * @param string $name       Archive dir name.
	 * @return bool
	 */
	private static function delete_archive_dir( $orphan_dir, $name ) {
		if ( '' === $orphan_dir || ! preg_match( self::ARCHIVE_NAME_PATTERN, (string) $name ) ) {
			return false;
		}
		$path = $orphan_dir . $name;
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return false;
		}
		$real_root = realpath( $orphan_dir );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path ) {
			return false;
		}
		$root_prefix = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real_path, $root_prefix ) || basename( $real_path ) !== $name ) {
			return false;
		}
		return self::delete_tree( $real_path, 0 );
	}

	/**
	 * Bounded recursive delete; symlinks are unlinked, never followed.
	 *
	 * @param string $dir   Directory.
	 * @param int    $depth Depth.
	 * @return bool
	 */
	private static function delete_tree( $dir, $depth ) {
		if ( $depth > 4 ) {
			return false;
		}
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}
		$ok = true;
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				$ok = @unlink( $path ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned archive tree.
			} else {
				$ok = self::delete_tree( $path, $depth + 1 ) && $ok;
			}
		}
		closedir( $handle );
		return $ok && @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned archive tree.
	}

	/**
	 * Days archived data stays in `_orphaned/` (0 = never purged automatically).
	 *
	 * @return int
	 */
	private static function archive_retention_days() {
		try {
			if ( class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
				$purge = new Opti_Behavior_Heatmap_Orphan_Purge();
				if ( method_exists( $purge, 'get_retention_days' ) ) {
					return max( 0, (int) $purge->get_retention_days() );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return 0;
	}

	/**
	 * @return string
	 */
	private static function settings_url() {
		return admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=data-collection' );
	}

	/**
	 * @param string $command Command.
	 * @return string
	 */
	private static function action_url( $command ) {
		$action = self::ACTION_PREFIX . $command;
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	/**
	 * Feed the Cleanup Tasks history.
	 *
	 * @param array $summary Tick summary.
	 * @param bool  $partial More work pending.
	 * @return void
	 */
	private static function report_to_registry( array $summary, $partial ) {
		if ( ! class_exists( 'Opti_Behavior_Cleanup_Task_Registry' ) || ! method_exists( 'Opti_Behavior_Cleanup_Task_Registry', 'report_run' ) ) {
			return;
		}
		$note = sprintf(
			/* translators: 1: checked, 2: archived, 3: kept popular, 4: waiting */
			__( 'Checked %1$d heatmap(s): %2$d archive-page heatmap(s) archived, %3$d kept (100+ sessions), %4$d waiting for their session count.', 'opti-behavior' ),
			$summary['scanned'],
			$summary['archived'],
			$summary['popular'],
			$summary['deferred']
		);
		Opti_Behavior_Cleanup_Task_Registry::report_run(
			array(
				'status' => $partial ? 'partial' : ( $summary['archived'] > 0 ? 'completed' : 'skipped' ),
				'note'   => $note,
				'notes'  => $summary['notes'],
			)
		);
	}
}
