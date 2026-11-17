<?php
// Mandantenfähigkeit (Issue #60): zentrale Plattform-Datenbank mit Mandanten (Organisationen), Konten, Mitgliedschaften und Gerätesitzungen.
// Jeder Mandant hat eine eigene Datenbank; Org 1 ist die bisherige Datenbank aus config.php (bestehende Installationen laufen unverändert weiter).
// Eingebunden in index.php vor dem Öffnen der Datenbank: wählt den Mandanten der Anfrage (Header X-Org, sonst ?o=) und setzt $cfg['dsn'] auf seine Datenbank.
// Die zentralen Tabellen liegen standardmässig in der Datenbank von Org 1 (Präfix pf_), optional getrennt über 'platform_dsn' in config.php.
// Anmeldedaten (Passwort, Token, Sitzungen) und Profil (Name, Bild, Telefon) gehören dem Konto (pf_idents) und werden in jede Mandanten-Datenbank gespiegelt
// (users.gid = Konto), damit der übrige Code unverändert über users.token bzw. sessions anmeldet. Ein Token gilt so für alle Mandanten der Person.

const PF_SHARE = [ // gemeinsame Einstellungen: Gruppe => [Bezeichnung, Schlüssel in settings, Eingaben in settings/save]
  'geo' => ['Geodaten-Quellen', ['postgis', 'postgis_pass', 'pgapi', 'pgapi_key', 'water_order', 'overpass_on', 'overpass_off', 'overpass_urls', 'overpass_ttl'], ['postgis', 'pgapi', 'water_order', 'overpass_on', 'overpass_off', 'overpass_urls', 'overpass_ttl']],
  'maps' => ['Kartenanbieter', ['maps'], ['maps']],
  'air' => ['Helikopter (Quellen und Filter)', ['air'], ['air']],
  'hydro' => ['Pegel & Abfluss (Quellen)', ['hydro'], ['hydro']],
];
const PF_MODES = ['org' => 'Je Organisation', 'over' => 'Plattform-Vorgabe, übersteuerbar', 'global' => 'Global (nur Plattform)'];
const PF_ALWAYS = ['vapid']; // immer gemeinsam: ein Browser hat nur ein Push-Abonnement für alle Mandanten, also ein VAPID-Schlüssel

$PF = new PDO($cfg['platform_dsn'] ?? $cfg['dsn'], $cfg['platform_dsn'] ?? null ? ($cfg['platform_user'] ?? null) : $cfg['db_user'], $cfg['platform_dsn'] ?? null ? ($cfg['platform_pass'] ?? null) : $cfg['db_pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$PFMY = str_starts_with($cfg['platform_dsn'] ?? $cfg['dsn'], 'mysql');
if (!$PFMY) { $PF->exec('PRAGMA journal_mode=WAL'); $PF->exec('PRAGMA busy_timeout=10000'); }
(function () use ($PF, $PFMY) {
  $ai = $PFMY ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT'; $tx = $PFMY ? 'VARCHAR(190)' : 'TEXT';
  foreach ([
    "CREATE TABLE IF NOT EXISTS pf_orgs(id $ai, slug $tx UNIQUE, name $tx, dsn TEXT NULL, db_user $tx NULL, db_pass $tx NULL, active INT DEFAULT 1, sort INT DEFAULT 0, created BIGINT)",
    "CREATE TABLE IF NOT EXISTS pf_idents(id $ai, username $tx UNIQUE, pass_hash $tx, must_change INT DEFAULT 0, name $tx, photo MEDIUMTEXT NULL, phone $tx NULL, token $tx NULL, last_login BIGINT NULL, platform_admin INT DEFAULT 0, created BIGINT)",
    "CREATE TABLE IF NOT EXISTS pf_members(id $ai, ident_id INT, org_id INT, user_id INT NULL, boot INT DEFAULT 0, created BIGINT)",
    "CREATE TABLE IF NOT EXISTS pf_sessions(id $ai, ident_id INT, token $tx UNIQUE, device $tx, platform $tx, created BIGINT, last_seen BIGINT, app_ver $tx NULL)",
    "CREATE TABLE IF NOT EXISTS pf_settings(k $tx PRIMARY KEY, v MEDIUMTEXT)",
  ] as $q) $PF->exec($PFMY ? $q : str_replace('MEDIUMTEXT', 'TEXT', $q));
  try { $PF->exec("CREATE UNIQUE INDEX pf_members_io ON pf_members(ident_id, org_id)"); } catch (Throwable $e) {}
  // Org 1 = die bisherige Datenbank, immer vorhanden
  if (!(int)$PF->query("SELECT COUNT(*) FROM pf_orgs")->fetchColumn())
    try { $PF->prepare("INSERT INTO pf_orgs(id,slug,name,dsn,active,sort,created) VALUES(1,'org1','Organisation',NULL,1,1,?)")->execute([time()]); } catch (Throwable $e) {}
})();

function pfSet($k, $d = null) { global $PF; $s = $PF->prepare("SELECT v FROM pf_settings WHERE k=?"); $s->execute([$k]); $v = $s->fetchColumn(); $s->closeCursor(); return $v === false ? $d : $v; }
function pfPut($k, $v) { global $PF, $PFMY; if ($v === null) { $PF->prepare("DELETE FROM pf_settings WHERE k=?")->execute([$k]); return; }
  $PF->prepare($PFMY ? "INSERT INTO pf_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)" : "INSERT INTO pf_settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, (string)$v]); }
// Modus einer Gruppe gemeinsamer Einstellungen; Gruppe und Modus eines Schlüssels
function pfMode($g) { $m = pfSet('share:' . $g, 'org'); return isset(PF_MODES[$m]) ? $m : 'org'; }
function pfKeyMode($k) { if (in_array($k, PF_ALWAYS, true)) return 'global'; foreach (PF_SHARE as $g => $x) if (in_array($k, $x[1], true)) return pfMode($g); return 'org'; }
function pfInGroup($in) { foreach (PF_SHARE as $g => $x) if (in_array($in, $x[2], true)) return $g; return null; } // Eingabe von settings/save → Gruppe

function pfOrg($id) { global $PF; $s = $PF->prepare("SELECT * FROM pf_orgs WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch() ?: null; $s->closeCursor(); return $r; }
function pfOrgs($all = false) { global $PF; return $PF->query("SELECT id,slug,name,active,sort FROM pf_orgs" . ($all ? '' : ' WHERE active=1') . " ORDER BY sort, id")->fetchAll(); }
// Datenbank eines Mandanten: Org 1 = config.php, sonst eigene DSN bzw. (SQLite) data/org<id>/swiftwater.sqlite
function pfDsn($o) { global $cfg; if ((int)$o['id'] === 1) return [$cfg['dsn0'], $cfg['db_user0'], $cfg['db_pass0']];
  if (trim((string)$o['dsn']) !== '') return [$o['dsn'], $o['db_user'], $o['db_pass']];
  if (!str_starts_with($cfg['dsn0'], 'sqlite')) throw new RuntimeException('Für diesen Mandanten ist keine Datenbank eingetragen');
  $d = __DIR__ . '/data/org' . (int)$o['id']; if (!is_dir($d)) @mkdir($d, 0775, true); return ['sqlite:' . $d . '/swiftwater.sqlite', null, null]; }
function pfData($id) { return (int)$id === 1 ? __DIR__ . '/data' : __DIR__ . '/data/org' . (int)$id; } // Ordner der Dateien je Mandant (Funk, Helikopter, Sperren)
// Verbindung zur Datenbank eines anderen Mandanten (zwischengespeichert); null, wenn sie (noch) nicht eingerichtet ist
function pfConn($id) { global $db, $PF; static $c = []; $id = (int)$id;
  if ($id === ORG_ID && isset($db) && $db instanceof PDO) return $db;
  if (array_key_exists($id, $c)) return $c[$id];
  $o = pfOrg($id); if (!$o) return $c[$id] = null;
  try { [$dsn, $u, $p] = pfDsn($o);
    if (str_starts_with($dsn, 'sqlite:') && !is_file(substr($dsn, 7))) return $c[$id] = null; // noch nie geöffnet: Schema fehlt, Mitglieder werden beim ersten Aufruf verknüpft
    $x = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!str_starts_with($dsn, 'mysql')) $x->exec('PRAGMA busy_timeout=10000');
    $x->query("SELECT gid FROM users LIMIT 1"); // Schema mit Konten-Spalte vorhanden?
    return $c[$id] = $x; } catch (Throwable $e) { return $c[$id] = null; } }
function pfUpsert($x, $k, $v) { $my = $x->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $x->prepare($my ? "INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)" : "INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]); }

// Konto zum Token (Browser-Token des Kontos oder Gerätesitzung)
function pfIdentByToken($t) { global $PF; if ($t === '') return null;
  $s = $PF->prepare("SELECT * FROM pf_idents WHERE token=?"); $s->execute([$t]); $r = $s->fetch(); $s->closeCursor(); if ($r) return $r;
  $s = $PF->prepare("SELECT i.* FROM pf_sessions s JOIN pf_idents i ON i.id=s.ident_id WHERE s.token=?"); $s->execute([$t]); $r = $s->fetch(); $s->closeCursor(); return $r ?: null; }
// Mitgliedschaften eines Kontos in aktiven Mandanten
function pfMemberOrgs($gid) { global $PF; $s = $PF->prepare("SELECT o.id,o.slug,o.name,m.user_id FROM pf_members m JOIN pf_orgs o ON o.id=m.org_id WHERE m.ident_id=? AND o.active=1 ORDER BY o.sort, o.id");
  $s->execute([(int)$gid]); return array_map(fn($r) => ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'user_id' => $r['user_id'] === null ? null : (int)$r['user_id']], $s->fetchAll()); }
function pfIsMember($gid, $oid) { foreach (pfMemberOrgs($gid) as $o) if ($o['id'] === (int)$oid) return true; return false; }

// Felder des Kontos in alle Mandanten spiegeln (users.gid): Name, Bild, Telefon, Benutzername, Passwort, Token
function pfSync($gid, $f) { global $PF; $gid = (int)$gid; if (!$gid || !$f) return;
  $ok = ['name', 'photo', 'phone', 'username', 'pass_hash', 'must_change', 'token', 'last_login'];
  $f = array_intersect_key($f, array_flip($ok)); if (!$f) return;
  $set = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
  $PF->prepare("UPDATE pf_idents SET $set WHERE id=?")->execute([...array_values($f), $gid]);
  foreach (pfMemberOrgs($gid) as $o) { $x = pfConn($o['id']); if (!$x) continue;
    try { $x->prepare("UPDATE users SET $set WHERE gid=?")->execute([...array_values($f), $gid]); } catch (Throwable $e) {} } }
// Gerätesitzung in allen Mandanten anlegen bzw. löschen (gleicher Token überall)
function pfSessAdd($gid, $t, $dev, $plat, $ver) { global $PF, $now; $n = time();
  $PF->prepare("INSERT INTO pf_sessions(ident_id,token,device,platform,created,last_seen,app_ver) VALUES(?,?,?,?,?,?,?)")->execute([(int)$gid, $t, $dev, $plat, $n, $n, $ver]);
  foreach (pfMemberOrgs($gid) as $o) { $x = pfConn($o['id']); if (!$x || !$o['user_id']) continue;
    try { $x->prepare("INSERT INTO sessions(user_id,token,device,platform,created,last_seen,app_ver) VALUES(?,?,?,?,?,?,?)")->execute([$o['user_id'], $t, $dev, $plat, $n, $n, $ver]); } catch (Throwable $e) {} }
  // höchstens 20 je Konto, die ältesten fallen weg
  $s = $PF->prepare("SELECT token FROM pf_sessions WHERE ident_id=? ORDER BY last_seen DESC, id DESC"); $s->execute([(int)$gid]);
  foreach (array_slice($s->fetchAll(PDO::FETCH_COLUMN), 20) as $old) pfSessDel($gid, $old); }
function pfSessDel($gid, $t = null) { global $PF; // $t null = alle Sitzungen des Kontos
  if ($t === null) $PF->prepare("DELETE FROM pf_sessions WHERE ident_id=?")->execute([(int)$gid]); else $PF->prepare("DELETE FROM pf_sessions WHERE token=?")->execute([$t]);
  foreach (pfMemberOrgs($gid) as $o) { $x = pfConn($o['id']); if (!$x || !$o['user_id']) continue;
    try { if ($t === null) { $x->prepare("DELETE FROM push_native WHERE user_id=?")->execute([$o['user_id']]); $x->prepare("DELETE FROM sessions WHERE user_id=?")->execute([$o['user_id']]); }
      else { $x->prepare("DELETE FROM push_native WHERE session_id IN (SELECT id FROM sessions WHERE token=?)")->execute([$t]); $x->prepare("DELETE FROM sessions WHERE token=?")->execute([$t]); } } catch (Throwable $e) {} } }

// Anfrage: Mandant wählen. Ohne Angabe bzw. ohne Mitgliedschaft im gewünschten: Org 1, wenn die Person dort Mitglied ist, sonst die erste Mitgliedschaft.
$cfg['dsn0'] = $cfg['dsn']; $cfg['db_user0'] = $cfg['db_user']; $cfg['db_pass0'] = $cfg['db_pass'];
(function () use (&$cfg) {
  $want = (int)($_SERVER['HTTP_X_ORG'] ?? ($_GET['o'] ?? 0)); $tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
  $o = $want ? pfOrg($want) : null; if ($o && !(int)$o['active']) $o = null;
  $id = pfIdentByToken($tok);
  if ($id) { $ms = pfMemberOrgs($id['id']);
    if ($ms && (!$o || !in_array((int)$o['id'], array_column($ms, 'id'), true))) {
      $first = $ms[0]['id']; foreach ($ms as $m) if ($m['id'] === 1) $first = 1;
      // nur beim Wunsch eines anderen Mandanten wechseln; ohne Wunsch (bestehende Geräte) wie bisher Org 1, falls Mitglied
      $o = pfOrg($first); } }
  if (!$o) $o = pfOrg(1);
  define('ORG_ID', (int)$o['id']); define('ORG_DATA', pfData($o['id']));
  [$cfg['dsn'], $cfg['db_user'], $cfg['db_pass']] = pfDsn($o);
  $GLOBALS['PF_IDENT'] = $id; header('X-Org: ' . ORG_ID);
})();

// Erstmalige Migration der bisherigen Installation (Org 1): Personen werden Konten, Sitzungen und VAPID-Schlüssel zentral, Systemadministratoren werden Plattform-Administratoren.
// Gemeinsame Einstellungen erhalten den Modus «übersteuerbar» mit den Werten von Org 1 als Vorgabe; Org 1 behält ihre eigenen Werte.
function pfMigrate() { global $db, $PF;
  if (ORG_ID !== 1) return; $s = $db->query("SELECT v FROM settings WHERE k='pf_mig'"); $done = $s->fetchColumn(); $s->closeCursor(); if ($done !== false) return;
  try { $db->exec("INSERT INTO settings(k,v) VALUES('pf_mig','0')"); } catch (Throwable $e) { return; } // Sperrzeile: nur eine Anfrage migriert
  $n = time(); $ad = [];
  try { foreach ($db->query("SELECT DISTINCT x.user_id FROM user_uroles x JOIN uroles r ON r.id=x.urole_id WHERE r.sys='admin'")->fetchAll(PDO::FETCH_COLUMN) as $x) $ad[(int)$x] = 1; } catch (Throwable $e) {}
  foreach ($db->query("SELECT * FROM users WHERE gid IS NULL AND (purged IS NULL OR purged=0)")->fetchAll() as $u) {
    $s = $PF->prepare("SELECT id FROM pf_idents WHERE username=?"); $s->execute([$u['username']]); $gid = $s->fetchColumn(); $s->closeCursor();
    if ($gid === false) { $PF->prepare("INSERT INTO pf_idents(username,pass_hash,must_change,name,photo,phone,token,last_login,platform_admin,created) VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$u['username'], $u['pass_hash'], (int)$u['must_change'], $u['name'], $u['photo'], $u['phone'] ?? null, $u['token'], $u['last_login'] ?? null, isset($ad[(int)$u['id']]) && (int)$u['active'] ? 1 : 0, $n]);
      $gid = (int)$PF->lastInsertId(); }
    $db->prepare("UPDATE users SET gid=? WHERE id=?")->execute([(int)$gid, $u['id']]);
    try { $PF->prepare("INSERT INTO pf_members(ident_id,org_id,user_id,created) VALUES(?,1,?,?)")->execute([(int)$gid, $u['id'], $n]); } catch (Throwable $e) {} }
  foreach ($db->query("SELECT s.*, u.gid FROM sessions s JOIN users u ON u.id=s.user_id")->fetchAll() as $x)
    try { $PF->prepare("INSERT INTO pf_sessions(ident_id,token,device,platform,created,last_seen,app_ver) VALUES(?,?,?,?,?,?,?)")->execute([(int)$x['gid'], $x['token'], $x['device'], $x['platform'], $x['created'], $x['last_seen'], $x['app_ver'] ?? null]); } catch (Throwable $e) {}
  $lv = function ($k) use ($db) { $s = $db->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $v = $s->fetchColumn(); $s->closeCursor(); return $v === false ? null : $v; };
  if (pfSet('vapid') === null && ($v = $lv('vapid')) !== null) pfPut('vapid', $v);
  foreach (PF_SHARE as $g => [, $keys]) { if (pfSet('share:' . $g) !== null) continue; pfPut('share:' . $g, 'over');
    foreach ($keys as $k) if (pfSet($k) === null && ($v = $lv($k)) !== null) pfPut($k, $v); }
  $name = ''; if (($l = json_decode((string)$lv('legal'), true)) && trim((string)($l['org'] ?? '')) !== '') $name = trim($l['org']);
  elseif (($a = json_decode((string)$lv('aar_cfg'), true)) && trim((string)($a['org'] ?? '')) !== '') $name = trim($a['org']);
  if ($name !== '') $PF->prepare("UPDATE pf_orgs SET name=? WHERE id=1 AND name='Organisation'")->execute([mb_substr($name, 0, 120)]);
  $db->exec("UPDATE settings SET v='1' WHERE k='pf_mig'"); }

// Neue Mitglieder dieses Mandanten (von der Plattform zugeordnet) als Personen anlegen: Konto, Token und Sitzungen übernehmen,
// Benutzerrollen mit «bei neuen vorausgewählt», beim ersten Administrator eines neuen Mandanten (boot) zusätzlich Systemadministrator.
function pfLink() { global $db, $PF;
  $s = $PF->prepare("SELECT m.id mid, m.boot, i.* FROM pf_members m JOIN pf_idents i ON i.id=m.ident_id WHERE m.org_id=? AND m.user_id IS NULL"); $s->execute([ORG_ID]); $rs = $s->fetchAll(); if (!$rs) return;
  foreach ($rs as $i) {
    $q = $db->prepare("SELECT id FROM users WHERE gid=? OR username=?"); $q->execute([$i['id'], $i['username']]); $uid = $q->fetchColumn(); $q->closeCursor();
    if ($uid === false) { $db->prepare("INSERT INTO users(username,name,pass_hash,is_admin,active,photo,token,must_change,phone,last_login,gid) VALUES(?,?,?,?,1,?,?,?,?,?,?)")
        ->execute([$i['username'], $i['name'], $i['pass_hash'], (int)$i['boot'], $i['photo'], $i['token'], (int)$i['must_change'], $i['phone'], $i['last_login'], $i['id']]);
      $uid = (int)$db->lastInsertId();
      $ur = $db->prepare("INSERT INTO user_uroles(user_id,urole_id) VALUES(?,?)");
      foreach ($db->query("SELECT id,sys,def FROM uroles")->fetchAll() as $r) if ((int)$r['def'] || ((int)$i['boot'] && $r['sys'] === 'admin')) $ur->execute([$uid, $r['id']]); }
    else { $uid = (int)$uid; $db->prepare("UPDATE users SET gid=?, active=1, token=?, pass_hash=?, must_change=? WHERE id=?")->execute([$i['id'], $i['token'], $i['pass_hash'], (int)$i['must_change'], $uid]); }
    $ss = $PF->prepare("SELECT * FROM pf_sessions WHERE ident_id=?"); $ss->execute([$i['id']]);
    foreach ($ss->fetchAll() as $x) try { $db->prepare("INSERT INTO sessions(user_id,token,device,platform,created,last_seen,app_ver) VALUES(?,?,?,?,?,?,?)")->execute([$uid, $x['token'], $x['device'], $x['platform'], $x['created'], $x['last_seen'], $x['app_ver']]); } catch (Throwable $e) {}
    $PF->prepare("UPDATE pf_members SET user_id=? WHERE id=?")->execute([$uid, $i['mid']]); } }

// Neues Konto für eine in der Verwaltung angelegte Person (Benutzername plattformweit eindeutig)
function pfIdentNew($username, $name, $hash, $phone = null) { global $PF;
  $s = $PF->prepare("SELECT id FROM pf_idents WHERE username=?"); $s->execute([$username]); if ($s->fetchColumn() !== false) return null;
  $PF->prepare("INSERT INTO pf_idents(username,pass_hash,must_change,name,phone,created) VALUES(?,?,1,?,?,?)")->execute([$username, $hash, $name, $phone, time()]); return (int)$PF->lastInsertId(); }
function pfMemberSet($gid, $uid) { global $PF;
  try { $PF->prepare("INSERT INTO pf_members(ident_id,org_id,user_id,created) VALUES(?,?,?,?)")->execute([(int)$gid, ORG_ID, (int)$uid, time()]); }
  catch (Throwable $e) { $PF->prepare("UPDATE pf_members SET user_id=? WHERE ident_id=? AND org_id=?")->execute([(int)$uid, (int)$gid, ORG_ID]); } }
// Mitgliedschaft in diesem Mandanten beenden (Person gelöscht); ohne weitere Mitgliedschaft und ohne Plattform-Recht auch das Konto
function pfMemberDrop($gid) { global $PF; if (!$gid) return;
  $PF->prepare("DELETE FROM pf_members WHERE ident_id=? AND org_id=?")->execute([(int)$gid, ORG_ID]);
  $s = $PF->prepare("SELECT COUNT(*) FROM pf_members WHERE ident_id=?"); $s->execute([(int)$gid]);
  if (!(int)$s->fetchColumn()) { $PF->prepare("DELETE FROM pf_idents WHERE id=? AND platform_admin=0")->execute([(int)$gid]); $PF->prepare("DELETE FROM pf_sessions WHERE ident_id=? AND NOT EXISTS (SELECT 1 FROM pf_idents WHERE id=?)")->execute([(int)$gid, (int)$gid]); } }
// Ist die Person nur in diesem Mandanten? Dann darf seine Verwaltung Passwort und Benutzernamen ändern, sonst nur die Plattform
function pfOnlyHere($gid) { global $PF; if (!$gid) return true; $s = $PF->prepare("SELECT COUNT(*) FROM pf_members WHERE ident_id=? AND org_id<>?"); $s->execute([(int)$gid, ORG_ID]); return !(int)$s->fetchColumn(); }

// Alarme in anderen Mandanten der Person (Issue #60): laufende Einsätze, zu denen sie aufgeboten und nicht abwesend gemeldet ist.
// Die Person entscheidet wie bei parallelen Einsätzen selbst, ob sie wechselt.
function pfOtherAlarms($gid) { $r = [];
  foreach (pfMemberOrgs($gid) as $o) { if ($o['id'] === ORG_ID || !$o['user_id']) continue; $x = pfConn($o['id']); if (!$x) continue;
    try { $u = $o['user_id']; $a = $x->prepare("SELECT active FROM users WHERE id=?"); $a->execute([$u]); if (!(int)$a->fetchColumn()) continue;
      $ks = []; foreach ($x->query("SELECT id,name,color,alarm,sec FROM inc_kinds")->fetchAll() as $k) $ks[(int)$k['id']] = $k;
      foreach ($x->query("SELECT id,kind_id,message,alarm_at,invite,inv_groups,inv_absent,created_by FROM incidents WHERE ended_at IS NULL ORDER BY id DESC")->fetchAll() as $i) {
        $k = $ks[(int)$i['kind_id']] ?? null; if ($k && ((int)$k['sec'] === 1 || $k['alarm'] === 'none')) continue; if ((int)$i['created_by'] === $u) continue; // selbst gestartet
        if (in_array($u, array_map('intval', json_decode($i['inv_absent'] ?? '[]', true) ?: []), true)) continue;
        $g = json_decode($i['inv_groups'] ?? 'null', true);
        if (empty($g['all']) && $i['invite'] !== null && !in_array($u, array_map('intval', json_decode($i['invite'], true) ?: []), true)) continue;
        $p = $x->prepare("SELECT 1 FROM participants WHERE incident_id=? AND user_id=? AND mode<>'declined' AND left_at IS NULL"); $p->execute([$i['id'], $u]); $j = (bool)$p->fetchColumn();
        $r[] = ['org' => $o['id'], 'org_name' => $o['name'], 'id' => (int)$i['id'], 'message' => (string)$i['message'], 'alarm_at' => (int)$i['alarm_at'], 'kind_name' => $k['name'] ?? '', 'kind_color' => $k['color'] ?? '', 'level' => $k['alarm'] ?? 'normal', 'joined' => $j ? 1 : 0]; } }
    catch (Throwable $e) {} }
  return $r; }

// Eintrag einer Person in die übrigen Mandanten spiegeln (gkey = derselbe Eintrag überall): Abwesenheiten «für alle Organisationen» und Regeln der Einsatzzeiten.
// $f = Felder (ohne user_id), null = in den anderen Mandanten entfernen
function pfMirror($table, $gkey, $gid, $f) { if (!$gkey || !$gid) return;
  foreach (pfMemberOrgs($gid) as $o) { if ($o['id'] === ORG_ID || !$o['user_id']) continue; $x = pfConn($o['id']); if (!$x) continue;
    try { if ($f === null) { $x->prepare("UPDATE $table SET removed=1 WHERE gkey=?")->execute([$gkey]); continue; }
      $s = $x->prepare("SELECT id FROM $table WHERE gkey=?"); $s->execute([$gkey]); $id = $s->fetchColumn(); $s->closeCursor();
      if ($id !== false) $x->prepare("UPDATE $table SET " . implode(',', array_map(fn($k) => "$k=?", array_keys($f))) . ", removed=0 WHERE id=?")->execute([...array_values($f), $id]);
      else $x->prepare("INSERT INTO $table(user_id,gkey,created_by," . implode(',', array_keys($f)) . ") VALUES(?,?,NULL" . str_repeat(',?', count($f)) . ")")->execute([$o['user_id'], $gkey, ...array_values($f)]); }
    catch (Throwable $e) {} } }
function pfMulti($gid) { return $gid && count(array_filter(pfMemberOrgs($gid), fn($o) => $o['user_id'] !== null)) > 1; } // Person in mehreren Mandanten?
