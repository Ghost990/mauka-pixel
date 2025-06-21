<?php
/**
 * Admin UI for Mauka Meta Pixel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI Class
 */
class Mauka_Meta_Pixel_Admin {
    
    /**
     * Render admin page
     */
    public function render_page() {
        $plugin = mauka_meta_pixel();
        if (!$plugin) {
            echo '<div class="notice notice-error"><p>Plugin not initialized properly.</p></div>';
            return;
        }
        
        $options = $plugin->get_options();
        $message = '';
        $message_type = '';
        
        // Handle form submission with proper nonce verification
        if (isset($_POST['submit']) && isset($_POST['mauka_meta_pixel_nonce'])) {
            if (wp_verify_nonce($_POST['mauka_meta_pixel_nonce'], 'mauka_meta_pixel_save')) {
                $result = $this->save_settings($_POST);
                if ($result['success']) {
                    $message = __('✅ Beállítások sikeresen mentve!', 'mauka-meta-pixel');
                    $message_type = 'success';
                    // Reload options after save
                    $plugin->reload_options();
                    $options = $plugin->get_options();
                } else {
                    $message = $result['message'];
                    $message_type = 'error';
                }
            } else {
                $message = __('Biztonsági ellenőrzés sikertelen!', 'mauka-meta-pixel');
                $message_type = 'error';
            }
        }
        
        // Handle test connection
        if (isset($_POST['test_connection']) && isset($_POST['mauka_meta_pixel_nonce'])) {
            if (wp_verify_nonce($_POST['mauka_meta_pixel_nonce'], 'mauka_meta_pixel_save')) {
                $this->test_connection();
            }
        }
        
        // Handle log actions
        $this->handle_log_actions();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Meta Pixel Beállítások', 'mauka-meta-pixel'); ?></h1>
            
            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?>">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <?php settings_errors('mauka_meta_pixel_options'); ?>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        
                        <!-- Main Settings Form -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('Plugin Beállítások', 'mauka-meta-pixel'); ?></span></h2>
                            <div class="inside">
                                <form method="post" action="">
                                    <?php wp_nonce_field('mauka_meta_pixel_save', 'mauka_meta_pixel_nonce'); ?>
                                    
                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <!-- Basic Settings -->
                                            <tr>
                                                <th scope="row">
                                                    <label for="pixel_id"><?php _e('Meta Pixel ID', 'mauka-meta-pixel'); ?> <span style="color: red;">*</span></label>
                                                </th>
                                                <td>
                                                    <input type="text" id="pixel_id" name="mauka_meta_pixel_options[pixel_id]" 
                                                           value="<?php echo esc_attr($options['pixel_id']); ?>" 
                                                           class="regular-text" placeholder="123456789012345" />
                                                    <p class="description">
                                                        <?php _e('A Meta Pixel ID-ja (15-16 számjegy). Ezt megtalálod az Events Manager-ben.', 'mauka-meta-pixel'); ?>
                                                    </p>
                                                </td>
                                            </tr>
                                            
                                            <tr>
                                                <th scope="row">
                                                    <label for="access_token"><?php _e('Access Token (CAPI)', 'mauka-meta-pixel'); ?></label>
                                                </th>
                                                <td>
                                                    <input type="password" id="access_token" name="mauka_meta_pixel_options[access_token]" 
                                                           value="<?php echo esc_attr($options['access_token']); ?>" 
                                                           class="regular-text" />
                                                    <p class="description">
                                                        <?php _e('A Conversions API access token. Ezt a Meta fejlesztői konzolban generálhatod.', 'mauka-meta-pixel'); ?>
                                                    </p>
                                                </td>
                                            </tr>
                                            
                                            <tr>
                                                <th scope="row">
                                                    <?php _e('Tracking Módok', 'mauka-meta-pixel'); ?>
                                                </th>
                                                <td>
                                                    <fieldset>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[pixel_enabled]" value="1" 
                                                                   <?php checked($options['pixel_enabled']); ?> />
                                                            <strong><?php _e('Meta Pixel (Böngésző oldali tracking)', 'mauka-meta-pixel'); ?></strong>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[capi_enabled]" value="1" 
                                                                   <?php checked($options['capi_enabled']); ?> />
                                                            <strong><?php _e('Conversions API (Szerver oldali tracking)', 'mauka-meta-pixel'); ?></strong>
                                                        </label>
                                                    </fieldset>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <!-- Test Mode Section -->
                                    <h3><?php _e('Teszt Mód', 'mauka-meta-pixel'); ?></h3>
                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <tr>
                                                <th scope="row">
                                                    <?php _e('Fejlesztői Beállítások', 'mauka-meta-pixel'); ?>
                                                </th>
                                                <td>
                                                    <label>
                                                        <input type="checkbox" name="mauka_meta_pixel_options[test_mode]" value="1" 
                                                               <?php checked($options['test_mode']); ?> />
                                                        <strong><?php _e('Teszt mód engedélyezése', 'mauka-meta-pixel'); ?></strong>
                                                    </label>
                                                    <p class="description">
                                                        <?php _e('Teszt módban minden CAPI esemény tartalmazza a teszt event code-ot.', 'mauka-meta-pixel'); ?>
                                                    </p>
                                                </td>
                                            </tr>
                                            
                                            <tr>
                                                <th scope="row">
                                                    <label for="test_event_code"><?php _e('Test Event Code', 'mauka-meta-pixel'); ?></label>
                                                </th>
                                                <td>
                                                    <input type="text" id="test_event_code" name="mauka_meta_pixel_options[test_event_code]" 
                                                           value="<?php echo esc_attr($options['test_event_code']); ?>" 
                                                           class="regular-text" placeholder="TEST12345" />
                                                    <p class="description">
                                                        <?php _e('A teszt event code, amit a Meta Events Manager-ben generálhatsz.', 'mauka-meta-pixel'); ?>
                                                    </p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <!-- Event Tracking Section -->
                                    <h3><?php _e('Esemény Követés', 'mauka-meta-pixel'); ?></h3>
                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <tr>
                                                <th scope="row">
                                                    <?php _e('Standard Események', 'mauka-meta-pixel'); ?>
                                                </th>
                                                <td>
                                                    <fieldset>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_pageview]" value="1" 
                                                                   <?php checked($options['track_pageview']); ?> />
                                                            <strong>PageView</strong> - <?php _e('Oldal megtekintés', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_viewcontent]" value="1" 
                                                                   <?php checked($options['track_viewcontent']); ?> />
                                                            <strong>ViewContent</strong> - <?php _e('Termék megtekintés', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_addtocart]" value="1" 
                                                                   <?php checked($options['track_addtocart']); ?> />
                                                            <strong>AddToCart</strong> - <?php _e('Kosárba helyezés', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_initiatecheckout]" value="1" 
                                                                   <?php checked($options['track_initiatecheckout']); ?> />
                                                            <strong>InitiateCheckout</strong> - <?php _e('Pénztár megkezdése', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_purchase]" value="1" 
                                                                   <?php checked($options['track_purchase']); ?> />
                                                            <strong>Purchase</strong> - <?php _e('Vásárlás', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_search]" value="1" 
                                                                   <?php checked($options['track_search']); ?> />
                                                            <strong>Search</strong> - <?php _e('Keresés', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                    </fieldset>
                                                </td>
                                            </tr>
                                            
                                            <tr>
                                                <th scope="row">
                                                    <?php _e('További Események', 'mauka-meta-pixel'); ?>
                                                </th>
                                                <td>
                                                    <fieldset>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_lead]" value="1" 
                                                                   <?php checked($options['track_lead']); ?> />
                                                            <strong>Lead</strong> - <?php _e('Kapcsolatfelvétel', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_completeregistration]" value="1" 
                                                                   <?php checked($options['track_completeregistration']); ?> />
                                                            <strong>CompleteRegistration</strong> - <?php _e('Regisztráció', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_viewcategory]" value="1" 
                                                                   <?php checked($options['track_viewcategory']); ?> />
                                                            <strong>ViewCategory</strong> - <?php _e('Kategória megtekintés', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_addtowishlist]" value="1" 
                                                                   <?php checked($options['track_addtowishlist']); ?> />
                                                            <strong>AddToWishlist</strong> - <?php _e('Kívánságlistához adás (YITH)', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                        <br><br>
                                                        <label>
                                                            <input type="checkbox" name="mauka_meta_pixel_options[track_addpaymentinfo]" value="1" 
                                                                   <?php checked($options['track_addpaymentinfo']); ?> />
                                                            <strong>AddPaymentInfo</strong> - <?php _e('Fizetési mód megadása', 'mauka-meta-pixel'); ?>
                                                        </label>
                                                    </fieldset>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <!-- Logging -->
                                    <h3><?php _e('Naplózás', 'mauka-meta-pixel'); ?></h3>
                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <tr>
                                                <th scope="row">
                                                    <?php _e('Debug Eszközök', 'mauka-meta-pixel'); ?>
                                                </th>
                                                <td>
                                                    <label>
                                                        <input type="checkbox" name="mauka_meta_pixel_options[enable_logging]" value="1" 
                                                               <?php checked($options['enable_logging']); ?> />
                                                        <strong><?php _e('CAPI kérések naplózása', 'mauka-meta-pixel'); ?></strong>
                                                    </label>
                                                    <p class="description">
                                                        <?php _e('Minden szerver oldali kérés naplózva lesz debug célokra.', 'mauka-meta-pixel'); ?>
                                                    </p>
                                                    
                                                    <?php $this->render_log_status(); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <p class="submit">
                                        <input type="submit" name="submit" class="button-primary" 
                                               value="<?php _e('Beállítások mentése', 'mauka-meta-pixel'); ?>" />
                                        <input type="submit" name="test_connection" class="button" 
                                               value="<?php _e('Kapcsolat tesztelése', 'mauka-meta-pixel'); ?>" />
                                    </p>
                                </form>
                            </div>
                        </div>
                        
                    </div>
                    
                    <!-- Sidebar -->
                    <div id="postbox-container-1" class="postbox-container">
                        
                        <!-- Status Widget -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('Plugin Állapot', 'mauka-meta-pixel'); ?></span></h2>
                            <div class="inside">
                                <?php $this->render_status_widget($options); ?>
                            </div>
                        </div>
                        
                        <!-- Help Widget -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('Gyors Segítség', 'mauka-meta-pixel'); ?></span></h2>
                            <div class="inside">
                                <?php $this->render_help_widget(); ?>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .mauka-status-good { color: #46b450; font-weight: bold; }
        .mauka-status-warning { color: #ffb900; font-weight: bold; }
        .mauka-status-error { color: #dc3232; font-weight: bold; }
        .mauka-status-item { margin: 8px 0; padding: 8px; background: #f9f9f9; border-radius: 4px; }
        .mauka-log-actions { margin-top: 10px; }
        .mauka-log-actions .button { margin-right: 5px; }
        </style>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings($post_data) {
        if (!isset($post_data['mauka_meta_pixel_options']) || !is_array($post_data['mauka_meta_pixel_options'])) {
            return array('success' => false, 'message' => __('Hiányzó beállítások!', 'mauka-meta-pixel'));
        }
        
        $input = $post_data['mauka_meta_pixel_options'];
        $sanitized = array();
        
        // Text fields with sanitization
        $text_fields = array('pixel_id', 'access_token', 'test_event_code');
        foreach ($text_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? sanitize_text_field($input[$field]) : '';
        }
        
        // Validate Pixel ID format
        if (!empty($sanitized['pixel_id']) && !preg_match('/^\d{15,16}$/', $sanitized['pixel_id'])) {
            return array('success' => false, 'message' => __('Érvénytelen Pixel ID formátum! 15-16 számjegyből kell állnia.', 'mauka-meta-pixel'));
        }
        
        // Boolean fields
        $boolean_fields = array(
            'pixel_enabled', 'capi_enabled', 'test_mode', 'enable_logging',
            'track_pageview', 'track_viewcontent', 'track_addtocart', 
            'track_initiatecheckout', 'track_purchase', 'track_lead', 
            'track_completeregistration', 'track_search', 'track_viewcategory',
            'track_addtowishlist', 'track_addpaymentinfo'
        );
        
        foreach ($boolean_fields as $field) {
            $sanitized[$field] = isset($input[$field]) && $input[$field] == '1';
        }
        
        // Merge with defaults
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
            'track_viewcategory' => true,
            'track_addtowishlist' => true,
            'track_addpaymentinfo' => true,
            'pixel_id' => '',
            'access_token' => '',
            'test_event_code' => ''
        );
        
        $final_options = array_merge($defaults, $sanitized);
        
        // Update the option
        $result = update_option('mauka_meta_pixel_options', $final_options);
        
        if ($result || get_option('mauka_meta_pixel_options') == $final_options) {
            return array('success' => true, 'message' => __('Beállítások sikeresen mentve!', 'mauka-meta-pixel'));
        }
        
        return array('success' => false, 'message' => __('Hiba történt a mentés során!', 'mauka-meta-pixel'));
    }
    
    /**
     * Test connection
     */
    private function test_connection() {
        $plugin = mauka_meta_pixel();
        if (!$plugin) {
            echo '<div class="notice notice-error"><p>Plugin not available.</p></div>';
            return;
        }
        
        $pixel_id = $plugin->get_option('pixel_id');
        $access_token = $plugin->get_option('access_token');
        $pixel_enabled = $plugin->get_option('pixel_enabled');
        $capi_enabled = $plugin->get_option('capi_enabled');
        $test_mode = $plugin->get_option('test_mode');
        
        echo '<div class="notice notice-info"><p><strong>' . __('🧪 Teszt eredmények:', 'mauka-meta-pixel') . '</strong></p></div>';
        
        // Test 1: Pixel ID validation
        if (empty($pixel_id)) {
            echo '<div class="notice notice-error"><p>' . __('❌ Hiányzó Pixel ID!', 'mauka-meta-pixel') . '</p></div>';
            return;
        } else {
            echo '<div class="notice notice-success"><p>' . __('✅ Pixel ID: ', 'mauka-meta-pixel') . esc_html($pixel_id) . '</p></div>';
        }
        
        // Test 2: Pixel enabled check
        if ($pixel_enabled) {
            echo '<div class="notice notice-success"><p>' . __('✅ Meta Pixel engedélyezve', 'mauka-meta-pixel') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>' . __('⚠️ Meta Pixel letiltva', 'mauka-meta-pixel') . '</p></div>';
        }
        
        // Test 3: CAPI test (disable test mode temporarily for clean test)
        if ($capi_enabled) {
            if (empty($access_token)) {
                echo '<div class="notice notice-error"><p>' . __('❌ CAPI: Hiányzó Access Token!', 'mauka-meta-pixel') . '</p></div>';
            } else {
                // Temporarily disable test mode for clean CAPI test
                $original_test_mode = $plugin->get_option('test_mode');
                $plugin->update_option('test_mode', false);
                
                // Send clean test event without test_event_code
                $test_result = Mauka_Meta_Pixel_Helpers::send_capi_event('PageView', array(
                    'event_id' => 'test_connection_' . time(),
                ));
                
                // Restore original test mode
                $plugin->update_option('test_mode', $original_test_mode);
                
                if ($test_result) {
                    echo '<div class="notice notice-success"><p>' . __('✅ CAPI teszt sikeres! Ellenőrizd az Events Manager-ben.', 'mauka-meta-pixel') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . __('❌ CAPI teszt sikertelen! Ellenőrizd a log fájlt.', 'mauka-meta-pixel') . '</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-info"><p>' . __('ℹ️ CAPI letiltva', 'mauka-meta-pixel') . '</p></div>';
        }
        
        // Test 4: Test mode status
        if ($test_mode) {
            echo '<div class="notice notice-warning"><p>' . __('⚠️ Teszt mód aktív - kapcsold ki éles használatra!', 'mauka-meta-pixel') . '</p></div>';
        }
        
        // Test 5: Browser pixel check instructions
        echo '<div class="notice notice-info"><p>' . 
             __('🔍 Browser pixel ellenőrzés: Nyisd meg a konzolt (F12), írd be: <code>fbq</code> és nyomj Enter-t. Ha <code>function</code>-t látsz, a pixel betöltődött.', 'mauka-meta-pixel') . 
             '</p></div>';
        
        echo '<div class="notice notice-info"><p>' . 
             __('📍 Következő lépések: Látogass el az oldal lapjaira és 2-3 perc múlva ellenőrizd a Meta Events Manager-ben az eseményeket.', 'mauka-meta-pixel') . 
             '</p></div>';
    }
    
    /**
     * Render status widget
     */
    private function render_status_widget($options) {
        ?>
        <div class="mauka-status-item">
            <strong><?php _e('Meta Pixel:', 'mauka-meta-pixel'); ?></strong><br>
            <?php if (!empty($options['pixel_id']) && $options['pixel_enabled']): ?>
                <span class="mauka-status-good">✅ <?php _e('Aktív', 'mauka-meta-pixel'); ?></span>
            <?php else: ?>
                <span class="mauka-status-error">❌ <?php _e('Inaktív', 'mauka-meta-pixel'); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="mauka-status-item">
            <strong><?php _e('CAPI:', 'mauka-meta-pixel'); ?></strong><br>
            <?php if (!empty($options['access_token']) && $options['capi_enabled']): ?>
                <span class="mauka-status-good">✅ <?php _e('Aktív', 'mauka-meta-pixel'); ?></span>
            <?php else: ?>
                <span class="mauka-status-error">❌ <?php _e('Inaktív', 'mauka-meta-pixel'); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="mauka-status-item">
            <strong><?php _e('WooCommerce:', 'mauka-meta-pixel'); ?></strong><br>
            <?php if (class_exists('WooCommerce')): ?>
                <span class="mauka-status-good">✅ <?php _e('Telepítve', 'mauka-meta-pixel'); ?></span>
            <?php else: ?>
                <span class="mauka-status-warning">⚠️ <?php _e('Nincs telepítve', 'mauka-meta-pixel'); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render help widget
     */
    private function render_help_widget() {
        ?>
        <p><strong><?php _e('Gyors beállítás:', 'mauka-meta-pixel'); ?></strong></p>
        <ol>
            <li><?php _e('Pixel ID megadása', 'mauka-meta-pixel'); ?></li>
            <li><?php _e('Meta Pixel bekapcsolása', 'mauka-meta-pixel'); ?></li>
            <li><?php _e('Események kiválasztása', 'mauka-meta-pixel'); ?></li>
            <li><?php _e('Beállítások mentése', 'mauka-meta-pixel'); ?></li>
        </ol>
        
        <p><strong><?php _e('Hasznos linkek:', 'mauka-meta-pixel'); ?></strong></p>
        <ul>
            <li><a href="https://business.facebook.com/events_manager" target="_blank"><?php _e('Events Manager', 'mauka-meta-pixel'); ?></a></li>
        </ul>
        <?php
    }
    
    /**
     * Render log status
     */
    private function render_log_status() {
        $log_file = MAUKA_META_PIXEL_PLUGIN_DIR . 'log/mauka-capi.log';
        
        if (file_exists($log_file)) {
            $file_size = filesize($log_file);
            ?>
            <div class="mauka-log-actions">
                <p><strong><?php _e('Log fájl:', 'mauka-meta-pixel'); ?></strong> <?php echo size_format($file_size); ?></p>
                <a href="<?php echo admin_url('admin.php?page=mauka-meta-pixel&action=view_log'); ?>" 
                   class="button button-small"><?php _e('Log megtekintése', 'mauka-meta-pixel'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=mauka-meta-pixel&action=clear_log'); ?>" 
                   class="button button-small" 
                   onclick="return confirm('<?php _e('Biztosan törölni szeretnéd?', 'mauka-meta-pixel'); ?>')"><?php _e('Log törlése', 'mauka-meta-pixel'); ?></a>
            </div>
            <?php
        } else {
            ?>
            <p><em><?php _e('Még nincs log fájl.', 'mauka-meta-pixel'); ?></em></p>
            <?php
        }
    }
    
    /**
     * Handle log actions
     */
    private function handle_log_actions() {
        if (!isset($_GET['action']) || !current_user_can('manage_options')) {
            return;
        }
        
        $log_file = MAUKA_META_PIXEL_PLUGIN_DIR . 'log/mauka-capi.log';
        
        switch ($_GET['action']) {
            case 'view_log':
                if (file_exists($log_file)) {
                    echo '<div class="postbox" style="margin-top: 20px;">
                        <h2 class="hndle"><span>' . __('Log Fájl', 'mauka-meta-pixel') . '</span></h2>
                        <div class="inside">
                            <textarea readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px;">';
                    
                    $lines = file($log_file);
                    if ($lines) {
                        $last_lines = array_slice($lines, -100);
                        echo esc_textarea(implode('', $last_lines));
                    }
                    
                    echo '</textarea>
                            <p><a href="' . admin_url('admin.php?page=mauka-meta-pixel') . '" class="button">' . __('Vissza', 'mauka-meta-pixel') . '</a></p>
                        </div>
                    </div>';
                }
                break;
                
            case 'clear_log':
                if (file_exists($log_file)) {
                    if (unlink($log_file)) {
                        echo '<div class="notice notice-success"><p>' . __('Log fájl törölve.', 'mauka-meta-pixel') . '</p></div>';
                    }
                }
                break;
        }
    }
}