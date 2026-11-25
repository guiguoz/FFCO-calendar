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

        $cols = $this->detect_columns($xpath);
        $this->add_log('Colonnes : ' . $this->format_columns($cols));

        // Contrôle structurel : une ligne de données doit comporter autant de
        // cellules que l'en-tête. C'est ce qui trahit immédiatement un décalage.
        $header_cells = $this->count_header_cells($xpath);
        $first_cells  = $xpath->query('.//th | .//td', $rows->item(0));
        $data_cells   = $first_cells ? $first_cells->length : 0;
        $this->add_log(sprintf(
            "Cellules : %d par ligne de données, %d dans l'en-tête.",
            $data_cells, $header_cells
        ));
        if ($header_cells > 0 && $data_cells !== $header_cells) {
            $this->add_log(sprintf(
                "DÉCALAGE : %d cellules de données pour %d colonnes d'en-tête — la correspondance "
                . 'des colonnes est fausse, le tableau CN a changé de structure.',
                $data_cells, $header_cells
            ));
        }

        $sample     = null;
        $nb_no_link = 0;

        foreach ($rows as $row) {
            // th ET td : le CN sert la colonne « Course » dans un <th scope="row">.
            // Ne sélectionner que les <td> la saute et décale toutes les colonnes
            // d'un cran vers la gauche. L'union XPath conserve l'ordre du document.
            $cells = $xpath->query('.//th | .//td', $row);
            if ($cells->length < 4) {
                continue;
            }

            $cell = function (string $key) use ($cells, $cols): string {
                $idx  = $cols[$key] ?? -1;
                $node = ($idx >= 0) ? $cells->item($idx) : null;
                return $node ? $this->clean_text($node->textContent) : '';
            };

            $event_date = $this->parse_date($cell('date'));
            $title      = $cell('course');
            $lieu       = $cell('lieu');
            $organizer  = $cell('organisateur');
            $groupe     = $cell('groupe');
            $discipline = $cell('specialite'); // Pédestre, VTT…
            $category   = $cell('epreuve');    // MD, LD, Sprint, Nuit…

            if ($event_date === null || $title === '') {
                continue;
            }

            // Lien vers la fiche CN — absent pour certaines courses
            $event_url  = '';
            $title_cell = $cells->item($cols['course']);
            $link_node  = $title_cell ? $xpath->query('.//a', $title_cell)->item(0) : null;
            if ($link_node) {
                $href = $link_node->getAttribute('href');
                if ($href !== '') {
                    $candidate = (strpos($href, 'http') === 0) ? $href : self::CN_BASE . $href;
                    $event_url = $this->is_allowed_url($candidate) ? $candidate : '';
                }
            }
            if ($event_url === '') {
                $nb_no_link++;
            }

            // Identifiant : celui de la fiche CN quand elle existe, sinon une
            // empreinte de la ligne entière. Deux courses homonymes le même jour
            // au même endroit ne doivent pas être confondues.
            $ffco_id = $this->extract_ffco_id($event_url) ?? 'cn_' . md5(implode('|', [
                $event_date, $title, $lieu, $organizer, $groupe, $discipline, $category,
            ]));

            $event = [
                'ffco_id'     => sanitize_text_field($ffco_id),
                'event_date'  => $event_date,
                'title'       => sanitize_text_field($title),
                'location'    => sanitize_text_field($lieu),
                'department'  => $this->resolve_department($lieu, $cell('departement')),
                'is_national' => $this->is_national($title, $groupe) ? 1 : 0,
                'discipline'  => sanitize_text_field($discipline),
                'category'    => sanitize_text_field($category),
                'event_url'   => esc_url_raw($event_url),
                'status'      => 'OK',
            ];
            $events[] = $event;

            if ($sample === null) {
                $sample = sprintf(
                    'date=%s | course=%s | lieu=%s | organisateur=%s | groupe=%s | spécialité=%s | épreuve=%s => dép.=%s',
                    $event_date, $title, $lieu, $organizer, $groupe, $discipline, $category,
                    $event['department'] === '' ? '(aucun)' : $event['department']
                );
            }
        }

        if ($sample !== null) {
            $this->add_log('Échantillon, 1re ligne lue → ' . $sample);
        }
        if ($nb_no_link > 0) {
            $this->add_log(sprintf('%d lignes sans fiche CN (identifiant calculé depuis la ligne).', $nb_no_link));
        }

        return $events;
    }

    /**
     * Normalise un texte issu du DOM.
     *
     * PHP trim() ne retire ni U+00A0 (espace insécable produite par &nbsp;) ni
     * U+202F (espace fine insécable, courante en typographie française), et le
     * \s de PCRE ne les reconnaît pas davantage. Sans cette normalisation, un
     * groupe « B1 » encadré d'insécables n'est pas détecté comme national et un
     * lieu « Ville (14) » peut ne pas livrer son département.
     */
    private function clean_text(?string $raw): string {
        if ($raw === null) {
            return '';
        }
        $text = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim($text);
    }

    /**
     * Département de la course : lu dans le lieu (« Ville (14) ») en priorité,
     * à défaut dans une éventuelle colonne dédiée du tableau CN.
     */
    private function resolve_department(string $lieu, string $dept_brut): string {
        $dept = $this->normalize_department($lieu);
        if ($dept !== '') {
            return $dept;
        }
        if ($dept_brut === '') {
            return '';
        }
        $code = $this->normalize_dept_code($dept_brut);
        if ($code === '' || !ctype_digit($code)) {
            return '';
        }
        $num = (int) $code;
        return (($num >= 1 && $num <= 95) || ($num >= 971 && $num <= 976)) ? $code : '';
    }

    /**
     * Détermine l'index de chaque colonne d'après la ligne d'en-tête, avec repli
     * sur l'ordre connu. Résiste à un ajout ou à un réordonnancement côté CN.
     */
    /**
     * Ligne d'en-tête du tableau : <thead> d'abord, sinon la première ligne
     * contenant des <th>. Les lignes de données du CN contiennent elles aussi un
     * <th scope="row"> (colonne « Course »), d'où cette précaution.
     */
    private function find_header_row(DOMXPath $xpath): ?DOMElement {
        $row = $xpath->query('//table/thead//tr[th]')->item(0);
        if ($row instanceof DOMElement) {
            return $row;
        }
        $row = $xpath->query('//table//tr[th]')->item(0);
        return $row instanceof DOMElement ? $row : null;
    }

    /** Nombre de cellules de la ligne d'en-tête (0 si en-tête introuvable). */
    private function count_header_cells(DOMXPath $xpath): int {
        $header_row = $this->find_header_row($xpath);
        if ($header_row === null) {
            return 0;
        }
        $cells = $xpath->query('.//th | .//td', $header_row);
        return $cells ? $cells->length : 0;
    }

    private function detect_columns(DOMXPath $xpath): array {
        $cols = [
            'date' => 0, 'course' => 1, 'lieu' => 2, 'organisateur' => 3,
            'groupe' => 4, 'specialite' => 5, 'epreuve' => 6,
            // Colonne dédiée absente du tableau actuel : -1 = ne pas lire.
            'departement' => -1,
        ];

        $header_row = $this->find_header_row($xpath);
        if ($header_row === null) {
            $this->add_log('En-tête du tableau absent : ordre de colonnes par défaut supposé.');
            return $cols;
        }

        $detected = [];
        foreach ($xpath->query('.//th', $header_row) as $idx => $th) {
            $key = $this->header_key($th->textContent);
            if ($key !== null && !isset($detected[$key])) {
                $detected[$key] = $idx;
            }
        }

        if ($detected === []) {
            $this->add_log('En-tête du tableau non reconnu : ordre de colonnes par défaut supposé.');
            return $cols;
        }

        return array_merge($cols, $detected);
    }

    /** @return string|null Clé de colonne reconnue dans un libellé d'en-tête. */
    private function header_key(?string $label): ?string {
        if ($label === null) {
            return null;
        }
        $label = mb_strtolower($this->clean_text($label));
        $label = strtr($label, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o',
            'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        // « résultats » n'est volontairement pas reconnu : colonne ignorée.
        static $map = [
            'date'         => 'date',
            'course'       => 'course',
            'lieu'         => 'lieu',
            'ville'        => 'lieu',
            'organisateur' => 'organisateur',
            'departement'  => 'departement',
            'groupe'       => 'groupe',
            'specialite'   => 'specialite',
            'epreuve'      => 'epreuve',
        ];
        foreach ($map as $needle => $key) {
            if (str_contains($label, $needle)) {
                return $key;
            }
        }
        return null;
    }

    private function format_columns(array $cols): string {
        $parts = [];
        foreach ($cols as $key => $idx) {
            $parts[] = $key . '=' . $idx;
        }
        return implode(', ', $parts);
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
        $nb_national = 0;
        $nb_dept     = 0;
        $nb_kw       = 0;

        // Même ordre de priorité que filter_normandie(), pour que les compteurs
        // annoncés ici correspondent exactement au résultat du filtre.
        foreach ($events as $e) {
            if ($min === null || $e['event_date'] < $min) { $min = $e['event_date']; }
            if ($max === null || $e['event_date'] > $max) { $max = $e['event_date']; }

            if (!empty($e['is_national'])) {
                $nb_national++;
            } elseif (in_array($e['department'], self::NORMANDIE_DEPTS, true)) {
                $nb_dept++;
            } elseif ($this->is_normandie_by_keyword($e['title'], $e['location'])) {
                $nb_kw++;
            }
        }

        $this->add_log(sprintf('Bilan source : %d courses, du %s au %s.', count($events), $min, $max));
        $this->add_log(sprintf(
            'Bilan filtres : %d nationales (groupe %s ou titre), %d normandes par département (%s), %d par mot-clé.',
            $nb_national,
            implode('/', self::NATIONAL_GROUPS),
            $nb_dept,
            implode('/', self::NORMANDIE_DEPTS),
            $nb_kw
        ));

        if (count($events) >= 50 && $nb_national === 0 && $nb_dept === 0) {
            $this->add_log(sprintf(
                "ANOMALIE : aucune course nationale ni normande détectée sur %d lignes. Une saison "
                . "complète en contient normalement plusieurs dizaines. Comparer l'échantillon ci-dessus "
                . "au tableau de cn.ffcorientation.fr/course/ : ordre ou contenu des colonnes "
                . 'probablement modifié côté fédération.',
                count($events)
            ));
        }

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
        // Strategy 1: explicit "(14)" or "(076)" pattern.
        // Des espaces peuvent s'y glisser (insécables normalisées par clean_text).
        if (preg_match('/\(\s*(\d{2,3})\s*\)/u', $lieu, $m)) {
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
