<?php
// Konfiguration. Für MySQL (cyon): 'dsn' => 'mysql:host=localhost;dbname=DBNAME;charset=utf8mb4', 'db_user' => '...', 'db_pass' => '...'
return [
  'dsn'     => 'sqlite:' . __DIR__ . '/data/swiftwater.sqlite',
  'db_user' => null,
  'db_pass' => null,
  'admin_user' => 'admin',
  'admin_pass' => 'BITTE-AENDERN',   // Nur für das erstmalige Anlegen des Admin-Kontos
];
