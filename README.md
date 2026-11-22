# Sophro du jour

Application web installable (PWA) : chaque jour, un **instant** de sophrologie (phrase, image et/ou mini-séance audio). Dans l'interface, chaque publication s'appelle un « instant ».
Hébergée sur **https://sophro.schwaller.fr** (o2switch), déployée automatiquement depuis GitHub.

## Fonctionnement

- **Site public** (`/`) : le contenu du jour, les 7 jours précédents, un bouton *Partager* et l'installation sur l'écran d'accueil.
- **Administration** (`/admin/`) : créer, modifier, programmer et supprimer les contenus ; aperçu des 14 prochains jours.
- **Choix du contenu du jour** (dans l'ordre) :
  1. un contenu programmé **à cette date précise** ;
  2. un contenu **de saison** (« chaque année du … au … », ex. Noël, printemps) : ceux pas encore vus cette saison passent en priorité, ensuite ils sont mélangés aux autres ;
  3. un instant réservé à **ce jour de la semaine** (« chaque lundi », « lundi et vendredi ») : s'il y en a plusieurs, ils passent chacun leur tour ;
  4. sinon un **tirage au hasard** parmi les contenus « au hasard » et les anciens contenus datés, en favorisant ceux qui n'ont pas été montrés depuis longtemps.
  Les contenus de saison ne sortent jamais en dehors de leur période. Le choix est mémorisé : tout le monde voit le même contenu dans la journée (heure de Paris).
- **Partage** : sur téléphone, le menu de partage natif (WhatsApp, Instagram, Messenger, SMS… avec l'image quand il y en a une) ; sur ordinateur, un menu Facebook / WhatsApp / X / LinkedIn / e-mail / copier le lien.
  Chaque contenu a son lien `/p/<numéro>` avec un aperçu (titre, texte, image) sur les réseaux sociaux ; pour un texte seul, une image est générée automatiquement.

## Import de contenus (CSV)

Admin → *Importer* : un fichier CSV avec les colonnes `titre ; texte ; quand ; date ; debut ; fin`
(`quand` = `hasard` ; `date` avec `date` en JJ/MM/AAAA pour une seule fois ; `chaque année` avec `date` en JJ/MM pour un jour fixe répété tous les ans, ou `debut`/`fin` en JJ/MM pour une période ; un jour de la semaine, ex. `lundi` ou `lundi et vendredi`, ou `semaine` avec une colonne `jours`).
Les doublons (même titre et même texte) sont ignorés : réimporter le même fichier est sans risque.
Pour une période annuelle, les contenus d'un seul jour (ex. 25/12) passent avant ceux d'une période plus longue.

## Fonds de saison

Le fond du site public change tout seul : printemps (20 mars), été (21 juin), automne (22 septembre), hiver (21 décembre).
Fichiers : `assets/seasons/*.svg`. Aperçu d'une autre saison : `/?saison=printemps` (ou `ete`, `automne`, `hiver`).

## Notification du matin et publication automatique

Une seule tâche cron, chaque matin à 7 h 30 (cPanel → *Tâches cron*) :

```sh
php ~/sophro.schwaller.fr/cron/daily.php >> ~/sophro-cron.log 2>&1
```

Elle fixe le contenu du jour, puis :
- **envoie la notification** « Votre instant du jour est arrivé » à ceux qui ont touché « Me prévenir chaque jour » dans l'app, et pose une **pastille** sur l'icône (effacée à l'ouverture). Android : oui. iPhone : iOS 16.4+, app ajoutée à l'écran d'accueil. Les clés de notification (VAPID) sont créées automatiquement par le serveur ;
- **publie le contenu** sur la Page Facebook et/ou le compte Instagram professionnel, si c'est activé dans l'admin (*Réseaux & notifications* : identifiant de page, identifiant Instagram, jeton d'accès de page Meta). Image utilisée : la photo du contenu, ou une carte carrée générée avec le texte.

Chaque étape n'est faite qu'une fois par jour. Options : `--force`, `--no-push`, `--no-social`.

## Technique

PHP 8 + SQLite, sans dépendance ni étape de compilation.

| Dossier / fichier | Rôle |
|---|---|
| `index.php`, `og.php` | Site public, image de partage générée |
| `admin/` | Administration (mot de passe), réglages réseaux sociaux |
| `api/` | Abonnement aux notifications, résumé du jour pour la notification |
| `cron/daily.php` | Tâche du matin (bloquée depuis le web) |
| `lib/` | Base de données, choix du jour, envoi de fichiers (non accessible depuis le web) |
| `data/sophro.sqlite` | Base de données, créée automatiquement (non versionnée, jamais écrasée au déploiement) |
| `uploads/` | Images et audios envoyés (non versionnés, jamais écrasés au déploiement) |
| `sw.js`, `manifest.webmanifest`, `assets/` | Application installable, styles, icônes, police Lora (licence OFL) |

## Mise en ligne (Git cPanel chez o2switch)

o2switch récupère le code depuis ce dépôt (cPanel → *Git Version Control*, avec une tâche cron qui fait `git pull`).
Le dossier cloné doit être la racine du sous-domaine `sophro.schwaller.fr` (SSL activé, PHP 8.1 ou plus).

- **Aucun identifiant dans GitHub.** À la première visite de `/admin/`, le site demande de créer le mot de passe d'administration (stocké chiffré dans la base). Il se change ensuite dans l'admin, menu *Mot de passe*.
  À faire juste après la première mise en ligne, pour que personne d'autre ne le crée avant.
- `data/` (base SQLite) et `uploads/` (images, audios) ne sont pas dans Git : un `git pull` ne les touche jamais.
- Le dossier `.git`, `lib/`, `data/`, `cron/` et `tools/` sont bloqués par `.htaccess`.
- GitHub Actions vérifie seulement la syntaxe PHP à chaque envoi (`.github/workflows/check.yml`).

Exemple de tâche cron (cPanel → *Tâches cron*, toutes les 15 minutes) :

```sh
cd ~/sophro.schwaller.fr && git pull --ff-only -q
```

## Tester en local

```sh
php -S localhost:8000 tools/router.php
```

## Aperçu sur les réseaux sociaux

Chaque instant partagé (texte, image ou audio) a le même aperçu 1200×630 : `og.php?p=<numéro>`.
Pour une image, la photo est recadrée à ce format avec le titre en bas ; pour un texte, une carte est générée.
Facebook garde un aperçu en mémoire : après une modification, utiliser l'outil de débogage de partage de Facebook pour le rafraîchir.

## Statistiques

Page « Statistiques » dans l'admin : nombre de personnes différentes qui ont ouvert l'instant chaque jour (dont depuis l'appli installée ou la notification), liens partagés ouverts, abonnés aux notifications.
Sans traceur externe ni cookie publicitaire : un numéro au hasard est gardé sur l'appareil (localStorage) et envoyé haché à `api/hit.php`. Les visites de l'admin connectée et les robots ne sont pas comptés ; les données de plus de 13 mois sont effacées.
