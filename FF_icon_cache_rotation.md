# FF — Rotation partielle du tampon d'icônes SpotAir

**Statut :** À développer
**Priorité :** Basse (le tampon actuel fonctionne sans rotation, la feature est une amélioration de robustesse)
**Dépendance externe :** aucune — repose uniquement sur le système de fichiers Linux et le scheduler Laravel

---

## 1. Contexte

Depuis V2.x, les icônes SpotAir (sites + balises) transitent par un tampon
disque sous `public/icons-cache/{site|balise}/.../*.svg` (cf. section
*Module 1 — Carte météo* de `CLAUDE.md` et `App\Http\Controllers\IconCacheController`).
Le tampon se remplit au fil de l'eau : au premier hit d'une combinaison
de paramètres, PHP fetch SpotAir et persiste le SVG ; ensuite Nginx sert
le fichier statique directement.

**Le tampon est aujourd'hui *write-once* : aucune entrée n'est jamais
re-fetchée.** Conséquence : si SpotAir change sa charte graphique (refonte
visuelle, ajout de détails sur les bouclier-icônes, etc.), notre cache
reste figé sur l'ancienne version *ad vitam aeternam*, sauf intervention
manuelle (`rm -rf public/icons-cache/`).

## 2. Objectif

Mettre en place une **rotation lente et plafonnée** du tampon, de manière
à ce que :

- Une refonte graphique côté SpotAir soit absorbée naturellement en
  ~10-15 jours, sans intervention humaine.
- Le rythme de rafraîchissement reste **prévisible et borné**, pour ne
  jamais créer de pic d'appels vers SpotAir (qui nous fournit gracieusement
  ces icônes — on tient à rester un voisin discret).
- L'opération soit **idempotente et sans risque** : un fichier supprimé
  est re-fetché transparemment au prochain hit.

## 3. Algorithme retenu — FIFO plafonné

Job journalier (Laravel Scheduler, `daily()`), pour chaque sous-arbre
`site/` et `balise/` séparément :

1. Lister les fichiers `*.svg`.
2. Filtrer ceux dont `filemtime() < now - ROTATION_MIN_AGE_DAYS`
   (défaut : **30 jours**).
3. Trier par mtime ascendant (les plus anciens d'abord).
4. Prendre les premiers `min(count_eligibles, total_files × ROTATION_MAX_RATIO)`
   (défaut ratio : **10 %**).
5. `unlink()` chacun.
6. Logger : nb supprimés / nb éligibles / nb total.

### Pourquoi FIFO et pas aléatoire ?

| Critère                          | FIFO                                  | Aléatoire parmi éligibles            |
|----------------------------------|---------------------------------------|--------------------------------------|
| Date limite par icône            | Bornée à `MIN_AGE + (total/quota)`    | Pas de borne (espérance seulement)   |
| Prévisibilité monitoring         | Bonne (on sait combien de jours max)  | Faible                               |
| Distribution de la charge SpotAir | Égale (rafraîchissement régulier)     | Égale aussi                          |
| Complexité                       | `sort + slice`                        | `array_rand` sur l'éligibles         |
| Détection d'anomalie             | Facile (un fichier très vieux = bug)  | Plus dur                             |

→ **FIFO retenu** : la prévisibilité l'emporte ; aucun gain pratique côté
aléatoire pour notre volume (~250 SVG aujourd'hui, plafond estimé ~1500
en régime stable avec 1000 sites et 1000 balises).

### Pourquoi `mtime` et pas `ctime` / `atime` ?

- `mtime` : modifié à la création (`file_put_contents`) et jamais
  retouché ensuite → exactement ce qu'on veut.
- `ctime` : touché aussi par `chown/chmod` → potentiellement bruité si
  on intervient à la main.
- `atime` : souvent désactivé en prod (`noatime` au montage) pour
  économiser les IOPS.

## 4. Configuration

Stockée dans la table `settings` (cf. `App\Services\Settings`) :

| Clé                          | Défaut | Description                                     |
|------------------------------|--------|-------------------------------------------------|
| `icon_cache.rotation_enabled`| `true` | Coupe-circuit (mettre `false` désactive le job) |
| `icon_cache.min_age_days`    | `30`   | Âge minimum avant éligibilité à la suppression  |
| `icon_cache.max_ratio_pct`   | `10`   | Quota max par run, en % du total                |

Toutes éditables depuis `/admin/settings`.

## 5. Composants à créer

### Commande Artisan

`App\Console\Commands\RotateIconCache`

- Signature : `icons:rotate [--type=site|balise] [--dry-run] [--force]`
- `--dry-run` : liste ce qui serait supprimé sans rien toucher.
- `--force` : ignore `icon_cache.rotation_enabled = false` (utile en debug).
- Sortie : tableau récap par type (`total`, `eligible`, `deleted`).

### Scheduler

Dans `routes/console.php` :
```php
Schedule::command('icons:rotate')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
```
Heure choisie hors fenêtre de scoring (qui tourne aux heures rondes) et
pendant le creux de trafic.

### Service interne (optionnel)

Si la logique passe les ~30 LOC, l'extraire dans
`App\Services\Map\IconCacheRotator` (méthodes `rotate(string $type)`,
`statsFor(string $type)`). Sinon, garder le tout dans la commande.

## 6. Estimation

- Implémentation : **30-60 min** (commande + scheduler + tests manuels
  via `--dry-run`).
- Tests automatiques : ajout d'un test unitaire qui crée des fichiers
  factices avec `touch -t`, lance la rotation, vérifie le quota → 30 min.
- Doc CLAUDE.md (ajout d'une sous-section dans *Module 1 — Carte météo*) :
  10 min.
- Total : **~1h30** avec marges.

## 7. Risques et garde-fous

| Risque                                                | Mitigation                                                                                          |
|-------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| SpotAir indisponible au moment du re-fetch            | Le contrôleur renvoie 502, le navigateur affiche une icône cassée le temps d'un cycle. Acceptable.   |
| Suppression simultanée à une requête PHP en cours     | `unlink()` ne casse rien : le `file_put_contents` futur recrée. Race fenêtre = ms.                  |
| Cache vidé à 90 % par erreur de config (`max_ratio=90`) | Borne dure dans la commande : `max_ratio_pct` clampé à `[1, 25]`. Logs explicites si valeur clampée. |
| Coupe-circuit oublié sur `false`                      | Job écrit un INFO `"rotation désactivée"` à chaque run → visible en monitoring.                     |
| Disque saturé par un bug ailleurs (pas notre faute)   | Hors scope. La rotation n'est pas un outil de gestion d'espace, c'est un outil de fraîcheur.        |

## 8. Ordre de découpage suggéré

1. Ajouter les 3 clés à `Settings::DEFAULTS` + seeder.
2. Créer la commande `icons:rotate` avec `--dry-run` (sans suppression réelle).
3. Tester manuellement en prod sur le cache existant en `--dry-run` :
   vérifier que les sorties sont cohérentes.
4. Activer la suppression réelle, lancer un run manuel, vérifier le
   nombre de fichiers restants.
5. Ajouter l'entrée scheduler + redémarrer le container scheduler.
6. Documenter dans `CLAUDE.md` (sous-section dédiée + commande utile).
7. Bump éventuel de `CHANGELOG.md`.

## 9. Évolutions ultérieures envisagées

- **Endpoint admin** `/admin/icons-cache` : visualiser nb d'icônes par
  type, distribution des âges (histogramme), bouton « rotation
  immédiate » — utile pour anticiper une refonte SpotAir annoncée.
- **Cache-busting via query-string** : si SpotAir publiait un endpoint
  `/version` ou un header `Last-Modified`, on pourrait piloter la
  rotation par delta de version plutôt que par âge. Pour l'instant
  l'API SpotAir ne fournit rien de tel.
- **Compression** : gzip des SVG sur disque (économie ~60 %). Marginal
  vu le volume (~12 Mo plafond), pas la peine pour l'instant.
