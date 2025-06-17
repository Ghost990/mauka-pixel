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
     * Get hashed user data for current user
     */
    public static function get_user_data() {
        $user_data = array();
        
        // Get current user
        $current_user = wp_get_current_user();
        
        if ($current_user && $current_user->ID > 0) {
            // Email
            if (!empty($current_user->user_email)) {
                $user_data['em'] = self::hash_user_data($current_user->user_email);
            }
            
            // First name
            if (!empty($current_user->first_name)) {
                $user_data['fn'] = self::hash_user_data($current_user->first_name);
            }
            
            // Last name  
            if (!empty($current_user->last_name)) {
                $user_data['ln'] = self::hash_user_data($current_user->last_name);
            }
            
            // Phone (if available in user meta)
            $phone = get_user_meta($current_user->ID, 'billing_phone', true);
            if (!empty($phone)) {
                $user_data['ph'] = self::hash_user_data(preg_replace('/[^0-9]/', '', $phone));
            }
        }
        
        // Try to get data from WooCommerce session/checkout
        if (class_exists('WC') && function_exists('WC') && WC()->session) {
            $customer = WC()->customer;
            
            if ($customer) {
                // Email from customer
                $email = $customer->get_email();
                if (!empty($email) && empty($user_data['em'])) {
                    $user_data['em'] = self::hash_user_data($email);
                }
                
                // Phone from customer
                $phone = $customer->get_billing_phone();
                if (!empty($phone) && empty($user_data['ph'])) {
                    $user_data['ph'] = self::hash_user_data(preg_replace('/[^0-9]/', '', $phone));
                }
                
                // Names from customer
                $first_name = $customer->get_first_name();
                if (!empty($first_name) && empty($user_data['fn'])) {
                    $user_data['fn'] = self::hash_user_data($first_name);
                }
                
                $last_name = $customer->get_last_name();
                if (!empty($last_name) && empty($user_data['ln'])) {
                    $user_data['ln'] = self::hash_user_data($last_name);
                }
            }
        }
        
        return !empty($user_data) ? $user_data : null;
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
    public static function send_capi_event($event_name, $event_data = array(), $custom_data = array()) {
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
        
        // Prepare event data
        $event = array(
            'event_name' => $event_name,
            'event_time' => time(),
            'event_id' => isset($event_data['event_id']) ? $event_data['event_id'] : self::generate_event_id($event_name),
            'action_source' => 'website',
            'event_source_url' => home_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''),
            'user_data' => array(
                'client_ip_address' => self::get_client_ip(),
                'client_user_agent' => self::get_user_agent(),
                'fbp' => self::get_fbp(),
            ),
        );
        
        // Add fbc if available
        $fbc = self::get_fbc();
        if ($fbc) {
            $event['user_data']['fbc'] = $fbc;
        }
        
        // Add hashed user data
        $user_data = self::get_user_data();
        if ($user_data && is_array($user_data)) {
            $event['user_data'] = array_merge($event['user_data'], $user_data);
        }
        
        // Add custom data
        if (!empty($custom_data) && is_array($custom_data)) {
            $event['custom_data'] = $custom_data;
        }
        
        // Prepare API request data
        $api_url = "https://graph.facebook.com/v18.0/{$pixel_id}/events";
        $data = array(
            'data' => array($event),
            'access_token' => $access_token,
        );
        
        // Add test event code at the request level (not in event data)
        $test_mode = $plugin->get_option('test_mode');
        if ($test_mode) {
            $test_event_code = $plugin->get_option('test_event_code');
            if (!empty($test_event_code)) {
                $data['test_event_code'] = $test_event_code;
            }
        }
        
        // Send request
        $response = wp_remote_post($api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
        ));
        
        if (is_wp_error($response)) {
            self::log('CAPI event failed: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            self::log("CAPI event sent successfully: {$event_name} (ID: {$event['event_id']})", 'info');
            return true;
        } else {
            self::log("CAPI event failed: HTTP {$response_code} - {$response_body}", 'error');
            return false;
        }
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
        
        return array(
            'content_id' => (string) $product_id,
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'content_category' => self::get_product_categories($product),
            'value' => self::format_price($product->get_price()),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
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
            'Purchase' => 'track_purchase',
            'Lead' => 'track_lead',
            'CompleteRegistration' => 'track_completeregistration',
            'Search' => 'track_search',
        );
        
        $option_key = isset($option_map[$event_name]) ? $option_map[$event_name] : null;
        return $option_key ? $plugin->get_option($option_key, true) : false;
    }
}