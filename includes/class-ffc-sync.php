<?php
if (!defined('ABSPATH')) exit;

class FFCCalendarSync {

    const FFCO_BASE_URL  = 'https://www.ffcorientation.fr';
    const FFCO_AGENDA    = 'https://www.ffcorientation.fr/courses/agenda/';
    const NORMANDIE_DEPTS = ['14', '27', '50', '61', '76'];
    const MAX_PAGES      = 20;

    private array $log = [];

    public function sync(): void {
        $events = $this->fetch_all_events();

        if ($events === false) {
            $this->add_log('ERREUR : Aucun endpoint FFCO accessible — données existantes conservées.');
            $this->save_log();
            return;
        }

        $filtered = $this->filter_normandie($events);
        $this->add_log(sprintf(
            'Récupérés : %d événements total → %d après filtrage Normandie + national.',
            count($events),
            count($filtered)
        ));

        // Purge future events before reinserting — prevents stale data from broken syncs
        FFCCalendarDB::delete_future_events();

        foreach ($filtered as $event) {
            FFCCalendarDB::upsert_event($event);
        }

        FFCCalendarDB::cleanup_old_events();
        $this->add_log(sprintf('%d événements en base après sync.', count($filtered)));
        update_option('ffc_last_sync', current_time('mysql'));
        $this->save_log();
    }

    // -------------------------------------------------------------------------
    // Fetch orchestration
    // -------------------------------------------------------------------------

    /** @return array|false */
    private function fetch_all_events() {
        $saved_method = get_option('ffc_fetch_method', 'auto');

        if ($saved_method !== 'auto') {
            $events = $this->fetch_with_method($saved_method);
            if ($events !== false) {
                // Method works — return even if empty (e.g., off-season)
                return $events;
            }
            // Saved method completely failed (network/format error): reset and re-detect
            delete_option('ffc_fetch_method');
            $this->add_log("Méthode '{$saved_method}' a échoué, redétection...");
        }

        foreach (['ics', 'csv', 'html'] as $method) {
            $events = $this->fetch_with_method($method);
            if ($events !== false) {
                update_option('ffc_fetch_method', $method);
                $this->add_log("Méthode retenue : {$method}.");
                return $events;
            }
        }

        return false;
    }

    /** @return array|false */
    private function fetch_with_method(string $method) {
        switch ($method) {
            case 'ics':  return $this->fetch_ics();
            case 'csv':  return $this->fetch_csv();
            case 'html': return $this->fetch_html();
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // ICS
    // -------------------------------------------------------------------------

    /** @return array|false  Returns [] if ICS works but has no events; false if endpoint not available. */
    private function fetch_ics() {
        $today = date('Y-m-d');
        $urls  = [
            self::FFCO_AGENDA . '?format=ics&du=' . $today,
            self::FFCO_AGENDA . '?format=ics',
        ];

        foreach ($urls as $url) {
            $body = $this->http_get($url);
            if ($body && strpos($body, 'BEGIN:VCALENDAR') !== false) {
                return $this->parse_ics($body); // [] is a valid "method works, no events" result
            }
        }

        return false;
    }

    private function parse_ics(string $content): array {
        $events = [];
        // Unfold continuation lines (RFC 5545 §3.1)
        $content = preg_replace("/\r\n[ \t]/", '', $content);
        $content = preg_replace("/\n[ \t]/", '', $content);

        preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $content, $matches);
        foreach ($matches[1] as $block) {
            $event = $this->parse_vevent($block);
            if ($event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @return array|null */
    private function parse_vevent(string $block) {
        $fields = [];
        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line, "\r");
            if (empty($line) || strpos($line, ':') === false) {
                continue;
            }
            [$raw_key, $value] = explode(':', $line, 2);
            // Strip parameters (DTSTART;VALUE=DATE → DTSTART)
            $key          = strtok($raw_key, ';');
            $fields[$key] = $value;
        }

        if (empty($fields['DTSTART']) || empty($fields['SUMMARY'])) {
            return null;
        }

        // Date: strip time/TZ component then parse YYYYMMDD or YYYY-MM-DD
        $date_raw = preg_replace('/[TZ].*/', '', $fields['DTSTART']);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date_raw, $dm)) {
            $event_date = $dm[1] . '-' . $dm[2] . '-' . $dm[3];
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw)) {
            $event_date = $date_raw;
        } else {
            return null;
        }

        $url      = trim($fields['URL'] ?? '');
        $location = $this->unescape_ical($fields['LOCATION'] ?? '');
        $desc     = $this->unescape_ical($fields['DESCRIPTION'] ?? '');
        $summary  = $this->unescape_ical($fields['SUMMARY']);

        $ffco_id = $this->extract_ffco_id($url) ?? 'ics_' . md5($event_date . $summary);

        $is_national = $this->is_truly_national($summary);

        $department = $this->normalize_department($location);

        return [
            'ffco_id'     => sanitize_text_field($ffco_id),
            'event_date'  => $event_date,
            'title'       => sanitize_text_field($summary),
            'location'    => sanitize_text_field($location),
            'department'  => $department,
            'is_national' => $is_national ? 1 : 0,
            'discipline'  => '',
            'category'    => '',
            'event_url'   => esc_url_raw($url),
            'status'      => 'OK',
        ];
    }

    private function unescape_ical(string $value): string {
        return str_replace(['\\,', '\\;', '\\n', '\\N'], [',', ';', ' ', ' '], $value);
    }

    // -------------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------------

    /** @return array|false  Returns [] if CSV works but has no events; false if endpoint not available. */
    private function fetch_csv() {
        $today = date('Y-m-d');
        $urls  = [
            self::FFCO_BASE_URL . '/courses/agenda/export/?format=csv&du=' . $today,
            self::FFCO_BASE_URL . '/courses/agenda/?format=csv&du=' . $today, // alternate param location
        ];

        foreach ($urls as $url) {
            $body = $this->http_get($url);
            if (!$body) {
                continue;
            }
            $events = $this->parse_csv($body);
            if (!empty($events)) {
                return $events;
            }
        }

        return false;
    }

    private function parse_csv(string $content): array {
        // FFCO exports in Windows-1252 — convert to UTF-8 before parsing
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $events = [];
        $lines  = preg_split('/\r?\n/', trim($content));
        if (count($lines) < 2) {
            return $events;
        }

        // Detect separator: semicolon is common in French CSV exports
        $header_line = $lines[0];
        $sep = (substr_count($header_line, ';') >= substr_count($header_line, ',')) ? ';' : ',';

        $headers = array_map(
            fn($h) => mb_strtolower(trim($h, " \t\r\n\0\x0B\"\xEF\xBB\xBF")),
            str_getcsv($header_line, $sep)
        );

        // Column index resolver
        $col = function(array $aliases) use ($headers): int {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $headers, true);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return -1;
        };

        $c_date  = $col(['date']);
        $c_cn    = $col(['cn', 'national']);
        $c_nom   = $col(['nom', 'evenement', 'événement', 'name']);
        $c_lieu  = $col(['lieu', 'location']);
        $c_dept  = $col(['departement', 'département', 'dept', 'dep']);
        $c_spec  = $col(['specialite', 'spécialité', 'discipline']);
        $c_cat   = $col(['epreuve', 'épreuve', 'categorie', 'catégorie']);
        $c_url   = $col(['url', 'lien', 'site web', 'site']);
        $c_manif = $col(['manifestation', 'groupe', 'statut']);

        $this->add_log(sprintf(
            'CSV headers: %s | date=%d nom=%d lieu=%d dept=%d',
            implode(',', $headers), $c_date, $c_nom, $c_lieu, $c_dept
        ));

        if ($c_date < 0 || $c_nom < 0) {
            return $events; // Unrecognised format
        }

        $get = function(array $row, int $idx): string {
            return isset($row[$idx]) ? trim($row[$idx]) : '';
        };

        array_shift($lines); // Remove header row
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line, $sep);

            $date_raw = $get($row, $c_date);
            $event_date = $this->parse_date($date_raw);
            if (!$event_date) {
                continue;
            }

            $title = $get($row, $c_nom);
            if ($title === '') {
                continue;
            }

            $lieu       = $get($row, $c_lieu);
            $dept_raw   = $c_dept >= 0 ? $get($row, $c_dept) : '';
            $department = $dept_raw !== ''
                ? $this->normalize_dept_code($dept_raw)
                : $this->normalize_department($lieu);

            $is_national = $this->is_truly_national($title);

            // Priority: organizer site web (any domain allowed) → FFCO agenda page for that date
            $site_web  = $c_url >= 0 ? $get($row, $c_url) : '';
            $ffco_date = esc_url_raw(self::FFCO_AGENDA . '?du=' . $event_date . '&au=' . $event_date);
            $event_url = ($site_web && filter_var($site_web, FILTER_VALIDATE_URL))
                ? esc_url_raw($site_web)
                : $ffco_date;
            $ffco_id   = $this->extract_ffco_id($site_web) ?? 'csv_' . md5($event_date . $title);

            $manif  = $c_manif >= 0 ? mb_strtolower($get($row, $c_manif)) : '';
            $status = (strpos($manif, 'annul') !== false) ? 'annulée' : 'OK';

            $events[] = [
                'ffco_id'     => sanitize_text_field($ffco_id),
                'event_date'  => $event_date,
                'title'       => sanitize_text_field($title),
                'location'    => sanitize_text_field($lieu),
                'department'  => $department,
                'is_national' => $is_national ? 1 : 0,
                'discipline'  => sanitize_text_field($c_spec >= 0 ? $get($row, $c_spec) : ''),
                'category'    => sanitize_text_field($c_cat  >= 0 ? $get($row, $c_cat)  : ''),
                'event_url'   => $event_url,
                'status'      => $status,
            ];
        }

        return $events;
    }

    // -------------------------------------------------------------------------
    // HTML scraping (fallback)
    // -------------------------------------------------------------------------

    /** @return array|false */
    private function fetch_html() {
        $today  = date('Y-m-d');
        $events = [];
        $page   = 1;

        do {
            $url  = self::FFCO_AGENDA . '?du=' . $today . '&page=' . $page;
            $html = $this->http_get($url);

            if (!$html) {
                if ($page === 1) {
                    return false;
                }
                $this->add_log(sprintf(
                    'Avertissement : fetch HTML interrompu à la page %d — données partielles stockées.',
                    $page
                ));
                break;
            }

            $page_events = $this->parse_html_page($html);
            $events      = array_merge($events, $page_events);
            $has_next    = $this->html_has_next_page($html, $page);
            $page++;

        } while ($has_next && $page <= self::MAX_PAGES);

        return $events;
    }

    private function parse_html_page(string $html): array {
        $events = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find the events table: largest table whose first data row has ≥4 columns
        $best_table = null;
        $best_count = 0;
        foreach ($xpath->query('//table') as $table) {
            $first_data = $xpath->query('.//tr[not(.//th)]', $table)->item(0);
            if (!$first_data || $xpath->query('.//td', $first_data)->length < 4) {
                continue;
            }
            $count = $xpath->query('.//tr', $table)->length;
            if ($count > $best_count) {
                $best_count = $count;
                $best_table = $table;
            }
        }

        if (!$best_table || $best_count < 2) {
            return $events;
        }

        // Detect column indices from header row (more robust than hardcoded indices)
        $col_date  = 0;
        $col_cn    = 1;
        $col_event = 2;
        $col_lieu  = 3;
        $col_disc  = 4;
        $col_cat   = 5;

        $header_row = $xpath->query('.//tr[.//th]', $best_table)->item(0);
        if ($header_row) {
            foreach ($xpath->query('.//th', $header_row) as $idx => $th) {
                $txt = mb_strtolower(trim($th->textContent));
                if ($txt === 'date')                                          $col_date  = $idx;
                elseif ($txt === 'cn')                                        $col_cn    = $idx;
                elseif (str_contains($txt, 'vement') || $txt === 'course')   $col_event = $idx;
                elseif (str_contains($txt, 'lieu') || $txt === 'ville')      $col_lieu  = $idx;
                elseif (str_contains($txt, 'sp') || str_contains($txt, 'disc')) $col_disc = $idx;
                elseif (str_contains($txt, 'preuve') || $txt === 'cat')      $col_cat   = $idx;
            }
        }

        foreach ($xpath->query('.//tr', $best_table) as $row) {
            if ($xpath->query('.//th', $row)->length > 0) {
                continue; // Skip header rows
            }

            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 3) {
                continue;
            }

            // Date
            $date_raw   = trim($cells->item($col_date)->textContent ?? '');
            $event_date = $this->parse_date($date_raw);
            if (!$event_date) {
                continue;
            }

            // is_national is determined from the title after extraction (see below)

            // Event title + URL (use detected column index)
            $event_cell = $cells->item($col_event);
            $link_node  = $event_cell ? $xpath->query('.//a', $event_cell)->item(0) : null;
            $title      = $link_node
                ? trim($link_node->textContent)
                : ($event_cell ? trim($event_cell->textContent) : '');
            $href       = $link_node ? $link_node->getAttribute('href') : '';

            if ($title === '') {
                continue;
            }

            // National status determined from title only
            $is_national = $this->is_truly_national($title);

            $event_url = '';
            if ($href) {
                $candidate = (strpos($href, 'http') === 0)
                    ? $href
                    : self::FFCO_BASE_URL . $href;
                $event_url = $this->is_allowed_url($candidate) ? $candidate : '';
            }

            $ffco_id = $this->extract_ffco_id($event_url) ?? 'html_' . md5($event_date . $title);

            // Location & department (use detected column index)
            $lieu_cell  = $cells->item($col_lieu);
            $lieu       = $lieu_cell ? trim($lieu_cell->textContent) : '';
            $department = $this->normalize_department($lieu);

            // Discipline (images alt) and category
            $discipline = '';
            $category   = '';
            $disc_cell  = $cells->item($col_disc);
            if ($disc_cell) {
                $parts = [];
                foreach ($xpath->query('.//img', $disc_cell) as $img) {
                    $alt = $img->getAttribute('alt') ?: $img->getAttribute('title');
                    if ($alt) {
                        $parts[] = $alt;
                    }
                }
                $discipline = implode(', ', $parts) ?: trim($disc_cell->textContent);
            }
            $cat_cell = $cells->item($col_cat);
            if ($cat_cell) {
                $category = trim($cat_cell->textContent);
            }

            $events[] = [
                'ffco_id'     => sanitize_text_field($ffco_id),
                'event_date'  => $event_date,
                'title'       => sanitize_text_field($title),
                'location'    => sanitize_text_field($lieu),
                'department'  => $department,
                'is_national' => $is_national ? 1 : 0,
                'discipline'  => sanitize_text_field($discipline),
                'category'    => sanitize_text_field($category),
                'event_url'   => esc_url_raw($event_url),
                'status'      => 'OK',
            ];
        }

        return $events;
    }

    private function html_has_next_page(string $html, int $current_page): bool {
        // "Page N/M" pattern
        if (preg_match('/[Pp]age\s+' . $current_page . '\s*\/\s*(\d+)/', $html, $m)) {
            return $current_page < (int) $m[1];
        }
        // Link to next page
        return (bool) preg_match('/[?&]page=' . ($current_page + 1) . '/', $html);
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    private function filter_normandie(array $events): array {
        $cnt_national  = 0;
        $cnt_normandie = 0;
        $cnt_excluded  = 0;

        $result = array_values(array_filter($events, function(array $e) use (&$cnt_national, &$cnt_normandie, &$cnt_excluded): bool {
            if (!empty($e['is_national'])) {
                $cnt_national++;
                return true;
            }
            if (in_array($e['department'], self::NORMANDIE_DEPTS, true)) {
                $cnt_normandie++;
                return true;
            }
            if ($this->is_normandie_by_keyword($e['title'], $e['location'])) {
                $cnt_normandie++;
                return true;
            }
            $cnt_excluded++;
            // Log only September events so we can diagnose without 255 entries
            if (isset($e['event_date']) && substr($e['event_date'], 5, 2) === '09') {
                $this->add_log(sprintf(
                    'EXCLU-sept : "%s" | lieu="%s" | dept="%s"',
                    $e['title'], $e['location'], $e['department']
                ));
            }
            return false;
        }));

        $this->add_log(sprintf(
            'Filtre : %d nationaux + %d Normandie conservés, %d exclus (autres régions).',
            $cnt_national, $cnt_normandie, $cnt_excluded
        ));

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true only for genuinely national-level competitions (open to all France).
     * We intentionally ignore the FFCO CN flag because it includes ligue-level qualifying
     * events from any region that the user does not want to see.
     */
    private function is_truly_national(string $title): bool {
        return (bool) preg_match(
            '/Championnat[s]?\s+de\s+France|Crit[eé]rium\s+[Nn]ational|Coupe\s+de\s+France|Interr[eé]gionaux?\s+/iu',
            $title
        );
    }

    private function normalize_department(string $lieu): string {
        // Strategy 1: explicit "(14)" or "(076)" pattern
        if (preg_match('/\((\d{2,3})\)/', $lieu, $m)) {
            $code = $this->normalize_dept_code($m[1]);
            $num  = (int) $code;
            if ($code !== '' && (($num >= 1 && $num <= 95) || ($num >= 971 && $num <= 976))) {
                return $code;
            }
        }

        // Strategy 2: postal code (14000-14999 → dept 14, etc.)
        if (preg_match('/\b(14|27|50|61|76)\d{3}\b/', $lieu, $m)) {
            return $m[1];
        }

        // Strategy 3: bare department number at start of string or after separator
        if (preg_match('/(?:^|[\s,\-])(\d{2})(?=\s|$|,)/', $lieu, $m)) {
            $code = ltrim($m[1], '0') ?: $m[1];
            if (in_array($code, self::NORMANDIE_DEPTS, true)) {
                return $code;
            }
        }

        // Strategy 4: department name → code mapping
        static $names = [
            'calvados'       => '14',
            'eure'           => '27',
            'manche'         => '50',
            'orne'           => '61',
            'seine-maritime' => '76',
            'seine maritime' => '76',
        ];
        $lieu_lc = mb_strtolower($lieu);
        foreach ($names as $name => $code) {
            if (str_contains($lieu_lc, $name)) {
                return $code;
            }
        }

        return '';
    }

    /**
     * Fallback: detect Normandie events by keyword when department code is unavailable.
     */
    private function is_normandie_by_keyword(string $title, string $location): bool {
        static $keywords = [
            'normandie', 'calvados', 'manche', 'seine-maritime',
            'seine maritime', 'orne', 'douvres', 'caen', 'rouen',
            'cherbourg', 'alençon', 'évreux', 'lnco',
        ];
        $haystack = mb_strtolower($title . ' ' . $location);
        foreach ($keywords as $kw) {
            if (str_contains($haystack, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function normalize_dept_code(string $code): string {
        $code = ltrim(trim($code), '0');
        return $code === '' ? '' : $code;
    }

    private function is_allowed_url(string $url): bool {
        if ($url === '') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return in_array($host, ['www.ffcorientation.fr', 'ffcorientation.fr'], true);
    }

    /** @return string|null */
    private function extract_ffco_id(string $url) {
        if (preg_match('/\/agenda\/(\d+)\//i', $url, $m)) {
            return 'ffco_' . $m[1];
        }
        return null;
    }

    /** @return string|null  Returns Y-m-d or null on failure */
    private function parse_date(string $raw): ?string {
        $raw = trim($raw);
        // dd/mm/yy or dd/mm/yyyy
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $raw, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $ts   = mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $year);
            return $ts ? date('Y-m-d', $ts) : null;
        }
        // ISO yyyy-mm-dd
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return null;
    }

    private function http_get(string $url): ?string {
        $response = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'CalendrierLNCO/2.0 (liguenormandiecoursedorientation.fr)',
            'headers'    => ['Accept' => 'text/html,text/calendar,text/csv,*/*'],
        ]);

        if (is_wp_error($response)) {
            $this->add_log('HTTP error ' . $url . ' : ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->add_log('HTTP ' . $code . ' — ' . esc_url($url));
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    private function add_log(string $msg): void {
        $this->log[] = '[' . date('H:i:s') . '] ' . $msg;
    }

    private function save_log(): void {
        $history   = get_option('ffc_sync_log', []);
        $new_entry = ['date' => current_time('mysql'), 'messages' => $this->log];
        array_unshift($history, $new_entry);
        update_option('ffc_sync_log', array_slice($history, 0, 10));
    }
}
