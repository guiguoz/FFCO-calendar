<?php
/**
 * CODE À AJOUTER dans ffc-events-calendar-sync.php
 */

// 1. AJOUTER cette fonction dans la classe FFCEventsCalendarSyncVikazim2 :

private function extractSpecialite($url) {
    if (!$url) return 'Course d\'orientation';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) return 'Course d\'orientation';
    
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Chercher dans les tableaux
    $rows = $xpath->query('//table//tr');
    foreach ($rows as $row) {
        $cells = $xpath->query('./th|./td', $row);
        if ($cells->length >= 2) {
            $label = trim(mb_strtolower($cells->item(0)->textContent));
            $value = trim($cells->item(1)->textContent);
            
            if (strpos($label, 'spécialité') !== false || 
                strpos($label, 'type') !== false ||
                strpos($label, 'discipline') !== false) {
                return $value ?: 'Course d\'orientation';
            }
        }
    }
    
    // Fallback: mots-clés
    if (preg_match('/(raid\s+orientation|trail\s+o|ski\s+o|course\s+d\'orientation)/i', $html, $matches)) {
        return ucwords(strtolower(trim($matches[1])));
    }
    
    return 'Course d\'orientation';
}

// 2. MODIFIER la fonction getEventDetailsFromPage pour inclure la spécialité :

private function getEventDetailsFromPage($url, $department, $title) {
    $details = ['location' => '', 'organizer' => 'FFCOrientation', 'specialite' => ''];
    if ($url) {
        $page_details = $this->parseEventDetailPage($url);
        if ($page_details['location']) { $details['location'] = $page_details['location']; }
        if ($page_details['organizer']) { $details['organizer'] = $page_details['organizer']; }
        if ($page_details['date']) { $details['date'] = $page_details['date']; }
        
        // AJOUTER cette ligne :
        $details['specialite'] = $this->extractSpecialite($url);
    }
    return $details;
}

// 3. MODIFIER la fonction extractEventData pour passer la spécialité :

private function extractEventData($node, $xpath) {
    $cells = $xpath->query('.//td', $node);
    if ($cells->length >= 4) {
        $date = trim(html_entity_decode($cells->item(0)->textContent, ENT_QUOTES, 'UTF-8'));
        $title = trim(html_entity_decode($cells->item(2)->textContent, ENT_QUOTES, 'UTF-8'));
        $location = trim(html_entity_decode($cells->item(3)->textContent, ENT_QUOTES, 'UTF-8'));
        if (preg_match('/\\d{2}\\/\\d{2}\\/\\d{2}/', $title)) { $tmp = $date; $date = $title; $title = $tmp; }
        if (!$date || !$title || !preg_match('/\\d{2}\\/\\d{2}\\/\\d{2}/', $date)) { return null; }

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
            'specialite' => $details['specialite'], // AJOUTER cette ligne
            'description' => ''
        ];
    }
    return null;
}

// 4. MODIFIER la fonction createEventsCalendarEvent pour sauvegarder la spécialité :

// Dans createEventsCalendarEvent, AJOUTER après les autres update_post_meta :
update_post_meta($post_id, 'ffc_specialite', $event['specialite']);

// Et dans la taxonomie, REMPLACER la ligne de catégorie par :
$category_name = $event['specialite'] ?: 'Course d\'orientation';
$category = get_term_by('name', $category_name, 'tribe_events_cat');
if (!$category) {
    $new_cat = wp_insert_term($category_name, 'tribe_events_cat');
    if (!is_wp_error($new_cat)) { $category = get_term($new_cat['term_id'], 'tribe_events_cat'); }
}
if ($category) { wp_set_post_terms($post_id, [$category->term_id], 'tribe_events_cat'); }
?>

<!-- 
RÉSUMÉ DES MODIFICATIONS :
1. Ajouter la fonction extractSpecialite()
2. Modifier getEventDetailsFromPage() 
3. Modifier extractEventData()
4. Modifier createEventsCalendarEvent()

La spécialité sera automatiquement extraite et utilisée comme catégorie d'événement !
-->