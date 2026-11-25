<?php
if (!defined('ABSPATH')) exit;

class FFCCalendarDB {

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

    public static function delete_future_events(): void {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM `{$table}` WHERE event_date >= CURDATE()");
    }

    public static function cleanup_old_events(): void {
        global $wpdb;
        $table = self::get_table_name(); // Trusted: WP prefix + literal suffix
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM `{$table}` WHERE event_date < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)");
    }

    public static function count_events(): int {
        global $wpdb;
        $table = self::get_table_name(); // Trusted: WP prefix + literal suffix
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
