# FF_newscoring — Indices convectifs : orage, stabilité & turbulence

> Note de conception pour l'extension du moteur de scoring **ParapenteFR**.
> Document de réflexion / spécification d'implémentation. À lire en complément de `CLAUDE.md`.
> Statut : **brouillon de conception** — non implémenté.

---

## 0. Contexte & intégration dans l'architecture existante

Le moteur actuel fonctionne sur **3 niveaux** :

1. **Niveau 1 — Éliminatoires** (rédhibitoires) : précipitations, vent moyen hors plage, direction hors plage → rouge sans calcul, avec biais sécuritaire sur le vote (1 modèle dissident sur une éliminatoire = orange minimum).
2. **Niveau 2 — Score de qualité** : plafond, nébulosité (haute/moyenne/basse), humidité, température.
3. **Niveau 3 — Confiance multi-modèles** : convergence par variable → % de fiabilité.

Ce document ajoute trois indices dérivés de **variables convectives** :

| Indice | Niveau d'insertion | Nature |
|---|---|---|
| Risque orageux | Niveau 1 (éliminatoire gradué) | Sécurité — red flag |
| Stabilité / activité thermique | Niveau 1 **et** Niveau 2 | Bidirectionnel (malus sécurité ↔ bonus qualité) |
| Turbulence composite | Niveau 1 (éliminatoire gradué) | Sécurité — synthèse |

Principe directeur : l'orage et la turbulence sont des **éliminatoires gradués** (comme la pluie, mais avec un degré plutôt qu'un seuil binaire). L'activité thermique est la seule variable **non monotone** du modèle — son optimum est au milieu de l'échelle, et dépend du pilote.

---

## 1. Indice de risque orageux

### 1.1 Variables sources

| Variable Open-Meteo | Rôle | Dispo |
|---|---|---|
| `cape` | Énergie convective (instabilité) | Quasi tous les modèles |
| `convective_inhibition` (CIN) | Le « couvercle » | Partielle |
| `lifted_index` | Instabilité (négatif = instable) | Partielle |
| `convective_precipitation` | Précipitation convective produite par le modèle | Partielle |

> **Dégradation gracieuse** : si seul `cape` est disponible pour un modèle, l'indice fonctionne quand même (sans les modulations). Vérifier la disponibilité **par modèle** au moment du fetch — tous les 10 modèles n'exposent pas CIN/LI.

### 1.2 Calcul du niveau brut, par modèle et par créneau

Niveau de base à partir du CAPE :

```
CAPE < 300         → niveau 0  (négligeable)
300  ≤ CAPE < 800  → niveau 1  (faible)
800  ≤ CAPE < 1500 → niveau 2  (modéré)
CAPE ≥ 1500        → niveau 3  (fort)
```

Modulations (appliquées au niveau de base) :

```
convective_precipitation > 0.5 mm   → +1 cran   (orage déjà "produit" par le modèle)
lifted_index < -4                    → +1 cran
CIN > 100 J/kg                       → -1 cran   (convection bloquée par le couvercle)
lifted_index > 0                     → force niveau = 0 (atmosphère stable)

Flag spécial "explosif" :
  CIN > 100 ET CAPE ≥ 1500           → niveau réduit MAIS afficher avertissement
                                        (si le couvercle saute, orage plus violent)
```

Le niveau final est borné à `[0, 3]`.

### 1.3 Consensus — pondération des modèles à maille fine

**Point clé** : tous les modèles ne se valent pas pour l'orage.

- Modèles **convection-permitting** (résolvent réellement la convection) :
  **AROME** (~1.3 km), **ICON-D2** (~2 km), **HARMONIE**. Leur `convective_precipitation` est un **signal fort et direct**.
- Modèles **globaux** (paramétrisent la convection) : GFS, IFS-HRES, AIFS, ICON, GEM, ARPEGE-EU, ICON-EU. Leur CAPE est un **potentiel**, pas une prévision d'orage.

→ Pondération majorée des modèles convection-permitting dans le vote orageux.

```
Logique de consensus (biais sécuritaire, jamais la moyenne) :
  - 1 modèle convection-permitting à niveau ≥ 2        → orange minimum
  - majorité des modèles ≥ niveau 2
    OU un modèle fine maille à niveau 3                 → rouge
  - sinon, le max(niveaux pondérés) tire la couleur
```

### 1.4 Mapping couleur (feu tricolore existant)

| Niveau consensus | Pastille | Libellé info-bulle |
|---|---|---|
| 0 | 🟢 vert | (rien) |
| 1 | 🟢 vert | « convection faible » |
| 2 | 🟠 orange | « risque d'orage / surdéveloppement » |
| 3 | 🔴 rouge | « orage probable — vol déconseillé » |

Comme c'est un éliminatoire, le rouge orage **écrase** un Niveau 2 par ailleurs excellent.

### 1.5 Corroboration par la couverture nuageuse

Idée : croiser l'indice orageux avec les prévisions de couverture nuageuse **basse / moyenne / haute** déjà disponibles, pour confirmer ou désamorcer une alerte.

#### Le piège à éviter : convectif ≠ stratiforme

Un développement nuageux n'implique pas un orage. Deux régimes **opposés** font tous deux monter la couverture basse + moyenne et donnent de la pluie :

| Nuage | Régime | Air | Sens pour le pilote |
|---|---|---|---|
| **Cumulonimbus (Cb)** | convectif | instable (CAPE) | le danger qu'on traque |
| **Nimbostratus (Ns)** | stratiforme | stable (soulèvement de grande échelle, front) | « gris et mouillé », air calme |

→ **La couverture basse + moyenne seule ne distingue PAS le convectif du stratiforme.** C'est un signal « nuageux / probablement pluvieux », pas un signal « ça convecte ». La lier naïvement à l'indice orageux génère de fausses alertes sur les journées d'overcast plat et stable.

#### La vraie signature d'un Cb : la verticalité, pas le bas + moyen

Ce qui signe la convection profonde :

- les **trois couches simultanément**, avec une composante **haute** marquée (l'enclume / cirrus d'étalement au sommet) ;
- des **sommets nuageux hauts et froids** (forte extension verticale) ;
- une **tendance** : bourgeonnement pendant les heures chaudes (cumulus épars → congestus → étalement) — la signature même du **surdéveloppement**.

#### Usage retenu : la couverture comme signal de *réalisation*, pas de déclenchement

Le CAPE est **causal** (le potentiel). La couverture est une **conséquence** (la réalisation). On l'utilise donc pour **confirmer ou désamorcer**, jamais pour déclencher seule :

```
CAPE élevé + couverture qui se développe (surtout moyen/haut)
    → la convection part réellement
    → confiance ↑, on pousse vers le rouge

CAPE élevé + ciel qui reste clair / couverture basse seulement
    → le couvercle (CIN) tient, thermiques "bleus"
    → on DÉSAMORCE l'alerte (pas d'orage malgré le potentiel)
```

Cela résout l'ambiguïté **potentiel vs réalisé** que le CAPE seul ne tranche pas.

> La variable `convective_precipitation` (§1.1) est le signal de réalisation le **plus direct** : c'est le modèle qui annonce lui-même une pluie convective. À croiser avec la tendance de couverture, c'est un confirmateur de premier ordre.

#### Logique d'application

```
modulation_confirmation(niveau_orage_brut, couverture, tendance, conv_precip) :
    si niveau_orage_brut ≥ 2 :
        si (couverture_moyenne/haute en hausse) OU (conv_precip > 0.5 mm)
            → confirmer : maintenir / monter d'un cran
        sinon si (ciel clair OU couverture basse seule, stable)
            → désamorcer : descendre d'un cran, info-bulle "potentiel non réalisé (couvercle)"
    # n'élève jamais un niveau 0/1 sur la seule base de la couverture
```

> Garde-fou : la couverture **module** un indice orageux déjà ≥ 2 (potentiel présent). Elle ne crée jamais une alerte à partir de rien — sinon on retombe dans le piège Ns.

#### Calcul de la tendance de couverture

La tendance n'est **pas** une valeur instantanée : c'est une **dérivée** sur la fenêtre des heures chaudes. Le bourgeonnement est un phénomène d'après-midi — c'est la *montée* de la couverture pendant le pic de chauffe qui signe le développement, pas un snapshot.

**1. Métrique pondérée** — privilégier les couches moyennes/hautes (le bas seul est ambigu : stratus stable possible) :

```
couverture_pondérée(h) = 0.5·cc_mid(h) + 0.4·cc_high(h) + 0.1·cc_low(h)
```

**2. Fenêtre glissante par créneau** — pour chaque heure H de la fenêtre solaire, calculer la pente sur une fenêtre centrée (données toutes prévisionnelles, donc pas de souci de "fuite") :

```
fenêtre = [H-2h, H+1h]                       # ~4 points horaires
pente(H) = régression linéaire (moindres carrés)
           de couverture_pondérée sur la fenêtre     [% / h]
```

> La régression amortit déjà le bruit ; un simple delta début/fin serait trop sensible aux à-coups horaires des modèles. Lisser éventuellement (moyenne glissante 2–3 pts) avant.

**3. Classement de la tendance** — avec un plancher de couverture pour ne pas qualifier de "développement" des micro-variations sur ciel quasi clair :

```
pente > +8 %/h  ET  couverture_pondérée(H) ≥ 30%   → "en développement"   (hausse)
-8 ≤ pente ≤ +8                                     → stable
pente < -8 %/h                                      → "en dissipation"
```

(Seuils `+8 %/h` et `30%` à calibrer empiriquement.)

**4. Consensus** — cohérent avec §3.4 : calculer la pente **par modèle** d'abord (chaque modèle a sa propre série horaire et son propre timing), puis voter. Ne pas moyenner les séries horaires entre modèles avant de dériver — ça écraserait les décalages de timing. Tendance retenue "en hausse" si ≥ X % des modèles la voient, ou si un modèle convection-permitting la voit.

C'est la valeur `tendance` consommée par `modulation_confirmation` ci-dessus.

**Raffinement optionnel — signature d'enclume :** détecter une croissance **verticale** en repérant que `cc_high` monte *après* `cc_low`/`cc_mid` (décalage temporel = cumulus → congestus → étalement). Signal plus spécifique du Cb que la seule pente agrégée, mais plus coûteux à implémenter — à garder pour une v2.

---

## 2. Indice de stabilité / activité thermique

### 2.1 Pourquoi le CAPE seul est insuffisant

Le CAPE est intégré sur **toute la colonne** jusqu'à la tropopause. Les thermiques de parapente vivent dans la **couche limite** (0–2500 m). On peut avoir un gros CAPE haut perché qui ne se traduit pas en thermiques exploitables, et inversement. Il faut donc des métriques propres à la basse couche.

### 2.2 Variables sources

**Gradient (taux de décroissance) basse couche** — calculé à partir de la température au sol et à ~850 hPa (≈ 1500 m) :

```
gradient = (T_sol - T_850hPa) / Δaltitude   [°C / 100 m]

gradient ≥ ~1.0 °C/100m   → proche adiabatique sèche → thermiques puissants
gradient ~0.6–1.0          → bon thermique
gradient < ~0.6            → atmosphère molle, dynamique seulement
```

**Hauteur de couche convective** — `boundary_layer_height` (Open-Meteo), plafonnée par la base des cumulus déjà calculée. Indique *jusqu'où on peut monter*.

### 2.3 Caractère bidirectionnel

| État | Pastille | Sens |
|---|---|---|
| gradient faible + couche basse | ⚪ neutre | « soaring dynamique seulement » |
| gradient bon + couche haute | 🟢 bonus qualité | belle journée XC |
| gradient fort + CAPE 800–1500 | 🟡 sportif | malus pour bas niveaux |
| + déclencheur orageux | 🔴 éliminatoire | bascule vers l'indice orage (§1) |

C'est la seule variable qui alimente **à la fois** un malus de sécurité (Niveau 1) et un bonus de qualité (Niveau 2).

### 2.4 Lien avec le scoring perso

L'optimum dépend du pilote : le « vert » d'un débutant (calme, gradient mou) est le « bof » d'un cross-pilote, et le « sportif » qui fait fuir un élève est le jour rêvé du cross-man.

→ Cet axe a sa place dans le **scoring personnel** : un curseur d'intensité thermique souhaitée (« je veux du calme » ↔ « je veux que ça pousse »), au même titre que les plages de vent perso.

---

## 3. Indice de turbulence composite

### 3.1 Fondement physique

Le **nombre de Richardson** (flottabilité thermique ÷ cisaillement²) est la grandeur de référence pour quantifier la turbulence d'une masse d'air : sous un seuil, l'air devient turbulent. Mais Ri fond les deux sources en un chiffre unique et perd l'info « quel type ». Pour un pilote, c'est gênant — la décision diffère selon la source. On construit donc un **proxy décomposé** qui s'en inspire tout en restant lisible.

### 3.2 Les deux composantes (normalisées 0–100)

**Turbulence thermique** — issue du gradient (§2.2) et du CAPE.
C'est la *rugosité* : workload, fermetures actives. Se gère, se calme en début/fin de journée.

**Turbulence mécanique** — issue de :
- le **cisaillement** = différence **vectorielle** entre vent sol et vent altitude (capte l'écart de vitesse **et** le changement de direction) ;
- le **facteur de rafales** = `rafale / vent_moyen` au sol (mesure empirique directe).

C'est le *danger* : rotors, plaquage sous le vent, blowback.

```
Facteur de rafales :
  ratio = rafale / vent_moyen
  ratio > ~1.6–1.8   → malus mécanique, indépendamment des valeurs brutes

Cisaillement vectoriel (sol → 850 hPa, p.ex.) :
  shear = || V_alt - V_sol ||   (somme vectorielle, pas scalaire)
```

### 3.3 Combinaison non-linéaire

Thermique fort + vent fort ≠ « un peu des deux » : les thermiques sont déchirés par le cisaillement → violence non-linéaire. D'où un **terme croisé** :

```
T_indice = T_therm + T_méca + α · (T_therm × T_méca) / 100
                              └── pénalise la présence SIMULTANÉE ──┘

α : coefficient de calibration (à ajuster empiriquement, point de départ ~0.5)
```

Mapping en 4 bandes :

| Indice | Pastille | Libellé |
|---|---|---|
| lisse | 🟢 | air calme |
| actif | 🟡 | un peu de mouvement |
| sportif | 🟠 | air cassant — expérience requise |
| sévère | 🔴 | turbulence forte — déconseillé |

La part **mécanique** porte le risque vital → poids supérieur pour le déclenchement du rouge.

### 3.4 Point de conception — ordre du consensus

> **Calculer l'indice par modèle d'abord, PUIS faire le vote.**

Si on prend le consensus variable par variable *avant* de combiner, on risque de marier le scénario venteux d'un modèle avec le scénario instable d'un autre → on double artificiellement la turbulence. Un indice par modèle reste cohérent en interne (un modèle venteux *et* instable produit un indice logiquement élevé). Le consensus se fait sur l'indice **final**.

### 3.5 Décomposition visible

Garder la ventilation thermique / mécanique dans l'onglet **Détail du scoring** (comme les couleurs par paramètre actuelles), car la décision pilote diffère :
- turbulence **thermique** → décaler le créneau (10 h ou 17 h) ;
- turbulence **mécanique** → ne pas voler du tout.

Le chiffre unique pour la pastille, la ventilation pour comprendre.

---

## 4. Variables à ajouter au fetch météo

| Variable | Indice concerné | Niveau |
|---|---|---|
| `cape` | orage, thermique, turbulence | surface |
| `convective_inhibition` | orage | surface |
| `lifted_index` | orage | surface |
| `convective_precipitation` | orage | surface |
| `temperature_2m` (déjà présent) | gradient | surface |
| `temperature_850hPa` | gradient | niveau pression |
| `boundary_layer_height` | thermique | surface |
| `wind_speed_850hPa`, `wind_direction_850hPa` | cisaillement, vent altitude | niveau pression |

> Vérifier la disponibilité **par modèle** et prévoir la dégradation gracieuse partout.

---

## 5. Logique de consensus (transversale)

Règles communes aux trois indices :

1. **Biais sécuritaire** : sur les éliminatoires (orage, turbulence), le pire scénario tire vers le rouge — jamais la moyenne.
2. **Pondération par maille** : majorer les modèles convection-permitting (AROME, ICON-D2, HARMONIE) pour tout ce qui touche la convection.
3. **Indice par modèle d'abord, vote ensuite** (cf. §3.4) pour les indices composites.
4. **Confiance** : exposer le % de convergence comme pour les variables existantes.

---

## 6. Limites & garde-fous (à intégrer au produit)

- Ces indices captent la turbulence **de la masse d'air** (convective + cisaillement synoptique). Ils ne voient **pas** le rotor local derrière une crête précise — la maille des modèles est trop grossière.
- C'est de **l'aide à la décision** (« l'air est cassant aujourd'hui »), pas une garantie spot par spot. Ne pas survendre la pastille.
- Le rotor local dépend de la connaissance **site × direction de vent**, donnée fine non disponible à l'échelle de ~700 sites au départ. Possible modulateur par site **plus tard**.
- Tout seuil d'acceptabilité reste **par pilote** (scoring perso), pas figé dans l'indice global.

---

## 7. Prochaines étapes

1. Ajouter les variables convectives au fetch + audit de disponibilité par modèle.
2. Implémenter l'indice orageux (le plus simple, le plus directement utile).
3. Implémenter l'indice de stabilité thermique (gradient + couche convective).
4. Implémenter l'indice de turbulence composite (calibrer `α` et les poids).
5. Étendre `computeParamColors` et le `detail` JSON pour porter les nouveaux axes.
6. Exposer la ventilation dans l'onglet Détail + curseur d'intensité dans le scoring perso.
7. Backfill éventuel via une commande artisan dédiée (cf. `scores:recompute-detail-colors`).
