<?php
/**
 * Helper functions for Mauka Meta Pixel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper class for Meta Pixel operations
 */
class Mauka_Meta_Pixel_Helpers {
    
    /**
     * Generate unique event ID for deduplication
     */
    public static function generate_event_id($event_name = '', $additional_data = '') {
        $timestamp = microtime(true);
        $user_id = get_current_user_id();
        $session_id = self::get_session_id();
        
        $unique_string = $event_name . $timestamp . $user_id . $session_id . $additional_data;
        return 'evt_' . substr(md5($unique_string), 0, 20);
    }
    
    /**
     * Get or create session ID
     */
    public static function get_session_id() {
        if (!session_id()) {
            if (!headers_sent()) {
                session_start();
            }
        }
        
        if (!isset($_SESSION['mauka_session_id'])) {
            $_SESSION['mauka_session_id'] = uniqid('ses_', true);
        }
        
        return isset($_SESSION['mauka_session_id']) ? $_SESSION['mauka_session_id'] : 'ses_' . time();
    }
    
    /**
     * Get or set Facebook browser ID (fbp)
     */
    public static function get_fbp() {
        if (isset($_COOKIE['_fbp'])) {
            return sanitize_text_field($_COOKIE['_fbp']);
        }
        
        // Generate new fbp if not exists
        $fbp = 'fb.1.' . time() . '.' . wp_rand(1000000000, 9999999999);
        
        // Set cookie for 90 days
        if (!headers_sent()) {
            setcookie('_fbp', $fbp, time() + (90 * 24 * 60 * 60), '/', '', is_ssl(), true);
        }
        
        return $fbp;
    }
    
    /**
     * Get Facebook click ID (fbc)
     */
    public static function get_fbc() {
        if (isset($_COOKIE['_fbc'])) {
            return sanitize_text_field($_COOKIE['_fbc']);
        }
        
        // Check for fbclid in URL
        if (isset($_GET['fbclid'])) {
            $fbclid = sanitize_text_field($_GET['fbclid']);
            $fbc = 'fb.1.' . time() . '.' . $fbclid;
            
            // Set cookie for 90 days
            if (!headers_sent()) {
                setcookie('_fbc', $fbc, time() + (90 * 24 * 60 * 60), '/', '', is_ssl(), true);
            }
            
            return $fbc;
        }
        
        return null;
    }
    
    /**
     * Hash user data for GDPR compliance
     */
    public static function hash_user_data($data) {
        if (empty($data)) {
            return null;
        }
        
        // Convert to lowercase and trim
        $data = strtolower(trim($data));
        
        // Hash with SHA256
        return hash('sha256', $data);
    }

    /**
     * Hash phone numbers specifically for GDPR compliance.
     */
    public static function hash_phone($phone) {
        if (empty($phone)) {
            return null;
        }
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return hash('sha256', $phone);
    }

    /**
     * Get hashed user data for current user or from order/checkout.
     */
    public static function get_user_data($order_id = null) {
        $user_data = array();
        $user = null;

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $user_id = $order->get_user_id();
                if ($user_id) {
                    $user = get_user_by('id', $user_id);
                } else {
                    // Guest user from a completed order
                    $user_data = array(
                        'em'      => self::hash_user_data($order->get_billing_email()),
                        'ph'      => self::hash_phone($order->get_billing_phone()),
                        'fn'      => self::hash_user_data($order->get_billing_first_name()),
                        'ln'      => self::hash_user_data($order->get_billing_last_name()),
                        'ct'      => self::hash_user_data($order->get_billing_city()),
                        'st'      => self::hash_user_data($order->get_billing_state()),
                        'zp'      => hash('sha256', $order->get_billing_postcode()),
                        'country' => self::hash_user_data($order->get_billing_country()),
                        'gender'  => self::hash_user_data(get_post_meta($order_id, '_billing_gender', true)),
                        'db'      => self::hash_user_data(get_post_meta($order_id, '_billing_birth_date', true)),
                    );
                }
            }
        } else {
            $user = wp_get_current_user();
        }

        if ($user && $user->ID) { // It's a logged-in user
            $billing_first_name = get_user_meta($user->ID, 'billing_first_name', true);
            $billing_last_name  = get_user_meta($user->ID, 'billing_last_name', true);

            $user_data = array(
                'em'          => self::hash_user_data($user->user_email),
                'ph'          => self::hash_phone(get_user_meta($user->ID, 'billing_phone', true)),
                'fn'          => self::hash_user_data($billing_first_name ?: $user->first_name),
                'ln'          => self::hash_user_data($billing_last_name ?: $user->last_name),
                'ct'          => self::hash_user_data(get_user_meta($user->ID, 'billing_city', true)),
                'st'          => self::hash_user_data(get_user_meta($user->ID, 'billing_state', true)),
                'zp'          => hash('sha256', get_user_meta($user->ID, 'billing_postcode', true)),
                'country'     => self::hash_user_data(get_user_meta($user->ID, 'billing_country', true)),
                'external_id' => (string) $user->ID,
                'gender'      => self::hash_user_data(get_user_meta($user->ID, 'billing_gender', true)),
                'db'          => self::hash_user_data(get_user_meta($user->ID, 'billing_birth_date', true)),
            );
        } elseif (function_exists('WC') && 
                 WC() && 
                 property_exists(WC(), 'checkout') && 
                 WC()->checkout && 
                 function_exists('is_checkout') && 
                 is_checkout() && 
                 function_exists('is_order_received_page') && 
                 !is_order_received_page() && 
                 method_exists(WC()->checkout, 'get_posted_data') && 
                 !empty(WC()->checkout->get_posted_data())) {
            // Guest user on checkout page with filled-in data
            $posted_data = WC()->checkout->get_posted_data();
            $user_data = array(
                'em'      => isset($posted_data['billing_email']) ? self::hash_user_data($posted_data['billing_email']) : null,
                'ph'      => isset($posted_data['billing_phone']) ? self::hash_phone($posted_data['billing_phone']) : null,
                'fn'      => isset($posted_data['billing_first_name']) ? self::hash_user_data($posted_data['billing_first_name']) : null,
                'ln'      => isset($posted_data['billing_last_name']) ? self::hash_user_data($posted_data['billing_last_name']) : null,
                'ct'      => isset($posted_data['billing_city']) ? self::hash_user_data($posted_data['billing_city']) : null,
                'st'      => isset($posted_data['billing_state']) ? self::hash_user_data($posted_data['billing_state']) : null,
                'zp'      => isset($posted_data['billing_postcode']) ? hash('sha256', $posted_data['billing_postcode']) : null,
                'country' => isset($posted_data['billing_country']) ? self::hash_user_data($posted_data['billing_country']) : null,
                'gender'  => isset($posted_data['billing_gender']) ? self::hash_user_data($posted_data['billing_gender']) : null,
                'db'      => isset($posted_data['billing_birth_date']) ? self::hash_user_data($posted_data['billing_birth_date']) : null,
            );
        }

        // Add client IP and User Agent if available
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $user_data['client_ip_address'] = self::get_client_ip();
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $user_data['client_user_agent'] = self::get_user_agent();
        }

        // Get Facebook Click ID and Browser ID from cookies
        if (isset($_COOKIE['_fbp'])) {
            $user_data['fbp'] = $_COOKIE['_fbp'];
        }
        if (isset($_COOKIE['_fbc'])) {
            $user_data['fbc'] = $_COOKIE['_fbc'];
        }
        
        // Get Facebook Login ID if available
        if (function_exists('get_user_meta') && isset($user) && $user && $user->ID) {
            $fb_login_id = get_user_meta($user->ID, 'facebook_login_id', true);
            if (!empty($fb_login_id)) {
                $user_data['fb_login_id'] = $fb_login_id;
            }
        }

        return array_filter($user_data);
    }
    
    /**
     * Get client IP address
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }
    
    /**
     * Get user agent
     */
    public static function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }
    
    /**
     * Log messages to file
     */
    public static function log($message, $level = 'info') {
        $plugin = mauka_meta_pixel();
        
        if (!$plugin || !$plugin->get_option('enable_logging')) {
            return;
        }
        
        $log_file = MAUKA_META_PIXEL_PLUGIN_DIR . 'log/mauka-capi.log';
        
        // Create log directory if not exists
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        error_log($log_entry, 3, $log_file);
        
        // Limit log file size (max 10MB)
        if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
            $lines = file($log_file);
            if ($lines && is_array($lines)) {
                $keep_lines = array_slice($lines, -1000); // Keep last 1000 lines
                file_put_contents($log_file, implode('', $keep_lines));
            }
        }
    }
    
    /**
     * Send CAPI event to Meta
     */
    public static function send_capi_event($event_name, $event_data = array(), $custom_data = array(), $order_id = null, $event_time = null) {
        $plugin = mauka_meta_pixel();
        
        if (!$plugin || !$plugin->get_option('capi_enabled')) {
            return false;
        }
        
        $pixel_id = $plugin->get_option('pixel_id');
        $access_token = $plugin->get_option('access_token');
        
        if (empty($pixel_id) || empty($access_token)) {
            self::log('CAPI event failed: Missing Pixel ID or Access Token', 'error');
            return false;
        }
        
        // Use provided event_time or current time
        if ($event_time === null) {
            $event_time = time();
        }
        
        // Prepare event data
        $event = array(
            'event_name' => $event_name,
            'event_time' => $event_time,
            'event_id' => isset($event_data['event_id']) ? $event_data['event_id'] : self::generate_event_id($event_name),
            'action_source' => 'website',
            'event_source_url' => home_url(add_query_arg(array(), $GLOBALS['wp']->request)),
            'user_data' => self::get_user_data($order_id),
        );
        
        // Add custom data
        if (!empty($custom_data) && is_array($custom_data)) {
            $event['custom_data'] = $custom_data;
        }
        
        // Prepare API request data
        $api_url = "https://graph.facebook.com/v19.0/{$pixel_id}/events";
        $data = array(
            'data' => array($event),
            'access_token' => $access_token,
        );
        
        // Add test event code at the request level
        $test_mode = $plugin->get_option('test_mode');
        if ($test_mode) {
            $test_event_code = $plugin->get_option('test_event_code');
            if (!empty($test_event_code)) {
                $data['test_event_code'] = $test_event_code;
            }
        }
        
        // Send request with retry logic for better CAPI coverage
        $max_retries = 2;
        $retry_count = 0;
        
        do {
            $response = wp_remote_post($api_url, array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($data),
            ));
            
            if (is_wp_error($response)) {
                $retry_count++;
                if ($retry_count <= $max_retries) {
                    // Wait before retry (exponential backoff)
                    sleep(pow(2, $retry_count - 1));
                    continue;
                }
                self::log('CAPI event failed after ' . $max_retries . ' retries: ' . $response->get_error_message(), 'error');
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code === 200) {
                self::log("CAPI event sent successfully: {$event_name} (ID: {$event['event_id']})", 'info');
                return true;
            } else if ($response_code >= 500 && $retry_count < $max_retries) {
                // Retry on server errors
                $retry_count++;
                self::log("CAPI event failed with HTTP {$response_code}, retrying ({$retry_count}/{$max_retries})", 'warning');
                sleep(pow(2, $retry_count - 1));
                continue;
            } else {
                self::log("CAPI event failed: HTTP {$response_code} - {$response_body}", 'error');
                return false;
            }
        } while ($retry_count <= $max_retries);
    }
    
    /**
     * Format price for Meta (remove currency formatting)
     */
    public static function format_price($price) {
        if (empty($price)) {
            return 0;
        }
        
        // Remove currency symbols and formatting
        $price = preg_replace('/[^0-9.,]/', '', $price);
        $price = str_replace(',', '.', $price);
        
        return (float) $price;
    }
    
    /**
     * Get product data for tracking
     */
    public static function get_product_data($product_id) {
        if (!$product_id || !function_exists('wc_get_product')) {
            return array();
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return array();
        }
        
        // Get user's content ID format preference
        $plugin = mauka_meta_pixel();
        $content_id_format = $plugin ? $plugin->get_option('content_id_format', 'sku_fallback') : 'sku_fallback';
        
        // Generate content_id based on user preference
        if ($content_id_format === 'product_id') {
            $content_id = (string) $product_id;
        } else {
            // Default: SKU with fallback to product ID (sku_fallback)
            $content_id = $product->get_sku();
            if (empty($content_id)) {
                $content_id = (string) $product_id;
            }
        }
        
        return array(
            'content_id' => $content_id,
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'content_category' => self::get_product_categories($product),
            'value' => self::format_price($product->get_price()),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            // Add additional fields that help with catalog matching
            'brand' => self::get_product_brand($product),
            'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
        );
    }
    
    /**
     * Get product categories
     */
    public static function get_product_categories($product) {
        if (!$product) {
            return '';
        }
        
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        return is_array($categories) ? implode(', ', $categories) : '';
    }
    
    /**
     * Get product brand
     */
    public static function get_product_brand($product) {
        if (!$product) {
            return '';
        }
        
        // Assuming brand is stored in a custom attribute called 'brand'
        $brand = $product->get_attribute('brand');
        return $brand ? $brand : '';
    }
    
    /**
     * Check if tracking is enabled for event
     */
    public static function is_event_enabled($event_name) {
        $plugin = mauka_meta_pixel();
        
        if (!$plugin) {
            return false;
        }
        
        $option_map = array(
            'PageView' => 'track_pageview',
            'ViewContent' => 'track_viewcontent', 
            'AddToCart' => 'track_addtocart',
            'InitiateCheckout' => 'track_initiatecheckout',
            'AddPaymentInfo' => 'track_addpaymentinfo',
            'Purchase' => 'track_purchase',
            'Lead' => 'track_lead',
            'CompleteRegistration' => 'track_completeregistration',
            'Search' => 'track_search',
            'ViewCategory' => 'track_viewcategory',
            'AddToWishlist' => 'track_addtowishlist',
        );
        
        $option_key = isset($option_map[$event_name]) ? $option_map[$event_name] : null;
        return $option_key ? $plugin->get_option($option_key, true) : false;
    }
} 