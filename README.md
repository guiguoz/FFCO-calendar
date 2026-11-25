# FFCO-calendar

Extension WordPress qui récupère le calendrier fédéral de course d'orientation et
le publie sous forme de grille mensuelle.

Installée sur **deux sites**, avec le même code et le même comportement :

- **vikazim.fr** — club Vik'azim
- **liguenormandiecoursedorientation.fr** — Ligue de Normandie CO

Affichage via le shortcode `[ffc_calendar_ligue]` (utilisé notamment dans une
page Elementor).

## Source des données

**`https://cn.ffcorientation.fr/course/`** — le site « Courses CN ».

L'ancien agenda `https://www.ffcorientation.fr/courses/agenda/` ne sert plus
aucune donnée depuis la refonte du site fédéral (thème `ffco2026`) :

- la page « Agenda des manifestations » n'est plus qu'une page de renvoi vers un
  formulaire Jotform, sans tableau ni liste d'événements ;
- le WordPress fédéral n'expose aucun type de contenu « événement » (vérifié via
  `/wp-json/wp/v2/types`) ;
- les exports ICS et CSV ont disparu avec l'ancien agenda.

Le tableau CN comporte 8 colonnes :

| Date | Course | Lieu | Organisateur | Groupe | Spécialité | Épreuve | Résultats |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `jj/mm/aaaa` | titre + lien `/course/{id}/` | `Ville (dép.)` | `1408 - VIK'AZIM` | `A`/`B1`/`B2`/`D` | Pédestre, VTT | MD, LD, Sprint, Nuit | icône |

⚠️ **La page CN n'affiche qu'une saison à la fois** (`?season=AAAA`), et la
saison suit l'année civile. Toute période à cheval sur deux années doit donc être
récupérée en deux requêtes — c'est ce que fait la synchro.

### Filtrage

- **Normandie** : département extrait du Lieu `(14, 27, 50, 61, 76)`, avec repli
  sur le code postal, le nom du département, puis des mots-clés recherchés en
  tant que mots entiers.
- **National** : groupe CN `A` ou `B1`, ou titre contenant *Championnat de
  France*, *Critérium National*, *Coupe de France*, *Interrégionaux*.
  `B2` = championnat de **ligue** (toutes régions) → volontairement exclu.

## Fichiers

À déposer dans `wp-content/plugins/plugin wordpress sync calendar/` :

| Fichier | Rôle |
| --- | --- |
| `ffc-calendar-ligue-plugin.php` | Point d'entrée (v2.1) : cron, shortcode, amorçage |
| `includes/class-ffc-sync.php` | Récupération CN, filtrage, journal de synchro |
| `includes/class-ffc-db.php` | Table `{prefix}ffc_events`, rétention, purges |
| `includes/class-ffc-display.php` | Shortcode `[ffc_calendar_ligue]` : grille mensuelle + CSS |
| `includes/class-ffc-admin.php` | Réglages → *Calendrier FFC* : état, synchro manuelle, historique |

L'extension se suffit à elle-même : elle n'écrit que dans sa propre table et ne
dépend d'aucun autre plugin d'agenda.

## Fonctionnement

- **Synchronisation** : cron WordPress le 1er du mois à 3 h, plus un bouton
  « Synchroniser maintenant » dans l'admin (AJAX protégé par nonce).
- **Affichage** : `[ffc_calendar_ligue]` pour le mois courant, ou
  `[ffc_calendar_ligue month="9" year="2026"]`. Navigation mois par mois par
  arguments d'URL (`ffc_month`, `ffc_year`).
- **Réinsertion par année civile** : chaque synchro remplace les années qu'elle a
  effectivement récupérées, courses passées comprises, afin que les mois déjà
  écoulés de la saison restent visibles. Une année dont la page est injoignable
  n'est pas touchée — aucune perte de données.
- **Historique conservé** : `FFCCalendarDB::RETENTION_MONTHS` (18 mois par
  défaut). La synchro s'aligne automatiquement sur cette valeur.
- **Garde-fou** : si la source est joignable mais ne renvoie aucune course, la
  purge est annulée pour ne pas vider la base.

## Installation / mise à jour

1. Copier les fichiers du dépôt dans `wp-content/plugins/plugin wordpress sync calendar/`.
2. **Désactiver puis réactiver** l'extension dans *Extensions* — cela recrée la
   table si besoin et re-planifie le cron.
3. *Réglages → Calendrier FFC → « Synchroniser maintenant »*, puis lire le
   journal affiché en dessous.

## Dépannage

Le journal (visible dans *Réglages → Calendrier FFC*, 10 dernières exécutions)
détaille chaque étape :

```
Source : https://cn.ffcorientation.fr/course/ — saisons interrogées : 2026, 2027.
Saison 2026 : 243 lignes lues, 243 nouvelles courses.
Saison 2027 : page injoignable — https://cn.ffcorientation.fr/course/?season=2027
Bilan source : 243 courses, du 2026-01-11 au 2026-11-22.
Bilan filtres : 12 en Normandie (14/27/50/61/76), 29 nationales (groupe A/B1 ou titre).
Filtre : 29 nationaux + 12 Normandie conservés, 202 exclus (autres régions).
Année 2026 : 41 événements réinsérés.
```

Un résultat à **zéro** n'est pas nécessairement une panne : en fin de saison, la
dernière course publiée peut être déjà passée et le calendrier de la saison
suivante ne pas encore être en ligne. Le journal l'indique explicitement.

Un **mois vide** dans la grille est tout aussi normal : le filtre ne retient que
les courses normandes et les compétitions nationales. Hors saison
(novembre-décembre), il n'y a parfois aucune course de ce type dans toute la
France — c'est un reflet fidèle du calendrier fédéral, pas un dysfonctionnement.

## Contraintes

- PHP 8.0+ (`str_contains`, propriétés typées).
- Extensions PHP `dom` et `libxml` (parsing du tableau HTML).
- Sortie réseau autorisée vers `cn.ffcorientation.fr`.

## Historique du dépôt

Une seconde implémentation a coexisté un temps : des scripts autonomes
`ffc-events-calendar-sync*.php` déposés dans `wp-content/ffc-sync/` et déclenchés
par un cron externe en `?run=1`, qui créaient des événements **The Events
Calendar** (`tribe_events`). Aucune page ne les affichait — l'API Tribe des deux
sites renvoyait zéro événement — et ils doublaient le travail du plugin. Ils ont
été retirés ; la présente extension est l'unique mécanisme de synchronisation.
