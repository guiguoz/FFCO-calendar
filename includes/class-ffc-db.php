<?php
if (!defined('ABSPATH')) exit;

class FFCCalendarDB {

    /**
     * Nombre de mois d'historique conservés en base.
     *
     * La source CN publie une saison entière (année civile) pour le même coût
     * qu'un seul mois : conserver 18 mois permet d'afficher toute la saison en
     * cours plus la fin de la précédente. À ajuster ici uniquement — la synchro
     * s'aligne automatiquement sur cette valeur.
     */
    const RETENTION_MONTHS = 18;

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ffc_events';
    }

    public static function create_table() {
        global $wpdb;
        $table          = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          INT          NOT NULL AUTO_INCREMENT,
            ffco_id     VARCHAR(50)  NOT NULL,
            event_date  DATE         NOT NULL,
            title       VARCHAR(255) NOT NULL,
            location    VARCHAR(255) NOT NULL DEFAULT '',
            department  VARCHAR(5)   NOT NULL DEFAULT '',
            is_national TINYINT(1)   NOT NULL DEFAULT 0,
            discipline  VARCHAR(100) NOT NULL DEFAULT '',
            category    VARCHAR(100) NOT NULL DEFAULT '',
            event_url   VARCHAR(500) NOT NULL DEFAULT '',
            status      VARCHAR(20)  NOT NULL DEFAULT 'OK',
            synced_at   DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ffco_id (ffco_id),
            KEY event_date (event_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function upsert_event(array $data): void {
        global $wpdb;
        $table = self::get_table_name();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (ffco_id, event_date, title, location, department, is_national,
                 discipline, category, event_url, status, synced_at)
             VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                event_date  = VALUES(event_date),
                title       = VALUES(title),
                location    = VALUES(location),
                department  = VALUES(department),
                is_national = VALUES(is_national),
                discipline  = VALUES(discipline),
                category    = VALUES(category),
                event_url   = VALUES(event_url),
                status      = VALUES(status),
                synced_at   = VALUES(synced_at)",
            $data['ffco_id'],
            $data['event_date'],
            $data['title'],
            $data['location']    ?? '',
            $data['department']  ?? '',
            empty($data['is_national']) ? 0 : 1,
            $data['discipline']  ?? '',
            $data['category']    ?? '',
            $data['event_url']   ?? '',
            $data['status']      ?? 'OK',
            current_time('mysql')
        ));
    }

    /** @return object[] */
    public static function get_events_for_month(int $month, int $year): array {
        global $wpdb;
        $table      = self::get_table_name();
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date   = date('Y-m-t', strtotime($start_date));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE event_date BETWEEN %s AND %s
             ORDER BY event_date ASC, is_national DESC",
            $start_date,
            $end_date
        ));
    }

    /**
     * Mois présents en base, du plus ancien au plus récent.
     * Sert à orienter le visiteur quand le mois affiché ne contient aucune course :
     * le calendrier fédéral s'arrête à la dernière course publiée, et la saison
     * suivante n'est pas toujours en ligne.
     *
     * @return array<int, array{year:int, month:int, nb:int}>
     */
    public static function get_months_with_events(): array {
        global $wpdb;
        $table = self::get_table_name();
        $rows  = $wpdb->get_results(
            "SELECT YEAR(event_date) AS y, MONTH(event_date) AS m, COUNT(*) AS nb
               FROM $table
              GROUP BY YEAR(event_date), MONTH(event_date)
              ORDER BY y ASC, m ASC"
        );

        $months = [];
        foreach ((array) $rows as $row) {
            $months[] = [
                'year'  => (int) $row->y,
                'month' => (int) $row->m,
                'nb'    => (int) $row->nb,
            ];
        }
        return $months;
    }

    public static function delete_future_events(): void {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM `{$table}` WHERE event_date >= CURDATE()");
    }

    /**
     * Supprime tous les événements d'une année civile.
     *
     * La source CN publie une saison entière d'un coup : la synchro remplace
     * donc l'année complète, courses passées comprises, plutôt que de ne purger
     * que le futur. Cela évite les doublons lorsqu'une même course change
     * d'identifiant entre deux sources.
     */
    public static function delete_events_for_year(int $year): void {
        global $wpdb;
        $table = self::get_table_name(); // Trusted: WP prefix + literal suffix
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE YEAR(event_date) = %d", $year));
    }

    public static function cleanup_old_events(): void {
        global $wpdb;
        $table = self::get_table_name(); // Trusted: WP prefix + literal suffix
        $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE FROM `{$table}` WHERE event_date < DATE_SUB(CURDATE(), INTERVAL %d MONTH)",
            self::RETENTION_MONTHS
        ));
    }

    public static function count_events(): int {
        global $wpdb;
        $table = self::get_table_name(); // Trusted: WP prefix + literal suffix
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
