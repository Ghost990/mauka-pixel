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
        // Test logging when class is constructed
        Mauka_Meta_Pixel_Helpers::log('Mauka_Meta_Pixel_Tracking class constructed - logging test', 'info');
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Standard tracking events
        add_action('wp_footer', array($this, 'track_page_view'));
        add_action('wp_footer', array($this, 'output_stored_pixel_events'), 999);
        
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
            
            // InitiateCheckout event - multiple hooks to ensure it fires
            add_action('woocommerce_review_order_before_payment', array($this, 'track_initiate_checkout'), 1); // Priority 1 to fire BEFORE AddPaymentInfo
            add_action('woocommerce_before_checkout_form', array($this, 'track_initiate_checkout'), 10); // Earlier hook in checkout process
            add_action('woocommerce_checkout_before_customer_details', array($this, 'track_initiate_checkout'), 10); // Another early hook
            
            // Debug: Confirm hook registration
            if (function_exists('wp_footer')) {
                add_action('wp_footer', function() {
                    if (function_exists('is_checkout') && is_checkout()) {
                        echo '<script>console.log("MAUKA DEBUG: InitiateCheckout hook registered successfully");</script>';
                    }
                });
            }

            // Add Payment Info
            add_action('woocommerce_review_order_before_payment', array($this, 'track_add_payment_info'));
            
            // Purchase completed - use multiple hooks to ensure it fires
            add_action('woocommerce_thankyou', array($this, 'track_purchase'), 10);
            add_action('woocommerce_payment_complete', array($this, 'track_purchase'), 10);
            add_action('woocommerce_order_status_processing', array($this, 'track_purchase'), 10);
            add_action('woocommerce_order_status_completed', array($this, 'track_purchase'), 10);
            
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
        // Debug log that we're attempting to track a page view
        Mauka_Meta_Pixel_Helpers::log("Attempting to track PageView event", 'debug');
        
        if ( $this->should_skip_request() ) { 
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: should_skip_request returned true", 'debug');
            return; 
        }
        
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('PageView')) {
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: event not enabled in settings", 'debug');
            return;
        }
        // Skip AJAX requests and repeated loads on checkout page to avoid duplicates
        if (defined('DOING_AJAX') && DOING_AJAX) {
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: DOING_AJAX is defined", 'debug');
            return;
        }
        if (defined('WC_DOING_AJAX') && WC_DOING_AJAX) {
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: WC_DOING_AJAX is defined", 'debug');
            return;
        }
        if (function_exists('is_checkout') && is_checkout() && ( !function_exists('is_order_received_page') || !is_order_received_page())) {
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: is on checkout page but not order received page", 'debug');
            return;
        }
        
        // Prevent multiple PageView events on same page load
        static $pageview_sent = false;
        if ($pageview_sent) {
            Mauka_Meta_Pixel_Helpers::log("PageView skipped: already sent on this page load", 'debug');
            return;
        }
        $pageview_sent = true;
        
        Mauka_Meta_Pixel_Helpers::log("PageView event proceeding - all checks passed", 'debug');
        
        // Generate event ID for deduplication
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('PageView', get_the_ID());
        $event_time = time();
        
        Mauka_Meta_Pixel_Helpers::log("PageView event ID: {$event_id}", 'debug');
        
        // Add to page for pixel tracking
        $this->add_pixel_event('PageView', array('event_id' => $event_id));
        Mauka_Meta_Pixel_Helpers::log("PageView added to pixel events queue", 'debug');
        
        // Send CAPI event
        $result = Mauka_Meta_Pixel_Helpers::send_capi_event('PageView', array('event_id' => $event_id), array(), null, $event_time);
        Mauka_Meta_Pixel_Helpers::log("PageView CAPI event sent, result: " . ($result ? 'success' : 'failed'), 'debug');
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

        // Get current user data for better matching
        $user_data = array();
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && $user->ID) {
                $user_data['em'] = Mauka_Meta_Pixel_Helpers::hash_user_data($user->user_email);
                $user_data['fn'] = Mauka_Meta_Pixel_Helpers::hash_user_data($user->first_name);
                $user_data['ln'] = Mauka_Meta_Pixel_Helpers::hash_user_data($user->last_name);
            }
        }
        
        // Get data from WooCommerce session if available
        if (function_exists('WC') && WC()->session) {
            $session_customer = WC()->session->get('customer');
            if (!empty($session_customer)) {
                if (!empty($session_customer['email']) && empty($user_data['em'])) {
                    $user_data['em'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['email']);
                }
                if (!empty($session_customer['first_name']) && empty($user_data['fn'])) {
                    $user_data['fn'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['first_name']);
                }
                if (!empty($session_customer['last_name']) && empty($user_data['ln'])) {
                    $user_data['ln'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['last_name']);
                }
                if (!empty($session_customer['postcode'])) {
                    $user_data['zp'] = hash('sha256', $session_customer['postcode']);
                }
                if (!empty($session_customer['city'])) {
                    $user_data['ct'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['city']);
                }
                if (!empty($session_customer['state'])) {
                    $user_data['st'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['state']);
                }
                if (!empty($session_customer['country'])) {
                    $user_data['country'] = Mauka_Meta_Pixel_Helpers::hash_user_data($session_customer['country']);
                }
            }
        }
        
        // Add event source URL for better tracking
        $event_source_url = '';
        if (isset($_SERVER['HTTP_REFERER'])) {
            $event_source_url = $_SERVER['HTTP_REFERER'];
        } elseif (function_exists('get_permalink') && $actual_product_id) {
            $event_source_url = get_permalink($actual_product_id);
        }
        
        // Log the AddToCart attempt with detailed information
        Mauka_Meta_Pixel_Helpers::log("Sending AddToCart CAPI event for product #{$actual_product_id} with event_id: {$event_id}", 'debug');
        
        // Add current event time to ensure proper timestamp
        $current_time = time();
        
        // Send CAPI event with enhanced data for better matching
        $capi_result = Mauka_Meta_Pixel_Helpers::send_capi_event('AddToCart',
            array(
                'event_id' => $event_id,
                'event_source_url' => $event_source_url
            ),
            $enhanced_data,
            null,
            $current_time,
            $user_data
        );
        
        // Log success or failure
        if ($capi_result) {
            Mauka_Meta_Pixel_Helpers::log("AddToCart CAPI event sent successfully for product #{$actual_product_id}", 'info');
        } else {
            Mauka_Meta_Pixel_Helpers::log("AddToCart CAPI event FAILED for product #{$actual_product_id}", 'error');
        }
    }

    /**
     * Helper to buffer pixel events for rendering in footer
     */
    private function add_pixel_event($event_name, $event_data = array()) {
        Mauka_Meta_Pixel_Helpers::log("Adding pixel event: {$event_name} with data: " . json_encode($event_data), 'debug');
        
        add_filter('mauka_meta_pixel_events', function($events) use ($event_name, $event_data) {
            $events[] = array(
                'event_name' => $event_name,
                'event_data' => $event_data,
            );
            
            Mauka_Meta_Pixel_Helpers::log("Total pixel events in queue: " . count($events), 'debug');
            return $events;
        });
    }
    
    /**
     * Output stored pixel events in the footer
     */
    public function output_stored_pixel_events() {
        $plugin = mauka_meta_pixel();
        if (!$plugin || !$plugin->get_option('pixel_enabled')) {
            return;
        }
        
        $pixel_id = $plugin->get_option('pixel_id');
        if (empty($pixel_id)) {
            return;
        }
        
        // Get all events added via add_pixel_event
        $events = apply_filters('mauka_meta_pixel_events', array());
        
        if (empty($events)) {
            Mauka_Meta_Pixel_Helpers::log("No pixel events to output", 'debug');
            return;
        }
        
        Mauka_Meta_Pixel_Helpers::log("Outputting " . count($events) . " pixel events", 'debug');
        
        echo "<!-- Mauka Meta Pixel Events -->\n";
        echo "<script>\n";
        
        foreach ($events as $event) {
            $event_name = $event['event_name'];
            $event_data = isset($event['event_data']) ? $event['event_data'] : array();
            
            echo "fbq('track', '" . esc_js($event_name) . "'";
            
            if (!empty($event_data)) {
                echo ", " . wp_json_encode($event_data);
            }
            
            echo ");\n";
            
            Mauka_Meta_Pixel_Helpers::log("Rendered pixel event: {$event_name}", 'debug');
        }
        
        echo "</script>\n";
        echo "<!-- End Mauka Meta Pixel Events -->\n";
    }

    /**
     * Flag to track if checkout has been started
     */
    private $checkout_started = false;
    
    /**
     * Mark that checkout has started
     */
    public function mark_checkout_started() {
        $this->checkout_started = true;
    }
    
    /**
     * Fallback InitiateCheckout tracking for checkout pages
     */
    public function track_initiate_checkout_fallback() {
        // Only fire on checkout pages as a fallback if main hooks didn't work
        if (function_exists('is_checkout') && is_checkout() && 
            (!function_exists('is_order_received_page') || !is_order_received_page())) {
            
            // Add some debugging
            Mauka_Meta_Pixel_Helpers::log("InitiateCheckout fallback triggered from wp_head", 'debug');
            
            // Call the main tracking method (no parameters)
            $this->track_initiate_checkout();
        }
    }
    

    /**
     * Track InitiateCheckout Event - using same approach as AddPaymentInfo
     */
    public function track_initiate_checkout() {
        // Add console debugging for deployment server testing
        echo '<script>console.log("MAUKA DEBUG: track_initiate_checkout() called at "+new Date().toISOString());</script>';
        
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout: Starting event tracking", 'debug');
        
        if ( $this->should_skip_request() ) { 
            echo '<script>console.log("MAUKA DEBUG: InitiateCheckout skipped - should_skip_request");</script>';
            return; 
        }
        
        $event_enabled = Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout');
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $not_order_received = !function_exists('is_order_received_page') || !is_order_received_page();
        
        echo '<script>console.log("MAUKA DEBUG: InitiateCheckout validation - event_enabled: ' . ($event_enabled ? 'true' : 'false') . ', is_checkout: ' . ($is_checkout ? 'true' : 'false') . ', not_order_received: ' . ($not_order_received ? 'true' : 'false') . '");</script>';
        
        if (!$event_enabled || !$is_checkout || !$not_order_received) {
            echo '<script>console.log("MAUKA DEBUG: InitiateCheckout skipped - validation failed");</script>';
            Mauka_Meta_Pixel_Helpers::log("InitiateCheckout skipped: validation failed", 'debug');
            return;
        }

        // Prevent multiple triggers using static variable for current request
        static $triggered = false;
        if ($triggered) {
            Mauka_Meta_Pixel_Helpers::log("InitiateCheckout skipped: already triggered in this request", 'debug');
            echo '<script>console.log("MAUKA DEBUG: InitiateCheckout skipped - already triggered in this request");</script>';
            return;
        }
        $triggered = true;
        
        // Also check for session-based tracking if WC session is available
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get_customer_id();
            $transient_key = 'mauka_initiate_checkout_' . md5($session_id . date('Y-m-d-H'));
            
            if (get_transient($transient_key)) {
                Mauka_Meta_Pixel_Helpers::log("InitiateCheckout skipped: already triggered in this session", 'debug');
                echo '<script>console.log("MAUKA DEBUG: InitiateCheckout skipped - already triggered in this session");</script>';
                return;
            }
            
            // Set transient for 1 hour to prevent duplicate events in the same session
            set_transient($transient_key, true, 3600); // 3600 seconds = 1 hour
        }

        if (!function_exists('WC') || !WC()->cart || !method_exists(WC()->cart, 'is_empty') || WC()->cart->is_empty()) {
            Mauka_Meta_Pixel_Helpers::log("InitiateCheckout skipped: cart empty", 'debug');
            echo '<script>console.log("MAUKA DEBUG: InitiateCheckout skipped - cart empty");</script>';
            return;
        }

        $cart = WC()->cart;
        $event_id = Mauka_Meta_Pixel_Helpers::generate_event_id('InitiateCheckout', $cart->get_cart_hash());
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout: Generated event ID: {$event_id}", 'debug');

        $content_ids = array();
        $contents = array();
        
        // Use enhanced product data for better catalog matching (same as AddPaymentInfo)
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
        $this->add_pixel_event('InitiateCheckout', array(
            'event_id' => $event_id,
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => $cart->get_total('edit'),
            'currency' => get_woocommerce_currency(),
            'num_items' => $cart->get_cart_contents_count(),
        ));
        
        echo '<script>console.log("MAUKA DEBUG: InitiateCheckout event added with ID: ' . $event_id . '");</script>';
        
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout: Pixel event added", 'debug');

        // Send CAPI event
        Mauka_Meta_Pixel_Helpers::send_capi_event('InitiateCheckout',
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
        
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout: CAPI event sent successfully", 'debug');
    }

    /**
     * Track Purchase event
     */
    public function track_purchase($order_id) {
        // Add detailed debugging
        Mauka_Meta_Pixel_Helpers::log("Attempting to track purchase for order ID: {$order_id}", 'debug');
        echo '<script>console.log("MAUKA DEBUG: track_purchase() called for order ID: ' . esc_js($order_id) . ' at "+new Date().toISOString());</script>';
        
        if (!Mauka_Meta_Pixel_Helpers::is_event_enabled('Purchase')) {
            Mauka_Meta_Pixel_Helpers::log("Purchase event tracking is disabled in settings", 'debug');
            echo '<script>console.log("MAUKA DEBUG: Purchase event tracking is disabled in settings");</script>';
            return;
        }
        
        if (!$order_id) {
            Mauka_Meta_Pixel_Helpers::log("No order ID provided for purchase tracking", 'debug');
            echo '<script>console.log("MAUKA DEBUG: No order ID provided for purchase tracking");</script>';
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            Mauka_Meta_Pixel_Helpers::log("Order {$order_id} not found", 'error');
            echo '<script>console.log("MAUKA DEBUG: Order ' . esc_js($order_id) . ' not found");</script>';
            return;
        }
        
        // Only track purchases for valid order statuses
        $valid_statuses = array('processing', 'completed', 'on-hold', 'pending');
        $order_status = $order->get_status();
        if (!in_array($order_status, $valid_statuses)) {
            Mauka_Meta_Pixel_Helpers::log("Order {$order_id} has status '{$order_status}', not tracking Purchase event", 'debug');
            echo '<script>console.log("MAUKA DEBUG: Order ' . esc_js($order_id) . ' has status \'' . esc_js($order_status) . '\', not tracking Purchase event");</script>';
            return;
        }
        
        Mauka_Meta_Pixel_Helpers::log("Processing purchase for order #{$order_id} with total: {$order->get_total()} {$order->get_currency()}", 'debug');
        echo '<script>console.log("MAUKA DEBUG: Processing purchase for order #' . esc_js($order_id) . ' with total: ' . esc_js($order->get_total()) . ' ' . esc_js($order->get_currency()) . '");</script>';
        
        // Prevent duplicate tracking - but allow forcing it for testing
        if ($order->get_meta('_mauka_pixel_tracked', true) && !isset($_GET['force_pixel_tracking'])) {
            Mauka_Meta_Pixel_Helpers::log("Order {$order_id} already tracked, skipping", 'debug');
            echo '<script>console.log("MAUKA DEBUG: Order ' . esc_js($order_id) . ' already tracked, skipping");</script>';
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
        
        // Enhanced payload with additional parameters required for better CAPI matching
        $payload = array(
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'num_items' => $order->get_item_count(),
            // Add order info for better conversion tracking
            'order_id' => (string)$order_id,
            'transaction_id' => (string)$order_id,
        );
        
        // Add to page for pixel tracking
        $this->add_pixel_event('Purchase', array_merge(array('event_id' => $event_id), $payload));
        
        // Extract comprehensive user data from order for better CAPI matching
        $additional_user_data = array();
        
        // Add direct order billing data for better user matching
        if ($order->get_billing_email()) {
            $additional_user_data['em'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_email());
        }
        if ($order->get_billing_phone()) {
            $additional_user_data['ph'] = Mauka_Meta_Pixel_Helpers::hash_phone($order->get_billing_phone());
        }
        if ($order->get_billing_first_name()) {
            $additional_user_data['fn'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_first_name());
        }
        if ($order->get_billing_last_name()) {
            $additional_user_data['ln'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_last_name());
        }
        if ($order->get_billing_city()) {
            $additional_user_data['ct'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_city());
        }
        if ($order->get_billing_state()) {
            $additional_user_data['st'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_state());
        }
        if ($order->get_billing_postcode()) {
            $additional_user_data['zp'] = hash('sha256', $order->get_billing_postcode());
        }
        if ($order->get_billing_country()) {
            $additional_user_data['country'] = Mauka_Meta_Pixel_Helpers::hash_user_data($order->get_billing_country());
        }
        
        // Check for subscription data if WooCommerce Subscriptions is active
        if (function_exists('wcs_get_subscriptions_for_order')) {
            $subscriptions = wcs_get_subscriptions_for_order($order_id);
            if (!empty($subscriptions)) {
                $subscription = reset($subscriptions);
                $additional_user_data['subscription_id'] = Mauka_Meta_Pixel_Helpers::hash_user_data($subscription->get_id());
                $additional_user_data['subscription_status'] = Mauka_Meta_Pixel_Helpers::hash_user_data($subscription->get_status());
            }
        }
        
        // Add any custom order meta that might contain user data
        $order_meta = $order->get_meta_data();
        foreach ($order_meta as $meta) {
            $key = $meta->key;
            $value = $meta->value;
            if (strpos($key, '_billing_') === 0 && !in_array($key, array('_billing_email', '_billing_phone', '_billing_first_name', '_billing_last_name', '_billing_city', '_billing_state', '_billing_postcode', '_billing_country'))) {
                $field_name = str_replace('_billing_', '', $key);
                $additional_user_data[$field_name] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
            }
            
            // Check for date of birth in order meta
            if (stripos($key, 'birth') !== false || stripos($key, 'dob') !== false) {
                $dob = $value;
                // Format date to YYYYMMDD if possible
                if (strtotime($dob)) {
                    $additional_user_data['db'] = Mauka_Meta_Pixel_Helpers::hash_user_data(date('Ymd', strtotime($dob)));
                }
            }
            
            // Check for gender in order meta
            if (stripos($key, 'gender') !== false) {
                $gender = strtolower(substr($value, 0, 1));
                if ($gender == 'm' || $gender == 'f') {
                    $additional_user_data['ge'] = Mauka_Meta_Pixel_Helpers::hash_user_data($gender);
                }
            }
        }
        
        // Send CAPI event with enhanced user data and detailed logging
        Mauka_Meta_Pixel_Helpers::log("Sending Purchase CAPI event for order #{$order_id} with event_id: {$event_id}", 'debug');
        Mauka_Meta_Pixel_Helpers::log("Purchase payload: " . json_encode($payload), 'debug');
        Mauka_Meta_Pixel_Helpers::log("Additional user data parameters: " . count($additional_user_data), 'debug');
        
        // Add additional debugging in the browser console
        echo '<script>console.log("MAUKA DEBUG: Sending Purchase CAPI event for order #' . esc_js($order_id) . ' with event_id: ' . esc_js($event_id) . '");</script>';
        
        // Force the event time to be the current time to avoid any timing issues
        $current_event_time = time();
        
        // Send the CAPI event with enhanced data
        $capi_result = Mauka_Meta_Pixel_Helpers::send_capi_event('Purchase', 
            array(
                'event_id' => $event_id,
                'event_source_url' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url('/checkout/order-received/' . $order_id),
            ),
            $payload, 
            $order_id, 
            $current_event_time, 
            $additional_user_data
        );
        
        if ($capi_result) {
            Mauka_Meta_Pixel_Helpers::log("Purchase CAPI event sent successfully for order #{$order_id}", 'info');
            echo '<script>console.log("MAUKA DEBUG: Purchase CAPI event sent successfully for order #' . esc_js($order_id) . '");</script>';
            
            // Mark as tracked to prevent duplicates
            $order->update_meta_data('_mauka_pixel_tracked', true);
            $order->update_meta_data('_mauka_pixel_tracked_time', current_time('mysql'));
            $order->save();
            Mauka_Meta_Pixel_Helpers::log("Order #{$order_id} marked as tracked", 'debug');
        } else {
            Mauka_Meta_Pixel_Helpers::log("Purchase CAPI event FAILED for order #{$order_id}", 'error');
            echo '<script>console.log("MAUKA DEBUG: Purchase CAPI event FAILED for order #' . esc_js($order_id) . '");</script>';
        }
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
        
        // Get additional user data from user meta
        $additional_user_data = array();
        $user = get_user_by('id', $customer_id);
        
        if ($user) {
            // Get all user meta
            $user_meta = get_user_meta($customer_id);
            
            // Extract relevant user meta fields
            foreach ($user_meta as $key => $values) {
                $value = reset($values); // Get first value (user meta returns arrays)
                
                // Look for additional fields that might contain user data
                if (!in_array($key, array('billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country')) && 
                    (strpos($key, 'billing_') === 0 || strpos($key, 'shipping_') === 0)) {
                    $field_name = str_replace(array('billing_', 'shipping_'), '', $key);
                    $additional_user_data[$field_name] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
                }
            }
        }
        
        $this->add_pixel_event('CompleteRegistration', array('event_id' => $event_id, 'status' => 'registered'));
        Mauka_Meta_Pixel_Helpers::send_capi_event('CompleteRegistration', array('event_id' => $event_id), array('status' => 'registered'), null, $event_time, $additional_user_data);
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
        
        // Get additional search context data
        $additional_user_data = array();
        
        // Add search refinements if available (like category, tag filters)
        if (isset($_GET['product_cat']) && !empty($_GET['product_cat'])) {
            $additional_user_data['product_category'] = Mauka_Meta_Pixel_Helpers::hash_user_data($_GET['product_cat']);
        }
        
        if (isset($_GET['product_tag']) && !empty($_GET['product_tag'])) {
            $additional_user_data['product_tag'] = Mauka_Meta_Pixel_Helpers::hash_user_data($_GET['product_tag']);
        }
        
        // Add any other search parameters
        foreach ($_GET as $key => $value) {
            if (strpos($key, 'filter_') === 0 && !empty($value)) {
                $filter_name = str_replace('filter_', '', $key);
                $additional_user_data[$filter_name] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
            }
        }
        
        $this->add_pixel_event('Search', array('event_id' => $event_id, 'search_string' => $term));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Search', array('event_id' => $event_id), array('search_string' => $term), null, $event_time, $additional_user_data);
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
        
        // Extract user data from form submission
        $additional_user_data = array();
        
        // Check if Contact Form 7 is active and the class exists
        if (class_exists('WPCF7_Submission')) {
            $submission = WPCF7_Submission::get_instance();
            
            if ($submission) {
            $posted_data = $submission->get_posted_data();
            
            // Map common field names to Meta parameters
            $field_mapping = array(
                'email' => 'em',
                'your-email' => 'em',
                'email-address' => 'em',
                'phone' => 'ph',
                'your-phone' => 'ph',
                'tel' => 'ph',
                'telephone' => 'ph',
                'first-name' => 'fn',
                'your-first-name' => 'fn',
                'fname' => 'fn',
                'last-name' => 'ln',
                'your-last-name' => 'ln',
                'lname' => 'ln',
                'city' => 'ct',
                'your-city' => 'ct',
                'state' => 'st',
                'your-state' => 'st',
                'zip' => 'zp',
                'zipcode' => 'zp',
                'postal-code' => 'zp',
                'your-zip' => 'zp',
                'country' => 'country',
                'your-country' => 'country'
            );
            
            // Process form fields
            foreach ($posted_data as $field_name => $value) {
                if (isset($field_mapping[$field_name])) {
                    // This is a known field, map it to the correct Meta parameter
                    $meta_param = $field_mapping[$field_name];
                    
                    // Special handling for phone numbers
                    if ($meta_param === 'ph') {
                        $additional_user_data[$meta_param] = Mauka_Meta_Pixel_Helpers::hash_phone($value);
                    } 
                    // Special handling for zip codes
                    else if ($meta_param === 'zp') {
                        $additional_user_data[$meta_param] = hash('sha256', $value);
                    }
                    // Standard hashing for other fields
                    else {
                        $additional_user_data[$meta_param] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
                    }
                } 
                // Process any other fields that might contain user data
                else if (!empty($value) && is_string($value)) {
                    $additional_user_data[$field_name] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
                }
            }
        }
        }
        
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Contact Form: ' . $form_id));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Contact Form: ' . $form_id), null, $event_time, $additional_user_data);
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
        
        // Extract user data from form submission
        $additional_user_data = array();
        
        // Process form fields based on their type
        if (!empty($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $field_id = $field->id;
                $field_type = $field->type;
                
                // Safely get the value using rgar if available, otherwise use array access
                if (function_exists('rgar')) {
                    $value = rgar($entry, $field_id);
                } else {
                    $value = isset($entry[$field_id]) ? $entry[$field_id] : '';
                }
                
                if (empty($value)) {
                    continue;
                }
                
                // Process based on field type
                switch ($field_type) {
                    case 'email':
                        $additional_user_data['em'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
                        break;
                        
                    case 'phone':
                        $additional_user_data['ph'] = Mauka_Meta_Pixel_Helpers::hash_phone($value);
                        break;
                        
                    case 'name':
                        // Handle name fields which might have prefix, first, middle, last, suffix
                        if (is_array($value)) {
                            if (!empty($value[3])) { // First name
                                $additional_user_data['fn'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value[3]);
                            }
                            if (!empty($value[6])) { // Last name
                                $additional_user_data['ln'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value[6]);
                            }
                        } else {
                            // Simple name field
                            $name_parts = explode(' ', $value, 2);
                            if (!empty($name_parts[0])) {
                                $additional_user_data['fn'] = Mauka_Meta_Pixel_Helpers::hash_user_data($name_parts[0]);
                            }
                            if (!empty($name_parts[1])) {
                                $additional_user_data['ln'] = Mauka_Meta_Pixel_Helpers::hash_user_data($name_parts[1]);
                            }
                        }
                        break;
                        
                    case 'address':
                        // Handle address fields
                        if (is_array($value)) {
                            if (!empty($value['city'])) {
                                $additional_user_data['ct'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value['city']);
                            }
                            if (!empty($value['state'])) {
                                $additional_user_data['st'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value['state']);
                            }
                            if (!empty($value['zip'])) {
                                $additional_user_data['zp'] = hash('sha256', $value['zip']);
                            }
                            if (!empty($value['country'])) {
                                $additional_user_data['country'] = Mauka_Meta_Pixel_Helpers::hash_user_data($value['country']);
                            }
                        }
                        break;
                        
                    default:
                        // For other field types, use the field label as the key
                        if (is_string($value) && !empty($field->label)) {
                            $field_key = sanitize_title($field->label);
                            $additional_user_data[$field_key] = Mauka_Meta_Pixel_Helpers::hash_user_data($value);
                        }
                        break;
                }
            }
        }
        
        $this->add_pixel_event('Lead', array('event_id' => $event_id, 'content_name' => 'Gravity Form: ' . $form['title']));
        Mauka_Meta_Pixel_Helpers::send_capi_event('Lead', array('event_id' => $event_id), array('content_name' => 'Gravity Form: ' . $form['title']), null, $event_time, $additional_user_data);
    }
}
