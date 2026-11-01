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
$ai = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tx = $my ? 'VARCHAR(190)' : 'TEXT';
foreach ([
 "CREATE TABLE IF NOT EXISTS users(id $ai, username $tx UNIQUE, name $tx, pass_hash $tx, is_admin INT DEFAULT 0, active INT DEFAULT 1, photo MEDIUMTEXT, token $tx, exercise_mode INT DEFAULT 0, must_change INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS roles(id $ai, name $tx, color $tx, is_lead INT DEFAULT 0, active INT DEFAULT 1, is_post INT DEFAULT 0, can_message INT DEFAULT 1)",
 "CREATE TABLE IF NOT EXISTS incidents(id $ai, message TEXT, lat DOUBLE, lon DOUBLE, alarm_at BIGINT, ended_at BIGINT NULL, flow_ms DOUBLE NULL, created_by INT, is_exercise INT DEFAULT 0)",
 "CREATE TABLE IF NOT EXISTS participants(id $ai, incident_id INT, user_id INT, mode $tx, role_id INT NULL, lat DOUBLE NULL, lon DOUBLE NULL, loc_at BIGINT NULL, left_at BIGINT NULL, log_lat DOUBLE NULL, log_lon DOUBLE NULL, log_at BIGINT NULL, fast_at BIGINT NULL, UNIQUE(incident_id,user_id))",
 // lat/lon: Standort der sendenden Person beim Senden; mark=1: als Markierung auf der Karte (mark_lat/mark_lon)
 "CREATE TABLE IF NOT EXISTS messages(id $ai, incident_id INT, from_user INT, text TEXT, recipients TEXT NULL, created BIGINT, lat DOUBLE NULL, lon DOUBLE NULL, mark INT DEFAULT 0, mark_lat DOUBLE NULL, mark_lon DOUBLE NULL)",
 "CREATE TABLE IF NOT EXISTS incident_log(id $ai, incident_id INT, ts BIGINT, user_id INT NULL, kind $tx, text TEXT)",
 "CREATE TABLE IF NOT EXISTS rescuee_marks(id $ai, incident_id INT, user_id INT, lat DOUBLE, lon DOUBLE, ts BIGINT, manual INT DEFAULT 0)",
 // Einrückoptionen beim Annehmen eines Einsatzes; participants.mode verweist auf code
 // lead_rights: Option mit den Rechten der Einsatzleitung, aber ohne Spezialrolle (z. B. Koordinator KP Rück)
 "CREATE TABLE IF NOT EXISTS modes(id $ai, code $tx UNIQUE, name $tx, share_location INT DEFAULT 1, active INT DEFAULT 1, lead_rights INT DEFAULT 0)",
 // Einstellungen der Verwaltung als Schlüssel/Wert, z. B. msg_no_role
 "CREATE TABLE IF NOT EXISTS settings(k $tx PRIMARY KEY, v TEXT)",
 // Positionen und Bereiche der Einsatzleitung: kind point|area, pts = JSON [[lat,lon],…]; removed = Zeitpunkt des Entfernens
 "CREATE TABLE IF NOT EXISTS pois(id $ai, incident_id INT, kind $tx, name $tx, color $tx, pts TEXT, created_by INT, created BIGINT, removed BIGINT NULL)",
] as $s) $db->exec($s);
// Migrationen für ältere Installationen (schlagen bei vorhandenen Spalten still fehl)
try { $db->exec("ALTER TABLE roles ADD COLUMN is_post INT DEFAULT 0"); $db->exec("UPDATE roles SET is_post=1 WHERE name LIKE 'Sicherungs%'"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE modes ADD COLUMN lead_rights INT DEFAULT 0"); $db->exec("UPDATE modes SET lead_rights=1 WHERE code='coordinator'"); } catch (Throwable $e) {}
foreach (['roles' => ['can_message INT DEFAULT 1'], 'messages' => ['lat DOUBLE NULL', 'lon DOUBLE NULL', 'mark INT DEFAULT 0', 'mark_lat DOUBLE NULL', 'mark_lon DOUBLE NULL'],'participants' => ['log_lat DOUBLE NULL', 'log_lon DOUBLE NULL', 'log_at BIGINT NULL', 'fast_at BIGINT NULL'], 'users' => ['exercise_mode INT DEFAULT 0', 'must_change INT DEFAULT 0'], 'incidents' => ['is_exercise INT DEFAULT 0']] as $t => $cs)
  foreach ($cs as $c) { try { $db->exec("ALTER TABLE $t ADD COLUMN $c"); } catch (Throwable $e) {} }
if (!$db->query("SELECT COUNT(*) c FROM roles")->fetch()['c']) {
  $st = $db->prepare("INSERT INTO roles(name,color,is_lead,is_post) VALUES(?,?,?,?)");
  foreach ([['Einsatzleitung','#d62828',1,0],['Rettungsboot','#1d7fd1',0,0],['Rettungsschlitten','#e6a100',0,0],['Sicherungs- und Beobachtungsposten','#2a9d5c',0,1]] as $r) $st->execute($r);
}
if (!$db->query("SELECT COUNT(*) c FROM modes")->fetch()['c']) { // bisherige feste Optionen, Codes bleiben für bestehende Einsätze gültig
  $st = $db->prepare("INSERT INTO modes(code,name,share_location,lead_rights) VALUES(?,?,?,?)");
  foreach ([['base','Anfahrt via Stützpunkt',1,0],['direct','Anfahrt direkt',1,0],['logistics','Logistik',1,0],['coordinator','Koordinator KP Rück',0,1]] as $r) $st->execute($r);
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

if ($route === 'login') {
  $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1"); $st->execute([$in['username'] ?? '']);
  $u = $st->fetch();
  if (!$u || !password_verify($in['password'] ?? '', $u['pass_hash'])) fail('Benutzername oder Passwort falsch', 401);
  $t = bin2hex(random_bytes(24));
  $db->prepare("UPDATE users SET token=? WHERE id=?")->execute([$t, $u['id']]);
  out(['token' => $t]);
}

$tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
$st = $db->prepare("SELECT id,username,name,is_admin,photo,exercise_mode,must_change FROM users WHERE token=? AND active=1"); $st->execute([$tok]);
$me = $tok ? $st->fetch() : null;
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
function logev($incId, $userId, $kind, $text) { global $db, $now; $db->prepare("INSERT INTO incident_log(incident_id,ts,user_id,kind,text) VALUES(?,?,?,?,?)")->execute([$incId, $now, $userId, $kind, $text]); }
function hav($a, $b, $c, $d) { $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = $p2 - $p1; $dl = deg2rad($d - $b); $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2; return 12742000 * asin(min(1, sqrt($x))); }
function avg($a) { $a = array_values(array_filter($a, fn($x) => $x !== null)); return $a ? round(array_sum($a) / count($a), 1) : null; }
function http_get($url) {
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'SwiftwaterRescue/0.1']); $r = curl_exec($c); curl_close($c); return $r; }
  return @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: SwiftwaterRescue/0.1\r\n"]]));
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

switch ($route) {
 case 'me': out(['me' => $me]);
 case 'roles': out(array_map(fn($r) => ints($r, ['id', 'is_lead', 'is_post', 'can_message']), $db->query("SELECT id,name,color,is_lead,is_post,can_message FROM roles WHERE active=1 ORDER BY id")->fetchAll()));
 case 'modes': // alle, auch deaktivierte (für die Anzeige bestehender Teilnahmen)
  out(array_map(fn($r) => ints($r, ['id', 'share_location', 'active', 'lead_rights']), $db->query("SELECT id,code,name,share_location,active,lead_rights FROM modes ORDER BY id")->fetchAll()));

 case 'incident/start':
  if (activeIncident()) fail('Es läuft bereits ein Einsatz');
  if (trim($in['message'] ?? '') === '' || !isset($in['lat'], $in['lon'])) fail('Meldung und Standort (PLS) sind Pflicht');
  $at = !empty($in['alarm_at']) ? (int)$in['alarm_at'] : $now;
  if ($at > $now + 60) fail('Der Alarmeingang darf nicht in der Zukunft liegen');
  if ($at < $now - 7 * 86400) fail('Der Alarmeingang liegt mehr als 7 Tage zurück');
  $ex = empty($in['is_exercise']) ? 0 : 1;
  $db->prepare("INSERT INTO incidents(message,lat,lon,alarm_at,created_by,is_exercise) VALUES(?,?,?,?,?,?)")->execute([$in['message'], (float)$in['lat'], (float)$in['lon'], $at, $uid, $ex]);
  $nid = (int)$db->lastInsertId();
  logev($nid, $uid, 'start', 'hat den Einsatz gestartet (' . ($ex ? 'Übung' : 'scharfer Einsatz') . '): ' . $in['message']);
  out(['id' => $nid]);

 case 'incident/state': // Polling-Endpunkt: ?since=<letzte Nachrichten-ID>
  $inc = activeIncident();
  if (!$inc) {
    $last = $db->query("SELECT id,ended_at FROM incidents ORDER BY id DESC LIMIT 1")->fetch();
    out(['incident' => null, 'last_ended' => $last ?: null]);
  }
  $inc['id'] = (int)$inc['id']; $inc['lat'] = (float)$inc['lat']; $inc['lon'] = (float)$inc['lon']; $inc['alarm_at'] = (int)$inc['alarm_at']; $inc['created_by'] = (int)$inc['created_by']; $inc['is_exercise'] = (int)$inc['is_exercise'];
  if ($inc['flow_ms'] !== null) $inc['flow_ms'] = (float)$inc['flow_ms'];
  $id = $inc['id']; $since = (int)($_GET['since'] ?? 0);
  $ps = $db->prepare("SELECT p.user_id,p.mode,p.role_id,p.lat,p.lon,p.loc_at,p.left_at,u.name,u.photo FROM participants p JOIN users u ON u.id=p.user_id WHERE p.incident_id=?");
  $ps->execute([$id]);
  $isP = isActivePart($id);
  $msgs = []; $msgMarks = [];
  // Lesen: alle, die den Einsatz angenommen haben; mit Einstellung msg_read_all auch alle anderen angemeldeten Personen
  $canRead = $isP || setting('msg_read_all', '0') === '1';
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
  $marks = array_map(fn($m) => ints(array_merge($m, ['lat' => (float)$m['lat'], 'lon' => (float)$m['lon']]), ['id', 'user_id', 'ts', 'manual']), $mk->fetchAll());
  // Positionen und Bereiche der Einsatzleitung: für alle sichtbar, immer vollständig (Entfernen kommt bei allen an)
  $po = $db->prepare("SELECT p.id,p.kind,p.name,p.color,p.pts,p.created_by,p.created,u.name by_name FROM pois p LEFT JOIN users u ON u.id=p.created_by WHERE p.incident_id=? AND p.removed IS NULL ORDER BY p.id");
  $po->execute([$id]);
  $pois = array_map(fn($p) => array_merge(ints($p, ['id', 'created_by', 'created']), ['pts' => json_decode($p['pts'], true) ?: []]), $po->fetchAll());
  $cf = canFlow($inc);
  out(['incident' => $inc, 'pois' => $pois,
       'participants' => array_map(function ($p) { foreach (['lat', 'lon'] as $k) if ($p[$k] !== null) $p[$k] = (float)$p[$k]; return ints($p, ['user_id', 'role_id', 'left_at', 'loc_at']); }, $ps->fetchAll()),
       'marks' => $marks, 'messages' => $msgs, 'msg_marks' => $msgMarks,'me' => part($id) ?: null, 'is_lead' => isLead($id), 'can_manage' => canManage($inc), 'can_flow' => $cf, 'can_message' => canMessage($inc), 'can_read_messages' => $canRead, 'critical' => critical($inc),
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
  logev($inc['id'], $uid, 'flow', 'setzt die Fliessgeschwindigkeit auf ' . $v . ' m/s (' . (($in['src'] ?? '') === 'BAFU-Vorschlag' ? 'BAFU-Vorschlag' : 'manuell') . ')');
  out(['ok' => true]);

 case 'flow/suggest': // Vorschlag aus BAFU-Abflussdaten (via api.existenz.ch), grobe Schätzung
  $lat = (float)($in['lat'] ?? 0); $lon = (float)($in['lon'] ?? 0); $river = mb_strtolower(trim($in['river'] ?? ''));
  $cache = __DIR__ . '/data/hydro_locations.json'; $q = 'app=swiftwater.rescue&version=0.1';
  $raw = (is_file($cache) && filemtime($cache) > $now - 7 * 86400) ? file_get_contents($cache) : http_get("https://api.existenz.ch/apiv1/hydro/locations?$q");
  $pl = json_decode($raw ?: '', true)['payload'] ?? null;
  if (!is_array($pl) || !$pl) fail('BAFU-Stationsliste nicht abrufbar', 502);
  if (!is_file($cache) || filemtime($cache) <= $now - 7 * 86400) @file_put_contents($cache, $raw);
  $c = [];
  foreach ($pl as $key => $e) {
    if (!is_array($e)) continue;
    $la = $lo = null;
    array_walk_recursive($e, function ($v, $k) use (&$la, &$lo) { $k = strtolower((string)$k); if (!is_numeric($v)) return; if ($k === 'lat' || $k === 'latitude') $la = (float)$v; if ($k === 'lon' || $k === 'lng' || $k === 'longitude') $lo = (float)$v; });
    if ($la === null || $lo === null) continue;
    $d = hav($lat, $lon, $la, $lo); if ($d > 25000) continue;
    $nm = is_string($e['name'] ?? null) ? $e['name'] : (string)$key;
    $c[] = ['id' => (string)($e['id'] ?? $key), 'd' => $d, 'name' => $nm, 'm' => $river !== '' && str_contains(mb_strtolower(json_encode($e, JSON_UNESCAPED_UNICODE)), $river)];
  }
  if (!$c) fail('Keine BAFU-Messstation innerhalb von 25 km gefunden', 404);
  usort($c, fn($a, $b) => [$b['m'], $a['d']] <=> [$a['m'], $b['d']]);
  $c = array_slice($c, 0, 6);
  $lt = json_decode(http_get('https://api.existenz.ch/apiv1/hydro/latest?locations=' . implode(',', array_column($c, 'id')) . "&parameters=flow&$q") ?: '', true)['payload'] ?? null;
  if (!is_array($lt)) fail('BAFU-Abflussdaten nicht abrufbar', 502);
  $flow = []; foreach ($lt as $r) if (($r['par'] ?? '') === 'flow' && is_numeric($r['val'] ?? null)) $flow[(string)$r['loc']] = (float)$r['val'];
  foreach ($c as $s) if (isset($flow[$s['id']]) && $flow[$s['id']] > 0) {
    $qv = $flow[$s['id']];
    out(['name' => $s['name'], 'station' => $s['id'], 'distance_km' => round($s['d'] / 1000, 1), 'q' => round($qv, 1), 'v' => max(0.3, round(0.8 * pow($qv, 0.18), 1)), 'same_river' => $s['m']]);
  }
  fail('Keine aktuellen Abflussdaten in der Nähe verfügbar', 404);

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
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Positionen und Bereiche markieren', 403);
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
  if ($kind === 'point' ? count($pts) !== 1 : count($pts) < 3 || count($pts) > 200) fail($kind === 'point' ? 'Für eine Position ist genau ein Punkt nötig' : 'Ein Bereich braucht 3 bis 200 Punkte');
  $db->prepare("INSERT INTO pois(incident_id,kind,name,color,pts,created_by,created) VALUES(?,?,?,?,?,?,?)")->execute([$inc['id'], $kind, $name, $col, json_encode($pts), $uid, $now]);
  $pid = (int)$db->lastInsertId();
  if ($kind === 'point') $txt = sprintf('markiert die Position «%s» (%.5F, %.5F)', $name, $pts[0][0], $pts[0][1]);
  else { // Fläche grob über lokale Projektion (Shoelace), reicht für die Angabe im Log
    $k = 111320 * cos(deg2rad($pts[0][0])); $a = 0; $n = count($pts);
    for ($j = 0; $j < $n; $j++) { $p = $pts[$j]; $q = $pts[($j + 1) % $n]; $a += $p[1] * $k * $q[0] * 111320 - $q[1] * $k * $p[0] * 111320; }
    $txt = sprintf('markiert den Bereich «%s» (%d Punkte, ca. %s ha)', $name, $n, number_format(abs($a) / 2 / 10000, 1, '.', "'"));
  }
  logev($inc['id'], $uid, 'poi', $txt);
  out(['ok' => true, 'id' => $pid]);

 case 'incident/poi/remove': // Position oder Bereich entfernen: nur Einsatzleitung (bleibt in der Datenbank, removed gesetzt)
  $inc = activeIncident(); if (!$inc) fail('Kein aktiver Einsatz');
  if (!canManage($inc)) fail('Nur die Einsatzleitung kann Positionen und Bereiche entfernen', 403);
  $s = $db->prepare("SELECT * FROM pois WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([(int)($in['id'] ?? 0), $inc['id']]); $p = $s->fetch();
  if (!$p) fail('Markierung nicht gefunden', 404);
  $db->prepare("UPDATE pois SET removed=? WHERE id=?")->execute([$now, $p['id']]);
  logev($inc['id'], $uid, 'poidel', ($p['kind'] === 'area' ? 'entfernt den Bereich «' : 'entfernt die Position «') . $p['name'] . '»');
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
  $db->prepare("UPDATE messages SET mark=0 WHERE id=?")->execute([$m['id']]);
  logev($inc['id'], $uid, 'markdel', 'entfernt die Kartenmarkierung der Nachricht «' . mb_substr($m['text'], 0, 60) . '»');
  out(['ok' => true]);

 case 'incident/end':
  $inc = activeIncident(); if (!$inc || !canManage($inc)) fail('Nur Einsatzleitung, Administratoren oder die Person, die den Einsatz gestartet hat, können den Einsatz beenden', 403);
  $db->prepare("UPDATE incidents SET ended_at=? WHERE id=?")->execute([$now, $inc['id']]);
  logev($inc['id'], $uid, 'end', 'hat den Einsatz beendet');
  out(['ok' => true]);

 case 'incident/log': admin(); out(logRows((int)($_GET['id'] ?? 0), true));

 case 'stats': admin();
  $rows = array_map(fn($r) => ints($r, ['id', 'alarm_at', 'ended_at', 'created_by', 'is_exercise', 'accepted', 'declined', 'first_accept', 'first_area']), $db->query(
   "SELECT i.id,i.message,i.alarm_at,i.ended_at,i.created_by,i.is_exercise,u.name creator,
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
 case 'me/settings': // Übungsmodus: Übungen wie scharfe Einsätze kritisch alarmieren
  $db->prepare("UPDATE users SET exercise_mode=? WHERE id=?")->execute([empty($in['exercise_mode']) ? 0 : 1, $uid]);
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
 case 'settings': admin(); out(['msg_no_role' => (int)setting('msg_no_role', '1'), 'msg_read_all' => (int)setting('msg_read_all', '0')]);
 case 'settings/save': admin();
  foreach (['msg_no_role', 'msg_read_all'] as $k) if (isset($in[$k])) setSetting($k, empty($in[$k]) ? '0' : '1');
  out(['ok' => true]);
 case 'mode/save': admin(); // Einrückoption anlegen oder ändern
  $nm = trim($in['name'] ?? ''); if ($nm === '') fail('Name fehlt');
  $s = $db->prepare("SELECT * FROM modes WHERE id=?"); $s->execute([(int)($in['id'] ?? 0)]); $old = $s->fetch() ?: ['share_location' => 1, 'active' => 1, 'lead_rights' => 0];
  $flag = fn($k) => isset($in[$k]) ? (empty($in[$k]) ? 0 : 1) : (int)$old[$k]; // nicht übergeben = bisheriger Wert
  $sh = $flag('share_location'); $ac = $flag('active'); $lr = $flag('lead_rights');
  if (!empty($in['id'])) {
    $id = (int)$in['id']; if (empty($old['id'])) fail('Einrückoption nicht gefunden', 404);
    $s = $db->prepare("SELECT COUNT(*) c FROM modes WHERE active=1 AND id<>?"); $s->execute([$id]);
    if (!$ac && !$s->fetch()['c']) fail('Mindestens eine Einrückoption muss aktiv bleiben');
    $db->prepare("UPDATE modes SET name=?,share_location=?,active=?,lead_rights=? WHERE id=?")->execute([$nm, $sh, $ac, $lr, $id]);
  } else {
    $db->prepare("INSERT INTO modes(code,name,share_location,active,lead_rights) VALUES(?,?,?,1,?)")->execute(['neu-' . bin2hex(random_bytes(6)), $nm, $sh, $lr]);
    $id = (int)$db->lastInsertId(); $db->prepare("UPDATE modes SET code=? WHERE id=?")->execute(['m' . $id, $id]);
  }
  out(['ok' => true]);
}
fail('Unbekannte Route', 404);
