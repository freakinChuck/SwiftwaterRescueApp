<?php
// Swiftwater Rescue API – Aufruf: api/index.php?r=<route>
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('serialize_precision', '-1'); // Zahlen im JSON kurz ausgeben (47.481 statt 47.48100000000000164…), Hosting-Einstellung übersteuern
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE);
  if (!empty($GLOBALS['pushQ'])) { // Web-Push erst nach der Antwort senden (Issue #67), damit z. B. «Alarm auslösen» nicht auf die Push-Dienste wartet
    ignore_user_abort(true); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); else flush(); // cyon läuft mit LiteSpeed
    try { pushFlush(); } catch (Throwable $e) { error_log('Push: ' . $e->getMessage()); } }
  exit; }
function fail($m, $c = 400) { out(['error' => $m], $c); }
// Unerwartete Fehler (z. B. Datenbank gesperrt) als JSON melden statt leerer 500-Antwort; offene Transaktion zurückrollen, Details ins Fehlerprotokoll
set_exception_handler(function ($e) { global $db; error_log('API ' . ($_GET['r'] ?? '') . ': ' . $e->getMessage());
  try { if ($db && $db->inTransaction()) $db->rollBack(); } catch (Throwable $x) {}
  fail('Serverfehler, bitte nochmals versuchen', 500); });

$db = new PDO($cfg['dsn'], $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$my = str_starts_with($cfg['dsn'], 'mysql');
// SQLite im WAL-Modus: Lesende blockieren Schreibende nicht (und umgekehrt). Sonst sperrt ein langer Request (z. B. Overpass) mit offenem
// Lesezugriff die Datenbank, sobald jemand schreiben will, und alle weiteren Requests laufen nach dem Timeout in einen Serverfehler.
if (!$my) { $db->exec('PRAGMA journal_mode=WAL'); $db->exec('PRAGMA busy_timeout=10000'); }
$ai = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tx = $my ? 'VARCHAR(190)' : 'TEXT';
foreach ([
 "CREATE TABLE IF NOT EXISTS users(id $ai, username $tx UNIQUE, name $tx, pass_hash $tx, is_admin INT DEFAULT 0, active INT DEFAULT 1, photo MEDIUMTEXT, token $tx, exercise_mode INT DEFAULT 0, must_change INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS roles(id $ai, name $tx, color $tx, is_lead INT DEFAULT 0, active INT DEFAULT 1, is_post INT DEFAULT 0, can_message INT DEFAULT 1)",
 // Aufzeichnung fürs Replay in der Verwaltung: record = aktueller Zustand (0 aus/beendet, 1 läuft, 2 pausiert), recorded=1 sobald Daten vorhanden sind
 // Öffentliche Ansicht ohne Login (public.html?k=<public_token>): public_token NULL = aus; public_anon=1 blendet Namen aus
 "CREATE TABLE IF NOT EXISTS incidents(id $ai, message TEXT, lat DOUBLE, lon DOUBLE, alarm_at BIGINT, ended_at BIGINT NULL, flow_ms DOUBLE NULL, created_by INT, is_exercise INT DEFAULT 0, record INT DEFAULT 0, recorded INT DEFAULT 0, public_token $tx NULL, public_anon INT DEFAULT 1, pls_at BIGINT NULL)",
 // Aufzeichnung: Standortverlauf je Einsatzkraft (user_id) bzw. markierte Standorte je Rettling (rescuee_id)
 "CREATE TABLE IF NOT EXISTS track(id $ai, incident_id INT, user_id INT NULL, rescuee_id INT NULL, ts BIGINT, lat DOUBLE, lon DOUBLE)",
 // Aufzeichnung der Luftfahrzeuge in der Relevanzzone (Issue #73): hex = Transponder-Adresse, alt in ft (barometrisch, 0 = am Boden), trk = Kurs
 "CREATE TABLE IF NOT EXISTS air_track(id $ai, incident_id INT, hex $tx, ts BIGINT, lat DOUBLE, lon DOUBLE, alt INT NULL, trk INT NULL, cs $tx NULL, reg $tx NULL, type $tx NULL, cat $tx NULL)",
 // Zeiträume, in denen aufgezeichnet wurde (stop NULL = läuft); Pausen liegen dazwischen
 "CREATE TABLE IF NOT EXISTS rec_periods(id $ai, incident_id INT, start BIGINT, stop BIGINT NULL)",
 "CREATE TABLE IF NOT EXISTS participants(id $ai, incident_id INT, user_id INT, mode $tx, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, left_at BIGINT NULL, log_lat DOUBLE NULL, log_lon DOUBLE NULL, log_at BIGINT NULL, fast_at BIGINT NULL, UNIQUE(incident_id,user_id))",
 // lat/lon: Standort der sendenden Person beim Senden; mark=1: als Markierung auf der Karte (mark_lat/mark_lon)
 "CREATE TABLE IF NOT EXISTS messages(id $ai, incident_id INT, from_user INT, text TEXT, recipients TEXT NULL, created BIGINT, lat DOUBLE NULL, lon DOUBLE NULL, mark INT DEFAULT 0, mark_lat DOUBLE NULL, mark_lon DOUBLE NULL, unmarked BIGINT NULL)",
 "CREATE TABLE IF NOT EXISTS incident_log(id $ai, incident_id INT, ts BIGINT, user_id INT NULL, kind $tx, text TEXT)",
 "CREATE TABLE IF NOT EXISTS rescuee_marks(id $ai, incident_id INT, user_id INT, lat DOUBLE, lon DOUBLE, ts BIGINT, manual INT DEFAULT 0)",
 // Einrückoptionen beim Annehmen eines Einsatzes; participants.mode verweist auf code
 // lead_rights: Option mit den Rechten der Einsatzleitung, aber ohne Spezialrolle (z. B. Koordinator KP Rück)
 // counts: zählt im Zähler am Kartenknopf «Einsatzkräfte» (Standard ja, Koordinator KP Rück nein)
 "CREATE TABLE IF NOT EXISTS modes(id $ai, code $tx UNIQUE, name $tx, share_location INT DEFAULT 1, active INT DEFAULT 1, lead_rights INT DEFAULT 0, counts INT DEFAULT 1)",
 // Einstellungen der Verwaltung als Schlüssel/Wert, z. B. msg_no_role
 "CREATE TABLE IF NOT EXISTS settings(k $tx PRIMARY KEY, v TEXT)",
 // Positionen und Bereiche der Einsatzleitung: kind point|area, pts = JSON [[lat,lon],…]; removed = Zeitpunkt des Entfernens
 "CREATE TABLE IF NOT EXISTS pois(id $ai, incident_id INT, kind $tx, name $tx, color $tx, pts TEXT, created_by INT, created BIGINT, removed BIGINT NULL)",
 // Rettlinge (vermisste Personen) eines Einsatzes: letzter markierter Standort (lat/lon, loc_at, loc_by), gerettet (rescued_at, rescued_by); removed = entfernt
// pls_lat/pls_lon/pls_at: eigener PLS mit Zeitpunkt (Issue #24), NULL = PLS des Einsatzes
 "CREATE TABLE IF NOT EXISTS rescuees(id $ai, incident_id INT, name $tx, descr TEXT, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, loc_by INT NULL, rescued_at BIGINT NULL, rescued_by INT NULL, created_by INT, created BIGINT, removed BIGINT NULL, pls_lat DOUBLE NULL, pls_lon DOUBLE NULL, pls_at BIGINT NULL)",
 // Externe Einsatzkräfte ohne App-Konto mit festem Standort (Issue #39), z. B. Sicherungs- und Beobachtungsposten der Feuerwehr; moved = zuletzt verschoben, removed = entfernt
 "CREATE TABLE IF NOT EXISTS externals(id $ai, incident_id INT, name $tx, descr TEXT, role_id INT NULL, lat DOUBLE, lon DOUBLE, created_by INT, created BIGINT, moved BIGINT NULL, removed BIGINT NULL)",
 // Vorlagen dafür aus der Verwaltung; lat/lon NULL = Standort beim Erfassen auf der Karte wählen; deaktiviert statt gelöscht
 // Web-Push-Abonnements je Gerät (Issue #67): endpoint des Push-Dienstes, Schlüssel des Browsers (p256dh, auth, base64url)
 "CREATE TABLE IF NOT EXISTS push_subs(id $ai, user_id INT, endpoint TEXT, p256dh $tx, auth $tx, created BIGINT)",
 // Benutzerrollen (Issue #63): perms = JSON-Liste der Rechte (PERMS), sys 'admin' = Administrator (alle Rechte, auch künftige, nicht bearbeitbar), def = bei neuen Benutzern vorausgewählt.
 // Eine Person kann mehrere Rollen haben (user_uroles) und erhält alle Rechte all ihrer Rollen
 "CREATE TABLE IF NOT EXISTS uroles(id $ai, name $tx, perms TEXT, sys $tx NULL, def INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS user_uroles(user_id INT, urole_id INT, PRIMARY KEY(user_id, urole_id))",
 "CREATE TABLE IF NOT EXISTS ext_templates(id $ai, name $tx, descr TEXT, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, active INT DEFAULT 1)",
 // Verwendung je Overpass-Server (Issue #45): src server = Abfrage vom Hosting, device = direkte Abfrage des Geräts (Rückfall); ok_ms = Summe der Antwortzeiten der erfolgreichen
 "CREATE TABLE IF NOT EXISTS overpass_stats(url $tx, src $tx, uses INT DEFAULT 0, ok INT DEFAULT 0, fails INT DEFAULT 0, ok_ms BIGINT DEFAULT 0, last_ok BIGINT NULL, last_fail BIGINT NULL, last_err $tx NULL, PRIMARY KEY(url, src))",
 // Vorbereitete Übungen (Issue #29): Titel (nur für Verwaltung, Übungsleitung und Warteseite), Aufgebotsmeldung (message), Übungsleitung (leads = JSON-Liste von User-IDs,
 // Rechte der Einsatzleitung, nicht Teil der Übung), invite (aufgebotene Personen, JSON-Liste von User-IDs, NULL = alle; nur sie werden alarmiert, teilnehmen dürfen alle), Aufzeichnung, vorbereiteter öffentlicher Link (public_token, schon vor dem Start gültig); incident_id = gestartete Übung; active 0 = entfernt
 "CREATE TABLE IF NOT EXISTS ex_plans(id $ai, title $tx, message TEXT, leads TEXT, record INT DEFAULT 1, public_token $tx NULL, public_anon INT DEFAULT 1, created_by INT, created BIGINT, incident_id INT NULL, active INT DEFAULT 1, invite TEXT NULL)",
 // Vorbereitete Elemente einer Übung, erst nach der Freigabe durch die Übungsleitung sichtbar: kind pls (PLS des Einsatzes setzen/verschieben), res (neuer Rettling mit PLS: name, descr),
 // seen (Rettling gesichtet: neuer PLS für den Rettling aus dem Element ref_id; ref_id NULL = neuer Rettling mit name bzw. unbekannter Rettling ohne name, erfasst bei der Freigabe); ago = Zeitpunkt des PLS in Minuten vor der Freigabe (NULL = Übungsleitung gibt ihn bei der Freigabe ein);
 // ext (neue externe Einsatzkraft: name, descr, role_id, abbr, color; ext_id nach der Freigabe), extdel (entfernt die externe Einsatzkraft aus dem Element ref_id, Standort wie dieses)
 // auto = automatisch freigeben so viele Minuten nach dem Übungsstart (NULL = nur von Hand; released_by NULL = automatisch freigegeben); msg = Meldung, geht bei der Freigabe als Nachricht an alle
 "CREATE TABLE IF NOT EXISTS ex_injects(id $ai, plan_id INT, kind $tx, label $tx, name $tx NULL, descr TEXT NULL, lat DOUBLE, lon DOUBLE, ago INT DEFAULT 0, msg TEXT NULL, ref_id INT NULL, released BIGINT NULL, released_by INT NULL, rescuee_id INT NULL, removed INT DEFAULT 0, auto INT NULL, role_id INT NULL, abbr $tx NULL, color $tx NULL, ext_id INT NULL)",
  "CREATE TABLE IF NOT EXISTS aar(incident_id INT PRIMARY KEY, data MEDIUMTEXT, updated BIGINT, updated_by INT NULL)", // After Action Review (Issue #55)
  "CREATE TABLE IF NOT EXISTS aar_img(id $ai, incident_id INT, field_id $tx, caption TEXT, data MEDIUMTEXT, w INT, h INT, created BIGINT, created_by INT NULL)", // Bilder im After Action Review (Feldart img)
  // Vor dem Anonymisieren gesichertes Review (aarArchive): snap/aar_arch_img bis das PDF steht, das PDF in Stücken (aar_arch_part, unabhängig von Upload- und Paketgrenzen), unveränderlich ab pdf_at
  "CREATE TABLE IF NOT EXISTS aar_arch(incident_id INT PRIMARY KEY, created BIGINT, snap LONGTEXT NULL, pdf_at BIGINT NULL, pdf_parts INT NULL, pdf_name TEXT NULL, pdf_sha TEXT NULL)",
  "CREATE TABLE IF NOT EXISTS aar_arch_img(incident_id INT, img_id INT, field_id $tx, caption TEXT, data MEDIUMTEXT, w INT, h INT, PRIMARY KEY(incident_id,img_id))",
  "CREATE TABLE IF NOT EXISTS aar_arch_part(incident_id INT, n INT, data MEDIUMTEXT, PRIMARY KEY(incident_id,n))",
  // AI-Assistent (Issue #111): jede Abfrage beim Anbieter (Kostenbremse, Statistik) und die letzte Antwort je Funktion und Objekt (Companion, keine doppelten Kosten)
  "CREATE TABLE IF NOT EXISTS ai_usage(id $ai, ts BIGINT, user_id INT, fn $tx, incident_id INT NULL, tokens INT DEFAULT 0, ok INT DEFAULT 1, err TEXT NULL)",
  "CREATE TABLE IF NOT EXISTS ai_cache(k VARCHAR(64) PRIMARY KEY, ts BIGINT, ctx_hash VARCHAR(64), text MEDIUMTEXT, tokens INT DEFAULT 0, model $tx NULL)",
] as $s) $db->exec($s);
// Migrationen für ältere Installationen (schlagen bei vorhandenen Spalten still fehl)
try { $db->exec("ALTER TABLE roles ADD COLUMN is_post INT DEFAULT 0"); $db->exec("UPDATE roles SET is_post=1 WHERE name LIKE 'Sicherungs%'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN lead_rights INT DEFAULT 0"); $db->exec("UPDATE modes SET lead_rights=1 WHERE code='coordinator'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN counts INT DEFAULT 1"); $db->exec("UPDATE modes SET counts=0 WHERE code='coordinator'"); } catch (Throwable $e) {}
foreach (['aar' => ['status INT DEFAULT 0', 'rel_at BIGINT NULL', 'rel_by INT NULL'], 'roles' => ['can_message INT DEFAULT 1'], 'messages' => ['lat DOUBLE NULL', 'lon DOUBLE NULL', 'mark INT DEFAULT 0', 'mark_lat DOUBLE NULL', 'mark_lon DOUBLE NULL', 'unmarked BIGINT NULL', "from_label $tx NULL", 'to_label TEXT NULL'],'participants' => ["label $tx NULL", 'log_lat DOUBLE NULL', 'log_lon DOUBLE NULL', 'log_at BIGINT NULL', 'fast_at BIGINT NULL'], 'users' => ['is_planner INT DEFAULT 0', 'exercise_mode INT DEFAULT 0', 'must_change INT DEFAULT 0', "coord_sys $tx NULL", "nav_app $tx NULL", 'ai_modes TEXT NULL'], 'externals' => ['manual INT DEFAULT 0', "abbr $tx NULL", "color $tx NULL"], 'ext_templates' => ["abbr $tx NULL", "color $tx NULL"], 'incidents' => ['is_exercise INT DEFAULT 0', 'record INT DEFAULT 0', 'recorded INT DEFAULT 0', "public_token $tx NULL", 'public_anon INT DEFAULT 1', 'pls_at BIGINT NULL', 'plan_id INT NULL', 'public_log INT DEFAULT 1', 'rp_anon TEXT NULL', 'log_anon INT DEFAULT 0', 'air INT NULL', 'view_anon INT DEFAULT 0', 'txt_anon INT DEFAULT 0', 'part_anon INT DEFAULT 0'], 'incident_log' => ["actor $tx NULL"], 'rescuees' => ['pls_lat DOUBLE NULL', 'pls_lon DOUBLE NULL', 'pls_at BIGINT NULL'], 'ex_plans' => ['invite TEXT NULL'], 'ex_injects' => ['auto INT NULL', 'role_id INT NULL', "abbr $tx NULL", "color $tx NULL", 'ext_id INT NULL']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }
// Letzte Anmeldung (Löschen nur für Personen, die sich nie angemeldet haben); bei der Migration gelten Konten mit eigenem Passwort oder Token als angemeldet (0 = Zeitpunkt unbekannt)
try { $db->exec("ALTER TABLE users ADD COLUMN last_login BIGINT NULL"); $db->exec("UPDATE users SET last_login=0 WHERE must_change=0 OR token IS NOT NULL"); } catch (Throwable $e) {}
try { $db->exec("CREATE INDEX track_inc ON track(incident_id,ts)"); } catch (Throwable $e) {} // schlägt bei vorhandenem Index still fehl
// Bilder im After Action Review: einmalig ein Feld «Bilder» an bereits gespeicherte Einstellungen anhängen (der Standard hat es schon); entfernbar
if (setting('aar_img_mig', '') === '') { $c = json_decode((string)setting('aar_cfg', ''), true);
  if (is_array($c) && !array_filter((array)($c['fields'] ?? []), fn($f) => ($f['type'] ?? '') === 'img')) {
    $c['seq'] = (int)($c['seq'] ?? 12) + 1; $c['fields'][] = ['id' => 'f' . $c['seq'], 'type' => 'img', 'label' => 'Bilder', 'hint' => 'Fotos, Skizzen, Kartenausschnitte'];
    setSetting('aar_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); }
  setSetting('aar_img_mig', '1'); }
try { $db->exec("CREATE INDEX air_track_inc ON air_track(incident_id,ts)"); } catch (Throwable $e) {}
if (!$db->query("SELECT COUNT(*) c FROM roles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO roles(name,color,is_lead,is_post) VALUES(?,?,?,?)");
  foreach ([['Einsatzleitung','#d62828',1,0],['Rettungsboot','#1d7fd1',0,0],['Rettungsschlitten','#e6a100',0,0],['Sicherungs- und Beobachtungsposten','#2a9d5c',0,1]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM modes")->fetch()['c']) { // bisherige feste Optionen, Codes bleiben für bestehende Einsätze gültig
  $st = $db->prepare("INSERT INTO modes(code,name,share_location,lead_rights,counts) VALUES(?,?,?,?,?)");
  foreach ([['base','Anfahrt via Stützpunkt',1,0,1],['direct','Anfahrt direkt',1,0,1],['logistics','Logistik',1,0,1],['coordinator','Koordinator KP Rück',0,1,0]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM users")->fetch()['c'])
  $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,must_change) VALUES(?,?,?,1,1)")->execute([$cfg['admin_user'], 'Administrator', password_hash($cfg['admin_pass'], PASSWORD_DEFAULT)]);
// Berechtigungssystem (Issue #63): beim ersten Aufruf die bisherigen festen Benutzerrollen als Rollen anlegen und zuweisen
// (alle = Einsatzkraft, is_planner = zusätzlich Übungsleiter, is_admin = zusätzlich Systemadministrator). Die Spalten is_admin/is_planner gelten danach nicht mehr.
if (!$db->query("SELECT COUNT(*) c FROM uroles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO uroles(name,perms,sys,def) VALUES(?,?,?,?)"); $ids = [];
  foreach ([['Systemadministrator', [], 'admin', 0], ['Übungsleiter', ['ex_plan'], null, 0], ['Einsatzkraft', ['inc_start', 'ex_start'], null, 1]] as [$n, $p, $sy, $d]) { $st->execute([$n, json_encode($p), $sy, $d]); $ids[] = (int)$db->lastInsertId(); }
  $st = $db->prepare("INSERT INTO user_uroles(user_id,urole_id) VALUES(?,?)");
  foreach ($db->query("SELECT id,is_admin,is_planner FROM users")->fetchAll() as $u) {
    $st->execute([(int)$u['id'], $ids[2]]);
    if ((int)$u['is_admin']) $st->execute([(int)$u['id'], $ids[0]]); elseif ((int)$u['is_planner']) $st->execute([(int)$u['id'], $ids[1]]);
  }
}
// Die feste Rolle heisst Systemadministrator (früher «Administrator»)
if ($db->query("SELECT COUNT(*) c FROM uroles WHERE sys='admin' AND name='Administrator'")->fetch()['c'])
  $db->exec("UPDATE uroles SET name='Systemadministrator' WHERE sys='admin' AND name='Administrator'");

$route = $_GET['r'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$now = time();

$MODES = ['base' => 'Anfahrt via Stützpunkt', 'direct' => 'Anfahrt direkt', 'logistics' => 'Logistik', 'coordinator' => 'Koordinator KP Rück'];
// Einsatzlog: nur Fahrten mit Fahrzeug protokollieren (nicht zu Fuss, nicht auf dem Gewässer)
const VEHICLE_MS = 7;    // ab ca. 25 km/h zwischen zwei Standortmeldungen gilt eine Bewegung als Fahrt (Gehen/Laufen liegt deutlich darunter)
const TRIP_MIN_M = 500;  // kürzere Fahrten und GPS-Sprünge nicht protokollieren
const STOP_S = 90;       // so lange ohne schnelle Meldung = Fahrt beendet
const TRIP_LOG_S = 600;  // lange Fahrten zusätzlich alle 10 Minuten protokollieren
const COORDS = ['lv95', 'lv03', 'wgs84', 'wgs84dms']; // Koordinatensysteme für die Anzeige (Umrechnung im Frontend, COORD_SYS in common.js)
// Gewässernetz (OSM via Overpass) über den Server mit Datei-Cache in api/data/overpass/ (overpassServe)
const OVERPASS = ['https://overpass-api.de/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];
// Reihenfolge der Abfrage: Einstellung overpass_urls (JSON-Liste, in der Verwaltung unter «Gewässerdaten»), leer = OVERPASS
function overpassUrls() { $v = json_decode(setting('overpass_urls', ''), true); return is_array($v) && $v ? $v : OVERPASS; }
// Issue #75: ausgeschaltete Server (Einstellung overpass_off, JSON-Liste) und Zugriff ganz aus (overpass_on = 0); abgefragt werden nur die übrigen
function overpassOff() { $v = json_decode(setting('overpass_off', ''), true); return array_values(array_intersect(overpassUrls(), is_array($v) ? $v : [])); }
function overpassActive() { return setting('overpass_on', '1') === '1' ? array_values(array_diff(overpassUrls(), overpassOff())) : []; }
// Abschaltbare Funktionen (Issue #35), je für scharfe Einsätze und Übungen getrennt (Abschnitt «Funktionen» in der Verwaltung).
// Neue Funktionen hier aufnehmen (Schlüssel => Bezeichnung) und ihre Routen in FEATURE_ROUTES; das Frontend liest features aus incident/state bzw. me.
// Rechte für Benutzerrollen (Issue #63): Schlüssel => [Gruppe, Bezeichnung, Erklärung]. Neue Rechte hier aufnehmen; Administratoren haben sie automatisch.
// Rechte im Einsatz selbst (Nachrichten, Rettlinge, Markierungen …) regeln weiterhin Einsatzleitung, Spezialrollen und Einrückoptionen.
const PERMS = [
  'inc_start' => ['Einsatz-App', 'Einsätze starten', 'Scharfe Einsätze auslösen (kritischer Alarm an alle)'],
  'ex_start' => ['Einsatz-App', 'Übungen starten', 'Übungen ohne Vorbereitung starten (vorbereitete startet die eingetragene Übungsleitung)'],
  'inc_lead' => ['Einsatz-App', 'Jeden Einsatz leiten', 'Rechte der Einsatzleitung in jedem Einsatz, an dem die Person teilnimmt'],
  'ex_plan' => ['Übungen', 'Übungen vorbereiten', 'Verwaltung → Übungen; im Einsatz die vorbereiteten Elemente sehen und freigeben'],
  'users' => ['Verwaltung', 'Benutzer verwalten', 'Anlegen, bearbeiten, Passwort zurücksetzen, deaktivieren, nie angemeldete löschen, Benutzerrollen zuweisen'],
  'uroles' => ['Verwaltung', 'Benutzerrollen verwalten', 'Rollen anlegen, ändern und entfernen'],
  'cfg_inc' => ['Verwaltung', 'Einsatz einstellen', 'Einrückoptionen, Spezialrollen, Nachrichten, Vorlagen für externe Einsatzkräfte'],
  'cfg_func' => ['Verwaltung', 'Funktionen einstellen', 'Funktionen, Aufzeichnung, Flussdistanz, Navigation'],
  'cfg_map' => ['Verwaltung', 'Karte & Daten einstellen', 'Koordinaten, Gewässerdaten (Overpass), Helikopter'],
  'cfg_ai' => ['Verwaltung', 'AI-Assistent einstellen', 'Anbieter, API-Schlüssel, erlaubte Modi je Benutzerrolle, Datenschutz und Limits des AI-Assistenten; Nutzung einsehen'],
  'stats' => ['Auswertung', 'Alle Einsätze auswerten', 'Einsatzstatistik, Log und Replay aller Einsätze'],
  'anon' => ['Auswertung', 'Anonymisieren und Aufzeichnungen löschen', 'Anonymisierung einstellen (Stufen, automatisch nach Tagen) und anwenden, auch für mehrere Einsätze; endgültige Stufen sind nicht umkehrbar'],
  'cfg_my' => ['Auswertung', 'Einsicht für Einsatzkräfte einstellen', 'Was Teilnehmende von ihren eigenen Einsätzen sehen (Meine Einsätze)'],
  'aar' => ['Auswertung', 'After Action Reviews erstellen', 'In der Einsatzstatistik je beendetem Einsatz die Angaben erfassen, als nicht relevant markieren, ansehen und herunterladen (ohne «Alle Einsätze auswerten» nur Liste und Kennzahlen, ohne Log und Replay)'],
  'aar_rel' => ['Auswertung', 'After Action Reviews freigeben', 'Als abgeschlossen freigeben (danach gesperrt und für die Teilnehmenden unter «Meine Einsätze» sichtbar) und Freigaben zurücknehmen'],
  'aar_cfg' => ['Auswertung', 'After Action Review einrichten', 'Felder, Dokumenttitel, Organisation, Logo, mitgelieferte Abschnitte und erlaubte Formate'],
  'aar_arch' => ['Auswertung', 'Gesicherte After Action Reviews abrufen', 'Das vor dem Anonymisieren gesicherte, vollständige PDF eines freigegebenen Reviews abrufen, mit allen Namen'],
];
// Einstellungen (settings/save) und das Recht, das sie ändern darf
const SETTING_PERMS = ['msg_no_role' => 'cfg_inc', 'msg_read_all' => 'cfg_inc', 'features' => 'cfg_func', 'rec_real' => 'cfg_func', 'rec_ex' => 'cfg_func', 'rd_speeds' => 'cfg_func', 'nav_off' => 'cfg_func', 'nav_default' => 'cfg_func',
  'coord_sys' => 'cfg_map', 'overpass_ttl' => 'cfg_map', 'overpass_urls' => 'cfg_map', 'overpass_off' => 'cfg_map', 'overpass_on' => 'cfg_map', 'air' => 'cfg_map', 'my_runs' => 'cfg_my', 'my_btns' => 'cfg_my', 'ai' => 'cfg_ai']; // ai: eigene Route ai/cfg/save (Prüfung aiIn), settings/save lehnt es ab
/* ---------- After Action Review (Issue #55) ---------- */
// Felder frei definierbar (Einstellung aar_cfg: Titel, Organisation, mitgelieferte Abschnitte, Felder), Werte je Einsatz in der Tabelle aar (JSON je Feld-ID).
// Das Dokument (Word/PDF) entsteht im Browser (admin.html, aarModel); der Server liefert nur die Daten. Logo als PNG-/JPEG-Data-URL in der Einstellung aar_logo.
const AAR_TYPES = ['head' => 'Zwischentitel', 'text' => 'Text einzeilig', 'area' => 'Text mehrzeilig', 'date' => 'Datum', 'time' => 'Uhrzeit', 'number' => 'Zahl', 'select' => 'Auswahl', 'check' => 'Ja/Nein', 'img' => 'Bilder'];
const AAR_IMG_MAX = 50; // Bilder je Review, je höchstens 3 MB (der Browser verkleinert auf 1600 px JPEG)
const AAR_AUTO = ['inc' => 'Einsatzdaten (Art, Zeiten, Kennzahlen)', 'people' => 'Einsatzkräfte', 'res' => 'Rettlinge', 'ext' => 'Externe Einsatzkräfte', 'log' => 'Einsatzlog'];
// Status je Review: 0 = in Arbeit, 1 = freigegeben (abgeschlossen, gesperrt, für Teilnehmende sichtbar), 2 = nicht relevant
const AAR_STATUS = [0 => 'in Arbeit', 1 => 'freigegeben', 2 => 'nicht relevant'];
const AAR_ARCH_PARTS = 400; const AAR_ARCH_CHUNK = 2200000; // gesichertes PDF: höchstens so viele Stücke zu je so vielen Zeichen Base64 (reicht für 50 Bilder zu 3 MB)
const AAR_DL = ['docx' => 'Word (.docx)', 'pdf' => 'PDF']; // Download je Format und Zustand erlaubt (Einstellung dl); Ansehen im Browser ist davon unabhängig
const AAR_DL_ST = ['wip' => 'In Arbeit', 'rel' => 'Freigegeben', 'nr' => 'Nicht relevant']; // «noch kein AAR» zählt als in Arbeit
// dl je Format und Zustand; die frühere Einstellung (nur Format ein/aus) gilt für «Freigegeben»
function aarDlNorm($d) { $r = []; foreach (AAR_DL as $k => $_) { $v = $d[$k] ?? null; foreach (AAR_DL_ST as $s => $__) $r[$k][$s] = is_array($v) ? (int)!empty($v[$s]) : ($s === 'rel' ? (int)($v ?? 1) : 0); } return $r; }
const AAR_DEFAULT = ['title' => 'After Action Review', 'org' => '', 'seq' => 13, 'auto' => ['inc' => 1, 'people' => 1, 'res' => 1, 'ext' => 1, 'log' => 0], 'dl' => ['docx' => ['wip' => 0, 'rel' => 1, 'nr' => 0], 'pdf' => ['wip' => 0, 'rel' => 1, 'nr' => 0]], 'fields' => [
  ['id' => 'f1', 'type' => 'date', 'label' => 'Datum des Reviews', 'hint' => ''],
  ['id' => 'f2', 'type' => 'text', 'label' => 'Moderation', 'hint' => ''],
  ['id' => 'f3', 'type' => 'area', 'label' => 'Teilnehmende am Review', 'hint' => ''],
  ['id' => 'f4', 'type' => 'text', 'label' => 'Wetter und Wasserstand', 'hint' => ''],
  ['id' => 'f5', 'type' => 'head', 'label' => 'Ablauf', 'hint' => ''],
  ['id' => 'f6', 'type' => 'area', 'label' => 'Was war geplant?', 'hint' => 'Auftrag, Absicht, erwarteter Ablauf'],
  ['id' => 'f7', 'type' => 'area', 'label' => 'Was ist tatsächlich passiert?', 'hint' => 'Ablauf aus Sicht der Beteiligten'],
  ['id' => 'f8', 'type' => 'area', 'label' => 'Warum gab es Abweichungen?', 'hint' => ''],
  ['id' => 'f9', 'type' => 'head', 'label' => 'Erkenntnisse', 'hint' => ''],
  ['id' => 'f10', 'type' => 'area', 'label' => 'Was lief gut?', 'hint' => 'Beibehalten'],
  ['id' => 'f11', 'type' => 'area', 'label' => 'Was können wir verbessern?', 'hint' => ''],
  ['id' => 'f12', 'type' => 'area', 'label' => 'Massnahmen', 'hint' => 'Was, wer, bis wann'],
  ['id' => 'f13', 'type' => 'img', 'label' => 'Bilder', 'hint' => 'Fotos, Skizzen, Kartenausschnitte'],
]];
// Zugriff auf ein Review: Verwaltung (aar, aar_rel) oder my=1 (Meine Einsätze: Teilnahme, freigegeben, my_runs nicht off)
// Statistik pro Person (Issue #87): eine Zeile je Teilnahme; $only = nur diese Person (Meine Statistik)
function psRows($only = null) { global $db, $now; $only = $only === null ? null : (int)$only;
  // Einsatzzeit = Summe der Abschnitte von «angenommen»/«wieder eingestiegen» bis «verlassen», der letzte bis Austritt (left_at) bzw. Einsatzende
  // Fahrten = Log-Einträge «move» (Luftlinie gemäss Log), Weg = Summe der aufgezeichneten Standorte (nur mit Aufzeichnung, Lücken über 120 s nicht, wie GAP_S im Replay)
  // Endgültig anonymisierte Einsätze bleiben personenbezogen: Log-Einträge tragen dann die Bezeichnung «Einsatzkraft N» (actor), die Spuren eine negative ID (rp_anon);
  // beide folgen der Reihenfolge der Teilnahmen (anonCtx, participants nach id), daraus ergibt sich wieder die Person
  $inc = []; foreach ($db->query("SELECT id,alarm_at,ended_at,is_exercise,log_anon,rp_anon FROM incidents") as $r) $inc[(int)$r['id']] = ints($r, ['id', 'alarm_at', 'ended_at', 'is_exercise', 'log_anon']);
  $lab = []; $n = []; foreach ($db->query("SELECT incident_id,user_id FROM participants ORDER BY incident_id,id") as $r) { $i = (int)$r['incident_id']; $n[$i] = ($n[$i] ?? 0) + 1; $lab[$i]['Einsatzkraft ' . $n[$i]] = (int)$r['user_id']; }
  $neg = []; foreach ($inc as $i => $x) if ($x['rp_anon'] !== null) foreach (json_decode($x['rp_anon'], true) ?: [] as $p) if (isset($p['user_id'], $p['name'], $lab[$i][$p['name']])) $neg[$i][(int)$p['user_id']] = $lab[$i][$p['name']];
  $ev = []; foreach ($db->query("SELECT incident_id,user_id,actor,ts,kind,text FROM incident_log WHERE kind IN ('accept','leave','move','area') AND (user_id IS NOT NULL OR actor IS NOT NULL) ORDER BY ts, id") as $r) {
   $i = (int)$r['incident_id']; $u = $r['user_id'] !== null ? (int)$r['user_id'] : ($lab[$i][(string)$r['actor']] ?? null);
   if ($u !== null && ($only === null || $u === $only)) $ev[$i . ':' . $u][] = $r; }
  $tr = []; $last = []; foreach ($db->query("SELECT incident_id,user_id,ts,lat,lon FROM track WHERE user_id IS NOT NULL ORDER BY incident_id,user_id,ts,id") as $r) {
   $i = (int)$r['incident_id']; $u = (int)$r['user_id']; if ($u < 0) $u = $neg[$i][$u] ?? null;
   if ($u === null || ($only !== null && $u !== $only)) continue;
   $k = $i . ':' . $u; $l = $last[$k] ?? null;
   if ($l && (int)$r['ts'] - (int)$l['ts'] <= 120) $tr[$k] = ($tr[$k] ?? 0) + hav((float)$l['lat'], (float)$l['lon'], (float)$r['lat'], (float)$r['lon']);
   $last[$k] = $r; }
  $rows = [];
  foreach ($db->query("SELECT p.incident_id,p.user_id,p.mode,p.left_at,u.name,u.username,u.active,m.name mode_name FROM participants p JOIN users u ON u.id=p.user_id LEFT JOIN modes m ON m.code=p.mode" . ($only === null ? '' : ' WHERE p.user_id=' . (int)$only)) as $p) {
   $i = $inc[(int)$p['incident_id']] ?? null; if (!$i) continue;
   $k = $p['incident_id'] . ':' . $p['user_id']; $e = $ev[$k] ?? []; $dec = $p['mode'] === 'declined';
   $dur = 0; $open = null; $first = null; $trips = 0; $km = 0.0; $area = null;
   foreach ($e as $x) { $t = (int)$x['ts'];
    if ($x['kind'] === 'accept') { if ($first === null) $first = $t; if ($open === null) $open = $t; }
    elseif ($x['kind'] === 'leave') { if ($open !== null) { $dur += $t - $open; $open = null; } }
    elseif ($x['kind'] === 'area') { if ($area === null) $area = $t; }
    elseif (preg_match('~ca\. ([0-9.]+) km~', $x['text'], $mm)) { $trips++; $km += (float)$mm[1]; } }
   $stop = $p['left_at'] !== null ? (int)$p['left_at'] : ($i['ended_at'] ?? $now);
   if ($open !== null && !$dec) $dur += max(0, $stop - $open);
   $row = ['incident_id' => $i['id'], 'is_exercise' => $i['is_exercise'], 'alarm_at' => $i['alarm_at'], 'declined' => $dec ? 1 : 0,
    'dur' => $dec ? 0 : $dur, 'react' => $first === null ? null : max(0, $first - $i['alarm_at']), 'area' => $area === null ? null : max(0, $area - $i['alarm_at']),
    'trips' => $trips, 'km' => round($km, 1), 'track_km' => isset($tr[$k]) ? round($tr[$k] / 1000, 1) : null, 'mode_name' => $dec ? null : $p['mode_name']];
   $row += ['user_id' => (int)$p['user_id'], 'name' => $p['name'], 'username' => $p['username'], 'active' => (int)$p['active']];
   $rows[] = $row; }

  return $rows; }
// Ab der Sicherung (aarArchive) gibt es das Review nur noch als PDF (Recht aar_arch), auch wenn die Anzeige wieder aufgehoben wird: nichts ansehen, nichts ändern
// Anonymisiert (jede Stufe ausser track, solange sie gilt): kein Review mehr. Ein freigegebenes wäre gesichert (aarSealed); bleibt eines ohne Sicherung, stammt es von vor der Sicherung und bleibt lesbar
const AAR_LOCK = "(i.rp_anon IS NOT NULL OR COALESCE(i.log_anon,0)<>0 OR COALESCE(i.view_anon,0)<>0 OR COALESCE(i.txt_anon,0)<>0 OR COALESCE(i.part_anon,0)<>0) AND NOT EXISTS (SELECT 1 FROM aar a WHERE a.incident_id=i.id AND a.status=1)";
function aarSealed($id) { global $db; $s = $db->prepare("SELECT 1 FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$id]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v !== false) fail('Dieses After Action Review ist gesichert und nur noch als PDF verfügbar.', 409);
  $s = $db->prepare("SELECT 1 FROM incidents i WHERE i.id=? AND " . AAR_LOCK); $s->execute([(int)$id]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v !== false) fail('Dieser Einsatz ist anonymisiert, ein After Action Review ist nicht mehr möglich.', 409); }
function aarView($id) { global $db; if (empty($_GET['my'])) { need('aar', 'aar_rel'); aarSealed($id); return false; }
  if (myRuns() === 'off' || !myPart($id) || !myAar()) fail('Kein Zugriff auf diesen Einsatz', 403);
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $st = $s->fetchColumn(); $s->closeCursor();
  if ($st === false || (int)$st !== 1) fail('Dieses After Action Review ist nicht freigegeben', 403);
  aarSealed($id); return true; }
// Bearbeiten (Angaben, Bilder): Recht aar, Einsatz beendet, nicht freigegeben; danach gilt das Review als in Arbeit
function aarEditable($inc) { global $db; need('aar'); aarSealed($inc['id']);
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([(int)$inc['id']]); $st = $s->fetchColumn(); $s->closeCursor();
  if ($st !== false && (int)$st === 1) fail('Das After Action Review ist freigegeben. Zum Bearbeiten zuerst die Freigabe zurücknehmen.');
  return $st !== false; }
function aarTouch($id, $exists) { global $db, $now, $uid;
  if ($exists) $db->prepare("UPDATE aar SET updated=?,updated_by=?,status=0 WHERE incident_id=?")->execute([$now, $uid, $id]);
  else $db->prepare("INSERT INTO aar(incident_id,data,updated,updated_by,status) VALUES(?,'{}',?,?,0)")->execute([$id, $now, $uid]); }
function aarImgRow($img) { global $db; $s = $db->prepare("SELECT id,incident_id FROM aar_img WHERE id=?"); $s->execute([(int)$img]); $r = $s->fetch(); $s->closeCursor(); return $r ?: fail('Bild nicht gefunden', 404); }
// Inhalt des Dokuments (Route aar/get, Sicherung aarArchive); c = anonCtx oder null
function aarDoc($inc, $c, $anon, $forced, $mine, $self) { global $db; $id = (int)$inc['id']; $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  $a = $q("SELECT data,updated,updated_by,status,rel_at,rel_by FROM aar WHERE incident_id=?")[0] ?? null;
  $nm = fn($u) => $u === null ? null : ($c ? ($c['u'][(int)$u] ?? 'Person') : ($db->query('SELECT name FROM users WHERE id=' . (int)$u)->fetchColumn() ?: '?'));
  $rpa = $inc['rp_anon'] !== null;
  $parts = array_map(fn($p) => ['name' => $c && !$rpa ? ($c['u'][$p['user_id']] ?? 'Einsatzkraft') : $p['name'], 'mode' => $p['mode_name'] ?? null, 'role' => $p['role'] ?? null, 'joined' => $p['joined'] ?? null, 'left_at' => $p['left_at'] ?? null],
    $rpa ? (json_decode($inc['rp_anon'], true) ?: []) : replayParts($id));
  $res = array_map(function ($r) use ($c) { $r = ints($r, ['id', 'loc_at', 'rescued_at', 'created', 'removed']); if ($c) { $r['name'] = $c['r'][$r['id']] ?? 'Rettling'; $r['descr'] = null; } return $r; },
    $q("SELECT id,name,descr,lat,lon,loc_at,rescued_at,created,removed FROM rescuees WHERE incident_id=? ORDER BY id"));
  $ext = array_map(function ($r) use ($c) { $r = ints($r, ['id', 'created', 'removed']); if ($c) { $r['name'] = $c['e'][$r['id']] ?? 'Externe Einsatzkraft'; $r['descr'] = null; } return $r; },
    $q("SELECT e.id,e.name,e.descr,e.created,e.removed,r.name role FROM externals e LEFT JOIN roles r ON r.id=e.role_id WHERE e.incident_id=? ORDER BY e.id"));
  $first = function ($k) use ($q) { $v = $q("SELECT MIN(ts) v FROM incident_log WHERE incident_id=? AND kind='$k'")[0]['v'] ?? null; return $v === null ? null : (int)$v; };
  $plan = $inc['plan_id'] ? ($db->query('SELECT title FROM ex_plans WHERE id=' . (int)$inc['plan_id'])->fetchColumn() ?: null) : null;
  return ['inc' => ['id' => $id, 'message' => $c ? anonText($inc['message'], $c) : $inc['message'], 'alarm_at' => (int)$inc['alarm_at'], 'ended_at' => $inc['ended_at'] === null ? null : (int)$inc['ended_at'],
      'is_exercise' => (int)$inc['is_exercise'], 'creator' => $nm($inc['created_by']), 'plan' => $plan, 'flow_ms' => $inc['flow_ms'] === null ? null : (float)$inc['flow_ms'],
      'lat' => $inc['lat'] === null ? null : (float)$inc['lat'], 'lon' => $inc['lon'] === null ? null : (float)$inc['lon'], 'first_accept' => $first('accept'), 'first_area' => $first('area')],
    'anon' => $anon, 'forced' => $forced, 'people' => $parts, 'rescuees' => $res, 'externals' => $ext, 'log' => $mine && !myBtns()['log'] ? [] : logRows($id, true, $anon, $self), 'my_anon' => $mine && myRuns() === 'anon',
    'data' => $a ? (json_decode($a['data'], true) ?: (object)[]) : (object)[], 'updated' => $a && $a['updated'] !== null ? (int)$a['updated'] : null, 'updated_by' => $a ? $nm($a['updated_by']) : null,
    'status' => $a ? (int)$a['status'] : 0, 'rel_at' => $a && $a['rel_at'] !== null ? (int)$a['rel_at'] : null, 'rel_by' => $a ? $nm($a['rel_by']) : null,
    'imgs' => array_map(fn($r) => ints($r, ['id', 'w', 'h']), $q("SELECT id,field_id field,caption,w,h FROM aar_img WHERE incident_id=? ORDER BY id"))]; } // Bilder ohne Inhalt, der kommt einzeln über aar/img
// Sicherung vor dem Anonymisieren: Ein freigegebenes Review bleibt als PDF vollständig erhalten (Tabelle aar_arch, Recht aar_arch). Das PDF entsteht im Browser (jsPDF), die automatische Anonymisierung läuft aber ohne Browser:
// Deshalb hält anonDo in derselben Transaktion zuerst den Inhalt fest (snap, Bilder in aar_arch_img); der nächste Browser mit dem Recht erzeugt daraus das PDF. Danach ist es unveränderlich (pdf_at), und snap und Bilder entfallen.
function aarArchive($inc) { global $db, $now, $my; $id = (int)$inc['id'];
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $st = $s->fetchColumn(); $s->closeCursor(); if ($st === false || (int)$st !== 1) return;
  $s = $db->prepare(($my ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . " INTO aar_arch(incident_id,created) VALUES(?,?)"); $s->execute([$id, $now]); if (!$s->rowCount()) return; // die erste Sicherung bleibt
  $fin = (int)$inc['log_anon'] || $inc['rp_anon'] !== null; $doc = aarDoc($inc, null, false, $fin, false, null);
  $doc['anon'] = $fin; $doc['cfg'] = aarCfg(); $doc['logo'] = setting('aar_logo', null); // Einrichtung mit sichern: spätere Änderungen betreffen das Archiv nicht
  $db->prepare("UPDATE aar_arch SET snap=? WHERE incident_id=?")->execute([json_encode($doc, JSON_UNESCAPED_UNICODE), $id]);
  $db->prepare("INSERT INTO aar_arch_img(incident_id,img_id,field_id,caption,data,w,h) SELECT incident_id,id,field_id,caption,data,w,h FROM aar_img WHERE incident_id=?")->execute([$id]); }
function aarArchRow($id) { global $db; $s = $db->prepare("SELECT incident_id,created,pdf_at,pdf_parts,pdf_name,pdf_sha FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$id]); $r = $s->fetch(); $s->closeCursor(); return $r ?: fail('Keine Sicherung für diesen Einsatz', 404); }
function aarCfg() { $c = json_decode((string)setting('aar_cfg', ''), true);
  if (!is_array($c)) return AAR_DEFAULT;
  $c += AAR_DEFAULT; $a = []; foreach (AAR_AUTO as $k => $_) $a[$k] = (int)($c['auto'][$k] ?? AAR_DEFAULT['auto'][$k]); $c['auto'] = $a;
  $c['dl'] = aarDlNorm((array)$c['dl']);
  return $c; }
// Einstellungen prüfen; neue Felder erhalten eine nie zuvor vergebene ID (seq), damit Werte entfernter Felder nie einem neuen Feld zufallen
function aarIn($in) { $old = aarCfg(); $s = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $n);
  $seq = (int)$old['seq']; $known = array_column($old['fields'], 'id'); $f = []; $ids = [];
  $fs = (array)($in['fields'] ?? []);
  if (count($fs) > 80) fail('Höchstens 80 Felder');
  foreach ($fs as $x) {
    if (!is_array($x)) fail('Ungültiges Feld');
    $t = (string)($x['type'] ?? ''); if (!isset(AAR_TYPES[$t])) fail('Ungültige Feldart');
    $l = $s($x['label'] ?? '', 150); if ($l === '') fail('Jedes Feld braucht eine Bezeichnung');
    $id = (string)($x['id'] ?? '');
    if (!in_array($id, $known, true) || isset($ids[$id])) $id = 'f' . ++$seq;
    $ids[$id] = 1; $r = ['id' => $id, 'type' => $t, 'label' => $l, 'hint' => $s($x['hint'] ?? '', 300)];
    if ($t === 'select') {
      $o = array_values(array_unique(array_filter(array_map(fn($v) => $s($v, 80), (array)($x['opts'] ?? [])), 'strlen')));
      if (!$o || count($o) > 30) fail("Auswahl «{$l}»: 1 bis 30 Werte angeben");
      $r['opts'] = $o;
    }
    $f[] = $r;
  }
  $a = []; foreach (AAR_AUTO as $k => $_) $a[$k] = (int)!empty(($in['auto'] ?? [])[$k]);
  $d = isset($in['dl']) ? aarDlNorm((array)$in['dl']) : $old['dl'];
  return ['title' => $s($in['title'] ?? '', 120) ?: AAR_DEFAULT['title'], 'org' => $s($in['org'] ?? '', 120), 'seq' => $seq, 'auto' => $a, 'dl' => $d, 'fields' => $f]; }
const FEATURES = ['area' => 'Suchgebiet', 'res' => 'Rettlinge', 'msg' => 'Nachrichten (mit Kartenmarkierungen)', 'poi' => 'Markierungen & Bereiche', 'ext' => 'Externe Einsatzkräfte',
                  'rd' => 'Werkzeug «Flussdistanz»', 'pub' => 'Öffentliche Ansicht', 'rec' => 'Aufzeichnung (Replay)', 'nav' => 'Navigation (Ziele an eine Navigations-App übergeben)']; // Helikopter: eigener Hauptschalter und Schalter je Einsatz (airInc), nicht hier
// Navigations-Apps (Funktion «nav»): in der Verwaltung einzeln ausschaltbar (nav_off), Standard nav_default, pro Person users.nav_app
const NAV_APPS = ['google' => 'Google Maps', 'apple' => 'Apple Karten', 'waze' => 'Waze', 'geo' => 'Andere App (nur Android: Auswahl der Karten-Apps)'];
function navApps() { $off = json_decode(setting('nav_off', '[]'), true) ?: []; $a = array_values(array_diff(array_keys(NAV_APPS), $off)); return $a ?: ['google']; } // eingeschaltete, mindestens eine
function navDefault() { $a = navApps(); $d = setting('nav_default', 'google'); return in_array($d, $a, true) ? $d : $a[0]; }
const FEATURE_ROUTES = ['incident/mark' => 'res', 'incident/mark/remove' => 'res', 'incident/rescuee/save' => 'res', 'incident/rescuee/locate' => 'res', 'incident/rescuee/rescued' => 'res', 'incident/rescuee/remove' => 'res',
                        'incident/message' => 'msg', 'incident/message/unmark' => 'msg', 'incident/poi' => 'poi', 'incident/poi/remove' => 'poi',
                        'incident/external/save' => 'ext', 'incident/external/remove' => 'ext', 'incident/public' => 'pub', 'incident/record' => 'rec', 'incident/event' => 'area'];
const OVERPASS_KEEP_S = 7 * 86400; // abgelaufene Einträge so lange als Rückfall behalten, falls Overpass nicht erreichbar ist
const EX_KINDS = ['pls' => 'PLS des Einsatzes', 'res' => 'Neuer Rettling', 'seen' => 'Rettling gesichtet', 'ext' => 'Neue externe Einsatzkraft', 'extdel' => 'Externe Einsatzkraft entfernen']; // Elemente vorbereiteter Übungen (Issue #29), steht vor public/state
/* ---------- Helikopter / Luftlage (Issue #73) ---------- */
// Positionen aus offenen ADS-B-Daten, immer über den Server (die Dienste erlauben keine Abfrage aus dem Browser, kein CORS), mit Datei-Cache je Einsatz
// in api/data/air/ (airServe). Alle Quellen sind gratis und ohne Schlüssel, aber nur für nicht-kommerzielle Nutzung gedacht (Vermerk in der Verwaltung).
// Steht vor public/state (public/air). Einstellung air = JSON, fehlende Werte aus AIR_DEFAULT (airCfg).
const AIR_SOURCES = [
  'adsbfi' => ['name' => 'adsb.fi (Open Data)', 'site' => 'https://adsb.fi', 'url' => 'https://opendata.adsb.fi/api/v3/lat/%s/lon/%s/dist/%d',
               'note' => 'Gratis, ohne Schlüssel, höchstens 1 Abfrage pro Sekunde. Nur für nicht-kommerzielle Nutzung.'],
  'adsblol' => ['name' => 'adsb.lol', 'site' => 'https://adsb.lol', 'url' => 'https://api.adsb.lol/v2/point/%s/%s/%d',
               'note' => 'Gratis, ohne Schlüssel. Daten unter der Open Database License (ODbL), Quellenangabe auf der Karte. Für nicht-kommerzielle Nutzung gedacht, Limits kann der Betreiber jederzeit ändern.'],
  'opensky' => ['name' => 'OpenSky Network', 'site' => 'https://opensky-network.org', 'url' => 'https://opensky-network.org/api/states/all?extended=1&lamin=%s&lomin=%s&lamax=%s&lomax=%s',
               'note' => 'Gratis nur für nicht-kommerzielle Nutzung (Forschung, gemeinnützige Zwecke). Ohne Konto nur rund 400 Abfragen pro Tag, das reicht für einen Einsatz nicht lange. Deshalb standardmässig aus, als letzter Rückfall gedacht.'],
];
const AIR_FILTERS = ['rescue' => 'Nur Rettungshelikopter (Liste unten)', 'heli' => 'Alle Helikopter, Rettungshelikopter hervorgehoben', 'all' => 'Alle Luftfahrzeuge (auch Flugzeuge, z. B. Suchflüge)'];
const AIR_APP = ['all' => 'Alle angemeldeten Personen', 'part' => 'Teilnehmende und Einsatzleitung', 'lead' => 'Nur Einsatzleitung', 'off' => 'Niemand'];
// Zahlen: [Standard, Minimum, Maximum]
const AIR_NUM = ['radius_km' => [15, 1, 500], 'max_alt_ft' => [10000, 500, 60000], 'max_age_s' => [60, 10, 600], 'trail_min' => [5, 0, 60], 'cache_s' => [5, 2, 120]];
const AIR_DEFAULT = ['on' => 1, 'def_real' => 1, 'def_ex' => 0, 'src' => ['adsbfi', 'adsblol'], 'order' => ['adsbfi', 'adsblol', 'opensky'], 'filter' => 'heli', 'app' => 'all', 'public' => 0, 'replay' => 1, 'rescue' => [['k' => 'RGA', 'n' => 'Rega'], ['k' => 'AAA', 'n' => 'Alpine Air Ambulance']]];
const AIR_MAX_M = 463000; // grösster Abfrageradius der Dienste (250 Seemeilen); ein grösserer Umkreis wird nur bis dahin abgefragt
const AIR_BACKOFF_S = 20; // nach einem Fehlschlag aller Quellen so lange nicht erneut abfragen
const AIR_REPLAY_S = 5;   // Aufzeichnung fürs Replay: höchstens ein Punkt je Luftfahrzeug in diesem Abstand
function airCfg() { $v = json_decode(setting('air', ''), true); $v = is_array($v) ? $v : []; $c = AIR_DEFAULT; $ks = array_keys(AIR_SOURCES);
  $c['order'] = array_values(array_unique(array_merge(array_values(array_intersect(array_filter((array)($v['order'] ?? []), 'is_string'), $ks)), AIR_DEFAULT['order'], $ks)));
  $c['src'] = array_values(array_intersect($c['order'], is_array($v['src'] ?? null) ? $v['src'] : AIR_DEFAULT['src'])); // eingeschaltete in der Reihenfolge
  foreach (['filter' => AIR_FILTERS, 'app' => AIR_APP] as $k => $o) if (is_string($v[$k] ?? null) && isset($o[$v[$k]])) $c[$k] = $v[$k];
  foreach (AIR_NUM as $k => [$d, $lo, $hi]) $c[$k] = is_numeric($v[$k] ?? null) ? (int)min(max((float)$v[$k], $lo), $hi) : $d;
  foreach (['on', 'def_real', 'def_ex', 'public', 'replay'] as $k) if (isset($v[$k])) $c[$k] = $v[$k] ? 1 : 0;
  if (is_array($v['rescue'] ?? null)) $c['rescue'] = airRescueIn($v['rescue'], false);
  return $c; }
// Liste der Rettungshelikopter: k = Anfang des Rufzeichens oder Kennzeichens (z. B. RGA, HB-Z) bzw. Transponder-Adresse (6 Hex-Zeichen), n = Bezeichnung
function airRescueIn($a, $strict) { $o = [];
  foreach (array_slice((array)$a, 0, 60) as $x) {
    $k = strtoupper(trim((string)($x['k'] ?? ''))); $n = mb_substr(trim((string)($x['n'] ?? '')), 0, 40);
    if (!preg_match('/^[A-Z0-9-]{2,8}$/', $k) || $n === '') { if ($strict) fail('Ungültiger Eintrag in der Liste der Rettungshelikopter: «' . mb_substr($k ?: $n, 0, 20) . '» (Kennung 2–8 Zeichen A–Z, 0–9, «-», Bezeichnung nötig)'); continue; }
    $o[] = ['k' => $k, 'n' => $n]; }
  return $o; }
// Einstellung aus der Verwaltung prüfen (streng, Fehler statt stillem Korrigieren)
function airIn($v) { if (!is_array($v)) fail('Ungültige Einstellungen für Helikopter'); $ks = array_keys(AIR_SOURCES);
  $order = array_values(array_filter((array)($v['order'] ?? []), 'is_string')); $src = array_values(array_filter((array)($v['src'] ?? []), 'is_string'));
  if (array_diff($order, $ks) || array_diff($src, $ks)) fail('Unbekannte Datenquelle');
  if (!isset(AIR_FILTERS[$v['filter'] ?? '']) || !isset(AIR_APP[$v['app'] ?? ''])) fail('Ungültige Auswahl');
  $o = ['order' => array_values(array_unique(array_merge($order, $ks))), 'src' => array_values(array_unique($src)), 'filter' => $v['filter'], 'app' => $v['app'], 'public' => empty($v['public']) ? 0 : 1, 'replay' => empty($v['replay']) ? 0 : 1];
  foreach (['on', 'def_real', 'def_ex'] as $k) $o[$k] = empty($v[$k]) ? 0 : 1;
  foreach (AIR_NUM as $k => [$d, $lo, $hi]) { if (!is_numeric($v[$k] ?? null) || $v[$k] < $lo || $v[$k] > $hi) fail("Wert ausserhalb des Bereichs ($lo–$hi): $k"); $o[$k] = (int)$v[$k]; }
  $o['rescue'] = airRescueIn($v['rescue'] ?? [], true);
  return $o; }
// Rettungshelikopter? Bezeichnung aus der Liste oder null
function airRescue($a, $c) { $cs = strtoupper($a['cs'] ?? ''); $rg = strtoupper((string)($a['reg'] ?? ''));
  foreach ($c['rescue'] as $x) if (($cs !== '' && str_starts_with($cs, $x['k'])) || ($rg !== '' && str_starts_with($rg, $x['k'])) || strtolower($x['k']) === $a['hex']) return $x['n'];
  return null; }
// passt zum Filter der Verwaltung (Kategorie A7 = Rotorcraft nach ADS-B)
function airKind($a, $c) { return $c['filter'] === 'all' || airRescue($a, $c) !== null || ($c['filter'] === 'heli' && $a['cat'] === 'A7'); }
// Antworten der Quellen auf ein gemeinsames Format: hex, cs (Rufzeichen), reg, type, desc, cat, lat, lon, alt (ft, barometrisch), gnd, gs (kt), trk (Grad), vr (ft/min), at (Zeit der Position)
function airNorm($src, $j) { $out = []; $str = fn($x, $n = 40) => is_string($x) && trim($x) !== '' ? mb_substr(trim($x), 0, $n) : null; $num = fn($x) => is_int($x) || is_float($x);
  if ($src === 'opensky') { if (!array_key_exists('states', $j)) return null; $t0 = (int)($j['time'] ?? time());
    foreach ((array)$j['states'] as $s) { if (!is_array($s) || !$num($s[5] ?? null) || !$num($s[6] ?? null) || !is_string($s[0] ?? null)) continue;
      $out[] = ['hex' => strtolower($s[0]), 'cs' => $str($s[1] ?? null, 12), 'reg' => null, 'type' => null, 'desc' => null, 'cat' => ($s[17] ?? null) === 8 ? 'A7' : null, 'lat' => (float)$s[6], 'lon' => (float)$s[5],
                'alt' => $num($s[7] ?? null) ? (int)round($s[7] / 0.3048) : null, 'gnd' => !empty($s[8]), 'gs' => $num($s[9] ?? null) ? round($s[9] * 1.943844, 1) : null, 'trk' => $num($s[10] ?? null) ? (int)round($s[10]) : null,
                'vr' => $num($s[11] ?? null) ? (int)round($s[11] * 196.85) : null, 'at' => $num($s[3] ?? null) ? (int)$s[3] : $t0]; }
    return $out; }
  $ac = $j['ac'] ?? $j['aircraft'] ?? null; if (!is_array($ac)) return null;
  $now = $num($j['now'] ?? null) ? (float)$j['now'] : time(); if ($now > 1e11) $now /= 1000; // adsb.lol und v3: Millisekunden
  foreach ($ac as $a) { if (!is_array($a) || !$num($a['lat'] ?? null) || !$num($a['lon'] ?? null) || !is_string($a['hex'] ?? null)) continue;
    $alt = $a['alt_baro'] ?? null; $gnd = $alt === 'ground';
    $out[] = ['hex' => strtolower(ltrim($a['hex'], '~')), 'cs' => $str($a['flight'] ?? null, 12), 'reg' => $str($a['r'] ?? null, 12), 'type' => $str($a['t'] ?? null, 8), 'desc' => $str($a['desc'] ?? null), 'cat' => $str($a['category'] ?? null, 4),
              'lat' => (float)$a['lat'], 'lon' => (float)$a['lon'], 'alt' => $gnd ? 0 : ($num($alt) ? (int)$alt : ($num($a['alt_geom'] ?? null) ? (int)$a['alt_geom'] : null)), 'gnd' => $gnd,
              'gs' => $num($a['gs'] ?? null) ? round($a['gs'], 1) : null, 'trk' => $num($a['track'] ?? null) ? (int)round($a['track']) : ($num($a['true_heading'] ?? null) ? (int)round($a['true_heading']) : null),
              'vr' => $num($a['baro_rate'] ?? null) ? (int)$a['baro_rate'] : ($num($a['geom_rate'] ?? null) ? (int)$a['geom_rate'] : null), 'at' => (int)round($now - ($num($a['seen_pos'] ?? null) ? $a['seen_pos'] : ($num($a['seen'] ?? null) ? $a['seen'] : 0)))]; }
  return $out; }
function airGet($url) {
  if (!function_exists('curl_init')) { $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
    return [preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0, $r === false ? null : $r, $r === false ? 'Keine Antwort' : '']; }
  $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
  $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $err = $r === false ? (curl_errno($c) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($c)) : ''; curl_close($c);
  return [$code, $r === false ? null : $r, $err]; }
// Abruf je Quelle für die Anzeige in der Verwaltung (api/data/air/status.json); darf die Abfrage nie stören
function airStat($k, $ok, $ms, $err, $n) { try { $f = __DIR__ . '/data/air/status.json'; if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true); $s = json_decode((string)@file_get_contents($f), true) ?: []; $x = $s[$k] ?? ['uses' => 0, 'fails' => 0];
  $x['uses']++; if ($ok) { $x['last_ok'] = time(); $x['ms'] = (int)$ms; $x['n'] = $n; } else { $x['fails']++; $x['last_fail'] = time(); $x['err'] = mb_substr((string)$err, 0, 150); }
  $s[$k] = $x; @file_put_contents("$f.tmp", json_encode($s)); @rename("$f.tmp", $f); } catch (Throwable $e) {} }
// erste Quelle mit gültiger Antwort für den Kreis (la, lo, rm Meter): [Quelle, Liste, abgedeckter Radius] oder null
function airFetch($srcs, $la, $lo, $rm) { $f = fn($x) => sprintf('%.4F', $x);
  $nm = (int)min(AIR_MAX_M / 1852, max(5, ceil($rm / 1852 / 5) * 5)); // Radius in Seemeilen, auf 5 aufgerundet (höchstens 250, Grenze der Dienste)
  foreach ($srcs as $k) { $S = AIR_SOURCES[$k];
    if ($k === 'opensky') { $d = $nm * 1852; $dla = $d / 111320; $dlo = $d / (111320 * max(.1, cos(deg2rad($la)))); $url = sprintf($S['url'], $f($la - $dla), $f($lo - $dlo), $f($la + $dla), $f($lo + $dlo)); }
    else $url = sprintf($S['url'], $f($la), $f($lo), $nm);
    $t = microtime(true); [$code, $body, $err] = airGet($url);
    $j = $code === 200 && $body !== null ? json_decode($body, true) : null; $list = is_array($j) ? airNorm($k, $j) : null;
    airStat($k, $list !== null, (microtime(true) - $t) * 1000, $err ?: ($code && $code !== 200 ? "HTTP $code" : ($code ? 'Ungültige Antwort' : 'Keine Antwort')), $list === null ? null : count($list));
    if ($list !== null) return [$k, $list, $nm * 1852]; }
  return null; }
// Relevanzzone: Kreise [lat, lon, Radius m, Art] um alle aktiven Positionen des Einsatzes. Um den PLS zusätzlich die Driftdistanz
// (Fliessgeschwindigkeit × Zeit seit dem PLS, höchstens 30 km wie das Gewässernetz), damit das ganze Suchgebiet abgedeckt ist.
function airZone($inc) { global $db, $now; $c = airCfg(); $R = $c['radius_km'] * 1000; $id = (int)$inc['id']; $z = []; $flow = $inc['flow_ms'] === null ? 0.0 : (float)$inc['flow_ms'];
  $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  foreach (plsSources($inc, incRes($inc)) as $s) $z[] = [$s['lat'], $s['lon'], $R + min(30000, max(0, $flow * ($now - $s['at']))), 'pls'];
  foreach ($q("SELECT lat,lon FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL AND lat IS NOT NULL") as $p) $z[] = [(float)$p['lat'], (float)$p['lon'], $R, 'pos'];
  if (feature($inc, 'ext')) foreach (incExternals($id) as $e) $z[] = [$e['lat'], $e['lon'], $R, 'pos'];
  if (feature($inc, 'res')) foreach (incRescuees($id) as $r) if ($r['lat'] !== null && $r['rescued_at'] === null) $z[] = [$r['lat'], $r['lon'], $R, 'pos'];
  if (feature($inc, 'poi')) foreach ($q("SELECT pts FROM pois WHERE incident_id=? AND removed IS NULL") as $p) foreach (json_decode($p['pts'], true) ?: [] as $pt) if (is_array($pt) && count($pt) >= 2) $z[] = [(float)$pt[0], (float)$pt[1], $R, 'pos'];
  return $z; }
// Kreis um die ganze Zone (Mitte des umschliessenden Rechtecks), für eine einzige Abfrage bei der Quelle
function airCircle($z) { $s = 90; $n = -90; $w = 180; $e = -180;
  foreach ($z as [$la, $lo, $r]) { $dla = $r / 111320; $dlo = $r / (111320 * max(.1, cos(deg2rad($la)))); $s = min($s, $la - $dla); $n = max($n, $la + $dla); $w = min($w, $lo - $dlo); $e = max($e, $lo + $dlo); }
  $la = ($s + $n) / 2; $lo = ($w + $e) / 2; $rm = 0; foreach ($z as [$a, $b, $r]) $rm = max($rm, hav($la, $lo, $a, $b) + $r);
  return [$la, $lo, $rm]; }
function airInZone($a, $z) { foreach ($z as [$la, $lo, $r]) if (hav($la, $lo, $a['lat'], $a['lon']) <= $r) return true; return false; }
// sichtbar: Filter, Höhe, Alter und Relevanzzone
function airShow($a, $c, $z) { global $now; return airKind($a, $c) && ($a['gnd'] || $a['alt'] === null || $a['alt'] <= $c['max_alt_ft']) && $now - $a['at'] <= $c['max_age_s'] && airInZone($a, $z); }
// Lage für einen laufenden Einsatz: aus dem Cache (Datei je Einsatz, gültig cache_s und solange er die Zone abdeckt), sonst neu bei der ersten erreichbaren Quelle.
// Holt gerade ein anderer Request (Sperre belegt), gibt es ohne Warten den bisherigen Stand. Der Cache hält auch die Spuren (trail_min) und zeichnet fürs Replay auf.
function airServe($inc) { global $now, $db; $c = airCfg(); $id = (int)$inc['id'];
  $base = ['air' => [], 'radius_km' => $c['radius_km'], 'now' => $now];
  if (!$c['src']) return $base + ['err' => 'Keine Datenquelle eingeschaltet (Verwaltung → Helikopter)'];
  $z = airZone($inc); if (!$z) return $base + ['info' => 'Noch kein Bezugspunkt: Helikopter erscheinen, sobald ein PLS oder Standorte bekannt sind.'];
  [$la, $lo, $rm] = airCircle($z);
  $dir = __DIR__ . '/data/air'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $file = "$dir/inc$id.json";
  $read = function () use ($file) { clearstatcache(true, $file); $d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null; return is_array($d) ? $d : null; };
  $ok = fn($d) => $d && isset($d['at']) && $now - (int)$d['at'] < $c['cache_s'] && hav($d['la'], $d['lo'], $la, $lo) + min($rm, AIR_MAX_M * .9) <= $d['rm']; // grösser als die Grenze der Dienste: deckt der Cache das Abfragbare ab, gilt er
  $wait = fn($d) => $d && !empty($d['fail_at']) && $now - (int)$d['fail_at'] < AIR_BACKOFF_S;
  $d = $read();
  if (!$ok($d) && !$wait($d) && ($lk = @fopen("$file.lock", 'c')) && flock($lk, LOCK_EX | LOCK_NB)) {
    $d = $read();
    if (!$ok($d) && !$wait($d)) { set_time_limit(60);
      $r = airFetch($c['src'], $la, $lo, $rm * 1.1); // etwas grösser, damit kleine Verschiebungen der Zone den Cache nicht ungültig machen
      if ($r) { [$k, $list, $cov] = $r; $hist = $d['hist'] ?? []; $keep = max(120, $c['trail_min'] * 60); $ac = [];
        $rec = (int)$inc['record'] === 1 && $c['replay'] && feature($inc, 'rec');
        $ins = $rec ? $db->prepare("INSERT INTO air_track(incident_id,hex,ts,lat,lon,alt,trk,cs,reg,type,cat) VALUES(?,?,?,?,?,?,?,?,?,?,?)") : null;
        foreach ($list as $a) { if (!airKind($a, $c) || !preg_match('/^[0-9a-f]{6}$/', $a['hex'])) continue; $ac[$a['hex']] = $a;
          $h = $hist[$a['hex']] ?? []; $last = $h ? end($h) : null;
          if (!$last || $a['at'] > $last[0]) { $h[] = [$a['at'], round($a['lat'], 6), round($a['lon'], 6), $a['alt']]; $hist[$a['hex']] = $h;
            if ($ins && airShow($a, $c, $z) && (!$last || $a['at'] - ($d['rec'][$a['hex']] ?? 0) >= AIR_REPLAY_S)) { $ins->execute([$id, $a['hex'], $a['at'], $a['lat'], $a['lon'], $a['gnd'] ? 0 : $a['alt'], $a['trk'], $a['cs'], $a['reg'], $a['type'], $a['cat']]); $d['rec'][$a['hex']] = $a['at']; } } }
        foreach ($hist as $hx => $h) { $h = array_values(array_filter($h, fn($p) => $now - $p[0] <= $keep)); if ($h) $hist[$hx] = $h; else unset($hist[$hx], $d['rec'][$hx]); }
        $d = ['at' => $now, 'la' => $la, 'lo' => $lo, 'rm' => $cov, 'src' => $k, 'ac' => array_values($ac), 'hist' => $hist, 'rec' => $d['rec'] ?? []];
      } else { $d = $d ?: ['ac' => [], 'hist' => []]; $d['fail_at'] = $now; } // alle Quellen fehlgeschlagen: bisherige Daten behalten, Pause AIR_BACKOFF_S
      if (file_put_contents("$file.tmp", json_encode($d)) !== false) rename("$file.tmp", $file); }
    flock($lk, LOCK_UN); }
  if (!$d) return $base + ['info' => 'Luftlage wird geladen …'];
  $pls = array_values(array_filter($z, fn($x) => $x[3] === 'pls')); $out = [];
  foreach ($d['ac'] ?? [] as $a) { if (!airShow($a, $c, $z)) continue;
    $dist = null; foreach ($pls as [$pa, $po]) { $x = hav($pa, $po, $a['lat'], $a['lon']); $dist = $dist === null ? $x : min($dist, $x); }
    $tr = $c['trail_min'] ? array_values(array_filter($d['hist'][$a['hex']] ?? [], fn($p) => $now - $p[0] <= $c['trail_min'] * 60)) : [];
    $out[] = $a + ['rescue' => airRescue($a, $c), 'pls_m' => $dist === null ? null : (int)round($dist), 'trail' => $tr]; }
  usort($out, fn($x, $y) => [$x['rescue'] === null, $x['pls_m'] ?? PHP_INT_MAX] <=> [$y['rescue'] === null, $y['pls_m'] ?? PHP_INT_MAX]); // Rettungshelikopter zuerst, dann nach Distanz
  $S = AIR_SOURCES[$d['src'] ?? ''] ?? null;
  return ['air' => $out, 'at' => isset($d['at']) ? (int)$d['at'] : null, 'src' => $S ? ['name' => $S['name'], 'site' => $S['site']] : null, 'err' => !empty($d['fail_at']) && $now - (int)$d['fail_at'] < 60 ? 'Keine Datenquelle erreichbar, neuer Versuch folgt' : null, 'radius_km' => $c['radius_km'], 'now' => $now]; }
// Helikopter für diesen Einsatz an? Hauptschalter (on) und Schalter je Einsatz (incidents.air, beim Start gesetzt; NULL = Vorauswahl der Art def_real/def_ex)
function airDef($ex) { $c = airCfg(); return $c['on'] ? $c[$ex ? 'def_ex' : 'def_real'] : 0; }
function airInc($inc) { $c = airCfg(); if (!$c['on']) return false; $v = $inc['air'] ?? null; return $v === null || $v === '' ? (bool)airDef((int)$inc['is_exercise']) : (bool)(int)$v; }
// Wer sieht die Helikopter in der Einsatz-App (Einstellung app)
function airAllowed($inc) { $a = airCfg()['app']; if (!airInc($inc) || $a === 'off') return false; if ($a === 'all') return true;
  if (canManage($inc)) return true; return $a === 'part' && isActivePart((int)$inc['id']); }
// Replay: aufgezeichnete Luftfahrzeuge je Transponder-Adresse, Punkte [0, ts, lat, lon, alt, trk] (Zeit an Stelle 1 wie bei track)
function airReplay($id) { global $db; $c = airCfg(); if (!$c['replay']) return [];
  $s = $db->prepare("SELECT hex,ts,lat,lon,alt,trk,cs,reg,type,cat FROM air_track WHERE incident_id=? ORDER BY hex,ts,id"); $s->execute([$id]); $o = [];
  foreach ($s->fetchAll() as $r) { $h = $r['hex'];
    if (!isset($o[$h])) { $a = ['hex' => $h, 'cs' => $r['cs'], 'reg' => $r['reg'], 'type' => $r['type'], 'cat' => $r['cat']]; $o[$h] = $a + ['rescue' => airRescue($a, $c), 'pts' => []]; }
    foreach (['cs', 'reg', 'type'] as $k) if ($r[$k] !== null) $o[$h][$k] = $r[$k];
    $o[$h]['pts'][] = [0, (int)$r['ts'], (float)$r['lat'], (float)$r['lon'], $r['alt'] === null ? null : (int)$r['alt'], $r['trk'] === null ? null : (int)$r['trk']]; }
  return array_values($o); }

if ($route === 'login') {
  $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1"); $st->execute([$in['username'] ?? '']);
  $u = $st->fetch();
  if (!$u || !password_verify($in['password'] ?? '', $u['pass_hash'])) fail('Benutzername oder Passwort falsch', 401);
  $t = bin2hex(random_bytes(24));
  $db->prepare("UPDATE users SET token=?, last_login=? WHERE id=?")->execute([$t, time(), $u['id']]);
  out(['token' => $t]);
}

// Öffentliche Ansicht (Public Mode): ohne Anmeldung, nur lesend, nur mit dem geheimen Link der Einsatzleitung (k = public_token).
// Geliefert wird nur, was zum Zuschauen nötig ist: keine Profilbilder, keine Benutzer-IDs; das Einsatzprotokoll (mit Nachrichten) nur mit public_log (Issue #54).
// Anonym (public_anon=1): statt Namen nur Spezialrolle bzw. Einrückoption, Rettlinge nummeriert und ohne Beschreibung.
function pubIncident() { global $db; $k = (string)($_GET['k'] ?? '');
  $s = $db->prepare("SELECT * FROM incidents WHERE public_token=?"); $s->execute([$k]); $inc = $s->fetch(); $s->closeCursor();
  if (!preg_match('/^[0-9a-f]{32}$/', $k) || !$inc || !feature($inc, 'pub')) fail('Dieser Link ist ungültig oder die öffentliche Ansicht wurde beendet.', 404);
  return $inc; }
// Gewässernetz fürs Suchgebiet: nur um einen PLS des Suchgebiets (la/lo, siehe plsSources) des laufenden Einsatzes, Radius m wie in der Einsatz-App (netRadius), gleicher Cache
if ($route === 'public/net') {
  $inc = pubIncident(); if ($inc['ended_at'] !== null) fail('Der Einsatz ist beendet.', 404);
  if (!feature($inc, 'area')) fail('Das Suchgebiet ist ausgeschaltet.', 403);
  $r = (int)(ceil(min(max(5000, (float)($_GET['m'] ?? 0)), 30000) / 1000) * 1000);
  $la = (float)($_GET['la'] ?? 0); $lo = (float)($_GET['lo'] ?? 0);
  $src = array_values(array_filter(plsSources($inc, incRes($inc)), fn($s) => abs($s['lat'] - $la) < 1e-6 && abs($s['lon'] - $lo) < 1e-6))[0] ?? fail('Kein PLS für das Suchgebiet', 404);
  overpassServe(overpassQuery(['around' => [$r, $src['lat'], $src['lon']]]));
}
// Helikopter in der öffentlichen Ansicht (Issue #73): nur mit Einstellung public, gleicher Cache wie die Einsatz-App
if ($route === 'public/air') {
  $inc = pubIncident(); if ($inc['ended_at'] !== null) fail('Der Einsatz ist beendet.', 404);
  if (!airInc($inc) || !airCfg()['public']) fail('Helikopter werden in der öffentlichen Ansicht nicht gezeigt.', 403);
  out(airServe($inc));
}
if ($route === 'public/state') {
  // vorbereitete Übung (Issue #29): Der Link gilt schon vor dem Start; bis dahin nur Titel und «noch nicht begonnen», ohne Aufgebotsmeldung
  $k = (string)($_GET['k'] ?? ''); $s = $db->prepare("SELECT title FROM ex_plans WHERE public_token=? AND active=1 AND incident_id IS NULL"); $s->execute([$k]); $wp = $s->fetch(); $s->closeCursor();
  if ($wp && preg_match('/^[0-9a-f]{32}$/', $k)) out(['waiting' => 1, 'title' => $wp['title'], 'now' => $now]);
  $inc = pubIncident(); if ($inc['plan_id'] !== null) { exAuto($inc); $inc = pubIncident(); }
  $pi = ['message' => $inc['message'], 'is_exercise' => (int)$inc['is_exercise'], 'alarm_at' => (int)$inc['alarm_at'], 'ended_at' => $inc['ended_at'] === null ? null : (int)$inc['ended_at']];
  if ($inc['ended_at'] !== null) out(['incident' => $pi, 'now' => $now]);
  $id = (int)$inc['id']; $anon = (int)$inc['public_anon'] === 1; $c = $anon ? anonCtx($id) : null; // anonym: Rettlinge wie im anonymisierten Protokoll nummeriert (Issue #54)
  $pi += ['lat' => $inc['lat'] === null ? null : (float)$inc['lat'], 'lon' => $inc['lon'] === null ? null : (float)$inc['lon'], 'pls_at' => plsAt($inc), 'flow_ms' => $inc['flow_ms'] === null ? null : (float)$inc['flow_ms']];
  $roles = array_map(fn($r) => ints($r, ['id', 'is_post']), $db->query("SELECT id,name,color,is_post FROM roles ORDER BY id")->fetchAll());
  $rn = array_column($roles, 'name', 'id');
  $ps = $db->prepare("SELECT p.id pid,p.role_id,p.lat,p.lon,p.loc_at,u.name,m.name mode_name FROM participants p JOIN users u ON u.id=p.user_id LEFT JOIN modes m ON m.code=p.mode WHERE p.incident_id=? AND p.mode<>'declined' AND p.left_at IS NULL ORDER BY p.id");
  $ps->execute([$id]); $parts = [];
  foreach ($ps->fetchAll() as $p) { // id = Teilnahme (participants.id), stabil fürs Folgen, keine Benutzer-ID
    $rid = $p['role_id'] === null ? null : (int)$p['role_id'];
    $parts[] = ['id' => (int)$p['pid'], 'name' => $anon ? ($rid !== null && isset($rn[$rid]) ? $rn[$rid] : ($p['mode_name'] ?: 'Einsatzkraft')) : $p['name'], 'mode_name' => $p['mode_name'], 'role_id' => $rid,
                'lat' => $p['lat'] === null ? null : (float)$p['lat'], 'lon' => $p['lon'] === null ? null : (float)$p['lon'], 'loc_at' => $p['loc_at'] === null ? null : (int)$p['loc_at']];
  }
  foreach (feature($inc, 'ext') ? incExternals($id) : [] as $e) // externe Einsatzkräfte (Issue #39): id negativ, damit sie sich nicht mit Teilnahmen überschneidet; ohne Beschreibung
    $parts[] = ['id' => -$e['id'], 'name' => $anon ? ($e['role_id'] !== null && isset($rn[$e['role_id']]) ? $rn[$e['role_id']] : 'Externe Einsatzkraft') : $e['name'], 'mode_name' => 'Extern', 'role_id' => $e['role_id'], 'lat' => $e['lat'], 'lon' => $e['lon'], 'loc_at' => $e['moved'] ?? $e['created'], 'ext' => 1, 'color' => $e['color']];
  $po = $db->prepare("SELECT id,kind,name,color,pts FROM pois WHERE incident_id=? AND removed IS NULL ORDER BY id"); $po->execute([$id]);
  $pois = !feature($inc, 'poi') ? [] : array_map(fn($p) => ['pts' => json_decode($p['pts'], true) ?: []] + ints($p, ['id']), $po->fetchAll());
  $res = [];
  foreach (incRes($inc) as $n => $r) $res[] = ['name' => $anon ? ($c['r'][$r['id']] ?? 'Rettling ' . ($n + 1)) : $r['name'], 'descr' => $anon ? null : $r['descr']] + array_intersect_key($r, array_flip(['id', 'lat', 'lon', 'loc_at', 'rescued_at', 'pls_lat', 'pls_lon', 'pls_at']));
  out(['incident' => $pi, 'sources' => plsSources($inc, $res), 'anon' => $anon ? 1 : 0, 'roles' => $roles, 'participants' => $parts, 'pois' => $pois, 'rescuees' => $res, 'coord_sys' => setting('coord_sys', 'lv95'), 'features' => features((int)$inc['is_exercise']), 'air' => airInc($inc) && airCfg()['public'] ? 1 : 0, 'log' => (int)$inc['public_log'] ? array_slice(logRows($id, false, $anon), 0, 300) : null, 'now' => $now]); // features: Fenster «Ansicht» (Issue #48); log: Einsatzprotokoll live, neueste zuerst, anonym wie die Ansicht (Issue #54, public_log)
}

$tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
$st = $db->prepare("SELECT id,username,name,photo,exercise_mode,must_change,coord_sys,nav_app,ai_modes FROM users WHERE token=? AND active=1"); $st->execute([$tok]);
$me = $tok ? $st->fetch() : null; $st->closeCursor(); // Lesezugriff sofort beenden, nicht erst am Ende des Requests
if (!$me) fail('Nicht angemeldet', 401);
$me = ints($me, ['id', 'exercise_mode', 'must_change']); unset($me['is_admin'], $me['is_planner']); $uid = $me['id'];
$me['ai_modes'] = json_decode((string)$me['ai_modes'], true) ?: []; // AI-Assistent (Issue #111): gewählter Modus je Funktion
// Benutzerrollen und Rechte (Issue #63): Vereinigung aller Rollen; is_admin = hat die Rolle Systemadministrator (alle Rechte)
$me['uroles'] = userRoles($uid); $me['is_admin'] = (int)array_reduce($me['uroles'], fn($c, $r) => $c || $r['sys'] === 'admin', false); $me['perms'] = permsOf($me['uroles']);
// Nach einem Zurücksetzen durch die Verwaltung ist nur der Passwortwechsel erlaubt
if ($me['must_change'] && !in_array($route, ['me', 'me/password'])) fail('Bitte zuerst ein eigenes Passwort setzen', 403);

/* ---------- Hilfsfunktionen ---------- */
function ints($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k]; return $r; }
function floats($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (float)$r[$k]; return $r; }
// Rechte (Issue #63): can() prüft ein Recht der angemeldeten Person, need() lehnt ohne ab
function can($p) { global $me; return in_array($p, $me['perms'], true); }
function need(...$ps) { foreach ($ps as $p) if (can($p)) return; fail('Dafür fehlt dir das Recht «' . PERMS[$ps[0]][1] . '»', 403); }
// Übungen vorbereiten (Issue #29): Recht ex_plan
function planner() { return can('ex_plan'); }
function plannerOnly() { need('ex_plan'); }
// Log und Replay eines Einsatzes: Administratoren alle, Übungsleiter die Durchführungen vorbereiteter Übungen (Verwaltung → Übungen),
// Teilnehmende ihre beendeten Einsätze (Issue #61, «Meine Einsätze», Einstellung my_runs). Rückgabe: 0 = Verwaltung, 1 = teilgenommen, mit allen Daten,
// 2 = teilgenommen, nur anonym ausser sich selbst. Endgültig anonymisierte Daten (rp_anon, log_anon) bleiben für alle anonym.
const MY_RUNS = ['off' => 'Ausgeschaltet', 'anon' => 'Anonymisiert (ausser sich selbst)', 'full' => 'Mit allen Daten'];
function myRuns() { $v = setting('my_runs', 'anon'); return isset(MY_RUNS[$v]) ? $v : 'anon'; }
// Knöpfe in «Meine Einsätze» (Einstellung my_btns); noch nicht gespeichert: Word und PDF wie in der Einrichtung des After Action Review (dl, Freigegeben), der Rest an
const MY_BTNS = ['log' => 'Log', 'replay' => 'Replay', 'aar' => 'After Action Review ansehen', 'docx' => 'After Action Review als Word', 'pdf' => 'After Action Review als PDF'];
function myBtns() { $v = json_decode(setting('my_btns', '') ?: 'null', true); $dl = aarCfg()['dl']; $r = []; foreach (MY_BTNS as $k => $_) $r[$k] = is_array($v) && isset($v[$k]) ? (int)!empty($v[$k]) : (isset($dl[$k]) ? (int)$dl[$k]['rel'] : 1); return $r; }
// Für Einsatzkräfte gibt es nur «anonymisiert»: Jede Stufe ausser track macht Log und Replay in «Meine Einsätze» anonym, auch bei my_runs full
function anonAny($i) { return $i['rp_anon'] !== null || (int)$i['log_anon'] || (int)$i['view_anon'] || (int)$i['txt_anon'] || (int)$i['part_anon']; }
function myAar() { $b = myBtns(); return $b['aar'] || $b['docx'] || $b['pdf']; } // Word und PDF entstehen im Browser aus denselben Daten wie die Ansicht
function myPart($id) { global $db, $uid; $s = $db->prepare("SELECT 1 FROM participants p JOIN incidents i ON i.id=p.incident_id WHERE p.incident_id=? AND p.user_id=? AND p.mode<>'declined' AND i.ended_at IS NOT NULL"); $s->execute([$id, $uid]); $r = (bool)$s->fetch(); $s->closeCursor(); return $r; }
// my=1 (Meine Einsätze): Ansicht als Einsatzkraft, auch für Administratoren und Übungsleiter, also nur eigene Einsätze gemäss my_runs
function incView($id) { global $db; $my = !empty($_GET['my']); if (can('stats') && !$my) return 0;
  if (planner() && !$my) { $s = $db->prepare("SELECT plan_id FROM incidents WHERE id=?"); $s->execute([$id]); $r = $s->fetch(); if ($r && $r['plan_id'] !== null) return 0; }
  if (myRuns() !== 'off' && myPart($id)) return myRuns() === 'full' ? 1 : 2;
  fail($my ? 'Kein Zugriff auf diesen Einsatz' : 'Dafür fehlt dir das Recht «' . PERMS['stats'][1] . '»', 403); }
// Benutzerrollen einer Person [{id, name, sys}] und die Rechte daraus (Administrator: alle aus PERMS)
function userRoles($u) { global $db; $s = $db->prepare("SELECT r.id,r.name,r.perms,r.sys FROM user_uroles x JOIN uroles r ON r.id=x.urole_id WHERE x.user_id=? ORDER BY r.id"); $s->execute([$u]);
  $r = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'sys' => $x['sys'], 'perms' => rolePerms($x)], $s->fetchAll()); $s->closeCursor(); return $r; }
function rolePerms($r) { return $r['sys'] === 'admin' ? array_keys(PERMS) : array_values(array_intersect(array_keys(PERMS), json_decode($r['perms'] ?? '[]', true) ?: [])); }
function permsOf($roles) { $p = []; foreach ($roles as $r) $p = array_merge($p, $r['perms']); return array_values(array_intersect(array_keys(PERMS), $p)); }
// Nur Rechte vergeben oder entziehen, die man selbst hat (sonst könnte sich jemand mit «Benutzer verwalten» zum Administrator machen)
function permsMine($ps) { global $me; return !array_diff($ps, $me['perms']); }
function uroleRow($id) { global $db; $s = $db->prepare("SELECT * FROM uroles WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch(); $s->closeCursor(); return $r ?: null; }
// Wer die Rolle Systemadministrator hat und aktiv ist; mindestens eine Person muss bleiben
function adminCount() { global $db; return (int)$db->query("SELECT COUNT(DISTINCT u.id) c FROM users u JOIN user_uroles x ON x.user_id=u.id JOIN uroles r ON r.id=x.urole_id WHERE u.active=1 AND r.sys='admin'")->fetch()['c']; }
// Benutzer bearbeiten (Name, Passwort, Rollen, deaktivieren) nur, wenn er keine Rechte hat, die man selbst nicht hat
function userEditable($id) { return permsMine(permsOf(userRoles($id))); }
// Konten, die irgendwo in Einsatz- oder Übungsdaten vorkommen (dürfen nie gelöscht werden, nur deaktiviert)
function usersUsed() { global $db; $q = []; foreach (['participants' => 'user_id', 'messages' => 'from_user', 'incident_log' => 'user_id', 'incidents' => 'created_by', 'track' => 'user_id', 'rescuee_marks' => 'user_id', 'pois' => 'created_by', 'rescuees' => 'created_by', 'externals' => 'created_by', 'ex_plans' => 'created_by', 'ex_injects' => 'released_by'] as $t => $c) $q[] = "SELECT $c u FROM $t WHERE $c IS NOT NULL";
  foreach (['loc_by', 'rescued_by'] as $c) $q[] = "SELECT $c u FROM rescuees WHERE $c IS NOT NULL";
  return array_flip(array_map('intval', $db->query("SELECT DISTINCT u FROM (" . implode(' UNION ', $q) . ") x")->fetchAll(PDO::FETCH_COLUMN))); }
function needUserEditable($id) { if (!userEditable($id)) fail('Diese Person hat Rechte, die du selbst nicht hast; nur jemand mit diesen Rechten kann sie bearbeiten', 403); }
function pwCheck($p) { if (!is_string($p) || $p === '') fail('Das Passwort darf nicht leer sein'); }
// Kritisch alarmieren: scharfe Einsätze immer, Übungen nur bei aktiviertem Übungsmodus
function critical($inc) { global $me; return !(int)$inc['is_exercise'] || $me['exercise_mode']; }
function part($incId, $u = null) { global $db, $uid; $s = $db->prepare("SELECT * FROM participants WHERE incident_id=? AND user_id=?"); $s->execute([$incId, $u ?? $uid]); return $s->fetch(); }
function isActivePart($incId) { $p = part($incId); return $p && $p['mode'] !== 'declined' && $p['left_at'] === null; }
// Einsatzleitung: Spezialrolle mit is_lead oder Einrückoption mit lead_rights (z. B. Koordinator KP Rück)
function isLead($incId) {
  global $db; $p = part($incId);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') return false;
  if (leadMode($p['mode'])) return true;
  if ($p['role_id'] === null) return false;
  $s = $db->prepare("SELECT is_lead FROM roles WHERE id=?"); $s->execute([$p['role_id']]); $r = $s->fetch();
  return $r && (int)$r['is_lead'];
}
function leadMode($code) { $m = modeRow($code); return $m && (int)$m['lead_rights']; }
// Einsatzleitung nur mit Teilnahme (Einrückoption gewählt, nicht abgelehnt/ausgetreten, Issue #82); die Übungsleitung auch ohne
function canManage($inc) { global $uid; return exLead($inc) || isActivePart((int)$inc['id']) && (can('inc_lead') || (int)$inc['created_by'] === $uid || isLead((int)$inc['id'])); }
// Vorbereitete Übung eines Einsatzes (Issue #29) und ob die angemeldete Person sie leitet (Übungsleitung: Rechte der Einsatzleitung, wird nicht alarmiert, kann zusätzlich teilnehmen)
function exPlan($inc) { global $db; if (empty($inc['plan_id'])) return null; $s = $db->prepare("SELECT * FROM ex_plans WHERE id=?"); $s->execute([(int)$inc['plan_id']]); return $s->fetch() ?: null; }
function exLeads($p) { return array_map('intval', json_decode($p['leads'] ?? '[]', true) ?: []); }
function exLead($inc) { global $uid; $p = exPlan($inc); return $p && in_array($uid, exLeads($p), true); }
// Aufgebotene Personen (NULL = alle) und ob die angemeldete Person alarmiert wird; teilnehmen dürfen alle
function exInvite($p) { return $p['invite'] === null ? null : array_map('intval', json_decode($p['invite'], true) ?: []); }
function alarmed($inc) { global $uid; if (exLead($inc)) return false; $p = exPlan($inc); $v = $p ? exInvite($p) : null; return $v === null || in_array($uid, $v, true); }
// Elemente einer vorbereiteten Übung, Zahlen als Zahlen
function exInjects($planId) { global $db; $s = $db->prepare("SELECT i.*,u.name released_name FROM ex_injects i LEFT JOIN users u ON u.id=i.released_by WHERE i.plan_id=? AND i.removed=0 ORDER BY i.id"); $s->execute([$planId]);
  return array_map(fn($r) => floats(ints($r, ['id', 'plan_id', 'ago', 'auto', 'ref_id', 'released', 'released_by', 'rescuee_id', 'removed', 'role_id', 'ext_id']), ['lat', 'lon']), $s->fetchAll()); }
// Element einer vorbereiteten Übung freigeben (ex/release, automatisch über exAuto mit $by NULL): PLS setzen bzw. Rettling erfassen oder seinen PLS neu setzen, Meldung an alle.
// Liefert NULL oder den Grund, warum es (noch) nicht geht; doppelte Freigaben (mehrere Geräte gleichzeitig) verhindert die Bedingung released IS NULL
function exRelease($inc, $j, $ago, $by) { global $db; $db->beginTransaction(); // ganz oder gar nicht, sonst bliebe ein Element ohne Rettling freigegeben
  try { $r = exRelease1($inc, $j, $ago, $by); $db->commit(); return $r; } catch (Throwable $e) { $db->rollBack(); throw $e; } }
function exRelease1($inc, $j, $ago, $by) { global $db, $now;
  $xk = $j['kind'] === 'ext' || $j['kind'] === 'extdel';
  if ($xk && !feature($inc, 'ext')) return 'Die Funktion «Externe Einsatzkräfte» ist für Übungen ausgeschaltet';
  if (!$xk && $j['kind'] !== 'pls' && !feature($inc, 'res')) return 'Die Funktion «Rettlinge» ist für Übungen ausgeschaltet';
  $ref = null;
  if ($j['kind'] === 'extdel') {
    $s = $db->prepare("SELECT e.* FROM ex_injects i JOIN externals e ON e.id=i.ext_id WHERE i.id=? AND i.plan_id=?"); $s->execute([(int)$j['ref_id'], (int)$inc['plan_id']]); $ref = $s->fetch();
    if (!$ref) return 'Zuerst die externe Einsatzkraft freigeben, die hier entfernt wird';
  }
  if ($j['kind'] === 'seen' && $j['ref_id'] !== null) {
    $s = $db->prepare("SELECT r.* FROM ex_injects i JOIN rescuees r ON r.id=i.rescuee_id WHERE i.id=? AND i.plan_id=? AND r.removed IS NULL"); $s->execute([(int)$j['ref_id'], (int)$inc['plan_id']]); $ref = $s->fetch();
    if (!$ref) return 'Zuerst den Rettling freigeben, der hier gesichtet wird';
  }
  $u = $db->prepare("UPDATE ex_injects SET released=?, released_by=? WHERE id=? AND released IS NULL"); $u->execute([$now, $by, $j['id']]);
  if (!$u->rowCount()) return null;
  $at = $now - 60 * $ago; $la = (float)$j['lat']; $lo = (float)$j['lon']; $pos = sprintf('%.5F, %.5F, %s', $la, $lo, hm($at));
  logev($inc['id'], $by, 'ex', $by ? "gibt «{$j['label']}» frei (" . EX_KINDS[$j['kind']] . ')' : "«{$j['label']}» automatisch freigegeben (" . EX_KINDS[$j['kind']] . ", {$j['auto']} min nach dem Start)");
  if ($j['kind'] === 'ext') { // wie incident/external/save; die Spezialrolle nur, wenn sie noch aktiv ist
    $s = $db->prepare("SELECT id,name FROM roles WHERE id=? AND active=1"); $s->execute([(int)$j['role_id']]); $ro = $s->fetch() ?: null;
    $db->prepare("INSERT INTO externals(incident_id,name,descr,role_id,abbr,color,lat,lon,created_by,created,manual) VALUES(?,?,?,?,?,?,?,?,?,?,0)")->execute([$inc['id'], $j['name'], (string)$j['descr'], $ro ? (int)$ro['id'] : null, $j['abbr'], $j['color'], $la, $lo, $by ?? (int)$inc['created_by'], $now]);
    $db->prepare("UPDATE ex_injects SET ext_id=? WHERE id=?")->execute([(int)$db->lastInsertId(), $j['id']]);
    logev($inc['id'], $by, 'ext', sprintf('erfasst die externe Einsatzkraft «%s» (%s, %.5F, %.5F)', $j['name'], $ro ? $ro['name'] : 'ohne Spezialrolle', $la, $lo));
  } elseif ($j['kind'] === 'extdel') { // schon von Hand entfernt: nur die Freigabe zählt
    if ($ref['removed'] === null) { $db->prepare("UPDATE externals SET removed=? WHERE id=?")->execute([$now, $ref['id']]); logev($inc['id'], $by, 'ext', "entfernt die externe Einsatzkraft «{$ref['name']}»"); }
  } elseif ($j['kind'] === 'pls') {
    $s = $db->prepare("SELECT lat FROM incidents WHERE id=?"); $s->execute([$inc['id']]); $had = $s->fetch()['lat'] !== null; // aktueller Stand, exAuto gibt evtl. mehrere nacheinander frei
    $db->prepare("UPDATE incidents SET lat=?, lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at === (int)$inc['alarm_at'] ? null : $at, $inc['id']]);
    logev($inc['id'], $by, 'pls', ($had ? 'ändert' : 'setzt') . " den PLS des Einsatzes ($pos)");
  } elseif (!$ref) { // neuer Rettling, auch gesichtet (neu oder unbekannt): Rettling mit PLS am Element erfassen
    $nm = $j['name'];
    if ($nm === null) $nm = unknownName((int)$inc['id']); // unbekannter Rettling
    $db->prepare("INSERT INTO rescuees(incident_id,name,descr,created_by,created,pls_lat,pls_lon,pls_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$inc['id'], $nm, (string)$j['descr'], $by ?? (int)$inc['created_by'], $now, $la, $lo, $at]);
    $db->prepare("UPDATE ex_injects SET rescuee_id=? WHERE id=?")->execute([(int)$db->lastInsertId(), $j['id']]);
    logev($inc['id'], $by, 'rescuee', ($j['kind'] === 'seen' ? 'meldet einen gesichteten Rettling und erfasst ihn als ' : 'erfasst den Rettling ') . "«{$nm}»" . ((string)$j['descr'] !== '' ? ": {$j['descr']}" : ''));
    logev($inc['id'], $by, 'rescuee', "setzt den PLS von «{$nm}» ($pos)");
  } else {
    $db->prepare("UPDATE rescuees SET pls_lat=?, pls_lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at, $ref['id']]);
    logev($inc['id'], $by, 'rescuee', "meldet «{$ref['name']}» gesichtet und setzt den PLS neu ($pos)");
  }
  if (trim((string)$j['msg']) !== '' && feature($inc, 'msg')) // Meldung als Nachricht an alle (erscheint im Einsatzprotokoll über logRows)
    $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created) VALUES(?,?,?,NULL,?)")->execute([$inc['id'], $by ?? (int)$inc['created_by'], $j['msg'], $now]);
  return null;
}
// fällige automatische Freigaben (auto Minuten nach dem Start) bei jedem Polling erledigen, mit dem vorbereiteten PLS-Zeitpunkt (ohne: Drift ab der Freigabe).
// Eine Sichtung wartet, bis ihr Rettling freigegeben ist
function exAuto($inc) { global $db, $now;
  if (empty($inc['plan_id']) || $inc['ended_at'] !== null) return;
  $s = $db->prepare("SELECT * FROM ex_injects WHERE plan_id=? AND removed=0 AND released IS NULL AND auto IS NOT NULL AND auto<=? ORDER BY auto,id"); // direkt mit der Spalte vergleichen, sonst vergleicht SQLite den Parameter als Text
  $s->execute([(int)$inc['plan_id'], intdiv($now - (int)$inc['alarm_at'], 60)]); $js = $s->fetchAll();
  do { $n = 0; foreach ($js as $k => $j) if (exRelease($inc, $j, (int)$j['ago'], null) === null) { unset($js[$k]); $n++; } } while ($n && $js); // nochmals, falls eine Sichtung auf ein eben freigegebenes Element wartet
}
function canFlow($inc) { return canManage($inc) || isActivePart((int)$inc['id']); }
function modeRow($code) { global $db; $s = $db->prepare("SELECT * FROM modes WHERE code=?"); $s->execute([$code]); return $s->fetch() ?: null; }
function modeShares($code) { $m = modeRow($code); return !$m || (int)$m['share_location']; }
function setting($k, $d) { global $db; $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetch(); return $r ? $r['v'] : $d; }
function setSetting($k, $v) { global $db, $my; $db->prepare($my ? "INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)" : "INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]); }
// Nachrichten senden: nur wer den Einsatz angenommen hat; wer den Einsatz verwalten darf, immer;
// sonst je nach Spezialrolle (roles.can_message) bzw. Einstellung für Personen ohne Rolle (msg_no_role)
function canMessage($inc) {
  global $db; if (exLead($inc)) return true; // Übungsleitung darf senden, ohne teilzunehmen
  $p = part((int)$inc['id']);
  if (!$p || $p['mode'] === 'declined' || $p['left_at'] !== null) return false;
  if (canManage($inc)) return true;
  if ($p['role_id'] === null) return (bool)(int)setting('msg_no_role', '1');
  $s = $db->prepare("SELECT can_message FROM roles WHERE id=?"); $s->execute([$p['role_id']]); $r = $s->fetch();
  return !$r || (bool)(int)$r['can_message'];
}
function activeIncident() { global $db; return $db->query("SELECT * FROM incidents WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
// Rettling ohne Namen (Issue #86, Sichtungen in Übungen): «Unbekannter Rettling», fortlaufend, falls es schon unbekannte gibt
function unknownName($incId) { global $db;
  $s = $db->prepare("SELECT COUNT(*) c FROM rescuees WHERE incident_id=? AND name LIKE 'Unbekannter Rettling%'"); $s->execute([$incId]); $n = (int)$s->fetch()['c'];
  return 'Unbekannter Rettling' . ($n ? ' ' . ($n + 1) : ''); }
// Rettlinge eines Einsatzes (ohne entfernte), Zahlen als Zahlen
function incRescuees($id) { global $db;
  $s = $db->prepare("SELECT r.id,r.name,r.descr,r.lat,r.lon,r.loc_at,r.loc_by,r.rescued_at,r.rescued_by,r.created_by,r.created,r.pls_lat,r.pls_lon,r.pls_at,a.name loc_name,b.name rescued_name FROM rescuees r LEFT JOIN users a ON a.id=r.loc_by LEFT JOIN users b ON b.id=r.rescued_by WHERE r.incident_id=? AND r.removed IS NULL ORDER BY r.id");
  $s->execute([$id]); return array_map(fn($r) => floats(ints($r, ['id', 'loc_at', 'loc_by', 'rescued_at', 'rescued_by', 'created_by', 'created', 'pls_at']), ['lat', 'lon', 'pls_lat', 'pls_lon']), $s->fetchAll()); }
// Externe Einsatzkräfte eines Einsatzes (ohne entfernte), Zahlen als Zahlen
function incExternals($id) { global $db;
  $s = $db->prepare("SELECT e.id,e.name,e.descr,e.role_id,e.abbr,e.color,e.lat,e.lon,e.created_by,e.created,e.moved,u.name by_name FROM externals e LEFT JOIN users u ON u.id=e.created_by WHERE e.incident_id=? AND e.removed IS NULL ORDER BY e.id");
  $s->execute([$id]); return array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'created_by', 'created', 'moved']), ['lat', 'lon']), $s->fetchAll()); }
// Kürzel (max. 4 Zeichen) und Farbe (#rrggbb) externer Einsatzkräfte und ihrer Vorlagen; leer = null
function extStyle($in) { $a = mb_substr(trim((string)($in['abbr'] ?? '')), 0, 4); $c = (string)($in['color'] ?? '');
  return [$a === '' ? null : $a, preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : null]; }
// Spezialrolle aus der Anfrage: aktive Rolle oder null
function roleIn($v) { global $db; if (empty($v)) return null;
  $s = $db->prepare("SELECT id FROM roles WHERE id=? AND active=1"); $s->execute([(int)$v]); return $s->fetch() ? (int)$v : fail('Unbekannte Spezialrolle'); }
// Koordinaten aus der Anfrage (WGS84), gerundet; ungültig = Fehler
function llIn($la, $lo) { if (!is_numeric($la) || !is_numeric($lo) || abs($la) > 90 || abs($lo) > 180) fail('Ungültige Koordinaten'); return [round((float)$la, 7), round((float)$lo, 7)]; }
// Zeitpunkt des Einsatz-PLS (Beginn der Drift): eigener Zeitpunkt, sonst Alarmeingang
function plsAt($inc) { return (int)($inc['pls_at'] ?? $inc['alarm_at']); }
// PLS fürs Suchgebiet (Issue #24): Gibt es Rettlinge, zählen nur die vermissten (kein Standort, nicht gerettet), je mit eigenem PLS
// oder dem des Einsatzes; ohne Rettlinge der PLS des Einsatzes. Gleiche PLS (Ort und Zeit) werden zusammengefasst, names = Rettlinge.
function plsSources($inc, $res) {
  $ip = $inc['lat'] !== null ? ['lat' => (float)$inc['lat'], 'lon' => (float)$inc['lon'], 'at' => plsAt($inc), 'own' => 0] : null;
  if (!$res) return $ip ? [$ip + ['names' => []]] : [];
  $out = [];
  foreach ($res as $r) {
    if ($r['rescued_at'] !== null || $r['lat'] !== null) continue;
    $p = $r['pls_lat'] !== null ? ['lat' => (float)$r['pls_lat'], 'lon' => (float)$r['pls_lon'], 'at' => (int)$r['pls_at'], 'own' => 1] : $ip;
    if (!$p) continue;
    $k = sprintf('%.7F,%.7F,%d', $p['lat'], $p['lon'], $p['at']);
    $out[$k] = $out[$k] ?? $p + ['names' => []]; $out[$k]['names'][] = $r['name'];
  }
  return array_values($out);
}
// PLS aus der Anfrage (lat, lon, at): Koordinaten gültig, Zeitpunkt höchstens 7 Tage zurück und nicht in der Zukunft (fehlt = $def)
function plsIn($p, $def) { global $now;
  if (!is_array($p) || !isset($p['lat'], $p['lon']) || !is_numeric($p['lat']) || !is_numeric($p['lon']) || abs($p['lat']) > 90 || abs($p['lon']) > 180) fail('Ungültige Koordinaten für den PLS');
  $at = !empty($p['at']) ? (int)$p['at'] : $def;
  if ($at > $now + 60) fail('Der Zeitpunkt des PLS darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Zeitpunkt des PLS liegt mehr als 7 Tage zurück');
  return [round((float)$p['lat'], 7), round((float)$p['lon'], 7), $at]; }
// Zeit für Log-Texte (Schweizer Zeit)
function hm($t) { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m. H:i'); }
function rescuee($inc, $id) { global $db; $s = $db->prepare("SELECT * FROM rescuees WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)$id, $inc['id']]); return $s->fetch() ?: fail('Rettling nicht gefunden', 404); }
// Aufzeichnung beim Einsatzstart vorausgewählt: [scharfer Einsatz, Übung] (Einstellungen rec_real, rec_ex)
function recDefault() { return [(int)setting('rec_real', '0'), (int)setting('rec_ex', '1')]; }
// Funktionen (FEATURES): Einstellung features = JSON {"real": [ausgeschaltete Schlüssel], "ex": [...]}; gespeichert werden nur die ausgeschalteten, Neues ist also an
function featOff() { $v = json_decode(setting('features', ''), true) ?: []; $o = [];
  foreach (['real', 'ex'] as $t) $o[$t] = array_values(array_intersect(array_keys(FEATURES), (array)($v[$t] ?? []))); return $o; }
function features($ex) { $off = featOff()[$ex ? 'ex' : 'real']; $r = []; foreach (FEATURES as $k => $_) $r[$k] = in_array($k, $off, true) ? 0 : 1; return $r; }
function feature($inc, $k) { return (bool)features((int)$inc['is_exercise'])[$k]; }
// Rettlinge des Einsatzes, leer bei ausgeschalteter Funktion (dann zählt fürs Suchgebiet nur der PLS des Einsatzes)
function incRes($inc) { return feature($inc, 'res') ? incRescuees((int)$inc['id']) : []; }
// Werkzeug Flussdistanz: Vergleichsgeschwindigkeiten in km/h (Einstellung rd_speeds, kommagetrennt)
function rdSpeeds() { return array_map('floatval', array_filter(explode(',', setting('rd_speeds', '10,20,30')), 'strlen')); }
// Aufzeichnung umschalten: Zeitraum öffnen (an) bzw. schliessen (aus), record = neuer Zustand
function setRec($incId, $state) { global $db, $now;
  $db->prepare("UPDATE rec_periods SET stop=? WHERE incident_id=? AND stop IS NULL")->execute([$now, $incId]);
  if ($state === 1) $db->prepare("INSERT INTO rec_periods(incident_id,start) VALUES(?,?)")->execute([$incId, $now]);
  $db->prepare("UPDATE incidents SET record=?" . ($state === 1 ? ', recorded=1' : '') . " WHERE id=?")->execute([$state, $incId]); }
/* ---------- Web-Push (Issue #67) ---------- */
// Payload verschlüsselt nach RFC 8291 (aes128gcm), Absender per VAPID (RFC 8292, ES256). Nur PHP mit openssl und curl, keine Bibliothek.
// Schlüsselpaar wird beim ersten Bedarf erzeugt und in settings (vapid) gespeichert. Ohne openssl/curl gibt es keinen Push, die App pollt wie bisher.
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64ud($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function pushOk() { return function_exists('openssl_pkey_new') && function_exists('curl_multi_init') && function_exists('openssl_pkey_derive'); }
function ecRaw($k) { $d = openssl_pkey_get_details($k)['ec']; return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT); } // unkomprimierter Punkt, 65 Byte
function ecNew() { return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); }
function ecPub($raw) { // öffentlicher P-256-Schlüssel (65 Byte) als PEM für openssl
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n"; }
function vapid() { static $v = null; if ($v) return $v;
  $v = json_decode(setting('vapid', ''), true);
  if (!$v || empty($v['pem'])) { $k = ecNew(); openssl_pkey_export($k, $pem); $v = ['pub' => b64u(ecRaw($k)), 'pem' => $pem]; setSetting('vapid', json_encode($v)); }
  return $v; }
function vapidJwt($aud) { global $cfg; $v = vapid();
  $sub = $cfg['push_subject'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
  openssl_sign($h, $der, openssl_pkey_get_private($v['pem']), OPENSSL_ALGO_SHA256);
  // DER (SEQUENCE{INTEGER r, INTEGER s}) → r||s mit je 32 Byte
  $o = 3 + (ord($der[1]) & 0x80 ? 1 : 0); $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 2 + $rl; $sl = ord($der[$o]); $sv = substr($der, $o + 1, $sl);
  $fix = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
  return $h . '.' . b64u($fix($r) . $fix($sv)); }
function pushEncrypt($payload, $p256dh, $auth) { // RFC 8291: ein Datensatz, aes128gcm
  $ua = b64ud($p256dh); $as = b64ud($auth); if (strlen($ua) !== 65 || strlen($as) < 16) return null;
  $eph = ecNew(); $ep = ecRaw($eph); $sec = openssl_pkey_derive(openssl_pkey_get_public(ecPub($ua)), $eph, 32); if ($sec === false) return null;
  $ikm = hash_hkdf('sha256', $sec, 32, "WebPush: info\0" . $ua . $ep, $as);
  $salt = random_bytes(16); $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt); $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $ep . $ct . $tag; }
// Benachrichtigung für Personen vormerken; gesendet wird in out() nach der Antwort. $msg: t (Titel), b (Text), tag (wie die Quittierschlüssel der App), crit
function pushTo($userIds, $msg) { global $pushQ; if (!pushOk() || !$userIds) return; foreach (array_unique(array_map('intval', $userIds)) as $u) $pushQ[] = [$u, $msg]; }
function pushFlush() { global $db, $pushQ; $q = $pushQ; $pushQ = []; if (!$q) return;
  $ids = array_values(array_unique(array_column($q, 0))); $in = implode(',', array_fill(0, count($ids), '?'));
  $s = $db->prepare("SELECT s.* FROM push_subs s JOIN users u ON u.id=s.user_id WHERE u.active=1 AND s.user_id IN ($in)"); $s->execute($ids); $subs = $s->fetchAll();
  $mh = curl_multi_init(); $hs = []; $jwt = [];
  foreach ($q as [$u, $m]) foreach ($subs as $sub) { if ((int)$sub['user_id'] !== $u) continue;
    $pu = parse_url($sub['endpoint']); if (!$pu || !in_array($pu['scheme'] ?? '', ['https', 'http'], true)) continue;
    $aud = $pu['scheme'] . '://' . $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : ''); $jwt[$aud] = $jwt[$aud] ?? vapidJwt($aud);
    $body = pushEncrypt(json_encode($m, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']); if ($body === null) continue;
    $h = curl_init($sub['endpoint']);
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . (!empty($m['crit']) ? 3600 : 600), 'Urgency: high', 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($m['tag'] ?? 'sw')), 0, 32), 'Authorization: vapid t=' . $jwt[$aud] . ', k=' . vapid()['pub']]]);
    curl_multi_add_handle($mh, $h); $hs[] = [$h, (int)$sub['id']]; }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  foreach ($hs as [$h, $id]) { $c = curl_getinfo($h, CURLINFO_HTTP_CODE); if ($c === 404 || $c === 410) $db->prepare("DELETE FROM push_subs WHERE id=?")->execute([$id]); // abgelaufen
    curl_multi_remove_handle($mh, $h); curl_close($h); }
  curl_multi_close($mh); }
// alle aktiven Benutzer ausser $skip (IDs), bei vorbereiteten Übungen nur die aufgebotenen ($only, NULL = alle); kritisch wie critical(): scharf immer, Übung nur mit Übungsmodus
function pushAlarm($inc, $skip, $only = null) { global $db; if (!pushOk()) return;
  foreach ($db->query("SELECT id,exercise_mode FROM users WHERE active=1")->fetchAll() as $u) { $id = (int)$u['id']; if (in_array($id, $skip, true) || ($only !== null && !in_array($id, $only, true))) continue;
    pushTo([(int)$u['id']], ['t' => (int)$inc['is_exercise'] ? 'ÜBUNG' : 'EINSATZ', 'b' => $inc['message'], 'tag' => 'i:' . $inc['id'], 'crit' => !(int)$inc['is_exercise'] || (int)$u['exercise_mode'] ? 1 : 0]); } }
function logev($incId, $userId, $kind, $text) { global $db, $now; $db->prepare("INSERT INTO incident_log(incident_id,ts,user_id,kind,text) VALUES(?,?,?,?,?)")->execute([$incId, $now, $userId, $kind, $text]); }
function hav($a, $b, $c, $d) { $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = $p2 - $p1; $dl = deg2rad($d - $b); $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2; return 12742000 * asin(min(1, sqrt($x))); }
function avg($a) { $a = array_values(array_filter($a, fn($x) => $x !== null)); return $a ? round(array_sum($a) / count($a), 1) : null; }
function http_get($url) {
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']); $r = curl_exec($c); curl_close($c); return $r; }
  return @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
}
// Gewässernetz über den Server mit Datei-Cache, damit gleiche Abfragen (z. B. Suchgebiet aller Geräte eines Einsatzes) nur einmal
// zu Overpass gehen. Haltedauer: Einstellung overpass_ttl in Minuten (Standard 120, 0 = kein Cache).
function overpassServe($q) { global $now;
  $ttl = max(0, (int)setting('overpass_ttl', '120')) * 60;
  $dir = __DIR__ . '/data/overpass'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = "$dir/" . sha1($q) . '.json';
  $hit = function () use ($ttl, $file) { clearstatcache(true, $file); if (!$ttl || !is_file($file) || time() - filemtime($file) >= $ttl) return false; header('X-Cache: HIT'); readfile($file); exit; };
  $hit();
  if (!overpassActive()) { // Zugriff auf Overpass in der Verwaltung ausgeschaltet (Issue #75): nur noch Daten aus dem Cache, auch abgelaufene, ohne Rückfall im Gerät
    clearstatcache(true, $file); if (is_file($file)) { header('X-Cache: STALE'); readfile($file); exit; }
    fail('Overpass ist in der Verwaltung ausgeschaltet', 503);
  }
  // Sperre je Abfrage: gleichzeitige Anfragen warten auf die erste und lesen danach den Cache, statt selbst zu Overpass zu gehen
  $lk = @fopen("$file.lock", 'c'); if ($lk) flock($lk, LOCK_EX);
  $hit();
  foreach (array_merge(glob("$dir/*.json") ?: [], glob("$dir/*.lock") ?: []) as $x) if ($now - filemtime($x) > max($ttl, OVERPASS_KEEP_S)) @unlink($x); // aufräumen, auch als Rückfall zu alt
  set_time_limit(90);
  $r = overpassFetch($q);
  if ($r !== null) { if ($ttl && file_put_contents("$file.tmp", $r) !== false) rename("$file.tmp", $file); header('X-Cache: MISS'); echo $r; }
  elseif (is_file($file)) { header('X-Cache: STALE'); readfile($file); } // Overpass nicht erreichbar: abgelaufene Daten sind besser als keine
  else out(['error' => 'Overpass antwortet nicht', 'query' => $q, 'servers' => overpassActive()], 502); // mit Abfrage und Servern: das Gerät fragt Overpass dann selbst (overpassNet in common.js)
  exit;
}
// Rückfall (Issue #28): Vom Hosting aus lehnt Overpass oft ab, vom Gerät aus nicht. Das Gerät liefert sein Ergebnis hier für den Cache
// aller ab. Der Schlüssel kommt aus denselben Parametern wie bei overpass; gespeichert werden nur geprüfte Wege mit den nötigen Feldern.
function overpassPut($q, $els) {
  $ttl = max(0, (int)setting('overpass_ttl', '120')) * 60;
  if (!overpassActive()) out(['ok' => true]); // Overpass ausgeschaltet: nichts annehmen
  if (!is_array($els) || count($els) > 50000) fail('Ungültige Gewässerdaten');
  $num = fn($x, $m) => (is_int($x) || is_float($x)) && abs($x) <= $m;
  $w = [];
  foreach ($els as $e) {
    $n = $e['nodes'] ?? null; $g = $e['geometry'] ?? null; $t = $e['tags'] ?? [];
    if (($e['type'] ?? '') !== 'way' || !is_int($e['id'] ?? null) || !is_array($n) || !is_array($g) || count($n) < 2 || count($n) !== count($g) || count($n) > 100000) fail('Ungültige Gewässerdaten');
    if (!is_array($t) || !preg_match('/^(river|stream|canal)$/', (string)($t['waterway'] ?? ''))) fail('Ungültige Gewässerdaten');
    $p = [];
    foreach ($n as $k => $id) {
      if (!is_int($id) || !$num($g[$k]['lat'] ?? null, 90) || !$num($g[$k]['lon'] ?? null, 180)) fail('Ungültige Gewässerdaten');
      $p[] = ['lat' => (float)$g[$k]['lat'], 'lon' => (float)$g[$k]['lon']];
    }
    $w[] = ['type' => 'way', 'id' => $e['id'], 'nodes' => array_values($n), 'geometry' => $p, 'tags' => ['waterway' => $t['waterway']] + (is_string($t['name'] ?? null) ? ['name' => mb_substr($t['name'], 0, 200)] : [])];
  }
  $dir = __DIR__ . '/data/overpass'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = "$dir/" . sha1($q) . '.json'; clearstatcache(true, $file);
  if (!$ttl || (is_file($file) && time() - filemtime($file) < $ttl)) out(['ok' => true]); // kein Cache bzw. schon aktuell: nichts überschreiben
  $tmp = "$file." . bin2hex(random_bytes(4)) . '.tmp';
  if (file_put_contents($tmp, json_encode(['elements' => $w])) !== false) rename($tmp, $file);
  out(['ok' => true]);
}
function overpassQuery($in) { // Abfrage aus Parametern bauen (keine freien Abfragen über den Server)
  $f = fn($x) => sprintf('%.5F', (float)$x);
  if (isset($in['around'])) {
    [$r, $la, $lo] = array_map('floatval', array_values((array)$in['around']) + [0, 0, 0]);
    if ($r < 100 || $r > 30000 || abs($la) > 90 || abs($lo) > 180) fail('Ungültiger Umkreis');
    $sel = 'around:' . (int)round($r) . ',' . $f($la) . ',' . $f($lo);
  } elseif (isset($in['bbox'])) {
    [$s, $w, $n, $e] = array_map('floatval', array_values((array)$in['bbox']) + [0, 0, 0, 0]);
    if ($s >= $n || $w >= $e || $n - $s > 1 || $e - $w > 1.5 || abs($s) > 90 || abs($n) > 90 || abs($w) > 180 || abs($e) > 180) fail('Ungültiger Ausschnitt');
    $sel = implode(',', array_map($f, [$s, $w, $n, $e]));
  } else fail('Ausschnitt fehlt');
  return '[out:json][timeout:25];way(' . $sel . ')["waterway"~"^(river|stream|canal)$"];out geom;';
}
function overpassFetch($q) { // erste Antwort mit elements, sonst null; jeder Versuch zählt in der Statistik (opStat)
  foreach (overpassActive() as $url) {
    $t = microtime(true); $err = '';
    if (function_exists('curl_init')) {
      $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query(['data' => $q]), CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
      $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
      if ($r === false) $err = curl_errno($c) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($c);
      curl_close($c);
    } else {
      $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SwiftwaterRescue/0.1\r\n", 'content' => http_build_query(['data' => $q])]]));
      $code = preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
      if ($r === false) $err = 'Keine Antwort';
    }
    $good = $code === 200 && is_string($r) && isset(json_decode($r, true)['elements']);
    opStat($url, 'server', $good, (microtime(true) - $t) * 1000, $good ? null : ($err ?: ($code !== 200 ? "HTTP $code" : 'Ungültige Antwort')));
    if ($good) return $r;
  }
  return null;
}
// Verwendungsstatistik je Overpass-Server und Quelle (Issue #45, Tabelle overpass_stats); darf die Abfrage nie stören
function opStat($url, $src, $ok, $ms, $err = null) { global $db;
  try {
    $now = time(); $err = $err === null ? null : mb_substr($err, 0, 150);
    $u = $db->prepare($ok ? "UPDATE overpass_stats SET uses=uses+1, ok=ok+1, ok_ms=ok_ms+?, last_ok=? WHERE url=? AND src=?" : "UPDATE overpass_stats SET uses=uses+1, fails=fails+1, last_fail=?, last_err=? WHERE url=? AND src=?");
    $p = $ok ? [(int)$ms, $now, $url, $src] : [$now, $err, $url, $src];
    $u->execute($p); if ($u->rowCount()) return;
    if (setting('overpass_stats_since', '') === '') setSetting('overpass_stats_since', (string)$now);
    try { $db->prepare("INSERT INTO overpass_stats(url,src,uses,ok,fails,ok_ms,last_ok,last_fail,last_err) VALUES(?,?,1,?,?,?,?,?,?)")->execute([$url, $src, $ok ? 1 : 0, $ok ? 0 : 1, $ok ? (int)$ms : 0, $ok ? $now : null, $ok ? null : $now, $err]); }
    catch (Throwable $e) { $u->execute($p); } // gleichzeitig angelegt: dann zählen
  } catch (Throwable $e) {}
}
// Ortsname zu Koordinaten: swisstopo-Ortschaftenverzeichnis, ausserhalb der Schweiz OpenStreetMap (Nominatim), sonst Koordinaten
function placeName($la, $lo) {
  $r = json_decode(http_get('https://api3.geo.admin.ch/rest/services/api/MapServer/identify?geometryType=esriGeometryPoint&sr=4326&tolerance=0&returnGeometry=false&lang=de&layers=all:ch.swisstopo-vd.ortschaftenverzeichnis_plz&geometry=' . sprintf('%.6F,%.6F', $lo, $la)) ?: '', true);
  $n = $r['results'][0]['attributes']['langtext'] ?? null;
  if (is_string($n) && $n !== '') return $n;
  $r = json_decode(http_get('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=14&accept-language=de&' . sprintf('lat=%.6F&lon=%.6F', $la, $lo)) ?: '', true);
  foreach (['village', 'town', 'city', 'suburb', 'hamlet', 'municipality'] as $k) if (!empty($r['address'][$k])) return $r['address'][$k];
  return sprintf('%.4F, %.4F', $la, $lo);
}
// Einsatzprotokoll: Log-Einträge plus alle Nachrichten im Volltext mit Empfängern (aus der Nachrichtentabelle,
// daher auch für ältere Einsätze vollständig); sortiert nach Zeit
// anon: anonymisierte Fassung (Issue #54, anonCtx/anonText); nach dem endgültigen Anonymisieren (log_anon) stehen die Bezeichnungen in actor, from_label und to_label
function logRows($id, $asc = false, $anon = false, $self = null) {
  global $db;
  $s = $db->prepare("SELECT l.id,l.ts,l.kind,l.text,l.user_id uid,COALESCE(u.name,l.actor) name FROM incident_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.incident_id=? AND l.kind<>'message' ORDER BY l.id DESC LIMIT 500");
  $s->execute([$id]);
  $rows = array_map(fn($r) => ints($r, ['id', 'ts']), $s->fetchAll());
  $names = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  $c = $anon ? anonCtx($id, $self) : null;
  $who = fn($u) => $c ? ($c['u'][(int)$u] ?? 'Person') : ($names[$u] ?? '?');
  $m = $db->prepare("SELECT m.id,m.created,m.text,m.recipients,m.to_label,m.lat,m.lon,m.mark,m.mark_lat,m.mark_lon,m.from_user uid,COALESCE(u.name,m.from_label) name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? ORDER BY m.id DESC LIMIT 500");
  $m->execute([$id]);
  $ll = fn($a, $b) => sprintf('%.5F, %.5F', $a, $b);
  foreach ($m->fetchAll() as $r) {
    $to = $r['to_label'] ?? ($r['recipients'] === null ? 'alle' : implode(', ', array_map($who, json_decode($r['recipients'], true) ?: [])));
    $extra = ($r['lat'] !== null ? ' · Standort ' . $ll($r['lat'], $r['lon']) : '') . ($r['mark_lat'] !== null ? ' · Kartenmarkierung ' . $ll($r['mark_lat'], $r['mark_lon']) . ((int)$r['mark'] ? '' : ' (entfernt)') : '');
    $rows[] = ['id' => 1000000000 + (int)$r['id'], 'ts' => (int)$r['created'], 'kind' => 'message', 'text' => "Nachricht an $to: " . ($c ? anonText($r['text'], $c) : $r['text']) . $extra, 'name' => $r['name'], 'uid' => $r['uid']];
  }
  foreach ($rows as &$r) {
    if ($c) { if ($r['uid'] !== null) $r['name'] = $who($r['uid']); if ($r['kind'] !== 'message') $r['text'] = anonText($r['text'], $c, $r['kind']); }
    unset($r['uid']);
  }
  unset($r);
  usort($rows, fn($a, $b) => $asc ? [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']] : [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']]);
  return array_slice($rows, 0, 1000);
}
/* ---------- Anonymisieren (Issue #54) ---------- */
// Bezeichnungen je Einsatz: Teilnehmende «Einsatzkraft N» (Reihenfolge der Teilnahme), übrige beteiligte Konten (Ersteller, Verwaltung) «Person N»,
// Rettlinge «Rettling N», externe Einsatzkräfte «Externe Einsatzkraft N» (je in der Reihenfolge der Erfassung, auch entfernte).
// Frühere Namen aus Umbenennungen im Log gelten mit. map: Text → Ersatz; ersetzt werden ganze Namen (nicht innerhalb von Wörtern), längste zuerst.
function anonCtx($id, $self = null) { global $db;
  $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return array_column($s->fetchAll(), 'v'); };
  $u = []; $n = 0; $o = 0;
  foreach ($q("SELECT user_id v FROM participants WHERE incident_id=? ORDER BY id") as $x) $u[(int)$x] = 'Einsatzkraft ' . ++$n;
  foreach (array_merge($q("SELECT created_by v FROM incidents WHERE id=?"), $q("SELECT user_id v FROM incident_log WHERE incident_id=? AND user_id IS NOT NULL ORDER BY id"), $q("SELECT from_user v FROM messages WHERE incident_id=? AND from_user IS NOT NULL ORDER BY id")) as $x)
    if ($x !== null && !isset($u[(int)$x])) $u[(int)$x] = 'Person ' . ++$o;
  $nm = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  $map = [];
  // self (Issue #61): die eigene Person behält ihren Namen («anonymisiert ausser sich selbst»), die übrigen behalten ihre Nummern
  if ($self !== null && isset($u[$self], $nm[$self])) $u[$self] = $nm[$self];
  foreach ($u as $k => $l) if ($k !== $self && isset($nm[$k]) && mb_strlen(trim($nm[$k])) >= 2) $map[(string)$nm[$k]] = $l;
  // gleicher Name = gleiche Bezeichnung (das Log kennt nur Namen, z. B. entfernt und neu erfasst)
  $lab = function ($sql, $pre) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); $r = []; $byName = []; foreach ($s->fetchAll() as $x) { $nx = (string)$x['name']; $byName[$nx] = $byName[$nx] ?? $pre . (count($byName) + 1); $r[(int)$x['id']] = [$byName[$nx], $nx]; } return $r; };
  $res = $lab("SELECT id,name FROM rescuees WHERE incident_id=? ORDER BY id", 'Rettling ');
  $ext = $lab("SELECT id,name FROM externals WHERE incident_id=? ORDER BY id", 'Externe Einsatzkraft ');
  $rn = []; foreach ($res as [$l, $x]) $rn[$x] = $rn[$x] ?? $l;
  $en = []; foreach ($ext as [$l, $x]) $en[$x] = $en[$x] ?? $l;
  // Umbenennungen rückwärts: der alte Name erhält die Bezeichnung des neuen
  $s = $db->prepare("SELECT kind,text FROM incident_log WHERE incident_id=? AND kind IN ('rescuee','ext') ORDER BY id DESC"); $s->execute([$id]);
  foreach ($s->fetchAll() as $r) {
    if ($r['kind'] === 'rescuee' && preg_match('/^ändert den Rettling «([^»]*)» zu «([^»]*)»/u', $r['text'], $m) && isset($rn[$m[2]])) $rn[$m[1]] = $rn[$m[1]] ?? $rn[$m[2]];
    if ($r['kind'] === 'ext' && preg_match('/^ändert die externe Einsatzkraft «([^»]*)» zu «([^»]*)»/u', $r['text'], $m) && isset($en[$m[2]])) $en[$m[1]] = $en[$m[1]] ?? $en[$m[2]];
  }
  foreach ($rn as $x => $l) { $map["«{$x}»"] = "«{$l}»"; if (mb_strlen($x) >= 3 && !isset($map[(string)$x])) $map[(string)$x] = $l; } // Rettlinge auch ohne «» (z. B. in Nachrichten)
  foreach ($en as $x => $l) $map["«{$x}»"] = $map["«{$x}»"] ?? "«{$l}»";
  $keys = array_map('strval', array_keys($map)); usort($keys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
  $re = $keys ? '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys)) . ')(?![\p{L}\p{N}])/u' : null;
  return ['u' => $u, 'r' => array_map(fn($x) => $x[0], $res), 'e' => array_map(fn($x) => $x[0], $ext), 'map' => $map, 're' => $re];
}
// Text anonymisieren: Namen ersetzen; bei Rettlingen fällt die Beschreibung weg («…»: Beschreibung)
function anonText($t, $c, $kind = '') {
  if ($t === null || $t === '') return $t;
  if ($kind === 'rescuee') $t = preg_replace('/^((?:erfasst|ändert|meldet einen).*?»): .*$/su', '$1', $t);
  if (!$c['re']) return $t;
  $r = preg_replace_callback($c['re'], fn($m) => $c['map'][$m[0]] ?? $m[0], $t);
  return $r ?? '(Text nicht anonymisierbar)';
}
/* ---------- Anonymisierung in Stufen, einstellbar und automatisch (Issue #87) ---------- */
// Stufen in dieser Reihenfolge: [Bezeichnung, Beschreibung, endgültig]. Einstellung anon_cfg je Stufe: on = angeboten (aus: weder von Hand noch automatisch),
// real/ex = automatisch so viele Tage nach dem Einsatzende (null = nie). Spalten: view_anon, rp_anon, log_anon, txt_anon, part_anon; «Aufzeichnung löschen» setzt recorded 0.
// Ohne gespeicherte Einstellung gelten die Fristen aus ANON_DEFAULT (Tage nach dem Einsatzende, [scharf, Übung]).
const ANON_STEPS = [
  'view' => ['Anzeige anonymisieren', 'Log, Replay und After Action Review zeigen für alle nur noch Bezeichnungen wie «Einsatzkraft 3», «Rettling 1» (in «Meine Einsätze» die Person selbst mit Namen). Die Daten bleiben unverändert, die Stufe lässt sich aufheben.', 0],
  'replay' => ['Replay pseudonymisieren', 'Die Standortverläufe verlieren die Verbindung zu den Benutzerkonten, das Replay zeigt nur noch Bezeichnungen (mit Einrückoption und Spezialrolle). Nur bei aufgezeichneten Einsätzen.', 1],
  'log' => ['Einsatzprotokoll pseudonymisieren', 'Log-Einträge und Nachrichten verlieren die Verbindung zu den Benutzerkonten, Namen im Text werden ersetzt, Beschreibungen der Rettlinge entfallen. Vornamen allein oder Spitznamen erkennt die Ersetzung nicht.', 1],
  'texts' => ['Personenangaben und Freitexte entfernen', 'Namen und Beschreibungen von Rettlingen und externen Einsatzkräften werden auch in den Einsatzdaten ersetzt, Bezeichnungen von Markierungen und Bereichen anonymisiert und Nachrichtentexte gelöscht. Pseudonymisiert vorher das Einsatzprotokoll.', 1],
  'parts' => ['Teilnahmen vom Konto lösen', 'Die Teilnahmen verlieren die Verbindung zu den Benutzerkonten: Der Einsatz fehlt danach in der Statistik pro Person und in «Meine Einsätze» der Teilnehmenden, Log, Replay und After Action Review zeigen nur noch Bezeichnungen. Pseudonymisiert vorher Replay und Einsatzprotokoll. Der Inhalt des After Action Reviews bleibt unverändert.', 1],
  'track' => ['Aufzeichnung löschen', 'Standortverläufe, aufgezeichnete Zeiträume und Helikopterspuren werden gelöscht, ein Replay gibt es danach nicht mehr.', 1],
];
const ANON_DEFAULT = ['view' => [90, 30], 'replay' => [180, 90], 'log' => [365, 180], 'texts' => [730, 365], 'parts' => [730, 365], 'track' => [365, 180]];
function anonCfg() { $c = json_decode(setting('anon_cfg', ''), true); $c = is_array($c) ? $c : []; $r = [];
  $d = fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : max(0, min(36500, (int)$v));
  foreach (ANON_STEPS as $k => $_) { $x = is_array($c[$k] ?? null) ? $c[$k] : ['real' => ANON_DEFAULT[$k][0] ?? null, 'ex' => ANON_DEFAULT[$k][1] ?? null]; $r[$k] = ['on' => (int)($x['on'] ?? 1) ? 1 : 0, 'real' => $d($x['real'] ?? null), 'ex' => $d($x['ex'] ?? null)]; }
  return $r; }
// würde die Stufe an diesem Einsatz etwas ändern?
function anonTodo($inc, $k) { switch ($k) {
  case 'view': return !(int)$inc['view_anon'];
  case 'replay': return (int)$inc['recorded'] && $inc['rp_anon'] === null;
  case 'log': return !(int)$inc['log_anon'];
  case 'texts': return !(int)$inc['txt_anon'];
  case 'parts': return !(int)$inc['part_anon'];
  case 'track': return (bool)(int)$inc['recorded']; } return false; }
// eine Stufe an einem beendeten Einsatz ausführen, in einer Transaktion; false = nichts zu tun (schon erledigt, nicht beendet, keine Aufzeichnung)
// how: Zusatz im Log, z. B. «(Verwaltung)» oder «(automatisch …)». Die Einträge stehen ohne Person (wie Issue #54).
function anonDo($id, $k, $how) { global $db;
  $db->beginTransaction();
  try {
    $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); $inc = $s->fetch(); $s->closeCursor();
    if (!$inc || $inc['ended_at'] === null || !anonTodo($inc, $k)) { $db->rollBack(); return false; }
    if ($k !== 'track') aarArchive($inc); // vor dem ersten Eingriff, in derselben Transaktion: ohne Sicherung keine Anonymisierung («Aufzeichnung löschen» betrifft das Review nicht)
    if ($k === 'texts' && !(int)$inc['log_anon'] && anonStep($inc, 'log', $how)) $inc['log_anon'] = 1; // Freitexte setzen das pseudonymisierte Protokoll voraus (danach fehlten die Namen für die Ersetzung)
    if ($k === 'parts') { // Replay und Protokoll brauchen die Konten für ihre Bezeichnungen, also zuerst
      if ((int)$inc['recorded'] && $inc['rp_anon'] === null && anonStep($inc, 'replay', $how)) $inc['rp_anon'] = '[]';
      if (!(int)$inc['log_anon'] && anonStep($inc, 'log', $how)) $inc['log_anon'] = 1; }
    $ok = anonStep($inc, $k, $how);
    $db->commit(); return $ok;
  } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
// zuerst schreibend sperren (UPDATE mit Bedingung): bei zwei gleichzeitigen Anfragen läuft nur eine durch
function anonStep($inc, $k, $how) { global $db; $id = (int)$inc['id'];
  $claim = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->rowCount() > 0; };
  switch ($k) {
  case 'view':
    if (!$claim("UPDATE incidents SET view_anon=1 WHERE id=? AND view_anon=0")) return false;
    logev($id, null, 'anon', "Anzeige anonymisiert $how"); return true;
  case 'replay':
    if (!$claim("UPDATE incidents SET rp_anon='[]' WHERE id=? AND rp_anon IS NULL AND recorded=1")) return false;
    $c = anonCtx($id); $people = [];
    $up = $db->prepare("UPDATE track SET user_id=? WHERE incident_id=? AND user_id=?");
    foreach (replayParts($id) as $p) { $pid = -(count($people) + 1); $up->execute([$pid, $id, $p['user_id']]);
      $people[] = ['user_id' => $pid, 'name' => $c['u'][$p['user_id']] ?? 'Einsatzkraft'] + array_intersect_key($p, array_flip(['mode', 'role_id', 'left_at', 'role', 'color', 'mode_name', 'joined'])); }
    $db->prepare("DELETE FROM track WHERE incident_id=? AND user_id>0")->execute([$id]); // Verläufe ohne Teilnahme (z. B. abgelehnt)
    $db->prepare("UPDATE participants SET lat=NULL, lon=NULL, loc_at=NULL, log_lat=NULL, log_lon=NULL, log_at=NULL, fast_at=NULL WHERE incident_id=?")->execute([$id]);
    $db->prepare("UPDATE incidents SET rp_anon=? WHERE id=?")->execute([json_encode($people), $id]);
    logev($id, null, 'anon', "Replay anonymisiert $how"); return true;
  case 'log':
    if (!$claim("UPDATE incidents SET log_anon=1 WHERE id=? AND log_anon=0")) return false;
    $c = anonCtx($id); $who = fn($u) => $c['u'][(int)$u] ?? 'Person';
    $s = $db->prepare("SELECT id,user_id,kind,text,actor FROM incident_log WHERE incident_id=?"); $s->execute([$id]);
    $up = $db->prepare("UPDATE incident_log SET text=?, actor=?, user_id=NULL WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['text'], $c, $r['kind']), $r['user_id'] !== null ? $who($r['user_id']) : $r['actor'], $r['id']]);
    $s = $db->prepare("SELECT id,from_user,from_label,recipients,to_label,text FROM messages WHERE incident_id=?"); $s->execute([$id]);
    $up = $db->prepare("UPDATE messages SET text=?, from_label=?, to_label=?, from_user=NULL, recipients=NULL WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['text'], $c), $r['from_user'] !== null ? $who($r['from_user']) : $r['from_label'],
      $r['to_label'] ?? ($r['recipients'] === null ? 'alle' : implode(', ', array_map($who, json_decode($r['recipients'], true) ?: []))), $r['id']]);
    $db->prepare("UPDATE incidents SET message=? WHERE id=?")->execute([anonText($inc['message'], $c), $id]);
    logev($id, null, 'anon', "Einsatzprotokoll anonymisiert $how"); return true;
  case 'texts':
    if (!$claim("UPDATE incidents SET txt_anon=1 WHERE id=? AND txt_anon=0")) return false;
    $c = anonCtx($id); // vor dem Umbenennen: Bezeichnungen aus den bisherigen Namen
    $up = $db->prepare("UPDATE rescuees SET name=?, descr=NULL WHERE id=?"); foreach ($c['r'] as $rid => $l) $up->execute([$l, $rid]);
    $up = $db->prepare("UPDATE externals SET name=?, descr=NULL WHERE id=?"); foreach ($c['e'] as $eid => $l) $up->execute([$l, $eid]);
    $s = $db->prepare("SELECT id,name FROM pois WHERE incident_id=?"); $s->execute([$id]); $up = $db->prepare("UPDATE pois SET name=? WHERE id=?");
    foreach ($s->fetchAll() as $r) $up->execute([anonText($r['name'], $c), $r['id']]);
    $db->prepare("UPDATE messages SET text='(Text entfernt)' WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Personenangaben und Freitexte entfernt $how"); return true;
  case 'parts': // negative ID in derselben Reihenfolge wie die Standortverläufe des Replays (Teilnahmen ohne Abgelehnte nach id, danach die Abgelehnten), Bezeichnung in label
    if (!$claim("UPDATE incidents SET part_anon=1 WHERE id=? AND part_anon=0")) return false;
    $c = anonCtx($id); $s = $db->prepare("SELECT id,user_id FROM participants WHERE incident_id=? ORDER BY CASE WHEN mode='declined' THEN 1 ELSE 0 END, id"); $s->execute([$id]);
    $up = $db->prepare("UPDATE participants SET user_id=?, label=?, lat=NULL, lon=NULL, loc_at=NULL, log_lat=NULL, log_lon=NULL, log_at=NULL, fast_at=NULL WHERE id=?"); $n = 0;
    foreach ($s->fetchAll() as $r) { $u = (int)$r['user_id']; $up->execute([-(++$n), $u < 0 ? null : ($c['u'][$u] ?? 'Einsatzkraft'), $r['id']]); }
    // übrige Verweise auf Konten in den Einsatzdaten (ausgelöst von, erfasst/markiert/gerettet von); das After Action Review bleibt unverändert
    $db->prepare("UPDATE incidents SET created_by=NULL WHERE id=?")->execute([$id]);
    $db->prepare("UPDATE rescuees SET created_by=NULL, loc_by=NULL, rescued_by=NULL WHERE incident_id=?")->execute([$id]);
    foreach (['externals', 'pois'] as $t) $db->prepare("UPDATE $t SET created_by=NULL WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Teilnahmen vom Konto gelöst $how"); return true;
  case 'track':
    if (!$claim("UPDATE incidents SET record=0, recorded=0, rp_anon=NULL WHERE id=? AND recorded=1")) return false;
    foreach (['track', 'rec_periods', 'air_track'] as $t) $db->prepare("DELETE FROM $t WHERE incident_id=?")->execute([$id]);
    logev($id, null, 'anon', "Aufzeichnung gelöscht $how"); return true; }
  return false; }
// automatisch fällige Stufen (kein Cron: höchstens alle 10 Minuten bei einer Anfrage, je Lauf höchstens 20 Schritte, der Rest beim nächsten Mal)
function anonAuto() { global $db, $now;
  if ((int)setting('anon_auto_at', 0) > $now - 600) return; setSetting('anon_auto_at', (string)$now);
  $n = 0;
  foreach (anonCfg() as $k => $c) { if (!$c['on']) continue;
    foreach (['real' => 0, 'ex' => 1] as $t => $ex) { if ($c[$t] === null) continue;
      $s = $db->prepare("SELECT * FROM incidents WHERE ended_at IS NOT NULL AND ended_at<=? AND is_exercise=? ORDER BY ended_at"); $s->execute([$now - $c[$t] * 86400, $ex]);
      foreach ($s->fetchAll() as $inc) { if (!anonTodo($inc, $k)) continue;
        if (anonDo((int)$inc['id'], $k, '(automatisch, ' . ($c[$t] ? $c[$t] . ($c[$t] === 1 ? ' Tag' : ' Tage') . ' nach dem Einsatzende' : 'beim Einsatzende') . ')')) $n++;
        if ($n >= 20) { setSetting('anon_auto_at', (string)($now - 540)); return; } } } } }
// Einsatzkräfte fürs Replay: Spezialrolle mit Stand am Ende (Rollenwechsel stehen im Log); joined = erste Annahme (nach dem Anonymisieren des Protokolls: erste Standortmeldung)
function replayParts($id) { global $db;
  $s = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.left_at,COALESCE(u.name,p.label,'Einsatzkraft') name,r.name role,r.color,m.name mode_name,COALESCE((SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=p.incident_id AND l.user_id=p.user_id AND l.kind='accept'),(SELECT MIN(ts) FROM track t WHERE t.incident_id=p.incident_id AND t.user_id=p.user_id)) joined
     FROM participants p LEFT JOIN users u ON u.id=p.user_id LEFT JOIN roles r ON r.id=p.role_id LEFT JOIN modes m ON m.code=p.mode WHERE p.incident_id=? AND p.mode<>'declined' ORDER BY p.id"); // label: vom Konto gelöste Teilnahme
  $s->execute([$id]); return array_map(fn($r) => ints($r, ['user_id', 'role_id', 'left_at', 'joined']), $s->fetchAll()); }
function incById($id) { global $db; $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)$id]); return $s->fetch() ?: fail('Einsatz nicht gefunden', 404); }

/* ---------- AI-Assistent (Issue #111) ---------- */
// Der Server baut den Kontext je Funktion selbst (gleiche Rechte wie die Ansicht) und fragt den Anbieter per curl; der API-Schlüssel verlässt den Server nie.
// Funktionen: Schlüssel => [Bezeichnung, Einsatzarten (real = scharf, ex = Übung, adm = Verwaltung ohne Einsatz), Companion möglich, Beschreibung]
const AI_FN = [
  'aar' => ['After Action Review', ['real', 'ex'], 1, 'Entwürfe für die Textfelder aus Einsatzdaten und Einsatzlog; begleitend: Entwurf für die leeren Felder beim Öffnen'],
  'log' => ['Zusammenfassung des Einsatzlogs', ['real', 'ex'], 1, 'Zusammenfassung in Worten zum Log (Verwaltung und Meine Einsätze); begleitend: beim Öffnen des Logs'],
  'plan' => ['Übungsvorbereitung', ['ex'], 0, 'Vorschläge für weitere Elemente, Meldungen und den Ablauf einer vorbereiteten Übung'],
  'stats' => ['Statistik in Worten', ['adm'], 0, 'Auswertung der Einsatzstatistik in Worten: Entwicklung, Auffälligkeiten'],
  'sit' => ['Lagezusammenfassung im Einsatz', ['real', 'ex'], 1, 'Kurze Lagezusammenfassung im laufenden Einsatz (☰-Menü); begleitend: höchstens alle 5 Minuten neu'],
];
const AI_CTX = ['real' => 'Scharfe Einsätze', 'ex' => 'Übungen', 'adm' => 'Verwaltung'];
const AI_MODES = ['off' => 'Aus', 'cmd' => 'Auf Abruf', 'comp' => 'Begleitend'];
const AI_RANK = ['off' => 0, 'cmd' => 1, 'comp' => 2];
const AI_PROV = ['openai' => 'OpenAI-kompatibel (Chat Completions)', 'anthropic' => 'Anthropic (Claude)'];
const AI_DEFAULT = ['on' => 0, 'provider' => 'openai', 'base_url' => '', 'model' => '', 'timeout' => 60, 'max_tokens' => 1500, 'anon' => 1, 'round_loc' => 1, 'day_limit' => 200, 'user_limit' => 30, 'matrix' => null];
const AI_SIT_S = 300; // Lagezusammenfassung: so lange gilt die letzte Antwort (Companion)
// Einstellung ai (ohne Schlüssel, der steht getrennt in ai_key); matrix {Benutzerrolle: {Funktion: {Art: Modus}}}, ohne Einstellung: Systemadministrator auf Abruf überall ausser Lage im scharfen Einsatz
function aiCfg() { $c = json_decode((string)setting('ai', ''), true); $c = (is_array($c) ? $c : []) + AI_DEFAULT;
  if (!is_array($c['matrix'])) { global $db; $c['matrix'] = [];
    foreach ($db->query("SELECT id FROM uroles WHERE sys='admin'")->fetchAll(PDO::FETCH_COLUMN) as $r) foreach (AI_FN as $f => $x) foreach ($x[1] as $k) $c['matrix'][(string)$r][$f][$k] = $f === 'sit' && $k === 'real' ? 'off' : 'cmd'; }
  return $c; }
function aiKey() { return (string)setting('ai_key', ''); }
function aiReady() { $c = aiCfg(); return (int)$c['on'] && aiKey() !== '' && trim((string)$c['model']) !== '' && ($c['provider'] === 'anthropic' || trim((string)$c['base_url']) !== ''); }
// höchster erlaubter Modus über alle Benutzerrollen der Person (wie die Rechte)
function aiMax($fn, $ctx) { global $me; if (!aiReady() || !isset(AI_FN[$fn]) || !in_array($ctx, AI_FN[$fn][1], true)) return 'off'; $m = aiCfg()['matrix']; $r = 0;
  foreach ($me['uroles'] as $u) $r = max($r, AI_RANK[$m[(string)$u['id']][$fn][$ctx] ?? 'off'] ?? 0);
  if (!AI_FN[$fn][2]) $r = min($r, 1); return array_search($r, AI_RANK, true); }
// gewählter Modus der Person (users.ai_modes, fehlt = höchster erlaubter), höchstens der erlaubte
function aiMode($fn, $ctx) { global $me; $mx = aiMax($fn, $ctx); $w = $me['ai_modes'][$fn] ?? null; return isset(AI_RANK[$w]) && AI_RANK[$w] < AI_RANK[$mx] ? $w : $mx; }
// für me: je Funktion die Modi je Art, den höchsten erlaubten (Profil) und die Wahl; null = für diese Person gibt es keinen AI-Assistenten
function aiMe() { global $me; $r = [];
  foreach (AI_FN as $f => $x) { $mx = 0; $m = []; foreach ($x[1] as $k) { $m[$k] = aiMode($f, $k); $mx = max($mx, AI_RANK[aiMax($f, $k)]); }
    if ($mx) $r[$f] = ['name' => $x[0], 'descr' => $x[3], 'modes' => $m, 'max' => array_search($mx, AI_RANK, true), 'choice' => $me['ai_modes'][$f] ?? null]; }
  return $r ?: null; }
function aiCtxOf($inc) { return (int)$inc['is_exercise'] ? 'ex' : 'real'; }
function aiT($ts) { return $ts === null ? '–' : (new DateTime('@' . (int)$ts))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i'); }
function aiHm($ts) { return $ts === null ? '–' : (new DateTime('@' . (int)$ts))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('H:i'); }
function aiDur($s) { $s = max(0, (int)$s); return $s >= 3600 ? floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min' : floor($s / 60) . ' min'; }
// Standorte gerundet senden: Koordinaten auf 3 Nachkommastellen (ca. 100 m)
function aiRound($t) { return preg_replace('/(?<![\d.])(\d{1,3}\.\d{3})\d+/', '$1', $t); }
// Einsatz als Text für die KI; c = anonCtx (Bezeichnungen statt Namen) oder null
function aiIncText($inc, $c, $withLog = true) { $d = aarDoc($inc, $c, (bool)$c, false, false, null); $i = $d['inc']; $L = [];
  $L[] = ($i['is_exercise'] ? 'Übung' : 'Scharfer Einsatz') . ($i['plan'] ? ' (vorbereitete Übung «' . ($c ? anonText($i['plan'], $c) : $i['plan']) . '»)' : '') . ', Alarm ' . aiT($i['alarm_at']) . ($i['ended_at'] ? ', Ende ' . aiT($i['ended_at']) . ', Dauer ' . aiDur($i['ended_at'] - $i['alarm_at']) : ', läuft seit ' . aiDur(time() - $i['alarm_at']));
  $L[] = 'Alarmmeldung: ' . ($i['message'] ?: '–');
  if ($i['creator']) $L[] = 'Ausgelöst von: ' . $i['creator'];
  if ($i['flow_ms'] !== null) $L[] = 'Fliessgeschwindigkeit: ' . round($i['flow_ms'] * 3.6, 1) . ' km/h';
  if ($i['lat'] !== null) $L[] = 'PLS (Point last seen) des Einsatzes: ' . sprintf('%.5F, %.5F', $i['lat'], $i['lon']);
  if ($i['first_accept']) $L[] = 'Erste Annahme: ' . aiDur($i['first_accept'] - $i['alarm_at']) . ' nach dem Alarm';
  if ($i['first_area']) $L[] = 'Suchgebiet erstmals erreicht: ' . aiDur($i['first_area'] - $i['alarm_at']) . ' nach dem Alarm';
  $L[] = ''; $L[] = 'Einsatzkräfte (' . count($d['people']) . '):';
  foreach ($d['people'] as $p) $L[] = '- ' . $p['name'] . ($p['mode'] ? ', ' . $p['mode'] : '') . ($p['role'] ? ', Spezialrolle ' . $p['role'] : '') . ($p['joined'] ? ', dabei ab ' . aiHm($p['joined']) : '') . ($p['left_at'] ? ', ausgetreten ' . aiHm($p['left_at']) : '');
  $res = array_filter($d['rescuees'], fn($r) => !$r['removed']);
  if ($res) { $L[] = ''; $L[] = 'Rettlinge:'; foreach ($res as $r) $L[] = '- ' . $r['name'] . ($r['descr'] ? ': ' . $r['descr'] : '') . ($r['loc_at'] ? ', Fundort gemeldet ' . aiHm($r['loc_at']) : ', vermisst') . ($r['rescued_at'] ? ', gerettet ' . aiHm($r['rescued_at']) : ''); }
  $ext = array_filter($d['externals'], fn($r) => !$r['removed']);
  if ($ext) { $L[] = ''; $L[] = 'Externe Einsatzkräfte:'; foreach ($ext as $r) $L[] = '- ' . $r['name'] . ($r['role'] ? ' (' . $r['role'] . ')' : '') . ($r['descr'] ? ': ' . $r['descr'] : ''); }
  if ($withLog) { $L[] = ''; $L[] = 'Einsatzlog (chronologisch, Zeit Person: Eintrag):'; $n = 0;
    foreach ($d['log'] as $r) { if (++$n > 600) { $L[] = '… (weitere Einträge gekürzt)'; break; } $L[] = aiHm($r['ts']) . ' ' . ($r['name'] ?: 'System') . ': ' . mb_substr((string)$r['text'], 0, 500); } }
  return [implode("\n", $L), $d]; }
const AI_SYS = 'Du unterstützt eine Einsatzgruppe der Wasserrettung (Fliessgewässer, Schweiz). Schreibe auf Deutsch in Schweizer Rechtschreibung (immer «ss», nie «ß»), sachlich und knapp. '
  . 'Stütze dich nur auf die gelieferten Daten und erfinde keine Fakten, Zeiten, Namen oder Zahlen; fehlt etwas, schreibe «(bitte ergänzen)». '
  . 'Bezeichnungen wie «Einsatzkraft 3», «Person 1», «Rettling 2» oder «Externe Einsatzkraft 1» sind Pseudonyme: übernimm sie unverändert und versuche nie, Personen zu erraten.';
// Anfrage beim Anbieter: [Text, Tokens, Modell]; Fehler mit fail (502)
function aiCall($sys, $user, $maxTok = null) { $c = aiCfg(); $key = aiKey(); if ($key === '') fail('Für den AI-Assistenten ist kein API-Schlüssel hinterlegt', 503);
  if (!function_exists('curl_init')) fail('Auf dem Server fehlt curl', 503);
  $mt = (int)($maxTok ?? $c['max_tokens']); $model = trim((string)$c['model']); @set_time_limit((int)$c['timeout'] + 20);
  if ($c['provider'] === 'anthropic') { $url = rtrim(trim((string)$c['base_url']) ?: 'https://api.anthropic.com', '/') . '/v1/messages';
    $h = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    $body = ['model' => $model, 'max_tokens' => $mt, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $user]]]; }
  else { $url = rtrim(trim((string)$c['base_url']), '/') . '/chat/completions'; $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    $body = ['model' => $model, 'max_tokens' => $mt, 'temperature' => 0.3, 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]]; }
  $k = curl_init($url); curl_setopt_array($k, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_TIMEOUT => (int)$c['timeout'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']);
  $r = curl_exec($k); $code = curl_getinfo($k, CURLINFO_HTTP_CODE); $err = $r === false ? (curl_errno($k) === CURLE_OPERATION_TIMEDOUT ? 'Zeitüberschreitung' : curl_error($k)) : ''; curl_close($k);
  if ($r === false) throw new AiErr('Keine Antwort vom AI-Anbieter: ' . $err);
  $j = json_decode($r, true);
  if ($code !== 200) { $m = is_array($j) ? ($j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : null) ?? $j['message'] ?? null) : null; throw new AiErr('AI-Anbieter (HTTP ' . $code . '): ' . mb_substr((string)($m ?? $r), 0, 300)); }
  if ($c['provider'] === 'anthropic') { $t = implode('', array_map(fn($x) => $x['text'] ?? '', array_filter((array)($j['content'] ?? []), fn($x) => ($x['type'] ?? '') === 'text'))); $tok = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['output_tokens'] ?? 0); }
  else { $t = (string)($j['choices'][0]['message']['content'] ?? ''); $tok = (int)($j['usage']['total_tokens'] ?? 0); }
  if (trim($t) === '') throw new AiErr('Der AI-Anbieter lieferte keine Antwort');
  return [trim($t), $tok, (string)($j['model'] ?? $model)]; }
class AiErr extends Exception {}
function aiUse($fn, $incId, $tok, $ok, $err = null) { global $db, $now, $uid; $db->prepare("INSERT INTO ai_usage(ts,user_id,fn,incident_id,tokens,ok,err) VALUES(?,?,?,?,?,?,?)")->execute([$now, $uid, $fn, $incId, (int)$tok, $ok ? 1 : 0, $err === null ? null : mb_substr($err, 0, 300)]); }
// Kostenbremse: Abfragen der letzten 24 Stunden gesamt und je Person (0 = unbegrenzt)
function aiLimit() { global $db, $now, $uid; $c = aiCfg();
  $q = fn($w, $a) => (int)(function () use ($db, $w, $a) { $s = $db->prepare("SELECT COUNT(*) FROM ai_usage WHERE ts>? $w"); $s->execute($a); $v = $s->fetchColumn(); $s->closeCursor(); return $v; })();
  if ((int)$c['day_limit'] && $q('', [$now - 86400]) >= (int)$c['day_limit']) fail('Tageslimit des AI-Assistenten erreicht (' . (int)$c['day_limit'] . ' Abfragen in 24 Stunden)', 429);
  if ((int)$c['user_limit'] && $q('AND user_id=?', [$now - 86400, $uid]) >= (int)$c['user_limit']) fail('Dein Tageslimit für den AI-Assistenten ist erreicht (' . (int)$c['user_limit'] . ' Abfragen in 24 Stunden)', 429); }
// Pseudonyme der Antwort zurück in die Namen, die die Person sehen darf: c = Kontext der Anfrage, cv = anonCtx der Ansicht oder null (Namen)
function aiDeanon($t, $c, $cv, $id) { global $db; if (!$c) return $t; $map = [];
  $nm = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach ($c['u'] as $u => $l) $map[$l] = $cv ? ($cv['u'][$u] ?? $l) : ($nm[$u] ?? $l);
  foreach (['r' => 'rescuees', 'e' => 'externals'] as $k => $tb) { $s = $db->prepare("SELECT id,name FROM $tb WHERE incident_id=?"); $s->execute([$id]); $n = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($c[$k] as $x => $l) $map[$l] = $map[$l] ?? ($cv ? ($cv[$k][$x] ?? $l) : (trim((string)($n[$x] ?? '')) !== '' ? $n[$x] : $l)); }
  $map = array_filter($map, fn($v, $k) => $v !== $k, ARRAY_FILTER_USE_BOTH); if (!$map) return $t;
  $ks = array_keys($map); usort($ks, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
  return preg_replace_callback('/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $ks)) . ')(?![\p{L}\p{N}])/u', fn($m) => $map[$m[0]], $t) ?? $t; }
function aiJson($t) { if (preg_match('/\{.*\}/s', $t, $m)) { $j = json_decode($m[0], true); if (is_array($j)) return $j; } return null; }
// Eingaben der Verwaltung prüfen (ai/cfg/save)
function aiIn($in) { global $db; $o = aiCfg(); $c = [];
  $c['on'] = (int)!empty($in['on'] ?? $o['on']);
  $c['provider'] = (string)($in['provider'] ?? $o['provider']); if (!isset(AI_PROV[$c['provider']])) fail('Unbekannter Anbieter');
  $u = trim((string)($in['base_url'] ?? $o['base_url']));
  if ($u !== '' && (strlen($u) > 300 || !filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^(https://[^/?\#@\s]+|http://(localhost|127\.0\.0\.1)(:\d+)?)(/|$)#i', $u))) fail('Ungültige Basis-URL (nur https://…)');
  if ($u === '' && $c['provider'] === 'openai' && $c['on']) fail('Für OpenAI-kompatible Anbieter braucht es eine Basis-URL');
  $c['base_url'] = $u;
  $c['model'] = mb_substr(trim((string)($in['model'] ?? $o['model'])), 0, 120); if ($c['on'] && $c['model'] === '') fail('Bitte ein Modell angeben');
  foreach (['timeout' => [5, 300], 'max_tokens' => [100, 8000], 'day_limit' => [0, 100000], 'user_limit' => [0, 10000]] as $k => [$a, $b]) {
    $v = $in[$k] ?? $o[$k]; if (!is_numeric($v) || (int)$v < $a || (int)$v > $b) fail("Ungültiger Wert für $k ($a bis $b)"); $c[$k] = (int)$v; }
  $c['anon'] = (int)!empty($in['anon'] ?? $o['anon']); $c['round_loc'] = (int)!empty($in['round_loc'] ?? $o['round_loc']);
  $ids = array_map('strval', $db->query("SELECT id FROM uroles")->fetchAll(PDO::FETCH_COLUMN)); $m = [];
  foreach ((array)($in['matrix'] ?? $o['matrix']) as $r => $fs) { if (!in_array((string)$r, $ids, true) || !is_array($fs)) continue;
    foreach ($fs as $f => $ks) { if (!isset(AI_FN[$f]) || !is_array($ks)) continue;
      foreach ($ks as $k => $v) { if (!in_array($k, AI_FN[$f][1], true) || !isset(AI_MODES[$v])) continue; if ($v === 'comp' && !AI_FN[$f][2]) $v = 'cmd'; $m[(string)$r][$f][$k] = $v; } } }
  $c['matrix'] = (object)$m; return $c; }
// Kontext und Auftrag je Funktion; prüft die Rechte wie die zugehörige Ansicht. Rückgabe: [Benutzer-Prompt, Art, Einsatz-ID, c (Anfrage), cv (Ansicht), Zusatz]
function aiTask($fn, $in) { global $db, $uid, $now; $anon = (int)aiCfg()['anon']; $q = trim(mb_substr((string)($in['q'] ?? ''), 0, 1000));
  $wish = $q !== '' ? "\n\nZusätzlicher Wunsch der Person: $q" : '';
  if ($fn === 'aar') { $inc = incById($in['id'] ?? 0); aarEditable($inc); $id = (int)$inc['id'];
    $forced = (int)$inc['log_anon'] || $inc['rp_anon'] !== null || (int)$inc['view_anon']; $c = $anon || $forced ? anonCtx($id) : null; $cv = $forced ? anonCtx($id) : null;
    [$txt, $d] = aiIncText($inc, $c); $data = (array)$d['data'];
    $fs = array_values(array_filter(aarCfg()['fields'], fn($f) => in_array($f['type'], ['text', 'area'], true)));
    $only = isset($in['field']) ? (string)$in['field'] : null; if ($only !== null) $fs = array_values(array_filter($fs, fn($f) => $f['id'] === $only));
    elseif (empty($in['all'])) $fs = array_values(array_filter($fs, fn($f) => trim((string)($data[$f['id']] ?? '')) === ''));
    if (!$fs) fail($only !== null ? 'Für dieses Feld gibt es keinen Vorschlag' : 'Alle Textfelder sind bereits ausgefüllt');
    $known = []; foreach (aarCfg()['fields'] as $f) if (in_array($f['type'], ['head', 'img'], true) || trim((string)($data[$f['id']] ?? '')) === '') continue; else $known[] = '- ' . $f['label'] . ': ' . mb_substr(is_scalar($data[$f['id']]) ? (string)$data[$f['id']] : json_encode($data[$f['id']], JSON_UNESCAPED_UNICODE), 0, 1500);
    $ask = implode("\n", array_map(fn($f) => '- ' . $f['id'] . ' (' . ($f['type'] === 'area' ? 'mehrzeilig, einige Sätze oder Stichpunkte' : 'einzeilig, kurz') . '): ' . $f['label'] . ($f['hint'] ? ' – Hinweis: ' . $f['hint'] : '') . (trim((string)($data[$f['id']] ?? '')) !== '' ? "\n  Bisheriger Text (überarbeiten, Inhalt beibehalten): " . mb_substr((string)$data[$f['id']], 0, 3000) : ''), $fs));
    $p = "Erstelle Entwürfe für Felder eines After Action Review (Nachbesprechung) zu diesem Einsatz.\n\n=== Einsatzdaten ===\n$txt\n\n" . ($known ? "=== Bereits erfasste Angaben ===\n" . implode("\n", $known) . "\n\n" : '')
      . "=== Gesuchte Felder (ID, Art: Bezeichnung) ===\n$ask\n\nAntworte ausschliesslich mit einem JSON-Objekt {\"<Feld-ID>\": \"<Text>\"} für genau diese Felder, ohne weitere Erklärung. Was sich aus den Daten nicht ableiten lässt (z. B. Bewertungen, Gefühle, Lehren), formuliere als Frage zur Nachbesprechung oder «(bitte ergänzen)».$wish";
    return [$p, aiCtxOf($inc), $id, $c, $cv, ['fields' => array_column($fs, 'id'), 'key' => $only ?? (empty($in['all']) ? 'empty' : 'all')]]; }
  if ($fn === 'log') { $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $v = incView($id); if ($v && !myBtns()['log']) fail('Das Einsatzlog ist für Einsatzkräfte ausgeschaltet', 403);
    $va = $v === 2 || (int)$inc['view_anon'] || ($v && anonAny($inc)); $self = $v ? $uid : null; $c = $anon || $va ? anonCtx($id) : null; $cv = $va ? anonCtx($id, $self) : null;
    [$txt] = aiIncText($inc, $c);
    $p = "Fasse diesen Einsatz anhand der Daten und des Einsatzlogs zusammen. Gliederung mit kurzen Zwischentiteln: Überblick (2–3 Sätze), Ablauf mit den wichtigsten Zeitpunkten, Kräfte und Rettlinge, Auffälligkeiten (z. B. lange Wartezeiten, offene Punkte). Höchstens 300 Wörter, keine Bewertung von Personen.\n\n$txt$wish";
    return [$p, aiCtxOf($inc), $id, $c, $cv, ['key' => $v ? "v$v:$self" : 'adm']]; }
  if ($fn === 'sit') { $inc = activeIncident(); if (!$inc || (int)$inc['id'] !== (int)($in['id'] ?? 0)) fail('Der Einsatz läuft nicht mehr', 409); $id = (int)$inc['id'];
    $pp = part($id); if (!(($pp && $pp['mode'] !== 'declined') || canManage($inc) || exLead($inc))) fail('Die Lagezusammenfassung gibt es nur für Teilnehmende', 403);
    $c = $anon ? anonCtx($id) : null; [$txt, $d] = aiIncText($inc, $c, false);
    $s = $db->prepare("SELECT p.user_id,p.lat,p.lon,p.loc_at,p.left_at,p.mode FROM participants p WHERE p.incident_id=? AND p.mode<>'declined'"); $s->execute([$id]); $loc = [];
    foreach ($s->fetchAll() as $r) if ($r['lat'] !== null && $r['left_at'] === null) $loc[] = '- ' . ($c ? ($c['u'][(int)$r['user_id']] ?? 'Einsatzkraft') : ($db->query('SELECT name FROM users WHERE id=' . (int)$r['user_id'])->fetchColumn() ?: '?')) . ': ' . sprintf('%.5F, %.5F', $r['lat'], $r['lon']) . ($r['loc_at'] ? ' (Stand ' . aiHm($r['loc_at']) . ')' : '');
    $src = array_map(fn($x) => sprintf('%.5F, %.5F', $x['lat'], $x['lon']) . (isset($x['at']) ? ' seit ' . aiHm($x['at']) : ''), plsSources($inc, incRes($inc)));
    $log = array_slice($d['log'], -80); $lt = implode("\n", array_map(fn($r) => aiHm($r['ts']) . ' ' . ($r['name'] ?: 'System') . ': ' . mb_substr((string)$r['text'], 0, 300), $log));
    $p = "Erstelle eine kurze Lagezusammenfassung für den laufenden Einsatz (jetzt " . aiHm($now) . "). Höchstens 8 kurze Punkte: Lage und Auftrag, Suchgebiet/PLS, eingesetzte Kräfte und Positionen, Rettlinge, offene Punkte und nächste sinnvolle Schritte (nur als Hinweis, keine Befehle).\n\n$txt\n\nPLS fürs Suchgebiet:\n" . ($src ? '- ' . implode("\n- ", $src) : '–') . "\n\nStandorte der Einsatzkräfte:\n" . ($loc ? implode("\n", $loc) : '–') . "\n\nLetzte Log-Einträge und Nachrichten:\n" . ($lt ?: '–') . $wish;
    return [$p, aiCtxOf($inc), $id, $c, null, ['key' => 'u']]; }
  if ($fn === 'plan') { plannerOnly(); $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $pl = $s->fetch(); $s->closeCursor(); if (!$pl) fail('Übung nicht gefunden', 404);
    $roles = $db->query("SELECT name FROM roles WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $el = array_map(fn($j) => '- ' . (EX_KINDS[$j['kind']] ?? $j['kind']) . ': ' . $j['label'] . ($j['name'] ? ', Name ' . $j['name'] : '') . ($j['descr'] ? ', ' . $j['descr'] : '') . ($j['lat'] !== null ? sprintf(', Standort %.5F, %.5F', $j['lat'], $j['lon']) : '') . ($j['auto'] !== null ? ', automatisch ' . (int)$j['auto'] . ' min nach dem Start' : ', Freigabe von Hand') . ((int)$j['ago'] ? ', PLS ' . (int)$j['ago'] . ' min vor der Freigabe' : '') . ($j['msg'] ? ', Meldung: ' . $j['msg'] : ''), exInjects((int)$pl['id']));
    $p = "Hilf bei der Vorbereitung einer Übung. Mache konkrete Vorschläge für weitere Elemente (Arten: " . implode(', ', EX_KINDS) . "), je mit Bezeichnung, ungefährem Ort relativ zu den bestehenden Elementen, Zeitpunkt (Minuten nach dem Start) und Meldung an die Teilnehmenden; dazu Hinweise zum Ablauf und zu Lernzielen. Keine erfundenen Ortsnamen, Koordinaten nur relativ (z. B. «ca. 500 m flussabwärts»). Höchstens 300 Wörter.\n\n"
      . "Titel: {$pl['title']}\nAufgebotsmeldung: " . ($pl['message'] ?: '–') . "\nSpezialrollen: " . (implode(', ', $roles) ?: '–') . "\n\nBisherige Elemente:\n" . ($el ? implode("\n", $el) : '– (noch keine)') . $wish;
    return [$p, 'ex', null, null, null, ['key' => 'p' . (int)$pl['id']]]; }
  if ($fn === 'stats') { need('stats'); $rows = $db->query("SELECT i.id,i.alarm_at,i.ended_at,i.is_exercise,(SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') acc,(SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') fa,(SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') far,(SELECT COUNT(*) FROM rescuees r WHERE r.incident_id=i.id AND r.removed IS NULL) res,(SELECT COUNT(*) FROM rescuees r WHERE r.incident_id=i.id AND r.removed IS NULL AND r.rescued_at IS NOT NULL) rsd FROM incidents i WHERE i.ended_at IS NOT NULL ORDER BY i.id DESC LIMIT 200")->fetchAll();
    if (!$rows) fail('Es gibt noch keine beendeten Einsätze');
    $L = array_map(fn($r) => '- ' . ((int)$r['is_exercise'] ? 'Übung' : 'Einsatz') . ' ' . aiT($r['alarm_at']) . ', Dauer ' . aiDur($r['ended_at'] - $r['alarm_at']) . ', ' . (int)$r['acc'] . ' Einsatzkräfte' . ($r['fa'] ? ', erste Annahme nach ' . aiDur($r['fa'] - $r['alarm_at']) : '') . ($r['far'] ? ', Suchgebiet nach ' . aiDur($r['far'] - $r['alarm_at']) : '') . ((int)$r['res'] ? ', Rettlinge ' . (int)$r['res'] . ' (gerettet ' . (int)$r['rsd'] . ')' : ''), $rows);
    $p = "Werte diese Einsatzstatistik in Worten aus (ohne Personen): Anzahl und Entwicklung scharfer Einsätze und Übungen, Reaktionszeiten (Alarm bis erste Annahme, bis Suchgebiet), Anzahl Einsatzkräfte, Auffälligkeiten und Anregungen. Scharfe Einsätze und Übungen getrennt betrachten. Höchstens 300 Wörter.\n\nBeendete Einsätze (neueste zuerst, heute ist " . aiT($now) . "):\n" . implode("\n", $L) . $wish;
    return [$p, 'adm', null, null, null, ['key' => 's']]; }
  fail('Unbekannte Funktion'); }
// Einrückoption ohne Standortfreigabe: Standortmeldungen werden nicht gespeichert (serverseitig erzwungen)
if ($route === 'incident/location' && ($inc = activeIncident()) && ($p = part($inc['id'])) && !modeShares($p['mode'])) out(['ok' => true, 'shared' => false]);

// ausgeschaltete Funktionen serverseitig sperren (FEATURE_ROUTES)
if (array_key_exists($route, FEATURE_ROUTES) && ($inc = activeIncident()) && !feature($inc, FEATURE_ROUTES[$route]))
  fail('Die Funktion «' . FEATURES[FEATURE_ROUTES[$route]] . '» ist für ' . ((int)$inc['is_exercise'] ? 'Übungen' : 'scharfe Einsätze') . ' ausgeschaltet', 403);

try { anonAuto(); } catch (Throwable $e) { error_log('anonAuto: ' . $e->getMessage()); } // fällige automatische Anonymisierung (Issue #87)
switch ($route) {
 case 'me': out(['me' => $me + ['coord_default' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'rec_default' => recDefault(), 'air_default' => airCfg()['on'] ? [airDef(0), airDef(1)] : null, 'nav_apps' => navApps(), 'nav_default' => navDefault(), 'features' => ['real' => features(0), 'ex' => features(1)], 'my_runs' => myRuns(), 'ai' => aiMe(), 'ai_anon' => (int)aiCfg()['anon']]]); // ai: AI-Assistent je Funktion (Issue #111); my_runs: Meine Einsätze (Issue #61); coord_sys null = Standard der Verwaltung; rd_speeds für das Werkzeug Flussdistanz; features je Art (real, ex) für «Einsatz starten» und die Flussdistanz ohne Einsatz; rec_default für «Einsatz starten»
 case 'roles': out(array_map(fn($r) => ints($r, ['id', 'is_lead', 'is_post', 'can_message']), $db->query("SELECT id,name,color,is_lead,is_post,can_message FROM roles WHERE active=1 ORDER BY id")->fetchAll()));
 case 'modes': // alle, auch deaktivierte (für die Anzeige bestehender Teilnahmen)
  out(array_map(fn($r) => ints($r, ['id', 'share_location', 'active', 'lead_rights', 'counts']), $db->query("SELECT id,code,name,share_location,active,lead_rights,counts FROM modes ORDER BY id")->fetchAll()));

 case 'overpass': // Gewässernetz für Suchgebiet und Flussdistanz (alle angemeldeten Personen): around [m, lat, lon] oder bbox [s, w, n, e]; Antwort wie Overpass
  overpassServe(overpassQuery($in));
 case 'overpass/stat': // Ergebnisse der direkten Abfragen des Geräts (Rückfall bei 502) für die Statistik: results [{url, ok, ms, err}], nur hinterlegte Server
  foreach (array_slice((array)($in['results'] ?? []), 0, 8) as $x) {
    if (!is_array($x) || !in_array($x['url'] ?? null, overpassActive(), true)) continue;
    opStat($x['url'], 'device', !empty($x['ok']), min(max(0, (float)($x['ms'] ?? 0)), 120000), empty($x['ok']) ? (mb_substr(trim((string)($x['err'] ?? '')), 0, 150) ?: 'Fehler') : null);
  }
  out(['ok' => true]);
 case 'overpass/stats': need('cfg_map'); // Statistik für die Verwaltung (Gewässerdaten)
  out(['since' => (int)setting('overpass_stats_since', '0') ?: null, 'rows' => array_map(fn($r) => ints($r, ['uses', 'ok', 'fails', 'ok_ms', 'last_ok', 'last_fail']), $db->query("SELECT * FROM overpass_stats ORDER BY url, src")->fetchAll())]);
 case 'overpass/stats/reset': need('cfg_map');
  $db->exec("DELETE FROM overpass_stats"); setSetting('overpass_stats_since', (string)time()); out(['ok' => true]);
 case 'overpass/put': // Ergebnis einer direkten Abfrage des Geräts (Rückfall bei 502), gleiche Parameter wie overpass plus elements
  overpassPut(overpassQuery($in), $in['elements'] ?? null);

 case 'incident/air': // Helikopter in der Relevanzzone des laufenden Einsatzes (Issue #73), Abruf alle paar Sekunden durch die Einsatz-App
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz', 404);
  if (!airAllowed($inc)) fail('Helikopter sind für dich nicht freigegeben', 403);
  out(airServe($inc));
 /* ---------- AI-Assistent (Issue #111) ---------- */
 case 'ai/cfg': need('cfg_ai'); $c = aiCfg();
  $u = fn($w, $a) => (function () use ($db, $w, $a) { $s = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(tokens),0) t, COALESCE(SUM(CASE WHEN ok=0 THEN 1 ELSE 0 END),0) e FROM ai_usage WHERE ts>? $w"); $s->execute($a); $r = $s->fetch(); $s->closeCursor(); return ints($r, ['n', 't', 'e']); })();
  $fu = $db->prepare("SELECT fn, COUNT(*) n, COALESCE(SUM(tokens),0) t FROM ai_usage WHERE ts>? GROUP BY fn"); $fu->execute([$now - 30 * 86400]);
  $last = $db->query("SELECT a.ts,a.fn,a.incident_id,a.tokens,a.ok,a.err,u.name FROM ai_usage a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
  out(['cfg' => $c, 'has_key' => aiKey() !== '' ? 1 : 0, 'ready' => aiReady() ? 1 : 0, 'fn' => array_map(fn($x) => ['name' => $x[0], 'ctx' => $x[1], 'comp' => $x[2], 'descr' => $x[3]], AI_FN),
   'ctx' => AI_CTX, 'modes' => AI_MODES, 'prov' => AI_PROV, 'curl' => function_exists('curl_init') ? 1 : 0,
   'uroles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sys' => $r['sys']], $db->query("SELECT id,name,sys FROM uroles ORDER BY id")->fetchAll()),
   'usage' => ['day' => $u('', [$now - 86400]), 'month' => $u('', [$now - 30 * 86400]), 'fn' => array_map(fn($r) => ints($r, ['n', 't']), $fu->fetchAll()), 'last' => array_map(fn($r) => ints($r, ['ts', 'incident_id', 'tokens', 'ok']), $last)]]);
 case 'ai/cfg/save': need('cfg_ai'); $c = aiIn($in);
  if (isset($in['key']) && trim((string)$in['key']) !== '') { $k = trim((string)$in['key']); if (strlen($k) > 500 || preg_match('/\s/', $k)) fail('Ungültiger API-Schlüssel'); setSetting('ai_key', $k); }
  if (!empty($in['key_clear'])) setSetting('ai_key', '');
  if ($c['on'] && aiKey() === '') fail('Bitte zuerst einen API-Schlüssel hinterlegen');
  setSetting('ai', json_encode($c, JSON_UNESCAPED_UNICODE)); out(['ok' => true, 'ready' => aiReady() ? 1 : 0]);
 case 'ai/test': need('cfg_ai'); $t0 = microtime(true);
  try { [$t, $tok, $model] = aiCall('Antworte kurz auf Deutsch.', 'Antworte nur mit: Verbindung funktioniert.', 50); }
  catch (AiErr $e) { aiUse('test', null, 0, 0, $e->getMessage()); fail($e->getMessage(), 502); }
  aiUse('test', null, $tok, 1); out(['text' => $t, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'tokens' => $tok, 'model' => $model]);
 case 'ai/ask': // fn, id, q (zusätzlicher Wunsch), auto (begleitend), fresh (Cache übergehen), field/all (aar); my=1 im GET für Meine Einsätze
  $fn = (string)($in['fn'] ?? ''); if (!isset(AI_FN[$fn])) fail('Unbekannte Funktion');
  if (!aiReady()) fail('Der AI-Assistent ist nicht eingerichtet', 503);
  [$prompt, $ctx, $incId, $c, $cv, $x] = aiTask($fn, $in);
  $mode = aiMode($fn, $ctx); $auto = !empty($in['auto']); $q = trim((string)($in['q'] ?? ''));
  if (AI_RANK[$mode] < ($auto ? 2 : 1)) fail($auto ? 'Der begleitende Modus ist für dich nicht eingeschaltet' : 'Diese Funktion des AI-Assistenten ist für dich nicht freigegeben', 403);
  $cfg = aiCfg(); if ((int)$cfg['round_loc']) $prompt = aiRound($prompt);
  $ck = sha1($fn . '|' . ($incId ?? '') . '|' . $x['key']); $hash = sha1($cfg['model'] . '|' . $prompt);
  $deliver = function ($t, $cached, $ts, $tok) use ($fn, $c, $cv, $incId, $x) { $t = aiDeanon($t, $c, $cv, $incId);
    $o = ['text' => $t, 'cached' => $cached ? 1 : 0, 'ts' => (int)$ts, 'tokens' => (int)$tok, 'fn' => $fn];
    if ($fn === 'aar') { $j = aiJson($t); if (!$j) fail('Die Antwort des AI-Anbieters liess sich nicht auswerten', 502);
      $o['fields'] = (object)array_filter(array_map(fn($v) => is_scalar($v) ? trim((string)$v) : null, array_intersect_key($j, array_flip($x['fields']))), fn($v) => $v !== null && $v !== ''); unset($o['text']); }
    out($o); };
  if ($q === '' && empty($in['fresh'])) { $s = $db->prepare("SELECT * FROM ai_cache WHERE k=?"); $s->execute([$ck]); $r = $s->fetch(); $s->closeCursor();
    if ($r && ($r['ctx_hash'] === $hash || $auto && ($fn === 'aar' || $fn === 'sit' && $now - (int)$r['ts'] < AI_SIT_S))) $deliver($r['text'], true, $r['ts'], $r['tokens']); }
  aiLimit();
  try { [$t, $tok, $model] = aiCall(AI_SYS, $prompt); }
  catch (AiErr $e) { aiUse($fn, $incId, 0, 0, $e->getMessage()); fail($e->getMessage(), 502); }
  aiUse($fn, $incId, $tok, 1);
  if ($q === '') { $db->prepare("DELETE FROM ai_cache WHERE k=?")->execute([$ck]); $db->prepare("INSERT INTO ai_cache(k,ts,ctx_hash,text,tokens,model) VALUES(?,?,?,?,?,?)")->execute([$ck, $now, $hash, $t, $tok, $model]); }
  $deliver($t, false, $now, $tok);

 case 'air/test': need('cfg_map'); // Verwaltung: eine Quelle direkt abfragen (ohne Cache), Umkreis 120 NM um die Mitte der Schweiz
  $k = (string)($in['src'] ?? ''); if (!isset(AIR_SOURCES[$k])) fail('Unbekannte Datenquelle');
  $c = airCfg(); $t = microtime(true); $r = airFetch([$k], 46.8, 8.2, 120 * 1852); $ms = (int)((microtime(true) - $t) * 1000);
  if (!$r) out(['ok' => false, 'ms' => $ms, 'status' => json_decode((string)@file_get_contents(__DIR__ . '/data/air/status.json'), true)[$k] ?? null]);
  $h = array_values(array_filter($r[1], fn($a) => $a['cat'] === 'A7' || airRescue($a, $c) !== null));
  out(['ok' => true, 'ms' => $ms, 'total' => count($r[1]), 'heli' => array_map(fn($a) => ['cs' => $a['cs'], 'hex' => $a['hex'], 'reg' => $a['reg'], 'type' => $a['type'], 'alt' => $a['gnd'] ? 0 : $a['alt'], 'rescue' => airRescue($a, $c), 'age' => $now - $a['at']], $h)]);

 case 'incident/start':
  need(empty($in['is_exercise']) ? 'inc_start' : 'ex_start'); // Issue #63
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  if (trim($in['message'] ?? '') === '') fail('Die Meldung ist Pflicht');
  $at = !empty($in['alarm_at']) ? (int)$in['alarm_at'] : $now;
  if ($at > $now + 60) fail('Der Alarmeingang darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Alarmeingang liegt mehr als 7 Tage zurück');
  // PLS optional (Issue #24): ohne PLS gibt es ein Suchgebiet erst mit dem PLS eines Rettlings; Zeitpunkt des PLS = Alarmeingang
  $pls = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? plsIn($in, $at) : [null, null];
  $ex = empty($in['is_exercise']) ? 0 : 1; $rec = empty($in['record']) || !features($ex)['rec'] ? 0 : 1;
  $air = airCfg()['on'] ? (isset($in['air']) ? (empty($in['air']) ? 0 : 1) : airDef($ex)) : null; // Helikopter: Häkchen beim Start, sonst Vorauswahl; Hauptschalter aus = offen lassen (NULL)
  $db->prepare("INSERT INTO incidents(message,lat,lon,alarm_at,created_by,is_exercise,air) VALUES(?,?,?,?,?,?,?)")->execute([$in['message'], $pls[0], $pls[1], $at, $uid, $ex, $air]);
  $nid = (int)$db->lastInsertId();
  if ($rec) setRec($nid, 1);
  logev($nid, $uid, 'start', 'hat den Einsatz gestartet (' . ($ex ? 'Übung' : 'scharfer Einsatz') . ($rec ? ', mit Aufzeichnung' : '') . ($air ? ', mit Helikoptern' : '') . ($pls[0] === null ? ', ohne PLS' : '') . '): ' . $in['message']);
  pushAlarm(['id' => $nid, 'is_exercise' => $ex, 'message' => $in['message']], [$uid]);
  out(['id' => $nid]);

 case 'incident/state': // Polling-Endpunkt: ?since=<letzte Nachrichten-ID>
  $inc = activeIncident();
  if ($inc && $inc['plan_id'] !== null) { exAuto($inc); $inc = activeIncident(); } // fällige automatische Freigaben
  if (!$inc) {
    $last = $db->query("SELECT id,ended_at FROM incidents ORDER BY id DESC LIMIT 1")->fetch();
    // vorbereitete Übungen, die diese Person starten darf: nur die Übungsleitung der Übung (sonst fehlten ihr nach dem Start die Funktionen der Übungsleitung)
    $xp = array_values(array_filter($db->query("SELECT id,title,leads,invite FROM ex_plans WHERE active=1 AND incident_id IS NULL ORDER BY title")->fetchAll(), fn($p) => in_array($uid, exLeads($p), true)));
    out(['incident' => null, 'last_ended' => $last ?: null, 'features' => ['real' => features(0), 'ex' => features(1)], 'ex_plans' => array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title'], 'invited' => $p['invite'] !== null ? 1 : 0], $xp)]); // features: Flussdistanz ohne Einsatz
  }
  $inc['id'] = (int)$inc['id']; $inc['plan_id'] = $inc['plan_id'] === null ? null : (int)$inc['plan_id']; $inc = floats($inc, ['lat', 'lon']); $inc['pls_at'] = plsAt($inc); $inc['alarm_at'] = (int)$inc['alarm_at']; $inc['created_by'] = (int)$inc['created_by']; $inc['is_exercise'] = (int)$inc['is_exercise']; $inc['record'] = (int)$inc['record']; $inc['recorded'] = (int)$inc['recorded'];
  if ($inc['flow_ms'] !== null) $inc['flow_ms'] = (float)$inc['flow_ms'];
  $id = $inc['id']; $since = (int)($_GET['since'] ?? 0); $F = features($inc['is_exercise']); // ausgeschaltete Funktionen liefern nichts
  $ps = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.lat,p.lon,p.loc_at,p.left_at,u.name,u.photo FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=?");
  $ps->execute([$id]);
  $isP = isActivePart($id);
  $msgs = []; $msgMarks = [];
  // Lesen: alle, die den Einsatz angenommen haben; mit Einstellung msg_read_all auch alle anderen angemeldeten Personen
  $xl = exLead($inc); // Übungsleitung (Issue #29)
  $canRead = $F['msg'] && ($isP || $xl || setting('msg_read_all', '0') === '1');
  if ($canRead) { // recipients = wer benachrichtigt wird (null = alle)
    $ms = $db->prepare("SELECT m.id,m.from_user,m.text,m.created,m.recipients,m.lat,m.lon,m.mark_lat,m.mark_lon,u.name from_name FROM messages m JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.id>? ORDER BY m.id");
    $ms->execute([$id, $since]);
    $msgs = array_map(fn($m) => floats(ints($m, ['id', 'from_user', 'created']), ['lat', 'lon', 'mark_lat', 'mark_lon']), $ms->fetchAll());
    // aktuelle Kartenmarkierungen separat (nicht inkrementell), damit entfernte Markierungen bei allen verschwinden
    $mm = $db->prepare("SELECT m.id,m.from_user,m.text,m.created,m.mark_lat lat,m.mark_lon lon,u.name from_name FROM messages m JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.mark=1 ORDER BY m.id");
    $mm->execute([$id]);
    $msgMarks = array_map(fn($m) => floats(ints($m, ['id', 'from_user', 'created']), ['lat', 'lon']), $mm->fetchAll());
  }
  $mk = $db->prepare("SELECT m.id,m.user_id,m.lat,m.lon,m.ts,m.manual,u.name FROM rescuee_marks m LEFT JOIN users u ON u.id=m.user_id WHERE m.incident_id=? ORDER BY m.id");
  $mk->execute([$id]);
  $marks = !$F['res'] ? [] : array_map(fn($m) => ints(array_merge($m, ['lat' => (float)$m['lat'], 'lon' => (float)$m['lon']]), ['id', 'user_id', 'ts', 'manual']), $mk->fetchAll());
  // Positionen und Bereiche der Einsatzleitung: für alle sichtbar, immer vollständig (Entfernen kommt bei allen an)
  $po = $db->prepare("SELECT p.id,p.kind,p.name,p.color,p.pts,p.created_by,p.created,u.name by_name FROM pois p LEFT JOIN users u ON u.id=p.created_by WHERE p.incident_id=? AND p.removed IS NULL ORDER BY p.id");
  $po->execute([$id]);
  $pois = !$F['poi'] ? [] : array_map(fn($p) => array_merge(ints($p, ['id', 'created_by', 'created']), ['pts' => json_decode($p['pts'], true) ?: []]), $po->fetchAll());
  // Rettlinge: für alle sichtbar, immer vollständig
  $rescuees = incRes($inc);
  $cf = canFlow($inc);
  // Öffentliche Ansicht: alle sehen, ob sie eingeschaltet ist (public); den geheimen Link (public_token) nur die Einsatzleitung
  $inc['public'] = $inc['public_token'] !== null ? 1 : 0; $inc['public_anon'] = (int)$inc['public_anon']; $inc['public_log'] = (int)$inc['public_log'];
  if (!canManage($inc)) unset($inc['public_token']);
  // Externe Einsatzkräfte für alle; Vorlagen nur für die Einsatzleitung (nur sie erfasst Externe)
  // Einsatz-Vorlagen: von Hand erfasste Externe dieses Einsatzes (auch entfernte), je Name die neuste, ohne Standort und ohne Namen der Verwaltungsvorlagen
  $ext = $F['ext'] ? incExternals($id) : []; $tpl = []; $itpl = [];
  if ($F['ext'] && canManage($inc)) {
    $tpl = array_map(fn($r) => floats(ints($r, ['id', 'role_id']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon FROM ext_templates WHERE active=1 ORDER BY name")->fetchAll());
    $seen = array_fill_keys(array_map(fn($t) => mb_strtolower($t['name']), $tpl), 1);
    $s = $db->prepare("SELECT name,descr,role_id,abbr,color FROM externals WHERE incident_id=? AND manual=1 ORDER BY id DESC"); $s->execute([$id]);
    foreach ($s->fetchAll() as $r) { $k = mb_strtolower($r['name']); if (isset($seen[$k])) continue; $seen[$k] = 1; $itpl[] = ints($r, ['role_id']); }
    usort($itpl, fn($x, $y) => strcasecmp($x['name'], $y['name']));
  }
  out(['incident' => $inc, 'pois' => $pois, 'rescuees' => $rescuees, 'externals' => $ext, 'ext_templates' => $tpl, 'ext_inc_templates' => $itpl, 'sources' => plsSources($inc, $rescuees),
       'participants' => array_map(function ($p) { foreach (['lat', 'lon'] as $k) if ($p[$k] !== null) $p[$k] = (float)$p[$k]; return ints($p, ['user_id', 'role_id', 'left_at', 'loc_at']); }, $ps->fetchAll()),
       'marks' => $marks, 'messages' => $msgs, 'msg_marks' => $msgMarks,'me' => part($id) ?: null, 'is_lead' => isLead($id), 'can_manage' => canManage($inc), 'can_flow' => $cf, 'can_message' => $F['msg'] && canMessage($inc), 'can_read_messages' => $canRead, 'critical' => critical($inc), 'alarm' => alarmed($inc), 'features' => $F,
       'air' => airAllowed($inc) ? 1 : 0, 'air_inc' => airCfg()['on'] ? (airInc($inc) ? 1 : 0) : null, 'ex_lead' => $xl, 'ex_title' => $inc['plan_id'] && ($xl || planner()) ? (exPlan($inc)['title'] ?? null) : null, 'ex_injects' => $inc['plan_id'] && ($xl || planner()) ? exInjects((int)$inc['plan_id']) : [],
       'log' => $cf ? logRows($id) : null, 'now' => $now]);

 case 'incident/join': // mode: code einer aktiven Einrückoption oder declined
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $mode = (string)($in['mode'] ?? '');
  $m = $mode === 'declined' ? null : modeRow($mode);
  if ($mode !== 'declined' && (!$m || !(int)$m['active'])) fail('Ungültige Auswahl');
  $prev = part($inc['id']);
  $db->prepare($my ? "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode), left_at=NULL"
                   : "INSERT INTO participants(incident_id,user_id,mode) VALUES(?,?,?) ON CONFLICT(incident_id,user_id) DO UPDATE SET mode=excluded.mode, left_at=NULL")->execute([$inc['id'], $uid, $mode]);
  // Option ohne Standortfreigabe (z. B. Koordinator KP Rück): bisherigen Standort von der Karte nehmen
  if ($m && !(int)$m['share_location']) $db->prepare("UPDATE participants SET lat=NULL, lon=NULL, loc_at=NULL WHERE incident_id=? AND user_id=?")->execute([$inc['id'], $uid]);
  if ($m && (int)$m['lead_rights']) $db->prepare("UPDATE participants SET role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$inc['id'], $uid]); // Leitungsrechte ohne Spezialrolle
  if (!$prev || $prev['mode'] !== $mode || $prev['left_at'] !== null) {
    if ($mode === 'declined') logev($inc['id'], $uid, 'decline', 'hat den Einsatz abgelehnt');
    else logev($inc['id'], $uid, 'accept', ($prev && $prev['left_at'] !== null ? 'ist wieder eingestiegen' : 'hat den Einsatz angenommen') . ' (' . $m['name'] . ')');
  }
  out(['ok' => true]);

 case 'incident/leave':
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']);
  $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $uid]);
  if ($p && $p['left_at'] === null && $p['mode'] !== 'declined') logev($inc['id'], $uid, 'leave', 'hat den Einsatz verlassen');
  out(['ok' => true]);

 case 'incident/role': // eigene Spezialrolle: role_id oder null
  $inc = activeIncident(); $p = $inc ? part($inc['id']) : null; if (!$p) fail('Nicht im Einsatz');
  if (leadMode($p['mode'])) fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
  $rid = !empty($in['role_id']) ? (int)$in['role_id'] : null;
  if ($rid !== ($p['role_id'] !== null ? (int)$p['role_id'] : null)) {
    $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $uid]);
    if ($rid) { $s = $db->prepare("SELECT name FROM roles WHERE id=?"); $s->execute([$rid]); logev($inc['id'], $uid, 'role', 'übernimmt die Rolle ' . ($s->fetch()['name'] ?? '?')); }
    else logev($inc['id'], $uid, 'role', 'legt die Spezialrolle ab');
  }
  out(['ok' => true]);

 case 'incident/role/set': // Einsatzleitung setzt die Spezialrolle einer Einsatzkraft: user_id, role_id oder null
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur die Einsatzleitung kann Spezialrollen anderer Personen ändern', 403);
  $t = (int)($in['user_id'] ?? 0); $p = part($inc['id'], $t);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') fail('Die Person ist nicht im Einsatz');
  if (leadMode($p['mode'])) fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
  $rid = !empty($in['role_id']) ? (int)$in['role_id'] : null; $rn = null;
  if ($rid) { $s = $db->prepare("SELECT name FROM roles WHERE id=? AND active=1"); $s->execute([$rid]); $rn = $s->fetch()['name'] ?? null; if ($rn === null) fail('Unbekannte Spezialrolle'); }
  if ($rid !== ($p['role_id'] !== null ? (int)$p['role_id'] : null)) {
    $db->prepare("UPDATE participants SET role_id=? WHERE incident_id=? AND user_id=?")->execute([$rid, $inc['id'], $t]);
    $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$t]); $who = $s->fetch()['name'] ?? '?';
    logev($inc['id'], $uid, 'role', $rid ? "teilt $who die Rolle $rn zu" : "entfernt die Spezialrolle von $who");
  }
  out(['ok' => true]);

 case 'incident/location': // water=1: Gerät liegt am Gewässer (z. B. Boot), manual=1: von Hand gesetzt; beides zählt nicht als Fahrt
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $p = part($inc['id']); if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') out(['ok' => true]);
  $la = (float)$in['lat']; $lo = (float)$in['lon'];
  $db->prepare("UPDATE participants SET lat=?,lon=?,loc_at=? WHERE incident_id=? AND user_id=?")->execute([$la, $lo, $now, $inc['id'], $uid]);
  if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,user_id,ts,lat,lon) VALUES(?,?,?,?,?)")->execute([$inc['id'], $uid, $now, $la, $lo]);
  // Fahrt erkennen: Start = letzte Position vor der ersten schnellen Meldung (log_*), fast_at = letzte schnelle Meldung
  $dt = $p['loc_at'] !== null ? $now - (int)$p['loc_at'] : 0;
  $fast = $dt >= 4 && empty($in['water']) && empty($in['manual']) && hav((float)$p['lat'], (float)$p['lon'], $la, $lo) / $dt >= VEHICLE_MS;
  $trip = fn($a, $b, $c, $f) => $db->prepare("UPDATE participants SET log_lat=?,log_lon=?,log_at=?,fast_at=? WHERE incident_id=? AND user_id=?")->execute([$a, $b, $c, $f, $inc['id'], $uid]);
  if ($p['fast_at'] === null) { if ($fast) $trip((float)$p['lat'], (float)$p['lon'], (int)$p['loc_at'], $now); }
  else {
    $end = !$fast && $now - (int)$p['fast_at'] >= STOP_S; $long = $fast && $now - (int)$p['log_at'] >= TRIP_LOG_S;
    if ($end || $long) {
      $d = hav((float)$p['log_lat'], (float)$p['log_lon'], $la, $lo);
      if ($d >= TRIP_MIN_M) {
        $t = max(1, ($end ? (int)$p['fast_at'] : $now) - (int)$p['log_at']); $a = placeName((float)$p['log_lat'], (float)$p['log_lon']); $b = placeName($la, $lo);
        logev($inc['id'], $uid, 'move', ($a === $b ? "fährt innerhalb von $a" : "fährt von $a " . ($end ? 'nach' : 'Richtung') . " $b")
          . ' (ca. ' . number_format($d / 1000, 1, '.', '') . ' km Luftlinie, Ø ' . round($d / $t * 3.6) . ' km/h' . ($end ? '' : ', unterwegs') . ')');
      }
      $end ? $trip(null, null, null, null) : $trip($la, $lo, $now, $now); // lange Fahrt: ab hier neuer Abschnitt
    } elseif ($fast) $db->prepare("UPDATE participants SET fast_at=? WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $uid]);
  }
  out(['ok' => true]);

 case 'incident/event': // vom Gerät erkannte Ereignisse, z. B. Suchgebiet erreicht
  $inc = activeIncident(); if (!$inc || !isActivePart($inc['id'])) fail('Nicht im Einsatz');
  if (($in['kind'] ?? '') === 'area') {
    $s = $db->prepare("SELECT COUNT(*) c FROM incident_log WHERE incident_id=? AND user_id=? AND kind='area'"); $s->execute([$inc['id'], $uid]);
    if (!$s->fetch()['c']) logev($inc['id'], $uid, 'area', 'hat das Suchgebiet erreicht');
  }
  out(['ok' => true]);

 case 'incident/flow': // Fliessgeschwindigkeit in m/s (Teilnehmende und Verwaltung)
  $inc = activeIncident(); if (!$inc || !canFlow($inc)) fail('Nur Personen, die am Einsatz teilnehmen, können die Fliessgeschwindigkeit setzen', 403);
  $v = (float)($in['flow_ms'] ?? 0); if ($v <= 0 || $v > 15) fail('Fliessgeschwindigkeit muss zwischen 0 und 15 m/s liegen');
  $db->prepare("UPDATE incidents SET flow_ms=? WHERE id=?")->execute([$v, $inc['id']]);
  logev($inc['id'], $uid, 'flow', 'setzt die Fliessgeschwindigkeit auf ' . $v . ' m/s');
  out(['ok' => true]);

 case 'incident/mark': // Position des Rettlings: alle Teilnehmenden (eigener Standort), manuell nur Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $man = !empty($in['manual']);
  if ($man ? !canManage($inc) : !isActivePart($inc['id'])) fail($man ? 'Nur die Einsatzleitung kann die Position manuell setzen' : 'Nur Personen, die den Einsatz angenommen haben, können markieren', 403);
  if (!isset($in['lat'], $in['lon'])) fail('Standort fehlt');
  $la = (float)$in['lat']; $lo = (float)$in['lon'];
  if (!$man) $db->prepare("DELETE FROM rescuee_marks WHERE incident_id=? AND user_id=? AND manual=0")->execute([$inc['id'], $uid]);
  $db->prepare("INSERT INTO rescuee_marks(incident_id,user_id,lat,lon,ts,manual) VALUES(?,?,?,?,?,?)")->execute([$inc['id'], $uid, $la, $lo, $now, $man ? 1 : 0]);
  logev($inc['id'], $uid, 'mark', $man ? 'setzt die Position des Rettlings manuell' : 'markiert die Position des Rettlings an eigenem Standort');
  out(['ok' => true]);

 case 'incident/mark/remove': // Einsatzleitung/Verwaltung alle, sonst nur eigene
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $s = $db->prepare("SELECT * FROM rescuee_marks WHERE id=? AND incident_id=?"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $m = $s->fetch();
  if (!$m) fail('Markierung nicht gefunden', 404);
  if (!canManage($inc) && (int)$m['user_id'] !== $uid) fail('Nur die Einsatzleitung kann fremde Markierungen entfernen', 403);
  $db->prepare("DELETE FROM rescuee_marks WHERE id=?")->execute([$m['id']]);
  logev($inc['id'], $uid, 'markdel', (int)$m['user_id'] === $uid ? 'entfernt die eigene Markierung des Rettlings' : 'entfernt eine Markierung des Rettlings');
  out(['ok' => true]);

 case 'incident/poi': // Position (kind=point, 1 Punkt) oder Bereich (kind=area, Polygon mit 3–200 Punkten) markieren: nur Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Markierungen und Bereiche setzen', 403);
  $kind = ($in['kind'] ?? '') === 'area' ? 'area' : 'point';
  $name = trim((string)($in['name'] ?? ''));
  if ($name === '') fail('Bezeichnung fehlt');
  $name = mb_substr($name, 0, 80);
  $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#ff9f1c';
  $pts = [];
  foreach ((array)($in['pts'] ?? []) as $q) {
    if (!is_array($q) || count($q) !== 2 || !is_numeric($q[0]) || !is_numeric($q[1]) || abs($q[0]) > 90 || abs($q[1]) > 180) fail('Ungültige Koordinaten');
    $pts[] = [round((float)$q[0], 7), round((float)$q[1], 7)];
  }
  if ($kind === 'point' ? count($pts) !== 1 : count($pts) < 3 || count($pts) > 200) fail($kind === 'point' ? 'Für eine Markierung ist genau ein Punkt nötig' : 'Ein Bereich braucht 3 bis 200 Punkte');
  $db->prepare("INSERT INTO pois(incident_id,kind,name,color,pts,created_by,created) VALUES(?,?,?,?,?,?,?)")->execute([$inc['id'], $kind, $name, $col, json_encode($pts), $uid, $now]);
  $pid = (int)$db->lastInsertId();
  if ($kind === 'point') $txt = sprintf('setzt die Markierung «%s» (%.5F, %.5F)', $name, $pts[0][0], $pts[0][1]);
  else { // Fläche grob über lokale Projektion (Shoelace), reicht für die Angabe im Log
    $k = 111320 * cos(deg2rad($pts[0][0])); $a = 0; $n = count($pts);
    for ($j = 0; $j < $n; $j++) { $p = $pts[$j]; $q = $pts[($j + 1) % $n]; $a += $p[1] * $k * $q[0] * 111320 - $q[1] * $k * $p[0] * 111320; }
    $txt = sprintf('markiert den Bereich «%s» (%d Punkte, ca. %s ha)', $name, $n, number_format(abs($a) / 2 / 10000, 1, '.', "'"));
  }
  logev($inc['id'], $uid, 'poi', $txt);
  out(['ok' => true, 'id' => $pid]);

 case 'incident/poi/remove': // Position oder Bereich entfernen: nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Markierungen und Bereiche entfernen', 403);
  $s = $db->prepare("SELECT * FROM pois WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $p = $s->fetch();
  if (!$p) fail('Markierung nicht gefunden', 404);
  $db->prepare("UPDATE pois SET removed=? WHERE id=?")->execute([$now, $p['id']]);
  logev($inc['id'], $uid, 'poidel', ($p['kind'] === 'area' ? 'entfernt den Bereich «' : 'entfernt die Markierung «') . $p['name'] . '»');
  out(['ok' => true]);

 // Rettlinge: erfassen, bearbeiten, Standort und «gerettet» markieren dürfen alle im Einsatz, entfernen nur die Einsatzleitung
 case 'incident/rescuee/save': // id fehlt = neu; name (leer = «Unbekannter Rettling»), descr; pls: {lat, lon, at} = eigener PLS, null = PLS des Einsatzes, fehlt = unverändert
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können Rettlinge erfassen', 403);
  $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 1000);
  $r = !empty($in['id']) ? rescuee($inc, $in['id']) : null;
  if ($name === '') $name = $r && strpos($r['name'], 'Unbekannter Rettling') === 0 ? $r['name'] : unknownName((int)$inc['id']);
  $hasPls = array_key_exists('pls', $in); $pls = $hasPls && $in['pls'] !== null ? plsIn($in['pls'], plsAt($inc)) : [null, null, null];
  $plsTxt = fn($n) => $pls[0] === null ? "übernimmt für «{$n}» den PLS des Einsatzes" : sprintf('setzt den PLS von «%s» (%.5F, %.5F, %s)', $n, $pls[0], $pls[1], hm($pls[2]));
  if ($r) {
    $db->prepare("UPDATE rescuees SET name=?, descr=? WHERE id=?")->execute([$name, $descr, $r['id']]);
    if ($r['name'] !== $name || (string)$r['descr'] !== $descr) logev($inc['id'], $uid, 'rescuee', ($r['name'] !== $name ? "ändert den Rettling «{$r['name']}» zu «{$name}»" : "ändert die Beschreibung von «{$name}»") . ($descr !== '' ? ": {$descr}" : ''));
    $old = [$r['pls_lat'] === null ? null : round((float)$r['pls_lat'], 7), $r['pls_lon'] === null ? null : round((float)$r['pls_lon'], 7), $r['pls_at'] === null ? null : (int)$r['pls_at']];
    if ($hasPls && $old !== $pls) {
      $db->prepare("UPDATE rescuees SET pls_lat=?, pls_lon=?, pls_at=? WHERE id=?")->execute([...$pls, $r['id']]);
      logev($inc['id'], $uid, 'rescuee', $plsTxt($name));
    }
    out(['ok' => true, 'id' => (int)$r['id']]);
  }
  $db->prepare("INSERT INTO rescuees(incident_id,name,descr,created_by,created,pls_lat,pls_lon,pls_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$inc['id'], $name, $descr, $uid, $now, ...$pls]);
  $rid = (int)$db->lastInsertId();
  logev($inc['id'], $uid, 'rescuee', "erfasst den Rettling «{$name}»" . ($descr !== '' ? ": {$descr}" : ''));
  if ($pls[0] !== null) logev($inc['id'], $uid, 'rescuee', $plsTxt($name));
  out(['ok' => true, 'id' => $rid]);

 case 'incident/rescuee/locate': // own=1: eigener geteilter Standort (serverseitig), clear=1: Fundort zurücksetzen (Issue #85), sonst lat/lon (auf der Karte gewählt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können den Standort markieren', 403);
  $r = rescuee($inc, $in['id'] ?? 0);
  if (!empty($in['clear'])) { // wieder vermisst (ohne Standort), zählt wieder fürs Suchgebiet; im Replay ab hier ohne Standort (track ohne lat/lon)
    if ($r['lat'] === null) out(['ok' => true]);
    $db->prepare("UPDATE rescuees SET lat=NULL, lon=NULL, loc_at=NULL, loc_by=NULL WHERE id=?")->execute([$r['id']]);
    if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,rescuee_id,ts,lat,lon) VALUES(?,?,?,NULL,NULL)")->execute([$inc['id'], $r['id'], $now]);
    logev($inc['id'], $uid, 'resmark', "setzt den Fundort von «{$r['name']}» zurück");
    out(['ok' => true]);
  }
  if (!empty($in['own'])) {
    $p = part($inc['id']);
    if (!$p || $p['lat'] === null) fail('Dein Standort ist nicht bekannt: Standortfreigabe erlauben oder Position auf der Karte wählen');
    [$la, $lo] = [(float)$p['lat'], (float)$p['lon']];
  } else {
    if (!isset($in['lat'], $in['lon']) || !is_numeric($in['lat']) || !is_numeric($in['lon']) || abs($in['lat']) > 90 || abs($in['lon']) > 180) fail('Ungültige Koordinaten');
    [$la, $lo] = [round((float)$in['lat'], 7), round((float)$in['lon'], 7)];
  }
  $db->prepare("UPDATE rescuees SET lat=?, lon=?, loc_at=?, loc_by=? WHERE id=?")->execute([$la, $lo, $now, $uid, $r['id']]);
  if ((int)$inc['record'] === 1) $db->prepare("INSERT INTO track(incident_id,rescuee_id,ts,lat,lon) VALUES(?,?,?,?,?)")->execute([$inc['id'], $r['id'], $now, $la, $lo]); // frühere Standorte fürs Replay
  logev($inc['id'], $uid, 'resmark', sprintf('markiert den Standort von «%s» %s (%.5F, %.5F)', $r['name'], !empty($in['own']) ? 'am eigenen Standort' : 'auf der Karte', $la, $lo));
  out(['ok' => true]);

 case 'incident/rescuee/rescued': // rescued=1 gerettet, 0 = zurücknehmen
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können Rettlinge als gerettet markieren', 403);
  $r = rescuee($inc, $in['id'] ?? 0); $on = !empty($in['rescued']);
  if ($on === ($r['rescued_at'] !== null)) out(['ok' => true]); // unverändert, z. B. gleichzeitig von zwei Personen
  $db->prepare("UPDATE rescuees SET rescued_at=?, rescued_by=? WHERE id=?")->execute($on ? [$now, $uid, $r['id']] : [null, null, $r['id']]);
  logev($inc['id'], $uid, 'rescued', $on ? "meldet «{$r['name']}» als gerettet" : "nimmt «gerettet» bei «{$r['name']}» zurück");
  out(['ok' => true]);

 case 'incident/rescuee/remove': // nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Rettlinge entfernen', 403);
  $r = rescuee($inc, $in['id'] ?? 0);
  $db->prepare("UPDATE rescuees SET removed=? WHERE id=?")->execute([$now, $r['id']]);
  logev($inc['id'], $uid, 'rescuee', "entfernt den Rettling «{$r['name']}»");
  out(['ok' => true]);

 // Externe Einsatzkräfte ohne App-Konto mit festem Standort (Issue #39): nur Einsatzleitung
 case 'incident/external/save': // id fehlt = neu (lat, lon Pflicht); name, descr, role_id; lat/lon bei bestehenden = verschieben
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann externe Einsatzkräfte erfassen', 403);
  $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500);
  if ($name === '') fail('Name fehlt');
  $rid = roleIn($in['role_id'] ?? null); $ll = isset($in['lat'], $in['lon']) ? llIn($in['lat'], $in['lon']) : null; [$ab, $co] = extStyle($in);
  $rn = function ($r) use ($db) { if ($r === null) return 'ohne Spezialrolle'; $s = $db->prepare("SELECT name FROM roles WHERE id=?"); $s->execute([$r]); return $s->fetch()['name'] ?? '?'; };
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM externals WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)$in['id'], $inc['id']]); $e = $s->fetch() ?: fail('Externe Einsatzkraft nicht gefunden', 404);
    $old = $e['role_id'] === null ? null : (int)$e['role_id'];
    $db->prepare("UPDATE externals SET name=?, descr=?, role_id=?, abbr=?, color=? WHERE id=?")->execute([$name, $descr, $rid, $ab, $co, $e['id']]);
    if ($e['name'] !== $name || (string)$e['descr'] !== $descr || $old !== $rid) logev($inc['id'], $uid, 'ext', "ändert die externe Einsatzkraft «{$e['name']}»" . ($e['name'] !== $name ? " zu «{$name}»" : '') . ($old !== $rid ? ' (' . $rn($rid) . ')' : ''));
    if ($ll && $ll !== [round((float)$e['lat'], 7), round((float)$e['lon'], 7)]) {
      $db->prepare("UPDATE externals SET lat=?, lon=?, moved=? WHERE id=?")->execute([$ll[0], $ll[1], $now, $e['id']]);
      logev($inc['id'], $uid, 'ext', sprintf('verschiebt die externe Einsatzkraft «%s» (%.5F, %.5F)', $name, $ll[0], $ll[1]));
    }
    out(['ok' => true, 'id' => (int)$e['id']]);
  }
  if (!$ll) fail('Standort fehlt');
  // manual = von Hand erfasst (nicht ab Vorlage): steht danach für den Rest des Einsatzes als Vorlage bereit
  $db->prepare("INSERT INTO externals(incident_id,name,descr,role_id,abbr,color,lat,lon,created_by,created,manual) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$inc['id'], $name, $descr, $rid, $ab, $co, $ll[0], $ll[1], $uid, $now, empty($in['manual']) ? 0 : 1]);
  $nid = (int)$db->lastInsertId();
  logev($inc['id'], $uid, 'ext', sprintf('erfasst die externe Einsatzkraft «%s» (%s, %.5F, %.5F)', $name, $rn($rid), $ll[0], $ll[1]));
  out(['ok' => true, 'id' => $nid]);

 case 'incident/external/remove': // nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann externe Einsatzkräfte entfernen', 403);
  $s = $db->prepare("SELECT * FROM externals WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $e = $s->fetch() ?: fail('Externe Einsatzkraft nicht gefunden', 404);
  $db->prepare("UPDATE externals SET removed=? WHERE id=?")->execute([$now, $e['id']]);
  logev($inc['id'], $uid, 'ext', "entfernt die externe Einsatzkraft «{$e['name']}»");
  out(['ok' => true]);

 case 'incident/kick': // Einsatzkraft aus dem Einsatz entfernen (Wiedereinstieg bleibt möglich)
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können Einsatzkräfte entfernen', 403);
  $t = (int)($in['user_id'] ?? 0);
  if ($t === $uid) fail('Zum Verlassen den Knopf «Einsatz verlassen» verwenden');
  $p = part($inc['id'], $t);
  if (!$p || $p['left_at'] !== null || $p['mode'] === 'declined') fail('Die Person ist nicht im Einsatz');
  $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $t]);
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$t]);
  logev($inc['id'], $uid, 'kick', 'hat ' . ($s->fetch()['name'] ?? '?') . ' aus dem Einsatz entfernt');
  out(['ok' => true]);

 case 'incident/message': // to: leer = alle Teilnehmenden, sonst Liste von User-IDs (nur Teilnehmende)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !exLead($inc)) fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');
  if (!canMessage($inc)) fail('Deine Rolle darf keine Nachrichten senden', 403);
  if (trim($in['text'] ?? '') === '') fail('Text fehlt');
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]);
  $act = array_map('intval', array_column($s->fetchAll(), 'user_id'));
  $to = null;
  if (!empty($in['to'])) { $to = array_values(array_intersect(array_map('intval', $in['to']), $act)); if (!$to) fail('Die gewählten Personen haben den Einsatz nicht angenommen'); }
  // Standort der sendenden Person (nur wenn sie ihn teilt); Markierung an mark_lat/mark_lon oder am eigenen Standort
  $p = part($inc['id']); $la = $p && $p['lat'] !== null ? (float)$p['lat'] : null; $lo = $p && $p['lon'] !== null ? (float)$p['lon'] : null; // Übungsleitung: keine Teilnahme, kein Standort
  $mk = !empty($in['mark']) ? 1 : 0; $mla = $mlo = null;
  if ($mk) {
    $ok = isset($in['mark_lat'], $in['mark_lon']) && is_numeric($in['mark_lat']) && is_numeric($in['mark_lon']) && abs($in['mark_lat']) <= 90 && abs($in['mark_lon']) <= 180;
    [$mla, $mlo] = $ok ? [(float)$in['mark_lat'], (float)$in['mark_lon']] : [$la, $lo];
    if ($mla === null) fail('Für die Markierung ist ein Standort nötig: Position auf der Karte wählen');
  }
  $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created,lat,lon,mark,mark_lat,mark_lon) VALUES(?,?,?,?,?,?,?,?,?,?)")
     ->execute([$inc['id'], $uid, $in['text'], $to === null ? null : json_encode($to), $now, $la, $lo, $mk, $mla, $mlo]);
  // Push nur an die Empfänger (keine Auswahl = alle Teilnehmenden), nicht an die sendende Person
  pushTo(array_diff($to ?? $act, [$uid]), ['t' => 'Nachricht von ' . $me['name'], 'b' => mb_substr($in['text'], 0, 300), 'tag' => 'm:' . $db->lastInsertId(), 'crit' => 0]);
  out(['ok' => true]); // im Einsatzprotokoll erscheint die Nachricht über logRows()

 case 'incident/message/unmark': // Kartenmarkierung einer Nachricht entfernen: sendende Person oder Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $s = $db->prepare("SELECT * FROM messages WHERE id=? AND incident_id=? AND mark=1"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $m = $s->fetch();
  if (!$m) fail('Markierung nicht gefunden', 404);
  if ((int)$m['from_user'] !== $uid && !canManage($inc)) fail('Nur wer die Nachricht gesendet hat oder die Einsatzleitung kann die Markierung entfernen', 403);
  $db->prepare("UPDATE messages SET mark=0, unmarked=? WHERE id=?")->execute([$now, $m['id']]);
  logev($inc['id'], $uid, 'markdel', 'entfernt die Kartenmarkierung der Nachricht «' . mb_substr($m['text'], 0, 60) . '»');
  out(['ok' => true]);

 case 'incident/air/set': // Helikopter für diesen Einsatz ein- oder ausschalten (Einsatzleitung), nur bei eingeschaltetem Hauptschalter
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die Helikopter ein- und ausschalten', 403);
  if (!airCfg()['on']) fail('Helikopterdaten sind in der Verwaltung ausgeschaltet', 403);
  $on = empty($in['on']) ? 0 : 1; if (airInc($inc) === (bool)$on) out(['ok' => true]);
  $db->prepare("UPDATE incidents SET air=? WHERE id=?")->execute([$on, $inc['id']]);
  logev($inc['id'], $uid, 'air', $on ? 'schaltet die Helikopter ein' : 'schaltet die Helikopter aus');
  out(['ok' => true]);

 case 'incident/record': // Aufzeichnung steuern (Einsatzleitung): start (auch nach «beenden» wieder), pause, resume, stop
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die Aufzeichnung steuern', 403);
  $cur = (int)$inc['record']; $a = (string)($in['action'] ?? '');
  // erlaubte Übergänge: Ziel-Zustand und Log-Text je Aktion
  $T = ['start' => [[0], 1, (int)$inc['recorded'] ? 'nimmt die Aufzeichnung wieder auf' : 'startet die Aufzeichnung'], 'pause' => [[1], 2, 'pausiert die Aufzeichnung'],
        'resume' => [[2], 1, 'setzt die Aufzeichnung fort'], 'stop' => [[1, 2], 0, 'beendet die Aufzeichnung']];
  if (!isset($T[$a])) fail('Unbekannte Aktion');
  if (!in_array($cur, $T[$a][0], true)) out(['ok' => true, 'record' => $cur]); // schon im gewünschten Zustand, z. B. gleichzeitig von zwei Personen
  setRec((int)$inc['id'], $T[$a][1]);
  logev($inc['id'], $uid, 'rec', $T[$a][2]);
  out(['ok' => true, 'record' => $T[$a][1]]);

 case 'incident/public': // Öffentliche Ansicht (nur Einsatzleitung): on = ein/aus, anon = Namen ausblenden, log = Einsatzprotokoll zeigen (Issue #54). Ausschalten macht den Link ungültig, beim nächsten Einschalten gibt es einen neuen.
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die öffentliche Ansicht steuern', 403);
  $was = $inc['public_token'] !== null; $wasAnon = (int)$inc['public_anon'] === 1;
  $on = array_key_exists('on', $in) ? !empty($in['on']) : $was; $anon = array_key_exists('anon', $in) ? !empty($in['anon']) : $wasAnon;
  $wasLog = (int)$inc['public_log'] === 1; $lg = array_key_exists('log', $in) ? !empty($in['log']) : $wasLog;
  $t = $on ? ($inc['public_token'] ?? bin2hex(random_bytes(16))) : null;
  $db->prepare("UPDATE incidents SET public_token=?, public_anon=?, public_log=? WHERE id=?")->execute([$t, $anon ? 1 : 0, $lg ? 1 : 0, $inc['id']]);
  $how = ($anon ? 'anonym, ohne Namen' : 'mit Namen') . ($lg ? ', mit Einsatzprotokoll' : ', ohne Einsatzprotokoll');
  if ($on && !$was) logev($inc['id'], $uid, 'pub', "schaltet die öffentliche Ansicht ein ($how)");
  elseif (!$on && $was) logev($inc['id'], $uid, 'pub', 'schaltet die öffentliche Ansicht aus');
  elseif ($on && ($anon !== $wasAnon || $lg !== $wasLog)) logev($inc['id'], $uid, 'pub', "stellt die öffentliche Ansicht um ($how)");
  out(['ok' => true, 'public_token' => $t]);

 case 'incident/pls': // PLS des Einsatzes setzen, verschieben oder entfernen (Einsatzleitung): lat, lon, at (fehlt = Alarmeingang) oder clear=1
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann den PLS des Einsatzes ändern', 403);
  if (!empty($in['clear'])) {
    $db->prepare("UPDATE incidents SET lat=NULL, lon=NULL, pls_at=NULL WHERE id=?")->execute([$inc['id']]);
    logev($inc['id'], $uid, 'pls', 'entfernt den PLS des Einsatzes');
    out(['ok' => true]);
  }
  [$la, $lo, $at] = plsIn($in, (int)$inc['alarm_at']);
  $db->prepare("UPDATE incidents SET lat=?, lon=?, pls_at=? WHERE id=?")->execute([$la, $lo, $at === (int)$inc['alarm_at'] ? null : $at, $inc['id']]);
  logev($inc['id'], $uid, 'pls', sprintf('%s den PLS des Einsatzes (%.5F, %.5F, %s)', $inc['lat'] === null ? 'setzt' : 'ändert', $la, $lo, hm($at)));
  out(['ok' => true]);

 case 'incident/end':
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können den Einsatz beenden', 403);
  if ((int)$inc['record']) setRec((int)$inc['id'], 0); // laufende Aufzeichnung endet mit dem Einsatz
  $db->prepare("UPDATE incidents SET ended_at=? WHERE id=?")->execute([$now, $inc['id']]);
  logev($inc['id'], $uid, 'end', 'hat den Einsatz beendet');
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL AND user_id<>?"); $s->execute([$inc['id'], $uid]);
  pushTo(array_column($s->fetchAll(), 'user_id'), ['t' => (int)$inc['is_exercise'] ? 'ÜBUNG BEENDET' : 'EINSATZ BEENDET', 'b' => (int)$inc['is_exercise'] ? 'Die Übung wurde beendet.' : 'Der Einsatz wurde beendet.', 'tag' => 'e:' . $inc['id'], 'crit' => 0]);
  out(['ok' => true]);

 /* ---------- Vorbereitete Übungen (Issue #29) ---------- */
 case 'ex/start': // vorbereitete Übung starten: nur die Übungsleitung dieser Übung (auch Administratoren und Übungsleiter nur, wenn sie eingetragen sind); Alarm mit der Aufgebotsmeldung, ohne PLS (kommt über die Freigabe)
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch();
  if (!$p || $p['incident_id'] !== null) fail('Diese Übung ist nicht mehr startbar', 404);
  if (!in_array($uid, exLeads($p), true)) fail('Nur die Übungsleitung dieser Übung kann sie starten', 403);
  $rec = (int)$p['record'] && features(1)['rec'] ? 1 : 0; $tok = features(1)['pub'] ? $p['public_token'] : null;
  $db->prepare("INSERT INTO incidents(message,alarm_at,created_by,is_exercise,plan_id,public_token,public_anon,air) VALUES(?,?,?,1,?,?,?,?)")->execute([$p['message'], $now, $uid, $p['id'], $tok, (int)$p['public_anon'], airCfg()['on'] ? airDef(1) : null]);
  $nid = (int)$db->lastInsertId();
  $db->prepare("UPDATE ex_plans SET incident_id=? WHERE id=?")->execute([$nid, $p['id']]);
  if ($rec) setRec($nid, 1);
  $iv = exInvite($p);
  logev($nid, $uid, 'start', "startet die vorbereitete Übung «{$p['title']}»" . ($rec ? ' (mit Aufzeichnung)' : '') . ($iv === null ? '' : ', alarmiert ' . count($iv) . (count($iv) === 1 ? ' aufgebotene Person' : ' aufgebotene Personen')) . ': ' . $p['message']);
  if ($tok) logev($nid, $uid, 'pub', 'öffentliche Ansicht vorbereitet (' . ((int)$p['public_anon'] ? 'anonym, ohne Namen' : 'mit Namen') . ')');
  pushAlarm(['id' => $nid, 'is_exercise' => 1, 'message' => $p['message']], array_merge([$uid], exLeads($p)), $iv); // wie alarmed(): nur Aufgebotene, nie die Übungsleitung
  out(['id' => $nid]);
 case 'ex/release': // vorbereitetes Element freigeben (Übungsleitung, Administratoren oder Übungsleiter): erst jetzt wird es für alle sichtbar und fürs Suchgebiet relevant
  $inc = activeIncident(); if (!$inc || empty($inc['plan_id'])) fail('Keine vorbereitete Übung aktiv');
  if (!exLead($inc) && !planner()) fail('Nur die Übungsleitung kann vorbereitete Elemente freigeben', 403);
  $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND plan_id=? AND removed=0"); $s->execute([(int)($in['id'] ?? 0), (int)$inc['plan_id']]); $j = $s->fetch();
  if (!$j) fail('Element nicht gefunden', 404);
  if ($j['released'] !== null) out(['ok' => true]); // schon freigegeben, z. B. gleichzeitig von zwei Personen
  // ohne vorbereiteten Zeitpunkt gibt ihn die Übungsleitung bei der Freigabe an (ago, Minuten vor jetzt)
  $ago = $j['ago'] !== null ? (int)$j['ago'] : (int)($in['ago'] ?? 0); if ($ago < 0 || $ago > 1440) fail('Zeitpunkt: 0 bis 1440 Minuten vor der Freigabe');
  if ($e = exRelease($inc, $j, $ago, $uid)) fail($e);
  out(['ok' => true]);
 case 'ex/plans': plannerOnly(); // alle vorbereiteten Übungen mit Elementen und Stand der Übung
  $ps = $db->query("SELECT p.*,i.alarm_at,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.active=1 ORDER BY p.incident_id IS NOT NULL, i.alarm_at DESC, p.title")->fetchAll();
  // Durchführungen (auch vor einem Zurücksetzen) mit Log und Replay
  $rs = []; foreach ($db->query("SELECT id,plan_id,alarm_at,ended_at,recorded FROM incidents WHERE plan_id IS NOT NULL ORDER BY alarm_at DESC")->fetchAll() as $r) $rs[(int)$r['plan_id']][] = ints($r, ['id', 'plan_id', 'alarm_at', 'ended_at', 'recorded']);
  out(array_map(fn($p) => ['leads' => exLeads($p), 'invite' => exInvite($p), 'injects' => exInjects((int)$p['id']), 'runs' => $rs[(int)$p['id']] ?? []] + ints($p, ['id', 'record', 'public_anon', 'created_by', 'created', 'incident_id', 'alarm_at', 'ended_at']), $ps));
 case 'ex/plan/save': plannerOnly(); // anlegen oder ändern (nur vor dem Start): title, message, leads, invite (null = alle alarmieren), record, public, public_anon
  $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120); $msg = trim((string)($in['message'] ?? ''));
  if ($title === '') fail('Titel fehlt'); if ($msg === '') fail('Die Aufgebotsmeldung ist Pflicht');
  $leads = array_values(array_unique(array_map('intval', (array)($in['leads'] ?? []))));
  if (!$leads) fail('Mindestens eine Person als Übungsleitung wählen');
  $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE active=1 AND id IN (" . implode(',', array_fill(0, count($leads), '?')) . ")"); $s->execute($leads);
  if ((int)$s->fetch()['c'] !== count($leads)) fail('Unbekannte oder deaktivierte Person in der Übungsleitung');
  $inv = null; // aufgeboten: ohne die Übungsleitung (wird ohnehin nicht alarmiert)
  if (($in['invite'] ?? null) !== null) {
    $inv = array_values(array_diff(array_unique(array_map('intval', (array)$in['invite'])), $leads));
    if (!$inv) fail('Mindestens eine Person aufbieten oder «Alle» alarmieren');
    $s = $db->prepare("SELECT COUNT(*) c FROM users WHERE active=1 AND id IN (" . implode(',', array_fill(0, count($inv), '?')) . ")"); $s->execute($inv);
    if ((int)$s->fetch()['c'] !== count($inv)) fail('Unbekannte oder deaktivierte Person bei den Aufgebotenen');
    $inv = json_encode($inv);
  }
  $rec = empty($in['record']) ? 0 : 1; $anon = empty($in['public_anon']) ? 0 : 1;
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)$in['id']]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
    if ($p['incident_id'] !== null) fail('Die Übung wurde bereits gestartet');
    $tok = empty($in['public']) ? null : ($p['public_token'] ?? bin2hex(random_bytes(16)));
    $db->prepare("UPDATE ex_plans SET title=?, message=?, leads=?, invite=?, record=?, public_token=?, public_anon=? WHERE id=?")->execute([$title, $msg, json_encode($leads), $inv, $rec, $tok, $anon, $p['id']]);
    out(['ok' => true, 'id' => (int)$p['id']]);
  }
  $db->prepare("INSERT INTO ex_plans(title,message,leads,invite,record,public_token,public_anon,created_by,created) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$title, $msg, json_encode($leads), $inv, $rec, empty($in['public']) ? null : bin2hex(random_bytes(16)), $anon, $uid, $now]);
  out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
 case 'ex/plan/copy': plannerOnly(); // als neue, noch nicht gestartete Übung kopieren (mit allen Elementen, nicht freigegeben)
  $s = $db->prepare("SELECT * FROM ex_plans WHERE id=? AND active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  $db->prepare("INSERT INTO ex_plans(title,message,leads,invite,record,public_token,public_anon,created_by,created) VALUES(?,?,?,?,?,?,?,?,?)")->execute([mb_substr('Kopie von ' . $p['title'], 0, 120), $p['message'], $p['leads'], $p['invite'], $p['record'], $p['public_token'] === null ? null : bin2hex(random_bytes(16)), $p['public_anon'], $uid, $now]);
  $nid = (int)$db->lastInsertId(); $map = []; $js = exInjects((int)$p['id']);
  foreach ($js as $j) {
    $db->prepare("INSERT INTO ex_injects(plan_id,kind,label,name,descr,lat,lon,ago,auto,msg,role_id,abbr,color) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$nid, $j['kind'], $j['label'], $j['name'], $j['descr'], $j['lat'], $j['lon'], $j['ago'], $j['auto'], $j['msg'], $j['role_id'], $j['abbr'], $j['color']]);
    $map[$j['id']] = (int)$db->lastInsertId();
  }
  foreach ($js as $j) if ($j['ref_id'] !== null && isset($map[$j['ref_id']])) // Verweise erst danach, sie können auch auf spätere Elemente zeigen
    $db->prepare("UPDATE ex_injects SET ref_id=? WHERE id=?")->execute([$map[$j['ref_id']], $map[$j['id']]]);
  out(['ok' => true, 'id' => $nid]);
 case 'ex/plan/reset': plannerOnly(); // beendete Übung zurücksetzen: wieder vorbereitet, alle Elemente nicht freigegeben; die bisherige Durchführung bleibt mit Log und Replay erhalten
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['incident_id'] === null) fail('Diese Übung ist noch nicht durchgeführt');
  if ($p['ended_at'] === null) fail('Die Übung läuft noch. Zuerst in der Einsatz-App beenden.');
  $db->beginTransaction();
  $db->prepare("UPDATE ex_plans SET incident_id=NULL WHERE id=?")->execute([$p['id']]);
  $db->prepare("UPDATE ex_injects SET released=NULL, released_by=NULL, rescuee_id=NULL, ext_id=NULL WHERE plan_id=?")->execute([$p['id']]);
  // der öffentliche Link gilt wieder für die nächste Durchführung (Warteseite), nicht mehr für die beendete
  if ($p['public_token'] !== null) $db->prepare("UPDATE incidents SET public_token=NULL WHERE id=?")->execute([$p['incident_id']]);
  $db->commit();
  logev((int)$p['incident_id'], $uid, 'ex', "setzt die vorbereitete Übung «{$p['title']}» für eine neue Durchführung zurück");
  out(['ok' => true]);
 case 'ex/plan/remove': plannerOnly(); // entfernen (nicht während sie läuft); gestartete bleiben im Einsatzlog erhalten
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['incident_id'] !== null && $p['ended_at'] === null) fail('Die Übung läuft noch');
  $db->prepare("UPDATE ex_plans SET active=0, public_token=CASE WHEN incident_id IS NULL THEN NULL ELSE public_token END WHERE id=?")->execute([$p['id']]);
  out(['ok' => true]);
 case 'ex/inject/save': plannerOnly(); // Element anlegen oder ändern (nur nicht freigegebene): plan_id, kind, label, lat, lon, ago, auto, msg; res: name, descr; seen: ref_id ('new' mit name/descr, 'unk' mit descr oder Element-ID); ext: name, descr, role_id, abbr, color; extdel: ref_id (ext-Element, ohne lat/lon)
  $s = $db->prepare("SELECT p.*,i.ended_at FROM ex_plans p LEFT JOIN incidents i ON i.id=p.incident_id WHERE p.id=? AND p.active=1"); $s->execute([(int)($in['plan_id'] ?? 0)]); $p = $s->fetch() ?: fail('Übung nicht gefunden', 404);
  if ($p['ended_at'] !== null) fail('Die Übung ist beendet');
  $kind = (string)($in['kind'] ?? ''); if (!isset(EX_KINDS[$kind])) fail('Unbekannte Art');
  $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 120); if ($label === '') fail('Bezeichnung fehlt');
  $xk = $kind === 'ext' || $kind === 'extdel'; $ref = null;
  if ($kind === 'extdel') { // Standort wie die externe Einsatzkraft, die entfernt wird
    $s = $db->prepare("SELECT id,lat,lon FROM ex_injects WHERE id=? AND plan_id=? AND removed=0 AND kind='ext'"); $s->execute([(int)($in['ref_id'] ?? 0), $p['id']]); $x = $s->fetch() ?: fail('Externe Einsatzkraft dieser Übung wählen');
    $ref = (int)$x['id']; $la = (float)$x['lat']; $lo = (float)$x['lon'];
  } else [$la, $lo] = llIn($in['lat'] ?? null, $in['lon'] ?? null);
  $ago = $xk ? 0 : (($in['ago'] ?? null) === null || $in['ago'] === '' ? null : (int)$in['ago']); // NULL = die Übungsleitung gibt den Zeitpunkt bei der Freigabe ein; externe ohne PLS
  if ($ago !== null && ($ago < 0 || $ago > 1440)) fail('Zeitpunkt: 0 bis 1440 Minuten vor der Freigabe');
  // seen: ref_id = 'new' (neuer Rettling mit Name), 'unk' (unbekannter Rettling) oder ein Element, das einen Rettling erfasst (res oder seen ohne ref_id)
  $sm = $kind === 'seen' ? (string)($in['ref_id'] ?? '') : ''; $own = $kind === 'res' || $sm === 'new' || $sm === 'unk';
  $name = $kind === 'res' || $sm === 'new' || $kind === 'ext' ? mb_substr(trim((string)($in['name'] ?? '')), 0, 80) : null; if ($name === '') fail($kind === 'ext' ? 'Name fehlt' : 'Name des Rettlings fehlt');
  $descr = $own || $kind === 'ext' ? mb_substr(trim((string)($in['descr'] ?? '')), 0, $kind === 'ext' ? 500 : 1000) : null;
  $rid = $kind === 'ext' ? roleIn($in['role_id'] ?? null) : null; [$ab, $co] = $kind === 'ext' ? extStyle($in) : [null, null];
  if ($kind === 'seen' && !$own) { $s = $db->prepare("SELECT id FROM ex_injects WHERE id=? AND id<>? AND plan_id=? AND removed=0 AND (kind='res' OR (kind='seen' AND ref_id IS NULL))"); $s->execute([(int)$sm, (int)($in['id'] ?? 0), $p['id']]); $ref = (int)(($s->fetch() ?: [])['id'] ?? 0) ?: fail('Rettling wählen: neu, unbekannt oder ein Rettling dieser Übung'); }
  $auto = ($in['auto'] ?? null) === null || $in['auto'] === '' ? null : (int)$in['auto']; // NULL = nur von Hand freigeben
  if ($auto !== null && ($auto < 0 || $auto > 1440)) fail('Automatische Freigabe: 0 bis 1440 Minuten nach dem Start');
  $msg = mb_substr(trim((string)($in['msg'] ?? '')), 0, 2000);
  $v = [$kind, $label, $name, $descr, $la, $lo, $ago, $auto, $msg === '' ? null : $msg, $ref, $rid, $ab, $co];
  // Elemente, auf die andere verweisen (Rettling: res, seen ohne ref_id; externe Einsatzkraft: ext), dürfen das nicht verlieren
  $prov = fn($k, $refNull) => $k === 'ext' ? 'ext' : ($k === 'res' || ($k === 'seen' && $refNull) ? 'res' : null);
  if (!empty($in['id'])) {
    $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND plan_id=? AND removed=0"); $s->execute([(int)$in['id'], $p['id']]); $j = $s->fetch() ?: fail('Element nicht gefunden', 404);
    if ($j['released'] !== null) fail('Freigegebene Elemente lassen sich nicht mehr ändern');
    if ($prov($j['kind'], $j['ref_id'] === null) !== $prov($kind, $ref === null)) { $s = $db->prepare("SELECT COUNT(*) c FROM ex_injects WHERE ref_id=? AND removed=0"); $s->execute([$j['id']]); if ((int)$s->fetch()['c']) fail('Auf dieses Element verweisen andere Elemente; zuerst diese ändern'); }
    $db->prepare("UPDATE ex_injects SET kind=?, label=?, name=?, descr=?, lat=?, lon=?, ago=?, auto=?, msg=?, ref_id=?, role_id=?, abbr=?, color=? WHERE id=?")->execute([...$v, $j['id']]);
    if ($kind === 'ext') $db->prepare("UPDATE ex_injects SET lat=?, lon=? WHERE ref_id=? AND kind='extdel'")->execute([$la, $lo, $j['id']]); // Entfernen am selben Ort
    out(['ok' => true]);
  }
  $db->prepare("INSERT INTO ex_injects(kind,label,name,descr,lat,lon,ago,auto,msg,ref_id,role_id,abbr,color,plan_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$v, $p['id']]);
  out(['ok' => true]);
 case 'ex/inject/remove': plannerOnly(); // nur nicht freigegebene
  $s = $db->prepare("SELECT * FROM ex_injects WHERE id=? AND removed=0"); $s->execute([(int)($in['id'] ?? 0)]); $j = $s->fetch() ?: fail('Element nicht gefunden', 404);
  if ($j['released'] !== null) fail('Freigegebene Elemente lassen sich nicht entfernen');
  $s = $db->prepare("SELECT COUNT(*) c FROM ex_injects WHERE ref_id=? AND removed=0"); $s->execute([$j['id']]); if ((int)$s->fetch()['c']) fail('Auf dieses Element verweisen andere Elemente; zuerst diese entfernen');
  $db->prepare("UPDATE ex_injects SET removed=1 WHERE id=?")->execute([$j['id']]);
  out(['ok' => true]);

 case 'incident/log': $v = incView((int)($_GET['id'] ?? 0)); if ($v && !myBtns()['log']) fail('Das Einsatzlog ist für Einsatzkräfte ausgeschaltet', 403); // anon=1: anonymisierte Fassung (Issue #54); Teilnehmende (Issue #61) behalten den eigenen Namen, bei my_runs anon immer anonym
  $inc = incById($_GET['id'] ?? 0); out(logRows((int)$inc['id'], true, $v === 2 || !empty($_GET['anon']) || (int)$inc['view_anon'] || ($v && anonAny($inc)), $v ? $uid : null)); // view_anon: Anzeige anonymisiert (Issue #87)

 case 'incident/replay': $v = incView((int)($_GET['id'] ?? 0)); if ($v && !myBtns()['replay']) fail('Das Replay ist für Einsatzkräfte ausgeschaltet', 403); $self = $v ? $uid : null; // alle Daten eines aufgezeichneten Einsatzes für das Replay in der Verwaltung; anon=1: ohne Personendaten (Issue #54)
  $inc = incById($_GET['id'] ?? 0);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  $id = (int)$inc['id']; $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  // endgültig anonymisiertes Replay (rp_anon): Einsatzkräfte ohne Verbindung zu Konten, Standortverläufe mit negativer ID; immer anonym
  $rpa = $inc['rp_anon'] !== null; $va = (int)$inc['view_anon']; $fa = $v && anonAny($inc); $anon = $rpa || $va || $fa || $v === 2 || !empty($_GET['anon']); $c = $anon ? anonCtx($id, $self) : null;
  $parts = $rpa ? (json_decode($inc['rp_anon'], true) ?: []) : replayParts($id);
  if ($anon && !$rpa) foreach ($parts as &$p) $p['name'] = $c['u'][$p['user_id']] ?? 'Einsatzkraft';
  unset($p);
  // Teilnehmende (Issue #61): eigene Person markiert (me); anonym ohne Konto-IDs der anderen (Spuren mit negativer ID wie bei rp_anon)
  $uidMap = [];
  if ($self !== null && !$rpa) foreach ($parts as $i => &$p) { if ($p['user_id'] === $self) $p['me'] = 1; elseif ($anon) { $uidMap[$p['user_id']] = -($i + 1); $p['user_id'] = -($i + 1); } }
  unset($p);
  // Verlauf kompakt als [id, ts, lat, lon]
  $tr = ['u' => [], 'r' => []];
  foreach ($q("SELECT user_id,rescuee_id,ts,lat,lon FROM track WHERE incident_id=? ORDER BY ts,id") as $r)
    $r['user_id'] !== null ? $tr['u'][] = [$uidMap[(int)$r['user_id']] ?? (int)$r['user_id'], (int)$r['ts'], (float)$r['lat'], (float)$r['lon']] : $tr['r'][] = [(int)$r['rescuee_id'], (int)$r['ts'], $r['lat'] === null ? null : (float)$r['lat'], $r['lon'] === null ? null : (float)$r['lon']]; // ohne lat/lon = Fundort zurückgesetzt
  $pois = array_map(fn($r) => ['pts' => json_decode($r['pts'], true)] + ints($r, ['id', 'created', 'removed']), $q("SELECT id,kind,name,color,pts,created,removed FROM pois WHERE incident_id=? ORDER BY id"));
  $res = array_map(fn($r) => floats(ints($r, ['id', 'created', 'removed', 'loc_at', 'rescued_at', 'pls_at']), ['lat', 'lon', 'pls_lat', 'pls_lon']), $q("SELECT id,name,descr,lat,lon,loc_at,rescued_at,created,removed,pls_lat,pls_lon,pls_at FROM rescuees WHERE incident_id=? ORDER BY id"));
  $marks = array_map(fn($r) => floats(ints($r, ['id', 'created', 'unmarked', 'mark', 'uid']), ['mark_lat', 'mark_lon']), $q("SELECT m.id,m.text,m.created,m.mark,m.unmarked,m.mark_lat,m.mark_lon,m.from_user uid,COALESCE(u.name,m.from_label) name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.mark_lat IS NOT NULL ORDER BY m.id"));
  $flow = array_map(fn($r) => ints($r, ['ts']), $q("SELECT ts,text FROM incident_log WHERE incident_id=? AND kind='flow' ORDER BY ts,id"));
  // externe Einsatzkräfte: Standort und Spezialrolle mit Stand Einsatzende
  $ext = array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'created', 'removed']), ['lat', 'lon']), $q("SELECT id,name,descr,role_id,abbr,color,lat,lon,created,removed FROM externals WHERE incident_id=? ORDER BY id"));
  if ($anon) { // Rettlinge und Externe nummeriert, ohne Beschreibung; Nachrichten-Markierungen mit Bezeichnung der sendenden Person
    foreach ($res as &$r) { $r['name'] = $c['r'][$r['id']] ?? 'Rettling'; $r['descr'] = null; }
    unset($r);
    foreach ($ext as &$r) { $r['name'] = $c['e'][$r['id']] ?? 'Externe Einsatzkraft'; $r['descr'] = null; }
    unset($r);
    foreach ($marks as &$r) { if (isset($r['uid'])) $r['name'] = $c['u'][$r['uid']] ?? 'Person'; $r['text'] = anonText($r['text'], $c); }
    unset($r);
    $inc['message'] = anonText($inc['message'], $c);
  }
  foreach ($marks as &$r) unset($r['uid']);
  unset($r);
  $per = array_map(fn($r) => ints($r, ['start', 'stop']), $q("SELECT start,stop FROM rec_periods WHERE incident_id=? ORDER BY start,id"));
  $inc = floats(ints($inc, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded', 'log_anon']), ['lat', 'lon', 'flow_ms']); $inc['pls_at'] = plsAt($inc);
  unset($inc['public_token']); $inc['rp_anon'] = $rpa ? 1 : 0; if ($anon && $v) $inc['created_by'] = null;
  out(['incident' => $inc, 'anon' => $anon ? 1 : 0, 'forced' => $v === 2 || $va || $fa ? 1 : 0, 'view_anon' => $va, 'periods' => $per, 'participants' => $parts, 'track' => $tr['u'], 'rtrack' => $tr['r'], 'pois' => $pois, 'rescuees' => $res, 'externals' => $ext, 'marks' => $marks, 'flow' => $flow, 'air' => airReplay($id), 'log' => $v && !myBtns()['log'] ? array_values(array_filter(logRows($id, true, $anon, $self), fn($l) => ($l['kind'] ?? '') === 'pls')) : logRows($id, true, $anon, $self), 'now' => $now]); // air: Helikopter (Issue #73)

 // Endgültig anonymisieren (Issue #54, nur Verwaltung, nur beendete Einsätze, nicht umkehrbar). Zwei getrennte Funktionen:
 // Replay: Einsatzkräfte im Replay ohne Verbindung zu den Konten (Bezeichnung, Einrückoption, Spezialrolle in rp_anon), Standortverläufe mit negativer ID,
 //   letzte Standorte der Teilnahmen gelöscht; das Replay zeigt danach auch Rettlinge, Externe und Log nur noch anonym. Einsatzprotokoll und Statistik bleiben (Statistik pro Person weiter personenbezogen, psRows).
 // Einsatzprotokoll: Log-Einträge und Nachrichten ohne Konto (Bezeichnung in actor, from_label, to_label), Namen im Text ersetzt, ebenso in der Alarmmeldung.
 case 'incident/anon/replay': need('anon'); // einzelne Stufen wie bisher (Issue #54); allgemein: anon/apply
  $inc = incById($in['id'] ?? 0);
  if ($inc['ended_at'] === null) fail('Nur beendete Einsätze lassen sich anonymisieren');
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  if (!anonDo((int)$inc['id'], 'replay', '(Verwaltung)')) fail('Das Replay ist bereits anonymisiert');
  out(['ok' => true]);
 case 'incident/anon/log': need('anon');
  $inc = incById($in['id'] ?? 0);
  if ($inc['ended_at'] === null) fail('Nur beendete Einsätze lassen sich anonymisieren');
  if (!anonDo((int)$inc['id'], 'log', '(Verwaltung)')) fail('Das Einsatzprotokoll ist bereits anonymisiert');
  out(['ok' => true]);
 /* ---------- Anonymisierung einstellen und anwenden (Issue #87) ---------- */
 case 'anon/cfg': need('anon'); // Stufen, Einstellung und alle beendeten Einsätze mit ihrem Stand (für die Massenanonymisierung)
  $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'is_exercise', 'recorded', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'part_anon', 'accepted', 'rec_del']), $db->query(
    "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.is_exercise,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,i.part_anon,
      (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
      (SELECT COUNT(*) FROM incident_log l WHERE l.incident_id=i.id AND (l.kind='anon' AND l.text LIKE 'Aufzeichnung gelöscht%' OR l.kind='rec' AND l.text LIKE 'löscht die Aufzeichnung%')) rec_del FROM incidents i WHERE i.ended_at IS NOT NULL ORDER BY i.alarm_at DESC, i.id DESC")->fetchAll()); // rec_del: Aufzeichnung gelöscht, sonst nicht von «nie aufgezeichnet» zu unterscheiden; aus dem Log, gilt also auch für bereits gelöschte
  out(['steps' => array_map(fn($k, $v) => ['key' => $k, 'name' => $v[0], 'descr' => $v[1], 'final' => $v[2], 'def' => ANON_DEFAULT[$k] ?? null], array_keys(ANON_STEPS), ANON_STEPS), 'cfg' => anonCfg(), 'incidents' => $rows]);
 case 'anon/cfg/save': need('anon'); // cfg: {Stufe: {on, real, ex}}, Tage leer = nie
  $c = is_array($in['cfg'] ?? null) ? $in['cfg'] : fail('Ungültige Einstellung'); $r = [];
  foreach (ANON_STEPS as $k => $_) { $x = is_array($c[$k] ?? null) ? $c[$k] : [];
    foreach (['real', 'ex'] as $t) { $v = $x[$t] ?? null; if ($v !== null && $v !== '' && (!is_numeric($v) || (int)$v < 0 || (int)$v > 36500)) fail('Tage: 0 bis 36500 oder leer (nie)'); }
    $r[$k] = ['on' => empty($x['on']) ? 0 : 1, 'real' => ($x['real'] ?? '') === '' ? null : (int)$x['real'], 'ex' => ($x['ex'] ?? '') === '' ? null : (int)$x['ex']]; }
  setSetting('anon_cfg', json_encode($r)); setSetting('anon_auto_at', '0'); out(['cfg' => anonCfg()]);
 case 'anon/apply': need('anon'); // ids = Einsätze (beendete, höchstens 1000), steps = Stufen; je Einsatz in der Reihenfolge der Stufen, schon Erledigtes wird übersprungen
  $ids = array_values(array_unique(array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : [])));
  $steps = array_values(array_intersect(array_keys(ANON_STEPS), is_array($in['steps'] ?? null) ? $in['steps'] : []));
  if (!$ids) fail('Keine Einsätze gewählt'); if (count($ids) > 1000) fail('Höchstens 1000 Einsätze auf einmal');
  if (!$steps) fail('Bitte mindestens eine Stufe wählen');
  $cfg = anonCfg(); foreach ($steps as $k) if (!$cfg[$k]['on']) fail('Die Stufe «' . ANON_STEPS[$k][0] . '» ist ausgeschaltet');
  @set_time_limit(300); $done = array_fill_keys($steps, 0); $n = 0;
  foreach ($ids as $id) { $any = false; foreach ($steps as $k) if (anonDo($id, $k, '(Verwaltung)')) { $done[$k]++; $any = true; } if ($any) $n++; }
  out(['done' => $done, 'incidents' => $n]);
 case 'anon/undo': need('anon'); // nur die umkehrbare Stufe «Anzeige anonymisieren» aufheben
  $ids = array_values(array_unique(array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : []))); if (!$ids) fail('Keine Einsätze gewählt');
  $up = $db->prepare("UPDATE incidents SET view_anon=0 WHERE id=? AND view_anon=1"); $n = 0;
  foreach ($ids as $id) { $up->execute([$id]); if ($up->rowCount()) { logev($id, null, 'anon', 'Anonymisierte Anzeige aufgehoben (Verwaltung)'); $n++; } }
  out(['incidents' => $n]);

 case 'incident/replay/delete': need('anon'); // Aufzeichnung eines Einsatzes löschen (Standortverläufe und Zeiträume); Log, Nachrichten usw. bleiben
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $inc = $s->fetch();
  if (!$inc) fail('Einsatz nicht gefunden', 404);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  foreach (['track', 'rec_periods', 'air_track'] as $t) $db->prepare("DELETE FROM $t WHERE incident_id=?")->execute([$inc['id']]);
  $db->prepare("UPDATE incidents SET record=0, recorded=0, rp_anon=NULL WHERE id=?")->execute([$inc['id']]); // läuft der Einsatz noch, endet auch die Aufzeichnung
  logev($inc['id'], $uid, 'rec', 'löscht die Aufzeichnung' . ($inc['ended_at'] === null ? ' (Aufzeichnung beendet)' : ''));
  out(['ok' => true]);

 case 'my/incidents': // Meine Einsätze (Issue #61): beendete Einsätze, an denen die Person teilgenommen hat (nicht abgelehnt), für Log und Replay
  if (myRuns() === 'off') fail('Die Einsicht in eigene Einsätze ist ausgeschaltet', 403);
  $s = $db->prepare("SELECT i.id,i.message,i.alarm_at,i.ended_at,i.is_exercise,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,m.name mode_name,(SELECT a.rel_at FROM aar a WHERE a.incident_id=i.id AND a.status=1 AND NOT EXISTS (SELECT 1 FROM aar_arch r WHERE r.incident_id=i.id)) aar_rel,(SELECT COUNT(*) FROM aar_arch r WHERE r.incident_id=i.id) aar_arch,(SELECT a.rel_at FROM aar a WHERE a.incident_id=i.id AND a.status=1) aar_at
    FROM participants p JOIN incidents i ON i.id=p.incident_id LEFT JOIN modes m ON m.code=p.mode WHERE p.user_id=? AND p.mode<>'declined' AND i.ended_at IS NOT NULL ORDER BY i.alarm_at DESC, i.id DESC");
  $s->execute([$uid]); $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'is_exercise', 'recorded', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'aar_rel', 'aar_arch', 'aar_at']), $s->fetchAll()); // aar_rel: Review verfügbar (Zeitpunkt der Freigabe, Issue #55); aar_at: freigegeben, auch wenn gesichert
  if (myRuns() === 'anon') foreach ($rows as &$r) $r['message'] = anonText($r['message'], anonCtx($r['id'], $uid)); // Alarmmeldung wie im Log
  unset($r);
  if (!myAar()) foreach ($rows as &$r) { $r['aar_rel'] = null; if (empty($r['aar_arch']) || !can('aar_arch')) $r['aar_at'] = null; }
  unset($r);
  if (!can('aar_arch')) foreach ($rows as &$r) unset($r['aar_arch']); // das gesicherte PDF nur mit dem Recht, unabhängig von my_btns
  unset($r);
  out(['mode' => myRuns(), 'btns' => myBtns(), 'incidents' => $rows]);
 case 'my/stats': // Meine Statistik (Issue #87): nur die eigenen Teilnahmen, für alle Angemeldeten, auch aus endgültig anonymisierten Einsätzen
  out(['rows' => psRows($uid)]);
 case 'stats': need('stats', 'aar', 'aar_rel', 'aar_arch'); // mit nur aar (After Action Review, Issue #55) ohne Log und Replay, die prüfen incView
  $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded', 'accepted', 'declined', 'first_accept', 'first_area', 'rp_anon', 'log_anon', 'view_anon', 'txt_anon', 'part_anon', 'aar_at', 'aar_st', 'aar_arch', 'aar_lock']), $db->query(
   "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.created_by,i.is_exercise,i.record,i.recorded,CASE WHEN i.rp_anon IS NULL THEN 0 ELSE 1 END rp_anon,i.log_anon,i.view_anon,i.txt_anon,i.part_anon,u.name creator,(SELECT a.updated FROM aar a WHERE a.incident_id=i.id) aar_at,(SELECT a.status FROM aar a WHERE a.incident_id=i.id) aar_st,(SELECT COUNT(*) FROM aar_arch r WHERE r.incident_id=i.id) aar_arch,CASE WHEN " . AAR_LOCK . " THEN 1 ELSE 0 END aar_lock,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode='declined') declined,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') first_accept,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') first_area
    FROM incidents i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.id DESC")->fetchAll());
  foreach ($rows as &$r) { $r['aar_seal'] = $r['aar_arch']; if (!can('aar_arch')) unset($r['aar_arch']); } unset($r); // aar_seal: Review nur noch als PDF, sehen alle; das PDF selbst nur mit dem Recht
  $ended = array_filter($rows, fn($r) => $r['ended_at']);
  out(['incidents' => $rows, 'totals' => [
    'count' => count($rows), 'ended' => count($ended), 'exercises' => count(array_filter($rows, fn($r) => $r['is_exercise'])),
    'avg_duration' => avg(array_map(fn($r) => $r['ended_at'] - $r['alarm_at'], $ended)),
    'avg_accepted' => avg(array_column($rows, 'accepted')),
    'avg_first_accept' => avg(array_map(fn($r) => $r['first_accept'] === null ? null : $r['first_accept'] - $r['alarm_at'], $rows)),
    'avg_first_area' => avg(array_map(fn($r) => $r['first_area'] === null ? null : $r['first_area'] - $r['alarm_at'], $rows))]]);

 case 'stats/people': need('stats'); // Statistik pro Person (Issue #87): eine Zeile je Teilnahme, ausgewertet (Zeitraum, Einsatz, Art) im Frontend
  out(['rows' => psRows()]);

 /* ---------- After Action Review (Issue #55) ---------- */
 case 'aar/cfg': if (myRuns() === 'off') need('aar', 'aar_rel', 'aar_cfg'); // mit Meine Einsätze für alle (Ansicht freigegebener Reviews)
  out(['cfg' => aarCfg(), 'logo' => setting('aar_logo', '') ?: null, 'types' => AAR_TYPES, 'auto' => AAR_AUTO, 'dls' => AAR_DL, 'dlst' => AAR_DL_ST]);
 case 'aar/cfg/save': need('aar_cfg'); setSetting('aar_cfg', json_encode(aarIn($in), JSON_UNESCAPED_UNICODE)); out(['cfg' => aarCfg()]);
 case 'aar/logo': need('aar_cfg'); // PNG oder JPEG als Data-URL (der Browser verkleinert vorher), null = entfernen
  $l = $in['logo'] ?? null;
  if ($l !== null) {
    if (!is_string($l) || !preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $l, $m)) fail('Nur PNG- oder JPEG-Bilder');
    $b = base64_decode($m[2], true);
    if ($b === false || strlen($b) > 1500000) fail('Das Bild ist zu gross (höchstens 1.5 MB)');
    if (!@getimagesizefromstring($b)) fail('Die Datei ist kein gültiges Bild');
  }
  setSetting('aar_logo', (string)$l); out(['ok' => true]);
 case 'aar/get': // Daten fürs Dokument; anon=1 oder endgültig anonymisiert: Bezeichnungen wie Issue #54 (die erfassten Angaben bleiben, wie sie geschrieben wurden)
  // my=1 (Meine Einsätze): nur freigegebene Reviews eigener Einsätze, Namen gemäss my_runs (anon: alle anderen anonym, die Person selbst mit Namen)
  $inc = incById($_GET['id'] ?? 0); $id = (int)$inc['id']; $mine = aarView($id);
  $fin = (int)$inc['log_anon'] || $inc['rp_anon'] !== null; $forced = $fin || (int)$inc['view_anon']; $self = $mine && !$fin ? $uid : null; // Anzeige anonymisiert: die Person selbst behält den Namen
  $anon = $forced || !empty($_GET['anon']) || $mine && myRuns() === 'anon'; $c = $anon ? anonCtx($id, $self) : null;
  out(aarDoc($inc, $c, $anon, $forced, $mine, $self));
 /* ---------- Vor dem Anonymisieren gesicherte Reviews, alles nur mit Recht aar_arch; das fertige PDF lässt sich nicht mehr ändern ---------- */
 case 'aar/arch/pending': need('aar_arch'); // Sicherungen, aus denen der Browser noch das PDF erzeugen muss
  out(['ids' => array_map('intval', $db->query("SELECT incident_id FROM aar_arch WHERE pdf_at IS NULL AND snap IS NOT NULL ORDER BY incident_id")->fetchAll(PDO::FETCH_COLUMN))]);
 case 'aar/arch/snap': need('aar_arch'); $r = aarArchRow($_GET['id'] ?? 0); if ($r['pdf_at'] !== null) fail('Das PDF liegt bereits vor');
  $s = $db->prepare("SELECT snap FROM aar_arch WHERE incident_id=?"); $s->execute([(int)$r['incident_id']]); out(['created' => (int)$r['created'], 'doc' => json_decode($s->fetchColumn(), true)]);
 case 'aar/arch/img': need('aar_arch'); $s = $db->prepare("SELECT data FROM aar_arch_img WHERE incident_id=? AND img_id=?"); $s->execute([(int)($_GET['id'] ?? 0), (int)($_GET['img'] ?? 0)]); $v = $s->fetchColumn(); if ($v === false) fail('Bild nicht gefunden', 404); out(['src' => $v]);
 case 'aar/arch/put': need('aar_arch'); // ein Stück des PDF: id, n, data (Base64)
  $r = aarArchRow($in['id'] ?? 0); if ($r['pdf_at'] !== null) fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409);
  $n = (int)($in['n'] ?? -1); $d = (string)($in['data'] ?? ''); if ($n < 0 || $n >= AAR_ARCH_PARTS || $d === '' || strlen($d) > AAR_ARCH_CHUNK || base64_decode($d, true) === false) fail('Ungültiges Stück');
  $db->prepare("DELETE FROM aar_arch_part WHERE incident_id=? AND n=?")->execute([(int)$r['incident_id'], $n]);
  $db->prepare("INSERT INTO aar_arch_part(incident_id,n,data) VALUES(?,?,?)")->execute([(int)$r['incident_id'], $n, $d]); out(['ok' => true]);
 case 'aar/arch/done': need('aar_arch'); // alle Stücke da: id, parts, sha (SHA-256 des PDF), name; ab hier unveränderlich
  $r = aarArchRow($in['id'] ?? 0); $id = (int)$r['incident_id']; if ($r['pdf_at'] !== null) fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409);
  $parts = (int)($in['parts'] ?? 0); if ($parts < 1 || $parts > AAR_ARCH_PARTS) fail('Ungültige Anzahl Stücke');
  $h = hash_init('sha256'); $s = $db->prepare("SELECT data FROM aar_arch_part WHERE incident_id=? AND n=?");
  for ($n = 0; $n < $parts; $n++) { $s->execute([$id, $n]); $v = $s->fetchColumn(); $s->closeCursor(); if ($v === false) fail('Es fehlen Stücke des PDF'); $v = base64_decode($v); if (!$n && substr($v, 0, 5) !== '%PDF-') fail('Das ist kein PDF'); hash_update($h, $v); }
  $sha = hash_final($h); if (!hash_equals($sha, (string)($in['sha'] ?? ''))) fail('Prüfsumme stimmt nicht');
  $db->beginTransaction();
  $u = $db->prepare("UPDATE aar_arch SET pdf_at=?,pdf_parts=?,pdf_name=?,pdf_sha=?,snap=NULL WHERE incident_id=? AND pdf_at IS NULL"); $u->execute([$now, $parts, mb_substr(basename((string)($in['name'] ?? 'AAR.pdf')), 0, 150), $sha, $id]);
  if (!$u->rowCount()) { $db->rollBack(); fail('Das gesicherte PDF lässt sich nicht mehr ändern', 409); }
  $db->prepare("DELETE FROM aar_arch_part WHERE incident_id=? AND n>=?")->execute([$id, $parts]); $db->prepare("DELETE FROM aar_arch_img WHERE incident_id=?")->execute([$id]);
  $db->commit(); out(['ok' => true]);
 case 'aar/arch/part': need('aar_arch'); // Abrufen: ein Stück mit den Angaben zum PDF; das Dokument setzt der Browser zusammen und prüft die Summe
  $r = aarArchRow($_GET['id'] ?? 0); if ($r['pdf_at'] === null) out(['pending' => true]);
  $s = $db->prepare("SELECT data FROM aar_arch_part WHERE incident_id=? AND n=?"); $s->execute([(int)$r['incident_id'], (int)($_GET['n'] ?? 0)]); $v = $s->fetchColumn(); if ($v === false) fail('Stück nicht gefunden', 404);
  out(['parts' => (int)$r['pdf_parts'], 'name' => $r['pdf_name'], 'sha' => $r['pdf_sha'], 'data' => $v]);
 case 'aar/img': // ein Bild als Data-URL (Zugriff wie aar/get)
  $r = aarImgRow($_GET['id'] ?? 0); aarView((int)$r['incident_id']);
  $s = $db->prepare("SELECT data FROM aar_img WHERE id=?"); $s->execute([(int)$r['id']]); out(['src' => $s->fetchColumn()]);
 case 'aar/img/add': // id = Einsatz, field = Feld der Art img oder area (im Text über die Marke [Bild <id>] platziert), src = JPEG/PNG als Data-URL, caption optional
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $ex = aarEditable($inc);
  $fid = (string)($in['field'] ?? ''); if (!array_filter(aarCfg()['fields'], fn($f) => $f['id'] === $fid && ($f['type'] === 'img' || $f['type'] === 'area'))) fail('Unbekanntes Bilderfeld');
  $src = $in['src'] ?? null;
  if (!is_string($src) || !preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $src, $m)) fail('Nur PNG- oder JPEG-Bilder');
  $b = base64_decode($m[2], true); if ($b === false || strlen($b) > 3000000) fail('Das Bild ist zu gross (höchstens 3 MB)');
  $sz = @getimagesizefromstring($b); if (!$sz) fail('Die Datei ist kein gültiges Bild');
  $s = $db->prepare("SELECT COUNT(*) FROM aar_img WHERE incident_id=?"); $s->execute([$id]); if ((int)$s->fetchColumn() >= AAR_IMG_MAX) fail('Höchstens ' . AAR_IMG_MAX . ' Bilder je Review');
  $db->beginTransaction();
  $db->prepare("INSERT INTO aar_img(incident_id,field_id,caption,data,w,h,created,created_by) VALUES(?,?,?,?,?,?,?,?)")->execute([$id, $fid, mb_substr(trim((string)($in['caption'] ?? '')), 0, 300), $src, (int)$sz[0], (int)$sz[1], $now, $uid]);
  $nid = (int)$db->lastInsertId(); aarTouch($id, $ex); $db->commit();
  out(['id' => $nid, 'w' => (int)$sz[0], 'h' => (int)$sz[1]]);
 case 'aar/img/save': // Beschriftung ändern
  $r = aarImgRow($in['img'] ?? 0); $id = (int)$r['incident_id']; $ex = aarEditable(incById($id));
  $db->prepare("UPDATE aar_img SET caption=? WHERE id=?")->execute([mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['caption'] ?? ''))), 0, 300), (int)$r['id']]); aarTouch($id, $ex); out(['ok' => true]);
 case 'aar/img/remove':
  $r = aarImgRow($in['img'] ?? 0); $id = (int)$r['incident_id']; $ex = aarEditable(incById($id));
  $db->prepare("DELETE FROM aar_img WHERE id=?")->execute([(int)$r['id']]);
  // auch die Marken in den Texten entfernen, sonst stünde dort nach einem Abbrechen des Formulars «[Bild 12]» als Text
  $s = $db->prepare("SELECT data FROM aar WHERE incident_id=?"); $s->execute([$id]); $d = json_decode((string)$s->fetchColumn(), true); $s->closeCursor();
  if (is_array($d)) { foreach ($d as $k => $v) if (is_string($v)) { $v = preg_replace('/\n?\[Bild ' . (int)$r['id'] . '\]/', '', $v); if (trim($v) === '') unset($d[$k]); else $d[$k] = $v; }
    $db->prepare("UPDATE aar SET data=? WHERE incident_id=?")->execute([json_encode((object)$d, JSON_UNESCAPED_UNICODE), $id]); }
  aarTouch($id, $ex); out(['ok' => true]);
 case 'aar/save': need('aar'); // nur Felder der Einstellungen; Werte entfernter Felder bleiben erhalten
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; aarSealed($id);
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT data,status FROM aar WHERE incident_id=?"); $s->execute([$id]); $old = $s->fetch(); $s->closeCursor(); $d = $old ? (json_decode((string)$old['data'], true) ?: []) : [];
  if ($old && (int)$old['status'] === 1) fail('Das After Action Review ist freigegeben. Zum Bearbeiten zuerst die Freigabe zurücknehmen.');
  $v = (array)($in['data'] ?? []);
  foreach (aarCfg()['fields'] as $f) if ($f['type'] !== 'head' && $f['type'] !== 'img' && array_key_exists($f['id'], $v)) {
    $x = $v[$f['id']]; if (!is_scalar($x) && $x !== null) fail('Ungültiger Wert');
    $x = mb_substr(str_replace("\r", '', trim((string)$x)), 0, 20000);
    if ($x === '') unset($d[$f['id']]); else $d[$f['id']] = $x;
  }
  // Speichern macht ein als nicht relevant markiertes Review wieder zu einem in Arbeit
  $j = json_encode((object)$d, JSON_UNESCAPED_UNICODE);
  if ($old) $db->prepare("UPDATE aar SET data=?,updated=?,updated_by=?,status=0 WHERE incident_id=?")->execute([$j, $now, $uid, $id]);
  else $db->prepare("INSERT INTO aar(incident_id,data,updated,updated_by,status) VALUES(?,?,?,?,0)")->execute([$id, $j, $now, $uid]);
  out(['ok' => true]);
 case 'aar/status': // 0 = in Arbeit, 1 = freigeben (Recht aar_rel, sperrt das Review), 2 = nicht relevant; Freigabe zurücknehmen ebenfalls nur mit aar_rel
  $inc = incById($in['id'] ?? 0); $id = (int)$inc['id']; $st = (int)($in['status'] ?? -1);
  if (!isset(AAR_STATUS[$st])) fail('Ungültiger Status');
  if ($inc['ended_at'] === null) fail('Ein After Action Review gibt es erst nach dem Einsatzende');
  $s = $db->prepare("SELECT status FROM aar WHERE incident_id=?"); $s->execute([$id]); $cur = $s->fetchColumn(); $s->closeCursor(); $cur = $cur === false ? null : (int)$cur;
  if ($st === 1 || $cur === 1) need('aar_rel'); else need('aar');
  aarSealed($id);
  if ($cur === null) $db->prepare("INSERT INTO aar(incident_id,data,status) VALUES(?,'{}',0)")->execute([$id]);
  $db->prepare("UPDATE aar SET status=?,rel_at=?,rel_by=? WHERE incident_id=?")->execute([$st, $st === 1 ? $now : null, $st === 1 ? $uid : null, $id]);
  out(['ok' => true]);

 /* ---------- Web-Push (Issue #67): Abonnement des Geräts ---------- */
 case 'push/key': out(['enabled' => pushOk(), 'key' => pushOk() ? vapid()['pub'] : null]); // öffentlicher VAPID-Schlüssel für pushManager.subscribe
 case 'push/subscribe': // endpoint, keys: {p256dh, auth}; ein Gerät gehört immer der zuletzt angemeldeten Person
  if (!pushOk()) fail('Push ist auf diesem Server nicht verfügbar (PHP ohne openssl/curl)');
  $ep = (string)($in['endpoint'] ?? ''); $k = $in['keys'] ?? [];
  if (!preg_match('~^https://~', $ep) || strlen($ep) > 1000 || strlen(b64ud((string)($k['p256dh'] ?? ''))) !== 65 || strlen(b64ud((string)($k['auth'] ?? ''))) < 16) fail('Ungültiges Abonnement');
  $db->prepare("DELETE FROM push_subs WHERE endpoint=?")->execute([$ep]);
  $db->prepare("INSERT INTO push_subs(user_id,endpoint,p256dh,auth,created) VALUES(?,?,?,?,?)")->execute([$uid, $ep, $k['p256dh'], $k['auth'], $now]);
  out(['ok' => true]);
 case 'push/unsubscribe': $db->prepare("DELETE FROM push_subs WHERE endpoint=? AND user_id=?")->execute([(string)($in['endpoint'] ?? ''), $uid]); out(['ok' => true]);
 case 'me/password': // eigenes Passwort ändern (aktuelles Passwort nötig)
  $s = $db->prepare("SELECT pass_hash FROM users WHERE id=?"); $s->execute([$uid]);
  if (!password_verify($in['old'] ?? '', $s->fetch()['pass_hash'])) fail('Das aktuelle Passwort ist falsch', 403);
  pwCheck($in['new'] ?? '');
  $db->prepare("UPDATE users SET pass_hash=?, must_change=0 WHERE id=?")->execute([password_hash($in['new'], PASSWORD_DEFAULT), $uid]);
  out(['ok' => true]);
 case 'me/settings': // nur übergebene Werte ändern. exercise_mode: Übungen wie scharfe Einsätze kritisch alarmieren; coord_sys: '' = Standard der Verwaltung, none = ausblenden
  if (isset($in['exercise_mode'])) $db->prepare("UPDATE users SET exercise_mode=? WHERE id=?")->execute([empty($in['exercise_mode']) ? 0 : 1, $uid]);
  if (isset($in['coord_sys'])) {
    $c = (string)$in['coord_sys']; if ($c !== '' && $c !== 'none' && !in_array($c, COORDS, true)) fail('Unbekanntes Koordinatensystem'); // none = Koordinaten ausblenden (nur persönlich)
    $db->prepare("UPDATE users SET coord_sys=? WHERE id=?")->execute([$c === '' ? null : $c, $uid]);
  }
  if (isset($in['ai_modes'])) { // AI-Assistent (Issue #111): {Funktion: Modus oder '' = höchster erlaubter}; nur bekannte Funktionen und Modi
    $v = []; foreach ((array)$in['ai_modes'] as $f => $m) if (isset(AI_FN[$f]) && is_string($m) && isset(AI_RANK[$m])) $v[$f] = $m;
    $db->prepare("UPDATE users SET ai_modes=? WHERE id=?")->execute([$v ? json_encode($v) : null, $uid]);
  }
  if (isset($in['nav_app'])) { // '' = Standard der Verwaltung
    $c = (string)$in['nav_app']; if ($c !== '' && !in_array($c, navApps(), true)) fail('Diese Navigations-App ist nicht verfügbar');
    $db->prepare("UPDATE users SET nav_app=? WHERE id=?")->execute([$c === '' ? null : $c, $uid]);
  }
  out(['ok' => true]);

 /* ---------- Verwaltung (je nach Recht der Benutzerrollen, Issue #63) ---------- */
 case 'users': need('users', 'ex_plan'); // Übungsleiter (nur ex_plan) nur Name und Benutzername (Auswahl von Übungsleitung und Aufgebotenen)
  if (!can('users')) out(array_map(fn($r) => ints($r, ['id', 'active']), $db->query("SELECT id,username,name,active FROM users ORDER BY active DESC, name")->fetchAll()));
  $ur = []; foreach ($db->query("SELECT user_id,urole_id FROM user_uroles")->fetchAll() as $x) $ur[(int)$x['user_id']][] = (int)$x['urole_id'];
  $used = usersUsed(); // deletable = nie angemeldet, in keinen Daten, nicht man selbst (Issue #63)
  out(array_map(fn($r) => ints($r, ['id', 'active', 'exercise_mode', 'must_change', 'last_login']) + ['uroles' => $ur[(int)$r['id']] ?? [], 'editable' => ($e = userEditable((int)$r['id'])) ? 1 : 0, 'deletable' => $e && $r['last_login'] === null && !isset($used[(int)$r['id']]) && (int)$r['id'] !== $uid ? 1 : 0], $db->query("SELECT id,username,name,active,exercise_mode,must_change,last_login FROM users ORDER BY active DESC, name")->fetchAll()));
 case 'user/save': need('users'); // anlegen oder Name/Benutzerrollen ändern: uroles = Liste der Rollen-IDs (nicht übergeben = unverändert)
  $nm = trim($in['name'] ?? ''); $id = (int)($in['id'] ?? 0);
  if ($id) needUserEditable($id);
  $old = $id ? array_column(userRoles($id), 'id') : [];
  $new = isset($in['uroles']) ? array_values(array_unique(array_map('intval', (array)$in['uroles']))) : $old;
  foreach (array_merge(array_diff($new, $old), array_diff($old, $new)) as $rid) { // geänderte Rollen: nur mit eigenen Rechten
    $r = uroleRow($rid); if (!$r) fail('Unbekannte Benutzerrolle');
    if (!permsMine(rolePerms($r))) fail('Die Rolle «' . $r['name'] . '» hat Rechte, die du selbst nicht hast', 403);
    if ($id === $uid && $r['sys'] === 'admin' && !in_array($rid, $new, true)) fail('Die eigene Rolle Systemadministrator kann nicht entfernt werden');
  }
  if ($id) {
    if ($nm === '') fail('Name fehlt');
    $db->prepare("UPDATE users SET name=? WHERE id=?")->execute([$nm, $id]);
  } else {
    $un = trim($in['username'] ?? ''); if ($un === '') fail('Benutzername ist Pflicht');
    pwCheck($in['password'] ?? '');
    try { $db->prepare("INSERT INTO users(username,name,pass_hash,must_change) VALUES(?,?,?,1)")->execute([$un, $nm ?: $un, password_hash($in['password'], PASSWORD_DEFAULT)]); }
    catch (PDOException $e) { fail('Benutzername existiert bereits'); }
    $id = (int)$db->lastInsertId();
  }
  if ($new !== $old || !isset($in['id'])) {
    $db->beginTransaction();
    $db->prepare("DELETE FROM user_uroles WHERE user_id=?")->execute([$id]);
    $s = $db->prepare("INSERT INTO user_uroles(user_id,urole_id) VALUES(?,?)"); foreach ($new as $rid) $s->execute([$id, $rid]);
    if (!adminCount()) { $db->rollBack(); fail('Mindestens eine aktive Person muss die Rolle Systemadministrator behalten'); }
    $db->commit();
  }
  out(['ok' => true, 'id' => $id]);
 case 'user/password': need('users'); // zurücksetzen: Person wird abgemeldet und muss bei der nächsten Anmeldung ein eigenes Passwort setzen
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Passwort bitte über «Passwort ändern» setzen');
  needUserEditable($id);
  pwCheck($in['password'] ?? '');
  $s = $db->prepare("UPDATE users SET pass_hash=?, must_change=1, token=NULL WHERE id=?"); $s->execute([password_hash($in['password'], PASSWORD_DEFAULT), $id]);
  $db->prepare("DELETE FROM push_subs WHERE user_id=?")->execute([$id]); // abgemeldet: Geräte erhalten keine Push-Nachrichten mehr
  if (!$s->rowCount()) fail('Benutzer nicht gefunden', 404);
  out(['ok' => true]);
 case 'user/delete': need('users'); // nur Personen, die sich nie angemeldet haben und in keinen Daten vorkommen; sonst deaktivieren
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Konto kann nicht gelöscht werden');
  needUserEditable($id);
  $s = $db->prepare("SELECT last_login FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch(); $s->closeCursor();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  if ($u['last_login'] !== null || isset(usersUsed()[$id])) fail('Diese Person hat sich schon angemeldet oder kommt in Einsatzdaten vor; bitte deaktivieren');
  $db->beginTransaction();
  foreach (['user_uroles', 'push_subs'] as $t) $db->prepare("DELETE FROM $t WHERE user_id=?")->execute([$id]);
  $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
  foreach ($db->query("SELECT id,leads,invite FROM ex_plans")->fetchAll() as $p) { // aus Übungsleitung und Aufgebot vorbereiteter Übungen entfernen
    $fx = fn($j) => $j === null ? null : json_encode(array_values(array_filter((array)json_decode($j, true), fn($x) => (int)$x !== $id)));
    if ($fx($p['leads']) !== $p['leads'] || $fx($p['invite']) !== $p['invite']) $db->prepare("UPDATE ex_plans SET leads=?, invite=? WHERE id=?")->execute([$fx($p['leads']), $fx($p['invite']), $p['id']]);
  }
  if (!adminCount()) { $db->rollBack(); fail('Mindestens eine aktive Person muss die Rolle Systemadministrator behalten'); }
  $db->commit();
  out(['ok' => true]);
 case 'user/active': need('users'); // Benutzer entfernen (deaktivieren) oder reaktivieren, Einsatzdaten bleiben erhalten
  $id = (int)($in['id'] ?? 0); $a = empty($in['active']) ? 0 : 1;
  if ($id === $uid) fail('Das eigene Konto kann nicht deaktiviert werden');
  needUserEditable($id);
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  $db->prepare("UPDATE users SET active=?" . ($a ? '' : ', token=NULL') . " WHERE id=?")->execute([$a, $id]);
  if (!$a) $db->prepare("DELETE FROM push_subs WHERE user_id=?")->execute([$id]); // keine Push-Nachrichten mehr an deaktivierte Personen
  if (!$a && ($inc = activeIncident())) { // aus laufendem Einsatz austragen
    $p = part($inc['id'], $id);
    if ($p && $p['left_at'] === null && $p['mode'] !== 'declined') {
      $db->prepare("UPDATE participants SET left_at=?, role_id=NULL WHERE incident_id=? AND user_id=?")->execute([$now, $inc['id'], $id]);
      logev($inc['id'], $uid, 'kick', 'hat ' . $u['name'] . ' aus dem Einsatz entfernt (Konto deaktiviert)');
    }
  }
  out(['ok' => true]);
 case 'me/photo': // kleines Bild als Data-URL (Client verkleinert auf ca. 96px)
  if (strlen($in['photo'] ?? '') > 200000) fail('Bild zu gross');
  $db->prepare("UPDATE users SET photo=? WHERE id=?")->execute([$in['photo'], $uid]);
  out(['ok' => true]);
 /* ---------- Benutzerrollen (Issue #63) ---------- */
 case 'uroles': need('users', 'uroles'); // alle Rollen mit Rechten und Anzahl Personen, dazu die Liste der Rechte (PERMS)
  $n = []; foreach ($db->query("SELECT x.urole_id,COUNT(*) c FROM user_uroles x JOIN users u ON u.id=x.user_id WHERE u.active=1 GROUP BY x.urole_id")->fetchAll() as $x) $n[(int)$x['urole_id']] = (int)$x['c'];
  out(['roles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sys' => $r['sys'], 'def' => (int)$r['def'], 'perms' => rolePerms($r), 'users' => $n[(int)$r['id']] ?? 0],
    $db->query("SELECT * FROM uroles ORDER BY sys IS NULL, name")->fetchAll()), 'perms' => array_map(fn($k, $v) => ['k' => $k, 'group' => $v[0], 'name' => $v[1], 'hint' => $v[2]], array_keys(PERMS), PERMS)]);
 case 'urole/save': need('uroles'); // anlegen oder ändern: id, name, perms (Liste), def (bei neuen Benutzern vorausgewählt); nur eigene Rechte
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $ps = array_values(array_unique(array_filter((array)($in['perms'] ?? []), 'is_string'))); foreach ($ps as $p) if (!isset(PERMS[$p])) fail('Unbekanntes Recht');
  $id = (int)($in['id'] ?? 0); $old = $id ? uroleRow($id) : null; if ($id && !$old) fail('Benutzerrolle nicht gefunden', 404);
  if ($old && $old['sys'] === 'admin') fail('Die Rolle Systemadministrator hat immer alle Rechte und kann nicht geändert werden');
  if (!permsMine(array_merge($ps, $old ? rolePerms($old) : []))) fail('Du kannst nur Rechte vergeben oder entziehen, die du selbst hast', 403);
  $s = $db->prepare("SELECT COUNT(*) c FROM uroles WHERE name=? AND id<>?"); $s->execute([$nm, $id]); if ($s->fetch()['c']) fail('Eine Benutzerrolle mit diesem Namen gibt es schon');
  $df = empty($in['def']) ? 0 : 1;
  if ($id) $db->prepare("UPDATE uroles SET name=?,perms=?,def=? WHERE id=?")->execute([$nm, json_encode($ps), $df, $id]);
  else { $db->prepare("INSERT INTO uroles(name,perms,def) VALUES(?,?,?)")->execute([$nm, json_encode($ps), $df]); $id = (int)$db->lastInsertId(); }
  out(['ok' => true, 'id' => $id]);
 case 'urole/remove': need('uroles'); // entfernen, die Personen verlieren die Rechte dieser Rolle
  $r = uroleRow($in['id'] ?? 0); if (!$r) fail('Benutzerrolle nicht gefunden', 404);
  if ($r['sys'] === 'admin') fail('Die Rolle Systemadministrator kann nicht entfernt werden');
  if (!permsMine(rolePerms($r))) fail('Die Rolle hat Rechte, die du selbst nicht hast', 403);
  $db->prepare("DELETE FROM user_uroles WHERE urole_id=?")->execute([(int)$r['id']]); $db->prepare("DELETE FROM uroles WHERE id=?")->execute([(int)$r['id']]);
  out(['ok' => true]);
 case 'role/save': need('cfg_inc');
  if (!empty($in['id'])) $db->prepare("UPDATE roles SET name=?,color=?,is_lead=?,is_post=?,active=?,can_message=? WHERE id=?")->execute([$in['name'], $in['color'], (int)$in['is_lead'], (int)($in['is_post'] ?? 0), (int)($in['active'] ?? 1), (int)($in['can_message'] ?? 1), $in['id']]);
  else $db->prepare("INSERT INTO roles(name,color,is_lead,can_message) VALUES(?,?,?,?)")->execute([$in['name'], $in['color'] ?: '#888888', (int)($in['is_lead'] ?? 0), (int)($in['can_message'] ?? 1)]);
  out(['ok' => true]);
 case 'settings': need('cfg_inc', 'cfg_func', 'cfg_map', 'cfg_my'); out(['my_runs' => myRuns(), 'my_runs_opts' => MY_RUNS, 'my_btns' => myBtns(), 'my_btns_opts' => MY_BTNS, 'msg_no_role' => (int)setting('msg_no_role', '1'), 'msg_read_all' => (int)setting('msg_read_all', '0'), 'coord_sys' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'rec_real' => recDefault()[0], 'rec_ex' => recDefault()[1], 'nav_apps' => array_map(fn($k) => ['k' => $k, 'name' => NAV_APPS[$k], 'on' => in_array($k, navApps(), true)], array_keys(NAV_APPS)), 'nav_default' => navDefault(), 'features' => array_map(fn($k, $r, $e) => ['k' => $k, 'name' => FEATURES[$k], 'real' => $r, 'ex' => $e], array_keys(FEATURES), features(0), features(1)), 'overpass_ttl' => (int)setting('overpass_ttl', '120'), 'overpass_urls' => overpassUrls(), 'overpass_default' => OVERPASS, 'overpass_on' => (int)setting('overpass_on', '1'), 'overpass_off' => overpassOff(),
   'air' => airCfg(), 'air_default' => AIR_DEFAULT + array_map(fn($x) => $x[0], AIR_NUM), 'air_num' => AIR_NUM, 'air_filters' => AIR_FILTERS, 'air_app' => AIR_APP, 'air_sources' => array_map(fn($s) => ['name' => $s['name'], 'site' => $s['site'], 'note' => $s['note']], AIR_SOURCES),
   'air_status' => json_decode((string)@file_get_contents(__DIR__ . '/data/air/status.json'), true) ?: (object)[]]); // Helikopter (Issue #73)
 case 'settings/save': // jede übergebene Einstellung braucht ihr Recht (SETTING_PERMS)
  foreach (SETTING_PERMS as $k => $p) if (isset($in[$k])) need($p);
  if (isset($in['ai'])) fail('Den AI-Assistenten über ai/cfg/save einstellen');
  if (isset($in['air'])) setSetting('air', json_encode(airIn($in['air']), JSON_UNESCAPED_UNICODE)); // Helikopter (Issue #73)
  if (isset($in['my_runs'])) { if (!is_string($in['my_runs']) || !isset(MY_RUNS[$in['my_runs']])) fail('Ungültige Einstellung'); setSetting('my_runs', (string)$in['my_runs']); } // Meine Einsätze (Issue #61)
  if (isset($in['my_btns'])) { if (!is_array($in['my_btns'])) fail('Ungültige Einstellung'); $b = myBtns(); foreach (MY_BTNS as $k => $_) if (array_key_exists($k, $in['my_btns'])) $b[$k] = (int)!empty($in['my_btns'][$k]); setSetting('my_btns', json_encode($b)); }
  if (isset($in['overpass_ttl'])) { // Haltedauer des Overpass-Caches in Minuten, 0 = kein Cache, höchstens 7 Tage
    if (!is_numeric($in['overpass_ttl']) || (int)$in['overpass_ttl'] < 0 || (int)$in['overpass_ttl'] > 10080) fail('Haltedauer: 0 bis 10080 Minuten');
    setSetting('overpass_ttl', (string)(int)$in['overpass_ttl']);
  }
  if (isset($in['overpass_urls'])) { // Overpass-Server in der Reihenfolge der Abfrage, 1–8 https-Adressen; leere Liste = Standard (OVERPASS)
    $v = [];
    foreach ((array)$in['overpass_urls'] as $u) {
      $u = trim((string)$u);
      if (strlen($u) > 300 || !filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https://[^/?\#@]+/#i', $u)) fail('Ungültige Adresse: ' . mb_substr($u, 0, 80) . ' (nur https://…)');
      if (!in_array($u, $v, true)) $v[] = $u;
    }
    if (count($v) > 8) fail('Höchstens 8 Overpass-Server');
    setSetting('overpass_urls', $v ? json_encode($v, JSON_UNESCAPED_SLASHES) : '');
  }
  if (isset($in['overpass_off'])) // ausgeschaltete Server, nur hinterlegte (Issue #75)
    setSetting('overpass_off', json_encode(array_values(array_intersect(overpassUrls(), array_filter((array)$in['overpass_off'], 'is_string'))), JSON_UNESCAPED_SLASHES));
  if (isset($in['rd_speeds'])) { // 1–6 Geschwindigkeiten zwischen 1 und 200 km/h, sortiert und ohne Duplikate
    foreach ((array)$in['rd_speeds'] as $x) if (!is_numeric($x)) fail('Nur Zahlen sind erlaubt (km/h)');
    $v = array_values(array_unique(array_map(fn($x) => round((float)$x, 1), (array)$in['rd_speeds'])));
    if (!$v || count($v) > 6) fail('Bitte 1 bis 6 Geschwindigkeiten angeben');
    foreach ($v as $x) if ($x < 1 || $x > 200) fail('Geschwindigkeiten müssen zwischen 1 und 200 km/h liegen');
    sort($v); setSetting('rd_speeds', implode(',', $v));
  }
  foreach (['msg_no_role', 'msg_read_all', 'rec_real', 'rec_ex', 'overpass_on'] as $k) if (isset($in[$k])) setSetting($k, empty($in[$k]) ? '0' : '1');
  if (isset($in['nav_off'])) { // ausgeschaltete Navigations-Apps, mindestens eine bleibt
    $v = array_values(array_intersect(array_keys(NAV_APPS), array_filter((array)$in['nav_off'], 'is_string')));
    if (count($v) >= count(NAV_APPS)) fail('Mindestens eine Navigations-App muss eingeschaltet bleiben');
    setSetting('nav_off', json_encode($v));
  }
  if (isset($in['nav_default'])) { if (!in_array($in['nav_default'], navApps(), true)) fail('Der Standard muss eine eingeschaltete App sein'); setSetting('nav_default', $in['nav_default']); }
  if (isset($in['coord_sys'])) { if (!in_array($in['coord_sys'], COORDS, true)) fail('Unbekanntes Koordinatensystem'); setSetting('coord_sys', $in['coord_sys']); }
  if (isset($in['features'])) { // {real: [ausgeschaltete Schlüssel], ex: [...]}; gilt sofort, auch im laufenden Einsatz
    $o = [];
    foreach (['real', 'ex'] as $t) { $o[$t] = array_values(array_unique(array_filter((array)($in['features'][$t] ?? []), 'is_string'))); foreach ($o[$t] as $k) if (!array_key_exists($k, FEATURES)) fail('Unbekannte Funktion'); }
    setSetting('features', json_encode($o));
    // laufender Einsatz: Aufzeichnung beenden bzw. öffentlichen Link ungültig machen, wenn die Funktion für seine Art jetzt aus ist
    if ($inc = activeIncident()) {
      if ((int)$inc['record'] && !feature($inc, 'rec')) { setRec((int)$inc['id'], 0); logev($inc['id'], $uid, 'rec', 'beendet die Aufzeichnung (Funktion in der Verwaltung ausgeschaltet)'); }
      if ($inc['public_token'] !== null && !feature($inc, 'pub')) { $db->prepare("UPDATE incidents SET public_token=NULL WHERE id=?")->execute([$inc['id']]); logev($inc['id'], $uid, 'pub', 'schaltet die öffentliche Ansicht aus (Funktion in der Verwaltung ausgeschaltet)'); }
    }
  }
  out(['ok' => true]);
 case 'ext_templates': need('cfg_inc', 'ex_plan'); // Vorlagen für externe Einsatzkräfte, auch deaktivierte (Übungsleiter: Übernahme ins Element «Neue externe Einsatzkraft»)
  out(array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'active']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon,active FROM ext_templates ORDER BY active DESC, name")->fetchAll()));
 case 'ext_template/save': need('cfg_inc'); // anlegen oder ändern: name, descr, role_id, abbr, color, lat/lon (beide leer = Standort beim Erfassen wählen), active
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $de = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500); $rid = roleIn($in['role_id'] ?? null); [$ab, $co] = extStyle($in);
  $ll = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? llIn($in['lat'], $in['lon']) : [null, null];
  $ac = array_key_exists('active', $in) && empty($in['active']) ? 0 : 1;
  if (!empty($in['id'])) $db->prepare("UPDATE ext_templates SET name=?,descr=?,role_id=?,abbr=?,color=?,lat=?,lon=?,active=? WHERE id=?")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac, (int)$in['id']]);
  else $db->prepare("INSERT INTO ext_templates(name,descr,role_id,abbr,color,lat,lon,active) VALUES(?,?,?,?,?,?,?,?)")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac]);
  out(['ok' => true]);
 case 'mode/save': need('cfg_inc'); // Einrückoption anlegen oder ändern
  $nm = trim($in['name'] ?? ''); if ($nm === '') fail('Name fehlt');
  $s = $db->prepare("SELECT * FROM modes WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $old = $s->fetch() ?: ['share_location' => 1, 'active' => 1, 'lead_rights' => 0, 'counts' => 1];
  $flag = fn($k) => isset($in[$k]) ? (empty($in[$k]) ? 0 : 1) : (int)$old[$k]; // nicht übergeben = bisheriger Wert
  $sh = $flag('share_location'); $ac = $flag('active'); $lr = $flag('lead_rights'); $co = $flag('counts');
  if (!empty($in['id'])) {
    $id = (int)$in['id']; if (empty($old['id'])) fail('Einrückoption nicht gefunden', 404);
    $s = $db->prepare("SELECT COUNT(*) c FROM modes WHERE active=1 AND id<>?"); $s->execute([$id]);
    if (!$ac && !$s->fetch()['c']) fail('Mindestens eine Einrückoption muss aktiv bleiben');
    $db->prepare("UPDATE modes SET name=?,share_location=?,active=?,lead_rights=?,counts=? WHERE id=?")->execute([$nm, $sh, $ac, $lr, $co, $id]);
  } else {
    $db->prepare("INSERT INTO modes(code,name,share_location,active,lead_rights,counts) VALUES(?,?,?,1,?,?)")->execute(['neu-' . bin2hex(random_bytes(6)), $nm, $sh, $lr, $co]);
    $id = (int)$db->lastInsertId(); $db->prepare("UPDATE modes SET code=? WHERE id=?")->execute(['m' . $id, $id]);
  }
  out(['ok' => true]);
}
fail('Unbekannte Route', 404);
