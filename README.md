# FFCO-calendar

Récupération du calendrier fédéral de course d'orientation et republication sur
deux sites WordPress : **vikazim.fr** (club Vik'azim) et
**liguenormandiecoursedorientation.fr** (Ligue de Normandie CO).

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

⚠️ **La page CN n'affiche qu'une saison à la fois** (`?season=AAAA`). Toute
période à cheval sur deux années doit donc être récupérée en deux requêtes.

### Filtrage

- **Normandie** : département extrait du Lieu `(14, 27, 50, 61, 76)`, avec
  repli sur le code postal, le nom du département, puis des mots-clés.
- **National** : groupe CN `A` ou `B1`, ou titre contenant *Championnat de
  France*, *Critérium National*, *Coupe de France*, *Interrégionaux*.
  `B2` = championnat de **ligue** (toutes régions) → volontairement exclu.

## Contenu du dépôt

Deux dispositifs indépendants coexistent, selon le plugin d'agenda installé sur
chaque site.

### 1. Extension WordPress (table dédiée) — `plugin wordpress sync calendar/`

| Fichier | Rôle |
| --- | --- |
| `ffc-calendar-ligue-plugin.php` | Point d'entrée (v2.1), cron mensuel, shortcode |
| `includes/class-ffc-sync.php` | Récupération CN, filtrage, journal de synchro |
| `includes/class-ffc-db.php` | Table `{prefix}ffc_events` (upsert par `ffco_id`) |
| `includes/class-ffc-display.php` | Shortcode `[ffc_calendar_ligue]` : grille mensuelle |
| `includes/class-ffc-admin.php` | Réglages → *Calendrier FFC* : état, bouton de synchro, historique |

- Synchronisation : **cron WordPress le 1er du mois à 3 h**, plus un bouton
  « Synchroniser maintenant » dans l'admin (AJAX, nonce).
- Affichage : `[ffc_calendar_ligue]` ou `[ffc_calendar_ligue month="9" year="2026"]`.
- Chaque synchro purge les événements **à venir** puis les réinsère ; les
  événements passés restent en base (les réinsérer créerait des doublons, leur
  identifiant ayant changé avec le changement de source).
- Garde-fou : si la source est joignable mais renvoie zéro course, la purge est
  annulée pour ne pas vider la base.

### 2. Scripts autonomes (The Events Calendar / Tribe) — `wp-content/ffc-sync/`

| Fichier | Rôle |
| --- | --- |
| `ffc-events-calendar-sync.php` | Instanciation Vikazim2, crée des `tribe_events` |
| `ffc-events-calendar-sync-ligue.php` | Variante Ligue de Normandie |
| `config.php` | Départements visés, URL source, correspondances villes → départements |

- Déclenchement par URL : `?run=1` (synchronisation) ou `?refresh=1` (mise à
  jour des événements existants), appelés par une tâche cron externe.
- Fenêtre : mois courant + 2 mois suivants.
- `config.php` doit se trouver **dans le même dossier** que le script.

## Dépannage

Le journal (admin pour l'extension, sortie écran pour les scripts) détaille
chaque étape :

```
Saison 2026 : 243 lignes lues, 41 nouvelles courses.
Saison 2027 : page injoignable — https://cn.ffcorientation.fr/course/?season=2027
Bilan source : 243 courses, du 2026-01-11 au 2026-11-22.
Bilan filtres : 12 en Normandie (14/27/50/61/76), 29 nationales (groupe A/B1 ou titre).
```

Un résultat à **zéro** n'est pas nécessairement une panne : en fin de saison, la
dernière course publiée peut être déjà passée et le calendrier de la saison
suivante ne pas encore être en ligne. Le journal l'indique explicitement.

## Contraintes

- PHP 8.0+ (`str_contains`, propriétés typées).
- Nécessite `curl` et les extensions `dom` / `libxml`.
- La saison CN suit l'année civile : en décembre, la liste de l'année courante
  est épuisée et celle de l'année suivante n'est pas encore publiée.
