<?php
if (!defined('ABSPATH')) exit;

/**
 * Synchronisation du calendrier depuis le site fédéral « Courses CN ».
 *
 * Source actuelle : https://cn.ffcorientation.fr/course/
 *
 * L'ancien agenda https://www.ffcorientation.fr/courses/agenda/ ne sert plus
 * aucune donnée depuis la refonte du site fédéral (thème ffco2026) :
 *   - la page « Agenda des manifestations » n'est plus qu'une page de renvoi
 *     vers un formulaire Jotform, sans tableau ni liste d'événements ;
 *   - le WordPress fédéral n'expose aucun type de contenu « événement »
 *     (vérifié via /wp-json/wp/v2/types) ;
 *   - les exports ICS et CSV ont disparu avec l'ancien agenda.
 * Les trois méthodes historiques (ics / csv / html) sont donc remplacées par
 * une seule : le tableau HTML du site Courses CN.
 *
 * Particularité du CN : la page n'affiche qu'UNE saison à la fois
 * (?season=AAAA). On interroge l'année courante et l'année suivante, puis on
 * fusionne en dédupliquant par identifiant de course.
 */
class FFCCalendarSync {

    const CN_BASE        = 'https://cn.ffcorientation.fr';
    const CN_COURSE_LIST = 'https://cn.ffcorientation.fr/course/';

    const NORMANDIE_DEPTS = ['14', '27', '50', '61', '76'];

    /**
     * Groupes CN considérés comme niveau national.
     * A et B1 = national ; B2 = championnat de ligue ; D = départemental/régional.
     */
    const NATIONAL_GROUPS = ['A', 'B1'];

    private array $log = [];

    public function sync(): void {
        $events = $this->fetch_all_events();

        if ($events === false) {
            $this->add_log('ERREUR : source CN inaccessible — données existantes conservées.');
            $this->save_log();
            return;
        }

        // Garde-fou : une source joignable mais vide ne doit pas vider la base.
        if ($events === []) {
            $this->add_log('Source CN joignable mais aucune course lue — purge annulée, données existantes conservées.');
            $this->save_log();
            return;
        }

        $filtered = $this->filter_normandie($events);

        // La source CN publie une saison entière d'un coup (année civile) : on
        // remplace chaque année réellement récupérée, courses passées comprises,
        // afin que le calendrier affiche aussi les mois déjà écoulés. Une année
        // dont la page est injoignable n'est pas touchée — aucune perte de données.
        $cutoff  = date('Y-m-d', strtotime('-' . FFCCalendarDB::RETENTION_MONTHS . ' months'));
        $by_year = [];
        $skipped = 0;

        foreach ($filtered as $event) {
            if ($event['event_date'] < $cutoff) {
                $skipped++; // cleanup_old_events() les supprimerait aussitôt
                continue;
            }
            $by_year[(int) substr($event['event_date'], 0, 4)][] = $event;
        }
        ksort($by_year);

        $this->add_log(sprintf(
            'Récupérés : %d courses CN → %d après filtrage Normandie + national → %d à insérer (depuis %s, %d trop anciennes ignorées).',
            count($events),
            count($filtered),
            count($filtered) - $skipped,
            $cutoff,
            $skipped
        ));

        $inserted = 0;
        foreach ($by_year as $year => $year_events) {
            FFCCalendarDB::delete_events_for_year((int) $year);
            foreach ($year_events as $event) {
                FFCCalendarDB::upsert_event($event);
                $inserted++;
            }
            $this->add_log(sprintf('Année %d : %d événements réinsérés.', $year, count($year_events)));
        }

        FFCCalendarDB::cleanup_old_events();
        $this->add_log(sprintf(
            '%d événements insérés, %d en base après sync.',
            $inserted,
            FFCCalendarDB::count_events()
        ));
        update_option('ffc_last_sync', current_time('mysql'));
        $this->save_log();
    }

    // -------------------------------------------------------------------------
    // Récupération depuis le site Courses CN
    // -------------------------------------------------------------------------

    /**
     * @return array|false  false = source inaccessible, [] = joignable mais vide
     */
    private function fetch_all_events() {
        $year    = (int) date('Y');
        $seasons = [$year, $year + 1];

        $events  = [];
        $seen    = [];
        $reached = false;

        $this->add_log(sprintf('Source : %s — saisons interrogées : %s.', self::CN_COURSE_LIST, implode(', ', $seasons)));

        foreach ($seasons as $season) {
            $url  = self::CN_COURSE_LIST . '?season=' . $season;
            $html = $this->http_get($url);

            if ($html === null) {
                $this->add_log(sprintf('Saison %d : page injoignable — %s', $season, $url));
                continue;
            }

            $reached = true;
            $parsed  = $this->parse_cn_page($html);
            $added   = $this->merge_events($parsed, $events, $seen);

            $this->add_log(sprintf('Saison %d : %d lignes lues, %d nouvelles courses.', $season, count($parsed), $added));
        }

        // Filet de sécurité : si ?season= n'a rien donné, on tente la page par défaut
        if ($events === []) {
            $html = $this->http_get(self::CN_COURSE_LIST);
            if ($html !== null) {
                $reached = true;
                $parsed  = $this->parse_cn_page($html);
                $added   = $this->merge_events($parsed, $events, $seen);
                $this->add_log(sprintf('Page par défaut : %d lignes lues, %d nouvelles courses.', count($parsed), $added));
            }
        }

        if (!$reached) {
            return false;
        }

        update_option('ffc_fetch_method', 'cn_html');
        $this->log_bilan($events);

        return $events;
    }

    /**
     * Ajoute les événements d'une page à la liste commune, sans doublon.
     *
     * @param array $parsed Lignes issues de parse_cn_page()
     * @param array $events Liste cumulée, modifiée par référence
     * @param array $seen   Identifiants déjà vus, modifié par référence
     */
    private function merge_events(array $parsed, array &$events, array &$seen): int {
        $added = 0;
        foreach ($parsed as $event) {
            $id = $event['ffco_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $events[]  = $event;
            $added++;
        }
        return $added;
    }

    /**
     * Parse le tableau Courses CN :
     * Date | Course | Lieu | Organisateur | Groupe | Spécialité | Épreuve | Résultats
     */
    private function parse_cn_page(string $html): array {
        $events = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows  = $xpath->query('//table//tr[td]');

        if (!$rows || $rows->length === 0) {
            $this->add_log('Aucune ligne de tableau trouvée (saison non publiée ou structure modifiée).');
            return $events;
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 4) {
                continue;
            }

            $cell_text = function (int $i) use ($cells): string {
                $node = $cells->item($i);
                if (!$node) {
                    return '';
                }
                return trim(html_entity_decode($node->textContent, ENT_QUOTES, 'UTF-8'));
            };

            $event_date = $this->parse_date($cell_text(0));
            $title      = $cell_text(1);
            $lieu       = $cell_text(2);
            $groupe     = $cell_text(4);
            $discipline = $cell_text(5); // Spécialité : Pédestre, VTT…
            $category   = $cell_text(6); // Épreuve : MD, LD, Sprint, Nuit…

            if ($event_date === null || $title === '') {
                continue;
            }

            // Lien vers la fiche CN — absent pour certaines courses
            $event_url = '';
            $title_cell = $cells->item(1);
            $link_node  = $title_cell ? $xpath->query('.//a', $title_cell)->item(0) : null;
            if ($link_node) {
                $href = $link_node->getAttribute('href');
                if ($href !== '') {
                    $candidate = (strpos($href, 'http') === 0) ? $href : self::CN_BASE . $href;
                    $event_url = $this->is_allowed_url($candidate) ? $candidate : '';
                }
            }

            $ffco_id = $this->extract_ffco_id($event_url) ?? 'cn_' . md5($event_date . '|' . $title . '|' . $lieu);

            $events[] = [
                'ffco_id'     => sanitize_text_field($ffco_id),
                'event_date'  => $event_date,
                'title'       => sanitize_text_field($title),
                'location'    => sanitize_text_field($lieu),
                'department'  => $this->normalize_department($lieu),
                'is_national' => $this->is_national($title, $groupe) ? 1 : 0,
                'discipline'  => sanitize_text_field($discipline),
                'category'    => sanitize_text_field($category),
                'event_url'   => esc_url_raw($event_url),
                'status'      => 'OK',
            ];
        }

        return $events;
    }

    /**
     * Bilan détaillé, pour comprendre immédiatement un résultat à zéro.
     */
    private function log_bilan(array $events): void {
        if ($events === []) {
            return;
        }

        $min = null;
        $max = null;
        $nb_normandie = 0;
        $nb_national  = 0;

        foreach ($events as $e) {
            if ($min === null || $e['event_date'] < $min) { $min = $e['event_date']; }
            if ($max === null || $e['event_date'] > $max) { $max = $e['event_date']; }
            if (in_array($e['department'], self::NORMANDIE_DEPTS, true)) { $nb_normandie++; }
            if (!empty($e['is_national'])) { $nb_national++; }
        }

        $this->add_log(sprintf('Bilan source : %d courses, du %s au %s.', count($events), $min, $max));
        $this->add_log(sprintf(
            'Bilan filtres : %d en Normandie (%s), %d nationales (groupe %s ou titre).',
            $nb_normandie,
            implode('/', self::NORMANDIE_DEPTS),
            $nb_national,
            implode('/', self::NATIONAL_GROUPS)
        ));

        $today = date('Y-m-d');
        if ($max !== null && $max < $today) {
            $this->add_log(sprintf(
                "La dernière course publiée (%s) est déjà passée : le calendrier de la saison %d n'est "
                . "probablement pas encore publié sur cn.ffcorientation.fr. Ce n'est pas un bug, la "
                . 'synchro reprendra dès sa publication.',
                $max,
                (int) substr($max, 0, 4) + 1
            ));
        }
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
     * Niveau national : groupe CN A ou B1, ou titre explicitement national.
     *
     * Le groupe CN est plus fiable que le titre : B2 correspond aux championnats
     * de ligue de toutes les régions, qu'on ne veut pas afficher ici.
     */
    private function is_national(string $title, string $groupe): bool {
        $g = strtoupper(trim($groupe));
        if ($g !== '' && in_array($g, self::NATIONAL_GROUPS, true)) {
            return true;
        }
        return $this->is_truly_national($title);
    }

    /**
     * Returns true only for genuinely national-level competitions (open to all France).
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
     *
     * Les mots-clés sont recherchés en tant que mots entiers. Une recherche par
     * sous-chaîne produisait des faux positifs : « manche » dans « dimanche »,
     * « orne » dans « Corneilla ». Les bornes utilisent \p{L} plutôt que \b, qui
     * n'est pas fiable en PHP sur les caractères accentués (« évreux », « alençon »).
     */
    private function is_normandie_by_keyword(string $title, string $location): bool {
        static $keywords = [
            'normandie', 'calvados', 'manche', 'seine-maritime',
            'seine maritime', 'orne', 'douvres', 'caen', 'rouen',
            'cherbourg', 'alençon', 'évreux', 'lnco',
        ];
        $haystack = mb_strtolower($title . ' ' . $location);
        foreach ($keywords as $kw) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/u', $haystack)) {
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
        return in_array($host, ['cn.ffcorientation.fr', 'www.ffcorientation.fr', 'ffcorientation.fr'], true);
    }

    /** @return string|null */
    private function extract_ffco_id(string $url) {
        if (preg_match('/\/course\/(\d+)/i', $url, $m)) {
            return 'cn_' . $m[1];
        }
        // Ancien format, conservé pour ne pas casser les identifiants déjà en base
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
            'user-agent' => 'CalendrierLNCO/2.1 (liguenormandiecoursedorientation.fr)',
            'headers'    => ['Accept' => 'text/html,*/*'],
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
