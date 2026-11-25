<?php
/**
 * Plugin Name: Calendrier FFC Ligue Normandie
 * Description: Calendrier des compétitions FFCOrientation pour la Ligue Normandie — sync mensuelle depuis cn.ffcorientation.fr (Courses CN). Shortcode : [ffc_calendar_ligue]
 * Version:     2.1
 * Author:      Ligue Normandie CO
 */

if (!defined('ABSPATH')) exit;

define('FFC_CALENDAR_VERSION',    '2.1');
define('FFC_CALENDAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FFC_CRON_HOOK',           'ffc_sync_cron');

require_once FFC_CALENDAR_PLUGIN_DIR . 'includes/class-ffc-db.php';
require_once FFC_CALENDAR_PLUGIN_DIR . 'includes/class-ffc-sync.php';
require_once FFC_CALENDAR_PLUGIN_DIR . 'includes/class-ffc-display.php';
require_once FFC_CALENDAR_PLUGIN_DIR . 'includes/class-ffc-admin.php';

// ---------------------------------------------------------------------------
// Returns the Unix timestamp for the 1st of next month at 03:00 (site TZ)
// ---------------------------------------------------------------------------
function ffc_next_first_of_month(): int {
    $tz = new DateTimeZone(wp_timezone_string());
    $dt = new DateTime('first day of next month 03:00:00', $tz);
    return $dt->getTimestamp();
}

// ---------------------------------------------------------------------------
// Activation : create table + schedule first sync on 1st of next month
// ---------------------------------------------------------------------------
register_activation_hook(__FILE__, function (): void {
    FFCCalendarDB::create_table();

    if (!wp_next_scheduled(FFC_CRON_HOOK)) {
        wp_schedule_single_event(ffc_next_first_of_month(), FFC_CRON_HOOK);
    }
});

// ---------------------------------------------------------------------------
// Deactivation : remove scheduled cron
// ---------------------------------------------------------------------------
register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook(FFC_CRON_HOOK);
});

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action('plugins_loaded', function (): void {

    // WP-Cron callback — reschedule for 1st of next month after each run
    add_action(FFC_CRON_HOOK, function (): void {
        (new FFCCalendarSync())->sync();
        wp_schedule_single_event(ffc_next_first_of_month(), FFC_CRON_HOOK);
    });

    // Shortcode + frontend styles
    $display = new FFCCalendarDisplay();
    add_shortcode('ffc_calendar_ligue', [$display, 'calendar_shortcode']);
    add_action('wp_enqueue_scripts', [$display, 'register_styles']);

    // Admin page + AJAX handlers
    if (is_admin()) {
        (new FFCCalendarAdmin())->register();
    }
});
