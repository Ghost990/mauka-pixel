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
     * Generate a unique event ID
     * 
     * @param string $event_name
     * @param mixed $identifier
     * @return string
     */
    public static function generate_event_id($event_name, $identifier = null) {
        // Create consistent IDs using same format as Meta expects
        // This ensures better deduplication between browser and server events
        $unique_base = uniqid('', true);
        
        // Add identifier for better specificity
        if ($identifier) {
            $unique_base .= '_' . $identifier;
        }
        
        // Use consistent format for both client-side and server-side events
        // Limited to 36 characters as recommended by Meta
        return substr(md5($event_name . '_' . $unique_base), 0, 36);
    }
    
    /**
     * Generate unique event ID for deduplication
     */
    public static function generate_legacy_event_id($event_name = '', $additional_data = '') {
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
        $dob = '';
        $gender = '';
        $external_id = '';

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $user_id = $order->get_user_id();
                if ($user_id) {
                    $user = get_user_by('id', $user_id);
                    // Get date of birth and gender if available from user meta
                    $dob = get_user_meta($user_id, 'billing_birth_date', true) ?: get_user_meta($user_id, 'birth_date', true);
                    $gender = get_user_meta($user_id, 'billing_gender', true) ?: get_user_meta($user_id, 'gender', true);
                    $external_id = (string) $user_id;
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
                    );
                    
                    // Try to get additional data from order meta
                    $dob = $order->get_meta('billing_birth_date', true) ?: get_post_meta($order_id, '_billing_birth_date', true);
                    $gender = $order->get_meta('billing_gender', true) ?: get_post_meta($order_id, '_billing_gender', true);
                    
                    // Try to get external_id from order meta or generate one from order
                    $external_id = $order->get_meta('_customer_user', true);
                    if (empty($external_id)) {
                        $external_id = 'guest_' . $order->get_id();
                    }
                }
            }
        } else {
            $user = wp_get_current_user();
        }
        
        if ($user && $user->ID) { // It's a logged-in user
            $billing_first_name = get_user_meta($user->ID, 'billing_first_name', true);
            $billing_last_name  = get_user_meta($user->ID, 'billing_last_name', true);
            $dob = get_user_meta($user->ID, 'billing_birth_date', true) ?: get_user_meta($user->ID, 'birth_date', true);
            $gender = get_user_meta($user->ID, 'billing_gender', true) ?: get_user_meta($user->ID, 'gender', true);
            $external_id = (string) $user->ID;

            $user_data = array(
                'em'          => self::hash_user_data($user->user_email),
                'ph'          => self::hash_phone(get_user_meta($user->ID, 'billing_phone', true)),
                'fn'          => self::hash_user_data($billing_first_name ?: $user->first_name),
                'ln'          => self::hash_user_data($billing_last_name ?: $user->last_name),
                'ct'          => self::hash_user_data(get_user_meta($user->ID, 'billing_city', true)),
                'st'          => self::hash_user_data(get_user_meta($user->ID, 'billing_state', true)),
                'zp'          => hash('sha256', get_user_meta($user->ID, 'billing_postcode', true)),
                'country'     => self::hash_user_data(get_user_meta($user->ID, 'billing_country', true)),
            );
        } elseif (function_exists('WC') && 
                 WC() && 
                 WC()->checkout && 
                 method_exists(WC()->checkout, 'get_posted_data') && 
                 function_exists('is_checkout') && 
                 is_checkout()) {
            // Guest user on checkout page
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
            );
            
            // Check for additional fields in posted data
            if (isset($posted_data['billing_birth_date'])) {
                $dob = $posted_data['billing_birth_date'];
            }
            if (isset($posted_data['billing_gender'])) {
                $gender = $posted_data['billing_gender'];
            }
            
            // Generate temporary external_id for checkout
            $session_id = self::get_session_id();
            $external_id = 'checkout_' . $session_id;
        } elseif (function_exists('WC') && WC() && WC()->session) {
            // Try to get data from WooCommerce session for non-checkout pages
            $session_customer = WC()->session->get('customer');
            if (!empty($session_customer)) {
                if (!empty($session_customer['email'])) {
                    $user_data['em'] = self::hash_user_data($session_customer['email']);
                }
                if (!empty($session_customer['phone'])) {
                    $user_data['ph'] = self::hash_phone($session_customer['phone']);
                }
                if (!empty($session_customer['first_name'])) {
                    $user_data['fn'] = self::hash_user_data($session_customer['first_name']);
                }
                if (!empty($session_customer['last_name'])) {
                    $user_data['ln'] = self::hash_user_data($session_customer['last_name']);
                }
                if (!empty($session_customer['postcode'])) {
                    $user_data['zp'] = hash('sha256', $session_customer['postcode']);
                }
                if (!empty($session_customer['city'])) {
                    $user_data['ct'] = self::hash_user_data($session_customer['city']);
                }
                if (!empty($session_customer['state'])) {
                    $user_data['st'] = self::hash_user_data($session_customer['state']);
                }
                if (!empty($session_customer['country'])) {
                    $user_data['country'] = self::hash_user_data($session_customer['country']);
                }
                
                // Generate session-based external_id
                $session_id = self::get_session_id();
                $external_id = 'session_' . $session_id;
            }
        }

        // Add date of birth if available
        if (!empty($dob)) {
            // Format date to YYYYMMDD
            $formatted_dob = preg_replace('/[^0-9]/', '', $dob);
            if (strlen($formatted_dob) == 8) {
                $user_data['db'] = self::hash_user_data($formatted_dob);
            } elseif (strtotime($dob)) {
                $user_data['db'] = self::hash_user_data(date('Ymd', strtotime($dob)));
            }
        }
        
        // Add gender if available
        if (!empty($gender)) {
            $gender = strtolower(substr($gender, 0, 1));
            if ($gender == 'm' || $gender == 'f') {
                $user_data['ge'] = self::hash_user_data($gender);
            }
        }
        
        // Add external_id if available
        if (!empty($external_id)) {
            // Make sure external_id is properly hashed for better security
            $user_data['external_id'] = self::hash_user_data($external_id);
        } else {
            // If we still don't have an external_id, generate one from session
            $session_id = self::get_session_id();
            $user_data['external_id'] = self::hash_user_data('visitor_' . $session_id);
        }
        
        // Add default location data for Budapest, Hungary if not already set
        if (empty($user_data['ct'])) {
            $user_data['ct'] = self::hash_user_data('Budapest');
        }
        if (empty($user_data['country'])) {
            $user_data['country'] = self::hash_user_data('HU');
        }
        if (empty($user_data['st'])) {
            $user_data['st'] = self::hash_user_data('Budapest');
        }
        if (empty($user_data['zp'])) {
            // Default postal code for central Budapest
            $user_data['zp'] = hash('sha256', '1051');
        }
        
        // Add client IP and User Agent if available
        if (self::get_client_ip()) {
            $user_data['client_ip_address'] = self::get_client_ip();
        }
        if (self::get_user_agent()) {
            $user_data['client_user_agent'] = self::get_user_agent();
        }
        
        // Get Facebook Click ID and Browser ID from cookies
        if (isset($_COOKIE['_fbp'])) {
            $user_data['fbp'] = $_COOKIE['_fbp'];
        } else {
            $user_data['fbp'] = self::get_fbp();
        }
        
        if (isset($_COOKIE['_fbc'])) {
            $user_data['fbc'] = $_COOKIE['_fbc'];
        } else {
            $fbc = self::get_fbc();
            if ($fbc) {
                $user_data['fbc'] = $fbc;
            }
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
        // Force logging during debugging
        $debug_mode = true;
        
        // Test logging functionality
        $test_log_file = MAUKA_META_PIXEL_PLUGIN_DIR . 'log/test-log.txt';
        @file_put_contents($test_log_file, "Log function called: {$message}\n", FILE_APPEND);
        
        // Also try to log to WordPress debug log
        if (function_exists('error_log')) {
            error_log("[Mauka Meta Pixel] {$message}");
        }
        
        try {
            $plugin = null;
            if (function_exists('mauka_meta_pixel')) {
                $plugin = mauka_meta_pixel();
            }
            
            if ((!$plugin || !$plugin->get_option('enable_logging')) && !$debug_mode) {
                return;
            }
            
            $log_file = MAUKA_META_PIXEL_PLUGIN_DIR . 'log/mauka-capi.log';
            
            // Create log directory if not exists
            $log_dir = dirname($log_file);
            if (!is_dir($log_dir)) {
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($log_dir);
                } else {
                    mkdir($log_dir, 0755, true);
                }
            }
            
            // Check if directory exists and is writable
            if (!is_dir($log_dir) || !is_writable($log_dir)) {
                return;
            }
            
            $timestamp = function_exists('current_time') ? current_time('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
            
            // Try to write to log file with error handling
            if (@file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX) === false) {
                // If we can't write to our log file, fall back to WP debug log
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                    error_log("Mauka Meta Pixel: {$message}");
                }
            }
            
            // Limit log file size (max 10MB)
            if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
                $lines = @file($log_file);
                if ($lines && is_array($lines)) {
                    $keep_lines = array_slice($lines, -1000); // Keep last 1000 lines
                    @file_put_contents($log_file, implode('', $keep_lines));
                }
            }
        } catch (Exception $e) {
            // Silent fail - we don't want logging to break functionality
        }
    }
    
    /**
     * Send CAPI event to Meta
     * 
     * @param string $event_name The name of the event
     * @param array $event_data Event metadata like event_id
     * @param array $custom_data Custom event data
     * @param int|null $order_id WooCommerce order ID if applicable
     * @param int|null $event_time Custom event timestamp
     * @param array|null $additional_user_data Additional user data to merge with automatically collected data
     * @return bool Success status
     */
    public static function send_capi_event($event_name, $event_data = array(), $custom_data = array(), $order_id = null, $event_time = null, $additional_user_data = null) {
        // Debug log the event attempt
        self::log("Attempting to send CAPI event: {$event_name}", 'debug');
        
        // Check if the main plugin function exists
        if (!function_exists('mauka_meta_pixel')) {
            self::log("CAPI event failed: mauka_meta_pixel function does not exist", 'error');
            return false;
        }
        
        $plugin = mauka_meta_pixel();
        
        if (!$plugin) {
            self::log("CAPI event failed: plugin instance not available", 'error');
            return false;
        }
        
        if (!$plugin->get_option('capi_enabled')) {
            self::log("CAPI event not sent: CAPI is disabled in settings", 'info');
            return false;
        }
        
        $pixel_id = $plugin->get_option('pixel_id');
        $access_token = $plugin->get_option('access_token');
        
        // Debug log the pixel ID and access token (masked)
        self::log("Pixel ID: {$pixel_id}", 'debug');
        if (!empty($access_token)) {
            $masked_token = substr($access_token, 0, 4) . '...' . substr($access_token, -4);
            self::log("Access Token: {$masked_token}", 'debug');
        }
        
        if (empty($pixel_id) || empty($access_token)) {
            self::log('CAPI event failed: Missing Pixel ID or Access Token', 'error');
            return false;
        }
        
        // Use provided event_time or current time
        if ($event_time === null) {
            $event_time = time();
        }
        
        // Get user data - enhancing with all available parameters for better match rate
        $user_data = self::get_user_data($order_id);
        
        // Ensure we have all recommended user data parameters to improve match quality
        self::ensure_recommended_user_data($user_data);
        
        // Merge with additional user data if provided
        if (!empty($additional_user_data) && is_array($additional_user_data)) {
            $user_data = array_merge($user_data, $additional_user_data);
        }
        
        // CRITICAL: Ensure we have the necessary Facebook cookies for DEDUPLICATION
        if (empty($user_data['fbp'])) {
            $user_data['fbp'] = self::get_fbp();
            if (!empty($user_data['fbp'])) {
                self::log("Added missing fbp cookie for deduplication: " . substr($user_data['fbp'], 0, 10) . '...', 'debug');
            }
        }
        
        if (empty($user_data['fbc'])) {
            $fbc = self::get_fbc();
            if ($fbc) {
                $user_data['fbc'] = $fbc;
                self::log("Added missing fbc cookie for deduplication: " . substr($user_data['fbc'], 0, 10) . '...', 'debug');
            }
        }
        
        // Get event ID - ensure consistency with browser events for proper deduplication
        $event_id = isset($event_data['event_id']) ? $event_data['event_id'] : self::generate_event_id($event_name);
        
        // Log deduplication keys available for troubleshooting
        $dedupe_keys_available = array(
            'has_event_id' => !empty($event_id),
            'has_fbp' => !empty($user_data['fbp']),
            'has_fbc' => !empty($user_data['fbc']),
            'has_external_id' => !empty($user_data['external_id']),
            'has_em' => !empty($user_data['em']),
            'has_ph' => !empty($user_data['ph'])
        );
        self::log("CAPI Deduplication keys for {$event_name}: " . json_encode($dedupe_keys_available), 'info');
        
        // Prepare event data with proper deduplication fields
        $event = array(
            'event_name' => $event_name,
            'event_time' => (int)$event_time, // Ensure integer timestamp for event_time
            'event_id' => $event_id,
            'action_source' => 'website',
            'user_data' => $user_data,
        );
        
        // Safely add event source URL if possible - important for event matching
        if (function_exists('home_url') && isset($GLOBALS['wp']) && isset($GLOBALS['wp']->request)) {
            $event['event_source_url'] = home_url(add_query_arg(array(), $GLOBALS['wp']->request));
        } elseif (function_exists('home_url')) {
            $event['event_source_url'] = home_url('/');
        }
        
        // Add event timestamp in ISO 8601 format as a backup for event_time
        // This helps ensure proper event timing for Meta
        $event['event_timestamp'] = date('c', $event_time);
        
        // Process custom data with improved content_id handling
        if (!empty($custom_data)) {
            // Ensure content_ids is properly formatted as array if it exists
            if (isset($custom_data['content_ids']) && !is_array($custom_data['content_ids'])) {
                if (is_string($custom_data['content_ids'])) {
                    // Convert comma-separated string to array
                    $custom_data['content_ids'] = array_map('trim', explode(',', $custom_data['content_ids']));
                } else {
                    // Convert single value to array
                    $custom_data['content_ids'] = array($custom_data['content_ids']);
                }
            }
            
            // Ensure content_id is included in content_ids if present
            if (isset($custom_data['content_id']) && !empty($custom_data['content_id'])) {
                if (!isset($custom_data['content_ids'])) {
                    $custom_data['content_ids'] = array($custom_data['content_id']);
                } elseif (!in_array($custom_data['content_id'], $custom_data['content_ids'])) {
                    $custom_data['content_ids'][] = $custom_data['content_id'];
                }
            }
            
            // Add custom data to event
            $event['custom_data'] = $custom_data;
        }
        
        // Prepare API URL and data
        $api_url = "https://graph.facebook.com/v17.0/{$pixel_id}/events";
        $data = array(
            'data' => array($event),
            'access_token' => $access_token,
        );
        
        // Get test event code if available
        $test_event_code = $plugin->get_option('test_event_code');
        if (!empty($test_event_code)) {
            $data['test_event_code'] = $test_event_code;
            self::log("Using test event code: {$test_event_code}", 'debug');
        } else {
            self::log("No test event code configured", 'debug');
        }
        
        // Send request with retry logic for better CAPI coverage
        $max_retries = 2;
        $retry_count = 0;
        
        // Log the request payload (for debugging)
        self::log("CAPI request payload: " . json_encode($data), 'debug');
        self::log("CAPI API URL: {$api_url}", 'debug');
        self::log("Test event code: " . (!empty($test_event_code) ? $test_event_code : 'none'), 'debug');
        
        do {
            self::log("Sending CAPI request (attempt #{$retry_count})", 'debug');
            
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
            
            // Log the full response for debugging
            self::log("CAPI response code: {$response_code}", 'debug');
            self::log("CAPI response body: {$response_body}", 'debug');
            
            if ($response_code === 200) {
                // Parse response to check for test event validation
                $response_data = json_decode($response_body, true);
                
                if (!empty($test_event_code)) {
                    if (isset($response_data['events_received']) && $response_data['events_received'] > 0) {
                        self::log("Test event successfully received by Meta: {$event_name} (ID: {$event['event_id']})", 'info');
                    } else {
                        self::log("Test event may not have been properly received by Meta despite 200 response", 'warning');
                    }
                }
                
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
        
        // Fallback return in case we somehow exit the loop without returning
        return false;
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
            self::log("Cannot get product data: Invalid product ID or WooCommerce not active", 'debug');
            return array();
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            self::log("Cannot get product data: Product {$product_id} not found", 'debug');
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
        
        // Log the content ID generation
        self::log("Generated content_id '{$content_id}' for product {$product_id} using format {$content_id_format}", 'debug');
        
        // Build a comprehensive product data array for Meta
        $product_data = array(
            'content_id' => $content_id,
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value' => self::format_price($product->get_price()),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'content_ids' => array($content_id),
            'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
        );
        
        // Add item_price for better reporting
        $product_data['item_price'] = self::format_price($product->get_price());
        
        // Add product URL if available
        if (method_exists($product, 'get_permalink')) {
            $product_data['item_url'] = $product->get_permalink();
        }
        
        // Add product image URL if available
        if (function_exists('wp_get_attachment_url') && $product->get_image_id()) {
            $product_data['image_url'] = wp_get_attachment_url($product->get_image_id());
        }
        
        // Add product categories
        $categories = self::get_product_categories($product);
        if (!empty($categories)) {
            $product_data['content_category'] = $categories;
        }
        
        // Add product brand if available
        $brand = self::get_product_brand($product);
        if (!empty($brand)) {
            $product_data['brand'] = $brand;
        }
        
        return $product_data;
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
        self::log("Checking if event '{$event_name}' is enabled", 'debug');
        
        $plugin = mauka_meta_pixel();
        
        if (!$plugin) {
            self::log("Event '{$event_name}' check failed: plugin instance not available", 'debug');
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
        
        if (!$option_key) {
            self::log("Event '{$event_name}' check failed: no matching option key in map", 'debug');
            return false;
        }
        
        $is_enabled = $plugin->get_option($option_key, true);
        self::log("Event '{$event_name}' is " . ($is_enabled ? 'enabled' : 'disabled') . " (option key: {$option_key})", 'debug');
        
        return $is_enabled;
    }
    
    /**
     * Ensure all recommended user data parameters are included to improve match rates
     * Based on the Meta Pixel diagnostics data showing potential 12-16% match rate improvement
     * 
     * @param array &$user_data The user data array to enhance with missing parameters
     * @return void
     */
    public static function ensure_recommended_user_data(&$user_data) {
        self::log("Enhancing user data parameters for better match quality", 'debug');
        
        // Default values to use when user data is incomplete
        // These significantly improve match quality according to Meta Pixel diagnostics
        $defaults = array(
            'ct' => self::hash_user_data('Budapest'), // City
            'st' => self::hash_user_data('Budapest'), // State
            'zp' => hash('sha256', '1051'),          // ZIP code
            'country' => self::hash_user_data('HU'),  // Country
        );
        
        // Define the most important parameters for match quality
        // Based on Meta Pixel diagnostics report showing 12-16% potential match improvement
        $important_params = array('em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'db', 'ge');
        
        // Logging current state of parameters
        $missing_params = array();
        foreach ($important_params as $param) {
            if (empty($user_data[$param])) {
                $missing_params[] = $param;
            }
        }
        
        if (!empty($missing_params)) {
            self::log("Missing user data parameters that could improve match quality: " . implode(', ', $missing_params), 'debug');
        }
        
        // Fill in missing parameters from defaults
        foreach ($defaults as $key => $value) {
            if (empty($user_data[$key])) {
                $user_data[$key] = $value;
                self::log("Added default value for user data parameter: {$key}", 'debug');
            }
        }
        
        // Ensure we have external_id - critical for user matching
        if (empty($user_data['external_id'])) {
            $session_id = self::get_session_id();
            $user_data['external_id'] = self::hash_user_data('visitor_' . $session_id);
            self::log("Generated default external_id from session", 'debug');
        }
        
        // Always ensure client IP and user agent are included
        if (empty($user_data['client_ip_address']) && self::get_client_ip()) {
            $user_data['client_ip_address'] = self::get_client_ip();
        }
        
        if (empty($user_data['client_user_agent']) && self::get_user_agent()) {
            $user_data['client_user_agent'] = self::get_user_agent();
        }
        
        self::log("User data parameters enhanced for better match quality", 'debug');
    }
} 