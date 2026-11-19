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

## Déploiement (GitHub Actions → FTP o2switch)

Chaque `push` sur `main` envoie le site sur o2switch (`.github/workflows/deploy.yml`).
À configurer une fois dans GitHub, *Settings → Secrets and variables → Actions* :

| Secret | Valeur |
|---|---|
| `FTP_SERVER` | serveur FTP o2switch (ex. `ftp.schwaller.fr`, visible dans cPanel → Comptes FTP) |
| `FTP_USERNAME` | identifiant FTP (idéalement un compte FTP limité au dossier du sous-domaine) |
| `FTP_PASSWORD` | mot de passe de ce compte FTP |
| `ADMIN_PASSWORD` | mot de passe de l'administration du site |

Variables facultatives (onglet *Variables*) : `FTP_DIR` (dossier du sous-domaine sur le serveur, par défaut `sophro.schwaller.fr/` ; mettre `/` si le compte FTP est déjà limité à ce dossier) et `SITE_URL` (par défaut `https://sophro.schwaller.fr`).

Côté o2switch : créer le sous-domaine `sophro.schwaller.fr` dans cPanel (dossier `sophro.schwaller.fr`) et activer le certificat SSL (AutoSSL). PHP 8.1 ou plus.

## Tester en local

```sh
cp tools/config.example.php config.local.php   # mot de passe admin de test : sophro
php -S localhost:8000 tools/router.php
```
