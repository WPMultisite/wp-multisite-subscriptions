<?php
/**
 * Domain Mapping Manager
 *
 * Handles processes related to domain mappings,
 * things like adding hooks to add asynchronous checking of DNS settings and SSL certs and more.
 *
 * @package WP_Ultimo
 * @subpackage Managers/Domain_Manager
 * @since 2.0.0
 */

namespace WP_Ultimo\Managers;

use WP_Ultimo\Managers\Base_Manager;
use WP_Ultimo\Domain_Mapping\Helper;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Handles processes related to domain mappings.
 *
 * @since 2.0.0
 */
class Domain_Manager extends Base_Manager {

	use \WP_Ultimo\Apis\Rest_Api, \WP_Ultimo\Apis\WP_CLI, \WP_Ultimo\Traits\Singleton;

	/**
	 * The manager slug.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $slug = 'domain';

	/**
	 * The model class associated to this manager.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $model_class = '\\WP_Ultimo\\Models\\Domain';

	/**
	 * Holds a list of the current integrations for domain mapping.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	protected $integrations = array();

	/**
	 * Returns the list of available host integrations.
	 *
	 * This needs to be a filterable method to allow integrations to self-register.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function get_integrations() {

		return apply_filters('wu_domain_manager_get_integrations', $this->integrations, $this);

	} // end get_integrations;

	/**
	 * Get the instance of one of the integrations classes.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id The id of the integration. e.g. runcloud.
	 * @return WP_Ultimo\Integrations\Host_Providers\Base_Host_Provider
	 */
	public function get_integration_instance($id) {

		$integrations = $this->get_integrations();

		if (isset($integrations[$id])) {

			$class_name = $integrations[$id];

			return $class_name::get_instance();

		} // end if;

		return false;

	} // end get_integration_instance;

	/**
	 * Instantiate the necessary hooks.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {

		$this->enable_rest_api();

		$this->enable_wp_cli();

		$this->set_cookie_domain();

		add_action('plugins_loaded', array($this, 'load_integrations'));

		add_action('wp_ajax_wu_test_hosting_integration', array($this, 'test_integration'));

		add_action('wp_ajax_wu_get_dns_records', array($this, 'get_dns_records'));

		add_action('wu_async_remove_old_primary_domains', array($this, 'async_remove_old_primary_domains'));

		add_action('wu_async_process_domain_stage', array($this, 'async_process_domain_stage'), 10, 2);

		add_action('wu_transition_domain_domain', array($this, 'send_domain_to_host'), 10, 3);

		add_action('wu_settings_domain_mapping', array($this, 'add_domain_mapping_settings'));

		add_action('wu_settings_sso', array($this, 'add_sso_settings'));

		/*
		 * Add and remove mapped domains
		 */

		add_action('wu_domain_created', array($this, 'handle_domain_created'), 10, 3);

		add_action('wu_domain_post_delete', array($this, 'handle_domain_deleted'), 10, 3);

		/*
		 * Add and remove sub-domains
		 */

		add_action('wp_insert_site', array($this, 'handle_site_created'));

		add_action('wp_delete_site', array($this, 'handle_site_deleted'));

	} // end init;

	/**
	 * Set COOKIE_DOMAIN if not defined in sites with mapped domains.
	 *
	 * @since 2.0.12
	 *
	 * @return void
	 */
	protected function set_cookie_domain() {

		if (defined('DOMAIN_CURRENT_SITE') && !defined('COOKIE_DOMAIN') && !preg_match('/' . DOMAIN_CURRENT_SITE . '$/', '.' . $_SERVER['HTTP_HOST'])) {

			define( 'COOKIE_DOMAIN', '.' . $_SERVER['HTTP_HOST'] );

		} // end if;

	} // end set_cookie_domain;

	/**
	 * Triggers subdomain mapping events on site creation.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Site $site The site being added.
	 * @return void
	 */
	public function handle_site_created($site) {

		global $current_site;

		$has_subdomain = str_replace($current_site->domain, '', $site->domain);

		if (!$has_subdomain) {

			return;

		} // end if;

		$args = array(
			'subdomain' => $site->domain,
			'site_id'   => $site->blog_id,
		);

		wu_enqueue_async_action('wu_add_subdomain', $args, 'domain');

	} // end handle_site_created;

	/**
	 * Triggers subdomain mapping events on site deletion.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Site $site The site being removed.
	 * @return void
	 */
	public function handle_site_deleted($site) {

		global $current_site;

		$has_subdomain = str_replace($current_site->domain, '', $site->domain);

		if (!$has_subdomain) {

			return;

		} // end if;

		$args = array(
			'subdomain' => $site->domain,
			'site_id'   => $site->blog_id,
		);

		wu_enqueue_async_action('wu_remove_subdomain', $args, 'domain');

	} // end handle_site_deleted;

	/**
	 * Triggers the do_event of the payment successful.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Ultimo\Models\Domain     $domain The domain.
	 * @param \WP_Ultimo\Models\Site       $site The site.
	 * @param \WP_Ultimo\Models\Membership $membership The membership.
	 * @return void
	 */
	public function handle_domain_created($domain, $site, $membership) {

		$payload = array_merge(
			wu_generate_event_payload('domain', $domain),
			wu_generate_event_payload('site', $site),
			wu_generate_event_payload('membership', $membership),
			wu_generate_event_payload('customer', $membership->get_customer())
		);

		wu_do_event('domain_created', $payload);

	} // end handle_domain_created;

	/**
	 * Remove send domain removal event.
	 *
	 * @since 2.0.0
	 *
	 * @param boolean                  $result The result of the deletion.
	 * @param \WP_Ultimo\Models\Domain $domain The domain being deleted.
	 * @return void
	 */
	public function handle_domain_deleted($result, $domain) {

		if ($result) {

			$args = array(
				'domain'  => $domain->get_domain(),
				'site_id' => $domain->get_site_id(),
			);

			wu_enqueue_async_action('wu_remove_domain', $args, 'domain');

		} // end if;

	} // end handle_domain_deleted;

	/**
	 * Add all domain mapping settings.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function add_domain_mapping_settings() {

		wu_register_settings_field('domain-mapping', 'domain_mapping_header', array(
			'title' => __('Domain Mapping Settings', 'wp-ultimo'),
			'desc'  => __('Define the domain mapping settings for your network.', 'wp-ultimo'),
			'type'  => 'header',
		));

		wu_register_settings_field('domain-mapping', 'enable_domain_mapping', array(
			'title'   => __('Enable Domain Mapping?', 'wp-ultimo'),
			'desc'    => __('Do you want to enable domain mapping?', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 1,
		));

		wu_register_settings_field('domain-mapping', 'force_admin_redirect', array(
			'title'   => __('Force Admin Redirect', 'wp-ultimo'),
			'desc'    => __('Select how you want your users to access the admin panel if they have mapped domains.', 'wp-ultimo') . '<br><br>' . __('Force Redirect to Mapped Domain: your users with mapped domains will be redirected to theirdomain.com/wp-admin, even if they access using yournetworkdomain.com/wp-admin.', 'wp-ultimo') . '<br><br>' . __('Force Redirect to Network Domain: your users with mapped domains will be redirect to yournetworkdomain.com/wp-admin, even if they access using theirdomain.com/wp-admin.', 'wp-ultimo'),
			'tooltip' => '',
			'type'    => 'select',
			'default' => 'both',
			'require' => array('enable_domain_mapping' => 1),
			'options' => array(
				'both'          => __('Allow access to the admin by both mapped domain and network domain', 'wp-ultimo'),
				'force_map'     => __('Force Redirect to Mapped Domain', 'wp-ultimo'),
				'force_network' => __('Force Redirect to Network Domain', 'wp-ultimo'),
			),
		));

		wu_register_settings_field('domain-mapping', 'custom_domains', array(
			'title'   => __('Enable Custom Domains?', 'wp-ultimo'),
			'desc'    => __('Toggle this option if you wish to allow end-customers to add their own domains. This can be controlled on a plan per plan basis.', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 1,
			'require' => array(
				'enable_domain_mapping' => true,
			),
		));

		wu_register_settings_field('domain-mapping', 'domain_mapping_instructions', array(
			'title'     => __('Add New Domain Instructions', 'wp-ultimo'),
			'tooltip'   => __('Display a customized message with instructions for the mapping and alerting the end-user of the risks of mapping a misconfigured domain.', 'wp-ultimo'),
			'desc'      => __('You can use the placeholder <code>%NETWORK_DOMAIN%</code> and <code>%NETWORK_IP%</code>.', 'wp-ultimo'),
			'type'      => 'textarea',
			'default'   => array($this, 'default_domain_mapping_instructions'),
			'html_attr' => array(
				'rows' => 8,
			),
			'require'   => array(
				'enable_domain_mapping' => true,
				'custom_domains'        => true,
			),
		));

	} // end add_domain_mapping_settings;

	/**
	 * Add all SSO settings.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function add_sso_settings() {

		wu_register_settings_field('sso', 'sso_header', array(
			'title' => __('Single Sign-On Settings', 'wp-ultimo'),
			'desc'  => __('Settings to configure the Single Sign-On functionality of WP Multisite Subscriptions, responsible for keeping customers and admins logged in across all network domains.', 'wp-ultimo'),
			'type'  => 'header',
		));

		wu_register_settings_field('sso', 'enable_sso', array(
			'title'   => __('Enable Single Sign-On', 'wp-ultimo'),
			'desc'    => __('Enables the Single Sign-on functionality.', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 1,
		));

		wu_register_settings_field('sso', 'restrict_sso_to_login_pages', array(
			'title'   => __('Restrict SSO Checks to Login Pages', 'wp-ultimo'),
			'desc'    => __('The Single Sign-on feature adds one extra ajax calls to every page load on sites with custom domains active to check if it should perform an auth loopback. You can restrict these extra calls to the login pages of sub-sites using this option. If enabled, SSO will only work on login pages.', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 0,
			'require' => array(
				'enable_sso' => true,
			),
		));

		wu_register_settings_field('sso', 'enable_sso_loading_overlay', array(
			'title'   => __('Enable SSO Loading Overlay', 'wp-ultimo'),
			'desc'    => __('When active, a loading overlay will be added on-top of the site currently being viewed while the SSO auth loopback is performed on the background.', 'wp-ultimo'),
			'type'    => 'toggle',
			'default' => 1,
			'require' => array(
				'enable_sso' => true,
			),
		));

	} // end add_sso_settings;
	/**
	 * Returns the default instructions for domain mapping.
	 *
	 * @since 2.0.0
	 */
	public function default_domain_mapping_instructions(): string {

		$instructions = array();

		$instructions[] = __("Cool! You're about to make this site accessible using your own domain name!", 'wp-ultimo');

		$instructions[] = __("For that to work, you'll need to create a new CNAME record pointing to <code>%NETWORK_DOMAIN%</code> on your DNS manager.", 'wp-ultimo');

		$instructions[] = __('After you finish that step, come back to this screen and click the button below.', 'wp-ultimo');

		return implode(PHP_EOL . PHP_EOL, $instructions);

	} // end default_domain_mapping_instructions;

	/**
	 * Gets the instructions, filtered and without the shortcodes.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_domain_mapping_instructions() {

		global $current_site;

		$instructions = wu_get_setting('domain_mapping_instructions');

		if (!$instructions) {

			$instructions = $this->default_domain_mapping_instructions();

		} // end if;

		$domain = $current_site->domain;
		$ip     = Helper::get_network_public_ip();

		/*
		 * Replace placeholders
		 */
		$instructions = str_replace('%NETWORK_DOMAIN%', $domain, (string) $instructions);
		$instructions = str_replace('%NETWORK_IP%', $ip, $instructions);

		return apply_filters('wu_get_domain_mapping_instructions', $instructions, $domain, $ip);

	} // end get_domain_mapping_instructions;

	/**
	 * Creates the event to save the transition.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $old_value The old value, before the transition.
	 * @param mixed $new_value The new value, after the transition.
	 * @param int   $item_id The id of the element transitioning.
	 * @return void
	 */
	public function send_domain_to_host($old_value, $new_value, $item_id) {

		if ($old_value !== $new_value) {

			$domain = wu_get_domain($item_id);

			$args = array(
				'domain'  => $new_value,
				'site_id' => $domain->get_site_id(),
			);

			wu_enqueue_async_action('wu_add_domain', $args, 'domain');

		} // end if;

	} // end send_domain_to_host;

	/**
	 * Checks the DNS and SSL status of a domain.
	 *
	 * @since 2.0.0
	 *
	 * @param int $domain_id The domain mapping ID.
	 * @param int $tries Number of tries.
	 * @return void
	 */
	public function async_process_domain_stage($domain_id, $tries = 0) {

		$domain = wu_get_domain($domain_id);

		if (!$domain) {

			return;

		} // end if;

		$max_tries = apply_filters('wu_async_process_domain_stage_max_tries', 5, $domain);

		$try_again_time = apply_filters('wu_async_process_domains_try_again_time', 5, $domain); // minutes

		$tries++;

		$stage = $domain->get_stage();

		$domain_url = $domain->get_domain();

		// translators: %s is the domain name
		wu_log_add("domain-{$domain_url}", sprintf(__('Starting Check for %s', 'wp-ultimo'), $domain_url));

		if ($stage === 'checking-dns') {

			if ($domain->has_correct_dns()) {

				$domain->set_stage('checking-ssl-cert');

				$domain->save();

				wu_log_add(
					"domain-{$domain_url}",
					__('- DNS propagation finished, advancing domain to next step...', 'wp-ultimo')
				);

				wu_enqueue_async_action('wu_async_process_domain_stage', array('domain_id' => $domain_id, 'tries' => 0), 'domain');

				do_action('wu_domain_manager_dns_propagation_finished', $domain);

				return;

			} else {
				/*
				 * Max attempts
				 */
				if ($tries > $max_tries) {

					$domain->set_stage('failed');

					$domain->save();

					wu_log_add(
						"domain-{$domain_url}",
						// translators: %d is the number of minutes to try again.
						sprintf(__('- DNS propagation checks tried for the max amount of times (5 times, one every %d minutes). Marking as failed.', 'wp-ultimo'), $try_again_time)
					);

					return;

				} // end if;

				wu_log_add(
					"domain-{$domain_url}",
					// translators: %d is the number of minutes before trying again.
					sprintf(__('- DNS propagation not finished, retrying in %d minutes...', 'wp-ultimo'), $try_again_time)
				);

				wu_schedule_single_action(
					strtotime("+{$try_again_time} minutes"),
					'wu_async_process_domain_stage',
					array(
						'domain_id' => $domain_id,
						'tries'     => $tries,
					),
					'domain'
				);

				return;

			} // end if;

		} elseif ($stage === 'checking-ssl-cert') {

			if ($domain->has_valid_ssl_certificate()) {

				$domain->set_stage('done');

				$domain->set_secure(true);

				$domain->save();

				wu_log_add(
					"domain-{$domain_url}",
					__('- Valid SSL cert found. Marking domain as done.', 'wp-ultimo')
				);

				return;

			} else {
				/*
				 * Max attempts
				 */
				if ($tries > $max_tries) {

					$domain->set_stage('done-without-ssl');

					$domain->save();

					wu_log_add(
						"domain-{$domain_url}",
						// translators: %d is the number of minutes to try again.
						sprintf(__('- SSL checks tried for the max amount of times (5 times, one every %d minutes). Marking as ready without SSL.', 'wp-ultimo'), $try_again_time)
					);

					return;

				} // end if;

				wu_log_add(
					"domain-{$domain_url}",
					// translators: %d is the number of minutes before trying again.
					sprintf(__('- SSL Cert not found, retrying in %d minute(s)...', 'wp-ultimo'), $try_again_time)
				);

				wu_schedule_single_action(strtotime("+{$try_again_time} minutes"), 'wu_async_process_domain_stage', array('domain_id' => $domain_id, 'tries' => $tries), 'domain');

				return;

			} // end if;

		} // end if;

	} // end async_process_domain_stage;

	/**
	 * Alternative implementation for PHP's native dns_get_record.
	 *
	 * @since 2.0.0
	 * @param string $domain The domain to check.
	 * @return array
	 */
	public static function dns_get_record($domain) {

		$results = array();

		wu_setup_memory_limit_trap('json');

		wu_try_unlimited_server_limits();

		$record_types = array(
			'NS',
			'CNAME',
			'A',
		);

		foreach ($record_types as $record_type) {

			$chain = new \WP_Ultimo\Dependencies\RemotelyLiving\PHPDNS\Resolvers\Chain(
				new \WP_Ultimo\Dependencies\RemotelyLiving\PHPDNS\Resolvers\CloudFlare(),
				new \WP_Ultimo\Dependencies\RemotelyLiving\PHPDNS\Resolvers\GoogleDNS(),
				new \WP_Ultimo\Dependencies\RemotelyLiving\PHPDNS\Resolvers\LocalSystem(),
				new \WP_Ultimo\Dependencies\RemotelyLiving\PHPDNS\Resolvers\Dig(),
			);

			$records = $chain->getRecords($domain, $record_type);

			foreach ($records as $record_data) {

				$record = array();

				$record['type'] = $record_type;

				$record['data'] = (string) $record_data->getData();

				if (empty($record['data'])) {

					$record['data'] = (string) $record_data->getIPAddress();

				} // end if;

				// Some DNS providers return a trailing dot.
				$record['data'] = rtrim($record['data'], '.');

				$record['ip'] = (string) $record_data->getIPAddress();

				$record['ttl'] = $record_data->getTTL();

				$record['host'] = $domain;

				$record['tag'] = ''; // Used by integrations.

				$results[] = $record;

			} // end foreach;

		} // end foreach;

		return apply_filters('wu_domain_dns_get_record', $results, $domain);

	} // end dns_get_record;

	/**
	 * Get the DNS records for a given domain.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function get_dns_records() {

		$domain = wu_request('domain');

		if (!$domain) {

			wp_send_json_error(new \WP_Error('domain-missing', __('A valid domain was not passed.', 'wp-ultimo')));

		} // end if;

		$auth_ns = array();

		$additional = array();

		try {

			$result = self::dns_get_record($domain);

		} catch (\Throwable $e) {

			wp_send_json_error(new \WP_Error('error', __('Not able to fetch DNS entries.', 'wp-ultimo'), array(
				'exception' => $e->getMessage(),
			)));

		} // end try;

		if ($result === false) {

			wp_send_json_error(new \WP_Error('error', __('Not able to fetch DNS entries.', 'wp-ultimo')));

		} // end if;

		wp_send_json_success(array(
			'entries'    => $result,
			'auth'       => $auth_ns,
			'additional' => $additional,
			'network_ip' => Helper::get_network_public_ip(),
		));

	} // end get_dns_records;

	/**
	 * Takes the list of domains and set them to non-primary when a new primary is added.
	 *
	 * This is triggered when a new domain is added as primary_domain.
	 *
	 * @since 2.0.0
	 *
	 * @param array $domains List of domain ids.
	 * @return void
	 */
	public function async_remove_old_primary_domains($domains) {

		foreach ($domains as $domain_id) {

			$domain = wu_get_domain($domain_id);

			if ($domain) {

				$domain->set_primary_domain(false);

				$domain->save();

			} // end if;

		} // end foreach;

	} // end async_remove_old_primary_domains;

	/**
	 * Tests the integration in the Wizard context.
	 *
	 * @since 2.0.0
	 * @return mixed
	 */
	public function test_integration() {

		$integration_id = wu_request('integration', 'none');

		$integration = $this->get_integration_instance($integration_id);

		if (!$integration) {

			wp_send_json_error(array(
				'message' => __('Invalid Integration ID', 'wp-ultimo'),
			));

		} // end if;

		/*
		 * Checks for the constants...
		 */
		if (!$integration->is_setup()) {

			wp_send_json_error(array(
				'message' => sprintf(
					__('The necessary constants were not found on your wp-config.php file: %s', 'wp-ultimo'),
					implode(', ', $integration->get_missing_constants())
				),
			));

		} // end if;

		return $integration->test_connection();

	} // end test_integration;

	/**
	 * Loads all the host provider integrations we have available.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function load_integrations() {
		/*
		* Loads our RunCloud integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\Runcloud_Host_Provider::get_instance();

		/*
		* Loads our Closte integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\Closte_Host_Provider::get_instance();

		/*
		* Loads our WP Engine integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\WPEngine_Host_Provider::get_instance();

		/*
		* Loads our Gridpane integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\Gridpane_Host_Provider::get_instance();

		/*
		* Loads our WPMU DEV integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\WPMUDEV_Host_Provider::get_instance();

		/*
		* Loads our Cloudways integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\Cloudways_Host_Provider::get_instance();

		/*
		* Loads our ServerPilot integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\ServerPilot_Host_Provider::get_instance();

		/*
		* Loads our cPanel integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\CPanel_Host_Provider::get_instance();

		/*
		* Loads our Cloudflare integration.
		*/
		\WP_Ultimo\Integrations\Host_Providers\Cloudflare_Host_Provider::get_instance();

		/**
		 * Allow developers to add their own host provider integrations via wp plugins.
	 	 *
		 * @since 2.0.0
		 */
		do_action('wp_ultimo_host_providers_load');

	} // end load_integrations;

} // end class Domain_Manager;
