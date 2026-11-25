<?php
if (!defined('ABSPATH')) exit;

class FFCCalendarDisplay {

    private const MONTHS_FR = [
        1 => 'Janvier',  2 => 'Février',   3 => 'Mars',
        4 => 'Avril',    5 => 'Mai',        6 => 'Juin',
        7 => 'Juillet',  8 => 'Août',       9 => 'Septembre',
        10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function register_styles(): void {
        wp_register_style('ffc-calendar-ligue', false);
        wp_add_inline_style('ffc-calendar-ligue', $this->get_css());
    }

    public function calendar_shortcode($atts): string {
        if (!is_array($atts)) {
            $atts = [];
        }
        $atts  = shortcode_atts([
            'month' => isset($_GET['ffc_month']) ? absint($_GET['ffc_month']) : (int) date('n'),
            'year'  => isset($_GET['ffc_year'])  ? absint($_GET['ffc_year'])  : (int) date('Y'),
        ], $atts);

        $month = max(1, min(12, (int) $atts['month']));
        $year  = max(2000, min(2100, (int) $atts['year']));

        return $this->render($month, $year);
    }

    private function render(int $month, int $year): string {
        $events = FFCCalendarDB::get_events_for_month($month, $year);

        $by_day = [];
        foreach ($events as $ev) {
            $by_day[(int) date('j', strtotime($ev->event_date))][] = $ev;
        }

        $start_date    = sprintf('%04d-%02d-01', $year, $month);
        $first_dow     = (int) date('w', strtotime($start_date)); // 0=Sun
        $days_in_month = (int) date('t', strtotime($start_date));
        $today         = date('Y-m-d');
        $month_label   = self::MONTHS_FR[$month] . ' ' . $year;

        [$prev_month, $prev_year] = $month === 1  ? [12, $year - 1] : [$month - 1, $year];
        [$next_month, $next_year] = $month === 12 ? [1,  $year + 1] : [$month + 1, $year];

        wp_enqueue_style('ffc-calendar-ligue');

        ob_start();
        ?>
        <div class="ffc-calendar-ligue">

            <div class="ffc-header-ligue"><?php echo esc_html($month_label); ?></div>

            <div class="ffc-nav-ligue">
                <a href="<?php echo esc_url(add_query_arg(['ffc_month' => $prev_month, 'ffc_year' => $prev_year])); ?>"
                   class="ffc-nav-btn-ligue">&#8249; Précédent</a>
                <span></span>
                <a href="<?php echo esc_url(add_query_arg(['ffc_month' => $next_month, 'ffc_year' => $next_year])); ?>"
                   class="ffc-nav-btn-ligue">Suivant &#8250;</a>
            </div>

            <div class="ffc-legend-ligue">
                <span class="ffc-legend-item ffc-legend-regional">Normandie (rég./dép.)</span>
                <span class="ffc-legend-item ffc-legend-national">Compétition nationale</span>
            </div>

            <?php if (empty($events)) : ?>
                <?php echo $this->render_empty_notice($month, $year); // déjà échappé ?>
            <?php endif; ?>

            <div class="ffc-grid-ligue">
                <?php foreach (['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'] as $d): ?>
                    <div class="ffc-day-header-ligue"><?php echo esc_html($d); ?></div>
                <?php endforeach; ?>

                <?php
                // Leading empty cells
                for ($i = 0; $i < $first_dow; $i++):
                    echo '<div class="ffc-day-ligue other-month"></div>';
                endfor;

                // Day cells
                for ($day = 1; $day <= $days_in_month; $day++):
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $is_today = ($date_str === $today);
                    ?>
                    <div class="ffc-day-ligue<?php echo $is_today ? ' today' : ''; ?>">
                        <div class="ffc-day-number"><?php echo $day; ?></div>
                        <?php if (isset($by_day[$day])): ?>
                            <?php foreach ($by_day[$day] as $ev): ?>
                                <?php
                                $label    = wp_trim_words($ev->title, 3, '…');
                                $tip      = esc_attr($ev->title . ($ev->location ? ' — ' . $ev->location : ''));
                                $css      = 'ffc-event-ligue' . ($ev->is_national ? ' ffc-event-national' : '');
                                ?>
                                <?php if ($ev->event_url): ?>
                                    <a href="<?php echo esc_url($ev->event_url); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="<?php echo esc_attr($css); ?>"
                                       title="<?php echo esc_attr($tip); ?>"><?php echo esc_html($label); ?></a>
                                <?php else: ?>
                                    <div class="<?php echo esc_attr($css); ?>"
                                         title="<?php echo esc_attr($tip); ?>"><?php echo esc_html($label); ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>

                <?php
                // Trailing empty cells
                $total     = $first_dow + $days_in_month;
                $remaining = (int) ceil($total / 7) * 7 - $total;
                for ($i = 0; $i < $remaining; $i++):
                    echo '<div class="ffc-day-ligue other-month"></div>';
                endfor;
                ?>
            </div><!-- .ffc-grid-ligue -->

        </div><!-- .ffc-calendar-ligue -->
        <?php
        return ob_get_clean();
    }

    /**
     * Message affiché quand le mois demandé ne contient aucune course.
     *
     * Sans lui, le visiteur voit une grille entièrement vide et conclut que la
     * synchronisation est cassée — alors que le calendrier fédéral s'arrête
     * simplement à la dernière course publiée, la saison suivante n'étant pas
     * encore en ligne sur cn.ffcorientation.fr.
     */
    private function render_empty_notice(int $month, int $year): string {
        $months = FFCCalendarDB::get_months_with_events();

        if (empty($months)) {
            return '<div class="ffc-empty-ligue">'
                . '<strong>Aucune course dans le calendrier.</strong>'
                . ' La synchronisation n\'a pas encore alimenté la base. '
                . 'Lancez « Synchroniser maintenant » dans Réglages → Calendrier FFC.'
                . '</div>';
        }

        $current = $year * 12 + $month;
        $prev    = null;
        $next    = null;
        foreach ($months as $m) {
            $key = $m['year'] * 12 + $m['month'];
            if ($key < $current && ($prev === null || $key > $prev['year'] * 12 + $prev['month'])) {
                $prev = $m;
            }
            if ($key > $current && ($next === null || $key < $next['year'] * 12 + $next['month'])) {
                $next = $m;
            }
        }
        $last = $months[count($months) - 1];

        $out   = '<div class="ffc-empty-ligue">';
        $out  .= '<strong>Aucune course en ' . esc_html(self::MONTHS_FR[$month] . ' ' . $year) . '.</strong>';

        if ($prev !== null) {
            $out .= $this->month_link($prev, 'Dernières courses : %s');
        }
        if ($next !== null) {
            $out .= $this->month_link($next, 'Prochaines courses : %s');
        }
        if ($next === null) {
            $out .= ' Le calendrier fédéral s\'arrête à '
                . esc_html(self::MONTHS_FR[$last['month']] . ' ' . $last['year'])
                . ' : la saison suivante n\'est pas encore publiée sur cn.ffcorientation.fr. '
                . 'Elle apparaîtra ici dès sa publication, sans intervention de votre part.';
        }

        return $out . '</div>';
    }

    /** Lien vers un mois donné, avec son nombre de courses. */
    private function month_link(array $m, string $label): string {
        $text = sprintf(
            $label,
            self::MONTHS_FR[$m['month']] . ' ' . $m['year'] . ' (' . $m['nb'] . ')'
        );
        return ' <a class="ffc-empty-link" href="'
            . esc_url(add_query_arg(['ffc_month' => $m['month'], 'ffc_year' => $m['year']]))
            . '">' . esc_html($text) . '</a>';
    }

    private function get_css(): string {
        return '
.ffc-calendar-ligue{max-width:100%;font-family:"Roboto",Arial,sans-serif;margin:20px 0;box-shadow:0 4px 15px rgba(0,0,0,.1);border-radius:12px;overflow:hidden}
.ffc-header-ligue{background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%);color:#fff;padding:20px;text-align:center;font-size:22px;font-weight:700;text-shadow:0 2px 4px rgba(0,0,0,.3)}
.ffc-nav-ligue{display:flex;justify-content:space-between;align-items:center;padding:15px 20px;background:#f8fafc;border-bottom:2px solid #e2e8f0}
.ffc-nav-btn-ligue{background:#fff;color:#1e3a8a;padding:10px 20px;text-decoration:none;border-radius:25px;font-size:14px;font-weight:600;transition:all .3s;box-shadow:0 2px 8px rgba(30,58,138,.1);border:2px solid #3b82f6}
.ffc-nav-btn-ligue:hover{background:#3b82f6;color:#fff;transform:translateY(-2px);box-shadow:0 4px 12px rgba(59,130,246,.3)}
.ffc-legend-ligue{display:flex;gap:16px;padding:8px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12px;flex-wrap:wrap;color:#444}
.ffc-legend-item{display:flex;align-items:center;gap:6px}
.ffc-legend-item::before{content:"";display:inline-block;width:12px;height:12px;border-radius:3px}
.ffc-legend-regional::before{background:linear-gradient(135deg,#1e3a8a,#3b82f6)}
.ffc-legend-national::before{background:linear-gradient(135deg,#c05621,#ed8936)}
.ffc-empty-ligue{padding:16px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;color:#78350f;font-size:14px;line-height:1.6}
.ffc-empty-ligue strong{display:block;margin-bottom:4px;font-size:15px;color:#92400e}
.ffc-empty-link{display:inline-block;margin:2px 8px 2px 0;padding:5px 12px;background:#fff;color:#92400e;border:1px solid #f59e0b;border-radius:20px;font-weight:600;font-size:13px;text-decoration:none}
.ffc-empty-link:hover{background:#f59e0b;color:#fff;text-decoration:none}
.ffc-grid-ligue{display:grid;grid-template-columns:repeat(7,1fr);background:#fff}
.ffc-day-header-ligue{background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%);color:#fff;padding:12px 5px;text-align:center;font-weight:700;font-size:13px;border-right:1px solid #1e40af}
.ffc-day-header-ligue:last-child{border-right:none}
.ffc-day-ligue{min-height:90px;padding:8px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;background:#fff;transition:background .2s}
.ffc-day-ligue:hover{background:#f8fafc}
.ffc-day-ligue:last-child{border-right:none}
.ffc-day-ligue.today{background:linear-gradient(135deg,#dbeafe 0%,#bfdbfe 100%);border:2px solid #3b82f6}
.ffc-day-ligue.other-month{background:#f8f9fa}
.ffc-day-number{font-weight:700;margin-bottom:5px;color:#1e3a8a;font-size:14px}
.ffc-day-ligue.today .ffc-day-number{color:#1e40af}
.ffc-event-ligue{background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%);color:#fff!important;padding:3px 6px;margin:2px 0;border-radius:6px;font-size:10px;line-height:1.3;transition:all .2s;box-shadow:0 1px 3px rgba(30,58,138,.3);font-weight:500;text-decoration:none!important;display:block;cursor:pointer}
.ffc-event-ligue:hover{background:linear-gradient(135deg,#1e40af 0%,#2563eb 100%);transform:translateY(-1px);box-shadow:0 2px 6px rgba(37,99,235,.4);color:#fff!important;text-decoration:none!important}
.ffc-event-ligue:visited,.ffc-event-ligue:active{color:#fff!important}
.ffc-event-national{background:linear-gradient(135deg,#c05621 0%,#ed8936 100%)!important;box-shadow:0 1px 3px rgba(192,86,33,.3)!important}
.ffc-event-national:hover{background:linear-gradient(135deg,#9c4221 0%,#dd6b20 100%)!important;box-shadow:0 2px 6px rgba(192,86,33,.4)!important}
@media(max-width:768px){
 .ffc-header-ligue{font-size:18px;padding:15px}
 .ffc-nav-ligue{padding:10px 15px}
 .ffc-nav-btn-ligue{padding:8px 15px;font-size:12px}
 .ffc-day-ligue{min-height:70px;padding:5px}
 .ffc-event-ligue{font-size:9px;padding:2px 4px}
}';
    }
}
