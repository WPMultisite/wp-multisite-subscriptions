<?php
/**
 * Admin bar shortcuts menu
 *
 * Adds the shortcuts menu to the admin bar.
 *
 * @category   WP Multisite Subscriptions
 * @package    WP_Ultimo
 * @author     Gustavo Modesto <gustavo@wpultimo.com>
 * @since      2.0.0
 */

namespace WP_Ultimo\Admin_Pages;

use WP_Ultimo\Settings;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * This class adds the top bar admin navigation menu
 *
 * @since 2.0.0
 */
class Top_Admin_Nav_Menu {

	/**
	 * Adds the hooks and actions
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {

		add_action('admin_bar_menu', array($this, 'add_top_bar_menus'), 50);

	} // end __construct;

	/**
	 * Adds the WP Multisite Subscriptions top-bar shortcut menu
	 *
	 * @since 1.1.0
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar identifier.
	 * @return void
	 */
	public function add_top_bar_menus($wp_admin_bar) {

    // Only for super admins
		if (!current_user_can('manage_network')) {

			return;

		} // end if;

    // Add Parent element
		$parent = array(
			'id'    => 'wp-ultimo',
			'title' => __('Multisite Waas', 'wp-ultimo'),
			'href'  => current_user_can('wu_read_dashboard') ? network_admin_url('admin.php?page=wp-ultimo') : '#',
			'meta'  => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the dashboard', 'wp-ultimo'),
			)
		);

    // Site
		$sites = array(
			'id'     => 'wp-ultimo-sites',
			'parent' => 'wp-ultimo',
			'title'  => __('Manage Sites', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-sites'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the sites page', 'wp-ultimo'),
			)
		);

    // Memberships
		$memberships = array(
			'id'     => 'wp-ultimo-memberships',
			'parent' => 'wp-ultimo',
			'title'  => __('Manage Memberships', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-memberships'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the memberships page', 'wp-ultimo'),
			)
		);

    // Customers
		$customers = array(
			'id'     => 'wp-ultimo-customers',
			'parent' => 'wp-ultimo',
			'title'  => __('Customers', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-customers'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the customers page', 'wp-ultimo'),
			)
		);

    // Products
		$products = array(
			'id'     => 'wp-ultimo-products',
			'parent' => 'wp-ultimo',
			'title'  => __('Products', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-products'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the products page', 'wp-ultimo'),
			)
		);

    // Payments
		$payments = array(
			'id'     => 'wp-ultimo-payments',
			'parent' => 'wp-ultimo',
			'title'  => __('Payments', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-payments'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the payments page', 'wp-ultimo'),
			)
		);

    // Discount Codes
		$discount_codes = array(
			'id'     => 'wp-ultimo-discount-codes',
			'parent' => 'wp-ultimo',
			'title'  => __('Discount Codes', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-discount-codes'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu',
				'title' => __('Go to the discount codes page', 'wp-ultimo'),
			)
		);

		$container = array(
			'id'     => 'wp-ultimo-settings-group',
			'parent' => 'wp-ultimo',
			'group'  => true,
			'title'  => __('Settings Container', 'wp-ultimo'),
			'href'   => '#',
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu ab-sub-secondary',
				'title' => __('Go to the settings page', 'wp-ultimo'),
			)
		);

    // Settings
		$settings = array(
			'id'     => 'wp-ultimo-settings',
			'parent' => 'wp-ultimo-settings-group',
			'title'  => __('Settings', 'wp-ultimo'),
			'href'   => network_admin_url('admin.php?page=wp-ultimo-settings'),
			'meta'   => array(
				'class' => 'wp-ultimo-top-menu ab-sub-secondary',
				'title' => __('Go to the settings page', 'wp-ultimo'),
			)
		);

		/**
		 * Add items to the top bar.
		 */
		$wp_admin_bar->add_node($parent);

		if (current_user_can('wu_read_sites')) {

			$wp_admin_bar->add_node($sites);

		} //end if;

		if (current_user_can('wu_read_memberships')) {

			$wp_admin_bar->add_node($memberships);

		} //end if;

		if (current_user_can('wu_read_customers')) {

			$wp_admin_bar->add_node($customers);

		} //end if;

		if (current_user_can('wu_read_products')) {

			$wp_admin_bar->add_node($products);

		} //end if;

		if (current_user_can('wu_read_payments')) {

			$wp_admin_bar->add_node($payments);
		} //end if;

		if (current_user_can('wu_read_discount_codes')) {

			$wp_admin_bar->add_node($discount_codes);
		} //end if;

		if (current_user_can('wu_read_settings')) {

			$wp_admin_bar->add_node($container);
			$wp_admin_bar->add_node($settings);

		} //end if;

		/*
		 * Add the sub-menus.
		 */
		$settings_tabs = Settings::get_instance()->get_sections();

		$has_addons = false;

		foreach ($settings_tabs as $tab => $tab_info) {

			if (wu_get_isset($tab_info, 'invisible')) {

				continue;

			} // end if;

			$parent = 'wp-ultimo-settings';

			if (wu_get_isset($tab_info, 'addon', false)) {

				$parent = 'wp-ultimo-settings-addons';

			} // end if;

			$settings_tab = array(
				'id'     => 'wp-ultimo-settings-' . $tab,
				'parent' => $parent,
				'title'  => $tab_info['title'],
				'href'   => network_admin_url('admin.php?page=wp-ultimo-settings&tab=') . $tab,
				'meta'   => array(
					'class' => 'wp-ultimo-top-menu',
					'title' => __('Go to the settings page', 'wp-ultimo'),
				)
			);

			$wp_admin_bar->add_node($settings_tab);

		} // end foreach;

	} // end add_top_bar_menus;

}  // end class Top_Admin_Nav_Menu;
