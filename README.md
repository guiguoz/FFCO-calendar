# FFCO-calendar

Scripts et plugins WordPress pour récupérer les compétitions du calendrier
fédéral ([ffcorientation.fr](https://www.ffcorientation.fr/)) et les afficher
sur les sites WordPress (compétitions régionales Normandie + épreuves
nationales).

## Structure

### Synchronisation (source : https://cn.ffcorientation.fr/course/ — Courses CN)

| Fichier | Rôle |
|---|---|
| `ffc-events-calendar-sync.php` | Script de synchro → The Events Calendar (site Vikazim). Lit sa configuration depuis `config.php`. |
| `ffc-events-calendar-sync-ligue.php` | Variante du script de synchro pour le site de la Ligue Normandie (configuration intégrée). |
| `config.php` | Configuration Vikazim : départements cibles (`27, 76, 14, 50, 61`), URL FFCO, mapping villes → départements, fenêtre de scan. |

### Affichage (plugins WordPress, shortcodes)

| Fichier | Shortcode | Rôle |
|---|---|---|
| `ffc-calendar-plugin.php` | `[ffc_calendar]` | Calendrier mensuel des événements (site Vikazim). |
| `ffc-calendar-ligue-plugin.php` | `[ffc_calendar_ligue]` | Calendrier mensuel des événements (site Ligue Normandie). |
| `ffc-events-list-ligue-plugin.php` | `[ffc_events_list]` | Liste des prochains événements (page d'accueil Ligue). |

### Système alternatif (table dédiée `wp_ffc_events`)

| Fichier | Rôle |
|---|---|
| `includes/class-ffc-sync.php` | Moteur de synchro (ICS → CSV → HTML, auto-détection, filtre Normandie + national). |
| `includes/class-ffc-db.php` | Accès base de données (table `ffc_events`). |
| `includes/class-ffc-display.php` | Affichage du calendrier. |
| `includes/class-ffc-admin.php` | Page d'administration + synchro manuelle (AJAX). |

> Note : ce système n'est pas encore branché sur un plugin principal
> (aucun fichier ne charge ces classes pour le moment). Il est conservé
> en l'état en attendant une intégration.

## Prérequis

- WordPress + plugin **The Events Calendar** (pour les scripts de synchro
  et les plugins d'affichage).
- Accès HTTP sortant vers `ffcorientation.fr`.
