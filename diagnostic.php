<?php
/**
 * Diagnostic The Events Calendar
 */

// Affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnostic The Events Calendar</h1>";

// 1. Test chargement WordPress
echo "<h2>1. WordPress</h2>";
if (!function_exists('wp_insert_post')) {
    $wp_paths = [
        $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-config.php',
        __DIR__ . '/../wp-config.php',
        __DIR__ . '/../../wp-config.php',
        __DIR__ . '/../../../wp-config.php'
    ];
    
    $wp_loaded = false;
    foreach ($wp_paths as $path) {
        if (file_exists($path)) {
            echo "Tentative: $path<br>";
            try {
                require_once($path);
                $wp_loaded = true;
                echo "✅ WordPress chargé depuis: $path<br>";
                break;
            } catch (Exception $e) {
                echo "❌ Erreur: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    if (!$wp_loaded) {
        echo "❌ WordPress non trouvé<br>";
        exit;
    }
} else {
    echo "✅ WordPress déjà chargé<br>";
}

// 2. Test The Events Calendar
echo "<h2>2. The Events Calendar</h2>";
if (class_exists('Tribe__Events__Main')) {
    echo "✅ The Events Calendar détecté<br>";
    echo "Version: " . (defined('Tribe__Events__Main::VERSION') ? Tribe__Events__Main::VERSION : 'inconnue') . "<br>";
} else {
    echo "❌ The Events Calendar non trouvé<br>";
    echo "Plugins actifs:<br>";
    if (function_exists('get_option')) {
        $active_plugins = get_option('active_plugins', []);
        foreach ($active_plugins as $plugin) {
            echo "- $plugin<br>";
        }
    }
    exit;
}

// 3. Test création événement simple
echo "<h2>3. Test création événement</h2>";
try {
    $test_event = [
        'post_title' => 'Test Event - ' . date('Y-m-d H:i:s'),
        'post_content' => 'Test de création d\'événement',
        'post_status' => 'publish',
        'post_type' => 'tribe_events'
    ];
    
    $post_id = wp_insert_post($test_event);
    
    if (is_wp_error($post_id)) {
        echo "❌ Erreur wp_insert_post: " . $post_id->get_error_message() . "<br>";
    } elseif ($post_id > 0) {
        echo "✅ Événement créé avec ID: $post_id<br>";
        
        // Test métadonnées
        $start_date = date('Y-m-d H:i:s');
        update_post_meta($post_id, '_EventStartDate', $start_date);
        echo "✅ Métadonnée _EventStartDate ajoutée<br>";
        
        // Supprimer l'événement test
        wp_delete_post($post_id, true);
        echo "✅ Événement test supprimé<br>";
    } else {
        echo "❌ wp_insert_post a retourné: $post_id<br>";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}

// 4. Test permissions
echo "<h2>4. Permissions</h2>";
if (function_exists('current_user_can')) {
    echo "Utilisateur actuel: " . (is_user_logged_in() ? wp_get_current_user()->user_login : 'non connecté') . "<br>";
    echo "Peut publier: " . (current_user_can('publish_posts') ? 'Oui' : 'Non') . "<br>";
    echo "Peut créer événements: " . (current_user_can('edit_tribe_events') ? 'Oui' : 'Non') . "<br>";
}

echo "<h2>Diagnostic terminé</h2>";
?>