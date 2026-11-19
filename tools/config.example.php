<?php
// Copie de config.local.php (créé automatiquement au déploiement par GitHub Actions).
// Pour tester en local : cp tools/config.example.php config.local.php
return [
    'site_url' => '',
    // mot de passe de test : "sophro"
    'admin_password_hash' => password_hash('sophro', PASSWORD_DEFAULT),
];
