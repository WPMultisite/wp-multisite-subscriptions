<?php
/**
 * Notification Manager
 *
 * Handles processes related to notifications.
 *
 * @package WP_Ultimo
 * @subpackage Managers/Notification_Manager
 * @since 2.0.0
 */

namespace WP_Ultimo\Managers;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Handles processes related to notifications.
 *
 * @since 2.0.0
 */
class Notification_Manager {

	use \WP_Ultimo\Traits\Singleton;

	/**
	 * List of callbacks to keep, for backwards compatibility purposes.
	 *
	 * @since 2.2.0
	 * @var array
	 */
	protected $backwards_compatibility_list;

	/**
	 * Instantiate the necessary hooks.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {

		add_action('wp_ultimo_load', array($this, 'add_settings'));

		if (is_admin() && !is_network_admin()) {

			add_action('admin_init', array($this, 'hide_notifications_subsites'));

		} // end if;

	} // end init;

	/**
	 * Hide notifications on subsites if settings was enabled.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function hide_notifications_subsites() {

		if (!wu_get_setting('hide_notifications_subsites')) {

			return;

		} // end if;

		global $wp_filter;

		/*
		 * List of callbacks to keep, for backwards compatibility purposes.
		 */
		$this->backwards_compatibility_list = apply_filters('wu_hide_notifications_exclude_list', array(
			'inject_admin_head_ads',
		));

		$cleaner = array($this, 'clear_callback_list');

		if (wu_get_isset($wp_filter, 'admin_notices')) {

			$wp_filter['admin_notices']->callbacks = array_filter($wp_filter['admin_notices']->callbacks, $cleaner === null ? fn($v, $k): bool => !empty($v) : $cleaner, $cleaner === null ? ARRAY_FILTER_USE_BOTH : 0);

		} // end if;

		if (wu_get_isset($wp_filter, 'all_admin_notices')) {

			$wp_filter['all_admin_notices']->callbacks = array_filter($wp_filter['all_admin_notices']->callbacks, $cleaner === null ? fn($v, $k): bool => !empty($v) : $cleaner, $cleaner === null ? ARRAY_FILTER_USE_BOTH : 0);

		} // end if;

	} // end hide_notifications_subsites;

	/**
	 * Keeps the allowed callbacks.
	 *
	 * @since 2.0.0
	 *
	 * @param array $callbacks The callbacks attached.
	 * @return array
	 */
	public function clear_callback_list($callbacks): bool {

		if (empty($this->backwards_compatibility_list)) {

			return false;

		} // end if;

		$keys = array_keys($callbacks);

		foreach ($keys as $key) {

			foreach ($this->backwards_compatibility_list as $key_to_keep) {

				if (strpos($key, (string) $key_to_keep) !== false) {

					return true;

				} // end if;

			} // end foreach;

		} // end foreach;

		return false;

	} // end clear_callback_list;

	/**
	 * Filter the WP Multisite Subscriptions settings to add Notifications Options
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function add_settings() {

		wu_register_settings_field('sites', 'hide_notifications_subsites', array(
			'title'   => __('Hide Admin Notices on Sites', 'wp-ultimo'),
			'desc'    => __('Hide all admin notices on network sites, except for WP Multisite Subscriptions broadcasts.', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 0,
			'order'   => 25,
		));

	} // end add_settings;

} // end class Notification_Manager;
