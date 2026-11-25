<?php
/**
 * Script de nettoyage des doublons d'événements FFC
 */

// Chargement WordPress
if (!function_exists('wp_insert_post')) {
    $wp_config_paths = [
        $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-config.php',
        __DIR__ . '/../wp-config.php',
        __DIR__ . '/../../wp-config.php',
        __DIR__ . '/../../../wp-config.php'
    ];

    foreach ($wp_config_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            break;
        }
    }
}

echo "<h1>Nettoyage des doublons d'événements FFC</h1>";

// Récupérer tous les événements FFC
global $wpdb;
$events = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_date, pm.meta_value as ffc_url
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE p.post_type = 'tribe_events'
    AND p.post_status = 'publish'
    AND pm.meta_key = 'ffc_source_url'
    ORDER BY p.post_date DESC
");

echo "Événements FFC trouvés: " . count($events) . "<br><br>";

// Grouper par titre + date
$groups = [];
foreach ($events as $event) {
    // Extraire la date de début
    $start_date = get_post_meta($event->ID, '_EventStartDate', true);
    $event_date = $start_date ? date('Y-m-d', strtotime($start_date)) : 'unknown';
    
    // Créer une clé unique basée sur le titre et la date
    $key = md5($event->post_title . $event_date);
    
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    
    $groups[$key][] = [
        'id' => $event->ID,
        'title' => $event->post_title,
        'date' => $event_date,
        'post_date' => $event->post_date
    ];
}

// Identifier et supprimer les doublons
$deleted = 0;
$kept = 0;

foreach ($groups as $key => $group) {
    if (count($group) > 1) {
        echo "<strong>Doublon détecté:</strong> " . $group[0]['title'] . " (" . $group[0]['date'] . ")<br>";
        
        // Trier par date de création (garder le plus récent)
        usort($group, function($a, $b) {
            return strtotime($b['post_date']) - strtotime($a['post_date']);
        });
        
        // Garder le premier (plus récent), supprimer les autres
        $keep = array_shift($group);
        echo "  → Garder: ID {$keep['id']} (créé le {$keep['post_date']})<br>";
        $kept++;
        
        foreach ($group as $duplicate) {
            if (!empty($_GET['delete'])) {
                wp_delete_post($duplicate['id'], true);
                echo "  → ✅ Supprimé: ID {$duplicate['id']} (créé le {$duplicate['post_date']})<br>";
                $deleted++;
            } else {
                echo "  → ⚠️ À supprimer: ID {$duplicate['id']} (créé le {$duplicate['post_date']})<br>";
            }
        }
        echo "<br>";
    }
}

echo "<h2>Résumé</h2>";
echo "Événements uniques conservés: $kept<br>";

if (!empty($_GET['delete'])) {
    echo "Doublons supprimés: $deleted<br>";
    echo "✅ <strong>Nettoyage terminé !</strong><br>";
} else {
    echo "Doublons détectés: " . array_sum(array_map(function($g) { return max(0, count($g) - 1); }, $groups)) . "<br>";
    echo "⚠️ <strong>Mode simulation</strong> - Aucune suppression effectuée<br>";
    echo "🔧 <a href='?delete=1' style='background:#dc3545;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>SUPPRIMER LES DOUBLONS</a><br>";
}

echo "<br><a href='?' style='background:#007cba;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Actualiser</a>";
?>