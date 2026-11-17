<?php
// Konfiguration. Für MySQL (cyon): 'dsn' => 'mysql:host=localhost;dbname=DBNAME;charset=utf8mb4', 'db_user' => '...', 'db_pass' => '...'
return [
  'dsn'     => 'sqlite:' . __DIR__ . '/data/swiftwater.sqlite',
  'db_user' => null,
  'db_pass' => null,
  'admin_user' => 'admin',
  'admin_pass' => 'BITTE-AENDERN',   // Nur für das erstmalige Anlegen des Admin-Kontos
  // 'push_subject' => 'mailto:it@example.ch', // optional: Kontakt für die Push-Dienste (Web-Push, VAPID), Standard https://<Host>
  // Optional: Push für die nativen Apps (Issue #108) über Firebase Cloud Messaging. Pfad zur JSON-Datei des Dienstkontos (service account) des Firebase-Projekts
  // der App (Firebase-Konsole → Projekteinstellungen → Dienstkonten → Neuen privaten Schlüssel generieren). Datei ausserhalb des Webroots oder in api/data/ ablegen, nie committen.
  // Ohne diesen Eintrag erhalten die Apps keinen Push und überwachen den Server nur per Polling.
  // 'fcm' => __DIR__ . '/data/firebase-service-account.json',
  // Optional: Mandanten (Issue #60). Die zentralen Tabellen (Organisationen, Konten, Sitzungen, gemeinsame Einstellungen; Präfix pf_) liegen
  // standardmässig in der Datenbank oben. Für eine eigene zentrale Datenbank:
  // 'platform_dsn' => 'mysql:host=localhost;dbname=DBNAME_PLATTFORM;charset=utf8mb4', 'platform_user' => '...', 'platform_pass' => '...',
];
