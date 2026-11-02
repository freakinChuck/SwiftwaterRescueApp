<?php
// Swiftwater Rescue API – Aufruf: api/index.php?r=<route>
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('serialize_precision', '-1'); // Zahlen im JSON kurz ausgeben (47.481 statt 47.48100000000000164…), Hosting-Einstellung übersteuern
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $c = 400) { out(['error' => $m], $c); }

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
 "CREATE TABLE IF NOT EXISTS ext_templates(id $ai, name $tx, descr TEXT, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, active INT DEFAULT 1)",
 // Verwendung je Overpass-Server (Issue #45): src server = Abfrage vom Hosting, device = direkte Abfrage des Geräts (Rückfall); ok_ms = Summe der Antwortzeiten der erfolgreichen
 "CREATE TABLE IF NOT EXISTS overpass_stats(url $tx, src $tx, uses INT DEFAULT 0, ok INT DEFAULT 0, fails INT DEFAULT 0, ok_ms BIGINT DEFAULT 0, last_ok BIGINT NULL, last_fail BIGINT NULL, last_err $tx NULL, PRIMARY KEY(url, src))",
] as $s) $db->exec($s);
// Migrationen für ältere Installationen (schlagen bei vorhandenen Spalten still fehl)
try { $db->exec("ALTER TABLE roles ADD COLUMN is_post INT DEFAULT 0"); $db->exec("UPDATE roles SET is_post=1 WHERE name LIKE 'Sicherungs%'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN lead_rights INT DEFAULT 0"); $db->exec("UPDATE modes SET lead_rights=1 WHERE code='coordinator'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN counts INT DEFAULT 1"); $db->exec("UPDATE modes SET counts=0 WHERE code='coordinator'"); } catch (Throwable $e) {}
foreach (['roles' => ['can_message INT DEFAULT 1'], 'messages' => ['lat DOUBLE NULL', 'lon DOUBLE NULL', 'mark INT DEFAULT 0', 'mark_lat DOUBLE NULL', 'mark_lon DOUBLE NULL', 'unmarked BIGINT NULL'],'participants' => ['log_lat DOUBLE NULL', 'log_lon DOUBLE NULL', 'log_at BIGINT NULL', 'fast_at BIGINT NULL'], 'users' => ['exercise_mode INT DEFAULT 0', 'must_change INT DEFAULT 0', "coord_sys $tx NULL"], 'externals' => ['manual INT DEFAULT 0', "abbr $tx NULL", "color $tx NULL"], 'ext_templates' => ["abbr $tx NULL", "color $tx NULL"], 'incidents' => ['is_exercise INT DEFAULT 0', 'record INT DEFAULT 0', 'recorded INT DEFAULT 0', "public_token $tx NULL", 'public_anon INT DEFAULT 1', 'pls_at BIGINT NULL'], 'rescuees' => ['pls_lat DOUBLE NULL', 'pls_lon DOUBLE NULL', 'pls_at BIGINT NULL']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }
try { $db->exec("CREATE INDEX track_inc ON track(incident_id,ts)"); } catch (Throwable $e) {} // schlägt bei vorhandenem Index still fehl
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
// Abschaltbare Funktionen (Issue #35), je für scharfe Einsätze und Übungen getrennt (Abschnitt «Funktionen» in der Verwaltung).
// Neue Funktionen hier aufnehmen (Schlüssel => Bezeichnung) und ihre Routen in FEATURE_ROUTES; das Frontend liest features aus incident/state bzw. me.
const FEATURES = ['res' => 'Rettlinge', 'msg' => 'Nachrichten (mit Kartenmarkierungen)', 'poi' => 'Markierungen & Bereiche', 'ext' => 'Externe Einsatzkräfte',
                  'rd' => 'Werkzeug «Flussdistanz»', 'pub' => 'Öffentliche Ansicht', 'rec' => 'Aufzeichnung (Replay)'];
const FEATURE_ROUTES = ['incident/mark' => 'res', 'incident/mark/remove' => 'res', 'incident/rescuee/save' => 'res', 'incident/rescuee/locate' => 'res', 'incident/rescuee/rescued' => 'res', 'incident/rescuee/remove' => 'res',
                        'incident/message' => 'msg', 'incident/message/unmark' => 'msg', 'incident/poi' => 'poi', 'incident/poi/remove' => 'poi',
                        'incident/external/save' => 'ext', 'incident/external/remove' => 'ext', 'incident/public' => 'pub', 'incident/record' => 'rec'];
const OVERPASS_KEEP_S = 7 * 86400; // abgelaufene Einträge so lange als Rückfall behalten, falls Overpass nicht erreichbar ist

if ($route === 'login') {
  $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1"); $st->execute([$in['username'] ?? '']);
  $u = $st->fetch();
  if (!$u || !password_verify($in['password'] ?? '', $u['pass_hash'])) fail('Benutzername oder Passwort falsch', 401);
  $t = bin2hex(random_bytes(24));
  $db->prepare("UPDATE users SET token=? WHERE id=?")->execute([$t, $u['id']]);
  out(['token' => $t]);
}

// Öffentliche Ansicht (Public Mode): ohne Anmeldung, nur lesend, nur mit dem geheimen Link der Einsatzleitung (k = public_token).
// Geliefert wird nur, was zum Zuschauen nötig ist: keine Nachrichten, kein Log, keine Profilbilder, keine Benutzer-IDs.
// Anonym (public_anon=1): statt Namen nur Spezialrolle bzw. Einrückoption, Rettlinge nummeriert und ohne Beschreibung.
function pubIncident() { global $db; $k = (string)($_GET['k'] ?? '');
  $s = $db->prepare("SELECT * FROM incidents WHERE public_token=?"); $s->execute([$k]); $inc = $s->fetch(); $s->closeCursor();
  if (!preg_match('/^[0-9a-f]{32}$/', $k) || !$inc || !feature($inc, 'pub')) fail('Dieser Link ist ungültig oder die öffentliche Ansicht wurde beendet.', 404);
  return $inc; }
// Gewässernetz fürs Suchgebiet: nur um einen PLS des Suchgebiets (la/lo, siehe plsSources) des laufenden Einsatzes, Radius m wie in der Einsatz-App (netRadius), gleicher Cache
if ($route === 'public/net') {
  $inc = pubIncident(); if ($inc['ended_at'] !== null) fail('Der Einsatz ist beendet.', 404);
  $r = (int)(ceil(min(max(5000, (float)($_GET['m'] ?? 0)), 30000) / 1000) * 1000);
  $la = (float)($_GET['la'] ?? 0); $lo = (float)($_GET['lo'] ?? 0);
  $src = array_values(array_filter(plsSources($inc, incRes($inc)), fn($s) => abs($s['lat'] - $la) < 1e-6 && abs($s['lon'] - $lo) < 1e-6))[0] ?? fail('Kein PLS für das Suchgebiet', 404);
  overpassServe(overpassQuery(['around' => [$r, $src['lat'], $src['lon']]]));
}
if ($route === 'public/state') {
  $inc = pubIncident();
  $pi = ['message' => $inc['message'], 'is_exercise' => (int)$inc['is_exercise'], 'alarm_at' => (int)$inc['alarm_at'], 'ended_at' => $inc['ended_at'] === null ? null : (int)$inc['ended_at']];
  if ($inc['ended_at'] !== null) out(['incident' => $pi, 'now' => $now]);
  $id = (int)$inc['id']; $anon = (int)$inc['public_anon'] === 1;
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
  foreach (incRes($inc) as $n => $r) $res[] = ['name' => $anon ? 'Rettling ' . ($n + 1) : $r['name'], 'descr' => $anon ? null : $r['descr']] + array_intersect_key($r, array_flip(['id', 'lat', 'lon', 'loc_at', 'rescued_at', 'pls_lat', 'pls_lon', 'pls_at']));
  out(['incident' => $pi, 'sources' => plsSources($inc, $res), 'anon' => $anon ? 1 : 0, 'roles' => $roles, 'participants' => $parts, 'pois' => $pois, 'rescuees' => $res, 'coord_sys' => setting('coord_sys', 'lv95'), 'features' => features((int)$inc['is_exercise']), 'now' => $now]); // features: Fenster «Ansicht» (Issue #48)
}

$tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
$st = $db->prepare("SELECT id,username,name,is_admin,photo,exercise_mode,must_change,coord_sys FROM users WHERE token=? AND active=1"); $st->execute([$tok]);
$me = $tok ? $st->fetch() : null; $st->closeCursor(); // Lesezugriff sofort beenden, nicht erst am Ende des Requests
if (!$me) fail('Nicht angemeldet', 401);
$me = ints($me, ['id', 'is_admin', 'exercise_mode', 'must_change']); $uid = $me['id'];
// Nach einem Zurücksetzen durch die Verwaltung ist nur der Passwortwechsel erlaubt
if ($me['must_change'] && !in_array($route, ['me', 'me/password'])) fail('Bitte zuerst ein eigenes Passwort setzen', 403);

/* ---------- Hilfsfunktionen ---------- */
function ints($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k]; return $r; }
function floats($r, $ks) { foreach ($ks as $k) if (isset($r[$k])) $r[$k] = (float)$r[$k]; return $r; }
function admin() { global $me; if (!$me['is_admin']) fail('Nur für Administratoren', 403); }
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
function canManage($inc) { global $me, $uid; return $me['is_admin'] || (int)$inc['created_by'] === $uid || isLead((int)$inc['id']); }
function canFlow($inc) { return canManage($inc) || isActivePart((int)$inc['id']); }
function modeRow($code) { global $db; $s = $db->prepare("SELECT * FROM modes WHERE code=?"); $s->execute([$code]); return $s->fetch() ?: null; }
function modeShares($code) { $m = modeRow($code); return !$m || (int)$m['share_location']; }
function setting($k, $d) { global $db; $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetch(); return $r ? $r['v'] : $d; }
function setSetting($k, $v) { global $db, $my; $db->prepare($my ? "INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)" : "INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]); }
// Nachrichten senden: nur wer den Einsatz angenommen hat; wer den Einsatz verwalten darf, immer;
// sonst je nach Spezialrolle (roles.can_message) bzw. Einstellung für Personen ohne Rolle (msg_no_role)
function canMessage($inc) {
  global $db; $p = part((int)$inc['id']);
  if (!$p || $p['mode'] === 'declined' || $p['left_at'] !== null) return false;
  if (canManage($inc)) return true;
  if ($p['role_id'] === null) return (bool)(int)setting('msg_no_role', '1');
  $s = $db->prepare("SELECT can_message FROM roles WHERE id=?"); $s->execute([$p['role_id']]); $r = $s->fetch();
  return !$r || (bool)(int)$r['can_message'];
}
function activeIncident() { global $db; return $db->query("SELECT * FROM incidents WHERE ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
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
  // Sperre je Abfrage: gleichzeitige Anfragen warten auf die erste und lesen danach den Cache, statt selbst zu Overpass zu gehen
  $lk = @fopen("$file.lock", 'c'); if ($lk) flock($lk, LOCK_EX);
  $hit();
  foreach (array_merge(glob("$dir/*.json") ?: [], glob("$dir/*.lock") ?: []) as $x) if ($now - filemtime($x) > max($ttl, OVERPASS_KEEP_S)) @unlink($x); // aufräumen, auch als Rückfall zu alt
  set_time_limit(90);
  $r = overpassFetch($q);
  if ($r !== null) { if ($ttl && file_put_contents("$file.tmp", $r) !== false) rename("$file.tmp", $file); header('X-Cache: MISS'); echo $r; }
  elseif (is_file($file)) { header('X-Cache: STALE'); readfile($file); } // Overpass nicht erreichbar: abgelaufene Daten sind besser als keine
  else out(['error' => 'Overpass antwortet nicht', 'query' => $q, 'servers' => overpassUrls()], 502); // mit Abfrage und Servern: das Gerät fragt Overpass dann selbst (overpassNet in common.js)
  exit;
}
// Rückfall (Issue #28): Vom Hosting aus lehnt Overpass oft ab, vom Gerät aus nicht. Das Gerät liefert sein Ergebnis hier für den Cache
// aller ab. Der Schlüssel kommt aus denselben Parametern wie bei overpass; gespeichert werden nur geprüfte Wege mit den nötigen Feldern.
function overpassPut($q, $els) {
  $ttl = max(0, (int)setting('overpass_ttl', '120')) * 60;
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
  foreach (overpassUrls() as $url) {
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
function logRows($id, $asc = false) {
  global $db;
  $s = $db->prepare("SELECT l.id,l.ts,l.kind,l.text,u.name FROM incident_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.incident_id=? AND l.kind<>'message' ORDER BY l.id DESC LIMIT 500");
  $s->execute([$id]);
  $rows = array_map(fn($r) => ints($r, ['id', 'ts']), $s->fetchAll());
  $names = $db->query("SELECT id,name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
  $m = $db->prepare("SELECT m.id,m.created,m.text,m.recipients,m.lat,m.lon,m.mark,m.mark_lat,m.mark_lon,u.name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? ORDER BY m.id DESC LIMIT 500");
  $m->execute([$id]);
  $ll = fn($a, $b) => sprintf('%.5F, %.5F', $a, $b);
  foreach ($m->fetchAll() as $r) {
    $to = $r['recipients'] === null ? 'alle' : implode(', ', array_map(fn($u) => $names[$u] ?? '?', json_decode($r['recipients'], true) ?: []));
    $extra = ($r['lat'] !== null ? ' · Standort ' . $ll($r['lat'], $r['lon']) : '') . ($r['mark_lat'] !== null ? ' · Kartenmarkierung ' . $ll($r['mark_lat'], $r['mark_lon']) . ((int)$r['mark'] ? '' : ' (entfernt)') : '');
    $rows[] = ['id' => 1000000000 + (int)$r['id'], 'ts' => (int)$r['created'], 'kind' => 'message', 'text' => "Nachricht an $to: " . $r['text'] . $extra, 'name' => $r['name']];
  }
  usort($rows, fn($a, $b) => $asc ? [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']] : [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']]);
  return array_slice($rows, 0, 1000);
}

// Einrückoption ohne Standortfreigabe: Standortmeldungen werden nicht gespeichert (serverseitig erzwungen)
if ($route === 'incident/location' && ($inc = activeIncident()) && ($p = part($inc['id'])) && !modeShares($p['mode'])) out(['ok' => true, 'shared' => false]);

// ausgeschaltete Funktionen serverseitig sperren (FEATURE_ROUTES)
if (array_key_exists($route, FEATURE_ROUTES) && ($inc = activeIncident()) && !feature($inc, FEATURE_ROUTES[$route]))
  fail('Die Funktion «' . FEATURES[FEATURE_ROUTES[$route]] . '» ist für ' . ((int)$inc['is_exercise'] ? 'Übungen' : 'scharfe Einsätze') . ' ausgeschaltet', 403);

switch ($route) {
 case 'me': out(['me' => $me + ['coord_default' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'rec_default' => recDefault(), 'features' => ['real' => features(0), 'ex' => features(1)]]]); // coord_sys null = Standard der Verwaltung; rd_speeds für das Werkzeug Flussdistanz; features je Art (real, ex) für «Einsatz starten» und die Flussdistanz ohne Einsatz; rec_default für «Einsatz starten»
 case 'roles': out(array_map(fn($r) => ints($r, ['id', 'is_lead', 'is_post', 'can_message']), $db->query("SELECT id,name,color,is_lead,is_post,can_message FROM roles WHERE active=1 ORDER BY id")->fetchAll()));
 case 'modes': // alle, auch deaktivierte (für die Anzeige bestehender Teilnahmen)
  out(array_map(fn($r) => ints($r, ['id', 'share_location', 'active', 'lead_rights', 'counts']), $db->query("SELECT id,code,name,share_location,active,lead_rights,counts FROM modes ORDER BY id")->fetchAll()));

 case 'overpass': // Gewässernetz für Suchgebiet und Flussdistanz (alle angemeldeten Personen): around [m, lat, lon] oder bbox [s, w, n, e]; Antwort wie Overpass
  overpassServe(overpassQuery($in));
 case 'overpass/stat': // Ergebnisse der direkten Abfragen des Geräts (Rückfall bei 502) für die Statistik: results [{url, ok, ms, err}], nur hinterlegte Server
  foreach (array_slice((array)($in['results'] ?? []), 0, 8) as $x) {
    if (!is_array($x) || !in_array($x['url'] ?? null, overpassUrls(), true)) continue;
    opStat($x['url'], 'device', !empty($x['ok']), min(max(0, (float)($x['ms'] ?? 0)), 120000), empty($x['ok']) ? (mb_substr(trim((string)($x['err'] ?? '')), 0, 150) ?: 'Fehler') : null);
  }
  out(['ok' => true]);
 case 'overpass/stats': admin(); // Statistik für die Verwaltung (Gewässerdaten)
  out(['since' => (int)setting('overpass_stats_since', '0') ?: null, 'rows' => array_map(fn($r) => ints($r, ['uses', 'ok', 'fails', 'ok_ms', 'last_ok', 'last_fail']), $db->query("SELECT * FROM overpass_stats ORDER BY url, src")->fetchAll())]);
 case 'overpass/stats/reset': admin();
  $db->exec("DELETE FROM overpass_stats"); setSetting('overpass_stats_since', (string)time()); out(['ok' => true]);
 case 'overpass/put': // Ergebnis einer direkten Abfrage des Geräts (Rückfall bei 502), gleiche Parameter wie overpass plus elements
  overpassPut(overpassQuery($in), $in['elements'] ?? null);

 case 'incident/start':
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  if (trim($in['message'] ?? '') === '') fail('Die Meldung ist Pflicht');
  $at = !empty($in['alarm_at']) ? (int)$in['alarm_at'] : $now;
  if ($at > $now + 60) fail('Der Alarmeingang darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Alarmeingang liegt mehr als 7 Tage zurück');
  // PLS optional (Issue #24): ohne PLS gibt es ein Suchgebiet erst mit dem PLS eines Rettlings; Zeitpunkt des PLS = Alarmeingang
  $pls = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? plsIn($in, $at) : [null, null];
  $ex = empty($in['is_exercise']) ? 0 : 1; $rec = empty($in['record']) || !features($ex)['rec'] ? 0 : 1;
  $db->prepare("INSERT INTO incidents(message,lat,lon,alarm_at,created_by,is_exercise) VALUES(?,?,?,?,?,?)")->execute([$in['message'], $pls[0], $pls[1], $at, $uid, $ex]);
  $nid = (int)$db->lastInsertId();
  if ($rec) setRec($nid, 1);
  logev($nid, $uid, 'start', 'hat den Einsatz gestartet (' . ($ex ? 'Übung' : 'scharfer Einsatz') . ($rec ? ', mit Aufzeichnung' : '') . ($pls[0] === null ? ', ohne PLS' : '') . '): ' . $in['message']);
  out(['id' => $nid]);

 case 'incident/state': // Polling-Endpunkt: ?since=<letzte Nachrichten-ID>
  $inc = activeIncident();
  if (!$inc) {
    $last = $db->query("SELECT id,ended_at FROM incidents ORDER BY id DESC LIMIT 1")->fetch();
    out(['incident' => null, 'last_ended' => $last ?: null, 'features' => ['real' => features(0), 'ex' => features(1)]]); // features: Flussdistanz ohne Einsatz
  }
  $inc['id'] = (int)$inc['id']; $inc = floats($inc, ['lat', 'lon']); $inc['pls_at'] = plsAt($inc); $inc['alarm_at'] = (int)$inc['alarm_at']; $inc['created_by'] = (int)$inc['created_by']; $inc['is_exercise'] = (int)$inc['is_exercise']; $inc['record'] = (int)$inc['record']; $inc['recorded'] = (int)$inc['recorded'];
  if ($inc['flow_ms'] !== null) $inc['flow_ms'] = (float)$inc['flow_ms'];
  $id = $inc['id']; $since = (int)($_GET['since'] ?? 0); $F = features($inc['is_exercise']); // ausgeschaltete Funktionen liefern nichts
  $ps = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.lat,p.lon,p.loc_at,p.left_at,u.name,u.photo FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=?");
  $ps->execute([$id]);
  $isP = isActivePart($id);
  $msgs = []; $msgMarks = [];
  // Lesen: alle, die den Einsatz angenommen haben; mit Einstellung msg_read_all auch alle anderen angemeldeten Personen
  $canRead = $F['msg'] && ($isP || setting('msg_read_all', '0') === '1');
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
  $inc['public'] = $inc['public_token'] !== null ? 1 : 0; $inc['public_anon'] = (int)$inc['public_anon'];
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
       'marks' => $marks, 'messages' => $msgs, 'msg_marks' => $msgMarks,'me' => part($id) ?: null, 'is_lead' => isLead($id), 'can_manage' => canManage($inc), 'can_flow' => $cf, 'can_message' => $F['msg'] && canMessage($inc), 'can_read_messages' => $canRead, 'critical' => critical($inc), 'features' => $F,
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
 case 'incident/rescuee/save': // id fehlt = neu; name, descr; pls: {lat, lon, at} = eigener PLS, null = PLS des Einsatzes, fehlt = unverändert
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können Rettlinge erfassen', 403);
  $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $descr = mb_substr(trim((string)($in['descr'] ?? '')), 0, 1000);
  if ($name === '') fail('Name fehlt');
  $hasPls = array_key_exists('pls', $in); $pls = $hasPls && $in['pls'] !== null ? plsIn($in['pls'], plsAt($inc)) : [null, null, null];
  $plsTxt = fn($n) => $pls[0] === null ? "übernimmt für «{$n}» den PLS des Einsatzes" : sprintf('setzt den PLS von «%s» (%.5F, %.5F, %s)', $n, $pls[0], $pls[1], hm($pls[2]));
  if (!empty($in['id'])) {
    $r = rescuee($inc, $in['id']);
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

 case 'incident/rescuee/locate': // own=1: eigener geteilter Standort (serverseitig), sonst lat/lon (auf der Karte gewählt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!isActivePart($inc['id']) && !canManage($inc)) fail('Nur Personen, die den Einsatz angenommen haben, können den Standort markieren', 403);
  $r = rescuee($inc, $in['id'] ?? 0);
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
  if (!isActivePart($inc['id'])) fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');
  if (!canMessage($inc)) fail('Deine Rolle darf keine Nachrichten senden', 403);
  if (trim($in['text'] ?? '') === '') fail('Text fehlt');
  $s = $db->prepare("SELECT user_id FROM participants WHERE incident_id=? AND mode<>'declined' AND left_at IS NULL"); $s->execute([$inc['id']]);
  $act = array_map('intval', array_column($s->fetchAll(), 'user_id'));
  $to = null;
  if (!empty($in['to'])) { $to = array_values(array_intersect(array_map('intval', $in['to']), $act)); if (!$to) fail('Die gewählten Personen haben den Einsatz nicht angenommen'); }
  // Standort der sendenden Person (nur wenn sie ihn teilt); Markierung an mark_lat/mark_lon oder am eigenen Standort
  $p = part($inc['id']); $la = $p['lat'] !== null ? (float)$p['lat'] : null; $lo = $p['lon'] !== null ? (float)$p['lon'] : null;
  $mk = !empty($in['mark']) ? 1 : 0; $mla = $mlo = null;
  if ($mk) {
    $ok = isset($in['mark_lat'], $in['mark_lon']) && is_numeric($in['mark_lat']) && is_numeric($in['mark_lon']) && abs($in['mark_lat']) <= 90 && abs($in['mark_lon']) <= 180;
    [$mla, $mlo] = $ok ? [(float)$in['mark_lat'], (float)$in['mark_lon']] : [$la, $lo];
    if ($mla === null) fail('Für die Markierung ist ein Standort nötig: Position auf der Karte wählen');
  }
  $db->prepare("INSERT INTO messages(incident_id,from_user,text,recipients,created,lat,lon,mark,mark_lat,mark_lon) VALUES(?,?,?,?,?,?,?,?,?,?)")
     ->execute([$inc['id'], $uid, $in['text'], $to === null ? null : json_encode($to), $now, $la, $lo, $mk, $mla, $mlo]);
  out(['ok' => true]); // im Einsatzprotokoll erscheint die Nachricht über logRows()

 case 'incident/message/unmark': // Kartenmarkierung einer Nachricht entfernen: sendende Person oder Einsatzleitung
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  $s = $db->prepare("SELECT * FROM messages WHERE id=? AND incident_id=? AND mark=1"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $m = $s->fetch();
  if (!$m) fail('Markierung nicht gefunden', 404);
  if ((int)$m['from_user'] !== $uid && !canManage($inc)) fail('Nur wer die Nachricht gesendet hat oder die Einsatzleitung kann die Markierung entfernen', 403);
  $db->prepare("UPDATE messages SET mark=0, unmarked=? WHERE id=?")->execute([$now, $m['id']]);
  logev($inc['id'], $uid, 'markdel', 'entfernt die Kartenmarkierung der Nachricht «' . mb_substr($m['text'], 0, 60) . '»');
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

 case 'incident/public': // Öffentliche Ansicht (nur Einsatzleitung): on = ein/aus, anon = Namen ausblenden. Ausschalten macht den Link ungültig, beim nächsten Einschalten gibt es einen neuen.
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann die öffentliche Ansicht steuern', 403);
  $was = $inc['public_token'] !== null; $wasAnon = (int)$inc['public_anon'] === 1;
  $on = array_key_exists('on', $in) ? !empty($in['on']) : $was; $anon = array_key_exists('anon', $in) ? !empty($in['anon']) : $wasAnon;
  $t = $on ? ($inc['public_token'] ?? bin2hex(random_bytes(16))) : null;
  $db->prepare("UPDATE incidents SET public_token=?, public_anon=? WHERE id=?")->execute([$t, $anon ? 1 : 0, $inc['id']]);
  $how = $anon ? 'anonym, ohne Namen' : 'mit Namen';
  if ($on && !$was) logev($inc['id'], $uid, 'pub', "schaltet die öffentliche Ansicht ein ($how)");
  elseif (!$on && $was) logev($inc['id'], $uid, 'pub', 'schaltet die öffentliche Ansicht aus');
  elseif ($on && $anon !== $wasAnon) logev($inc['id'], $uid, 'pub', "stellt die öffentliche Ansicht um ($how)");
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
  out(['ok' => true]);

 case 'incident/log': admin(); out(logRows((int)($_GET['id'] ?? 0), true));

 case 'incident/replay': admin(); // alle Daten eines aufgezeichneten Einsatzes für das Replay in der Verwaltung
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)($_GET['id'] ?? 0)]); $inc = $s->fetch();
  if (!$inc) fail('Einsatz nicht gefunden', 404);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  $id = (int)$inc['id']; $q = function ($sql) use ($db, $id) { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
  // Spezialrolle: Stand am Ende (Rollenwechsel stehen im Log); joined = erste Annahme
  $parts = array_map(fn($r) => ints($r, ['user_id', 'role_id', 'left_at', 'joined']), $q(
    "SELECT p.user_id,p.mode,p.role_id,p.left_at,u.name,r.name role,r.color,m.name mode_name,(SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=p.incident_id AND l.user_id=p.user_id AND l.kind='accept') joined
     FROM participants p JOIN users u ON u.id=p.user_id LEFT JOIN roles r ON r.id=p.role_id LEFT JOIN modes m ON m.code=p.mode WHERE p.incident_id=? AND p.mode<>'declined'"));
  // Verlauf kompakt als [id, ts, lat, lon]
  $tr = ['u' => [], 'r' => []];
  foreach ($q("SELECT user_id,rescuee_id,ts,lat,lon FROM track WHERE incident_id=? ORDER BY ts,id") as $r)
    $r['user_id'] !== null ? $tr['u'][] = [(int)$r['user_id'], (int)$r['ts'], (float)$r['lat'], (float)$r['lon']] : $tr['r'][] = [(int)$r['rescuee_id'], (int)$r['ts'], (float)$r['lat'], (float)$r['lon']];
  $pois = array_map(fn($r) => ['pts' => json_decode($r['pts'], true)] + ints($r, ['id', 'created', 'removed']), $q("SELECT id,kind,name,color,pts,created,removed FROM pois WHERE incident_id=? ORDER BY id"));
  $res = array_map(fn($r) => floats(ints($r, ['id', 'created', 'removed', 'loc_at', 'rescued_at', 'pls_at']), ['lat', 'lon', 'pls_lat', 'pls_lon']), $q("SELECT id,name,descr,lat,lon,loc_at,rescued_at,created,removed,pls_lat,pls_lon,pls_at FROM rescuees WHERE incident_id=? ORDER BY id"));
  $marks = array_map(fn($r) => floats(ints($r, ['id', 'created', 'unmarked', 'mark']), ['mark_lat', 'mark_lon']), $q("SELECT m.id,m.text,m.created,m.mark,m.unmarked,m.mark_lat,m.mark_lon,u.name FROM messages m LEFT JOIN users u ON u.id=m.from_user WHERE m.incident_id=? AND m.mark_lat IS NOT NULL ORDER BY m.id"));
  $flow = array_map(fn($r) => ints($r, ['ts']), $q("SELECT ts,text FROM incident_log WHERE incident_id=? AND kind='flow' ORDER BY ts,id"));
  // externe Einsatzkräfte: Standort und Spezialrolle mit Stand Einsatzende
  $ext = array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'created', 'removed']), ['lat', 'lon']), $q("SELECT id,name,descr,role_id,abbr,color,lat,lon,created,removed FROM externals WHERE incident_id=? ORDER BY id"));
  $per = array_map(fn($r) => ints($r, ['start', 'stop']), $q("SELECT start,stop FROM rec_periods WHERE incident_id=? ORDER BY start,id"));
  $inc = floats(ints($inc, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded']), ['lat', 'lon', 'flow_ms']); $inc['pls_at'] = plsAt($inc);
  out(['incident' => $inc, 'periods' => $per, 'participants' => $parts, 'track' => $tr['u'], 'rtrack' => $tr['r'], 'pois' => $pois, 'rescuees' => $res, 'externals' => $ext, 'marks' => $marks, 'flow' => $flow, 'log' => logRows($id, true), 'now' => $now]);

 case 'incident/replay/delete': admin(); // Aufzeichnung eines Einsatzes löschen (Standortverläufe und Zeiträume); Log, Nachrichten usw. bleiben
  $s = $db->prepare("SELECT * FROM incidents WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $inc = $s->fetch();
  if (!$inc) fail('Einsatz nicht gefunden', 404);
  if (!(int)$inc['recorded']) fail('Für diesen Einsatz gibt es keine Aufzeichnung');
  foreach (['track', 'rec_periods'] as $t) $db->prepare("DELETE FROM $t WHERE incident_id=?")->execute([$inc['id']]);
  $db->prepare("UPDATE incidents SET record=0, recorded=0 WHERE id=?")->execute([$inc['id']]); // läuft der Einsatz noch, endet auch die Aufzeichnung
  logev($inc['id'], $uid, 'rec', 'löscht die Aufzeichnung' . ($inc['ended_at'] === null ? ' (Aufzeichnung beendet)' : ''));
  out(['ok' => true]);

 case 'stats': admin();
  $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'record', 'recorded', 'accepted', 'declined', 'first_accept', 'first_area']), $db->query(
   "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.created_by,i.is_exercise,i.record,i.recorded,u.name creator,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode<>'declined') accepted,
     (SELECT COUNT(*) FROM participants p WHERE p.incident_id=i.id AND p.mode='declined') declined,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='accept') first_accept,
     (SELECT MIN(ts) FROM incident_log l WHERE l.incident_id=i.id AND l.kind='area') first_area
    FROM incidents i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.id DESC")->fetchAll());
  $ended = array_filter($rows, fn($r) => $r['ended_at']);
  out(['incidents' => $rows, 'totals' => [
    'count' => count($rows), 'ended' => count($ended), 'exercises' => count(array_filter($rows, fn($r) => $r['is_exercise'])),
    'avg_duration' => avg(array_map(fn($r) => $r['ended_at'] - $r['alarm_at'], $ended)),
    'avg_accepted' => avg(array_column($rows, 'accepted')),
    'avg_first_accept' => avg(array_map(fn($r) => $r['first_accept'] === null ? null : $r['first_accept'] - $r['alarm_at'], $rows)),
    'avg_first_area' => avg(array_map(fn($r) => $r['first_area'] === null ? null : $r['first_area'] - $r['alarm_at'], $rows))]]);

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
  out(['ok' => true]);

 /* ---------- Verwaltung (nur Benutzerrolle Administrator) ---------- */
 case 'users': admin();
  out(array_map(fn($r) => ints($r, ['id', 'is_admin', 'active', 'exercise_mode', 'must_change']), $db->query("SELECT id,username,name,is_admin,active,exercise_mode,must_change FROM users ORDER BY active DESC, name")->fetchAll()));
 case 'user/save': admin(); // anlegen oder Name/Benutzerrolle ändern
  $adm = empty($in['is_admin']) ? 0 : 1; $nm = trim($in['name'] ?? '');
  if (!empty($in['id'])) {
    $id = (int)$in['id'];
    // Die eigene Rolle bleibt bestehen, damit immer mindestens ein Administrator übrig ist
    if ($id === $uid && !$adm) fail('Die eigene Administratorrolle kann nicht entfernt werden');
    if ($nm === '') fail('Name fehlt');
    $db->prepare("UPDATE users SET name=?,is_admin=? WHERE id=?")->execute([$nm, $adm, $id]);
  } else {
    $un = trim($in['username'] ?? ''); if ($un === '') fail('Benutzername ist Pflicht');
    pwCheck($in['password'] ?? '');
    try { $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,must_change) VALUES(?,?,?,?,1)")->execute([$un, $nm ?: $un, password_hash($in['password'], PASSWORD_DEFAULT), $adm]); }
    catch (PDOException $e) { fail('Benutzername existiert bereits'); }
  }
  out(['ok' => true]);
 case 'user/password': admin(); // zurücksetzen: Person wird abgemeldet und muss bei der nächsten Anmeldung ein eigenes Passwort setzen
  $id = (int)($in['id'] ?? 0);
  if ($id === $uid) fail('Das eigene Passwort bitte über «Passwort ändern» setzen');
  pwCheck($in['password'] ?? '');
  $s = $db->prepare("UPDATE users SET pass_hash=?, must_change=1, token=NULL WHERE id=?"); $s->execute([password_hash($in['password'], PASSWORD_DEFAULT), $id]);
  if (!$s->rowCount()) fail('Benutzer nicht gefunden', 404);
  out(['ok' => true]);
 case 'user/active': admin(); // Benutzer entfernen (deaktivieren) oder reaktivieren, Einsatzdaten bleiben erhalten
  $id = (int)($in['id'] ?? 0); $a = empty($in['active']) ? 0 : 1;
  if ($id === $uid) fail('Das eigene Konto kann nicht deaktiviert werden');
  $s = $db->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$id]); $u = $s->fetch();
  if (!$u) fail('Benutzer nicht gefunden', 404);
  $db->prepare("UPDATE users SET active=?" . ($a ? '' : ', token=NULL') . " WHERE id=?")->execute([$a, $id]);
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
 case 'role/save': admin();
  if (!empty($in['id'])) $db->prepare("UPDATE roles SET name=?,color=?,is_lead=?,is_post=?,active=?,can_message=? WHERE id=?")->execute([$in['name'], $in['color'], (int)$in['is_lead'], (int)($in['is_post'] ?? 0), (int)($in['active'] ?? 1), (int)($in['can_message'] ?? 1), $in['id']]);
  else $db->prepare("INSERT INTO roles(name,color,is_lead,can_message) VALUES(?,?,?,?)")->execute([$in['name'], $in['color'] ?: '#888888', (int)($in['is_lead'] ?? 0), (int)($in['can_message'] ?? 1)]);
  out(['ok' => true]);
 case 'settings': admin(); out(['msg_no_role' => (int)setting('msg_no_role', '1'), 'msg_read_all' => (int)setting('msg_read_all', '0'), 'coord_sys' => setting('coord_sys', 'lv95'), 'rd_speeds' => rdSpeeds(), 'rec_real' => recDefault()[0], 'rec_ex' => recDefault()[1], 'features' => array_map(fn($k, $r, $e) => ['k' => $k, 'name' => FEATURES[$k], 'real' => $r, 'ex' => $e], array_keys(FEATURES), features(0), features(1)), 'overpass_ttl' => (int)setting('overpass_ttl', '120'), 'overpass_urls' => overpassUrls(), 'overpass_default' => OVERPASS]);
 case 'settings/save': admin();
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
  if (isset($in['rd_speeds'])) { // 1–6 Geschwindigkeiten zwischen 1 und 200 km/h, sortiert und ohne Duplikate
    foreach ((array)$in['rd_speeds'] as $x) if (!is_numeric($x)) fail('Nur Zahlen sind erlaubt (km/h)');
    $v = array_values(array_unique(array_map(fn($x) => round((float)$x, 1), (array)$in['rd_speeds'])));
    if (!$v || count($v) > 6) fail('Bitte 1 bis 6 Geschwindigkeiten angeben');
    foreach ($v as $x) if ($x < 1 || $x > 200) fail('Geschwindigkeiten müssen zwischen 1 und 200 km/h liegen');
    sort($v); setSetting('rd_speeds', implode(',', $v));
  }
  foreach (['msg_no_role', 'msg_read_all', 'rec_real', 'rec_ex'] as $k) if (isset($in[$k])) setSetting($k, empty($in[$k]) ? '0' : '1');
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
 case 'ext_templates': admin(); // Vorlagen für externe Einsatzkräfte, auch deaktivierte
  out(array_map(fn($r) => floats(ints($r, ['id', 'role_id', 'active']), ['lat', 'lon']), $db->query("SELECT id,name,descr,role_id,abbr,color,lat,lon,active FROM ext_templates ORDER BY active DESC, name")->fetchAll()));
 case 'ext_template/save': admin(); // anlegen oder ändern: name, descr, role_id, abbr, color, lat/lon (beide leer = Standort beim Erfassen wählen), active
  $nm = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($nm === '') fail('Name fehlt');
  $de = mb_substr(trim((string)($in['descr'] ?? '')), 0, 500); $rid = roleIn($in['role_id'] ?? null); [$ab, $co] = extStyle($in);
  $ll = isset($in['lat'], $in['lon']) && $in['lat'] !== '' && $in['lon'] !== '' ? llIn($in['lat'], $in['lon']) : [null, null];
  $ac = array_key_exists('active', $in) && empty($in['active']) ? 0 : 1;
  if (!empty($in['id'])) $db->prepare("UPDATE ext_templates SET name=?,descr=?,role_id=?,abbr=?,color=?,lat=?,lon=?,active=? WHERE id=?")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac, (int)$in['id']]);
  else $db->prepare("INSERT INTO ext_templates(name,descr,role_id,abbr,color,lat,lon,active) VALUES(?,?,?,?,?,?,?,?)")->execute([$nm, $de, $rid, $ab, $co, $ll[0], $ll[1], $ac]);
  out(['ok' => true]);
 case 'mode/save': admin(); // Einrückoption anlegen oder ändern
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
