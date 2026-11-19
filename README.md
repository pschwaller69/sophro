# Sophro du jour

Application web installable (PWA) : chaque jour, une publication de sophrologie (phrase, image et/ou mini-séance audio).
Hébergée sur **https://sophro.schwaller.fr** (o2switch), déployée automatiquement depuis GitHub.

## Fonctionnement

- **Site public** (`/`) : le contenu du jour, les 7 jours précédents, un bouton *Partager* et l'installation sur l'écran d'accueil.
- **Administration** (`/admin/`) : créer, modifier, programmer et supprimer les contenus ; aperçu des 14 prochains jours.
- **Choix du contenu du jour** (dans l'ordre) :
  1. un contenu programmé **à cette date précise** ;
  2. un contenu **de saison** (« chaque année du … au … », ex. Noël, printemps) : ceux pas encore vus cette saison passent en priorité, ensuite ils sont mélangés aux autres ;
  3. sinon un **tirage au hasard** parmi les contenus « au hasard » et les anciens contenus datés, en favorisant ceux qui n'ont pas été montrés depuis longtemps.
  Les contenus de saison ne sortent jamais en dehors de leur période. Le choix est mémorisé : tout le monde voit le même contenu dans la journée (heure de Paris).
- **Partage** : sur téléphone, le menu de partage natif (WhatsApp, Instagram, Messenger, SMS… avec l'image quand il y en a une) ; sur ordinateur, un menu Facebook / WhatsApp / X / LinkedIn / e-mail / copier le lien.
  Chaque contenu a son lien `/p/<numéro>` avec un aperçu (titre, texte, image) sur les réseaux sociaux ; pour un texte seul, une image est générée automatiquement.

## Technique

PHP 8 + SQLite, sans dépendance ni étape de compilation.

| Dossier / fichier | Rôle |
|---|---|
| `index.php`, `og.php` | Site public, image de partage générée |
| `admin/` | Administration (mot de passe) |
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
- Le dossier `.git`, `lib/`, `data/` et `tools/` sont bloqués par `.htaccess`.
- GitHub Actions vérifie seulement la syntaxe PHP à chaque envoi (`.github/workflows/check.yml`).

Exemple de tâche cron (cPanel → *Tâches cron*, toutes les 15 minutes) :

```sh
cd ~/sophro.schwaller.fr && git pull --ff-only -q
```

## Tester en local

```sh
php -S localhost:8000 tools/router.php
```
