<?php
// Plattform-Verwaltung (Issue #60): Mandanten, Konten, Mitgliedschaften und gemeinsame Einstellungen. Nur für Plattform-Administratoren (pf_idents.platform_admin).
// Eingebunden aus index.php für Routen pf/*, vor der Anmeldeprüfung des Mandanten: Plattform-Administratoren müssen in keinem Mandanten Mitglied sein.
// Die Plattform-Verwaltung sieht keine Einsatzdaten; Mandanten-Datenbanken öffnet sie nur, um Mitglieder zu deaktivieren und Einstellungen zu übernehmen.

$pi = $GLOBALS['PF_IDENT'] ?? null;
if (!$pi) out(['error' => 'Nicht angemeldet'], 401);
$pid = (int)$pi['id'];
$pfIdentPub = fn($i) => ['id' => (int)$i['id'], 'username' => $i['username'], 'name' => $i['name'], 'platform_admin' => (int)$i['platform_admin'], 'must_change' => (int)$i['must_change'], 'last_login' => $i['last_login'] === null ? null : (int)$i['last_login']];

// eigene Angaben, Abmelden und Passwort ändern gehen für alle Konten (z. B. Plattform-Administratoren ohne Mandant)
if ($route === 'pf/me') out(['me' => $pfIdentPub($pi), 'orgs' => array_map(fn($o) => ['id' => $o['id'], 'name' => $o['name']], pfMemberOrgs($pid, false)), 'version' => verPub()]);
if ($route === 'pf/logout') { $t = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
  $s = $PF->prepare("SELECT 1 FROM pf_sessions WHERE token=?"); $s->execute([$t]); $isSess = (bool)$s->fetchColumn(); $s->closeCursor();
  if ($isSess) pfSessDel($pid, $t); else pfSync($pid, ['token' => null]); out(['ok' => 1]); }
if ($route === 'pf/password') { $old = (string)($in['old'] ?? ''); $new = (string)($in['new'] ?? '');
  if ($new === '') out(['error' => 'Neues Passwort fehlt'], 400);
  if (!(int)$pi['must_change'] && !password_verify($old, (string)$pi['pass_hash'])) out(['error' => 'Aktuelles Passwort falsch'], 403);
  pfSync($pid, ['pass_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change' => 0]); alog('info', 'auth', 'Passwort geändert (Plattform)'); out(['ok' => 1]); }

if (!(int)$pi['platform_admin']) out(['error' => 'Nur für Plattform-Administratoren'], 403);
if ((int)$pi['must_change']) out(['error' => 'Bitte zuerst ein eigenes Passwort setzen', 'must_change' => 1], 403);

$pfSlug = function ($v) { $v = strtolower(trim((string)$v)); $v = preg_replace('/[^a-z0-9-]+/', '-', $v); return trim(substr($v, 0, 40), '-'); };
$pfCount = function ($oid) use ($PF) { $s = $PF->prepare("SELECT COUNT(*) FROM pf_members WHERE org_id=?"); $s->execute([(int)$oid]); return (int)$s->fetchColumn(); };
$pfOrgPub = fn($o) => ['id' => (int)$o['id'], 'slug' => $o['slug'], 'name' => $o['name'], 'active' => (int)$o['active'], 'sort' => (int)$o['sort'], 'dsn' => (int)$o['id'] === 1 ? '' : (string)$o['dsn'], 'db_user' => (string)($o['db_user'] ?? ''), 'pass_set' => trim((string)($o['db_pass'] ?? '')) !== '' ? 1 : 0, 'members' => $pfCount($o['id']), 'ready' => pfConn($o['id']) ? 1 : 0, 'mine' => pfIsMember($pid, $o['id']) ? 1 : 0]; // mine = eigenes Konto ist Mitglied (sonst öffnet admin.html?o= eine andere Organisation)
// Person in einem Mandanten deaktivieren (Mitgliedschaft beendet): abmelden, Push-Abos weg; Daten bleiben, erneutes Hinzufügen aktiviert sie wieder
$pfLocalOff = function ($oid, $gid) { $x = pfConn($oid); if (!$x) return;
  try { $s = $x->prepare("SELECT id FROM users WHERE gid=?"); $s->execute([(int)$gid]); $uid = $s->fetchColumn(); $s->closeCursor(); if ($uid === false) return;
    $x->prepare("UPDATE users SET active=0, token=NULL WHERE id=?")->execute([$uid]);
    foreach (['DELETE FROM push_native WHERE user_id=?', 'DELETE FROM sessions WHERE user_id=?', 'DELETE FROM push_subs WHERE user_id=?'] as $q) try { $x->prepare($q)->execute([$uid]); } catch (Throwable $e) {}
    $x->prepare("UPDATE participants SET left_at=? WHERE user_id=? AND left_at IS NULL AND incident_id IN (SELECT id FROM incidents WHERE ended_at IS NULL)")->execute([time(), $uid]); } catch (Throwable $e) {} };
$pfAdmins = fn() => (int)$PF->query("SELECT COUNT(*) FROM pf_idents WHERE platform_admin=1")->fetchColumn();
$pfShare = fn() => array_map(fn($g, $x) => ['key' => $g, 'label' => $x[0], 'mode' => pfMode($g), 'set' => count(array_filter($x[1], fn($k) => pfSet($k) !== null))], array_keys(PF_SHARE), PF_SHARE);

switch ($route) {
case 'pf/orgs':
  out(['orgs' => array_map(fn($o) => $pfOrgPub(pfOrg($o['id'])), pfOrgs(true)), 'share' => $pfShare(), 'modes' => PF_MODES, 'sqlite' => str_starts_with($cfg['dsn0'], 'sqlite') ? 1 : 0, 'admins' => $pfAdmins()]); // pfOrgs liefert nur die Kurzangaben, DSN und Zugang stehen in pfOrg
case 'pf/org/save': { // neu bzw. ändern; Org 1 = bisherige Datenbank (DSN nicht änderbar, nicht deaktivierbar)
  $id = (int)($in['id'] ?? 0); $o = $id ? pfOrg($id) : null; if ($id && !$o) out(['error' => 'Mandant nicht gefunden'], 404);
  $name = trim((string)($in['name'] ?? ($o['name'] ?? ''))); if ($name === '') out(['error' => 'Name fehlt'], 400); $name = mb_substr($name, 0, 120);
  $slug = $pfSlug($in['slug'] ?? ($o['slug'] ?? $name)); if ($slug === '') $slug = 'org' . ($id ?: time());
  $s = $PF->prepare("SELECT id FROM pf_orgs WHERE slug=? AND id<>?"); $s->execute([$slug, $id]); if ($s->fetchColumn() !== false) out(['error' => 'Kurzname schon vergeben'], 409);
  $active = isset($in['active']) ? ((int)$in['active'] ? 1 : 0) : (int)($o['active'] ?? 1); if ($id === 1 && !$active) out(['error' => 'Der erste Mandant (bisherige Installation) bleibt aktiv'], 400);
  $dsn = $id === 1 ? null : trim((string)($in['dsn'] ?? ($o['dsn'] ?? '')));
  if ($dsn !== null && $dsn !== '' && !preg_match('/^(mysql|sqlite):/', $dsn)) out(['error' => 'DSN muss mit mysql: oder sqlite: beginnen'], 400);
  if ($dsn === '' && !str_starts_with($cfg['dsn0'], 'sqlite')) out(['error' => 'Mit MySQL braucht jeder Mandant eine eigene Datenbank (DSN)'], 400);
  $dbu = $id === 1 ? null : trim((string)($in['db_user'] ?? ($o['db_user'] ?? '')));
  $dbp = $id === 1 ? null : (array_key_exists('db_pass', $in) && (string)$in['db_pass'] !== '' ? (string)$in['db_pass'] : (!empty($in['pass_clear']) ? null : ($o['db_pass'] ?? null)));
  if ($dsn !== null && $dsn !== '') { try { new PDO($dsn, $dbu ?: null, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]); } catch (Throwable $e) { out(['error' => 'Datenbank nicht erreichbar: ' . $e->getMessage()], 400); } }
  if ($o) $PF->prepare("UPDATE pf_orgs SET name=?, slug=?, active=?, dsn=?, db_user=?, db_pass=? WHERE id=?")->execute([$name, $slug, $active, $id === 1 ? null : ($dsn ?: null), $dbu ?: null, $dbp, $id]);
  else { $n = (int)$PF->query("SELECT COALESCE(MAX(sort),0) FROM pf_orgs")->fetchColumn() + 1;
    $PF->prepare("INSERT INTO pf_orgs(slug,name,dsn,db_user,db_pass,active,sort,created) VALUES(?,?,?,?,?,?,?,?)")->execute([$slug, $name, $dsn ?: null, $dbu ?: null, $dbp, $active, $n, time()]); $id = (int)$PF->lastInsertId(); }
  alog('info', 'platform', $o ? 'Mandant geändert' : 'Mandant angelegt', ['org' => $id]);
  out(['ok' => 1, 'id' => $id]); } // die Datenbank entsteht beim ersten Aufruf mit diesem Mandanten (plattform.html ruft danach die Route org mit ?o= auf)
case 'pf/org/move': { $id = (int)($in['id'] ?? 0); $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1; $os = pfOrgs(true); $ix = array_search($id, array_map(fn($o) => (int)$o['id'], $os), true);
  if ($ix === false || !isset($os[$ix + $dir])) out(['ok' => 1]); [$os[$ix], $os[$ix + $dir]] = [$os[$ix + $dir], $os[$ix]];
  foreach ($os as $k => $o) $PF->prepare("UPDATE pf_orgs SET sort=? WHERE id=?")->execute([$k + 1, $o['id']]); out(['ok' => 1]); }
case 'pf/members': { $oid = (int)($_GET['org'] ?? $in['org'] ?? 0); if (!pfOrg($oid)) out(['error' => 'Mandant nicht gefunden'], 404);
  $s = $PF->prepare("SELECT m.boot, m.user_id, i.* FROM pf_members m JOIN pf_idents i ON i.id=m.ident_id WHERE m.org_id=? ORDER BY i.name"); $s->execute([$oid]);
  $x = pfConn($oid); $act = [];
  if ($x) try { foreach ($x->query("SELECT gid, active FROM users WHERE gid IS NOT NULL")->fetchAll() as $u) $act[(int)$u['gid']] = (int)$u['active']; } catch (Throwable $e) {}
  out(['members' => array_map(fn($r) => $pfIdentPub($r) + ['boot' => (int)$r['boot'], 'linked' => $r['user_id'] !== null ? 1 : 0, 'active' => $act[(int)$r['id']] ?? null, 'orgs' => count(pfMemberOrgs($r['id']))], $s->fetchAll())]); }
case 'pf/member/add': { // bestehendes Konto (username) oder neues (username, name, password); admin = in diesem Mandanten Systemadministrator
  $oid = (int)($in['org'] ?? 0); if (!pfOrg($oid)) out(['error' => 'Mandant nicht gefunden'], 404);
  $un = trim((string)($in['username'] ?? '')); if ($un === '') out(['error' => 'Benutzername fehlt'], 400);
  $s = $PF->prepare("SELECT * FROM pf_idents WHERE username=?"); $s->execute([$un]); $i = $s->fetch(); $s->closeCursor();
  if (!$i) { $nm = trim((string)($in['name'] ?? '')); $pw = (string)($in['password'] ?? ''); if ($nm === '' || $pw === '') out(['error' => 'Neues Konto: Name und Startpasswort angeben', 'new' => 1], 400);
    $gid = pfIdentNew($un, mb_substr($nm, 0, 120), password_hash($pw, PASSWORD_DEFAULT)); $s->execute([$un]); $i = $s->fetch(); $s->closeCursor(); }
  $s = $PF->prepare("SELECT id, user_id FROM pf_members WHERE ident_id=? AND org_id=?"); $s->execute([$i['id'], $oid]); $m = $s->fetch(); $s->closeCursor();
  if ($m) out(['error' => 'Schon Mitglied dieses Mandanten'], 409);
  $PF->prepare("INSERT INTO pf_members(ident_id,org_id,user_id,boot,created) VALUES(?,?,NULL,?,?)")->execute([$i['id'], $oid, !empty($in['admin']) ? 1 : 0, time()]);
  alog('info', 'platform', 'Mitglied hinzugefügt', ['org' => $oid, 'ident' => (int)$i['id']]);
  out(['ok' => 1, 'ident' => (int)$i['id']]); } // die Person entsteht im Mandanten beim nächsten Aufruf dort (pfLink); eine früher entfernte wird wieder aktiv
case 'pf/member/remove': { $oid = (int)($in['org'] ?? 0); $gid = (int)($in['ident'] ?? 0);
  $s = $PF->prepare("SELECT COUNT(*) FROM pf_members WHERE ident_id=? AND org_id=?"); $s->execute([$gid, $oid]); if (!(int)$s->fetchColumn()) out(['error' => 'Keine Mitgliedschaft'], 404);
  $pfLocalOff($oid, $gid); $PF->prepare("DELETE FROM pf_members WHERE ident_id=? AND org_id=?")->execute([$gid, $oid]);
  // die Gerätesitzungen bleiben für die übrigen Mandanten gültig
  alog('info', 'platform', 'Mitglied entfernt', ['org' => $oid, 'ident' => $gid]); out(['ok' => 1]); }
case 'pf/idents': { $q = trim((string)($_GET['q'] ?? '')); $st = $PF->prepare("SELECT * FROM pf_idents" . ($q !== '' ? " WHERE username LIKE ? OR name LIKE ?" : '') . " ORDER BY name LIMIT 200");
  $st->execute($q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : []);
  out(['idents' => array_map(fn($i) => $pfIdentPub($i) + ['orgs' => array_map(fn($o) => ['id' => $o['id'], 'name' => $o['name']], pfMemberOrgs($i['id']))], $st->fetchAll())]); }
case 'pf/ident/save': { // Plattform-Recht setzen, Passwort zurücksetzen (Person muss beim nächsten Login ein eigenes setzen)
  $gid = (int)($in['id'] ?? 0); $s = $PF->prepare("SELECT * FROM pf_idents WHERE id=?"); $s->execute([$gid]); $i = $s->fetch(); $s->closeCursor(); if (!$i) out(['error' => 'Konto nicht gefunden'], 404);
  if (isset($in['platform_admin'])) { $v = (int)$in['platform_admin'] ? 1 : 0;
    if (!$v && $gid === $pid) out(['error' => 'Das eigene Plattform-Recht lässt sich nicht entfernen'], 400);
    if (!$v && (int)$i['platform_admin'] && $pfAdmins() <= 1) out(['error' => 'Mindestens ein Plattform-Administrator bleibt'], 400);
    $PF->prepare("UPDATE pf_idents SET platform_admin=? WHERE id=?")->execute([$v, $gid]); alog('info', 'platform', $v ? 'Plattform-Recht erteilt' : 'Plattform-Recht entzogen', ['ident' => $gid]); }
  if (isset($in['password'])) { $pw = (string)$in['password']; if ($pw === '') out(['error' => 'Startpasswort fehlt'], 400);
    pfSync($gid, ['pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => 1, 'token' => null]); pfSessDel($gid); alog('info', 'platform', 'Passwort zurückgesetzt', ['ident' => $gid]); }
  if (isset($in['name']) && trim((string)$in['name']) !== '') pfSync($gid, ['name' => mb_substr(trim((string)$in['name']), 0, 120)]);
  out(['ok' => 1]); }
case 'pf/links': // Vereinbarungen (Issue #60): Mandant org_from darf Einsatzgruppen von org_to aufbieten (grp null = alle, sonst nur diese)
  { $gr = []; foreach (pfOrgs(true) as $o) $gr[(int)$o['id']] = pfWith($o['id'], fn() => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color']], grpRows(true))) ?? [];
    out(['links' => pfLinks(), 'groups' => (object)$gr]); }
case 'pf/link/save': { $f = (int)($in['from'] ?? 0); $t = (int)($in['to'] ?? 0);
  if (!pfOrg($f) || !pfOrg($t) || $f === $t) out(['error' => 'Bitte zwei verschiedene Mandanten wählen'], 400);
  $g = $in['grp'] ?? null; $g = $g === null ? null : json_encode(array_values(array_unique(array_filter(array_map('intval', (array)$g), fn($x) => $x > 0))));
  if ($g === '[]') out(['error' => 'Bitte mindestens eine Einsatzgruppe wählen oder «alle»'], 400);
  $s = $PF->prepare("SELECT id FROM pf_links WHERE org_from=? AND org_to=?"); $s->execute([$f, $t]); $id = $s->fetchColumn(); $s->closeCursor();
  if ($id) $PF->prepare("UPDATE pf_links SET grp=? WHERE id=?")->execute([$g, $id]); else $PF->prepare("INSERT INTO pf_links(org_from,org_to,grp,created) VALUES(?,?,?,?)")->execute([$f, $t, $g, time()]);
  alog('info', 'platform', 'Vereinbarung gespeichert', ['from' => $f, 'to' => $t]); out(['ok' => 1, 'links' => pfLinks()]); }
case 'pf/link/remove': { $id = (int)($in['id'] ?? 0); $PF->prepare("DELETE FROM pf_links WHERE id=?")->execute([$id]);
  alog('info', 'platform', 'Vereinbarung entfernt', ['id' => $id]); out(['ok' => 1, 'links' => pfLinks()]); }
case 'pf/share/save': { $g = (string)($in['group'] ?? ''); $m = (string)($in['mode'] ?? ''); if (!isset(PF_SHARE[$g]) || !isset(PF_MODES[$m])) out(['error' => 'Ungültig'], 400);
  pfPut('share:' . $g, $m); alog('info', 'platform', 'Gemeinsame Einstellung: ' . PF_SHARE[$g][0] . ' = ' . PF_MODES[$m]); out(['ok' => 1, 'share' => $pfShare()]); }
case 'pf/share/take': { // Werte eines Mandanten als Plattform-Vorgabe übernehmen
  $g = (string)($in['group'] ?? ''); $oid = (int)($in['org'] ?? 0); if (!isset(PF_SHARE[$g])) out(['error' => 'Ungültig'], 400);
  $x = pfConn($oid); if (!$x) out(['error' => 'Datenbank des Mandanten nicht erreichbar'], 400);
  foreach (PF_SHARE[$g][1] as $k) { $s = $x->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $v = $s->fetchColumn(); $s->closeCursor(); pfPut($k, $v === false ? null : $v); }
  alog('info', 'platform', 'Vorgabe übernommen: ' . PF_SHARE[$g][0], ['org' => $oid]); out(['ok' => 1, 'share' => $pfShare()]); }
case 'pf/share/clear': { // eigene Werte aller Mandanten entfernen (danach gilt überall die Plattform-Vorgabe)
  $g = (string)($in['group'] ?? ''); if (!isset(PF_SHARE[$g])) out(['error' => 'Ungültig'], 400); $n = 0;
  foreach (pfOrgs(true) as $o) { $x = pfConn($o['id']); if (!$x) continue; foreach (PF_SHARE[$g][1] as $k) { $d = $x->prepare("DELETE FROM settings WHERE k=?"); $d->execute([$k]); $n += $d->rowCount(); } }
  alog('info', 'platform', 'Eigene Werte entfernt: ' . PF_SHARE[$g][0], ['n' => $n]); out(['ok' => 1, 'removed' => $n]); }
}
out(['error' => 'Unbekannte Route'], 404);
