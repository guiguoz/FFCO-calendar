<?php
/**
 * Sync FFCOrientation → The Events Calendar (Version WP_Query)
 */

require_once('/kunden/homepages/11/d468677337/htdocs/clickandbuilds/Vikazim604088/wp-config.php');

class FFCEventsSync {
    private $target_departments = ['27', '76', '14', '50', '61'];
    private $ffc_url = 'https://www.ffcorientation.fr/courses/agenda/';
    
    public function run() {
        echo "🚀 Début synchronisation...<br>";
        
        // Récupérer événements
        $events = $this->fetchEvents();
        echo "📊 Événements récupérés: " . count($events) . "<br>";
        
        $created = 0;
        foreach ($events as $event) {
            if ($this->createEvent($event)) {
                $created++;
            }
        }
        
        echo "✅ Événements créés: $created<br>";
        
        // Vérifier avec WP_Query
        $query = new WP_Query([
            'post_type' => 'tribe_events',
            'posts_per_page' => -1,
            'suppress_filters' => true,
            'meta_query' => [
                [
                    'key' => 'ffc_source_url',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        echo "📋 Événements FFCOrientation en base: " . $query->found_posts . "<br>";
        
        return $created;
    }
    
    private function fetchEvents() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->ffc_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $html = curl_exec($ch);
        curl_close($ch);
        
        if (!$html) return [];
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table//tr[position()>1]');
        
        $events = [];
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length >= 4) {
                $date = trim($cells->item(0)->textContent);
                $title = trim($cells->item(2)->textContent);
                $location = trim($cells->item(3)->textContent);
                
                if (preg_match('/\d{2}\/\d{2}\/\d{2}/', $date) && $title) {
                    $department = $this->extractDepartment($location);
                    if ($this->isAllowed($title, $department)) {
                        $events[] = [
                            'title' => $title,
                            'date' => $this->parseDate($date),
                            'location' => $location,
                            'department' => $department
                        ];
                    }
                }
            }
        }
        
        return $events;
    }
    
    private function createEvent($event) {
        // Vérifier si existe avec WP_Query
        $existing = new WP_Query([
            'post_type' => 'tribe_events',
            'posts_per_page' => 1,
            'suppress_filters' => true,
            'meta_query' => [
                [
                    'key' => 'ffc_event_hash',
                    'value' => md5($event['title'] . $event['date']),
                    'compare' => '='
                ]
            ]
        ]);
        
        if ($existing->have_posts()) {
            echo "  → Existe déjà: {$event['title']}<br>";
            return false;
        }
        
        $post_id = wp_insert_post([
            'post_title' => $event['title'] . ' - ' . $event['location'],
            'post_content' => $this->buildDescription($event),
            'post_status' => 'publish',
            'post_type' => 'tribe_events',
            'post_author' => 1
        ]);
        
        if (is_wp_error($post_id) || $post_id <= 0) {
            echo "  ❌ Échec: {$event['title']}<br>";
            return false;
        }
        
        // Métadonnées
        update_post_meta($post_id, '_EventStartDate', $event['date']);
        update_post_meta($post_id, '_EventEndDate', $event['date']);
        update_post_meta($post_id, '_EventAllDay', 'yes');
        update_post_meta($post_id, 'ffc_source_url', $this->ffc_url);
        update_post_meta($post_id, 'ffc_event_hash', md5($event['title'] . $event['date']));
        
        echo "  ✅ Créé: {$event['title']}<br>";
        return true;
    }
    
    private function extractDepartment($location) {
        if (preg_match('/\((\d{2})\)/', $location, $m)) {
            return $m[1];
        }
        return null;
    }
    
    private function isAllowed($title, $department) {
        if ($department && in_array($department, $this->target_departments)) {
            return true;
        }
        if (stripos($title, 'Championnat de France') !== false) {
            return true;
        }
        return false;
    }
    
    private function parseDate($dateStr) {
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{2})/', $dateStr, $m)) {
            return "20{$m[3]}-{$m[2]}-{$m[1]} 10:00:00";
        }
        return date('Y-m-d H:i:s');
    }
    
    private function buildDescription($event) {
        $desc = "<strong>Lieu:</strong> " . esc_html($event['location']) . "<br>";
        $desc .= "<strong>Département:</strong> " . esc_html($event['department']) . "<br>";
        $desc .= "<strong>Source:</strong> FFCOrientation";
        return $desc;
    }
}

// Exécution
if (!empty($_GET['run'])) {
    $sync = new FFCEventsSync();
    $sync->run();
}
?>