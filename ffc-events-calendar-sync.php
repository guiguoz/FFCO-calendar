<?php
/**
 * Sync FFCOrientation → The Events Calendar (Instance Vikazim2)
 * Cette copie lit la configuration locale depuis Vikazim2/config.php
 */

// Augmenter la limite de temps d'exécution (5 minutes)
set_time_limit(300);

// Désactiver la limite de mémoire
ini_set('memory_limit', '256M');

// Désactiver la mise en tampon de sortie pour voir les progrès
if (php_sapi_name() !== 'cli') {
    ob_implicit_flush(true);
    ob_end_flush();
    while (ob_get_level()) {
        ob_end_flush();
    }
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<br><strong>❌ ERREUR FATALE:</strong> " . $error['message'] . " dans " . $error['file'] . " ligne " . $error['line'];
    }
});

class FFCEventsCalendarSyncVikazim2 {
    public static $output_function = null;

    private $target_departments = ['27', '76', '14', '50', '61'];
    private $ffc_url = 'https://www.ffcorientation.fr/courses/agenda/';
    private $city_department_map = [];
    private $national_keyword = 'Championnat de France';
    private $scan_window = ['du_offset_days' => 0, 'scan_months_ahead' => 3, 'max_pages' => 20];

    private function output($message) {
        if (self::$output_function && is_callable(self::$output_function)) {
            call_user_func(self::$output_function, $message);
        } else {
            echo $message . "\n";
        }
    }

    public function __construct() {
        $this->output("🔧 Initialisation (Vikazim2)...");

        if (!function_exists('wp_insert_post')) {
            $wp_config_paths = [
                $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',
                dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-config.php',
                __DIR__ . '/../wp-config.php',
                __DIR__ . '/../../wp-config.php',
                __DIR__ . '/../../../wp-config.php'
            ];

            $wp_loaded = false;
            foreach ($wp_config_paths as $path) {
                if (file_exists($path)) {
                    $this->output("📁 Chargement WordPress depuis: $path");
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

        // Charger la configuration locale Vikazim2
        $config_path = __DIR__ . '/config.php';
        if (file_exists($config_path)) {
            $config = require $config_path;
            if (!empty($config['departments']) && is_array($config['departments'])) {
                $this->target_departments = $config['departments'];
            }
            if (!empty($config['ffc_url'])) {
                $this->ffc_url = $config['ffc_url'];
            }
            if (!empty($config['city_department_map']) && is_array($config['city_department_map'])) {
                $this->city_department_map = $config['city_department_map'];
            }
            if (!empty($config['scan_window']) && is_array($config['scan_window'])) {
                $this->scan_window = array_merge($this->scan_window, $config['scan_window']);
            }
        }
    }

    private function fetchFFCEvents() {
        $this->output("🌐 Début récupération événements FFCOrientation...");
        $all_events = [];
        $page_limit = (int)($this->scan_window['max_pages'] ?? 20);
        $mode = $this->scan_window['mode'] ?? 'current_and_next_month';

        $du_list = [];
        if ($mode === 'current_and_next_month') {
            $first_current = (new DateTime('first day of this month'))->format('Y-m-d');
            $first_next = (new DateTime('first day of next month'))->format('Y-m-d');
            $first_third = (new DateTime('first day of this month'))->modify('+2 months')->format('Y-m-d');
            $du_list = [$first_current, $first_next, $first_third];
            $page_limit = min($page_limit, 3);
            $this->output("🗓️ Mode: mois courant + 2 mois suivants");
            $this->output("📄 Limite pages: $page_limit");
        } else {
            $du_date = (new DateTime())->modify(($this->scan_window['du_offset_days'] ?? 0) . ' day');
            $du_list = [$du_date->format('Y-m-d')];
            $this->output("🗓️ Mode: offset depuis " . $du_date->format('Y-m-d'));
        }

        foreach ($du_list as $du) {
            $this->output("=== Scan FFCO à partir du $du ===");
            $page = 1;
            do {
                $base = $this->ffc_url;
                $query = ['du' => $du];
                if ($page > 1) $query['page'] = $page;
                $url = $base . (strpos($base, '?') !== false ? '&' : '?') . http_build_query($query);

                $this->output("📥 Téléchargement de la page $page...");
                $this->output("🔗 URL: $url");

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $html = curl_exec($ch);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if (!$html) {
                    $this->output("⚠️ Erreur de téléchargement: $curl_error");
                    break;
                }

                $this->output("📄 HTML téléchargé: " . strlen($html) . " caractères");

                $this->output("🔍 Analyse des événements...");
                $result = $this->parseEvents($html);
                $events = $result['events'];
                $total = $result['total'];

                if (($this->scan_window['mode'] ?? '') === 'current_and_next_month') {
                    $start_window = new DateTime('first day of this month 00:00:00');
                    $end_window = (new DateTime('first day of this month'))->modify('+2 months')->modify('last day of this month 23:59:59');
                    $events = array_values(array_filter($events, function($e) use ($start_window, $end_window) {
                        try { $dt = new DateTime($e['date']); } catch (Exception $ex) { return false; }
                        return $dt >= $start_window && $dt <= $end_window;
                    }));
                }

                if ($total === 0) {
                    $this->output("  -> Page $page : aucun événement, arrêt pour ce mois");
                    break;
                }

                if (count($events) === 0 && $mode === 'current_and_next_month') {
                    $this->output("  -> Page $page : aucun événement dans la fenêtre temporelle, arrêt pour ce mois");
                    break;
                }

                $all_events = array_merge($all_events, $events);
                $this->output("  ✅ Page $page : " . count($events) . " événements retenus sur $total");

                $page++;
            } while ($page <= $page_limit);
        }

        return $all_events;
    }

    private function parseEvents($html) {
        $this->output("🔍 Début parsing HTML...");
        $events = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $eventNodes = $xpath->query('//table//tr[position()>1]');

        foreach ($eventNodes as $node) {
            $event = $this->extractEventData($node, $xpath);
            if ($event && $event['title'] && $this->isEventAllowed($event)) {
                $events[] = $event;
            }
        }

        return ['events' => $events, 'total' => $eventNodes->length];
    }

    private function extractEventData($node, $xpath) {
        $cells = $xpath->query('.//td', $node);
        if ($cells->length >= 4) {
            $date = trim(html_entity_decode($cells->item(0)->textContent, ENT_QUOTES, 'UTF-8'));
            $title = trim(html_entity_decode($cells->item(2)->textContent, ENT_QUOTES, 'UTF-8'));
            $location = trim(html_entity_decode($cells->item(3)->textContent, ENT_QUOTES, 'UTF-8'));
            if (preg_match('/\d{2}\/\d{2}\/\d{2}/', $title)) { $tmp = $date; $date = $title; $title = $tmp; }
            if (!$date || !$title || !preg_match('/\d{2}\/\d{2}\/\d{2}/', $date)) { return null; }

            $link = $xpath->query('.//a', $node)->item(0)?->getAttribute('href') ?? '';
            if ($link && !str_starts_with($link, 'http')) { $link = 'https://www.ffcorientation.fr' . $link; }

            $department = $this->extractDepartment($location);
            $details = $this->getEventDetailsFromPage($link, $department, $title);

            return [
                'title' => $title,
                'date' => ($details['date'] ?: $this->parseDate($date)),
                'location' => $details['location'] ?: $location,
                'department' => $department,
                'link' => $link,
                'organizer' => $details['organizer'],
                'website' => $details['website'],
                'description' => ''
            ];
        }
        return null;
    }

    private function extractDepartment($location) {
        if (preg_match('/\((\d{2})\)/', $location, $matches)) { return $matches[1]; }
        foreach ((!empty($this->city_department_map) ? $this->city_department_map : []) as $city => $dept) {
            if (stripos($location, $city) !== false) { return $dept; }
        }
        return null;
    }

    private function getEventDetailsFromPage($url, $department, $title) {
        $details = ['location' => '', 'organizer' => 'FFCOrientation', 'website' => ''];
        if ($url) {
            $page_details = $this->parseEventDetailPage($url);
            if ($page_details['location']) { $details['location'] = $page_details['location']; }
            if ($page_details['organizer']) { $details['organizer'] = $page_details['organizer']; }
            if ($page_details['date']) { $details['date'] = $page_details['date']; }
            if ($page_details['website']) { $details['website'] = $page_details['website']; }
        }
        return $details;
    }

    private function parseEventDetailPage($url) {
        $details = ['location' => '', 'organizer' => '', 'date' => '', 'website' => ''];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) return $details;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table//tr');
        if ($rows && $rows->length) {
            foreach ($rows as $tr) {
                $cells = $xpath->query('./th|./td', $tr);
                if ($cells->length >= 2) {
                    $label = trim(mb_strtolower(html_entity_decode(strip_tags($cells->item(0)->textContent), ENT_QUOTES, 'UTF-8')));
                    $value = trim(html_entity_decode(strip_tags($cells->item(1)->textContent), ENT_QUOTES, 'UTF-8'));
                    if ($value === '') continue;
                    if (strpos($label, 'organisateur') !== false && !$details['organizer']) { $details['organizer'] = $value; }
                    if (strpos($label, 'lieu') !== false && !$details['location']) { $details['location'] = $value; }
                    if (strpos($label, 'date') !== false && !$details['date']) { $parsed = $this->parseFrenchLongDate($value); if ($parsed) $details['date'] = $parsed; }
                    if ((strpos($label, 'site web') !== false || strpos($label, 'site') !== false) && !$details['website']) {
                        $links = $xpath->query('.//a', $cells->item(1));
                        if ($links->length > 0) {
                            $details['website'] = $links->item(0)->getAttribute('href');
                        } elseif (filter_var($value, FILTER_VALIDATE_URL)) {
                            $details['website'] = $value;
                        }
                    }
                }
            }
        }
        if (!$details['organizer'] && preg_match('/Organisateur\s*:\s*([^<\n\r]+)/iu', $html, $m)) { $details['organizer'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
        if (!$details['location'] && preg_match('/Lieu\s*:\s*([^<\n\r]+)/iu', $html, $m)) { $details['location'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
        if (!$details['date'] && preg_match('/Date\s*:\s*([^<\n\r]+)/iu', $html, $m)) { $parsed = $this->parseFrenchLongDate(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'))); if ($parsed) $details['date'] = $parsed; }
        if (!$details['website'] && preg_match('/Site\s*web?\s*:\s*<a[^>]+href=["\']([^"\'][^>]*>[^<]*</a>/iu', $html, $m)) { $details['website'] = trim($m[1]); }
        return $details;
    }

    private function isEventAllowed($event) {
        if ($event['department'] && in_array($event['department'], $this->target_departments)) { return true; }
        if (stripos($event['title'], $this->national_keyword) !== false) { return true; }
        return false;
    }

    private function parseDate($dateStr) {
        $dateStr = trim($dateStr);
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
        if ($existing) { $this->updateExistingEventMeta($existing->ID, $event); return false; }

        $event_date = date('Y-m-d', strtotime($event['date']));
        $event_start = $event_date . ' 10:00:00';
        $event_end = $event_date . ' 18:00:00';

        // Récupérer ou créer l'utilisateur Guillaume
        $author = get_user_by('login', 'Guillaume');
        if (!$author) {
            $author_id = wp_create_user('Guillaume', wp_generate_password(), 'guillaume@vikazim.fr');
            if (!is_wp_error($author_id)) {
                wp_update_user([
                    'ID' => $author_id,
                    'display_name' => 'Guillaume',
                    'role' => 'author'
                ]);
            }
        } else {
            $author_id = $author->ID;
        }

        $post_id = wp_insert_post([
            'post_title' => $event['title'] . ' - ' . $event['location'],
            'post_content' => $this->buildEventDescription($event),
            'post_status' => 'publish',
            'post_type' => 'tribe_events',
            'post_author' => $author_id
        ]);

        if ($post_id > 0) {
            update_post_meta($post_id, '_EventStartDate', $event_start);
            update_post_meta($post_id, '_EventEndDate', $event_end);
            update_post_meta($post_id, '_EventAllDay', 'yes');
            update_post_meta($post_id, '_EventShowMapLink', '1');
            update_post_meta($post_id, '_EventShowMap', '1');
            $normalized = $this->normalizeUrl($event['link']);
            $event_url = !empty($event['website']) ? $event['website'] : $normalized;
            update_post_meta($post_id, '_EventURL', $event_url);
            update_post_meta($post_id, '_EventCost', '');
            update_post_meta($post_id, '_EventCurrencySymbol', '€');

            if ($event['location']) { $venue_id = $this->getOrCreateVenue($event['location']); update_post_meta($post_id, '_EventVenueID', $venue_id); }
            if ($event['organizer']) { $organizer_id = $this->getOrCreateOrganizer($event['organizer']); update_post_meta($post_id, '_EventOrganizerID', $organizer_id); }

            $category_name = (stripos($event['title'], $this->national_keyword) !== false) ? 'Championnat National' : 'Compétition fédérale';
            $category = get_term_by('name', $category_name, 'tribe_events_cat');
            if (!$category) {
                $new_cat = wp_insert_term($category_name, 'tribe_events_cat');
                if (!is_wp_error($new_cat)) { $category = get_term($new_cat['term_id'], 'tribe_events_cat'); }
            }
            if ($category) { wp_set_post_terms($post_id, [$category->term_id], 'tribe_events_cat'); }

            update_post_meta($post_id, 'ffc_source_url', $normalized);
            update_post_meta($post_id, 'ffc_event_uid', md5((string)$normalized));
            update_post_meta($post_id, 'ffc_event_id', md5($event['title'] . $event['date']));
            update_post_meta($post_id, 'ffc_department', $event['department']);
            update_post_meta($post_id, 'ffc_organizer_name', $event['organizer']);
            update_post_meta($post_id, 'ffc_organizer_website', $event['website']);
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
    }

    private function buildEventDescription($event) {
        $description = "<strong>Lieu:</strong> " . $event['location'] . "<br>";
        $description .= "<strong>Organisateur:</strong> " . $event['organizer'] . "<br>";
        if ($event['department']) { $description .= "<strong>Département:</strong> " . $event['department'] . "<br>"; }
        $is_national = stripos($event['title'], $this->national_keyword) !== false;
        $description .= "<strong>Type:</strong> " . ($is_national ? "Événement National" : "Événement Régional Normandie") . "<br>";
        $description .= "<strong>Source:</strong> FFCOrientation<br>";
        $description .= "<strong>Plus d'infos:</strong> <a href='" . $event['link'] . "' target='_blank'>Voir sur FFCOrientation</a>";
        return $description;
    }

    private function getOrCreateVenue($venue_name) {
        $venues = get_posts(['post_type' => 'tribe_venue', 'title' => $venue_name, 'posts_per_page' => 1]);
        if (!empty($venues)) { return $venues[0]->ID; }
        $venue_id = wp_insert_post(['post_title' => $venue_name, 'post_type' => 'tribe_venue', 'post_status' => 'publish']);
        if ($venue_id) { update_post_meta($venue_id, '_VenueAddress', $venue_name); update_post_meta($venue_id, '_VenueCountry', 'France'); }
        return $venue_id;
    }

    private function getOrCreateOrganizer($organizer_name = 'FFCOrientation') {
        $organizers = get_posts(['post_type' => 'tribe_organizer', 'title' => $organizer_name, 'posts_per_page' => 1]);
        if (!empty($organizers)) { return $organizers[0]->ID; }
        $organizer_id = wp_insert_post(['post_title' => $organizer_name, 'post_type' => 'tribe_organizer', 'post_status' => 'publish']);
        if ($organizer_id) { update_post_meta($organizer_id, '_OrganizerWebsite', 'https://www.ffcorientation.fr'); }
        return $organizer_id;
    }

    public function run() {
        $this->output("🚀 Début de la synchronisation (Vikazim2)...");
        $events = $this->fetchFFCEvents();
        $this->output("📊 Événements récupérés : " . count($events));
        if (!empty($events)) { $synced = $this->syncToEventsCalendar($events); $this->output("✅ Événements synchronisés : $synced"); return $synced; }
        $this->output("ℹ️ Aucun événement à synchroniser");
        return 0;
    }

    public function refreshExistingFromSource() {
        $this->output("🔄 Mise à jour des événements existants depuis la source (Vikazim2)...");
        $events = get_posts(['post_type' => 'tribe_events', 'posts_per_page' => -1, 'meta_key' => 'ffc_source_url']);
        $updated = 0;
        foreach ($events as $post) {
            $url = get_post_meta($post->ID, 'ffc_source_url', true);
            if (!$url) continue;
            $details = $this->parseEventDetailPage($url);
            if (!$details['location'] && !$details['organizer'] && !$details['date']) continue;
            $event = [
                'title' => $post->post_title,
                'date' => $details['date'] ?: get_post_meta($post->ID, '_EventStartDate', true),
                'location' => $details['location'] ?: '',
                'organizer' => $details['organizer'] ?: '',
                'department' => get_post_meta($post->ID, 'ffc_department', true),
                'link' => $url,
                'description' => $post->post_content,
            ];
            $this->updateExistingEventMeta($post->ID, $event);
            
            // Mettre à jour l'auteur si nécessaire
            $author = get_user_by('login', 'Guillaume');
            if ($author && $post->post_author != $author->ID) {
                wp_update_post([
                    'ID' => $post->ID,
                    'post_author' => $author->ID
                ]);
            }
            
            $updated++;
        }
        $this->output("✅ Événements mis à jour: $updated");
        return $updated;
    }
}

function output_message_v2($message) {
    if (php_sapi_name() === 'cli') { echo $message . "\n"; }
    else {
        echo nl2br(htmlspecialchars($message)) . "<br>\n";
        if (ob_get_level() > 0) { ob_flush(); flush(); }
    }
}

if (php_sapi_name() === 'cli' || !empty($_GET['run'])) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sync Vikazim2</title></head><body><div class="container"><h1>🗓️ Sync Vikazim2</h1><div class="log">';
        FFCEventsCalendarSyncVikazim2::$output_function = 'output_message_v2';
    }
    try {
        $sync = new FFCEventsCalendarSyncVikazim2();
        if (!empty($_GET['refresh'])) { $result = $sync->refreshExistingFromSource(); output_message_v2("✅ Mise à jour : $result événements"); }
        else { $result = $sync->run(); output_message_v2("✅ Synchronisation : $result événements"); }
    } catch (Exception $e) {
        output_message_v2("❌ ERREUR : " . $e->getMessage());
    }
    if (php_sapi_name() !== 'cli') { echo '</div></div></body></html>'; }
}

?>


