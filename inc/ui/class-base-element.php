<?php
/**
 * Base class to UI elements that are rendered on the backend and the frontend.
 *
 * @package WP_Ultimo\UI
 * @subpackage Base_Element
 * @since 2.0.0
 */

namespace WP_Ultimo\UI;

use \WP_Ultimo\Database\Sites\Site_Type;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Base class to UI elements that are rendered on the backend and the frontend.
 *
 * @since 2.0.0
 */
abstract class Base_Element {

	/**
	 * The id of the element.
	 *
	 * Something simple, without prefixes, like 'checkout', or 'pricing-tables'.
	 *
	 * This is used to construct shortcodes by prefixing the id with 'wu_'
	 * e.g. an id checkout becomes the shortcode 'wu_checkout' and
	 * to generate the Gutenberg block by prefixing it with 'wp-ultimo/'
	 * e.g. checkout would become the block 'wp-ultimo/checkout'.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $id;

	/**
	 * Should this element be hidden by default?
	 *
	 * @since 2.0.0
	 * @var boolean
	 */
	protected $hidden_by_default = false;

	/**
	 * Controls whether or not the widget and element should display.
	 *
	 * @since 2.0.0
	 * @var boolean
	 */
	protected $display = true;

	/**
	 * Controls if this is a public element to be used in pages/shortcodes by user.
	 *
	 * @since 2.0.24
	 * @var boolean
	 */
	protected $public = false;

	/**
	 * Keep an array with registered public elements.
	 *
	 * @since 2.0.24
	 * @var boolean
	 */
	protected static $public_elements = array();

	/**
	 * If the element exists, we pre-load the parameters.
	 *
	 * @since 2.0.0
	 * @var false|array
	 */
	protected $pre_loaded_attributes = false;

	/**
	 * Only load (run the setup method) once,
	 *
	 * This is specially true when in the admin context,
	 *
	 * @since 2.0.0
	 * @var boolean
	 */
	protected $loaded = false;

	/**
	 * Keeps a cached list of metabox ids to shave some time.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	protected static $metabox_cache = null;

	/**
	 * Sometimes we need to know if the element was actually loaded
	 * - meaning found on a page - even if tall elements are forcefully
	 * setup.
	 *
	 * @since 2.0.11
	 * @var boolean
	 */
	protected $actually_loaded = false;

	/**
	 * The icon of the UI element.
	 *
	 * E.g. return fa fa-search.
	 *
	 * @since 2.0.0
	 * @param string $context One of the values: block, elementor or bb.
	 * @return string
	 */
	abstract public function get_icon($context = 'block'); // end get_icon;

	/**
	 * The title of the UI element.
	 *
	 * This is used on the Blocks list of Gutenberg.
	 * You should return a string with the localized title.
	 * e.g. return __('My Element', 'wp-ultimo').
	 *
	 * @since 2.0.0
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * The description of the UI element.
	 *
	 * This is also used on the Gutenberg block list
	 * to explain what this block is about.
	 * You should return a string with the localized title.
	 * e.g. return __('Adds a checkout form to the page', 'wp-ultimo').
	 *
	 * @since 2.0.0
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * The list of fields to be added to Gutenberg.
	 *
	 * If you plan to add Gutenberg controls to this block,
	 * you'll need to return an array of fields, following
	 * our fields interface (@see inc/ui/class-field.php).
	 *
	 * You can create new Gutenberg panels by adding fields
	 * with the type 'header'. See the Checkout Elements for reference.
	 *
	 * @see inc/ui/class-checkout-element.php
	 *
	 * Return an empty array if you don't have controls to add.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	abstract public function fields(); // end fields;

	/**
	 * The list of keywords for this element.
	 *
	 * Return an array of strings with keywords describing this
	 * element. Gutenberg uses this to help customers find blocks.
	 *
	 * e.g.:
	 * return array(
	 *  'WP Multisite Subscriptions',
	 *  'Checkout',
	 *  'Form',
	 *  'Cart',
	 * );
	 *
	 * @since 2.0.0
	 * @return array
	 */
	abstract public function keywords(); // end keywords;

	/**
	 * List of default parameters for the element.
	 *
	 * If you are planning to add controls using the fields,
	 * it might be a good idea to use this method to set defaults
	 * for the parameters you are expecting.
	 *
	 * These defaults will be used inside a 'wp_parse_args' call
	 * before passing the parameters down to the block render
	 * function and the shortcode render function.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	abstract public function defaults(); // end defaults;

	/**
	 * The content to be output on the screen.
	 *
	 * Should return HTML markup to be used to display the block.
	 * This method is shared between the block render method and
	 * the shortcode implementation.
	 *
	 * @since 2.0.0
	 *
	 * @param array       $atts Parameters of the block/shortcode.
	 * @param string|null $content The content inside the shortcode.
	 * @return string
	 */
	abstract public function output($atts, $content = null); // end output;

	// Boilerplate -----------------------------------

	/**
	 * Initializes the singleton.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {

		add_action('plugins_loaded', array($this, 'register_form'));

		add_action('init', array($this, 'register_shortcode'));

		add_action('wp_enqueue_scripts', array($this, 'enqueue_element_scripts'));

		add_action("wu_{$this->id}_scripts", array($this, 'register_default_scripts'));

		add_action("wu_{$this->id}_scripts", array($this, 'register_scripts'));

		add_action('wp', array($this, 'maybe_setup'));

		add_action('admin_head', array($this, 'setup_for_admin'), 100);

		add_filter('pre_render_block', array($this, 'setup_for_block_editor'), 100, 2);

		add_action('wu_element_preview', array($this, 'setup_preview'));

		// Init should be the correct time to call this to avoid the deprecated notice from I18N.
		// But it doesn't work for some reason, fix later.
//		add_action('init', function () {
			do_action('wu_element_loaded', $this);
//		} );

		if ($this->public) {

			Base_Element::register_public_element($this);

		} // end if;

	} // end init;

	/**
	 * Register a public element to further use.
	 *
	 * @since 2.0.24
	 * @param mixed $element The element instance to be registered.
	 * @return void
	 */
	public static function register_public_element($element) {

		static::$public_elements[] = $element;

	} // end register_public_element;

	/**
	 * Retrieves the public registered elements.
	 *
	 * @since 2.0.24
	 * @return array
	 */
	public static function get_public_elements() {

		return static::$public_elements;

	} // end get_public_elements;

	/**
	 * Sets blocks up for the block editor.
	 *
	 * @since 2.0.0
	 *
	 * @param null  $short_circuit The value passed.
	 * @param array $block The parsed block data.
	 * @return null
	 */
	public function setup_for_block_editor($short_circuit, $block) {

		$should_load = false;

		if ($block['blockName'] === $this->get_id()) {

			$should_load = true;

		} // end if;

		/**
		 * We might need to add additional blocks later.
		 *
		 * @since 2.0.0
		 * @return array
		 */
		$blocks_to_check = apply_filters('wu_element_block_types_to_check', array(
			'core/shortcode',
			'core/paragraph',
		));

		if (in_array($block['blockName'], $blocks_to_check, true)) {

			if ($this->contains_current_element($block['innerHTML'])) {

				$should_load = true;

			} // end if;

		} // end if;

		if ($should_load) {

			if ($this->is_preview()) {

				$this->setup_preview();

			} else {

				$this->setup();

			} // end if;

		} // end if;

		return $short_circuit;

	} // end setup_for_block_editor;

	/**
	 * Search for an element id on the list of metaboxes.
	 *
	 * Builds a cached list of elements on the first run.
	 * Then uses the cache to run a simple in_array check.
	 *
	 * @since 2.0.0
	 *
	 * @param string $element_id The element ID.
	 * @return bool
	 */
	protected static function search_in_metaboxes($element_id) {

		global $wp_meta_boxes, $pagenow;

		/*
		 * Bail if things don't look normal or in the right context.
		 */
		if (!function_exists('get_current_screen')) {

			return;

		} // end if;

		$screen = get_current_screen();

		/*
		 * First, check on cache, to avoid recalculating it time and time again.
		 */
		if (is_array(self::$metabox_cache)) {

			return in_array($element_id, self::$metabox_cache, true);

		} // end if;

		$contains_metaboxes = wu_get_isset($wp_meta_boxes, $screen->id) || wu_get_isset($wp_meta_boxes, $pagenow);

		$elements_to_cache = array();

		$found = false;

		if (is_array($wp_meta_boxes) && $contains_metaboxes && is_array($wp_meta_boxes[$screen->id])) {

			foreach ($wp_meta_boxes[$screen->id] as $position => $priorities) {

				foreach ($priorities as $priority => $metaboxes) {

					foreach ($metaboxes as $metabox_id => $metabox) {

						$elements_to_cache[] = $metabox_id;

						if ($metabox_id === $element_id) {

							$found = true;

						} // end if;

					} // end foreach;

				} // end foreach;

			} // end foreach;

			/**
			 * Set a local cache so we don't have to loop it all over again.
			 */
			self::$metabox_cache = $elements_to_cache;

		} // end if;

		return $found;

	} // end search_in_metaboxes;

	/**
	 * Setup element on admin pages.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function setup_for_admin() {

		if ($this->loaded === true) {

			return;

		} // end if;

		$element_id = "wp-ultimo-{$this->id}-element";

		if (self::search_in_metaboxes($element_id)) {

			$this->loaded = true;

			$this->setup();

		} // end if;

	} // end setup_for_admin;

	/**
	 * Maybe run setup, when the shortcode or block is found.
	 *
	 * @todo check if this is working only when necessary.
	 * @since 2.0.0
	 * @return void
	 */
	public function maybe_setup() {

		global $post;

		if (is_admin() || empty($post)) {

			return;

		} // end if;

		if ($this->contains_current_element($post->post_content, $post)) {

			if ($this->is_preview()) {

				$this->setup_preview();

			} else {

				$this->setup();

			} // end if;

		} // end if;

	} // end maybe_setup;

	/**
	 * Runs early on the request lifecycle as soon as we detect the shortcode is present.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function setup() {} // end setup;

	/**
	 * Allows the setup in the context of previews.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function setup_preview() {} // end setup_preview;

	/**
	 * Checks content to see if the current element is present.
	 *
	 * This check uses different methods, covering classic shortcodes,
	 * blocks. It also adds a generic filter so developers can
	 * add additional tests for different builders and so on.
	 *
	 * @since 2.0.0
	 *
	 * @param string        $content The content that might contain the element.
	 * @param null|\WP_Post $post The WP Post, if it exists.
	 * @return bool
	 */
	protected function contains_current_element($content, $post = null) {

		/**
		 * If parameters where pre-loaded,
		 * we can skip the entire check and return true.
		 */
		if (is_array($this->pre_loaded_attributes)) {

			return true;

		} // end if;

		/*
		 * First, check for default shortcodes
		 * saved as regular post content.
		 */
		$shortcode = $this->get_shortcode_id();

		if (has_shortcode($content, $shortcode)) {

			$this->pre_loaded_attributes = $this->maybe_extract_arguments($content, 'shortcode');

			$this->actually_loaded = true;

			return true;

		} // end if;

		/*
		 * Handle the Block Editor
		 * and Gutenberg.
		 */
		$block = $this->get_id();

		if (has_block($block, $content)) {

			$this->pre_loaded_attributes = $this->maybe_extract_arguments($content, 'block');

			$this->actually_loaded = true;

			return true;

		} // end if;

		/*
		 * Runs generic version so plugins can extend it.
		 */
		$this->pre_loaded_attributes = $this->maybe_extract_arguments($content, 'other');

		$contains_element = false;

		/**
		 * Last option is to check for the post force setting.
		 */
		if ($post && get_post_meta($post->ID, '_wu_force_elements_loading', true)) {

			$contains_element = true;

		} // end if;

		/**
		 * Allow developers to change the results of the initial search.
		 *
		 * This is useful for third-party builders and such.
		 *
		 * @since 2.0.0
		 * @param bool $contains_elements If the element is contained on the content.
		 * @param string $content The content being examined.
		 * @param self The current element.
		 */
		return apply_filters('wu_contains_element', $contains_element, $content, $this, $post);

	} // end contains_current_element;

	/**
	 * Tries to extract element arguments depending on the element type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $content The content to parse.
	 * @param string $type The element type. Can be one of shortcode, block, and other.
	 * @return false|array
	 */
	protected function maybe_extract_arguments($content, $type = 'shortcode') {

		if ($type === 'shortcode') {

			/**
			 * Tries to parse the shortcode out of the content
			 * passed using the WordPress shortcode regex.
			 */
			$shortcode_regex = get_shortcode_regex(array($this->get_shortcode_id()));

			preg_match_all('/' . $shortcode_regex . '/', $content, $matches, PREG_SET_ORDER);

			return !empty($matches) ? shortcode_parse_atts($matches[0][3]) : false;

		} elseif ($type === 'block') {

			/**
			 * Next, try to parse attrs from blocks
			 * by parsing them out and finding the correct one.
			 */
			$block_content = parse_blocks($content);

			foreach ($block_content as $block) {

				if ($block['blockName'] === $this->get_id()) {

					return $block['attrs'];

				} // end if;

			} // end foreach;

			return false;

		} // end if;

		/**
		 * Adds generic filter to allow developers
		 * to extend this parser to deal with additional
		 * builders or plugins.
		 *
		 * @since 2.0.0
		 * @return false|array
		 */
		return apply_filters('wu_element_maybe_extract_arguments', false, $content, $type, $this);

	} // end maybe_extract_arguments;

	/**
	 * Adds custom CSS to the signup screen.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function enqueue_element_scripts() {

		global $post;

		if (!is_a($post, '\WP_Post')) {

			return;

		} // end if;

		$should_enqueue_scripts = apply_filters('wu_element_should_enqueue_scripts', false, $post, $this->get_shortcode_id());

		if ($should_enqueue_scripts || $this->contains_current_element($post->post_content, $post)) {

			/**
			 * Triggers the enqueue scripts hook.
			 *
			 * This is used by the element to hook its
			 * register_scripts method.
			 *
			 * @since 2.0.0
			 */
			do_action("wu_{$this->id}_scripts", $post, $this);

		} // end if;

	} // end enqueue_element_scripts;

	/**
	 * Tries to parse the shortcode content on page load.
	 *
	 * This allow us to have access to parameters before the shortcode
	 * gets actually parsed by the post content functions such as
	 * the_content(). It is useful if you need to access that
	 * date way earlier in the page lifecycle.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name The parameter name.
	 * @param mixed  $default The default value.
	 * @return mixed
	 */
	public function get_pre_loaded_attribute($name, $default = false) {

		if ($this->pre_loaded_attributes === false || !is_array($this->pre_loaded_attributes)) {

			return false;

		} // end if;

		return wu_get_isset($this->pre_loaded_attributes, $name, $default);

	} // end get_pre_loaded_attribute;

	/**
	 * Registers the shortcode.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_shortcode() {

		if (wu_get_current_site()->get_type() === Site_Type::CUSTOMER_OWNED && is_admin() === false) {

			return;

		} // end if;

		add_shortcode($this->get_shortcode_id(), array($this, 'display'));

	} // end register_shortcode;

	/**
	 * Registers the forms.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_form() {
		/*
		 * Add Generator Forms
		 */
		wu_register_form("shortcode_{$this->id}", array(
			'render'     => array($this, 'render_generator_modal'),
			'handler'    => '__return_empty_string',
			'capability' => 'manage_network',
		));

		/*
		 * Add Customize Forms
		 */
		wu_register_form("customize_{$this->id}", array(
			'render'     => array($this, 'render_customize_modal'),
			'handler'    => array($this, 'handle_customize_modal'),
			'capability' => 'manage_network',
		));

	} // end register_form;

	/**
	 * Adds the modal to copy the shortcode for this particular element.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function render_generator_modal() {

		$fields = $this->fields();

		$defaults = $this->defaults();

		$state = array();

		foreach ($fields as $field_slug => &$field) {

			if ($field['type'] === 'header' || $field['type'] === 'note') {

				unset($fields[$field_slug]);

				continue;

			} // end if;

			/*
			 * Additional State.
			 *
			 * We need to keep track of the state
			 * specially when we're dealing with
			 * complex fields, such as group.
			 */
			$additional_state = array();

			if ($field['type'] === 'group') {

				foreach ($field['fields'] as $sub_field_slug => &$sub_field) {

					$sub_field['html_attr'] = array(
						'v-model.lazy' => "attributes.{$sub_field_slug}",
					);

					$additional_state[$sub_field_slug] = wu_request($sub_field_slug, wu_get_isset($defaults, $sub_field_slug));

				} // end foreach;

				continue;

			} // end if;

			/*
			 * Set v-model
			 */
			$field['html_attr'] = array(
				'v-model.lazy' => "attributes.{$field_slug}",
			);

			$required = wu_get_isset($field, 'required');

			if (wu_get_isset($field, 'required')) {

				$shows = array();

				foreach ($required as $key => $value) {

					$value = is_string($value) ? "\"$value\"" : $value;

					$shows[] = "attributes.{$key} == $value";

				} // end foreach;

				$field['wrapper_html_attr'] = array(
					'v-show' => implode(' && ', $shows),
				);

				$state[$field_slug . '_shortcode_requires'] = $required;

			} // end if;

			$state[$field_slug] = wu_request($field_slug, wu_get_isset($defaults, $field_slug));

		} // end foreach;

		$fields['shortcode_result'] = array(
			'type'            => 'note',
			'wrapper_classes' => 'sm:wu-block',
			'desc'            => '<div class="wu-w-full"><span class="wu-my-1 wu-text-2xs wu-uppercase wu-font-bold wu-block">' . __('Result', 'wp-ultimo') . '</span><pre v-html="shortcode" id="wu-shortcode" style="overflow-x: scroll !important;" class="wu-text-center wu-p-4 wu-m-0 wu-mt-2 wu-rounded wu-content-center wu-bg-gray-800 wu-text-white wu-font-mono wu-border wu-border-solid wu-border-gray-300 wu-max-h-screen wu-overflow-x-scroll"></pre></div>',
		);

		$fields['submit_copy'] = array(
			'type'            => 'submit',
			'title'           => __('Copy Shortcode', 'wp-ultimo'),
			'value'           => 'edit',
			'classes'         => 'button button-primary wu-w-full wu-copy',
			'wrapper_classes' => 'wu-items-end',
			'html_attr'       => array(
				'data-clipboard-action' => 'copy',
				'data-clipboard-target' => '#wu-shortcode',
			),
		);

		$form = new \WP_Ultimo\UI\Form($this->id, $fields, array(
			'views'                 => 'admin-pages/fields',
			'classes'               => 'wu-modal-form wu-widget-list wu-striped wu-m-0 wu-mt-0 wu-w-full',
			'field_wrapper_classes' => 'wu-w-full wu-box-border wu-items-center wu-flex wu-justify-between wu-p-4 wu-m-0 wu-border-t wu-border-l-0 wu-border-r-0 wu-border-b-0 wu-border-gray-300 wu-border-solid',
			'html_attr'             => array(
				'data-wu-app' => "{$this->id}_generator",
				'data-state'  => wu_convert_to_state(array(
					'id'         => $this->get_shortcode_id(),
					'defaults'   => $defaults,
					'attributes' => $state,
				)),
			),
		));

		echo '<div class="wu-styling">';

		$form->render();

		echo '</div>';

	} // end render_generator_modal;

	/**
	 * Adds the modal customize the widget block
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function render_customize_modal() {

		$fields = array();

		$fields['hide'] = array(
			'type'            => 'toggle',
			'title'           => __('Hide Element', 'wp-ultimo'),
			'desc'            => __('Be careful. Hiding an element from the account page might remove important functionality from your customers\' reach.', 'wp-ultimo'),
			'value'           => $this->hidden_by_default,
			'classes'         => 'button button-primary wu-w-full',
			'wrapper_classes' => 'wu-items-end',
		);

		$fields = array_merge($fields, $this->fields());

		$saved_settings = $this->get_widget_settings();

		$defaults = $this->defaults();

		$state = array_merge($defaults, $saved_settings);

		foreach ($fields as $field_slug => &$field) {

			if ($field['type'] === 'header') {

				unset($fields[$field_slug]);

				continue;

			} // end if;

			$value = wu_get_isset($saved_settings, $field_slug, null);

			if ($value !== null) {

				$field['value'] = $value;

			} // end if;

		} // end foreach;

		$fields['save_line'] = array(
			'type'            => 'group',
			'classes'         => 'wu-justify-between',
			'wrapper_classes' => 'wu-bg-gray-100',
			'fields'          => array(
				'restore' => array(
					'type'            => 'submit',
					'title'           => __('Reset Settings', 'wp-ultimo'),
					'value'           => 'edit',
					'classes'         => 'button',
					'wrapper_classes' => 'wu-mb-0',
				),
				'submit'  => array(
					'type'            => 'submit',
					'title'           => __('Save Changes', 'wp-ultimo'),
					'value'           => 'edit',
					'classes'         => 'button button-primary',
					'wrapper_classes' => 'wu-mb-0',
				),
			),
		);

		$form = new \WP_Ultimo\UI\Form($this->id, $fields, array(
			'views'                 => 'admin-pages/fields',
			'classes'               => 'wu-modal-form wu-widget-list wu-striped wu-m-0 wu-mt-0',
			'field_wrapper_classes' => 'wu-w-full wu-box-border wu-items-center wu-flex wu-justify-between wu-p-4 wu-m-0 wu-border-t wu-border-l-0 wu-border-r-0 wu-border-b-0 wu-border-gray-300 wu-border-solid',
			'html_attr'             => array(
				'data-wu-app' => "{$this->id}_customize",
				'data-state'  => wu_convert_to_state($state),
			),
		));

		echo '<div class="wu-styling">';

		$form->render();

		echo '</div>';

	} // end render_customize_modal;

	/**
	 * Saves the customization settings for a given widget.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function handle_customize_modal() {

		$settings = array();

		if (wu_request('submit') !== 'restore') {

			$fields = $this->fields();

			$fields['hide'] = array(
				'type' => 'toggle',
			);

			foreach ($fields as $field_slug => $field) {

				$setting = wu_request($field_slug, false);

				if ($setting !== false || $field['type'] === 'toggle') {

					$settings[$field_slug] = $setting;

				} // end if;

			} // end foreach;

		} // end if;

		$this->save_widget_settings($settings);

		wp_send_json_success(array(
			'send'         => array(
				'scope'         => 'window',
				'function_name' => 'wu_block_ui',
				'data'          => '#wpcontent',
			),
			'redirect_url' => add_query_arg('updated', 1, $_SERVER['HTTP_REFERER']),
		));

	} // end handle_customize_modal;

	/**
	 * Registers scripts and styles necessary to render this.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_default_scripts() {

		wp_enqueue_style('wu-admin');

	} // end register_default_scripts;

	/**
	 * Registers scripts and styles necessary to render this.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_scripts() {} // end register_scripts;

	/**
	 * Loads dependencies that might not be available at render time.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function dependencies() {} // end dependencies;

	/**
	 * Returns the ID of this UI element.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_id() {

		return sprintf('wp-ultimo/%s', $this->id);

	} // end get_id;

	/**
	 * Returns the ID of this UI element.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_shortcode_id() {

		return str_replace('-', '_', sprintf('wu_%s', $this->id));

	} // end get_shortcode_id;

	/**
	 * Treats the attributes before passing them down to the output method.
	 *
	 * @since 2.0.0
	 *
	 * @param array $atts The element attributes.
	 * @return string
	 */
	public function display($atts) {

		if (!$this->should_display()) {

			return; // bail if the display was set to false.

		} // end if;

		$this->dependencies();

		$atts = wp_parse_args($atts, $this->defaults());

		/*
		 * Account for the 'className' Gutenberg attribute.
		 */
		$atts['className'] = trim('wu-' . $this->id . ' wu-element ' . wu_get_isset($atts, 'className', ''));

		/*
		 * Pass down the element so we can use helpers.
		 */
		$atts['element'] = $this;

		return call_user_func(array($this, 'output'), $atts);

	} // end display;

	/**
	 * Retrieves a cleaned up version of the content.
	 *
	 * This method strips out vue reactivity tags and more.
	 *
	 * @since 2.0.0
	 *
	 * @param array $atts The element attributes.
	 * @return string
	 */
	public function display_template($atts) {

		$content = $this->display($atts);

		$content = str_replace(array(
			'v-',
			'data-wu',
			'data-state',
		), 'inactive-', $content);

		$content = str_replace(array(
			'{{',
			'}}',
		), '', $content);

		return $content;

	} // end display_template;

	/**
	 * Checks if we need to display admin management attachments.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function should_display_customize_controls() {

		return apply_filters('wu_element_display_super_admin_notice', current_user_can('manage_network'), $this);

	} // end should_display_customize_controls;

	/**
	 * Adds the element as a inline block, without the admin widget frame.
	 *
	 * @since 2.0.0
	 *
	 * @param string $screen_id The screen id.
	 * @param string $hook The hook to add the content to. Defaults to admin_notices.
	 * @param array  $atts Array containing the shortcode attributes.
	 * @return void
	 */
	public function as_inline_content($screen_id, $hook = 'admin_notices', $atts = array()) {

		if (!function_exists('get_current_screen')) {

			_doing_it_wrong(__METHOD__, __('An element can not be loaded as inline content unless the get_current_screen() function is already available.', 'wp-ultimo'), '2.0.0');

			return;

		} // end if;

		$screen = get_current_screen();

		if (!$screen || $screen_id !== $screen->id) {

			return;

		} // end if;

		/*
		 * Run the setup in this case;
		 */
		$this->setup();

		if (!$this->should_display()) {

			return; // bail if the display was set to false.

		} // end if;

		if (empty($atts)) {

			$atts = $this->get_widget_settings();

		} // end if;

		$control_classes = '';

		if (wu_get_isset($atts, 'hide', $this->hidden_by_default)) {

			if (!$this->should_display_customize_controls()) {

				return;

			} // end if;

			$control_classes = 'wu-customize-mode wu-opacity-25';

		} // end if;

		add_action($hook, function() use ($atts, $control_classes) {

			echo '<div class="wu-inline-widget">';

				echo '<div class="wu-inline-widget-body ' . $control_classes . '">';

					echo $this->display($atts);

				echo '</div>';

				$this->super_admin_notice();

			echo '</div>';

		});

		do_action("wu_{$this->id}_scripts", null, $this);

	} // end as_inline_content;

	/**
	 * Save the widget options.
	 *
	 * @since 2.0.0
	 *
	 * @param array $settings The settings to save. Key => value array.
	 * @return void
	 */
	public function save_widget_settings($settings) {

		$key = wu_replace_dashes($this->id);

		wu_save_setting("widget_{$key}_settings", $settings);

	} // end save_widget_settings;

	/**
	 * Retrieves the settings for a particular widget.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function get_widget_settings() {

		$key = wu_replace_dashes($this->id);

		return wu_get_setting("widget_{$key}_settings", array());

	} // end get_widget_settings;

	/**
	 * Adds the element as a metabox.
	 *
	 * @since 2.0.0
	 *
	 * @param string $screen_id The screen id.
	 * @param string $position Position on the screen.
	 * @param array  $atts Array containing the shortcode attributes.
	 * @return void
	 */
	public function as_metabox($screen_id, $position = 'normal', $atts = array()) {

		$this->setup();

		if (!$this->should_display()) {

			return; // bail if the display was set to false.

		} // end if;

		if (empty($atts)) {

			$atts = $this->get_widget_settings();

		} // end if;

		$control_classes = '';

		if (wu_get_isset($atts, 'hide')) {

			if (!$this->should_display_customize_controls()) {

				return;

			} // end if;

			$control_classes = 'wu-customize-mode wu-opacity-25';

		} // end if;

		add_meta_box(
			"wp-ultimo-{$this->id}-element",
			$this->get_title(),
			function() use ($atts, $control_classes) {

				echo '<div class="wu-metabox-widget ' . $control_classes . '">';

					echo $this->display($atts);

				echo '</div>';

				$this->super_admin_notice();

			},
			$screen_id,
			$position,
			'high'
		);

		do_action("wu_{$this->id}_scripts", null, $this);

	} // end as_metabox;

	/**
	 * Adds note for super admins.
	 *
	 * Adds an admin notice to let the super admin know
	 * how to use the widgets.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function super_admin_notice() {

		$should_display = $this->should_display_customize_controls();

		if ($should_display) {

			// translators: %1$s is the URL to the customize modal. %2$s is the URL of the shortcode generation modal
			$message = __('<a class="wubox wu-no-underline" title="Customize" href="%1$s">Customize this element</a>, or <a class="wubox wu-no-underline" title="Shortcode" href="%2$s">generate a shortcode</a> to use it on the front-end!', 'wp-ultimo');

			$message .= wu_tooltip(__('You are seeing this because you are a super admin', 'wp-ultimo'));

			$link_shortcode = wu_get_form_url("shortcode_{$this->id}");
			$link_customize = wu_get_form_url("customize_{$this->id}");

			$text = sprintf(
				$message,
				$link_customize,
				$link_shortcode
			);

			$html = '
				<div class="wu-styling">
						<div class="wu-widget-inset">
							<div class="wubox wu-no-underline wu-p-4 wu-bg-gray-200 wu-block wu-mt-4 wu-text-center wu-text-sm wu-text-gray-600 wu-m-auto wu-border-solid wu-border-0 wu-border-t wu-border-gray-400">
								' . $text . '
							</div>
					</div>
				</div>
			';

			echo $html;

		} // end if;

	} // end super_admin_notice;

	/**
	 * Checks if we are in a preview context.
	 *
	 * @since 2.0.0
	 * @return boolean
	 */
	public function is_preview() {

		$is_preview = false;

		if (did_action('init')) {

			$is_preview = wu_request('preview') && current_user_can('edit_posts');

		} // end if;

		return apply_filters('wu_element_is_preview', false, $this);

	} // end is_preview;

	/**
	 * Get controls whether or not the widget and element should display..
	 *
	 * @since 2.0.0
	 * @return boolean
	 */
	public function should_display() {

		return $this->display || $this->is_preview();

	} // end should_display;

	/**
	 * Set controls whether or not the widget and element should display..
	 *
	 * @since 2.0.0
	 * @param boolean $display Controls whether or not the widget and element should display.
	 * @return void
	 */
	public function set_display($display) {

		$this->display = $display;

	} // end set_display;

	/**
	 * Checks if the current element was actually loaded.
	 *
	 * @since 2.0.11
	 * @return boolean
	 */
	public function is_actually_loaded() {

		return $this->actually_loaded;

	} // end is_actually_loaded;

} // end class Base_Element;
