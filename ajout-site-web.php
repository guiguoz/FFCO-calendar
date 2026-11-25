<?php
/**
 * CODE À AJOUTER dans ffc-events-calendar-sync.php pour le site web organisateur
 */

// 1. MODIFIER la fonction parseEventDetailPage pour extraire le site web :

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
                
                // AJOUTER cette ligne pour le site web :
                if ((strpos($label, 'site web') !== false || strpos($label, 'site') !== false) && !$details['website']) {
                    // Chercher un lien dans la cellule
                    $links = $xpath->query('.//a', $cells->item(1));
                    if ($links->length > 0) {
                        $details['website'] = $links->item(0)->getAttribute('href');
                    } else {
                        // Si pas de lien, prendre le texte (peut être une URL)
                        if (filter_var($value, FILTER_VALIDATE_URL)) {
                            $details['website'] = $value;
                        }
                    }
                }
            }
        }
    }
    
    // Fallback regex pour site web
    if (!$details['website'] && preg_match('/Site\s*web?\s*:\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>/iu', $html, $m)) {
        $details['website'] = trim($m[1]);
    }
    
    return $details;
}

// 2. MODIFIER la fonction getEventDetailsFromPage pour passer le site web :

private function getEventDetailsFromPage($url, $department, $title) {
    $details = ['location' => '', 'organizer' => 'FFCOrientation', 'website' => ''];
    if ($url) {
        $page_details = $this->parseEventDetailPage($url);
        if ($page_details['location']) { $details['location'] = $page_details['location']; }
        if ($page_details['organizer']) { $details['organizer'] = $page_details['organizer']; }
        if ($page_details['date']) { $details['date'] = $page_details['date']; }
        if ($page_details['website']) { $details['website'] = $page_details['website']; } // AJOUTER
    }
    return $details;
}

// 3. MODIFIER la fonction extractEventData pour inclure le site web :

return [
    'title' => $title,
    'date' => ($details['date'] ?: $this->parseDate($date)),
    'location' => $details['location'] ?: $location,
    'department' => $department,
    'link' => $link,
    'organizer' => $details['organizer'],
    'website' => $details['website'], // AJOUTER cette ligne
    'description' => ''
];

// 4. MODIFIER la fonction createEventsCalendarEvent pour utiliser le site web :

// REMPLACER cette ligne :
update_post_meta($post_id, '_EventURL', $normalized);

// PAR :
$event_url = !empty($event['website']) ? $event['website'] : $normalized;
update_post_meta($post_id, '_EventURL', $event_url);

// Et AJOUTER :
update_post_meta($post_id, 'ffc_organizer_website', $event['website']);

// 5. MODIFIER le calendrier personnalisé pour utiliser le site web :

// Dans ffc-calendar-plugin.php, MODIFIER la génération des événements :

if (isset($events_by_day[$day])) {
    foreach ($events_by_day[$day] as $event) {
        $title = wp_trim_words($event->post_title, 3);
        $event_url = get_post_meta($event->ID, '_EventURL', true);
        
        if ($event_url) {
            echo "<a href='$event_url' target='_blank' class='ffc-event' title='" . esc_attr($event->post_title) . "'>$title</a>";
        } else {
            echo "<div class='ffc-event' title='" . esc_attr($event->post_title) . "'>$title</div>";
        }
    }
}

// Et AJOUTER ce CSS dans le calendrier :
.ffc-event a {
    color: white;
    text-decoration: none;
}
.ffc-event a:hover {
    color: white;
}
?>

<!-- 
RÉSUMÉ :
1. Extraire le site web depuis la page FFCO
2. Le sauvegarder comme _EventURL 
3. Rendre les événements du calendrier cliquables vers le site organisateur
-->