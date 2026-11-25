<?php
/**
 * Sync FFCOrientation → The Events Calendar (Ligue Normandie)
 *
 * Source : https://cn.ffcorientation.fr/course/ (Courses CN)
 * (l'ancien agenda https://www.ffcorientation.fr/courses/agenda/ ne sert
 * plus de tableau d'événements depuis la refonte du site fédéral).
 */

// Augmenter la limite de temps d'exécution (5 minutes)
@set_time_limit(0);
ignore_user_abort(true);

// Désactiver la limite de mémoire
ini_set('memory_limit', '512M');

// Désactiver la mise en tampon de sortie pour voir les progrès
if (php_sapi_name() !== 'cli') {
    ob_implicit_flush(true);
    @ob_end_flush();
    while (ob_get_level()) {
        @ob_end_flush();
    }
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<br><strong>❌ ERREUR FATALE:</strong> " . $error['message'] . " dans " . $error['file'] . " ligne " . $error['line'];
    }
});

class FFCEventsCalendarSyncLigueNormandie {
    public static $output_function = null;

    private $target_departments = ['27', '76', '14', '50', '61'];
    private $ffc_url = 'https://cn.ffcorientation.fr/course/';
    private $city_department_map = [];
    private $national_keyword = 'Championnat de France';
    // Groupes CN considérés comme niveau national (A = national, B1 = national,
    // B2 = ligue, D = départemental/régional).
    private $national_groups = ['A', 'B1'];
    private $national_keywords = ['Championnat de France', 'Critérium National', 'Coupe de France'];
    private $scan_window = ['du_offset_days' => 0, 'scan_months_ahead' => 3, 'max_pages' => 20];

    private function output($message) {
        if (self::$output_function && is_callable(self::$output_function)) {
            call_user_func(self::$output_function, $message);
        } else {
            echo $message . "\n";
        }
    }

    public function __construct() {
        $this->output("🔧 Initialisation (Ligue Normandie)...");
        $this->output("⏱️ Time Limit: " . ini_get('max_execution_time') . "s");

        if (!function_exists('wp_insert_post')) {
            $wp_load_paths = [
                __DIR__ . '/../../wp-load.php',
                $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
                dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-load.php',
                __DIR__ . '/../wp-load.php',
                __DIR__ . '/../../../wp-load.php'
            ];

            $wp_loaded = false;
            foreach ($wp_load_paths as $path) {
                if (file_exists($path)) {
                    $resolved_path = realpath($path);
                    $this->output("📁 Chargement WordPress depuis: $resolved_path");
                    try {
                        require_once($path);
                        $wp_loaded = true;
                        break;
                    } catch (Exception $e) {
                        $this->output("⚠️ Erreur chargement WP: " . $e->getMessage());
                    }
                }
            }

            if (!$wp_loaded) {
                $this->output("❌ ERREUR: Impossible de charger WordPress");
                throw new Exception("WordPress non trouvé");
            }
        }

        if (!class_exists('Tribe__Events__Main')) {
            $this->output("❌ ERREUR: The Events Calendar n'est pas installé ou activé");
            throw new Exception("The Events Calendar requis");
        }

        $this->output("✅ The Events Calendar détecté");
        $this->loadConfig();
    }

    private function loadConfig() {
        // Configuration par défaut pour la Ligue Normandie
        $this->city_department_map = [
            // Seine-Maritime (76)
            'rouen' => '76', 'le havre' => '76', 'dieppe' => '76', 'fecamp' => '76',
            'elbeuf' => '76', 'sotteville' => '76', 'mont-saint-aignan' => '76',
            // Calvados (14)
            'caen' => '14', 'bayeux' => '14', 'lisieux' => '14', 'vire' => '14',
            'falaise' => '14', 'honfleur' => '14', 'cabourg' => '14',
            // Manche (50)
            'cherbourg' => '50', 'saint-lo' => '50', 'avranches' => '50', 'coutances' => '50',
            'granville' => '50', 'valognes' => '50',
            // Eure (27)
            'evreux' => '27', 'vernon' => '27', 'louviers' => '27', 'pont-audemer' => '27',
            'bernay' => '27', 'gisors' => '27',
            // Orne (61)
            'alencon' => '61', 'flers' => '61', 'argentan' => '61', 'mortagne-au-perche' => '61',
            'la ferte-mace' => '61', 'sees' => '61'
        ];
    }

    /**
     * Récupère la liste des courses CN (une seule page, pas de pagination),
     * puis applique la fenêtre temporelle (mois courant + 2 suivants).
     */
    private function fetchFFCEvents() {
        $this->output("🌐 Début récupération événements FFCOrientation (CN)...");
        $url = $this->ffc_url;

        $this->output("📥 Téléchargement de la liste des courses...");
        $this->output("🔗 URL: $url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $html = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!$html) {
            $this->output("⚠️ Erreur de téléchargement: $curl_error");
            return [];
        }

        $this->output("📄 HTML téléchargé: " . strlen($html) . " caractères");

        $this->output("🔍 Analyse des événements...");
        $result = $this->parseEvents($html);
        $events = $result['events'];
        $total = $result['total'];

        // Fenêtre temporelle : mois courant + 2 mois suivants
        $start_window = new DateTime('first day of this month 00:00:00');
        $end_window = (new DateTime('first day of this month'))->modify('+2 months')->modify('last day of this month 23:59:59');
        $this->output("🗓️ Fenêtre: " . $start_window->format('Y-m-d') . " → " . $end_window->format('Y-m-d'));
        $events = array_values(array_filter($events, function($e) use ($start_window, $end_window) {
            try { $dt = new DateTime($e['date']); } catch (Exception $ex) { return false; }
            return $dt >= $start_window && $dt <= $end_window;
        }));

        $this->output("  ✅ " . count($events) . " événements retenus sur $total lignes");
        return $events;
    }

    /**
     * Parse le tableau Courses CN : Date | Course | Lieu | Organisateur |
     * Groupe | Spécialité | Épreuve | Résultats.
     */
    private function parseEvents($html) {
        $events = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $eventNodes = $xpath->query('//table//tr[position()>1]');

        if (!$eventNodes || $eventNodes->length === 0) {
            $this->output("  ⚠️ Aucune ligne trouvée dans le tableau (structure modifiée ?)");
            return ['events' => [], 'total' => 0];
        }

        $count = 0;
        $total_nodes = $eventNodes->length;

        foreach ($eventNodes as $node) {
            $count++;
            if ($count % 50 === 0) {
                $this->output("    ... analyse ligne $count / $total_nodes");
            }
            $event = $this->extractEventData($node, $xpath);
            if ($event && $event['title'] && $this->isEventAllowed($event)) {
                $events[] = $event;
            }
        }

        return ['events' => $events, 'total' => $eventNodes->length];
    }

    private function extractEventData($node, $xpath) {
        $cells = $xpath->query('.//td', $node);
        if ($cells->length < 4) {
            return null;
        }

        $cellText = function($idx) use ($cells) {
            if ($idx >= $cells->length) return '';
            return trim(html_entity_decode($cells->item($idx)->textContent, ENT_QUOTES, 'UTF-8'));
        };

        $date = $cellText(0);
        $title = $cellText(1);
        $location = $cellText(2);
        $organizer = $cellText(3);
        $groupe = $cellText(4);
        $specialite = $cellText(5);
        $epreuve = $cellText(6);

        if (!$date || !$title || !preg_match('/\d{2}\/\d{2}\/\d{2,4}/', $date)) {
            return null;
        }

        // Lien vers la fiche détail (colonne Course) — absent pour certaines courses
        $link = '';
        if ($cells->length > 1) {
            $a = $xpath->query('.//a', $cells->item(1))->item(0);
            if ($a) {
                $link = $a->getAttribute('href');
            }
        }
        if ($link && !str_starts_with($link, 'http')) {
            $link = 'https://cn.ffcorientation.fr' . $link;
        }

        $department = $this->extractDepartment($location);

        return [
            'title' => $title,
            'date' => $this->parseDate($date),
            'location' => $location,
            'department' => $department,
            'link' => $link,
            'organizer' => $organizer ?: 'FFCOrientation',
            'website' => '',
            'groupe' => $groupe,
            'specialite' => $specialite,
            'epreuve' => $epreuve,
            'is_national' => $this->isNational($title, $groupe),
            'description' => ''
        ];
    }

    private function extractDepartment($location) {
        if (preg_match('/\((\d{2})\)/', $location, $matches)) {
            return $matches[1];
        }
        foreach ($this->city_department_map as $city => $dept) {
            if (stripos($location, $city) !== false) {
                return $dept;
            }
        }
        return null;
    }

    private function isNational($title, $groupe) {
        if ($groupe && in_array(strtoupper(trim($groupe)), $this->national_groups, true)) {
            return true;
        }
        foreach ($this->national_keywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isEventAllowed($event) {
        if ($event['department'] && in_array($event['department'], $this->target_departments)) {
            return true;
        }
        if (!empty($event['is_national'])) {
            return true;
        }
        return false;
    }

    /**
     * Parse une date JJ/MM/AAAA (format CN) ou JJ/MM/AA (ancien format).
     */
    private function parseDate($dateStr) {
        $dateStr = trim($dateStr);
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $dateStr, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{2})/', $dateStr, $m)) {
            $day = $m[1]; $month = $m[2]; $year = '20' . $m[3];
            return "$year-$month-$day 00:00:00";
        }
        return date('Y-m-d H:i:s');
    }

    private function parseFrenchLongDate($text) {
        $months = [
            'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
            'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9,
            'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12
        ];
        if (preg_match('/(\d{1,2})\s+([a-zéèêëàâîïôöûüç]+)\s+(\d{4})/iu', $text, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = $months[mb_strtolower($m[2], 'UTF-8')] ?? null;
            $year = $m[3];
            if ($month) { return sprintf('%04d-%02d-%02d 00:00:00', (int)$year, (int)$month, (int)$day); }
        }
        return '';
    }

    public function syncToEventsCalendar($events) {
        $synced = 0; $already_exists = 0;

        foreach ($events as $event) {
            $result = $this->createEventsCalendarEvent($event);
            if ($result) { $synced++; } else { $already_exists++; }
        }

        $this->output("📊 Créés:$synced, existants:$already_exists");
        return $synced;
    }

    private function createEventsCalendarEvent($event) {
        $existing = $this->findExistingEvent($event);
        if ($existing) {
            $this->updateExistingEventMeta($existing->ID, $event);
            return false;
        }

        $event_date = date('Y-m-d', strtotime($event['date']));
        $event_start = $event_date . ' 10:00:00';
        $event_end = $event_date . ' 18:00:00';

        $post_id = wp_insert_post([
            'post_title' => $event['title'] . ' - ' . $event['location'],
            'post_content' => $this->buildEventDescription($event),
            'post_status' => 'publish',
            'post_type' => 'tribe_events',
            'post_author' => 1
        ]);

        if ($post_id > 0) {
            update_post_meta($post_id, '_EventStartDate', $event_start);
            update_post_meta($post_id, '_EventEndDate', $event_end);
            update_post_meta($post_id, '_EventAllDay', 'yes');

            $normalized = $this->normalizeUrl($event['link']);
            $event_url = !empty($event['website']) ? $event['website'] : $normalized;
            update_post_meta($post_id, '_EventURL', $event_url);

            if ($event['location']) {
                $venue_id = $this->getOrCreateVenue($event['location']);
                update_post_meta($post_id, '_EventVenueID', $venue_id);
            }
            if ($event['organizer']) {
                $organizer_id = $this->getOrCreateOrganizer($event['organizer']);
                update_post_meta($post_id, '_EventOrganizerID', $organizer_id);
            }

            $category_name = !empty($event['is_national'])
                ? 'Championnat National'
                : 'Événement Normandie';
            $category = get_term_by('name', $category_name, 'tribe_events_cat');
            if (!$category) {
                $new_cat = wp_insert_term($category_name, 'tribe_events_cat');
                if (!is_wp_error($new_cat)) {
                    $category = get_term($new_cat['term_id'], 'tribe_events_cat');
                }
            }
            if ($category) {
                wp_set_post_terms($post_id, [$category->term_id], 'tribe_events_cat');
            }

            update_post_meta($post_id, 'ffc_source_url', $normalized);
            update_post_meta($post_id, 'ffc_event_uid', md5((string)$normalized));
            update_post_meta($post_id, 'ffc_event_id', md5($event['title'] . $event['date']));
            update_post_meta($post_id, 'ffc_department', $event['department']);
            update_post_meta($post_id, 'ffc_organizer_name', $event['organizer']);
            update_post_meta($post_id, 'ffc_organizer_website', $event['website']);
            update_post_meta($post_id, 'ffc_groupe', $event['groupe'] ?? '');
            update_post_meta($post_id, 'ffc_specialite', $event['specialite'] ?? '');
            update_post_meta($post_id, 'ffc_epreuve', $event['epreuve'] ?? '');
            return true;
        }
        return false;
    }

    private function findExistingEvent($event) {
        $link = $event['link'] ?? '';
        $normalized = $this->normalizeUrl($link);

        if ($normalized) {
            $by_url = get_posts([
                'post_type' => 'tribe_events',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => 'ffc_source_url',
                    'value' => $normalized,
                    'compare' => '='
                ]]
            ]);
            if (!empty($by_url)) return $by_url[0];

            $by_uid = get_posts([
                'post_type' => 'tribe_events',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => 'ffc_event_uid',
                    'value' => md5($normalized),
                    'compare' => '='
                ]]
            ]);
            if (!empty($by_uid)) return $by_uid[0];

            $by_eventurl = get_posts([
                'post_type' => 'tribe_events',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => '_EventURL',
                    'value' => $normalized,
                    'compare' => '='
                ]]
            ]);
            if (!empty($by_eventurl)) return $by_eventurl[0];
        }

        // Recherche par titre + date
        $legacy_hash = md5(($event['title'] ?? '') . ($event['date'] ?? ''));
        $by_legacy = get_posts([
            'post_type' => 'tribe_events',
            'posts_per_page' => 1,
            'meta_query' => [[
                'key' => 'ffc_event_id',
                'value' => $legacy_hash,
                'compare' => '='
            ]]
        ]);
        if (!empty($by_legacy)) return $by_legacy[0];

        // Filet de sécurité: même titre composé et même date
        $composed_title = trim(($event['title'] ?? '') . ' - ' . ($event['location'] ?? ''));
        $event_date = date('Y-m-d', strtotime($event['date'] ?? ''));
        if ($composed_title && $event_date) {
            $by_title = get_page_by_title($composed_title, OBJECT, 'tribe_events');
            if ($by_title && isset($by_title->ID)) {
                $start_meta = get_post_meta($by_title->ID, '_EventStartDate', true);
                if ($start_meta && strpos($start_meta, $event_date) === 0) {
                    return $by_title;
                }
            }
        }

        return null;
    }

    private function normalizeUrl($url) {
        if (!$url) return '';
        $parts = @parse_url(trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8')));
        if (!$parts || empty($parts['host'])) return rtrim($url, "/ ");
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        return $scheme . '://' . $host . $path;
    }

    private function updateExistingEventMeta($post_id, $event) {
        if ($post_id <= 0) return;
        if (!empty($event['date'])) {
            $event_date = date('Y-m-d', strtotime($event['date']));
            update_post_meta($post_id, '_EventStartDate', $event_date . ' 10:00:00');
            update_post_meta($post_id, '_EventEndDate', $event_date . ' 18:00:00');
        }
        if (!empty($event['website'])) {
            update_post_meta($post_id, '_EventURL', $event['website']);
        }
        if (!empty($event['groupe'])) {
            update_post_meta($post_id, 'ffc_groupe', $event['groupe']);
        }
        if (!empty($event['specialite'])) {
            update_post_meta($post_id, 'ffc_specialite', $event['specialite']);
        }
        if (!empty($event['epreuve'])) {
            update_post_meta($post_id, 'ffc_epreuve', $event['epreuve']);
        }
    }

    private function buildEventDescription($event) {
        $description = "<strong>Lieu:</strong> " . esc_html($event['location']) . "<br>";
        $description .= "<strong>Organisateur:</strong> " . esc_html($event['organizer']) . "<br>";
        if ($event['department']) {
            $description .= "<strong>Département:</strong> " . esc_html($event['department']) . "<br>";
        }
        if (!empty($event['specialite'])) {
            $description .= "<strong>Spécialité:</strong> " . esc_html($event['specialite']) . "<br>";
        }
        if (!empty($event['epreuve'])) {
            $description .= "<strong>Épreuve:</strong> " . esc_html($event['epreuve']) . "<br>";
        }
        $is_national = !empty($event['is_national']);
        $description .= "<strong>Type:</strong> " . ($is_national ? "Événement National" : "Événement Normandie") . "<br>";
        $description .= "<strong>Source:</strong> FFCOrientation<br>";
        if (!empty($event['link'])) {
            $description .= "<strong>Plus d'infos:</strong> <a href='" . esc_url($event['link']) . "' target='_blank'>Voir sur FFCOrientation</a>";
        }
        return $description;
    }

    private function getOrCreateVenue($venue_name) {
        $venues = get_posts([
            'post_type' => 'tribe_venue',
            'title' => $venue_name,
            'posts_per_page' => 1
        ]);
        if (!empty($venues)) { return $venues[0]->ID; }

        $venue_id = wp_insert_post([
            'post_title' => $venue_name,
            'post_type' => 'tribe_venue',
            'post_status' => 'publish'
        ]);
        if ($venue_id) {
            update_post_meta($venue_id, '_VenueAddress', $venue_name);
            update_post_meta($venue_id, '_VenueCountry', 'France');
        }
        return $venue_id;
    }

    private function getOrCreateOrganizer($organizer_name = 'FFCOrientation') {
        $organizers = get_posts([
            'post_type' => 'tribe_organizer',
            'title' => $organizer_name,
            'posts_per_page' => 1
        ]);
        if (!empty($organizers)) { return $organizers[0]->ID; }

        $organizer_id = wp_insert_post([
            'post_title' => $organizer_name,
            'post_type' => 'tribe_organizer',
            'post_status' => 'publish'
        ]);
        if ($organizer_id) {
            update_post_meta($organizer_id, '_OrganizerWebsite', 'https://www.ffcorientation.fr');
        }
        return $organizer_id;
    }

    public function run() {
        $this->output("🚀 Début de la synchronisation (Ligue Normandie)...");
        $events = $this->fetchFFCEvents();
        $this->output("📊 Événements récupérés : " . count($events));

        if (!empty($events)) {
            $synced = $this->syncToEventsCalendar($events);
            $this->output("✅ Événements synchronisés : $synced");
            return $synced;
        }

        $this->output("ℹ️ Aucun événement à synchroniser");
        return 0;
    }
}

function output_message_ligue($message) {
    if (php_sapi_name() === 'cli') {
        echo $message . "\n";
    } else {
        echo nl2br(htmlspecialchars($message)) . "<br>\n";
        echo str_pad('', 4096); // Force le flush du buffer navigateur
        if (ob_get_level() > 0) { ob_flush(); flush(); }
    }
}

if (php_sapi_name() === 'cli' || !empty($_GET['run'])) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sync Ligue Normandie</title></head><body><div class="container"><h1>🗓️ Sync Ligue Normandie</h1><div class="log">';
        FFCEventsCalendarSyncLigueNormandie::$output_function = 'output_message_ligue';
    }

    try {
        $sync = new FFCEventsCalendarSyncLigueNormandie();
        $result = $sync->run();
        output_message_ligue("✅ SUCCÈS GLOBAL : $result événements traités.");
    } catch (Exception $e) {
        output_message_ligue("❌ ERREUR : " . $e->getMessage());
    }

    if (php_sapi_name() !== 'cli') {
        echo '<div style="margin-top:20px;padding:15px;background:#ecfdf5;border:2px solid #10b981;color:#064e3b;text-align:center;font-weight:bold;border-radius:8px;font-size:18px;">🏁 FIN DU TRAITEMENT</div>';
        echo '</div></div></body></html>';
    }
}
?>
