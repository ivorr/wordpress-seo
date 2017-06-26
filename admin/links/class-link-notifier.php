<?php
/**
 * @package WPSEO\Premium
 */

/**
 * Repressents the notifier for adding link indexing notification to the dashboard.
 */
class WPSEO_Link_Notifier {

	const NOTIFICATION_ID = 'wpseo-reindex-links';

	/**
	 * Registers all hooks to WordPress
	 */
	public function register_hooks() {
		if ( filter_input( INPUT_GET, 'page' ) === 'wpseo_dashboard' ) {
			add_action( 'admin_init', array( $this, 'cleanup_notification' ) );
		}

		if ( ! wp_next_scheduled( self::NOTIFICATION_ID ) ) {
			wp_schedule_event( time(), 'daily', self::NOTIFICATION_ID );
		}

		add_action( self::NOTIFICATION_ID, array( $this, 'manage_notification' ) );
	}

	/**
	 * Removes the notification when it is set and the amount of unindexed items is lower than the threshold.
	 */
	public function cleanup_notification() {
		if ( ! $this->has_notification() || $this->requires_notification()  ) {
			return;
		}

		$this->remove_notification( $this->get_notification() );
	}

	/**
	 * Adds the notification when it isn't set already and the amount of unindexed items is greater than the set.
	 * threshold.
	 */
	public function manage_notification() {
		if ( $this->has_notification() || ! $this->requires_notification() ) {
			return;
		}

		$this->add_notification( $this->get_notification() );
	}

	/**
	 * Checks if the notification has been set already.
	 *
	 * @return bool True when there is a notification.
	 */
	public function has_notification() {
		$notification = Yoast_Notification_Center::get()->get_notification_by_id( self::NOTIFICATION_ID );

		return $notification instanceof Yoast_Notification;
	}

	/**
	 * Adds a notification to the notification center.
	 *
	 * @param Yoast_Notification $notification The notification to add.
	 */
	protected function add_notification( Yoast_Notification $notification ) {
		Yoast_Notification_Center::get()->add_notification( $notification );
	}

	/**
	 * Removes the notification from the notification center.
	 *
	 * @param Yoast_Notification $notification The notification to remove.
	 */
	protected function remove_notification( Yoast_Notification $notification ) {
		Yoast_Notification_Center::get()->remove_notification( $notification );
	}

	/**
	 * Returns an instance of the notification.
	 *
	 * @return Yoast_Notification The notification to show.
	 */
	protected function get_notification() {
		return new Yoast_Notification(
			sprintf(
			/* translators: 1: link to yoast.com post about internal linking suggestion. 2: is anchor closing. 3: button to the recalculation option. 4: closing button */
				__(
					'You need to index your posts and/or pages in order to receive the best %1$slink suggestions%2$s.

					%3$sAnalyze the content%4$s to generate the missing link suggestions.',
					'wordpress-seo'
				),
				'<a href="https://yoa.st/notification-internal-link">',
				'</a>',
				'<button type="button" id="noticeRunLinkIndex" class="button">',
				'</button>'
			),
			array(
				'type'         => Yoast_Notification::WARNING,
				'id'           => self::NOTIFICATION_ID,
				'capabilities' => 'manage_options',
				'priority'     => 0.8,
			)
		);
	}

	/**
	 * Checks if the unindexed threshold is exceeded.
	 *
	 * @return bool True when the threshold is exceeded.
	 */
	protected function requires_notification() {
		return WPSEO_Link_Query::has_unprocessed_posts( WPSEO_Link_Utils::get_public_post_types() );
	}
}