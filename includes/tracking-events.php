<?php
/**
 * Tracking Events for Mauka Meta Pixel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracking Events Class
 */
class Mauka_Meta_Pixel_Tracking {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Standard tracking events
        add_action('wp_footer', array($this, 'track_page_view'));
        
        // WooCommerce hooks
        if (class_exists('WooCommerce')) {
            // Product view
            add_action('woocommerce_single_product_summary', array($this, 'track_view_content'), 25);

            // Category view
            add_action('woocommerce_archive_description', array($this, 'track_view_category'));

            // Add to Wishlist (YITH)
            add_action('yith_wcwl_add_to_wishlist', array($this, 'track_add_to_wishlist'), 10, 2);
            
            // Add to cart
            add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
            
            // Checkout started
            add_action('woocommerce_checkout_order_review', array($this, 'track_initiate_checkout'));

            // Add Payment Info
            add_action('woocommerce_review_order_before_payment', array($this, 'track_add_payment_info'));
            
            // Purchase completed
            add_action('woocommerce_thankyou', array($this, 'track_purchase'));
            
            // Registration completed
            add_action('woocommerce_created_customer', array($this, 'track_complete_registration'));
            
            // Search
            add_action('pre_get_posts', array($this, 'track_search'));
            
            // AJAX add to cart (for shop page)
            add_action('wp_ajax_woocommerce_add_to_cart_variable_rc', array($this, 'ajax_add_to_cart'));
            add_action('wp_ajax_nopriv_woocommerce_add_to_cart_variable_rc', array($this, 'ajax_add_to_cart'));
        }
        
        // Contact form events
        add_action('wpcf7_mail_sent', array($this, 'track_contact_form_lead'));
        add_action('gform_after_submission', array($this, 'track_gravity_form_lead'), 10, 2);
    }
    
    /**
     * Track PageView event
     */
    public function track_page_view() {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('PageView')) {
            return;
        }
        // Skip AJAX requests and repeated loads on checkout page to avoid duplicates
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('WC_DOING_AJAX') && WC_DOING_AJAX) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout() && ( !function_exists('is_order_received_page') || !is_order_received_page())) {
            return;
        }
        
        // Prevent multiple PageView events on same page load
        static $pageview_sent = false;
        if ($pageview_sent) {
            return;
        }
        $pageview_sent = true;
        
        // Generate event ID for deduplication
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('PageView', get_the_ID());
        $event_time = time();
        
        // Add to page for pixel tracking
        $this->add_pixel_event('PageView', array('event_id' => $event_id));
        
        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('PageView', array('event_id' => $event_id), array(), null, $event_time);
    }
    
    /**
     * Track ViewContent event (product pages)
     */
    public function track_view_content() {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('ViewContent') ||
            (defined('DOING_AJAX') && DOING_AJAX) || 
            !function_exists('is_product') || 
            !is_product()) {
            return;
        }
        
        global $product;
        if (!$product) {
            $product = wc_get_product(get_the_ID());
        }
        
        if (!$product) {
            return;
        }
        
        $product_id = $product->get_id();
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('ViewContent', $product_id);
        $event_time = time();
        
        // Get product data
        $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
        
        // Build contents array with item_price for better match quality
        $contents = array(
            array(
                'id' => $product_data['content_id'],
                'quantity' => 1,
                'item_price' => $product_data['value'],
            ),
        );
        
        // Enhanced data for better CAPI coverage and event matching
        $enhanced_data = array(
            'content_name' => $product_data['content_name'],
            'content_ids' => array($product_data['content_id']),
            'content_type' => 'product',
            'contents' => $contents,
            'value' => $product_data['value'],
            'currency' => $product_data['currency'],
        );
        
        // Always include these fields for better matching regardless of enhanced_catalog_matching setting
        if (!empty($product_data['content_category'])) {
            $enhanced_data['content_category'] = $product_data['content_category'];
        }
        if (!empty($product_data['brand'])) {
            $enhanced_data['brand'] = $product_data['brand'];
        }
        if (!empty($product_data['availability'])) {
            $enhanced_data['availability'] = $product_data['availability'];
        }
        
        // Add to page for pixel tracking
        $this->add_pixel_event('ViewContent', array_merge(
            array('event_id' => $event_id),
            $enhanced_data
        ));
        
        // Send CAPI event with enhanced data for better matching
        Mauka_Meta_Pixel_Helpers::send_capi_event('ViewContent',
            array('event_id' => $event_id),
            $enhanced_data,
            null,
            $event_time
        );
    }
    
    /**
     * Track ViewCategory event
     */
    public function track_view_category() {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('ViewCategory') ||
            (defined('DOING_AJAX') && DOING_AJAX) || 
            !function_exists('is_product_category') || 
            !is_product_category()) {
            return;
        }

        $term = get_queried_object();
        if (!$term || !isset($term->term_id)) {
            return;
        }

        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('ViewCategory', $term->term_id);
        $event_time = time();

        // Add to page for pixel tracking
        $this->add_pixel_event('ViewCategory', array(
            'event_id' => $event_id,
            'content_name' => $term->name,
            'content_category' => $term->name,
            'content_type' => 'product_group',
        ));

        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('ViewCategory',
            array('event_id' => $event_id),
            array(
                'content_name' => $term->name,
                'content_category' => $term->name,
                'content_type' => 'product_group',
            ),
            null,
            $event_time
        );
    }

    /**
     * Track AddToWishlist event (YITH)
     */
    public function track_add_to_wishlist($product_id, $wishlist_id) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('AddToWishlist')) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('AddToWishlist', $product_id . '_' . $wishlist_id);
        $event_time = time();
        $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);

        // Add to page for pixel tracking
        $this->add_pixel_event('AddToWishlist', array(
            'event_id' => $event_id,
            'content_name' => $product_data['content_name'],
            'content_ids' => array($product_data['content_id']),
            'content_type' => 'product',
            'value' => $product_data['value'],
            'currency' => $product_data['currency'],
        ));

        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('AddToWishlist',
            array('event_id' => $event_id),
            array(
                'content_name' => $product_data['content_name'],
                'content_ids' => array($product_data['content_id']),
                'content_type' => 'product',
                'value' => $product_data['value'],
                'currency' => $product_data['currency'],
            ),
            null,
            $event_time
        );
    }

    /**
     * Track AddPaymentInfo event
     */
    public function track_add_payment_info() {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('AddPaymentInfo') || 
            !function_exists('is_checkout') || 
            !is_checkout() || 
            !function_exists('is_order_received_page') || 
            is_order_received_page()) {
            return;
        }

        // Prevent multiple triggers
        static $triggered = false;
        if ($triggered) {
            return;
        }
        $triggered = true;

        if (!function_exists('WC') || !WC()->cart || !method_exists(WC()->cart, 'is_empty') || WC()->cart->is_empty()) {
            return;
        }

        $cart = WC()->cart;
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('AddPaymentInfo', $cart->get_cart_hash());

        $content_ids = array();
        $contents = array();
        
        // Use enhanced product data for better catalog matching
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
            
            $content_ids[] = $product_data['content_id'];
            $contents[] = array(
                'id' => $product_data['content_id'],
                'quantity' => $cart_item['quantity'],
                'item_price' => ( isset($cart_item['data']) && method_exists($cart_item['data'], 'get_price') ) ? (float) $cart_item['data']->get_price() : 0,
            );
        }

        $event_time = time();

        // Add to page for pixel tracking
        $this->add_pixel_event('AddPaymentInfo', array(
            'event_id' => $event_id,
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => $cart->get_total('edit'),
            'currency' => get_woocommerce_currency(),
            'num_items' => $cart->get_cart_contents_count(),
        ));

        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('AddPaymentInfo',
            array('event_id' => $event_id),
            array(
                'content_ids' => $content_ids,
                'contents' => $contents,
                'content_type' => 'product',
                'value' => (float) $cart->get_total('edit'),
                'currency' => get_woocommerce_currency(),
                'num_items' => $cart->get_cart_contents_count(),
            ),
            null,
            $event_time
        );
    }

    /**
     * Track AddToCart event
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('AddToCart')) {
            return;
        }

        $actual_product_id = $variation_id ? $variation_id : $product_id;
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('AddToCart', $actual_product_id . '_' . $quantity);
        $event_time = time();

        $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($actual_product_id);

        // Build contents with item_price for better match quality
        $contents = array(
            array(
                'id' => $product_data['content_id'],
                'quantity' => $quantity,
                'item_price' => $product_data['value'],
            ),
        );

        // Enhanced data for better CAPI coverage and event matching
        $enhanced_data = array(
            'content_name' => $product_data['content_name'],
            'content_ids'  => array($product_data['content_id']),
            'content_type' => 'product',
            'contents'     => $contents,
            'value'        => $product_data['value'] * $quantity,
            'currency'     => $product_data['currency'],
            'num_items'    => $quantity,
        );

        // Always include these fields for better matching regardless of enhanced_catalog_matching setting
        if (!empty($product_data['content_category'])) {
            $enhanced_data['content_category'] = $product_data['content_category'];
        }
        if (!empty($product_data['brand'])) {
            $enhanced_data['brand'] = $product_data['brand'];
        }
        if (!empty($product_data['availability'])) {
            $enhanced_data['availability'] = $product_data['availability'];
        }

        // Add to page for pixel tracking
        $this->add_pixel_event('AddToCart', array_merge(
            array('event_id' => $event_id),
            $enhanced_data
        ));

        // Send CAPI event with enhanced data for better matching
        Mauka_Meta_Pixel_Helpers::send_capi_event('AddToCart',
            array('event_id' => $event_id),
            $enhanced_data,
            null,
            $event_time
        );
    }

    /**
     * Helper to buffer pixel events for rendering in footer
     */
    private function add_pixel_event($event_name, $event_data = array()) {
        add_filter('mauka_meta_pixel_events', function($events) use ($event_name, $event_data) {
            $events[] = array(
                'event_name' => $event_name,
                'event_data' => $event_data,
            );
            return $events;
        });
    }

    /**
     * Track InitiateCheckout event
     */
    public function track_initiate_checkout() {
        if ( $this->should_skip_request() ) { return; }
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout') || !function_exists('is_checkout') || !is_checkout() || (function_exists('is_order_received_page') && is_order_received_page())) {
            return;
        }
        static $triggered = false;
        if ($triggered) {
            return;
        }
        $triggered = true;
        if (!function_exists('WC') || !WC()->cart || !method_exists(WC()->cart, 'is_empty') || WC()->cart->is_empty()) {
            return;
        }
        $cart = WC()->cart;
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('InitiateCheckout', $cart->get_cart_hash());
        $event_time = time();
        $content_ids = array();
        $contents = array();
        
        // Use enhanced product data for better catalog matching
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
            
            $content_ids[] = $product_data['content_id'];
            $contents[] = array(
                'id' => $product_data['content_id'],
                'quantity' => $cart_item['quantity'],
                'item_price' => ( isset($cart_item['data']) && method_exists($cart_item['data'], 'get_price') ) ? (float) $cart_item['data']->get_price() : 0,
            );
        }
        
        // Enhanced data for better CAPI coverage and event matching
        $payload = array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => (float) $cart->get_total('edit'),
            'currency' => get_woocommerce_currency(),
            'num_items' => $cart->get_cart_contents_count(),
        );
        
        // Add optional fields for better matching
        $first_item = reset($cart->get_cart());
        if ($first_item) {
            $product_id = $first_item['variation_id'] ? $first_item['variation_id'] : $first_item['product_id'];
            $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
            
            if (!empty($product_data['content_category'])) {
                $payload['content_category'] = $product_data['content_category'];
            }
        }
        
        // Add to page for pixel tracking
        $this->add_pixel_event('InitiateCheckout', array_merge(
            array('event_id' => $event_id),
            $payload
        ));
        
        // Send CAPI event with enhanced data for better matching
        Mauka_Meta_Pixel_Helpers::send_capi_event('InitiateCheckout', 
            array('event_id' => $event_id), 
            $payload, 
            null, 
            $event_time
        );
    }

    /**
     * Track Purchase event
     */
    public function track_purchase($order_id) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('Purchase') || !$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Prevent duplicate tracking
        if ($order->get_meta('_mauka_pixel_tracked', true)) {
            return;
        }
        
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('Purchase', $order_id);
        $event_time = time();
        
        $content_ids = array();
        $contents = array();
        
        // Use enhanced product data for better catalog matching
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
            
            $content_ids[] = $product_data['content_id'];
            $unit_price = $item->get_total() / max(1, $item->get_quantity());
            $contents[] = array(
                'id' => $product_data['content_id'],
                'quantity' => $item->get_quantity(),
                'item_price' => (float) $unit_price
            );
        }
        
        $payload = array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'num_items' => $order->get_item_count(),
        );
        
        // Add to page for pixel tracking
        $this->add_pixel_event('Purchase', array_merge(array('event_id' => $event_id), $payload));
        
        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('Purchase', array('event_id' => $event_id), $payload, $order_id, $event_time);
        
        // Mark as tracked to prevent duplicates
        $order->update_meta_data('_mauka_pixel_tracked', true);
        $order->save();
    }

    /**
     * Determine whether current request context should be ignored for tracking
     */
    private function should_skip_request() {
        return ( is_admin() ||
            ( defined('DOING_CRON') && DOING_CRON ) ||
            ( defined('DOING_AJAX') && DOING_AJAX ) ||
            ( defined('WC_DOING_AJAX') && WC_DOING_AJAX ) ||
            ( function_exists('wp_doing_rest') && wp_doing_rest() ) );
    }

    /**
     * Track CompleteRegistration event
     */
    public function track_complete_registration($customer_id) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('CompleteRegistration')) {
            return;
        }
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('CompleteRegistration', $customer_id);
        $event_time = time();
        $this->add_pixel_event('CompleteRegistration', array('event_id' => $event_id, 'status' => 'registered'));
        Mauka_Meta_Pixel_Helpers::send_capi_event('CompleteRegistration', array('event_id' => $event_id), array('status' => 'registered'), null, $event_time);
    }

    /**
     * Track Search event
     */
    public function track_search($query) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('Search') || !function_exists('is_search') || !is_search() || !$query->is_main_query()) {
            return;
        }
        static $sent = false;
        if ($sent) {
            return;
        }
        $sent = true;
        $term = get_search_query();
        if (!$term) {
            return;
        }
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('Search', md5($term));
        $event_time = time();
        $this->add_pixel_event('Search', array('event_id' => $event_id, 'search_string' => $term));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Search', array('event_id' => $event_id), array('search_string' => $term), null, $event_time);
    }

    /**
     * Track Contact Form 7 Lead
     */
    public function track_contact_form_lead($contact_form) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('Lead')) {
            return;
        }
        $form_id = $contact_form->id();
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('Lead', 'cf7_' . $form_id);
        $event_time = time();
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Contact Form: ' . $form_id));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Contact Form: ' . $form_id), null, $event_time);
    }

    /**
     * Track Gravity Form Lead
     */
    public function track_gravity_form_lead($entry, $form) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('Lead')) {
            return;
        }
        $form_id = $form['id'];
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('Lead', 'gf_' . $form_id . '_' . $entry['id']);
        $event_time = time();
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Gravity Form: ' . $form['title']));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Gravity Form: ' . $form['title']), null, $event_time);
    }
}
