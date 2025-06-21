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
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('PageView')) {
            return;
        }
        // Skip AJAX requests and repeated loads on checkout page to avoid duplicates
        if (defined('DOING_AJAX') && DOING_AJAX) {
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
        
        // Add to page for pixel tracking
        $this->add_pixel_event('PageView', array('event_id' => $event_id));
        
        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('PageView', array('event_id' => $event_id));
    }
    
    /**
     * Track ViewContent event (product pages)
     */
    public function track_view_content() {
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
        
        // Get product data
        $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($product_id);
        
        // Add to page for pixel tracking
        $this->add_pixel_event('ViewContent', array(
            'event_id' => $event_id,
            'content_name' => $product_data['content_name'],
            'content_ids' => array($product_data['content_id']),
            'content_type' => 'product',
            'value' => $product_data['value'],
            'currency' => $product_data['currency'],
        ));
        
        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('ViewContent',
            array('event_id' => $event_id),
            array(
                'content_name' => $product_data['content_name'],
                'content_ids' => array($product_data['content_id']),
                'content_type' => 'product',
                'value' => $product_data['value'],
                'currency' => $product_data['currency'],
            )
        );
    }
    
    /**
     * Track ViewCategory event
     */
    public function track_view_category() {
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
            )
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
            )
        );
    }

    /**
     * Track AddPaymentInfo event
     */
    public function track_add_payment_info() {
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
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id' => (string) $product_id,
                'quantity' => $cart_item['quantity'],
                'item_price' => ( isset($cart_item['data']) && method_exists($cart_item['data'], 'get_price') ) ? (float) $cart_item['data']->get_price() : 0,
            );
        }

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
            )
        );
    }

    /**
     * Track AddToCart event
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('AddToCart')) {
            return;
        }

        $actual_product_id = $variation_id ? $variation_id : $product_id;
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('AddToCart', $actual_product_id . '_' . $quantity);

        $product_data = Mauka_Meta_Pixel_Helpers::get_product_data($actual_product_id);

        // Build contents with item_price for better match quality
        $contents = array(
            array(
                'id' => (string) $product_data['content_id'],
                'quantity' => $quantity,
                'item_price' => $product_data['value'],
            ),
        );

        // Add to page for pixel tracking
        $this->add_pixel_event('AddToCart', array(
            'event_id'     => $event_id,
            'content_name' => $product_data['content_name'],
            'content_ids'  => array($product_data['content_id']),
            'content_type' => 'product',
            'value'        => $product_data['value'] * $quantity,
            'currency'     => $product_data['currency'],
        ));

        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('AddToCart',
            array('event_id' => $event_id),
            array(
                'content_name' => $product_data['content_name'],
                'content_ids'  => array($product_data['content_id']),
                'content_type' => 'product',
                'value'        => $product_data['value'] * $quantity,
                'currency'     => $product_data['currency'],
                'contents'     => $contents,
            )
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
        $content_ids = array();
        $contents = array();
        foreach ($cart->get_cart() as $item) {
            $pid = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
            $content_ids[] = (string) $pid;
            $contents[] = array('id' => (string) $pid, 'quantity' => $item['quantity'], 'item_price' => ( isset($item['data']) && method_exists($item['data'], 'get_price') ) ? (float) $item['data']->get_price() : 0);
        }
        $payload = array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => (float) $cart->get_total('edit'),
            'currency' => get_woocommerce_currency(),
            'num_items' => $cart->get_cart_contents_count(),
        );
        $this->add_pixel_event('InitiateCheckout', array_merge(array('event_id' => $event_id), $payload));
        Mauka_Meta_Pixel_Helpers::send_capi_event('InitiateCheckout', array('event_id' => $event_id), $payload);
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
        if ($order->get_meta('_mauka_pixel_tracked', true)) {
            return;
        }
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('Purchase', $order_id);
        $content_ids = $contents = array();
        foreach ($order->get_items() as $item) {
            $pid = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $content_ids[] = (string) $pid;
            $unit_price = $item->get_total() / max(1, $item->get_quantity());
            $contents[] = array('id' => (string) $pid, 'quantity' => $item->get_quantity(), 'item_price' => (float) $unit_price);
        }
        $payload = array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => (float) $order->get_total(),
            'contents' => $contents,
            'currency' => $order->get_currency(),
            'num_items' => $order->get_item_count(),
        );
        $this->add_pixel_event('Purchase', array_merge(array('event_id' => $event_id), $payload));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Purchase', array('event_id' => $event_id), $payload, $order_id);
        $order->update_meta_data('_mauka_pixel_tracked', true);
        $order->save();
    }

    /**
     * Track CompleteRegistration event
     */
    public function track_complete_registration($customer_id) {
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('CompleteRegistration')) {
            return;
        }
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('CompleteRegistration', $customer_id);
        $this->add_pixel_event('CompleteRegistration', array('event_id' => $event_id, 'status' => 'registered'));
        Mauka_Meta_Pixel_Helpers::send_capi_event('CompleteRegistration', array('event_id' => $event_id), array('status' => 'registered'), null, $customer_id);
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
        $this->add_pixel_event('Search', array('event_id' => $event_id, 'search_string' => $term));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Search', array('event_id' => $event_id), array('search_string' => $term));
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
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Contact Form: ' . $form_id));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Contact Form: ' . $form_id));
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
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Gravity Form: ' . $form['title']));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Gravity Form: ' . $form['title']));
    }
}
