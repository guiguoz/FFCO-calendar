<?php
/**
 * Calendrier FFC personnalisé pour Elementor
 */

require_once('/kunden/homepages/11/d468677337/htdocs/clickandbuilds/Vikazim604088/wp-config.php');

// Enregistrer le shortcode
add_shortcode('ffc_calendar', 'ffc_calendar_shortcode');

function ffc_calendar_shortcode($atts) {
    $atts = shortcode_atts([
        'month' => date('n'),
        'year' => date('Y')
    ], $atts);
    
    return generate_ffc_calendar($atts['month'], $atts['year']);
}

function generate_ffc_calendar($month, $year) {
    global $wpdb;
    
    // Récupérer les événements FFC du mois
    $start_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $events = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_title, pm.meta_value as event_date
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
        WHERE p.post_type = 'tribe_events'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_EventStartDate'
        AND pm2.meta_key = 'ffc_source_url'
        AND DATE(pm.meta_value) BETWEEN %s AND %s
        ORDER BY pm.meta_value ASC
    ", $start_date, $end_date));
    
    // Organiser par jour
    $events_by_day = [];
    foreach ($events as $event) {
        $day = date('j', strtotime($event->event_date));
        $events_by_day[$day][] = $event;
    }
    
    // Générer le calendrier
    $first_day = date('w', strtotime($start_date));
    $days_in_month = date('t', strtotime($start_date));
    $month_name = date('F Y', strtotime($start_date));
    
    ob_start();
    ?>
    <div class="ffc-calendar">
        <style>
        .ffc-calendar {
            max-width: 800px;
            margin: 0 auto;
            font-family: Arial, sans-serif;
        }
        .ffc-calendar-header {
            background: #2c5aa0;
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
        }
        .ffc-calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f5f5f5;
        }
        .ffc-calendar-nav a {
            background: #2c5aa0;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 4px;
        }
        .ffc-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 1px solid #ddd;
        }
        .ffc-calendar-day-header {
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #ddd;
        }
        .ffc-calendar-day {
            min-height: 100px;
            padding: 5px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .ffc-calendar-day.other-month {
            background: #f9f9f9;
            color: #ccc;
        }
        .ffc-calendar-day.today {
            background: #fff3cd;
        }
        .ffc-calendar-event {
            background: #2c5aa0;
            color: white;
            padding: 2px 5px;
            margin: 2px 0;
            border-radius: 3px;
            font-size: 11px;
            line-height: 1.2;
        }
        .ffc-calendar-event:hover {
            background: #1e3d6f;
        }
        </style>
        
        <div class="ffc-calendar-header">
            Calendrier Course d'Orientation - <?php echo $month_name; ?>
        </div>
        
        <div class="ffc-calendar-nav">
            <a href="?ffc_month=<?php echo $month == 1 ? 12 : $month - 1; ?>&ffc_year=<?php echo $month == 1 ? $year - 1 : $year; ?>">‹ Précédent</a>
            <span>Événements FFCOrientation Normandie</span>
            <a href="?ffc_month=<?php echo $month == 12 ? 1 : $month + 1; ?>&ffc_year=<?php echo $month == 12 ? $year + 1 : $year; ?>">Suivant ›</a>
        </div>
        
        <div class="ffc-calendar-grid">
            <!-- En-têtes des jours -->
            <div class="ffc-calendar-day-header">Dim</div>
            <div class="ffc-calendar-day-header">Lun</div>
            <div class="ffc-calendar-day-header">Mar</div>
            <div class="ffc-calendar-day-header">Mer</div>
            <div class="ffc-calendar-day-header">Jeu</div>
            <div class="ffc-calendar-day-header">Ven</div>
            <div class="ffc-calendar-day-header">Sam</div>
            
            <?php
            // Jours vides du début
            for ($i = 0; $i < $first_day; $i++) {
                echo '<div class="ffc-calendar-day other-month"></div>';
            }
            
            // Jours du mois
            for ($day = 1; $day <= $days_in_month; $day++) {
                $is_today = (date('Y-m-d') == date('Y-m-d', strtotime("$year-$month-$day")));
                $class = $is_today ? 'ffc-calendar-day today' : 'ffc-calendar-day';
                
                echo "<div class='$class'>";
                echo "<strong>$day</strong>";
                
                if (isset($events_by_day[$day])) {
                    foreach ($events_by_day[$day] as $event) {
                        $title = wp_trim_words($event->post_title, 4);
                        echo "<div class='ffc-calendar-event' title='" . esc_attr($event->post_title) . "'>$title</div>";
                    }
                }
                
                echo "</div>";
            }
            
            // Compléter la grille
            $total_cells = $first_day + $days_in_month;
            $remaining = 42 - $total_cells; // 6 semaines * 7 jours
            for ($i = 0; $i < $remaining; $i++) {
                echo '<div class="ffc-calendar-day other-month"></div>';
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Test direct
if (!empty($_GET['test_calendar'])) {
    $month = $_GET['ffc_month'] ?? date('n');
    $year = $_GET['ffc_year'] ?? date('Y');
    echo generate_ffc_calendar($month, $year);
}
?>