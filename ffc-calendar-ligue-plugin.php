<?php
/**
 * Plugin Name: Calendrier FFC Ligue Normandie
 * Description: Calendrier personnalisé pour les événements FFCOrientation Normandie
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

class FFCCalendarLigueNormandie {
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_shortcode('ffc_calendar_ligue', [$this, 'calendar_shortcode']);
    }
    
    public function init() {
        // Plugin initialisé
    }
    
    public function calendar_shortcode($atts) {
        $atts = shortcode_atts([
            'month' => $_GET['ffc_month'] ?? date('n'),
            'year' => $_GET['ffc_year'] ?? date('Y')
        ], $atts);
        
        return $this->generate_calendar($atts['month'], $atts['year']);
    }
    
    private function generate_calendar($month, $year) {
        global $wpdb;
        
        // Récupérer événements FFC
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
        
        $first_day = date('w', strtotime($start_date));
        $days_in_month = date('t', strtotime($start_date));
        
        // Noms des mois en français
        $months_fr = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        $month_name = $months_fr[(int)$month] . ' ' . $year;
        
        $prev_month = $month == 1 ? 12 : $month - 1;
        $prev_year = $month == 1 ? $year - 1 : $year;
        $next_month = $month == 12 ? 1 : $month + 1;
        $next_year = $month == 12 ? $year + 1 : $year;
        
        ob_start();
        ?>
        <div class="ffc-calendar-ligue">
            <style>
            .ffc-calendar-ligue {
                max-width: 100%;
                font-family: 'Roboto', Arial, sans-serif;
                margin: 20px 0;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                border-radius: 12px;
                overflow: hidden;
            }
            .ffc-header-ligue {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                padding: 20px;
                text-align: center;
                font-size: 22px;
                font-weight: 700;
                text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            }
            .ffc-nav-ligue {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 20px;
                background: #f8fafc;
                color: #1e3a8a;
                border-bottom: 2px solid #e2e8f0;
            }
            .ffc-nav-btn-ligue {
                background: white;
                color: #1e3a8a;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 25px;
                font-size: 14px;
                font-weight: 600;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(30,58,138,0.1);
                border: 2px solid #3b82f6;
            }
            .ffc-nav-btn-ligue:hover {
                background: #3b82f6;
                color: white;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(59,130,246,0.3);
            }
            .ffc-nav-ligue span {
                font-weight: 700;
                font-size: 16px;
                color: #1e3a8a;
            }
            .ffc-grid-ligue {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                background: white;
            }
            .ffc-day-header-ligue {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                padding: 12px 5px;
                text-align: center;
                font-weight: 700;
                font-size: 13px;
                border-right: 1px solid #1e40af;
            }
            .ffc-day-header-ligue:last-child {
                border-right: none;
            }
            .ffc-day-ligue {
                min-height: 90px;
                padding: 8px;
                border-right: 1px solid #e2e8f0;
                border-bottom: 1px solid #e2e8f0;
                background: white;
                transition: background-color 0.2s ease;
            }
            .ffc-day-ligue:hover {
                background: #f8fafc;
            }
            .ffc-day-ligue:last-child {
                border-right: none;
            }
            .ffc-day-ligue.today {
                background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
                border: 2px solid #3b82f6;
            }
            .ffc-day-ligue.other-month {
                background: #f8f9fa;
                color: #a0aec0;
            }
            .ffc-day-ligue > div:first-child {
                font-weight: 700;
                margin-bottom: 5px;
                color: #1e3a8a;
                font-size: 14px;
            }
            .ffc-day-ligue.today > div:first-child {
                color: #1e40af;
            }
            .ffc-event-ligue {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white !important;
                padding: 3px 6px;
                margin: 2px 0;
                border-radius: 6px;
                font-size: 10px;
                line-height: 1.3;
                cursor: pointer;
                transition: all 0.2s ease;
                box-shadow: 0 1px 3px rgba(30,58,138,0.3);
                font-weight: 500;
                text-decoration: none !important;
                display: block;
            }
            .ffc-event-ligue:hover {
                background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
                transform: translateY(-1px);
                box-shadow: 0 2px 6px rgba(37,99,235,0.4);
                color: white !important;
                text-decoration: none !important;
            }
            .ffc-event-ligue:visited {
                color: white !important;
            }
            .ffc-event-ligue:active {
                color: white !important;
            }
            @media (max-width: 768px) {
                .ffc-header-ligue {
                    font-size: 18px;
                    padding: 15px;
                }
                .ffc-nav-ligue {
                    padding: 10px 15px;
                }
                .ffc-nav-ligue span {
                    font-size: 14px;
                }
                .ffc-nav-btn-ligue {
                    padding: 8px 15px;
                    font-size: 12px;
                }
                .ffc-day-ligue {
                    min-height: 70px;
                    padding: 5px;
                }
                .ffc-event-ligue {
                    font-size: 9px;
                    padding: 2px 4px;
                }
            }
            </style>
            
            <div class="ffc-header-ligue">
                <?php echo $month_name; ?>
            </div>
            
            <div class="ffc-nav-ligue">
                <a href="?ffc_month=<?php echo $prev_month; ?>&ffc_year=<?php echo $prev_year; ?>" class="ffc-nav-btn-ligue">‹ Précédent</a>
                <span></span>
                <a href="?ffc_month=<?php echo $next_month; ?>&ffc_year=<?php echo $next_year; ?>" class="ffc-nav-btn-ligue">Suivant ›</a>
            </div>
            
            <div class="ffc-grid-ligue">
                <div class="ffc-day-header-ligue">Dim</div>
                <div class="ffc-day-header-ligue">Lun</div>
                <div class="ffc-day-header-ligue">Mar</div>
                <div class="ffc-day-header-ligue">Mer</div>
                <div class="ffc-day-header-ligue">Jeu</div>
                <div class="ffc-day-header-ligue">Ven</div>
                <div class="ffc-day-header-ligue">Sam</div>
                
                <?php
                // Jours vides début
                for ($i = 0; $i < $first_day; $i++) {
                    echo '<div class="ffc-day-ligue other-month"></div>';
                }
                
                // Jours du mois
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $is_today = (date('Y-m-d') == date('Y-m-d', strtotime("$year-$month-$day")));
                    $class = $is_today ? 'ffc-day-ligue today' : 'ffc-day-ligue';
                    
                    echo "<div class='$class'>";
                    echo "<div style='font-weight:bold;margin-bottom:3px;'>$day</div>";
                    
                    if (isset($events_by_day[$day])) {
                        foreach ($events_by_day[$day] as $event) {
                            $title = wp_trim_words($event->post_title, 3);
                            $event_url = get_post_meta($event->ID, '_EventURL', true);
                            
                            if ($event_url) {
                                echo "<a href='$event_url' target='_blank' class='ffc-event-ligue' title='" . esc_attr($event->post_title) . "'>$title</a>";
                            } else {
                                echo "<div class='ffc-event-ligue' title='" . esc_attr($event->post_title) . "'>$title</div>";
                            }
                        }
                    }
                    
                    echo "</div>";
                }
                
                // Compléter grille
                $total_cells = $first_day + $days_in_month;
                $remaining = (ceil($total_cells / 7) * 7) - $total_cells;
                for ($i = 0; $i < $remaining; $i++) {
                    echo '<div class="ffc-day-ligue other-month"></div>';
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialiser le plugin
new FFCCalendarLigueNormandie();
?>