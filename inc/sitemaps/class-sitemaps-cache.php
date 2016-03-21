<?php
/**
 * @package WPSEO\XML_Sitemaps
 */

/**
 * Handles sitemaps caching and invalidation.
 */
class WPSEO_Sitemaps_Cache {

	/** @var array $cache_clear Holds the options that, when updated, should cause the cache to clear. */
	protected static $cache_clear = array();

	/** @var string Prefix of the transient key for sitemap caches */
	const STORAGE_KEY_PREFIX = 'yst_sm_';

	/** Sitemap index indentifier */
	const SITEMAP_INDEX_TYPE = '1';

	/** Name of the option that holds the global validation value */
	const VALIDATION_GLOBAL_KEY = 'wpseo_sitemap_cache_validator_global';

	/** The format which creates the key of the option that holds the type validation value */
	const VALIDATION_TYPE_KEY_FORMAT = 'wpseo_sitemap_%s_cache_validator';

	/**
	 * Hook methods for invalidation on necessary events.
	 */
	public function __construct() {

		add_action( 'deleted_term_relationships', array( __CLASS__, 'invalidate' ) );

		add_action( 'update_option', array( __CLASS__, 'clear_on_option_update' ) );

		add_action( 'edited_terms', array( __CLASS__, 'invalidate_helper' ), 10, 2 );
		add_action( 'clean_term_cache', array( __CLASS__, 'invalidate_helper' ), 10, 2 );
		add_action( 'clean_object_term_cache', array( __CLASS__, 'invalidate_helper' ), 10, 2 );

		add_action( 'save_post', array( __CLASS__, 'invalidate_post' ) );
	}

	/**
	 * If cache is enabled.
	 *
	 * @return boolean
	 */
	public function is_enabled() {

		/**
		 * Filter if XML sitemap transient cache is enabled.
		 *
		 * @param bool $unsigned Enable cache or not, defaults to true
		 */
		return apply_filters( 'wpseo_enable_xml_sitemap_transient_caching', true );
	}

	/**
	 * Get the cache key for a certain type and page
	 *
	 * A type of cache would be something like 'page', 'post' or 'video'.
	 *
	 * Example key format for sitemap type "post", page 1: wpseo_sitemap_post_1:akfw3e_23azBa
	 *
	 * @param null|string $type The type to get the key for. Null or self::SITEMAP_INDEX_TYPE for index cache.
	 * @param int         $page The page of cache to get the key for.
	 *
	 * @return bool|string The key where the cache is stored on. False if the key could not be generated.
	 */
	public static function get_storage_key( $type = null, $page = 1 ) {

		// Using SITEMAP_INDEX_TYPE for sitemap index cache.
		$type = is_null( $type ) ? self::SITEMAP_INDEX_TYPE : $type;

		$global_cache_validator = self::get_validator();
		$type_cache_validator   = self::get_validator( $type );

		$prefix  = self::STORAGE_KEY_PREFIX;
		$postfix = sprintf( '_%d:%s_%s', $page, $global_cache_validator, $type_cache_validator );

		try {
			$type = self::truncate_type( $type, $prefix, $postfix );
		} catch ( OutOfBoundsException $exception ) {
			// Maybe do something with the exception, for now just mark as invalid.
			return false;
		}

		// Build key.
		$full_key = $prefix . $type . $postfix;

		return $full_key;
	}

	/**
	 * If the type is over length make sure we compact it so we don't have any database problems
	 *
	 * When there are more 'extremely long' post types, changes are they have variations in either the start or ending.
	 * Because of this, we cut out the excess in the middle which should result in less chance of collision.
	 *
	 * @param string $type    The type of sitemap to be used.
	 * @param string $prefix  The part before the type in the cache key. Only the length is used.
	 * @param string $postfix The part after the type in the cache key. Only the length is used.
	 *
	 * @return string The type with a safe length to use
	 *
	 * @throws OutOfRangeException When there is less than 15 characters of space for a key that is originally longer.
	 */
	public static function truncate_type( $type, $prefix = '', $postfix = '' ) {
		/**
		 * This length has been restricted by the database column length of 64 in the past.
		 * The prefix added by WordPress is '_transient_' because we are saving to a transient.
		 * We need to use a timeout on the transient, otherwise the values get autoloaded, this adds
		 * another restriction to the length.
		 */
		$max_length = 45; // 64 - 19 ('_transient_timeout_')
		$max_length -= strlen( $prefix );
		$max_length -= strlen( $postfix );

		if ( strlen( $type ) > $max_length ) {

			if ( $max_length < 15 ) {
				/**
				 * If this happens the most likely cause is a page number that is too high.
				 *
				 * So this would not happen unintentionally..
				 * Either by trying to cause a high server load, finding backdoors or misconfiguration.
				 */
				throw new OutOfRangeException(
					__(
						'Trying to build truncate the sitemap cache key, but the postfix and prefix combination leaves too little room to do this. You are probably requesting a page that is way out of the expected range.',
						'wordpress-seo'
					)
				);
			}

			$half = ( $max_length / 2 );

			$first_part = substr( $type, 0, ( ceil( $half ) - 1 ) );
			$last_part  = substr( $type, ( 1 - floor( $half ) ) );

			$type = $first_part . '..' . $last_part;
		}

		return $type;
	}

	/**
	 * Get the cache validator option key for the specified type
	 *
	 * @param string $type Provide a type for a specific type validator, empty for global validator.
	 *
	 * @return string Validator to be used to generate the cache key.
	 */
	public static function get_validator_key( $type = '' ) {
		if ( empty( $type ) ) {
			return self::VALIDATION_GLOBAL_KEY;
		}

		return sprintf( self::VALIDATION_TYPE_KEY_FORMAT, $type );
	}

	/**
	 * Get the current cache validator
	 *
	 * Without the type the global validator is returned.
	 *  This can invalidate -all- keys in cache at once
	 *
	 * With the type parameter the validator for that specific
	 *  type can be invalidated
	 *
	 * @param string $type Provide a type for a specific type validator, empty for global validator.
	 *
	 * @return null|string The validator for the supplied type.
	 */
	private static function get_validator( $type = '' ) {
		$key = self::get_validator_key( $type );

		$current = get_option( $key, null );
		if ( ! is_null( $current ) ) {
			return $current;
		}

		if ( self::create_validator( $type ) ) {
			return self::get_validator( $type );
		}

		return null;
	}

	/**
	 * Refresh the cache validator value
	 *
	 * @param string $type Provide a type for a specific type validator, empty for global validator.
	 *
	 * @return bool True if validator key has been saved as option.
	 */
	public static function create_validator( $type = '' ) {
		$key = self::get_validator_key( $type );

		// Generate new validator.
		$microtime = microtime();

		// Remove space.
		list( $milliseconds, $seconds ) = explode( ' ', $microtime );

		// Transients are purged every 24h.
		$seconds      = ( $seconds % DAY_IN_SECONDS );
		$milliseconds = substr( $milliseconds, 2, 5 );

		// Combine seconds and milliseconds and convert to integer.
		$validator = intval( $seconds . '' . $milliseconds, 10 );

		// Apply base 61 encoding.
		$compressed = self::convert_base10_to_base61( $validator );

		return update_option( $key, $compressed );
	}

	/**
	 * Retrieve the sitemap page from cache.
	 *
	 * @param string $type Sitemap type.
	 * @param int    $page Page number to retrieve.
	 *
	 * @return string|boolean
	 */
	public function get_sitemap( $type, $page ) {

		$transient_key = self::get_storage_key( $type, $page );
		if ( false === $transient_key ) {
			return false;
		}

		return get_transient( $transient_key );
	}

	/**
	 * Get the sitemap that is cached
	 *
	 * @param string $type Sitemap type.
	 * @param int    $page Page number to retrieve.
	 *
	 * @return null|WPSEO_Sitemap_Cache_Data Null on no cache found otherwise object containing sitemap and meta data.
	 */
	public function get_sitemap_data( $type, $page ) {

		$sitemap = $this->get_sitemap( $type, $page );

		if ( empty( $sitemap ) ) {
			return null;
		}

		// Unserialize Cache Data object (is_serialized doesn't recognize classes).
		if ( 0 === strpos( $sitemap, 'C:24:"WPSEO_Sitemap_Cache_Data"' ) ) {

			$sitemap = unserialize( $sitemap );
		}

		// What we expect it to be if it is set.
		if ( $sitemap instanceof WPSEO_Sitemap_Cache_Data_Interface ) {
			return $sitemap;
		}

		return null;
	}

	/**
	 * Store the sitemap page from cache.
	 *
	 * @param string $type    Sitemap type.
	 * @param int    $page    Page number to store.
	 * @param string $sitemap Sitemap body to store.
	 * @param bool   $usable  Is this a valid sitemap or a cache of an invalid sitemap.
	 *
	 * @return bool
	 */
	public function store_sitemap( $type, $page, $sitemap, $usable = true ) {

		$transient_key = self::get_storage_key( $type, $page );
		if ( false === $transient_key ) {
			return false;
		}

		$status = ( $usable ) ? WPSEO_Sitemap_Cache_Data::OK : WPSEO_Sitemap_Cache_Data::ERROR;

		$sitemap_data = new WPSEO_Sitemap_Cache_Data();
		$sitemap_data->set_sitemap( $sitemap );
		$sitemap_data->set_status( $status );

		return set_transient( $transient_key, $sitemap_data, DAY_IN_SECONDS );
	}

	/**
	 * Delete cache transients for index and specific type.
	 *
	 * Always deletes the main index sitemaps cache, as that's always invalidated by any other change.
	 *
	 * @param string $type Sitemap type to invalidate.
	 *
	 * @return void
	 */
	public static function invalidate( $type ) {

		self::clear( array( $type ) );
	}

	/**
	 * Helper to invalidate in hooks where type is passed as second argument.
	 *
	 * @param int    $unused Unused term ID value.
	 * @param string $type   Taxonomy to invalidate.
	 *
	 * @return void
	 */
	public static function invalidate_helper( $unused, $type ) {

		self::invalidate( $type );
	}

	/**
	 * Invalidate sitemap cache for the post type of a post.
	 *
	 * Don't invalidate for revisions.
	 *
	 * @param int $post_id Post ID to invalidate type for.
	 *
	 * @return void
	 */
	public static function invalidate_post( $post_id ) {

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::invalidate( get_post_type( $post_id ) );
	}

	/**
	 * Delete cache transients for given sitemaps types or all by default.
	 *
	 * @param array $types Set of sitemap types to delete cache transients for.
	 *
	 * @return void
	 */
	public static function clear( $types = array() ) {

		// No types provided, clear all.
		if ( empty( $types ) ) {
			self::invalidate_storage();

			return;
		}

		// Always invalidate the index sitemap aswel.
		if ( ! in_array( self::SITEMAP_INDEX_TYPE, $types ) ) {
			array_unshift( $types, self::SITEMAP_INDEX_TYPE );
		}

		foreach ( $types as $type ) {
			self::invalidate_storage( $type );
		}
	}

	/**
	 * Invalidate sitemap cache
	 *
	 * @param null|string $type The type to get the key for. Null for all caches.
	 *
	 * @return void
	 */
	public static function invalidate_storage( $type = null ) {

		// Global validator gets cleared when no type is provided.
		$old_validator = null;

		// Get the current type validator.
		if ( ! is_null( $type ) ) {
			$old_validator = self::get_validator( $type );
		}

		// Refresh validator.
		self::create_validator( $type );

		if ( ! wp_using_ext_object_cache() ) {
			// Clean up current cache from the database.
			self::cleanup_database( $type, $old_validator );
		}

		// External object cache pushes old and unretrieved items out by itself so we don't have to do anything for that.
	}

	/**
	 * Cleanup invalidated database cache
	 *
	 * @param null|string $type      The type of sitemap to clear cache for.
	 * @param null|string $validator The validator to clear cache of.
	 *
	 * @return void
	 */
	public static function cleanup_database( $type = null, $validator = null ) {

		global $wpdb;

		if ( is_null( $type ) ) {
			// Clear all cache if no type is provided.
			$like = sprintf( '%s%%', self::STORAGE_KEY_PREFIX );
		}
		else {
			if ( ! is_null( $validator ) ) {
				// Clear all cache for provided type-validator.
				$like = sprintf( '%%_%s', $validator );
			}
			else {
				// Clear type cache for all type keys.
				$like = sprintf( '%1$s%2$s_%%', self::STORAGE_KEY_PREFIX, $type );
			}
		}

		/**
		 * Add slashes to the LIKE "_" single character wildcard.
		 *
		 * We can't use `esc_like` here because we need the % in the query.
		 */
		$where   = array();
		$where[] = sprintf( "option_name LIKE '%s'", addcslashes( '_transient_' . $like, '_' ) );
		$where[] = sprintf( "option_name LIKE '%s'", addcslashes( '_transient_timeout_' . $like, '_' ) );

		// Delete transients.
		$query = sprintf( 'DELETE FROM %1$s WHERE %2$s', $wpdb->options, implode( ' OR ', $where ) );
		$wpdb->query( $query );
	}

	/**
	 * Adds a hook that when given option is updated, the cache is cleared
	 *
	 * @param string $option Option name.
	 * @param string $type   Sitemap type.
	 */
	public static function register_clear_on_option_update( $option, $type = '' ) {

		self::$cache_clear[ $option ] = $type;
	}

	/**
	 * Clears the transient cache when a given option is updated, if that option has been registered before
	 *
	 * @param string $option The option name that's being updated.
	 *
	 * @return void
	 */
	public static function clear_on_option_update( $option ) {

		if ( array_key_exists( $option, self::$cache_clear ) ) {

			if ( empty( self::$cache_clear[ $option ] ) ) {
				// Clear all caches.
				self::clear();
			}
			else {
				// Clear specific provided type(s).
				$types = (array) self::$cache_clear[ $option ];
				self::clear( $types );
			}
		}
	}

	/**
	 * Encode to base61 format.
	 *
	 * This is base64 (numeric + alpha + alpha upper case) without the 0.
	 *
	 * @param int $base10 The number that has to be converted to base 61.
	 *
	 * @return string Base 61 converted string.
	 *
	 * @throws InvalidArgumentException When the input is not an integer.
	 */
	public static function convert_base10_to_base61( $base10 ) {
		if ( ! is_int( $base10 ) ) {
			throw new InvalidArgumentException( __( 'Expected an integer as input.', 'wordpress-seo' ) );
		}

		// Characters that will be used in the conversion.
		$characters = '123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$length     = strlen( $characters );

		$remainder = $base10;
		$output    = '';

		do {
			// Building from right to left in the result.
			$index = ( $remainder % $length );

			// Prepend the character to the output.
			$output = $characters[ $index ] . $output;

			// Determine the remainder after removing the applied number.
			$remainder = floor( $remainder / $length );

			// Keep doing it until we have no remainder left.
		} while ( $remainder );

		return $output;
	}
}