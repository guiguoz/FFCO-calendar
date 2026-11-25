<?php
/**
 * Script de nettoyage des doublons - Vikazim
 */

// Charger WordPress
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

function findDuplicateEvents() {
    $events = get_posts([
        'post_type' => 'tribe_events',
        'posts_per_page' => -1,
        'meta_key' => 'ffc_source_url'
    ]);
    
    $duplicates = [];
    $seen = [];
    
    foreach ($events as $event) {
        $title = $event->post_title;
        $date = get_post_meta($event->ID, '_EventStartDate', true);
        $key = md5($title . $date);
        
        if (isset($seen[$key])) {
            $duplicates[] = [
                'original' => $seen[$key],
                'duplicate' => $event
            ];
        } else {
            $seen[$key] = $event;
        }
    }
    
    return $duplicates;
}

function cleanupDuplicates($simulate = true) {
    $duplicates = findDuplicateEvents();
    $deleted = 0;
    
    echo "<h2>🧹 Nettoyage des doublons Vikazim</h2>";
    echo "<p>Mode: " . ($simulate ? "SIMULATION" : "SUPPRESSION RÉELLE") . "</p>";
    echo "<p>Doublons trouvés: " . count($duplicates) . "</p>";
    
    foreach ($duplicates as $dup) {
        $original = $dup['original'];
        $duplicate = $dup['duplicate'];
        
        echo "<div style='border:1px solid #ccc; margin:10px; padding:10px;'>";
        echo "<strong>Doublon détecté:</strong><br>";
        echo "Original: #{$original->ID} - {$original->post_title}<br>";
        echo "Doublon: #{$duplicate->ID} - {$duplicate->post_title}<br>";
        
        if (!$simulate) {
            wp_delete_post($duplicate->ID, true);
            echo "<span style='color:red'>✅ Supprimé</span>";
            $deleted++;
        } else {
            echo "<span style='color:orange'>🔍 À supprimer</span>";
        }
        echo "</div>";
    }
    
    echo "<p><strong>Total " . ($simulate ? "à supprimer" : "supprimé") . ": " . ($simulate ? count($duplicates) : $deleted) . "</strong></p>";
    
    if ($simulate && count($duplicates) > 0) {
        echo "<p><a href='?cleanup=real' style='background:red;color:white;padding:10px;text-decoration:none;'>🗑️ SUPPRIMER RÉELLEMENT</a></p>";
    }
}

// Interface web
if (!empty($_GET['cleanup'])) {
    $simulate = $_GET['cleanup'] !== 'real';
    cleanupDuplicates($simulate);
} else {
    echo "<h1>🧹 Nettoyage des doublons - Vikazim</h1>";
    echo "<p><a href='?cleanup=simulate' style='background:blue;color:white;padding:10px;text-decoration:none;'>🔍 SIMULER</a></p>";
}
?>