<?php
/**
 * Cron automatique pour synchronisation FFC
 * À ajouter dans le plugin ffc-calendar-plugin.php
 */

// Ajouter ces lignes dans la classe FFCCalendarPlugin :

public function __construct() {
    add_action('init', [$this, 'init']);
    add_shortcode('ffc_calendar', [$this, 'calendar_shortcode']);
    
    // Programmer le cron
    add_action('wp', [$this, 'schedule_sync']);
    add_action('ffc_sync_events', [$this, 'run_sync']);
}

public function schedule_sync() {
    if (!wp_next_scheduled('ffc_sync_events')) {
        // Synchronisation quotidienne à 6h du matin
        wp_schedule_event(strtotime('06:00:00'), 'daily', 'ffc_sync_events');
    }
}

public function run_sync() {
    // Inclure le script de sync
    require_once(__DIR__ . '/sync-wpquery.php');
    
    try {
        $sync = new FFCEventsSync();
        $result = $sync->run();
        
        // Log du résultat
        error_log("FFC Sync: $result événements synchronisés - " . date('Y-m-d H:i:s'));
        
    } catch (Exception $e) {
        error_log("FFC Sync Error: " . $e->getMessage());
    }
}

// Désactiver le cron à la désactivation du plugin
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('ffc_sync_events');
});

/**
 * COMMANDES MANUELLES :
 * 
 * 1. Activer le cron :
 * wp_schedule_event(time(), 'daily', 'ffc_sync_events');
 * 
 * 2. Tester immédiatement :
 * do_action('ffc_sync_events');
 * 
 * 3. Voir les crons programmés :
 * wp cron event list
 * 
 * 4. Supprimer le cron :
 * wp_clear_scheduled_hook('ffc_sync_events');
 */
?>