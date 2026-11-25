<?php
/**
 * Plugin Name: Liste Événements Ligue Normandie
 * Description: Affiche les prochains événements sous forme de liste pour la page d'accueil
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

class FFCEventsListLigueNormandie {
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_shortcode('ffc_events_list', [$this, 'events_list_shortcode']);
    }
    
    public function init() {
        // Plugin initialisé
    }
    
    public function events_list_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 5
        ], $atts);
        
        return $this->generate_events_list($atts['limit']);
    }
    
    private function generate_events_list($limit) {
        global $wpdb;
        
        // Récupérer les prochains événements FFC
        $today = date('Y-m-d');
        
        $events = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as event_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE p.post_type = 'tribe_events'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_EventStartDate'
            AND pm2.meta_key = 'ffc_source_url'
            AND DATE(pm.meta_value) >= %s
            ORDER BY pm.meta_value ASC
            LIMIT %d
        ", $today, $limit));
        
        if (empty($events)) {
            return '<p>Aucun événement à venir.</p>';
        }
        
        ob_start();
        ?>
        <div class="ffc-events-list">
            <style>
            .ffc-events-list {
                font-family: 'Roboto', Arial, sans-serif;
                margin: 20px 0;
            }
            .ffc-events-list h3 {
                color: #1e3a8a;
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 20px;
                border-bottom: 3px solid #3b82f6;
                padding-bottom: 10px;
            }
            .ffc-event-item {
                display: flex;
                align-items: center;
                padding: 15px;
                margin-bottom: 10px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: all 0.3s ease;
                text-decoration: none;
                color: inherit;
            }
            .ffc-event-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(59,130,246,0.2);
                text-decoration: none;
                color: inherit;
            }
            .ffc-event-date {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                padding: 10px 15px;
                border-radius: 8px;
                text-align: center;
                margin-right: 20px;
                min-width: 80px;
                font-weight: 700;
            }
            .ffc-event-date .day {
                font-size: 24px;
                line-height: 1;
                display: block;
            }
            .ffc-event-date .month {
                font-size: 12px;
                text-transform: uppercase;
                opacity: 0.9;
            }
            .ffc-event-details {
                flex: 1;
            }
            .ffc-event-title {
                font-size: 18px;
                font-weight: 600;
                color: #1e3a8a;
                margin-bottom: 5px;
                line-height: 1.3;
            }
            .ffc-event-location {
                font-size: 14px;
                color: #6b7280;
                margin-bottom: 3px;
            }
            .ffc-event-organizer {
                font-size: 13px;
                color: #3b82f6;
                font-weight: 500;
            }
            .ffc-view-all {
                text-align: center;
                margin-top: 20px;
            }
            .ffc-view-all a {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                padding: 12px 25px;
                text-decoration: none;
                border-radius: 25px;
                font-weight: 600;
                transition: all 0.3s ease;
                display: inline-block;
            }
            .ffc-view-all a:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(59,130,246,0.3);
                color: white;
                text-decoration: none;
            }
            @media (max-width: 768px) {
                .ffc-event-item {
                    flex-direction: column;
                    text-align: center;
                }
                .ffc-event-date {
                    margin-right: 0;
                    margin-bottom: 15px;
                }
                .ffc-event-title {
                    font-size: 16px;
                }
            }
            </style>
            
            <h3>📅 Prochains Événements</h3>
            
            <?php
            $months_fr = [
                1 => 'Jan', 2 => 'Fév', 3 => 'Mar', 4 => 'Avr',
                5 => 'Mai', 6 => 'Juin', 7 => 'Juil', 8 => 'Août',
                9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Déc'
            ];
            
            foreach ($events as $event) {
                $event_date = new DateTime($event->event_date);
                $day = $event_date->format('d');
                $month = $months_fr[(int)$event_date->format('n')];
                
                $event_url = get_post_meta($event->ID, '_EventURL', true);
                $organizer = get_post_meta($event->ID, 'ffc_organizer_name', true) ?: 'FFCOrientation';
                
                // Extraire titre et lieu
                $full_title = $event->post_title;
                $title_parts = explode(' - ', $full_title, 2);
                $title = $title_parts[0];
                $location = isset($title_parts[1]) ? $title_parts[1] : '';
                
                if ($event_url) {
                    echo "<a href='$event_url' target='_blank' class='ffc-event-item'>";
                } else {
                    echo "<div class='ffc-event-item'>";
                }
                ?>
                    <div class="ffc-event-date">
                        <span class="day"><?php echo $day; ?></span>
                        <span class="month"><?php echo $month; ?></span>
                    </div>
                    <div class="ffc-event-details">
                        <div class="ffc-event-title"><?php echo esc_html($title); ?></div>
                        <?php if ($location): ?>
                            <div class="ffc-event-location">📍 <?php echo esc_html($location); ?></div>
                        <?php endif; ?>
                        <div class="ffc-event-organizer">👥 <?php echo esc_html($organizer); ?></div>
                    </div>
                <?php
                if ($event_url) {
                    echo "</a>";
                } else {
                    echo "</div>";
                }
            }
            ?>
            
            <div class="ffc-view-all">
                <a href="/calendrier/">Voir tous les événements</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialiser le plugin
new FFCEventsListLigueNormandie();
?>