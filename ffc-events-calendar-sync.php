<?php
/**
 * Sync FFCOrientation → The Events Calendar (Instance Vikazim2)
 * Cette copie lit la configuration locale depuis Vikazim2/config.php
 *
 * Source : https://cn.ffcorientation.fr/course/ (Courses CN)
 * (l'ancien agenda https://www.ffcorientation.fr/courses/agenda/ ne sert
 * plus de tableau d'événements depuis la refonte du site fédéral).
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

    /**
     * Récupère la liste des courses CN.
     *
     * La page CN n'affiche qu'UNE saison à la fois (?season=AAAA). Comme la
     * fenêtre de scan peut chevaucher deux saisons (ex. nov. 2026 → janv. 2027),
     * on interroge chaque année couverte par la fenêtre puis on fusionne.
     */
    private function fetchFFCEvents() {
        $this->output("🌐 Début récupération événements FFCOrientation (CN)...");

        // Étape 1 — Fenêtre temporelle : mois courant + 2 mois suivants
        $mode = $this->scan_window['mode'] ?? 'current_and_next_month';
        $start_window = new DateTime('first day of this month 00:00:00');
        $end_window = (new DateTime('first day of this month'))->modify('+2 months')->modify('last day of this month 23:59:59');
        $this->output("🗓️ Fenêtre ($mode): " . $start_window->format('Y-m-d') . " → " . $end_window->format('Y-m-d'));

        // Étape 2 — Une requête par saison, c.-à-d. par année couverte par la fenêtre
        $seasons = range((int) $start_window->format('Y'), (int) $end_window->format('Y'));
        $this->output("📅 Saisons à interroger: " . implode(', ', $seasons));

        $rows = 0; $min_date = null; $max_date = null; $nb_normandie = 0; $nb_national = 0;
        $events = []; $seen = []; $failed = [];

        $accumulate = function ($result, $label) use (&$events, &$seen, &$rows, &$min_date, &$max_date, &$nb_normandie, &$nb_national) {
            $rows         += $result['total'];
            $nb_normandie += $result['normandie'];
            $nb_national  += $result['national'];
            if ($result['min_date'] && ($min_date === null || $result['min_date'] < $min_date)) { $min_date = $result['min_date']; }
            if ($result['max_date'] && ($max_date === null || $result['max_date'] > $max_date)) { $max_date = $result['max_date']; }

            $added = 0;
            foreach ($result['events'] as $event) {
                $key = $this->eventKey($event);
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $events[] = $event;
                $added++;
            }
            $this->output("   ↳ $label : " . $result['total'] . " lignes, dont $added Normandie/nationales");
        };

        foreach ($seasons as $season) {
            $html = $this->downloadPage($this->ffc_url . '?season=' . $season);
            if ($html === null) { $failed[] = $season; continue; }
            $accumulate($this->parseEvents($html), "Saison $season");
        }

        // Étape 3 — Filet de sécurité : si ?season= n'a rien donné, on tente la page par défaut
        if ($rows === 0) {
            $this->output("⚠️ Aucune ligne obtenue via ?season= — essai sur la page par défaut");
            $html = $this->downloadPage($this->ffc_url);
            if ($html !== null) { $accumulate($this->parseEvents($html), "Page par défaut"); }
        }

        // Étape 4 — Application de la fenêtre temporelle
        $in_window = [];
        foreach ($events as $event) {
            try { $dt = new DateTime($event['date']); } catch (Exception $ex) { continue; }
            if ($dt >= $start_window && $dt <= $end_window) { $in_window[] = $event; }
        }

        // Étape 5 — Bilan détaillé, pour comprendre immédiatement un résultat à 0
        $this->output("🔎 Bilan :");
        $this->output("   • Lignes lues : $rows" . ($failed ? " (saisons injoignables : " . implode(', ', $failed) . ")" : ""));
        if ($min_date && $max_date) {
            $this->output("   • Dates trouvées : du " . substr($min_date, 0, 10) . " au " . substr($max_date, 0, 10));
        }
        $this->output("   • Normandie (" . implode(', ', $this->target_departments) . ") : $nb_normandie");
        $this->output("   • Nationales (groupe " . implode('/', $this->national_groups) . " ou mots-clés) : $nb_national");
        $this->output("   • Dans la fenêtre : " . count($in_window));

        // Détail des événements retenus (utile pour vérifier le filtrage)
        if (count($in_window) > 0 && count($in_window) <= 20) {
            foreach ($in_window as $event) {
                $this->output("      – " . substr($event['date'], 0, 10)
                    . " | " . $event['title']
                    . " | dép. " . ($event['department'] ?: '?')
                    . " | groupe " . ($event['groupe'] ?: '?')
                    . (!empty($event['is_national']) ? " [national]" : ""));
            }
        }

        if (count($in_window) === 0) {
            $this->output("ℹ️ Aucun événement à synchroniser sur cette période.");
            if ($max_date && $max_date < $end_window->format('Y-m-d H:i:s')) {
                $this->output("   ⚠️ La dernière course publiée (" . substr($max_date, 0, 10) . ") est antérieure à la fin de fenêtre :");
                $this->output("      la saison " . $end_window->format('Y') . " n'est sans doute pas encore publiée sur cn.ffcorientation.fr.");
                $this->output("      Ce n'est pas un bug — la synchro reprendra dès publication du calendrier.");
            }
        }

        $this->output("  ✅ " . count($in_window) . " événements retenus sur $rows lignes");
        return $in_window;
    }

    /**
     * Télécharge une page et renvoie son HTML (null en cas d'échec).
     */
    private function downloadPage($url) {
        $this->output("📥 Téléchargement: $url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $html = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html) {
            $this->output("   ⚠️ Échec (HTTP $http_code) : $curl_error");
            return null;
        }

        $this->output("   📄 " . strlen($html) . " caractères (HTTP $http_code)");
        return $html;
    }

    /**
     * Clé d'unicité d'une course, pour fusionner plusieurs saisons sans doublon.
     */
    private function eventKey($event) {
        if (!empty($event['link'])) { return $this->normalizeUrl($event['link']); }
        return strtolower($event['title']) . '|' . $event['date'] . '|' . strtolower($event['location']);
    }

    /**
     * Parse le tableau Courses CN : Date | Course | Lieu | Organisateur |
     * Groupe | Spécialité | Épreuve | Résultats.
     */
    private function parseEvents($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $eventNodes = $xpath->query('//table//tr[position()>1]');

        $stats = [
            'events' => [], 'total' => 0, 'normandie' => 0, 'national' => 0,
            'min_date' => null, 'max_date' => null,
        ];

        if (!$eventNodes || $eventNodes->length === 0) {
            $this->output("   ⚠️ Aucune ligne trouvée dans le tableau (saison non publiée ou structure modifiée)");
            return $stats;
        }

        foreach ($eventNodes as $node) {
            $event = $this->extractEventData($node, $xpath);
            if (!$event || !$event['title'] || !$event['date']) { continue; }

            $stats['total']++;
            if ($stats['min_date'] === null || $event['date'] < $stats['min_date']) { $stats['min_date'] = $event['date']; }
            if ($stats['max_date'] === null || $event['date'] > $stats['max_date']) { $stats['max_date'] = $event['date']; }

            $is_normandie = $event['department'] && in_array($event['department'], $this->target_departments, true);
            if ($is_normandie) { $stats['normandie']++; }
            if (!empty($event['is_national'])) { $stats['national']++; }

            if ($is_normandie || !empty($event['is_national'])) { $stats['events'][] = $event; }
        }

        return $stats;
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
        if (preg_match('/\((\d{2})\)/', $location, $matches)) { return $matches[1]; }
        foreach ((!empty($this->city_department_map) ? $this->city_department_map : []) as $city => $dept) {
            if (stripos($location, $city) !== false) { return $dept; }
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

            $category_name = !empty($event['is_national']) ? 'Championnat National' : 'Compétition fédérale';
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
        $description = "<strong>Lieu:</strong> " . $event['location'] . "<br>";
        $description .= "<strong>Organisateur:</strong> " . $event['organizer'] . "<br>";
        if ($event['department']) { $description .= "<strong>Département:</strong> " . $event['department'] . "<br>"; }
        if (!empty($event['specialite'])) { $description .= "<strong>Spécialité:</strong> " . $event['specialite'] . "<br>"; }
        if (!empty($event['epreuve'])) { $description .= "<strong>Épreuve:</strong> " . $event['epreuve'] . "<br>"; }
        $is_national = !empty($event['is_national']);
        $description .= "<strong>Type:</strong> " . ($is_national ? "Événement National" : "Événement Régional Normandie") . "<br>";
        $description .= "<strong>Source:</strong> FFCOrientation<br>";
        if (!empty($event['link'])) {
            $description .= "<strong>Plus d'infos:</strong> <a href='" . $event['link'] . "' target='_blank'>Voir sur FFCOrientation</a>";
        }
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

    /**
     * Met à jour les événements déjà importés à partir de la liste CN
     * (les fiches détail ne contiennent plus les infos organisateur/date).
     */
    public function refreshExistingFromSource() {
        $this->output("🔄 Mise à jour des événements existants depuis la source (Vikazim2)...");
        $events = $this->fetchFFCEvents();
        if (empty($events)) {
            $this->output("⚠️ Aucun événement récupéré, mise à jour annulée");
            return 0;
        }

        $by_url = [];
        foreach ($events as $event) {
            $normalized = $this->normalizeUrl($event['link'] ?? '');
            if ($normalized) {
                $by_url[$normalized] = $event;
            }
        }

        $posts = get_posts(['post_type' => 'tribe_events', 'posts_per_page' => -1, 'meta_key' => 'ffc_source_url']);
        $updated = 0;
        foreach ($posts as $post) {
            $url = get_post_meta($post->ID, 'ffc_source_url', true);
            if (!$url || !isset($by_url[$url])) continue;
            $this->updateExistingEventMeta($post->ID, $by_url[$url]);

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
