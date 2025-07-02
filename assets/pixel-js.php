<?php
/**
 * Dynamic Meta Pixel JavaScript
 * This file outputs the Meta Pixel base code with dynamic configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = mauka_meta_pixel();
if (!$plugin) {
    return;
}

$pixel_id = $plugin->get_option('pixel_id');

if (empty($pixel_id)) {
    return;
}

// Generate or get Facebook browser ID
$fbp = Mauka_Meta_Pixel_Helpers::get_fbp();
$fbc = Mauka_Meta_Pixel_Helpers::get_fbc();

// Log that we're loading the script
Mauka_Meta_Pixel_Helpers::log('Loading Meta Pixel script with ID: ' . $pixel_id);

// Check if we're on the checkout page
$is_checkout = false;

// Use multiple methods to detect checkout page
if (function_exists('is_checkout') && is_checkout() && !is_order_received_page()) {
    $is_checkout = true;
    Mauka_Meta_Pixel_Helpers::log('Checkout page detected via is_checkout()');
}

// Check URL for checkout
$current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
if (strpos($current_url, '/checkout') !== false) {
    $is_checkout = true;
    Mauka_Meta_Pixel_Helpers::log('Checkout page detected via URL: ' . $current_url);
}

// Generate a unique nonce for this script
$nonce = wp_create_nonce('mauka_meta_pixel_script');
?>
<!-- Meta Pixel Code -->
<script>
// Set global variables for test mode and test event code
window.maukaMetaPixelTestMode = <?php echo $plugin->get_option('test_mode') ? 'true' : 'false'; ?>;
window.maukaMetaPixelTestEventCode = '<?php echo esc_js($plugin->get_option('test_event_code')); ?>';
window.maukaMetaPixelId = '<?php echo esc_js($pixel_id); ?>';

console.log('Mauka Meta Pixel: Script tag starting');
console.log('Mauka Meta Pixel: Test mode:', window.maukaMetaPixelTestMode, 'Test event code:', window.maukaMetaPixelTestEventCode);
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');

fbq('init', '<?php echo esc_js($pixel_id); ?>'<?php if ($fbc): ?>, {
    fbc: '<?php echo esc_js($fbc); ?>'
}<?php endif; ?>);

<?php 
// Configure test event code globally if test mode is enabled
$test_mode = $plugin->get_option('test_mode');
$test_event_code = $plugin->get_option('test_event_code');

if ($test_mode && !empty($test_event_code)):
    Mauka_Meta_Pixel_Helpers::log('Setting up test event code: ' . $test_event_code);
?>
// Global test event code configuration
fbq.dataProcessingOptions = [];
fbq('set', 'autoConfig', false, '<?php echo esc_js($pixel_id); ?>');
fbq('set', 'experiments', {testEventCode: '<?php echo esc_js($test_event_code); ?>'});
console.log('Mauka Meta Pixel: Test event code configured globally: <?php echo esc_js($test_event_code); ?>');
<?php endif; ?>

<?php if (Mauka_Meta_Pixel_Helpers::is_event_enabled('PageView')): ?>
fbq('track', 'PageView');
<?php endif; ?>

<?php 
// Debug logging for InitiateCheckout detection
Mauka_Meta_Pixel_Helpers::log("Server-side InitiateCheckout check - is_checkout: " . ($is_checkout ? 'true' : 'false') . ", event_enabled: " . (Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout') ? 'true' : 'false'), 'debug');

// Add console logging for debugging
?>
console.log('Mauka Meta Pixel: Server-side InitiateCheckout check - is_checkout: <?php echo $is_checkout ? "true" : "false"; ?>, event_enabled: <?php echo Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout') ? "true" : "false"; ?>');
<?php

if ($is_checkout && Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout')): 
    $event_id = 'initiate_checkout_server_' . time();
    Mauka_Meta_Pixel_Helpers::log('InitiateCheckout event firing from server-side detection with event_id: ' . $event_id);
    
    // Get test event configuration
    $test_mode = $plugin->get_option('test_mode');
    $test_event_code = $plugin->get_option('test_event_code');
    Mauka_Meta_Pixel_Helpers::log("Test mode: " . ($test_mode ? 'enabled' : 'disabled') . ", test event code: " . $test_event_code, 'debug');
    
    // Get cart data for InitiateCheckout event
    $cart_data = array();
    $cart_total = 0;
    $cart_quantity = 0;
    $currency = '';
    
    if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
        $currency = get_woocommerce_currency();
        $cart_total = (float) WC()->cart->get_total('edit');
        $cart_quantity = WC()->cart->get_cart_contents_count();
        
        $cart_items = array();
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if ($product) {
                $cart_items[] = array(
                    'id' => $product->get_id(),
                    'quantity' => $cart_item['quantity'],
                    'item_price' => (float) $product->get_price()
                );
            }
        }
        
        $cart_data = array(
            'content_type' => 'product',
            'currency' => $currency,
            'value' => $cart_total,
            'num_items' => $cart_quantity,
            'contents' => $cart_items,
            'event_id' => $event_id
        );
        
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout cart data: " . json_encode($cart_data), 'debug');
    } else {
        // Fallback with basic data
        $cart_data = array(
            'content_type' => 'product',
            'event_id' => $event_id
        );
        Mauka_Meta_Pixel_Helpers::log("InitiateCheckout using fallback data (empty cart)", 'debug');
    }
?>
// Fire InitiateCheckout event directly on checkout page
fbq('track', 'InitiateCheckout', <?php echo json_encode($cart_data); ?>);
console.log('Mauka Meta Pixel: InitiateCheckout event fired with data:', <?php echo json_encode($cart_data); ?>);
<?php endif; ?>

console.log('Mauka Meta Pixel: Base pixel initialized with ID: <?php echo esc_js($pixel_id); ?>');
</script>

<!-- Client-side event tracking -->
<script>
// Standalone debug console log to verify script execution
console.log('Mauka Meta Pixel: Event tracking script loaded');

// Direct InitiateCheckout detection - simplified approach
window.addEventListener('DOMContentLoaded', function() {
    console.log('Mauka Meta Pixel: DOM loaded, checking URL');
    
    // Check if we're on the checkout page
    if (window.location.href.indexOf('/checkout') > -1) {
        console.log('Mauka Meta Pixel: Checkout URL detected, will fire InitiateCheckout');
        
        // Fire immediately if fbq is available
        if (typeof fbq !== 'undefined') {
            var eventId = 'initiate_checkout_' + Date.now();
            
            // Fire event (test event code is already configured globally if needed)
            fbq('track', 'InitiateCheckout', {
                content_type: 'product',
                event_id: eventId
            });
            console.log('Mauka Meta Pixel: InitiateCheckout fired with ID:', eventId);
        } else {
            // Try again in 1 second
            console.log('Mauka Meta Pixel: fbq not available, will retry in 1 second');
            setTimeout(function() {
                if (typeof fbq !== 'undefined') {
                    var eventId = 'initiate_checkout_retry_' + Date.now();
                    
                    // Fire event (test event code is already configured globally if needed)
                    fbq('track', 'InitiateCheckout', {
                        content_type: 'product',
                        event_id: eventId
                    });
                    console.log('Mauka Meta Pixel: InitiateCheckout fired on retry with ID:', eventId);
                }
            }, 1000);
        }
    }
});

// Also add a direct script to fire InitiateCheckout if we're on the checkout page
if (window.location.href.indexOf('/checkout') > -1) {
    console.log('Mauka Meta Pixel: Checkout URL detected on initial load');
    setTimeout(function() {
        if (typeof fbq !== 'undefined') {
            var eventId = 'initiate_checkout_direct_' + Date.now();
            fbq('track', 'InitiateCheckout', {
                content_type: 'product',
                event_id: eventId
            });
            console.log('Mauka Meta Pixel: InitiateCheckout fired directly with ID:', eventId);
        }
    }, 500);
}

    
    // Purchase event detection
    var thankYouPatterns = [
        '/order-received/', '/thank-you/', '/megrendeles-fogadva/', '/koszonjuk/',
        'order-received', 'thank-you', 'megrendeles-fogadva', 'koszonjuk'
    ];
    
    var isThankYouPage = false;
    
    // Check URL for thank you patterns
    for (var i = 0; i < thankYouPatterns.length; i++) {
        if (window.location.href.indexOf(thankYouPatterns[i]) !== -1) {
            isThankYouPage = true;
            break;
        }
    }
    
    // Check page content for thank you messages
    if (!isThankYouPage) {
        var pageContent = document.body.innerText;
        var thankYouMessages = [
            'Thank you for your order', 'Order received', 'Köszönjük a rendelésed',
            'Megrendelés fogadva', 'Sikeres rendelés', 'Order complete'
        ];
        
        for (var j = 0; j < thankYouMessages.length; j++) {
            if (pageContent.indexOf(thankYouMessages[j]) !== -1) {
                isThankYouPage = true;
                break;
            }
        }
    }
    
    if (isThankYouPage) {
        console.log('Mauka Meta Pixel: Detected thank you page, firing Purchase event');
        
        // Try to get order ID from URL
        var orderId = null;
        var orderIdMatch = window.location.href.match(/order-received\/(\d+)/);
        if (orderIdMatch && orderIdMatch[1]) {
            orderId = orderIdMatch[1];
        } else {
            // Try to find order ID in query parameters
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('order')) {
                orderId = urlParams.get('order');
            } else if (urlParams.has('order_id')) {
                orderId = urlParams.get('order_id');
            }
        }
        
        console.log('Mauka Meta Pixel: Order ID detected: ' + (orderId || 'not found'));
        
        // Check if we've already tracked this purchase
        var purchaseTracked = false;
        if (orderId) {
            var trackedOrders = localStorage.getItem('mauka_tracked_purchases');
            if (trackedOrders) {
                trackedOrders = JSON.parse(trackedOrders);
                purchaseTracked = trackedOrders.indexOf(orderId) !== -1;
            } else {
                trackedOrders = [];
            }
            
            if (!purchaseTracked) {
                trackedOrders.push(orderId);
                localStorage.setItem('mauka_tracked_purchases', JSON.stringify(trackedOrders));
            }
        }
        
        if (!purchaseTracked) {
            if (typeof fbq !== 'undefined') {
                var eventId = orderId ? 'purchase_' + orderId : 'purchase_' + Date.now();
                fbq('track', 'Purchase', {
                    content_type: 'product',
                    event_id: eventId
                });
                console.log('Mauka Meta Pixel: Purchase event fired with ID: ' + eventId);
            } else {
                console.log('Mauka Meta Pixel: fbq not defined, cannot fire Purchase');
            }
        } else {
            console.log('Mauka Meta Pixel: Purchase already tracked for order ' + orderId);
        }
    }
});
</script>
<!-- End Meta Pixel Code -->

<?php
// Add server-side checkout detection for WooCommerce
if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && Mauka_Meta_Pixel_Helpers::is_event_enabled('InitiateCheckout')):
    // Get cart data
    $cart_items = array();
    $cart_total = 0;
    $cart_count = 0;

    if (function_exists('WC') && isset(WC()->cart)) {
        $cart = WC()->cart;
        if ($cart) {
            $cart_total = (float) $cart->get_cart_contents_total();
            $cart_count = (int) $cart->get_cart_contents_count();
            
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if ($product) {
                    $cart_items[] = array(
                        'id' => (string) $product->get_id(),
                        'name' => $product->get_name(),
                        'quantity' => $cart_item['quantity'],
                        'price' => (float) $product->get_price()
                    );
                }
            }
        }
    }
?>
<script>
// Server-side detected checkout event
console.log('Mauka Meta Pixel: Server-side checkout detection, firing InitiateCheckout');
fbq('track', 'InitiateCheckout', {
    content_ids: <?php echo json_encode(array_map(function($item) { return $item['id']; }, $cart_items)); ?>,
    content_type: 'product',
    value: <?php echo $cart_total; ?>,
    currency: '<?php echo get_woocommerce_currency(); ?>',
    num_items: <?php echo $cart_count; ?>,
    contents: <?php echo json_encode($cart_items); ?>,
    event_id: 'initiate_checkout_server_' + Date.now()
});
</script>
<?php endif; ?>

<?php 
// Server-side purchase detection for WooCommerce
$is_thank_you_page = false;

// Check if this is a thank you page using multiple methods
if (function_exists('is_order_received_page') && is_order_received_page()) {
    $is_thank_you_page = true;
}

// Check URL for common thank you page indicators
$current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
if (strpos($current_url, 'order-received') !== false || 
    strpos($current_url, 'thank-you') !== false || 
    strpos($current_url, 'checkout/complete') !== false ||
    strpos($current_url, 'order_id') !== false) {
    $is_thank_you_page = true;
}

if ($is_thank_you_page && Mauka_Meta_Pixel_Helpers::is_event_enabled('Purchase')): 
    // Get order ID from URL
    $order_id = 0;

    // Try to get order ID from URL
    if (isset($_GET['order'])) {
        $order_id = absint($_GET['order']);
    } elseif (isset($_GET['order_id'])) {
        $order_id = absint($_GET['order_id']);
    } else {
        global $wp;
        if (isset($wp->query_vars['order-received'])) {
            $order_id = absint($wp->query_vars['order-received']);
        }
    }

    // Only proceed if we have an order ID and it hasn't been tracked yet
    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        
        if ($order && !$order->get_meta('_mauka_pixel_tracked', true)) {
            // Only track purchases for valid order statuses
            $valid_statuses = array('processing', 'completed', 'on-hold');
            $order_status = $order->get_status();
            if (!in_array($order_status, $valid_statuses)) {
                Mauka_Meta_Pixel_Helpers::log("Order {$order_id} has status '{$order_status}', not tracking Purchase event from JS", 'debug');
                return;
            }
            
            // Mark as tracked (using same meta key as PHP tracking system)
            $order->update_meta_data('_mauka_pixel_tracked', true);
            $order->save();
            
            // Get order data
            $order_total = (float) $order->get_total();
            $currency = $order->get_currency();
            
            // Get items
            $items = array();
            $content_ids = array();
            
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $content_ids[] = (string) $product_id;
                
                $items[] = array(
                    'id' => (string) $product_id,
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price' => (float) ($item->get_total() / $item->get_quantity())
                );
            }
?>
<script>
// Server-side detected purchase event
console.log('Mauka Meta Pixel: Server-side purchase detection, firing Purchase for order <?php echo $order_id; ?>');
fbq('track', 'Purchase', {
    content_ids: <?php echo json_encode($content_ids); ?>,
    content_type: 'product',
    value: <?php echo $order_total; ?>,
    currency: '<?php echo $currency; ?>',
    contents: <?php echo json_encode($items); ?>,
    num_items: <?php echo count($items); ?>,
    event_id: 'purchase_<?php echo $order_id; ?>_server_' + Date.now()
});
</script>
<?php
        }
    }
endif; 
?>

<!-- --- BEGIN: User Data Hashing for Meta Pixel --- -->
(function() {
    // Minimal SHA256 implementation (c) Chris Veness, MIT License
    function sha256(ascii) {
        function rightRotate(v, a) { return (v>>>a) | (v<<(32-a)); }
        var mathPow = Math.pow, maxWord = Math.pow(2, 32), result = '', words = [], asciiBitLength = ascii.length*8;
        var hash = sha256.h = sha256.h || [], k = sha256.k = sha256.k || [], primeCounter = k.length;
        var isComposite = {};
        for (var candidate = 2; primeCounter < 64; candidate++) {
            if (!isComposite[candidate]) {
                for (i = 0; i < 313; i += candidate) isComposite[i] = candidate;
                hash[primeCounter] = (mathPow(candidate, .5)*maxWord)|0;
                k[primeCounter++] = (mathPow(candidate, 1/3)*maxWord)|0;
            }
        }
        ascii += '\x80';
        while (ascii.length%64 - 56) ascii += '\x00';
        for (i = 0; i < ascii.length; i++) {
            j = ascii.charCodeAt(i);
            if (j>>8) return; // ASCII only
            words[i>>2] |= j << ((3-i)%4)*8;
        }
        words[words.length] = ((asciiBitLength/Math.pow(2, 32))|0);
        words[words.length] = (asciiBitLength|0);
        for (j = 0; j < words.length;) {
            var w = words.slice(j, j += 16), oldHash = hash.slice(0), i;
            for (i = 16; i < 64; i++) w[i] = (rightRotate(w[i-2],17)^rightRotate(w[i-2],19)^(w[i-2]>>>10))+w[i-7]+(rightRotate(w[i-15],7)^rightRotate(w[i-15],18)^(w[i-15]>>>3))+w[i-16]|0;
            var a = hash[0], b = hash[1], c = hash[2], d = hash[3], e = hash[4], f = hash[5], g = hash[6], h = hash[7];
            for (i = 0; i < 64; i++) {
                var t1 = h + (rightRotate(e,6)^rightRotate(e,11)^rightRotate(e,25)) + ((e&f)^((~e)&g)) + k[i] + w[i];
                var t2 = (rightRotate(a,2)^rightRotate(a,13)^rightRotate(a,22)) + ((a&b)^(a&c)^(b&c));
                h = g; g = f; f = e; e = d + t1|0; d = c; c = b; b = a; a = t1 + t2|0;
            }
            hash[0] = hash[0]+a|0; hash[1] = hash[1]+b|0; hash[2] = hash[2]+c|0; hash[3] = hash[3]+d|0;
            hash[4] = hash[4]+e|0; hash[5] = hash[5]+f|0; hash[6] = hash[6]+g|0; hash[7] = hash[7]+h|0;
        }
        for (i = 0; i < hash.length; i++) {
            for (j = 3; j + 1; j--) {
                var b = (hash[i]>>(j*8))&255;
                result += ((b>>4).toString(16)) + ((b&15).toString(16));
            }
        }
        return result;
    }
    function getField(selector) {
        var el = document.querySelector(selector);
        return el ? el.value.trim().toLowerCase() : '';
    }
    function hashIf(val) { return val ? sha256(val) : undefined; }
    // Try to get user data from WooCommerce checkout fields
    var userData = {};
    userData.em = hashIf(getField('#billing_email'));
    userData.ph = hashIf(getField('#billing_phone').replace(/[^0-9]/g, ''));
    userData.fn = hashIf(getField('#billing_first_name'));
    userData.ln = hashIf(getField('#billing_last_name'));
    userData.ct = hashIf(getField('#billing_city'));
    userData.st = hashIf(getField('#billing_state'));
    userData.zp = hashIf(getField('#billing_postcode'));
    userData.country = hashIf(getField('#billing_country'));
    // Remove empty fields
    Object.keys(userData).forEach(function(k){ if(!userData[k]) delete userData[k]; });
    // If we have any user data, re-init fbq with it
    if(Object.keys(userData).length > 0 && typeof fbq !== 'undefined') {
        fbq('init', window.maukaMetaPixelId, userData);
        console.log('Mauka Meta Pixel: fbq re-initialized with user data', userData);
    }
})();
<!-- --- END: User Data Hashing for Meta Pixel --- -->
