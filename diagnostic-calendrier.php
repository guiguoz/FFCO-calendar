<?php
/**
 * Diagnostic configuration calendrier
 */

require_once('/kunden/homepages/11/d468677337/htdocs/clickandbuilds/Vikazim604088/wp-config.php');

echo "<h1>Diagnostic Configuration Calendrier</h1>";

// 1. Vérifier la page calendrier
echo "<h2>1. Page /calendrier/</h2>";
$calendar_page = get_page_by_path('calendrier');
if ($calendar_page) {
    echo "✅ Page trouvée ID: {$calendar_page->ID}<br>";
    echo "Titre: {$calendar_page->post_title}<br>";
    echo "Slug: {$calendar_page->post_name}<br>";
    echo "Template: " . get_page_template_slug($calendar_page->ID) . "<br>";
    echo "Contenu: " . htmlspecialchars($calendar_page->post_content) . "<br>";
} else {
    echo "❌ Page /calendrier/ non trouvée<br>";
}

// 2. Configuration The Events Calendar
echo "<h2>2. Configuration The Events Calendar</h2>";
$main_events_page = tribe_get_events_link();
echo "URL principale événements: $main_events_page<br>";

$events_slug = get_option('tribe_events_calendar_slug', 'events');
echo "Slug événements: $events_slug<br>";

// 3. Test si la page calendrier utilise un shortcode
echo "<h2>3. Solution: Ajouter shortcode</h2>";
if ($calendar_page) {
    $content = $calendar_page->post_content;
    if (strpos($content, '[tribe_events') === false) {
        echo "❌ Aucun shortcode The Events Calendar détecté<br>";
        echo "<strong>Solution:</strong> Ajoutez ce shortcode dans la page calendrier:<br>";
        echo "<code>[tribe_events view=\"month\"]</code><br><br>";
        
        // Proposer de l'ajouter automatiquement
        if (!empty($_GET['fix'])) {
            $new_content = $content . "\n\n[tribe_events view=\"month\"]";
            wp_update_post([
                'ID' => $calendar_page->ID,
                'post_content' => $new_content
            ]);
            echo "✅ Shortcode ajouté automatiquement !<br>";
            echo "🔗 <a href='https://vikazim.fr/calendrier/' target='_blank'>Vérifiez maintenant</a><br>";
        } else {
            echo "🔧 <a href='?fix=1'>Cliquez ici pour ajouter automatiquement</a><br>";
        }
    } else {
        echo "✅ Shortcode The Events Calendar détecté<br>";
    }
}

// 4. Vérifier les événements futurs
echo "<h2>4. Événements futurs</h2>";
global $wpdb;
$future_events = $wpdb->get_results("
    SELECT p.ID, p.post_title, pm.meta_value as start_date
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_EventStartDate'
    WHERE p.post_type = 'tribe_events' 
    AND p.post_status = 'publish'
    AND pm.meta_value >= CURDATE()
    ORDER BY pm.meta_value ASC
    LIMIT 5
");

echo "Événements futurs: " . count($future_events) . "<br>";
foreach ($future_events as $event) {
    echo "- {$event->post_title} | {$event->start_date}<br>";
}

echo "<h2>Diagnostic terminé</h2>";
?>