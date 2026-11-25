<?php
/**
 * Diagnostic affichage événements
 */

// Chargement WordPress
require_once('/kunden/homepages/11/d468677337/htdocs/clickandbuilds/Vikazim604088/wp-config.php');

echo "<h1>Diagnostic Affichage Événements</h1>";

// 1. Vérifier les événements créés
echo "<h2>1. Événements dans la base</h2>";

// D'abord tous les événements avec tous les statuts
$all_events = get_posts([
    'post_type' => 'tribe_events',
    'posts_per_page' => 50,
    'post_status' => ['publish', 'draft', 'private', 'pending']
]);

echo "Tous les événements (tous statuts): " . count($all_events) . "<br>";
foreach ($all_events as $event) {
    $ffc_url = get_post_meta($event->ID, 'ffc_source_url', true);
    echo "- ID: {$event->ID} | {$event->post_title} | Status: {$event->post_status} | ffc_url: '$ffc_url'<br>";
}

// Test direct par ID
echo "<br><strong>Test direct ID 26796:</strong><br>";
$direct_post = get_post(26796);
if ($direct_post) {
    echo "✅ Post 26796 trouvé: {$direct_post->post_title} | Type: {$direct_post->post_type} | Status: {$direct_post->post_status}<br>";
} else {
    echo "❌ Post 26796 non trouvé<br>";
}

// Puis ceux avec métadonnée
$events = get_posts([
    'post_type' => 'tribe_events',
    'posts_per_page' => 10,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => 'ffc_source_url',
            'compare' => 'EXISTS'
        ]
    ]
]);

echo "<br>Événements FFCOrientation trouvés: " . count($events) . "<br>";
foreach ($events as $event) {
    echo "- ID: {$event->ID} | Titre: {$event->post_title} | Status: {$event->post_status}<br>";
    $start_date = get_post_meta($event->ID, '_EventStartDate', true);
    echo "  Date: $start_date<br>";
    $venue_id = get_post_meta($event->ID, '_EventVenueID', true);
    echo "  Venue ID: $venue_id<br>";
}

// 2. Vérifier la configuration de la page calendrier
echo "<h2>2. Configuration page calendrier</h2>";
$calendar_page = get_page_by_path('calendrier');
if ($calendar_page) {
    echo "✅ Page calendrier trouvée (ID: {$calendar_page->ID})<br>";
    echo "Template: " . get_page_template_slug($calendar_page->ID) . "<br>";
    echo "Contenu: " . substr($calendar_page->post_content, 0, 200) . "...<br>";
} else {
    echo "❌ Page calendrier non trouvée<br>";
}

// 3. Vérifier les options The Events Calendar
echo "<h2>3. Options The Events Calendar</h2>";
$events_page = get_option('tribe_events_calendar_page');
echo "Page événements configurée: $events_page<br>";

$default_view = get_option('tribe_events_default_view');
echo "Vue par défaut: $default_view<br>";

// 4. Test requête événements futurs
echo "<h2>4. Test requête événements futurs</h2>";
$future_events = get_posts([
    'post_type' => 'tribe_events',
    'posts_per_page' => 5,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => 'ffc_source_url',
            'compare' => 'EXISTS'
        ]
    ]
]);

echo "Événements futurs FFCOrientation: " . count($future_events) . "<br>";
foreach ($future_events as $event) {
    $start_date = get_post_meta($event->ID, '_EventStartDate', true);
    echo "- {$event->post_title} | $start_date<br>";
}

echo "<h2>Diagnostic terminé</h2>";
?>