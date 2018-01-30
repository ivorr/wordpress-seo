<?php
/**
 * WPSEO plugin file.
 *
 * @package WPSEO\Admin
 */

/**
 * Class that holds most of the admin functionality for Yoast SEO.
 */
class WPSEO_Admin {

	/** The page identifier used in WordPress to register the admin page !DO NOT CHANGE THIS! */
	const PAGE_IDENTIFIER = 'wpseo_dashboard';

	/**
	 * Array of classes that add admin functionality.
	 *
	 * @var array
	 */
	protected $admin_features;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$integrations = array();

		global $pagenow;

		$wpseo_menu = new WPSEO_Menu();
		$wpseo_menu->register_hooks();

		if ( is_multisite() ) {
			WPSEO_Options::maybe_set_multisite_defaults( false );
		}

		if ( WPSEO_Options::get( 'stripcategorybase' ) === true ) {
			add_action( 'created_category', array( $this, 'schedule_rewrite_flush' ) );
			add_action( 'edited_category', array( $this, 'schedule_rewrite_flush' ) );
			add_action( 'delete_category', array( $this, 'schedule_rewrite_flush' ) );
		}

		$this->admin_features = array(
			// Google Search Console.
			'google_search_console' => new WPSEO_GSC(),
			'dashboard_widget'      => new Yoast_Dashboard_Widget(),
		);

		if ( WPSEO_Metabox::is_post_overview( $pagenow ) || WPSEO_Metabox::is_post_edit( $pagenow ) ) {
			$this->admin_features['primary_category'] = new WPSEO_Primary_Term_Admin();
		}

		if ( filter_input( INPUT_GET, 'page' ) === 'wpseo_tools' && filter_input( INPUT_GET, 'tool' ) === null ) {
			new WPSEO_Recalculate_Scores();
		}

		add_filter( 'plugin_action_links_' . WPSEO_BASENAME, array( $this, 'add_action_link' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'config_page_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_global_style' ) );

		add_filter( 'user_contactmethods', array( $this, 'update_contactmethods' ), 10, 1 );

		add_action( 'after_switch_theme', array( $this, 'switch_theme' ) );
		add_action( 'switch_theme', array( $this, 'switch_theme' ) );

		add_filter( 'set-screen-option', array( $this, 'save_bulk_edit_options' ), 10, 3 );

		add_action( 'admin_init', array( 'WPSEO_Plugin_Conflict', 'hook_check_for_plugin_conflicts' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'import_plugin_hooks' ) );

		add_action( 'admin_init', array( $this, 'map_manage_options_cap' ) );

		WPSEO_Sitemaps_Cache::register_clear_on_option_update( 'wpseo' );
		WPSEO_Sitemaps_Cache::register_clear_on_option_update( 'home' );

		if ( WPSEO_Utils::is_yoast_seo_page() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		if ( WPSEO_Utils::is_api_available() ) {
			$configuration = new WPSEO_Configuration_Page();
			$configuration->set_hooks();
			$configuration->catch_configuration_request();
		}

		$this->set_upsell_notice();

		$this->check_php_version();
		$this->initialize_cornerstone_content();

		$integrations[] = new WPSEO_Yoast_Columns();
		$integrations[] = new WPSEO_License_Page_Manager();
		$integrations[] = new WPSEO_Statistic_Integration();
		$integrations[] = new WPSEO_Slug_Change_Watcher();
		$integrations[] = new WPSEO_Capability_Manager_Integration( WPSEO_Capability_Manager_Factory::get() );
		$integrations   = array_merge( $integrations, $this->initialize_seo_links() );

		/** @var WPSEO_WordPress_Integration $integration */
		foreach ( $integrations as $integration ) {
			$integration->register_hooks();
		}

	}

	/**
	 * Setting the hooks for importing data from other plugins.
	 */
	public function import_plugin_hooks() {
		if ( current_user_can( $this->get_manage_options_cap() ) ) {
			$plugin_imports = array(
				'wpSEO'       => new WPSEO_Import_WPSEO_Hooks(),
				'aioseo'      => new WPSEO_Import_AIOSEO_Hooks(),
			);
		}
	}

	/**
	 * Schedules a rewrite flush to happen at shutdown.
	 */
	public function schedule_rewrite_flush() {
		add_action( 'shutdown', 'flush_rewrite_rules' );
	}

	/**
	 * Returns all the classes for the admin features.
	 *
	 * @return array
	 */
	public function get_admin_features() {
		return $this->admin_features;
	}

	/**
	 * Register assets needed on admin pages.
	 */
	public function enqueue_assets() {
		if ( 'wpseo_licenses' === filter_input( INPUT_GET, 'page' ) ) {
			$asset_manager = new WPSEO_Admin_Asset_Manager();
			$asset_manager->enqueue_style( 'extensions' );
		}
	}

	/**
	 * Returns the manage_options capability.
	 *
	 * @return string The capability to use.
	 */
	public function get_manage_options_cap() {
		/**
		 * Filter: 'wpseo_manage_options_capability' - Allow changing the capability users need to view the settings pages.
		 *
		 * @api string unsigned The capability.
		 */
		return apply_filters( 'wpseo_manage_options_capability', 'wpseo_manage_options' );
	}

	/**
	 * Maps the manage_options cap on saving an options page to wpseo_manage_options.
	 */
	public function map_manage_options_cap() {
		$option_page = ! empty( $_POST['option_page'] ) ? $_POST['option_page'] : ''; // WPCS: CSRF ok.

		if ( strpos( $option_page, 'yoast_wpseo' ) === 0 ) {
			add_filter( 'option_page_capability_' . $option_page, array( $this, 'get_manage_options_cap' ) );
		}
	}

	/**
	 * Adds the ability to choose how many posts are displayed per page
	 * on the bulk edit pages.
	 */
	public function bulk_edit_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Posts', 'wordpress-seo' ),
			'default' => 10,
			'option'  => 'wpseo_posts_per_page',
		);
		add_screen_option( $option, $args );
	}

	/**
	 * Saves the posts per page limit for bulk edit pages.
	 *
	 * @param int    $status Status value to pass through.
	 * @param string $option Option name.
	 * @param int    $value  Count value to check.
	 *
	 * @return int
	 */
	public function save_bulk_edit_options( $status, $option, $value ) {
		if ( 'wpseo_posts_per_page' === $option && ( $value > 0 && $value < 1000 ) ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Adds links to Premium Support and FAQ under the plugin in the plugin overview page.
	 *
	 * @staticvar string $this_plugin Holds the directory & filename for the plugin.
	 *
	 * @param array  $links Array of links for the plugins, adapted when the current plugin is found.
	 * @param string $file  The filename for the current plugin, which the filter loops through.
	 *
	 * @return array $links
	 */
	public function add_action_link( $links, $file ) {
		if ( WPSEO_BASENAME === $file && WPSEO_Capability_Utils::current_user_can( 'wpseo_manage_options' ) ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_IDENTIFIER ) ) . '">' . __( 'Settings', 'wordpress-seo' ) . '</a>';
			array_unshift( $links, $settings_link );
		}

		if ( class_exists( 'WPSEO_Product_Premium' ) ) {
			$product_premium   = new WPSEO_Product_Premium();
			$extension_manager = new WPSEO_Extension_Manager();

			if ( $extension_manager->is_activated( $product_premium->get_slug() ) ) {
				return $links;
			}
		}

		// Add link to premium support landing page.
		$premium_link = '<a href="' . esc_url( WPSEO_Shortlinker::get( 'https://yoa.st/1yb' ) ) . '">' . __( 'Premium Support', 'wordpress-seo' ) . '</a>';
		array_unshift( $links, $premium_link );

		// Add link to docs.
		$faq_link = '<a href="' . esc_url( WPSEO_Shortlinker::get( 'https://yoa.st/1yc' ) ) . '">' . __( 'FAQ', 'wordpress-seo' ) . '</a>';
		array_unshift( $links, $faq_link );

		return $links;
	}

	/**
	 * Enqueues the (tiny) global JS needed for the plugin.
	 */
	public function config_page_scripts() {
		$asset_manager = new WPSEO_Admin_Asset_Manager();
		$asset_manager->enqueue_script( 'admin-global-script' );

		wp_localize_script( WPSEO_Admin_Asset_Manager::PREFIX . 'admin-global-script', 'wpseoAdminGlobalL10n', $this->localize_admin_global_script() );
	}

	/**
	 * Enqueues the (tiny) global stylesheet needed for the plugin.
	 */
	public function enqueue_global_style() {
		$asset_manager = new WPSEO_Admin_Asset_Manager();
		$asset_manager->enqueue_style( 'admin-global' );
	}

	/**
	 * Filter the $contactmethods array and add Facebook, Google+ and Twitter.
	 *
	 * These are used with the Facebook author, rel="author" and Twitter cards implementation.
	 *
	 * @param array $contactmethods Currently set contactmethods.
	 *
	 * @return array $contactmethods with added contactmethods.
	 */
	public function update_contactmethods( $contactmethods ) {
		// Add Google+.
		$contactmethods['googleplus'] = __( 'Google+', 'wordpress-seo' );
		// Add Twitter.
		$contactmethods['twitter'] = __( 'Twitter username (without @)', 'wordpress-seo' );
		// Add Facebook.
		$contactmethods['facebook'] = __( 'Facebook profile URL', 'wordpress-seo' );

		return $contactmethods;
	}

	/**
	 * Log the updated timestamp for user profiles when theme is changed.
	 */
	public function switch_theme() {
		$users = get_users( array( 'who' => 'authors' ) );
		if ( is_array( $users ) && $users !== array() ) {
			foreach ( $users as $user ) {
				update_user_meta( $user->ID, '_yoast_wpseo_profile_updated', time() );
			}
		}
	}

	/**
	 * Localization for the dismiss urls.
	 *
	 * @return array
	 */
	private function localize_admin_global_script() {
		return array(
			'dismiss_about_url'       => $this->get_dismiss_url( 'wpseo-dismiss-about' ),
			'dismiss_tagline_url'     => $this->get_dismiss_url( 'wpseo-dismiss-tagline-notice' ),
			'help_video_iframe_title' => __( 'Yoast SEO video tutorial', 'wordpress-seo' ),
			'scrollable_table_hint'   => __( 'Scroll to see the table content.', 'wordpress-seo' ),
		);
	}

	/**
	 * Extending the current page URL with two params to be able to ignore the notice.
	 *
	 * @param string $dismiss_param The param used to dismiss the notification.
	 *
	 * @return string
	 */
	private function get_dismiss_url( $dismiss_param ) {
		$arr_params = array(
			$dismiss_param => '1',
			'nonce'        => wp_create_nonce( $dismiss_param ),
		);

		return esc_url( add_query_arg( $arr_params ) );
	}

	/**
	 * Sets the upsell notice.
	 */
	protected function set_upsell_notice() {
		$upsell = new WPSEO_Product_Upsell_Notice();
		$upsell->dismiss_notice_listener();
		$upsell->initialize();
	}

	/**
	 * Initializes Whip to show a notice for outdated PHP versions.
	 */
	protected function check_php_version() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->on_dashboard_page() ) {
			return;
		}

		whip_wp_check_versions( array(
			'php' => '>=5.4',
		) );
	}

	/**
	 * Whether we are on the admin dashboard page.
	 *
	 * @returns bool
	 */
	protected function on_dashboard_page() {
		return 'index.php' === $GLOBALS['pagenow'];
	}

	/**
	 * Loads the cornerstone filter.
	 */
	protected function initialize_cornerstone_content() {
		if ( ! WPSEO_Options::get( 'enable_cornerstone_content' ) ) {
			return;
		}

		$cornerstone = new WPSEO_Cornerstone();
		$cornerstone->register_hooks();

		$cornerstone_filter = new WPSEO_Cornerstone_Filter();
		$cornerstone_filter->register_hooks();
	}

	/**
	 * Initializes the seo link watcher.
	 *
	 * @returns WPSEO_WordPress_Integration[]
	 */
	protected function initialize_seo_links() {
		$integrations = array();

		$link_table_compatibility_notifier = new WPSEO_Link_Compatibility_Notifier();
		$link_table_accessible_notifier    = new WPSEO_Link_Table_Accessible_Notifier();

		if ( ! WPSEO_Options::get( 'enable_text_link_counter' ) ) {
			$link_table_compatibility_notifier->remove_notification();

			return $integrations;
		}

		$integrations[] = new WPSEO_Link_Cleanup_Transient();

		// Only use the link module for PHP 5.3 and higher and show a notice when version is wrong.
		if ( version_compare( phpversion(), '5.3', '<' ) ) {
			$link_table_compatibility_notifier->add_notification();

			return $integrations;
		}

		$link_table_compatibility_notifier->remove_notification();

		// When the table doesn't exists, just add the notification and return early.
		if ( ! WPSEO_Link_Table_Accessible::is_accessible() ) {
			WPSEO_Link_Table_Accessible::cleanup();
		}

		if ( ! WPSEO_Meta_Table_Accessible::is_accessible() ) {
			WPSEO_Meta_Table_Accessible::cleanup();
		}

		if ( ! WPSEO_Link_Table_Accessible::is_accessible() || ! WPSEO_Meta_Table_Accessible::is_accessible() ) {
			$link_table_accessible_notifier->add_notification();

			return $integrations;
		}

		$link_table_accessible_notifier->remove_notification();

		$integrations[] = new WPSEO_Link_Columns( new WPSEO_Meta_Storage() );
		$integrations[] = new WPSEO_Link_Reindex_Dashboard();
		$integrations[] = new WPSEO_Link_Notifier();

		// Adds a filter to exclude the attachments from the link count.
		add_filter( 'wpseo_link_count_post_types', array( 'WPSEO_Post_Type', 'filter_attachment_post_type' ) );

		return $integrations;
	}

	/********************** DEPRECATED METHODS **********************/

	// @codeCoverageIgnoreStart
	/**
	 * Check whether the current user is allowed to access the configuration.
	 *
	 * @deprecated 1.5.0
	 * @deprecated use WPSEO_Utils::grant_access()
	 * @see        WPSEO_Utils::grant_access()
	 *
	 * @return boolean
	 */
	public function grant_access() {
		_deprecated_function( __METHOD__, 'WPSEO 1.5.0', 'WPSEO_Utils::grant_access()' );

		return WPSEO_Utils::grant_access();
	}

	/**
	 * Check whether the current user is allowed to access the configuration.
	 *
	 * @deprecated 1.5.0
	 * @deprecated use wpseo_do_upgrade()
	 * @see        WPSEO_Upgrade
	 */
	public function maybe_upgrade() {
		_deprecated_function( __METHOD__, 'WPSEO 1.5.0', 'wpseo_do_upgrade' );
		new WPSEO_Upgrade();
	}

	/**
	 * Clears the cache.
	 *
	 * @deprecated 1.5.0
	 * @deprecated use WPSEO_Utils::clear_cache()
	 * @see        WPSEO_Utils::clear_cache()
	 */
	public function clear_cache() {
		_deprecated_function( __METHOD__, 'WPSEO 1.5.0', 'WPSEO_Utils::clear_cache()' );
		WPSEO_Utils::clear_cache();
	}

	/**
	 * Clear rewrites.
	 *
	 * @deprecated 1.5.0
	 * @deprecated use WPSEO_Utils::clear_rewrites()
	 * @see        WPSEO_Utils::clear_rewrites()
	 */
	public function clear_rewrites() {
		_deprecated_function( __METHOD__, 'WPSEO 1.5.0', 'WPSEO_Utils::clear_rewrites()' );
		WPSEO_Utils::clear_rewrites();
	}

	/**
	 * Register all the options needed for the configuration pages.
	 *
	 * @deprecated 1.5.0
	 * @deprecated use WPSEO_Option::register_setting() on each individual option
	 * @see        WPSEO_Option::register_setting()
	 */
	public function options_init() {
		_deprecated_function( __METHOD__, 'WPSEO 1.5.0', 'WPSEO_Option::register_setting()' );
	}

	/**
	 * Register the menu item and its sub menu's.
	 *
	 * @deprecated 5.5
	 */
	public function register_settings_page() {
		_deprecated_function( __METHOD__, 'WPSEO 5.5.0' );
	}

	/**
	 * Register the settings page for the Network settings.
	 *
	 * @deprecated 5.5
	 */
	public function register_network_settings_page() {
		_deprecated_function( __METHOD__, 'WPSEO 5.5.0' );
	}

	/**
	 * Load the form for a WPSEO admin page.
	 *
	 * @deprecated 5.5
	 */
	public function load_page() {
		_deprecated_function( __METHOD__, 'WPSEO 5.5.0' );
	}

	/**
	 * Loads the form for the network configuration page.
	 *
	 * @deprecated 5.5
	 */
	public function network_config_page() {
		_deprecated_function( __METHOD__, 'WPSEO 5.5.0' );
	}

	/**
	 * Filters all advanced settings pages from the given pages.
	 *
	 * @deprecated 5.5
	 * @param array $pages The pages to filter.
	 */
	public function filter_settings_pages( array $pages ) {
		_deprecated_function( __METHOD__, 'WPSEO 5.5.0' );
	}

	/**
	 * Display an error message when the blog is set to private.
	 *
	 * @deprecated 3.3
	 */
	public function blog_public_warning() {
		_deprecated_function( __METHOD__, 'WPSEO 3.3.0' );
	}

	/**
	 * Display an error message when the theme contains a meta description tag.
	 *
	 * @since 1.4.14
	 *
	 * @deprecated 3.3
	 */
	public function meta_description_warning() {
		_deprecated_function( __METHOD__, 'WPSEO 3.3.0' );
	}

	/**
	 * Returns the stopwords for the current language.
	 *
	 * @since 1.1.7
	 *
	 * @return void
	 */
	public function stopwords() {
		_deprecated_function( __METHOD__, 'WPSEO 3.1' );
	}

	/**
	 * Check whether the stopword appears in the string.
	 *
	 * @deprecated 3.1
	 *
	 * @return void
	 */
	public function stopwords_check() {
		_deprecated_function( __METHOD__, 'WPSEO 3.1' );
	}

	/**
	 * Cleans stopwords out of the slug, if the slug hasn't been set yet.
	 *
	 * @deprecated 6.4
	 *
	 * @return void
	 */
	public function remove_stopwords_from_slug() {
		_deprecated_function( __METHOD__, 'WPSEO 6.4' );
	}

	/**
	 * Filter the stopwords from the slug.
	 *
	 * @deprecated 6.4
	 *
	 * @return void
	 */
	public function filter_stopwords_from_slug() {
		_deprecated_function( __METHOD__, 'WPSEO 6.4' );
	}

	/**
	 * Adds contextual help to the titles & metas page.
	 *
	 * @deprecated 5.6.0
	 */
	public function title_metas_help_tab() {
		_deprecated_function( __METHOD__, '5.6.0' );

		$screen = get_current_screen();

		$screen->set_help_sidebar( '
			<p><strong>' . __( 'For more information:', 'wordpress-seo' ) . '</strong></p>
			<p><a target="_blank" href="https://yoast.com/wordpress-seo/#titles">' . __( 'Title optimization', 'wordpress-seo' ) . '</a></p>
			<p><a target="_blank" href="https://yoast.com/google-page-title/">' . __( 'Why Google won\'t display the right page title', 'wordpress-seo' ) . '</a></p>'
		);

		$screen->add_help_tab(
			array(
				'id'      => 'basic-help',
				'title'   => __( 'Template explanation', 'wordpress-seo' ),
				'content' => "\n\t\t<h2>" . __( 'Template explanation', 'wordpress-seo' ) . "</h2>\n\t\t" . '<p>' .
					sprintf(
						/* translators: %1$s expands to Yoast SEO. */
						__( 'The title &amp; metas settings for %1$s are made up of variables that are replaced by specific values from the page when the page is displayed. The tabs on the left explain the available variables.', 'wordpress-seo' ),
						'Yoast SEO'
					) .
					'</p><p>' . __( 'Note that not all variables can be used in every template.', 'wordpress-seo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'title-vars',
				'title'   => __( 'Basic Variables', 'wordpress-seo' ),
				'content' => "\n\t\t<h2>" . __( 'Basic Variables', 'wordpress-seo' ) . "</h2>\n\t\t" . WPSEO_Replace_Vars::get_basic_help_texts(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'title-vars-advanced',
				'title'   => __( 'Advanced Variables', 'wordpress-seo' ),
				'content' => "\n\t\t<h2>" . __( 'Advanced Variables', 'wordpress-seo' ) . "</h2>\n\t\t" . WPSEO_Replace_Vars::get_advanced_help_texts(),
			)
		);
	}

	// @codeCoverageIgnoreEnd
}
