<?php
/**
 * Events Manager
 *
 * Handles processes related to events.
 *
 * @package WP_Ultimo
 * @subpackage Managers/Event_Manager
 * @since 2.0.0
 */

namespace WP_Ultimo\Managers;

use \WP_Ultimo\Managers\Base_Manager;
use WP_Ultimo\Models\Base_Model;
use \WP_Ultimo\Models\Event;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Handles processes related to events.
 *
 * @since 2.0.0
 */
class Event_Manager extends Base_Manager {

	use \WP_Ultimo\Apis\Rest_Api, \WP_Ultimo\Apis\WP_CLI, \WP_Ultimo\Traits\Singleton;

	/**
	 * The manager slug.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $slug = 'event';

	/**
	 * The model class associated to this manager.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $model_class = '\\WP_Ultimo\\Models\\Event';

	/**
	 * Holds the list of available events for webhooks.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	protected $events = array();

	/**
	 * The list of registered models events.
	 *
	 * @since 2.1.4
	 * @var array
	 */
	protected $models_events = array();

	/**
	 * Instantiate the necessary hooks.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {

		$this->enable_rest_api();

		$this->enable_wp_cli();

		add_action('init', array($this, 'register_all_events'));

		add_action('wp_ajax_wu_get_event_payload_preview', array($this, 'event_payload_preview'));

		add_action('rest_api_init', array($this, 'hooks_endpoint'));

		add_action('wu_model_post_save', array($this, 'log_transitions'), 10, 4);

		add_action('wu_daily', array($this, 'clean_old_events'));

	} // end init;

	/**
	 * Returns the payload to be displayed in the payload preview field.
	 * Log model transitions.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $model The model name.
	 * @param array      $data The data being saved, serialized.
	 * @param array      $data_unserialized The data being saved, un-serialized.
	 * @param Base_Model $object The object being saved.
	 * @return void
	 */
	public function log_transitions($model, $data, $data_unserialized, $object) {

		if ($model === 'event') {

			return;

		} // end if;

		/*
		 * Editing Model
		 */
		if (wu_get_isset($data_unserialized, 'id')) {

			$original = $object->_get_original();

			$diff = wu_array_recursive_diff($data_unserialized, $original);

			$keys_to_remove = apply_filters('wu_exclude_transitions_keys', array(
				'meta',
				'last_login',
				'ips',
				'query_class',
				'settings',
				'_compiled_product_list',
				'_gateway_info',
				'_limitations',
			));

			foreach ($keys_to_remove as $key_to_remove) {

				unset($diff[$key_to_remove]);

			} // end foreach;

			/**
			 * If empty, go home.
			 */
			if (empty($diff)) {

				return;

			} // end if;

			$changed = array();

			/**
			 * Loop changed data.
			 */
			foreach ($diff as $key => $new_value) {

				$old_value = wu_get_isset($original, $key, '');

				if ($key === 'id' && intval($old_value) === 0) {

					return;

				} // end if;

				if (empty(json_encode($old_value)) && empty(json_encode($new_value))) {

					return;

				} // end if;

				$changed[$key] = array(
					'old_value' => $old_value,
					'new_value' => $new_value,
				);

			} // end foreach;

			$event_data = array(
				'severity'    => Event::SEVERITY_INFO,
				'slug'        => 'changed',
				'object_type' => $model,
				'object_id'   => $object->get_id(),
				'payload'     => $changed,
			);

		} else {

			$event_data = array(
				'severity'    => Event::SEVERITY_INFO,
				'slug'        => 'created',
				'object_type' => $model,
				'object_id'   => $object->get_id(),
				'payload'     => array(),
			);

		} // end if;

		if (!empty($_POST) && is_user_logged_in()) {

			$event_data['initiator'] = 'manual';
			$event_data['author_id'] = get_current_user_id();

		} // end if;

		return wu_create_event($event_data);

	} // end log_transitions;

	/**
	 * Returns the payload to be displayed in the payload preview field.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function event_payload_preview() {

		if (!wu_request('event')) {

			wp_send_json_error(new \WP_Error('error', __('No event was selected.', 'wp-ultimo')));

		} // end if;

		$slug = wu_request('event');

		if (!$slug) {

			wp_send_json_error(new \WP_Error('not-found', __('Event was not found.', 'wp-ultimo')));

		} // end if;

		$event = wu_get_event_type($slug);

		if (!$event) {

			wp_send_json_error(new \WP_Error('not-found', __('Data not found.', 'wp-ultimo')));

		} else {

			$payload = isset($event['payload']) ? wu_maybe_lazy_load_payload($event['payload']) : '{}';

			$payload = array_map('htmlentities2', $payload);

			wp_send_json_success($payload);

		} // end if;

	} // end event_payload_preview;

	/**
	 * Returns the list of event types to register.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function get_event_type_as_options() {
		/*
		 * We use this to order the options.
		*/
		$event_type_settings = wu_get_setting('saving_type', array());

		$types = array(
			'id'         => '$id',
			'title'      => '$title',
			'desc'       => '$desc',
			'class_name' => '$class_name',
			'active'     => 'in_array($id, $active_gateways, true)',
			'active'     => 'in_array($id, $active_gateways, true)',
			'gateway'    => '$class_name', // Deprecated.
			'hidden'     => false,
		);

		$types = array_filter($types, fn($item) => $item['hidden'] === false);

		return $types;

	} // end get_event_type_as_options;

	/**
	 * Add a new event.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug The slug of the event. Something like payment_received.
	 * @param array  $payload with the events information.
	 *
	 * @return array with returns message for now.
	 */
	public function do_event($slug, $payload) {

		$registered_event = $this->get_event($slug);

		if (!$registered_event) {

			return array('error' => 'Event not found');

		} // end if;

		$payload_diff = array_diff_key(wu_maybe_lazy_load_payload($registered_event['payload']), $payload);

		if (isset($payload_diff[0])) {

			foreach ($payload_diff[0] as $diff_key => $diff_value) {

				return array('error' => 'Param required:' . $diff_key);

			} // end foreach;

		} // end if;

		$payload['wu_version'] = wu_get_version();

		do_action('wu_event', $slug, $payload);

		do_action("wu_event_{$slug}", $payload);

		/**
		 * Saves in the database
		 */
		$this->save_event($slug, $payload);

	} // end do_event;

	/**
	 * Register a new event to be used as param.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug The slug of the event. Something like payment_received.
	 * @param array  $args with the events information.
	 *
	 * @return true
	 */
	public function register_event($slug, $args): bool {

		$this->events[$slug] = $args;

		return true;

	} // end register_event;

	/**
	 * Returns the list of available webhook events.
	 *
	 * @since 2.0.0
	 * @return array $events with all events.
	 */
	public function get_events() {

		return $this->events;

	} // end get_events;

	/**
	 * Returns the list of available webhook events.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug of the event.
	 * @return array $event with event params.
	 */
	public function get_event($slug) {

		$events = $this->get_events();

		if ($events) {

			foreach ($events as $key => $event) {

				if ($key === $slug) {

					return $event;

				} // end if;

			} // end foreach;

		} // end if;

		return false;

	} // end get_event;

	/**
	 * Saves event in the database.
	 *
	 * @param string $slug of the event.
	 * @param array  $payload with event params.
	 * @return void.
	 */
	public function save_event($slug, $payload) {

		$event = new Event(array(
			'object_id'    => wu_get_isset($payload, 'object_id', ''),
			'object_type'  => wu_get_isset($payload, 'object_type', ''),
			'severity'     => wu_get_isset($payload, 'type', Event::SEVERITY_INFO),
			'date_created' => wu_get_current_time('mysql', true),
			'slug'         => strtolower($slug),
			'payload'      => $payload,
		));

		$event->save();

	} // end save_event;

	/**
	 * Registers the list of default events.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_all_events() {

		/**
		 * Payment Received.
		 */
		wu_register_event_type('payment_received', array(
			'name'            => __('Payment Received', 'wp-ultimo'),
			'desc'            => __('This event is fired every time a new payment is received, regardless of the payment status.', 'wp-ultimo'),
			'payload'         => fn() => array_merge(
					wu_generate_event_payload('payment'),
					wu_generate_event_payload('membership'),
					wu_generate_event_payload('customer')
				),
			'deprecated_args' => array(
				'user_id' => 'customer_user_id',
				'amount'  => 'payment_total',
				'gateway' => 'payment_gateway',
				'status'  => 'payment_status',
				'date'    => 'payment_date_created',
			),
		));

		/**
		 * Site Published.
		 */
		wu_register_event_type('site_published', array(
			'name'            => __('Site Published', 'wp-ultimo'),
			'desc'            => __('This event is fired every time a new site is created tied to a membership, or transitions from a pending state to a published state.', 'wp-ultimo'),
			'payload'         => fn() => array_merge(
					wu_generate_event_payload('site'),
					wu_generate_event_payload('customer'),
					wu_generate_event_payload('membership')
				),
			'deprecated_args' => array(),
		));

		/**
		 * Confirm Email Address
		 */
		wu_register_event_type('confirm_email_address', array(
			'name'            => __('Email Verification Needed', 'wp-ultimo'),
			'desc'            => __('This event is fired every time a new customer is added with an email verification status of pending.', 'wp-ultimo'),
			'payload'         => fn() => array_merge(
					array(
						'verification_link' => 'https://linktoverifyemail.com',
					),
					wu_generate_event_payload('customer')
				),
			'deprecated_args' => array(),
		));

		/**
		 * Domain Mapping Added
		 */
		wu_register_event_type('domain_created', array(
			'name'            => __('New Domain Mapping Added', 'wp-ultimo'),
			'desc'            => __('This event is fired every time a new domain mapping is added by a customer.', 'wp-ultimo'),
			'payload'         => fn() => array_merge(
					wu_generate_event_payload('domain'),
					wu_generate_event_payload('site'),
					wu_generate_event_payload('membership'),
					wu_generate_event_payload('customer')
				),
			'deprecated_args' => array(
				'user_id'       => 1,
				'user_site_id'  => 1,
				'mapped_domain' => 'mydomain.com',
				'user_site_url' => 'http://test.mynetwork.com/',
				'network_ip'    => '125.399.3.23',
			),
		));

		/**
		 * Renewal payment created
		 */
		wu_register_event_type('renewal_payment_created', array(
			'name'            => __('New Renewal Payment Created', 'wp-ultimo'),
			'desc'            => __('This event is fired every time a new renewal payment is created by WP Multisite Subscriptions.', 'wp-ultimo'),
			'payload'         => fn() => array_merge(
					array(
						'default_payment_url' => 'https://linktopayment.com',
					),
					wu_generate_event_payload('payment'),
					wu_generate_event_payload('membership'),
					wu_generate_event_payload('customer')
				),
			'deprecated_args' => array(),
		));

		$models = $this->models_events;

		foreach ($models as $model => $params) {

			foreach ($params['types'] as $type) {

				wu_register_event_type($model . '_' . $type, array(
					'name'            => sprintf(__('%1$s %2$s', 'wp-ultimo'), $params['label'], ucfirst($type)),
					'desc'            => sprintf(__('This event is fired every time a %1$s is %2$s by WP Multisite Subscriptions.', 'wp-ultimo'), $params['label'], $type),
					'deprecated_args' => array(),
					'payload'         => fn() => $this->get_model_payload($model),
				));

			} // end foreach;

			add_action("wu_{$model}_post_save", array($this, 'dispatch_base_model_event'), 10, 3);

		} // end foreach;

		do_action('wu_register_all_events');

	} // end register_all_events;

	/**
	 * Register models events
	 *
	 * @param string $slug slug of event.
	 * @param string $label label of event.
	 * @param array  $event_types event types allowed.
	 * @since 2.1.4
	 */
	public static function register_model_events(string $slug, string $label, array $event_types): void {

		$instance = self::get_instance();

		$instance->models_events[$slug] = array(
			'label' => $label,
			'types' => $event_types,
		);

	} // end register_model_events;

	/**
	 * Dispatch registered model events
	 *
	 * @param array $data Data.
	 * @param mixed $obj Object.
	 * @param bool  $new New.
	 *
	 * @since 2.1.4
	 */
	public function dispatch_base_model_event(array $data, $obj, bool $new): void {

		$model = $obj->model;

		$type = $new ? 'created' : 'updated';

		$registered_model = wu_get_isset($this->models_events, $model);

		if (!$registered_model || !in_array($type, $registered_model['types'], true)) {

			return;

		} // end if;

		$payload = $this->get_model_payload($model, $obj);

		wu_do_event($model . '_' . $type, $payload);

	} // end dispatch_base_model_event;

	/**
	 * Returns the full payload for a given model.
	 *
	 * @param string      $model The model name.
	 * @param object|null $model_object The model object.
	 * @return array
	 *
	 * @since 2.3.0
	 */
	public function get_model_payload(string $model, ?object $model_object = null) {

		$obj     = $model_object ?? call_user_func("wu_mock_{$model}");
		$payload = wu_generate_event_payload($model, $obj);

		if (method_exists($obj, 'get_membership')) {

			$membership = $model_object ? $obj->get_membership() : false;

			$payload = array_merge(
				$payload,
				wu_generate_event_payload('membership', $membership)
			);

		} // end if;

		if (method_exists($obj, 'get_customer')) {

			$customer = $model_object ? $obj->get_customer() : false;

			$payload = array_merge(
				$payload,
				wu_generate_event_payload('customer', $customer)
			);

		} // end if;

		if (method_exists($obj, 'get_billing_address') || method_exists($obj, 'get_membership')) {

			if ($model_object) {

				$payload = method_exists($obj, 'get_billing_address')
				? array_merge(
					$payload,
					$obj->get_billing_address()->to_array()
				) : array_merge(
					$payload,
					$obj->get_membership()->get_billing_address()->to_array()
				);

			} else {

				$payload = array_merge(
					$payload,
					array_map(function () {
						return '';
					}, \WP_Ultimo\Objects\Billing_Address::fields())
				);

			} // end if;

		} // end if;

		return $payload;

	} // end get_model_payload;

	/**
	 * Every day, deletes old events that we don't want to keep.
	 *
	 * @since 2.0.0
	 */
	public function clean_old_events(): bool {
		/*
		 * Add a filter setting this to 0 or false
		 * to prevent old events from being ever deleted.
		 */
		$threshold_days = apply_filters('wu_events_threshold_days', 1);

		if (empty($threshold_days)) {

			return false;

		} // end if;

		$events_to_remove = wu_get_events(array(
			'number'     => 100,
			'date_query' => array(
				'column'    => 'date_created',
				'before'    => "-{$threshold_days} days",
				'inclusive' => true,
			),
		));

		$success_count = 0;

		foreach ($events_to_remove as $event) {

			$status = $event->delete();

			if (!is_wp_error($status) && $status) {

				$success_count++;

			} // end if;

		} // end foreach;

		wu_log_add('wu-cron', sprintf(__('Removed %1$d events successfully. Failed to remove %2$d events.', 'wp-ultimo'), $success_count, count($events_to_remove) - $success_count));

		return true;

	} // end clean_old_events;

	/**
	 * Create a endpoint to retrieve all available event hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return mixed
	 */
	public function hooks_endpoint() {

		if (!wu_get_setting('enable_api', true)) {

			return;

		} // end if;

		$api = \WP_Ultimo\API::get_instance();

		register_rest_route($api->get_namespace(), '/hooks', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'get_hooks_rest'),
			'permission_callback' => array($api, 'check_authorization'),
		));

	} // end hooks_endpoint;

	/**
	 * Return all event types for the REST API request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request sent.
	 * @return mixed
	 */
	public function get_hooks_rest($request) {

		$response = wu_get_event_types();

		foreach ($response as $key => $value) {

			$payload = wu_get_isset($value, 'payload');

			if (is_callable($payload)) {

				$response[$key]['payload'] = $payload();

			} // end if;

		} // end foreach;

		return rest_ensure_response($response);

	} // end get_hooks_rest;

} // end class Event_Manager;
