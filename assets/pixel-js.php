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

?>
<!-- Meta Pixel Code -->
<script>
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

<?php if (Mauka_Meta_Pixel_Helpers::is_event_enabled('PageView')): ?>
fbq('track', 'PageView');
<?php endif; ?>
</script>
<!-- End Meta Pixel Code -->