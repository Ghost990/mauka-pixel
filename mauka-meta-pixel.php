<?php
/**
 * Plugin Name: Mauka Meta Pixel
 * Plugin URI: https://mauka.hu
 * Description: Professional Meta Pixel integration with server-side CAPI, deduplication and WooCommerce support
 * Version: 1.0.1
 * Author: Mauka
 * Text Domain: mauka-meta-pixel
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package Mauka_Meta_Pixel
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAUKA_META_PIXEL_VERSION', '1.0.1');
define('MAUKA_META_PIXEL_PLUGIN_FILE', __FILE__);
define('MAUKA_META_PIXEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAUKA_META_PIXEL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAUKA_META_PIXEL_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class Mauka_Meta_Pixel {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options = array();
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_options();
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Load plugin options
     */
    private function load_options() {
        $defaults = array(
            'pixel_enabled' => false,
            'capi_enabled' => false,
            'test_mode' => false,
            'enable_logging' => true,
            'track_pageview' => true,
            'track_viewcontent' => true,
            'track_addtocart' => true,
            'track_initiatecheckout' => true,
            'track_purchase' => true,
            'track_lead' => false,
            'track_completeregistration' => false,
            'track_search' => true,
            'pixel_id' => '',
            'access_token' => '',
            'test_event_code' => ''
        );
        
        $stored_options = get_option('mauka_meta_pixel_options', array());
        $this->options = array_merge($defaults, $stored_options);
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('wp_head', array($this, 'add_pixel_script'), 1);
        add_action('wp_footer', array($this, 'add_pixel_noscript'), 999);
        
        // Declare WooCommerce HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        }
        
        // Plugin activation/deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once MAUKA_META_PIXEL_PLUGIN_DIR . 'includes/helpers.php';
        require_once MAUKA_META_PIXEL_PLUGIN_DIR . 'includes/tracking-events.php';
        
        if (is_admin()) {
            require_once MAUKA_META_PIXEL_PLUGIN_DIR . 'admin/admin-ui.php';
        }
    }
    
    /**
     * Declare WooCommerce HPOS compatibility
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
    
    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('mauka-meta-pixel', false, dirname(MAUKA_META_PIXEL_BASENAME) . '/languages');
    }
    
    /**
     * Add Pixel script to head
     */
    public function add_pixel_script() {
        $pixel_id = $this->get_option('pixel_id');
        $pixel_enabled = $this->get_option('pixel_enabled');
        
        if (empty($pixel_id) || !$pixel_enabled) {
            return;
        }
        
        // Load dynamic pixel script
        include MAUKA_META_PIXEL_PLUGIN_DIR . 'assets/pixel-js.php';
    }
    
    /**
     * Add Pixel noscript to footer
     */
    public function add_pixel_noscript() {
        $pixel_id = $this->get_option('pixel_id');
        $pixel_enabled = $this->get_option('pixel_enabled');
        
        if (empty($pixel_id) || !$pixel_enabled) {
            return;
        }
        
        echo '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . esc_attr($pixel_id) . '&ev=PageView&noscript=1" /></noscript>';
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Meta Pixel Beállítások', 'mauka-meta-pixel'),
            __('Meta Pixel', 'mauka-meta-pixel'),
            'manage_options',
            'mauka-meta-pixel',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'mauka_meta_pixel_options',
            'mauka_meta_pixel_options',
            array($this, 'sanitize_options')
        );
    }
    
    /**
     * Admin page callback
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $admin_ui = new Mauka_Meta_Pixel_Admin();
        $admin_ui->render_page();
    }
    
    /**
     * Sanitize options
     */
    public function sanitize_options($input) {
        if (!is_array($input)) {
            return $this->options;
        }
        
        $sanitized = array();
        
        // Text fields
        $text_fields = array('pixel_id', 'access_token', 'test_event_code');
        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            } else {
                $sanitized[$field] = '';
            }
        }
        
        // Validate Pixel ID
        if (!empty($sanitized['pixel_id']) && !preg_match('/^\d{15,16}$/', $sanitized['pixel_id'])) {
            add_settings_error(
                'mauka_meta_pixel_options',
                'invalid_pixel_id',
                __('Érvénytelen Pixel ID! 15-16 számjegyből kell állnia.', 'mauka-meta-pixel'),
                'error'
            );
            $sanitized['pixel_id'] = '';
        }
        
        // Boolean fields
        $boolean_fields = array(
            'pixel_enabled', 'capi_enabled', 'test_mode', 'enable_logging',
            'track_pageview', 'track_viewcontent', 'track_addtocart', 'track_initiatecheckout',
            'track_purchase', 'track_lead', 'track_completeregistration', 'track_search'
        );
        
        foreach ($boolean_fields as $field) {
            $sanitized[$field] = isset($input[$field]) && $input[$field] == '1';
        }
        
        // Merge with defaults to ensure all keys exist
        $defaults = array(
            'pixel_enabled' => false,
            'capi_enabled' => false,
            'test_mode' => false,
            'enable_logging' => true,
            'track_pageview' => true,
            'track_viewcontent' => true,
            'track_addtocart' => true,
            'track_initiatecheckout' => true,
            'track_purchase' => true,
            'track_lead' => false,
            'track_completeregistration' => false,
            'track_search' => true,
            'pixel_id' => '',
            'access_token' => '',
            'test_event_code' => ''
        );
        
        return array_merge($defaults, $sanitized);
    }
    
    /**
     * Load admin scripts
     */
    public function admin_scripts($hook) {
        if ('settings_page_mauka-meta-pixel' !== $hook) {
            return;
        }
        
        // Inline CSS to avoid external file dependency
        $css = "
        .mauka-status-good { color: #46b450; font-weight: bold; }
        .mauka-status-warning { color: #ffb900; font-weight: bold; }
        .mauka-status-error { color: #dc3232; font-weight: bold; }
        .mauka-status-item { margin: 8px 0; padding: 8px; background: #f9f9f9; border-radius: 4px; }
        .mauka-log-actions { margin-top: 10px; }
        .mauka-log-actions .button { margin-right: 5px; }
        ";
        
        wp_add_inline_style('wp-admin', $css);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create log directory
        $log_dir = MAUKA_META_PIXEL_PLUGIN_DIR . 'log';
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Create .htaccess to protect log files
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "deny from all\n");
        }
        
        // Set default options if not exist
        if (!get_option('mauka_meta_pixel_options')) {
            $default_options = array(
                'pixel_enabled' => false,
                'capi_enabled' => false,
                'test_mode' => false,
                'enable_logging' => true,
                'track_pageview' => true,
                'track_viewcontent' => true,
                'track_addtocart' => true,
                'track_initiatecheckout' => true,
                'track_purchase' => true,
                'track_lead' => false,
                'track_completeregistration' => false,
                'track_search' => true,
                'pixel_id' => '',
                'access_token' => '',
                'test_event_code' => ''
            );
            
            add_option('mauka_meta_pixel_options', $default_options);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
    }
    
    /**
     * Get plugin option
     */
    public function get_option($key, $default = null) {
        if (isset($this->options[$key])) {
            return $this->options[$key];
        }
        
        return $default !== null ? $default : false;
    }
    
    /**
     * Update plugin option
     */
    public function update_option($key, $value) {
        $this->options[$key] = $value;
        return update_option('mauka_meta_pixel_options', $this->options);
    }
    
    /**
     * Get all options
     */
    public function get_options() {
        return $this->options;
    }
    
    /**
     * Reload options from database
     */
    public function reload_options() {
        $this->load_options();
    }
}

/**
 * Initialize the plugin
 */
function mauka_meta_pixel() {
    return Mauka_Meta_Pixel::get_instance();
}

// Initialize plugin
add_action('plugins_loaded', 'mauka_meta_pixel', 10);

/**
 * Check if WooCommerce is active and initialize tracking
 */
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        // Initialize tracking after plugins are loaded
        add_action('init', function() {
            if (class_exists('Mauka_Meta_Pixel_Tracking')) {
                new Mauka_Meta_Pixel_Tracking();
            }
        });
    }
}, 20);