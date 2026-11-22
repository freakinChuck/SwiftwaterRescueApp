<?php
/* ---------- Dokumentenablage (Issue #229) ----------
 Eingebunden von index.php nach dem Anlegen des Schemas und vor der Gastseite des Funks. Enthält die Tabellen, die Rechteprüfung,
 die Routen ohne Anmeldung (Externe: ext/doc, guest/docs) und docRoute() für alle angemeldeten Routen (aufgerufen vor dem switch).
 Ordner (Baum) mit Freigaben je Einsatzgruppe/Benutzerrolle und Redaktion; Einträge als Datei (Versionen), Seite (Markdown) oder Link.
 Dateien in api/data/docs/<Eintrag>/<Version>.<Endung> (Ordner data ist gesperrt), ausgeliefert nur über doc/get mit Rechteprüfung. */

foreach ([
  // Ordner: read_grp/read_role NULL und NULL = alle Angemeldeten; edit_* = Redaktion (NULL und NULL = niemand ausser Recht docs); sug all|none|list mit sug_grp/sug_role; ext = mit Externen teilbar
  "CREATE TABLE IF NOT EXISTS doc_folders(id $ai, parent_id INT NULL, name $tx, descr TEXT NULL, sort INT NULL, active INT DEFAULT 1, read_grp TEXT NULL, read_role TEXT NULL, edit_grp TEXT NULL, edit_role TEXT NULL, sug $tx NULL, sug_grp TEXT NULL, sug_role TEXT NULL, ext INT DEFAULT 0, created BIGINT NULL, created_by INT NULL)",
  // Einträge: kind file|page|link, ver = aktuelle Version (Datei: Version der Datei, Seite/Link: Zähler der Änderungen), kinds = Einsatztypen (JSON), offline 0 nein, 1 erlaubt, 2 automatisch;
  // Lesebestätigung ack, ack_ver = Version, ab der sie gilt, ack_at = Zeitpunkt dieser Freigabe, ack_by read|list|skill, ack_grp/ack_role (bei list), ack_days Frist, ack_new = später Hinzukommende auch, ack_major = nur wesentliche Änderungen, ack_skill = Fähigkeit (#117)
  "CREATE TABLE IF NOT EXISTS docs(id $ai, folder_id INT, kind $tx, title $tx, descr TEXT NULL, tags TEXT NULL, body MEDIUMTEXT NULL, url TEXT NULL, kinds TEXT NULL, review_at BIGINT NULL, offline INT DEFAULT 1, search MEDIUMTEXT NULL, sort INT NULL, active INT DEFAULT 1, ver INT DEFAULT 0,
    ack INT DEFAULT 0, ack_ver INT DEFAULT 0, ack_at BIGINT NULL, ack_by $tx NULL, ack_grp TEXT NULL, ack_role TEXT NULL, ack_days INT NULL, ack_new INT DEFAULT 1, ack_major INT DEFAULT 0, ack_skill INT NULL, created BIGINT, created_by INT NULL, updated BIGINT, updated_by INT NULL)",
  // Versionen einer Datei; text = Text der Seiten eines PDF (JSON-Liste) für die Suche mit Seitenzahl
  "CREATE TABLE IF NOT EXISTS doc_files(id $ai, doc_id INT, ver INT, name $tx, ext $tx, mime $tx, bytes BIGINT, pages INT NULL, sha256 $tx, file $tx, text MEDIUMTEXT NULL, at BIGINT, by_user INT NULL)",
  "CREATE TABLE IF NOT EXISTS doc_imgs(id $ai, doc_id INT, data MEDIUMTEXT, w INT, h INT, at BIGINT, by_user INT NULL)",
  "CREATE TABLE IF NOT EXISTS doc_user(user_id INT, doc_id INT, fav INT DEFAULT 0, seen_at BIGINT NULL, ack_at BIGINT NULL, ack_ver INT NULL, ack_free INT DEFAULT 0, PRIMARY KEY(user_id, doc_id))",
  // verlangte Lesebestätigungen: since = verlangt seit (Freigabe bzw. Hinzukommen), due_at = Frist, reminded 0/1 (vor Ablauf)/2 (überfällig)
  "CREATE TABLE IF NOT EXISTS doc_ack_due(doc_id INT, user_id INT, ver INT, since BIGINT, due_at BIGINT NULL, reminded INT DEFAULT 0, PRIMARY KEY(doc_id, user_id))",
  "CREATE TABLE IF NOT EXISTS doc_sug(id $ai, folder_id INT, doc_id INT NULL, kind $tx, title $tx NULL, note TEXT NULL, file $tx NULL, name $tx NULL, ext $tx NULL, mime $tx NULL, bytes BIGINT NULL, sha256 $tx NULL, user_id INT NULL, at BIGINT, state $tx, reason TEXT NULL, done_by INT NULL, done_at BIGINT NULL, result_id INT NULL)",
  // Freigaben für Externe im Einsatz: guest_id NULL = alle Funk-Gäste, token = eigener Link (dok.html?k=), hits = Abrufe, hn/hh = Abrufe in der laufenden Stunde
  "CREATE TABLE IF NOT EXISTS doc_ext(id $ai, incident_id INT, doc_id INT, ver INT, guest_id INT NULL, token $tx NULL, hits INT DEFAULT 0, hn INT DEFAULT 0, hh BIGINT DEFAULT 0, by_user INT NULL, at BIGINT, removed BIGINT NULL)",
  "CREATE TABLE IF NOT EXISTS doc_view(user_id INT, kid INT, secs TEXT, PRIMARY KEY(user_id, kid))",
  "CREATE TABLE IF NOT EXISTS place_docs(place_id INT, doc_id INT, PRIMARY KEY(place_id, doc_id))",
] as $s) $db->exec($s);

// Dateitypen: Schlüssel => [Bezeichnung, Endungen, Anzeige in der App (pdf|img|text|audio|video|dl), standardmässig erlaubt]
const DOC_TYPES = ['pdf' => ['PDF', ['pdf'], 'pdf', 1], 'img' => ['Bilder', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'img', 1], 'word' => ['Word', ['doc', 'docx', 'odt', 'rtf'], 'dl', 1],
  'excel' => ['Excel', ['xls', 'xlsx', 'ods', 'csv'], 'dl', 1], 'ppt' => ['PowerPoint', ['ppt', 'pptx', 'odp'], 'dl', 1], 'text' => ['Text', ['txt', 'md'], 'text', 1],
  'geo' => ['Karten und Routen', ['gpx', 'kml', 'kmz', 'geojson'], 'dl', 1], 'audio' => ['Audio', ['mp3', 'm4a', 'wav', 'ogg'], 'audio', 0], 'video' => ['Video', ['mp4', 'mov', 'webm'], 'video', 0],
  'zip' => ['Archive', ['zip', '7z'], 'dl', 0]];
// nie erlaubt, auch nicht als freie Endung: ausführbare und aktive Inhalte
const DOC_NEVER = ['exe', 'bat', 'cmd', 'com', 'msi', 'msp', 'ps1', 'psm1', 'sh', 'bash', 'js', 'mjs', 'cjs', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl',
  'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'jar', 'apk', 'aab', 'ipa', 'app', 'dmg', 'pkg', 'deb', 'rpm', 'scr', 'lnk', 'hta', 'cpl', 'dll', 'sys', 'reg', 'inf', 'swf', 'htaccess', 'config'];
const DOC_MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
  'csv' => 'text/csv; charset=utf-8', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
  'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text', 'rtf' => 'application/rtf',
  'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
  'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'odp' => 'application/vnd.oasis.opendocument.presentation',
  'gpx' => 'application/gpx+xml', 'kml' => 'application/vnd.google-earth.kml+xml', 'kmz' => 'application/vnd.google-earth.kmz', 'geojson' => 'application/geo+json', 'zip' => 'application/zip', '7z' => 'application/x-7z-compressed'];
// Abschnitte des Fensters «Dokumente» (Einstellung docs.view): Schlüssel => [Bezeichnung, standardmässig an]
const DOC_SECS = ['ack' => ['Lesebestätigung offen', 1], 'fav' => ['Favoriten', 1], 'place' => ['An Standorten im Einsatzgebiet', 1], 'kind' => ['Für diesen Einsatz', 1],
  'recent' => ['Zuletzt geöffnet', 0], 'new' => ['Neu und geändert', 0], 'folders' => ['Ordner', 1]];
const DOC_SUG = ['all' => 'Alle, die den Ordner sehen', 'none' => 'Niemand', 'list' => 'Bestimmte Einsatzgruppen oder Benutzerrollen'];
const DOC_CHUNK = 1048576; // Bytes je Stück beim Hochladen (Base64 rund 1.4 MB, unter den Grenzen von cyon)
const DOC_SUG_MAX = 10; // offene Vorschläge je Person
const DOC_NEW_DAYS = 30; // «Neu und geändert»: geändert in den letzten Tagen
const DOCS_DEFAULT = ['types' => [], 'free' => '', 'max_mb' => 25, 'quota_mb' => 5120, 'keep' => 5, 'off_mb' => 300, 'n' => 5, 'menu_n' => 5, 'own' => 1, 'view' => [],
  'ext' => ['on' => 0, 'folder_only' => 1, 'guest' => 1, 'link' => 1, 'dl' => 1, 'hits_h' => 60]];

function docsCfg() { static $c = null; if ($c !== null) return $c; $v = json_decode((string)setting('docs', ''), true); $v = is_array($v) ? $v : [];
  $c = DOCS_DEFAULT; foreach (['free'] as $k) if (isset($v[$k]) && is_string($v[$k])) $c[$k] = $v[$k];
  foreach (['max_mb' => [1, 500], 'quota_mb' => [10, 1000000], 'keep' => [0, 50], 'off_mb' => [0, 5000], 'n' => [1, 50], 'menu_n' => [0, 20]] as $k => [$a, $b]) if (isset($v[$k]) && is_numeric($v[$k])) $c[$k] = max($a, min($b, (int)$v[$k]));
  $c['own'] = isset($v['own']) ? ((int)$v['own'] ? 1 : 0) : 1;
  foreach (DOC_TYPES as $k => $t) $c['types'][$k] = isset($v['types'][$k]) ? ((int)$v['types'][$k] ? 1 : 0) : $t[3];
  $e = is_array($v['ext'] ?? null) ? $v['ext'] : []; foreach (DOCS_DEFAULT['ext'] as $k => $d) $c['ext'][$k] = isset($e[$k]) ? ($k === 'hits_h' ? max(1, min(10000, (int)$e[$k])) : ((int)$e[$k] ? 1 : 0)) : $d;
  $c['view'] = ['0' => docSecsIn($v['view']['0'] ?? null)]; foreach ((array)($v['view'] ?? []) as $k => $l) if ((string)$k !== '0' && ctype_digit((string)$k) && is_array($l)) $c['view'][(string)$k] = docSecsIn($l);
  return $c; }
// Abschnitte normalisieren: alle Schlüssel genau einmal, fehlende hinten mit Vorgabe
function docSecsIn($l) { $r = []; $seen = []; foreach (is_array($l) ? $l : [] as $x) { $k = is_array($x) ? (string)($x['k'] ?? '') : (string)$x; if (!isset(DOC_SECS[$k]) || isset($seen[$k])) continue; $seen[$k] = 1; $r[] = ['k' => $k, 'on' => is_array($x) ? ((int)($x['on'] ?? 1) ? 1 : 0) : 1]; }
  foreach (DOC_SECS as $k => $s) if (!isset($seen[$k])) $r[] = ['k' => $k, 'on' => $s[1]]; return $r; }
// freie Endungen (ohne die nie erlaubten und ohne die des Katalogs)
function docFree() { $all = []; foreach (DOC_TYPES as $t) $all = array_merge($all, $t[1]);
  return array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim($x, " .\t")), explode(',', docsCfg()['free'])), fn($x) => preg_match('/^[a-z0-9]{1,10}$/', $x) && !in_array($x, DOC_NEVER, true) && !in_array($x, $all, true)))); }
// Endung prüfen: [Endung, Typ-Schlüssel bzw. 'free'] oder Fehler
function docExtOk($name) { $e = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)); if ($e === '' || in_array($e, DOC_NEVER, true)) return null;
  $c = docsCfg(); foreach (DOC_TYPES as $k => $t) if (in_array($e, $t[1], true)) return $c['types'][$k] ? [$e, $k] : null;
  return in_array($e, docFree(), true) ? [$e, 'free'] : null; }
function docShow($ext) { foreach (DOC_TYPES as $t) if (in_array($ext, $t[1], true)) return $t[2]; return 'dl'; }
function docMimeOf($ext) { return docShow($ext) === 'dl' && !isset(DOC_MIME[$ext]) ? 'application/octet-stream' : (DOC_MIME[$ext] ?? 'application/octet-stream'); }
// Inhalt prüfen (Signatur), nicht nur die Endung
function docSniff($p, $ext) { $h = (string)@file_get_contents($p, false, null, 0, 4096); $s = fn($x, $o = 0) => substr($h, $o, strlen($x)) === $x;
  $zip = $s("PK\x03\x04"); $ole = $s("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
  switch ($ext) {
    case 'pdf': return $s('%PDF-');
    case 'jpg': case 'jpeg': return $s("\xFF\xD8\xFF");
    case 'png': return $s("\x89PNG\r\n\x1A\n");
    case 'gif': return $s('GIF8');
    case 'webp': return $s('RIFF') && $s('WEBP', 8);
    case 'docx': case 'xlsx': case 'pptx': // ohne Makros (vbaProject)
      if (!$zip) return false; $z = (string)@file_get_contents($p, false, null, 0, 20000000); return strpos($z, 'vbaProject') === false;
    case 'odt': case 'ods': case 'odp': case 'kmz': case 'zip': return $zip;
    case 'doc': case 'xls': case 'ppt': return $ole;
    case 'rtf': return $s('{\\rtf');
    case '7z': return $s("7z\xBC\xAF\x27\x1C");
    case 'mp3': return $s('ID3') || (strlen($h) > 1 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0);
    case 'm4a': case 'mp4': case 'mov': return $s('ftyp', 4);
    case 'wav': return $s('RIFF') && $s('WAVE', 8);
    case 'ogg': return $s('OggS');
    case 'webm': return $s("\x1A\x45\xDF\xA3");
    case 'txt': case 'md': case 'csv': case 'gpx': case 'kml': case 'geojson': // Text: keine Nullbytes, keine aktiven Inhalte
      if (strpos($h, "\0") !== false) return false; return !preg_match('/<\s*(script|iframe|object|embed)\b|<\?php/i', $h);
  }
  return true; } // freie Endungen: nur als Download mit application/octet-stream
function docDir($id = null) { $d = __DIR__ . '/data/docs' . ($id === null ? '' : '/' . (int)$id); if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function docTmp($up) { global $uid; if (!preg_match('/^[0-9a-f]{16,32}$/', (string)$up)) fail('Ungültiges Hochladen'); $d = docDir() . '/tmp'; if (!is_dir($d)) @mkdir($d, 0775, true); return "$d/{$uid}_$up.part"; }
function docUsage() { global $db; $a = (int)$db->query("SELECT COALESCE(SUM(bytes),0) FROM doc_files")->fetchColumn(); $b = (int)$db->query("SELECT COALESCE(SUM(bytes),0) FROM doc_sug WHERE file IS NOT NULL")->fetchColumn(); return $a + $b; }

/* Mitgliedschaften: g = aktive Einsatzgruppen, r = Benutzerrollen */
function docMem($u = null) { global $uid; static $all = null, $one = [];
  if ($u === null) { if ($all !== null) return $all; global $db; $all = [];
    foreach ($db->query("SELECT id FROM users WHERE active=1 AND COALESCE(purged,0)=0")->fetchAll() as $r) $all[(int)$r['id']] = ['g' => [], 'r' => []];
    foreach ($db->query("SELECT m.user_id,m.group_id FROM inc_group_members m JOIN inc_groups g ON g.id=m.group_id WHERE g.active=1")->fetchAll() as $r) if (isset($all[$x = (int)$r['user_id']])) $all[$x]['g'][] = (int)$r['group_id'];
    foreach ($db->query("SELECT user_id,urole_id FROM user_uroles")->fetchAll() as $r) if (isset($all[$x = (int)$r['user_id']])) $all[$x]['r'][] = (int)$r['urole_id'];
    return $all; }
  return $one[$u] ??= ['g' => userGrps($u), 'r' => array_column(userRoles($u), 'id')]; }
function docJ($v) { return $v === null || $v === '' ? null : idsIn(json_decode((string)$v, true) ?: []); }
// Freigabe: beides NULL = alle; sonst genügt eine Gruppe oder eine Rolle
function docRule($g, $r, $m) { if ($g === null && $r === null) return true; return ($g && array_intersect($g, $m['g'])) || ($r && array_intersect($r, $m['r'])); }
function docRuleAny($g, $r, $m) { return ($g && array_intersect($g, $m['g'])) || ($r && array_intersect($r, $m['r'])); } // beides leer = niemand
function docFolders($fresh = false) { global $db; static $f = null; if ($f !== null && !$fresh) return $f; $f = [];
  foreach ($db->query("SELECT * FROM doc_folders ORDER BY CASE WHEN sort IS NULL THEN 1 ELSE 0 END, sort, name")->fetchAll() as $r) {
    $r = ints($r, ['id', 'sort', 'active', 'ext']); $r['parent_id'] = $r['parent_id'] === null ? null : (int)$r['parent_id'];
    foreach (['read_grp', 'read_role', 'edit_grp', 'edit_role', 'sug_grp', 'sug_role'] as $k) $r[$k] = docJ($r[$k]); $r['sug'] = isset(DOC_SUG[$r['sug'] ?? '']) ? $r['sug'] : 'all';
    $f[$r['id']] = $r; }
  return $f; }
function docChain($fid) { $f = docFolders(); $c = []; $n = 0; while ($fid !== null && isset($f[$fid]) && $n++ < 50) { array_unshift($c, $f[$fid]); $fid = $f[$fid]['parent_id']; } return $c; }
// sieht den Ordner nach den Freigaben (ohne Recht docs): alle Ordner bis zur Wurzel aktiv und erfüllt
function docSeeM($fid, $m) { $c = docChain($fid); if (!$c) return false; foreach ($c as $x) if (!$x['active'] || !docRule($x['read_grp'], $x['read_role'], $m)) return false; return true; }
function docEditM($fid, $m) { foreach (docChain($fid) as $x) if (docRuleAny($x['edit_grp'], $x['edit_role'], $m)) return true; return false; }
function docSee($fid) { global $uid; return can('docs') || docSeeM($fid, docMem($uid)) || docEditM($fid, docMem($uid)); }
function docEdit($fid) { global $uid; return can('docs') || docEditM($fid, docMem($uid)); }
function docEditAny() { global $uid; if (can('docs')) return true; foreach (docFolders() as $id => $_) if (docEditM($id, docMem($uid))) return true; return false; }
function docSugOk($fid) { global $uid; $f = docFolders()[$fid] ?? null; if (!$f || !docSee($fid)) return false;
  return $f['sug'] === 'all' || ($f['sug'] === 'list' && docRuleAny($f['sug_grp'], $f['sug_role'], docMem($uid))); }
function docExtFolder($fid) { foreach (docChain($fid) as $x) if ($x['ext']) return true; return false; }

function docRow($id, $need = true) { global $db; $s = $db->prepare("SELECT * FROM docs WHERE id=?"); $s->execute([(int)$id]); $d = $s->fetch(); $s->closeCursor();
  if (!$d) return $need ? fail('Eintrag nicht gefunden', 404) : null; return docNorm($d); }
function docNorm($d) { $d = ints($d, ['id', 'folder_id', 'offline', 'sort', 'active', 'ver', 'ack', 'ack_ver', 'ack_new', 'ack_major', 'created', 'updated']);
  foreach (['review_at', 'ack_at', 'ack_days', 'ack_skill', 'created_by', 'updated_by'] as $k) $d[$k] = $d[$k] === null ? null : (int)$d[$k];
  $d['kinds'] = docJ($d['kinds']) ?? []; $d['ack_grp'] = docJ($d['ack_grp']); $d['ack_role'] = docJ($d['ack_role']); $d['ack_by'] = in_array($d['ack_by'], ['read', 'list', 'skill'], true) ? $d['ack_by'] : 'read'; return $d; }
// aktuelle Datei eines Eintrags
function docFile($d, $ver = null) { global $db; $s = $db->prepare("SELECT * FROM doc_files WHERE doc_id=? AND ver=?"); $s->execute([$d['id'], $ver ?? $d['ver']]); $f = $s->fetch(); $s->closeCursor();
  return $f ? ints($f, ['id', 'doc_id', 'ver', 'bytes', 'pages', 'at']) : null; }
function docFilesOf($ids) { global $db; if (!$ids) return []; $r = []; $s = $db->prepare("SELECT f.id,f.doc_id,f.ver,f.name,f.ext,f.mime,f.bytes,f.pages,f.sha256,f.at FROM doc_files f JOIN docs d ON d.id=f.doc_id AND d.ver=f.ver WHERE f.doc_id IN (" . qm($ids) . ")");
  $s->execute(array_values($ids)); foreach ($s->fetchAll() as $f) $r[(int)$f['doc_id']] = ints($f, ['id', 'doc_id', 'ver', 'bytes', 'pages', 'at']); return $r; }
function docPlacesOf($ids) { global $db; if (!$ids) return []; $r = []; $s = $db->prepare("SELECT place_id,doc_id FROM place_docs WHERE doc_id IN (" . qm($ids) . ")"); $s->execute(array_values($ids));
  foreach ($s->fetchAll() as $x) $r[(int)$x['doc_id']][] = (int)$x['place_id']; return $r; }
function docUserOf($u, $ids = null) { global $db; $r = []; $s = $db->prepare("SELECT * FROM doc_user WHERE user_id=?"); $s->execute([$u]); foreach ($s->fetchAll() as $x) $r[(int)$x['doc_id']] = $x; return $r; }
// alle Einträge, die die angemeldete Person sieht (aktiv; mit $edit auch deaktivierte in Ordnern der Redaktion)
function docsVisible($edit = false) { global $db; $r = []; $see = []; $ed = [];
  foreach ($db->query("SELECT * FROM docs ORDER BY CASE WHEN sort IS NULL THEN 1 ELSE 0 END, sort, title")->fetchAll() as $d) { $d = docNorm($d); $f = $d['folder_id'];
    $see[$f] ??= docSee($f); if (!$see[$f]) continue; if (!$d['active']) { $ed[$f] ??= docEdit($f); if (!$edit || !$ed[$f]) continue; }
    $r[$d['id']] = $d; }
  return $r; }

/* ---------- Lesebestätigung ---------- */
function docSkill($id) { if ($id === null) return null; foreach (skillRows(true) as $k) if ($k['id'] === (int)$id) return $k; return null; }
// muss bestätigen (nach Freigaben, nicht nach dem Recht docs)
function docAckMust($d, $m) { if (!$d['ack'] || !$d['active'] || !docSeeM($d['folder_id'], $m)) return false;
  if ($d['ack_by'] === 'list') return docRuleAny($d['ack_grp'], $d['ack_role'], $m);
  if ($d['ack_by'] === 'skill') { $k = docSkill($d['ack_skill']); return $k && $k['active'] && skillFor($k, $m['g']) && skillReq($k, $m['g']); }
  return true; }
// Stand: ok | open | over, mit Frist und «verlangt seit»; $du = doc_user, $due = doc_ack_due, $sk = letzter Eintrag der Fähigkeit
function docAckState($d, $du, $due, $sk = null) { global $now;
  $days = $d['ack_days']; $since = $due ? (int)$due['since'] : ($d['ack_at'] ?? $now); $dueAt = $due && $due['due_at'] !== null ? (int)$due['due_at'] : ($days ? $since + $days * 86400 : null);
  $ok = $du && $du['ack_ver'] !== null && (int)$du['ack_ver'] >= $d['ack_ver'];
  if ($ok && $d['ack_skill'] !== null && ($k = docSkill($d['ack_skill']))) { $x = $sk ? skillExp($k, (int)$sk['done_at']) : null; // Fähigkeit abgelaufen = erneut verlangt
    if (!$sk || ($x !== null && $x < $now)) { $ok = false; if ($x !== null) { $since = max($since, $x); $dueAt = $days ? $x + $days * 86400 : null; } } }
  if ($ok) return ['st' => 'ok', 'at' => (int)$du['ack_at'], 'since' => $since, 'due' => $dueAt];
  return ['st' => $dueAt !== null && $dueAt < $now ? 'over' : 'open', 'since' => $since, 'due' => $dueAt, 'new' => $d['ack_at'] !== null && $since > $d['ack_at'] + 60 ? 1 : 0]; }
// verlangte Personen eines Eintrags nachführen: fehlende erhalten eine Zeile (sofern ack_new oder bei der Freigabe), $release = neue Freigabe (alle Zeilen neu)
function docAckSync($d, $release = false, $only = null) { global $db, $now; if (!$d['ack'] || !$d['active']) return;
  $mem = $only === null ? docMem() : [$only => docMem($only)]; $s = $db->prepare("SELECT * FROM doc_ack_due WHERE doc_id=?"); $s->execute([$d['id']]); $rows = []; foreach ($s->fetchAll() as $x) $rows[(int)$x['user_id']] = $x;
  $due = $d['ack_days'] ? $now + $d['ack_days'] * 86400 : null;
  $ins = $db->prepare("INSERT INTO doc_ack_due(doc_id,user_id,ver,since,due_at,reminded) VALUES(?,?,?,?,?,0)"); $upd = $db->prepare("UPDATE doc_ack_due SET ver=?, since=?, due_at=?, reminded=0 WHERE doc_id=? AND user_id=?");
  foreach ($mem as $u => $m) { if (!docAckMust($d, $m)) continue; $r = $rows[$u] ?? null;
    if ($r && (int)$r['ver'] === $d['ack_ver']) continue;
    if ($r) { if ($release || $d['ack_new']) $upd->execute([$d['ack_ver'], $now, $due, $d['id'], $u]); }
    elseif ($release || $d['ack_new']) $ins->execute([$d['id'], $u, $d['ack_ver'], $now, $due]); } }
// neue Freigabe der Lesebestätigung (neue Version bzw. «erneut verlangen»)
function docAckRelease($id) { global $db, $now; $d = docRow($id); if (!$d['ack']) return; $db->prepare("UPDATE docs SET ack_ver=?, ack_at=? WHERE id=?")->execute([$d['ver'], $now, $id]); docAckSync(docRow($id), true); }
// Stand aller Verlangten eines Eintrags (für doc/acks und Erinnerungen)
function docAckList($d) { global $db; $mem = docMem(); $s = $db->prepare("SELECT * FROM doc_ack_due WHERE doc_id=?"); $s->execute([$d['id']]); $due = []; foreach ($s->fetchAll() as $x) $due[(int)$x['user_id']] = $x;
  $s = $db->prepare("SELECT * FROM doc_user WHERE doc_id=?"); $s->execute([$d['id']]); $du = []; foreach ($s->fetchAll() as $x) $du[(int)$x['user_id']] = $x;
  $sk = $d['ack_skill'] !== null ? skillLast(array_keys($mem)) : []; $r = [];
  foreach ($mem as $u => $m) { if (!docAckMust($d, $m) || (!$d['ack_new'] && !isset($due[$u]))) continue;
    $last = null; foreach ($sk[$u] ?? [] as $e) if ((int)$e['skill_id'] === $d['ack_skill']) $last = $e;
    $r[$u] = docAckState($d, $du[$u] ?? null, $due[$u] ?? null, $last); }
  return [$r, $du]; }
// eigener Stand je Eintrag (nur verlangte); legt die eigene Zeile bei Bedarf an
function docAckMine($docs) { global $db, $uid; $m = docMem($uid); $du = docUserOf($uid); $s = $db->prepare("SELECT * FROM doc_ack_due WHERE user_id=?"); $s->execute([$uid]); $due = []; foreach ($s->fetchAll() as $x) $due[(int)$x['doc_id']] = $x;
  $sk = null; $r = [];
  foreach ($docs as $d) { if (!docAckMust($d, $m)) continue;
    if (!isset($due[$d['id']]) || (int)$due[$d['id']]['ver'] !== $d['ack_ver']) { if (!$d['ack_new'] && !isset($due[$d['id']])) continue; docAckSync($d, false, $uid); $s = $db->prepare("SELECT * FROM doc_ack_due WHERE user_id=? AND doc_id=?"); $s->execute([$uid, $d['id']]); $due[$d['id']] = $s->fetch() ?: null; if (!$due[$d['id']] && !$d['ack_new']) continue; }
    $last = null; if ($d['ack_skill'] !== null) { $sk ??= skillLast([$uid])[$uid] ?? []; foreach ($sk as $e) if ((int)$e['skill_id'] === $d['ack_skill']) $last = $e; }
    $r[$d['id']] = docAckState($d, $du[$d['id']] ?? null, $due[$d['id']] ?? null, $last); }
  return $r; }
// Nachlauf (höchstens stündlich): verlangte nachführen, Erinnerungen, abgelehnte Vorschläge nach 30 Tagen löschen
function docAuto() { global $db, $now; if ((int)setting('doc_auto_at', 0) > $now - 3600) return; setSetting('doc_auto_at', (string)$now);
  after(function () { global $db, $now;
    foreach ($db->query("SELECT * FROM docs WHERE ack=1 AND active=1")->fetchAll() as $d) { $d = docNorm($d); docAckSync($d);
      if (!$d['ack_days']) continue; [$st] = docAckList($d); $soon = []; $over = [];
      $s = $db->prepare("SELECT user_id,reminded FROM doc_ack_due WHERE doc_id=?"); $s->execute([$d['id']]); $rem = []; foreach ($s->fetchAll() as $x) $rem[(int)$x['user_id']] = (int)$x['reminded'];
      foreach ($st as $u => $x) { if ($x['st'] === 'ok' || $x['due'] === null) continue; $rm = $rem[$u] ?? 0;
        if ($x['st'] === 'over' && $rm < 2) $over[] = $u; elseif ($x['st'] === 'open' && $x['due'] - 3 * 86400 < $now && $rm < 1) $soon[] = $u; }
      foreach ([[$soon, 1, 'Bitte bis ' . date('d.m.Y', $now + 3 * 86400) . ' lesen und bestätigen'], [$over, 2, 'Lesebestätigung überfällig']] as [$us, $lv, $txt]) { if (!$us) continue;
        pushTo($us, ['t' => '📚 ' . $d['title'], 'b' => $txt, 'tag' => 'd:' . $d['id']] + pushLvl('normal'));
        $db->prepare("UPDATE doc_ack_due SET reminded=? WHERE doc_id=? AND user_id IN (" . qm($us) . ")")->execute(array_merge([$lv, $d['id']], $us)); } }
    foreach ($db->query("SELECT * FROM doc_sug WHERE state='rejected' AND done_at<" . ($now - 30 * 86400))->fetchAll() as $x) docSugDelete($x);
    foreach (glob(docDir() . '/tmp/*.part') ?: [] as $f) if (filemtime($f) < $now - 86400) @unlink($f);
    if (!empty($GLOBALS['pushQ'])) pushFlush(); }); }

/* ---------- Ausgabe ---------- */
// Kurzangaben für Listen
function docShort($d, $f, $pl, $du, $ack) { global $now;
  return ['id' => $d['id'], 'folder_id' => $d['folder_id'], 'kind' => $d['kind'], 'title' => $d['title'], 'descr' => (string)$d['descr'], 'tags' => (string)$d['tags'], 'url' => $d['kind'] === 'link' ? $d['url'] : null,
    'ver' => $d['ver'], 'kinds' => $d['kinds'], 'places' => $pl ?? [], 'offline' => $d['offline'], 'active' => $d['active'], 'updated' => $d['updated'], 'review_at' => $d['review_at'], 'review' => $d['review_at'] !== null && $d['review_at'] < $now ? 1 : 0,
    'file' => $f ? ['name' => $f['name'], 'ext' => $f['ext'], 'bytes' => $f['bytes'], 'pages' => $f['pages'], 'sha' => $f['sha256'], 'show' => docShow($f['ext'])] : null,
    'fav' => $du ? (int)$du['fav'] : 0, 'seen' => $du && $du['seen_at'] !== null ? (int)$du['seen_at'] : null, 'ack' => $d['ack'], 'mine' => $ack]; }
function docShorts($docs) { global $uid; $ids = array_keys($docs); $fs = docFilesOf($ids); $pl = docPlacesOf($ids); $du = docUserOf($uid); $ack = docAckMine($docs);
  return array_values(array_map(fn($d) => docShort($d, $fs[$d['id']] ?? null, $pl[$d['id']] ?? [], $du[$d['id']] ?? null, $ack[$d['id']] ?? null), $docs)); }
// Ordnerbaum (sichtbare), lock = eingeschränkt, edit = Redaktion, sug = darf vorschlagen
function docTree($edit = false) { $r = [];
  foreach (docFolders() as $id => $f) { if (!docSee($id)) { continue; } if (!$f['active'] && !($edit && docEdit($id))) continue;
    $lock = 0; foreach (docChain($id) as $x) if ($x['read_grp'] !== null || $x['read_role'] !== null) $lock = 1;
    $o = ['id' => $id, 'parent_id' => $f['parent_id'], 'name' => $f['name'], 'descr' => (string)$f['descr'], 'active' => $f['active'], 'lock' => $lock, 'edit' => docEdit($id) ? 1 : 0, 'sug' => docSugOk($id) ? 1 : 0, 'ext' => docExtFolder($id) ? 1 : 0];
    if ($edit && can('docs')) $o += array_intersect_key($f, array_flip(['read_grp', 'read_role', 'edit_grp', 'edit_role', 'sug_grp', 'sug_role'])) + ['sugm' => $f['sug'], 'ext_own' => $f['ext']];
    $r[] = $o; }
  return $r; }
function docCfgPub() { $c = docsCfg(); $t = [];
  foreach (DOC_TYPES as $k => $x) if ($c['types'][$k]) $t[] = ['k' => $k, 'label' => $x[0], 'ext' => $x[1], 'show' => $x[2]];
  return ['types' => $t, 'free' => docFree(), 'max_mb' => $c['max_mb'], 'off_mb' => $c['off_mb'], 'n' => $c['n'], 'menu_n' => $c['menu_n'], 'own' => $c['own'], 'view' => (object)$c['view'], 'secs' => array_map(fn($s) => $s[0], DOC_SECS), 'chunk' => DOC_CHUNK,
    'ext' => ['on' => $c['ext']['on'], 'guest' => $c['ext']['guest'], 'link' => $c['ext']['link'], 'dl' => $c['ext']['dl']]]; }
// für me: Favoriten, offene Lesebestätigungen, Offline-Liste (Version), Redaktion
function docMe() { global $db, $uid; $docs = docsVisible(); $du = docUserOf($uid); $ack = docAckMine($docs); $fs = docFilesOf(array_keys($docs)); $off = []; $acks = [];
  foreach ($docs as $id => $d) { $fav = (int)($du[$id]['fav'] ?? 0);
    if (($d['offline'] === 2 || ($fav && $d['offline'] === 1)) && ($d['kind'] !== 'file' || isset($fs[$id]))) $off[] = ['id' => $id, 'ver' => $d['ver'], 'kind' => $d['kind'], 'bytes' => $fs[$id]['bytes'] ?? 0, 'sha' => $fs[$id]['sha256'] ?? null, 'title' => $d['title']];
    if (isset($ack[$id]) && $ack[$id]['st'] !== 'ok') $acks[] = ['id' => $id, 'title' => $d['title'], 'due' => $ack[$id]['due'], 'st' => $ack[$id]['st']]; }
  usort($acks, fn($a, $b) => ($a['due'] ?? PHP_INT_MAX) <=> ($b['due'] ?? PHP_INT_MAX));
  $ed = docEditAny(); $sug = 0; if ($ed) foreach (docSugRows(false) as $x) if ($x['state'] === 'open') $sug++;
  return ['on' => $docs || docTree() ? 1 : 0, 'fav' => array_values(array_keys(array_filter($du, fn($x) => (int)$x['fav'] && isset($docs[(int)$x['doc_id']])))), 'ack' => $acks, 'off' => $off, 'edit' => $ed ? 1 : 0, 'sug_open' => $sug,
    'sug' => count(array_filter(docTree(), fn($f) => $f['sug'])) ? 1 : 0]; }
// für incident/state: Einträge des Einsatztyps und der festen Standorte (Kurzangaben), Freigaben für Externe (Einsatzleitung)
function docState($inc, $places) { global $db, $uid; if ($inc && !feature($inc, 'docs')) return ['on' => 0, 'list' => [], 'ext' => null];
  $pids = array_map(fn($p) => (int)$p['id'], $places ?: []); $byPl = [];
  if ($pids) { $s = $db->prepare("SELECT place_id,doc_id FROM place_docs WHERE place_id IN (" . qm($pids) . ")"); $s->execute($pids); foreach ($s->fetchAll() as $x) $byPl[(int)$x['doc_id']][] = (int)$x['place_id']; }
  $kid = $inc ? (int)kindOf($inc)['id'] : 0; $sel = [];
  foreach (docsVisible() as $id => $d) if (isset($byPl[$id]) || ($kid && in_array($kid, $d['kinds'], true))) $sel[$id] = $d;
  $l = docShorts($sel); foreach ($l as &$x) { $x['places'] = $byPl[$x['id']] ?? []; $x['kind_hit'] = $kid && in_array($kid, $x['kinds'], true) ? 1 : 0; } unset($x);
  $ext = null; if ($inc && canManage($inc) && docsCfg()['ext']['on']) $ext = docExtRows((int)$inc['id']);
  return ['on' => 1, 'list' => $l, 'ext' => $ext, 'ext_cfg' => $ext === null ? null : ['guest' => docsCfg()['ext']['guest'], 'link' => docsCfg()['ext']['link'], 'folder_only' => docsCfg()['ext']['folder_only']]]; }
// Ordner mit allen Unterordnern
function docFolderTree($id) { $r = [(int)$id]; $f = docFolders(true); for ($i = 0; $i < count($r); $i++) foreach ($f as $x) if ($x['parent_id'] === $r[$i] && !in_array($x['id'], $r, true)) $r[] = $x['id']; return $r; }
// Einträge endgültig löschen (Datenbank; Dateien löscht der Aufrufer nach dem Commit)
function docPurge($ids) { global $db; if (!$ids) return; $q = qm($ids);
  foreach (['doc_files', 'doc_imgs', 'doc_user', 'doc_ack_due', 'doc_ext', 'place_docs'] as $t) $db->prepare("DELETE FROM $t WHERE doc_id IN ($q)")->execute($ids);
  $s = $db->prepare("SELECT * FROM doc_sug WHERE doc_id IN ($q)"); $s->execute($ids); foreach ($s->fetchAll() as $x) docSugDelete($x);
  $db->prepare("DELETE FROM docs WHERE id IN ($q)")->execute($ids); }
// Vorschläge einer Person beim Löschen des Kontos: offene und abgelehnte entfernen, übernommene ohne Person behalten
function docSugUser($u) { global $db; $s = $db->prepare("SELECT * FROM doc_sug WHERE user_id=? AND state IN ('open','rejected')"); $s->execute([$u]); foreach ($s->fetchAll() as $x) docSugDelete($x);
  $db->prepare("UPDATE doc_sug SET user_id=NULL WHERE user_id=?")->execute([$u]); $db->prepare("UPDATE doc_ext SET by_user=NULL WHERE by_user=?")->execute([$u]); }
function docExtRows($incId) { global $db; $s = $db->prepare("SELECT x.*,d.title,g.label guest_label FROM doc_ext x JOIN docs d ON d.id=x.doc_id LEFT JOIN voice_guests g ON g.id=x.guest_id WHERE x.incident_id=? AND x.removed IS NULL ORDER BY x.at"); $s->execute([$incId]);
  return array_map(fn($x) => ['id' => (int)$x['id'], 'doc_id' => (int)$x['doc_id'], 'title' => $x['title'], 'ver' => (int)$x['ver'], 'guest_id' => $x['guest_id'] === null ? null : (int)$x['guest_id'], 'guest_label' => $x['guest_label'], 'token' => $x['token'], 'hits' => (int)$x['hits'], 'at' => (int)$x['at']], $s->fetchAll()); }

/* ---------- Dateien ausliefern ---------- */
function docSend($path, $mime, $name, $sha, $inline) {
  if (!is_file($path)) fail('Datei nicht gefunden', 404);
  $sz = filesize($path); $et = '"' . substr((string)$sha, 0, 32) . '"';
  header_remove('Content-Type'); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: sandbox; default-src 'none'"); header('Cache-Control: private, max-age=0, must-revalidate'); header('ETag: ' . $et); header('Accept-Ranges: bytes');
  if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $et) { http_response_code(304); exit; }
  header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
  header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
  $a = 0; $b = $sz - 1;
  if (preg_match('/bytes=(\d*)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $a = max(0, $sz - (int)$m[2]); } else { $a = (int)$m[1]; if ($m[2] !== '') $b = min($b, (int)$m[2]); }
    if ($a > $b) { http_response_code(416); header("Content-Range: bytes */$sz"); exit; }
    http_response_code(206); header("Content-Range: bytes $a-$b/$sz"); }
  header('Content-Length: ' . ($b - $a + 1)); $fp = fopen($path, 'rb'); fseek($fp, $a); $left = $b - $a + 1;
  while ($left > 0 && !feof($fp)) { $c = fread($fp, min(65536, $left)); echo $c; $left -= strlen($c); } fclose($fp); exit; }
function docFilePath($f) { return docDir((int)$f['doc_id']) . '/' . basename((string)$f['file']); }
function docInline($ext) { return in_array(docShow($ext), ['pdf', 'img', 'text', 'audio', 'video'], true); }

/* ---------- Externe (ohne Anmeldung) ---------- */
function docExtOut($x, $base) { global $db; $d = docRow($x['doc_id'], false); $c = docsCfg()['ext'];
  if (!$d || !$d['active']) fail('Dieses Dokument ist nicht mehr verfügbar.', 404);
  $f = $d['kind'] === 'file' ? docFile($d, (int)$x['ver']) : null; $imgs = [];
  if ($d['kind'] === 'page') { $s = $db->prepare("SELECT id,data FROM doc_imgs WHERE doc_id=?"); $s->execute([$d['id']]); foreach ($s->fetchAll() as $i) $imgs[(int)$i['id']] = $i['data']; }
  return ['title' => $d['title'], 'kind' => $d['kind'], 'descr' => (string)$d['descr'], 'body' => $d['kind'] === 'page' ? (string)$d['body'] : null, 'url' => $d['kind'] === 'link' ? $d['url'] : null, 'imgs' => (object)$imgs,
    'file' => $f ? ['name' => $f['name'], 'ext' => $f['ext'], 'bytes' => $f['bytes'], 'show' => docShow($f['ext'])] : null, 'dl' => $c['dl'], 'ver' => (int)$x['ver']] + $base; }
// Abruf einer Datei durch Externe: Begrenzung je Stunde, erster Abruf ins Einsatzlog
function docExtGet($x, $inc) { global $db, $now; $c = docsCfg()['ext']; $h = intdiv($now, 3600) * 3600;
  $n = (int)$x['hh'] === $h ? (int)$x['hn'] : 0; if ($n >= $c['hits_h']) fail('Zu viele Abrufe, bitte später nochmals versuchen.', 429);
  $db->prepare("UPDATE doc_ext SET hits=hits+1, hn=?, hh=? WHERE id=?")->execute([$n + 1, $h, $x['id']]);
  $d = docRow($x['doc_id'], false); if (!$d || !$d['active'] || $d['kind'] !== 'file') fail('Datei nicht gefunden', 404);
  if (!(int)$x['hits']) logev((int)$inc['id'], null, 'doc', 'Dokument «' . $d['title'] . '» erstmals von Externen abgerufen');
  $f = docFile($d, (int)$x['ver']) ?: fail('Datei nicht gefunden', 404);
  $inline = docInline($f['ext']) && (!$c['dl'] ? true : !isset($_GET['dl'])); docSend(docFilePath($f), docMimeOf($f['ext']), $f['name'], $f['sha256'], $inline); }
function docExtCheck($inc) { $c = docsCfg()['ext']; if (!$c['on']) fail('Dokumente für Externe sind ausgeschaltet.', 403);
  if ($inc['ended_at'] !== null) out(['ended' => 1, 'kind_name' => kindOf($inc)['name']]); if (!feature($inc, 'docs')) fail('Dokumente sind für diesen Einsatz ausgeschaltet.', 403); }
if ($route === 'ext/doc' || $route === 'ext/doc/get') { header('Referrer-Policy: no-referrer'); $k = (string)($_GET['k'] ?? '');
  if (!preg_match('/^[0-9a-f]{32}$/', $k)) fail('Dieser Link ist ungültig.', 404);
  $s = $db->prepare("SELECT * FROM doc_ext WHERE token=?"); $s->execute([$k]); $x = $s->fetch(); $s->closeCursor();
  if (!$x || $x['removed'] !== null) fail('Dieser Link ist ungültig oder wurde entfernt.', 404);
  $inc = incById($x['incident_id']); docExtCheck($inc); if (!docsCfg()['ext']['link']) fail('Links für Externe sind ausgeschaltet.', 403);
  if ($route === 'ext/doc/get') docExtGet($x, $inc);
  $kk = kindOf($inc); out(docExtOut($x, ['incident' => ['kind_name' => $kk['name'], 'kind_color' => $kk['color'], 'alarm_at' => (int)$inc['alarm_at']]])); }
if ($route === 'guest/docs' || $route === 'guest/doc' || $route === 'guest/doc/get') { [$g, $inc] = voiceGuest(); docExtCheck($inc); if (!docsCfg()['ext']['guest']) out(['docs' => []]);
  $s = $db->prepare("SELECT x.*,d.title,d.kind FROM doc_ext x JOIN docs d ON d.id=x.doc_id WHERE x.incident_id=? AND x.removed IS NULL AND (x.guest_id IS NULL OR x.guest_id=?) AND d.active=1 ORDER BY x.at"); $s->execute([$inc['id'], $g['id']]); $rows = $s->fetchAll();
  if ($route === 'guest/docs') out(['docs' => array_map(fn($x) => ['id' => (int)$x['id'], 'title' => $x['title'], 'kind' => $x['kind']], $rows)]);
  $x = null; foreach ($rows as $r) if ((int)$r['id'] === (int)($_GET['id'] ?? 0)) $x = $r; if (!$x) fail('Dokument nicht gefunden', 404);
  if ($route === 'guest/doc/get') docExtGet($x, $inc); out(docExtOut($x, [])); }

/* ---------- Vorschläge ---------- */
function docSugRows($mine) { global $db, $uid; $r = [];
  $s = $mine ? $db->prepare("SELECT s.*,u.name uname,d.title dtitle FROM doc_sug s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN docs d ON d.id=s.doc_id WHERE s.user_id=? ORDER BY s.at DESC") : $db->prepare("SELECT s.*,u.name uname,d.title dtitle FROM doc_sug s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN docs d ON d.id=s.doc_id ORDER BY s.at DESC");
  $s->execute($mine ? [$uid] : []); $fo = docFolders();
  foreach ($s->fetchAll() as $x) { $x = ints($x, ['id', 'folder_id', 'bytes', 'at']); if (!$mine && !docEdit($x['folder_id'])) continue;
    $r[] = ['id' => $x['id'], 'folder_id' => $x['folder_id'], 'folder' => $fo[$x['folder_id']]['name'] ?? '?', 'doc_id' => $x['doc_id'] === null ? null : (int)$x['doc_id'], 'doc_title' => $x['dtitle'], 'kind' => $x['kind'], 'title' => $x['title'], 'note' => $x['note'],
      'file' => $x['file'] !== null ? ['name' => $x['name'], 'ext' => $x['ext'], 'bytes' => $x['bytes'], 'show' => docShow((string)$x['ext'])] : null, 'user' => $x['uname'], 'user_id' => $x['user_id'] === null ? null : (int)$x['user_id'], 'at' => $x['at'],
      'state' => $x['state'], 'reason' => $x['reason'], 'done_at' => $x['done_at'] === null ? null : (int)$x['done_at'], 'result_id' => $x['result_id'] === null ? null : (int)$x['result_id']]; }
  return $r; }
function docSugRow($id) { global $db; $s = $db->prepare("SELECT * FROM doc_sug WHERE id=?"); $s->execute([(int)$id]); $x = $s->fetch(); $s->closeCursor(); if (!$x) fail('Vorschlag nicht gefunden', 404);
  if (!docEdit((int)$x['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); return $x; }
function docSugDelete($x) { global $db; if ($x['file']) @unlink(docDir() . '/sug/' . basename((string)$x['file'])); $db->prepare("DELETE FROM doc_sug WHERE id=?")->execute([$x['id']]); }
// Redaktion des Ordners (für Push bei neuen Vorschlägen)
function docEditors($fid) { $r = []; foreach (docMem() as $u => $m) if (docEditM($fid, $m) || in_array('docs', permsOf(userRoles($u)), true)) $r[] = $u; return $r; }
// Datei eines Vorschlags bzw. Hochladen prüfen und an ihren Ort bringen
function docUpFinish($in) { global $uid;
  $p = docTmp($in['up'] ?? ''); $want = (int)($in['size'] ?? -1);
  if (!is_file($p)) { alog('warn', 'docs', 'Hochladen unvollständig: keine Teile angekommen', ['size' => $want, 'parts' => (int)($in['parts'] ?? -1)]); fail('Hochladen unvollständig (keine Teile angekommen), bitte nochmals versuchen'); }
  $name = trim(basename(str_replace('\\', '/', (string)($in['name'] ?? '')))); $ex = docExtOk($name);
  if (!$ex) { @unlink($p); alog('warn', 'docs', 'Datei mit nicht erlaubtem Typ abgelehnt', ['ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION))]); fail('Dieser Dateityp ist nicht erlaubt'); }
  clearstatcache(); $sz = filesize($p); if ($sz !== $want) { @unlink($p); alog('warn', 'docs', 'Hochladen unvollständig: Grösse stimmt nicht', ['size' => $want, 'got' => $sz, 'parts' => (int)($in['parts'] ?? -1)]);
    fail('Hochladen unvollständig (' . $sz . ' von ' . $want . ' Bytes angekommen), bitte nochmals versuchen'); }
  if (hash_file('sha256', $p) !== strtolower((string)($in['sha'] ?? ''))) { @unlink($p); fail('Prüfsumme stimmt nicht, bitte nochmals hochladen'); }
  if (!docSniff($p, $ex[0])) { @unlink($p); alog('warn', 'docs', 'Datei abgelehnt: Inhalt passt nicht zur Endung', ['ext' => $ex[0]]); fail('Der Inhalt der Datei passt nicht zur Endung «.' . $ex[0] . '» (oder sie enthält Makros)'); }
  return [$p, $name, $ex[0], $sz, hash_file('sha256', $p)]; }
// Text der Seiten (PDF, im Browser mit pdf.js gelesen) bzw. der Textdatei für die Suche
function docTextIn($v, $p, $ext) { if (in_array($ext, ['txt', 'md', 'csv'], true)) return [mb_substr((string)@file_get_contents($p, false, null, 0, 2000000), 0, 500000)];
  if (!is_array($v)) return null; $r = []; $n = 0; foreach ($v as $t) { $t = mb_substr(preg_replace('/\s+/u', ' ', (string)$t), 0, 50000); $n += strlen($t); if ($n > 3000000 || count($r) >= 2000) break; $r[] = $t; } return $r; }
// neue Version einer Datei anlegen; ältere über keep hinaus löschen; Lesebestätigung erneut verlangen
function docAddVersion($d, $p, $name, $ext, $sz, $sha, $text, $pages, $major, $move = true) { global $db, $uid, $now;
  $v = (int)$db->query("SELECT COALESCE(MAX(ver),0) FROM doc_files WHERE doc_id=" . (int)$d['id'])->fetchColumn() + 1; $fn = $v . '.' . $ext; $dst = docDir($d['id']) . '/' . $fn;
  if (!($move ? @rename($p, $dst) : @copy($p, $dst))) fail('Datei konnte nicht gespeichert werden', 500);
  $db->prepare("INSERT INTO doc_files(doc_id,ver,name,ext,mime,bytes,pages,sha256,file,text,at,by_user) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$d['id'], $v, $name, $ext, docMimeOf($ext), $sz, $pages, $sha, $fn, $text === null ? null : json_encode($text, JSON_UNESCAPED_UNICODE), $now, $uid]);
  $db->prepare("UPDATE docs SET ver=?, updated=?, updated_by=?, search=? WHERE id=?")->execute([$v, $now, $uid, docSearchText($d, $text), $d['id']]);
  docPrune($d['id'], $v);
  if ($d['ack'] && (!$d['ack_major'] || $major)) docAckRelease($d['id']);
  return $v; }
function docPrune($id, $cur) { global $db; $keep = docsCfg()['keep']; $s = $db->prepare("SELECT id,file FROM doc_files WHERE doc_id=? AND ver<>? ORDER BY ver DESC"); $s->execute([$id, $cur]);
  foreach (array_slice($s->fetchAll(), $keep) as $f) { @unlink(docDir($id) . '/' . basename((string)$f['file'])); $db->prepare("DELETE FROM doc_files WHERE id=?")->execute([$f['id']]); } }
function docSearchText($d, $text = null) { $t = $d['title'] . ' ' . $d['descr'] . ' ' . $d['tags'] . ' ' . ($d['kind'] === 'page' ? $d['body'] : '') . ' ' . ($d['kind'] === 'link' ? $d['url'] : '');
  if ($text) $t .= ' ' . implode(' ', $text); return mb_substr($t, 0, 3000000); }
// Suche: alle Wörter müssen vorkommen; Fundstelle mit Seitenzahl bei PDF
function docSearch($q) { global $db; $w = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), fn($x) => mb_strlen($x) >= 2)); if (!$w) return [];
  $docs = docsVisible(); $hits = [];
  foreach ($docs as $id => $d) { $s = $db->prepare("SELECT search FROM docs WHERE id=?"); $s->execute([$id]); $t = mb_strtolower((string)$s->fetchColumn());
    $ok = true; foreach ($w as $x) if (mb_strpos($t, $x) === false) { $ok = false; break; } if (!$ok) continue;
    $head = mb_strtolower($d['title'] . ' ' . $d['tags']); $score = 0; foreach ($w as $x) if (mb_strpos($head, $x) !== false) $score += 10;
    $where = null; $page = null; $snip = '';
    foreach (['title' => $d['title'], 'tags' => (string)$d['tags'], 'descr' => (string)$d['descr'], 'body' => $d['kind'] === 'page' ? (string)$d['body'] : ''] as $k => $v) if (($p = mb_stripos($v, $w[0])) !== false) { $where = $k; $snip = docSnip($v, $p); break; }
    if ($where === null || $where === 'title' || $where === 'tags') { $f = docFile($d); if ($f && $f['text'] !== null) foreach ((array)json_decode((string)$f['text'], true) as $n => $pt) if (($p = mb_stripos((string)$pt, $w[0])) !== false) { if ($where === null) $where = 'file'; $page = count((array)json_decode((string)$f['text'], true)) > 1 ? $n + 1 : null; if ($where === 'file') $snip = docSnip((string)$pt, $p); break; } }
    $hits[] = ['id' => $id, 'where' => $where, 'page' => $page, 'snip' => $snip, 'score' => $score]; }
  usort($hits, fn($a, $b) => $b['score'] <=> $a['score']); return array_slice($hits, 0, 100); }
function docSnip($t, $p) { $a = max(0, $p - 60); $s = mb_substr($t, $a, 160); return ($a ? '…' : '') . trim(preg_replace('/\s+/u', ' ', $s)) . (mb_strlen($t) > $a + 160 ? '…' : ''); }

/* ---------- angemeldete Routen ---------- */
function docRoute($route) { global $db, $in, $uid, $me, $now;
  if (!(str_starts_with($route, 'doc') || str_starts_with($route, 'incident/doc/'))) return;
  docAuto();
  $id = (int)($in['id'] ?? $_GET['id'] ?? 0);
  switch ($route) {
  case 'docs': // Baum und Einträge; q = Suche, all = mit deaktivierten (Redaktion), kid = Einsatztyp für die Reihenfolge der Abschnitte
    $all = !empty($_GET['all']) && docEditAny(); $docs = docsVisible($all);
    $view = null; if (docsCfg()['own']) { $s = $db->prepare("SELECT kid,secs FROM doc_view WHERE user_id=?"); $s->execute([$uid]); $view = []; foreach ($s->fetchAll() as $x) $view[(string)(int)$x['kid']] = docSecsIn(json_decode((string)$x['secs'], true)); }
    $o = ['folders' => docTree($all), 'docs' => docShorts($docs), 'cfg' => docCfgPub(), 'my_view' => $view ? (object)$view : null];
    if (isset($_GET['q'])) $o['hits'] = docSearch((string)$_GET['q']);
    if ($all) $o += docMeta();
    out($o);
  case 'doc': // ein Eintrag mit Versionen, Bildern der Seite und eigenem Stand
    $d = docRow($id); if (!docSee($d['folder_id']) || (!$d['active'] && !docEdit($d['folder_id']))) fail('Dafür fehlt dir das Recht', 403); $ed = docEdit($d['folder_id']);
    $db->prepare($GLOBALS['my'] ? "INSERT INTO doc_user(user_id,doc_id,seen_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)" : "INSERT INTO doc_user(user_id,doc_id,seen_at) VALUES(?,?,?) ON CONFLICT(user_id,doc_id) DO UPDATE SET seen_at=excluded.seen_at")->execute([$uid, $id, $now]);
    $s = $db->prepare("SELECT id,data FROM doc_imgs WHERE doc_id=?"); $s->execute([$id]); $imgs = []; foreach ($s->fetchAll() as $i) $imgs[(int)$i['id']] = $i['data'];
    $s = $db->prepare("SELECT f.id,f.ver,f.name,f.ext,f.bytes,f.pages,f.sha256,f.at,f.text IS NOT NULL has_text,u.name by_name FROM doc_files f LEFT JOIN users u ON u.id=f.by_user WHERE f.doc_id=? ORDER BY f.ver DESC"); $s->execute([$id]);
    $files = array_map(fn($f) => ints($f, ['id', 'ver', 'bytes', 'pages', 'at', 'has_text']) + ['show' => docShow($f['ext'])], $s->fetchAll()); if (!$ed) $files = array_values(array_filter($files, fn($f) => $f['ver'] === $d['ver']));
    $s = $db->prepare("SELECT p.id,p.name,p.plan_id FROM place_docs x JOIN places p ON p.id=x.place_id WHERE x.doc_id=? ORDER BY p.name"); $s->execute([$id]);
    $sh = docShorts([$id => $d])[0]; $u = $db->prepare("SELECT name FROM users WHERE id=?"); $nm = function ($x) use ($u) { if ($x === null) return null; $u->execute([$x]); return $u->fetchColumn() ?: null; };
    $o = $sh + ['body' => $d['kind'] === 'page' ? (string)$d['body'] : null, 'url' => $d['url'], 'imgs' => (object)$imgs, 'files' => $files, 'place_list' => array_map(fn($p) => ints($p, ['id']) + ['plan_id' => $p['plan_id'] === null ? null : (int)$p['plan_id']], $s->fetchAll()),
      'edit' => $ed ? 1 : 0, 'sug' => docSugOk($d['folder_id']) ? 1 : 0, 'created' => $d['created'], 'created_by' => $nm($d['created_by']), 'updated_by' => $nm($d['updated_by']), 'ext_ok' => docExtFolder($d['folder_id']) ? 1 : 0,
      'path' => array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name']], docChain($d['folder_id']))];
    $mu = docUserOf($uid, [$id])[$id] ?? null; $o['my_ack'] = $mu && $mu['ack_ver'] !== null && (int)$mu['ack_ver'] >= $d['ack_ver'] ? (int)$mu['ack_at'] : null; // eigene Bestätigung (auch freiwillig)
    if ($ed) $o['ackcfg'] = array_intersect_key($d, array_flip(['ack', 'ack_ver', 'ack_at', 'ack_by', 'ack_grp', 'ack_role', 'ack_days', 'ack_new', 'ack_major', 'ack_skill']));
    out($o);
  case 'doc/get': // Datei (kein JSON); ver = frühere Version (Redaktion), sug = Datei eines Vorschlags (Redaktion), dl = herunterladen statt anzeigen
    if (!empty($_GET['sug'])) { $x = docSugRow((int)$_GET['sug']); if (!$x['file']) fail('Kein Anhang', 404); docSend(docDir() . '/sug/' . basename($x['file']), docMimeOf((string)$x['ext']), (string)$x['name'], (string)$x['sha256'], docInline((string)$x['ext']) && empty($_GET['dl'])); }
    $d = docRow($id); if (!docSee($d['folder_id']) || (!$d['active'] && !docEdit($d['folder_id']))) fail('Dafür fehlt dir das Recht', 403);
    $v = isset($_GET['ver']) ? (int)$_GET['ver'] : $d['ver']; if ($v !== $d['ver'] && !docEdit($d['folder_id'])) fail('Dafür fehlt dir das Recht', 403);
    $f = docFile($d, $v) ?: fail('Datei nicht gefunden', 404); docSend(docFilePath($f), docMimeOf($f['ext']), $f['name'], $f['sha256'], docInline($f['ext']) && empty($_GET['dl']));
  case 'doc/save': // Eintrag anlegen bzw. ändern; major = wesentliche Änderung (Seite/Link: neue Version)
    $d = $id ? docRow($id) : null; $fid = (int)($in['folder_id'] ?? ($d['folder_id'] ?? 0)); if (!isset(docFolders()[$fid])) fail('Ordner nicht gefunden');
    if (!docEdit($fid) || ($d && !docEdit($d['folder_id']))) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    $kind = $d ? $d['kind'] : (string)($in['kind'] ?? 'page'); if (!in_array($kind, ['file', 'page', 'link'], true)) fail('Ungültige Art');
    $title = trim((string)($in['title'] ?? ($d['title'] ?? ''))); if ($title === '' || mb_strlen($title) > 200) fail('Bitte einen Titel angeben (höchstens 200 Zeichen)');
    $url = $kind === 'link' ? trim((string)($in['url'] ?? ($d['url'] ?? ''))) : null; if ($kind === 'link' && !preg_match('~^https?://[^\s<>"]+$~i', $url)) fail('Bitte eine Adresse mit http:// oder https:// angeben');
    $body = $kind === 'page' ? (string)($in['body'] ?? ($d['body'] ?? '')) : null; if ($body !== null && strlen($body) > 2000000) fail('Der Text ist zu lang');
    $kinds = array_key_exists('kinds', $in) ? array_values(array_intersect(idsIn($in['kinds']), array_keys(kinds()))) : ($d['kinds'] ?? []);
    $rev = array_key_exists('review_at', $in) ? ($in['review_at'] === null || $in['review_at'] === '' ? null : (int)$in['review_at']) : ($d['review_at'] ?? null);
    $off = array_key_exists('offline', $in) ? max(0, min(2, (int)$in['offline'])) : ($d['offline'] ?? 1); $act = array_key_exists('active', $in) ? ((int)$in['active'] ? 1 : 0) : ($d['active'] ?? 1);
    $descr = mb_substr(trim((string)($in['descr'] ?? ($d['descr'] ?? ''))), 0, 4000); $tags = mb_substr(trim((string)($in['tags'] ?? ($d['tags'] ?? ''))), 0, 500);
    $chg = $d && (($kind === 'page' && $body !== (string)$d['body']) || ($kind === 'link' && $url !== $d['url'])); $ver = $d ? $d['ver'] + ($chg ? 1 : 0) : ($kind === 'file' ? 0 : 1);
    if ($d) { if ($d['folder_id'] !== $fid) $db->prepare("UPDATE docs SET sort=NULL WHERE id=?")->execute([$id]);
      $db->prepare("UPDATE docs SET folder_id=?, title=?, descr=?, tags=?, body=?, url=?, kinds=?, review_at=?, offline=?, active=?, ver=?, updated=?, updated_by=? WHERE id=?")->execute([$fid, $title, $descr, $tags, $body, $url, json_encode($kinds), $rev, $off, $act, $ver, $now, $uid, $id]); }
    else { $db->prepare("INSERT INTO docs(folder_id,kind,title,descr,tags,body,url,kinds,review_at,offline,active,ver,ack_by,created,created_by,updated,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'read',?,?,?,?)")->execute([$fid, $kind, $title, $descr, $tags, $body, $url, json_encode($kinds), $rev, $off, $act, $ver, $now, $uid, $now, $uid]); $id = (int)$db->lastInsertId(); }
    if (array_key_exists('places', $in)) { $db->prepare("DELETE FROM place_docs WHERE doc_id=?")->execute([$id]); $ok = array_map('intval', $db->query("SELECT id FROM places")->fetchAll(PDO::FETCH_COLUMN));
      $s = $db->prepare("INSERT INTO place_docs(place_id,doc_id) VALUES(?,?)"); foreach (array_slice(array_intersect(idsIn($in['places']), $ok), 0, 200) as $p) $s->execute([$p, $id]); }
    $nd = docRow($id); $f = $kind === 'file' ? docFile($nd) : null; $db->prepare("UPDATE docs SET search=? WHERE id=?")->execute([docSearchText($nd, $f && $f['text'] !== null ? json_decode($f['text'], true) : null), $id]);
    if ($chg && $nd['ack'] && (!$nd['ack_major'] || !empty($in['major']))) docAckRelease($id); elseif ($nd['ack'] && $d && !$d['active'] && $act) docAckSync($nd);
    out(['ok' => true, 'id' => $id, 'ver' => $ver]);
  case 'doc/active': $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    $db->prepare("UPDATE docs SET active=?, updated=?, updated_by=? WHERE id=?")->execute([(int)($in['on'] ?? 0) ? 1 : 0, $now, $uid, $id]); out(['ok' => true]);
  case 'doc/move': $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    sortMove('docs', "SELECT id FROM docs WHERE folder_id=? ORDER BY CASE WHEN sort IS NULL THEN 1 ELSE 0 END, sort, title", [$d['folder_id']], $id, (int)($in['dir'] ?? 0)); out(['ok' => true]);
  case 'doc/upload/put': // ein Stück: up (Kennung), n, data (Base64); beim ersten Stück name, size und Ziel (doc_id bzw. sug = Ordner) zur Prüfung vor dem Hochladen
    $p = docTmp($in['up'] ?? ''); $n = (int)($in['n'] ?? -1); $c = docsCfg();
    if ($n === 0) { $ex = docExtOk((string)($in['name'] ?? '')); if (!$ex) { alog('warn', 'docs', 'Datei mit nicht erlaubtem Typ abgelehnt', ['ext' => strtolower(pathinfo((string)($in['name'] ?? ''), PATHINFO_EXTENSION))]); fail('Dieser Dateityp ist nicht erlaubt'); }
      $sz = (int)($in['size'] ?? 0); if ($sz < 1) fail('Leere Datei'); if ($sz > $c['max_mb'] * 1048576) fail('Die Datei ist grösser als ' . $c['max_mb'] . ' MB');
      if (docUsage() + $sz > $c['quota_mb'] * 1048576) { alog('warn', 'docs', 'Speicher der Dokumentenablage voll', ['quota_mb' => $c['quota_mb']]); fail('Der Speicher der Dokumentenablage ist voll'); }
      if (!empty($in['doc_id'])) { $d = docRow($in['doc_id']); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); }
      elseif (!empty($in['sug'])) { if (!docSugOk((int)$in['sug'])) fail('In diesem Ordner darfst du nichts vorschlagen', 403); }
      else fail('Ziel fehlt');
      @unlink($p); }
    $d64 = (string)($in['data'] ?? ''); $raw = base64_decode($d64, true); if ($n < 0 || $raw === false || $raw === '' || strlen($raw) > DOC_CHUNK) fail('Ungültiges Stück');
    if ($n > 0 && !is_file($p)) { alog('warn', 'docs', 'Hochladen unvollständig: erstes Teil fehlt', ['n' => $n]); fail('Hochladen unvollständig (erstes Teil fehlt), bitte nochmals versuchen'); }
    if (($n + 1) * DOC_CHUNK > $c['max_mb'] * 1048576 + DOC_CHUNK) fail('Die Datei ist zu gross');
    $fp = fopen($p, 'c+'); if (!$fp) fail('Datei konnte nicht gespeichert werden', 500); fseek($fp, $n * DOC_CHUNK); $w = fwrite($fp, $raw); fclose($fp);
    if ($w !== strlen($raw)) { alog('err', 'docs', 'Teil der Datei konnte nicht geschrieben werden', ['n' => $n, 'len' => strlen($raw), 'written' => $w]); fail('Datei konnte nicht gespeichert werden (Speicherplatz?)', 500); }
    clearstatcache(); out(['ok' => true, 'have' => filesize($p)]);
  case 'doc/upload/done': // alle Stücke da: up, name, size, sha; doc_id = neue Version (text = Seitentexte, pages, major) bzw. sug = {folder_id, doc_id, kind, title, note}
    if (!empty($in['sug'])) { $sg = (array)$in['sug']; $fid = (int)($sg['folder_id'] ?? 0); if (!docSugOk($fid)) fail('In diesem Ordner darfst du nichts vorschlagen', 403);
      [$p, $name, $ext, $sz, $sha] = docUpFinish($in); $out = docSugAdd($sg, [$p, $name, $ext, $sz, $sha]); out($out); }
    $d = docRow($in['doc_id'] ?? 0); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); if ($d['kind'] !== 'file') fail('Nur Einträge der Art Datei haben Dateien');
    [$p, $name, $ext, $sz, $sha] = docUpFinish($in); $v = docAddVersion($d, $p, $name, $ext, $sz, $sha, docTextIn($in['text'] ?? null, $p, $ext), isset($in['pages']) ? max(0, min(100000, (int)$in['pages'])) : null, !empty($in['major']));
    out(['ok' => true, 'ver' => $v]);
  case 'doc/text': // Text der Seiten nachreichen (z. B. nach «Text erkennen»), id = Eintrag
    $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); $f = docFile($d) ?: fail('Keine Datei');
    $t = docTextIn($in['text'] ?? null, docFilePath($f), $f['ext']); $db->prepare("UPDATE doc_files SET text=?, pages=COALESCE(?,pages) WHERE id=?")->execute([$t === null ? null : json_encode($t, JSON_UNESCAPED_UNICODE), isset($in['pages']) ? (int)$in['pages'] : null, $f['id']]);
    $db->prepare("UPDATE docs SET search=? WHERE id=?")->execute([docSearchText($d, $t), $d['id']]); out(['ok' => true]);
  case 'doc/ver/remove': case 'doc/ver/restore': // id = Zeile in doc_files
    $s = $db->prepare("SELECT * FROM doc_files WHERE id=?"); $s->execute([$id]); $f = $s->fetch() ?: fail('Version nicht gefunden', 404); $d = docRow($f['doc_id']); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    if ((int)$f['ver'] === $d['ver']) fail('Die aktuelle Version lässt sich nicht ' . ($route === 'doc/ver/remove' ? 'entfernen' : 'wiederherstellen'));
    if ($route === 'doc/ver/remove') { @unlink(docFilePath($f)); $db->prepare("DELETE FROM doc_files WHERE id=?")->execute([$id]); out(['ok' => true]); }
    if (docUsage() + (int)$f['bytes'] > docsCfg()['quota_mb'] * 1048576) fail('Der Speicher der Dokumentenablage ist voll');
    $v = docAddVersion($d, docFilePath($f), $f['name'], $f['ext'], (int)$f['bytes'], $f['sha256'], $f['text'] === null ? null : json_decode($f['text'], true), $f['pages'] === null ? null : (int)$f['pages'], !empty($in['major']), false); out(['ok' => true, 'ver' => $v]);
  case 'doc/img/add': // Bild in einer Seite (Data-URL, im Browser verkleinert), Marke ![…](img:<id>)
    $d = docRow($in['doc_id'] ?? 0); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    $data = (string)($in['data'] ?? ''); if (!preg_match('~^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$~', $data) || strlen($data) > 4000000) fail('Ungültiges Bild (JPEG oder PNG, höchstens 3 MB)');
    $db->prepare("INSERT INTO doc_imgs(doc_id,data,w,h,at,by_user) VALUES(?,?,?,?,?,?)")->execute([$d['id'], $data, (int)($in['w'] ?? 0), (int)($in['h'] ?? 0), $now, $uid]); out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
  case 'doc/img/remove': $s = $db->prepare("SELECT doc_id FROM doc_imgs WHERE id=?"); $s->execute([$id]); $di = $s->fetchColumn() ?: fail('Bild nicht gefunden', 404); $d = docRow($di);
    if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); $db->prepare("DELETE FROM doc_imgs WHERE id=?")->execute([$id]); out(['ok' => true]);
  case 'doc/folder/save': need('docs'); // Ordner anlegen/ändern; Freigaben, Redaktion, Vorschläge, Externe
    $f = $id ? (docFolders()[$id] ?? fail('Ordner nicht gefunden', 404)) : null;
    $par = array_key_exists('parent_id', $in) ? ($in['parent_id'] === null || (int)$in['parent_id'] === 0 ? null : (int)$in['parent_id']) : ($f['parent_id'] ?? null);
    if ($par !== null) { if (!isset(docFolders()[$par])) fail('Übergeordneter Ordner nicht gefunden'); if ($id && in_array($id, array_column(docChain($par), 'id'), true)) fail('Ein Ordner kann nicht in sich selbst liegen'); }
    $name = trim((string)($in['name'] ?? ($f['name'] ?? ''))); if ($name === '' || mb_strlen($name) > 100) fail('Bitte einen Namen angeben (höchstens 100 Zeichen)');
    $gIds = array_map('intval', $db->query("SELECT id FROM inc_groups")->fetchAll(PDO::FETCH_COLUMN)); $rAll = $db->query("SELECT id,perms,sys FROM uroles")->fetchAll(); $rIds = array_map(fn($r) => (int)$r['id'], $rAll);
    $rOk = array_map(fn($r) => (int)$r['id'], array_filter($rAll, fn($r) => permsMine(rolePerms($r))));
    $lst = function ($k, $ids) use ($in, $f, $rOk) { if (!array_key_exists($k, $in)) return $f[$k] ?? null; if ($in[$k] === null) return null; $v = array_values(array_intersect(idsIn($in[$k]), $ids));
      if (str_ends_with($k, '_role')) foreach (array_diff($v, $rOk, $f[$k] ?? []) as $x) fail('Benutzerrollen mit Rechten, die du selbst nicht hast, kannst du nicht wählen'); return $v; };
    $v = [$lst('read_grp', $gIds), $lst('read_role', $rIds), $lst('edit_grp', $gIds), $lst('edit_role', $rIds), $lst('sug_grp', $gIds), $lst('sug_role', $rIds)];
    $sug = (string)($in['sug'] ?? ($f['sug'] ?? 'all')); if (!isset(DOC_SUG[$sug])) $sug = 'all';
    $row = [$par, $name, mb_substr(trim((string)($in['descr'] ?? ($f['descr'] ?? ''))), 0, 2000), array_key_exists('active', $in) ? ((int)$in['active'] ? 1 : 0) : ($f['active'] ?? 1)];
    $js = array_map(fn($x) => $x === null ? null : json_encode($x), $v); $ext = array_key_exists('ext', $in) ? ((int)$in['ext'] ? 1 : 0) : ($f['ext'] ?? 0);
    if ($f) { if ($f['parent_id'] !== $par) $db->prepare("UPDATE doc_folders SET sort=NULL WHERE id=?")->execute([$id]);
      $db->prepare("UPDATE doc_folders SET parent_id=?, name=?, descr=?, active=?, read_grp=?, read_role=?, edit_grp=?, edit_role=?, sug_grp=?, sug_role=?, sug=?, ext=? WHERE id=?")->execute(array_merge($row, $js, [$sug, $ext, $id])); }
    else { $db->prepare("INSERT INTO doc_folders(parent_id,name,descr,active,read_grp,read_role,edit_grp,edit_role,sug_grp,sug_role,sug,ext,created,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($row, $js, [$sug, $ext, $now, $uid])); $id = (int)$db->lastInsertId(); }
    docFolders(true); foreach ($db->query("SELECT * FROM docs WHERE ack=1 AND active=1")->fetchAll() as $x) docAckSync(docNorm($x)); out(['ok' => true, 'id' => $id]);
  case 'doc/folder/move': need('docs'); $f = docFolders()[$id] ?? fail('Ordner nicht gefunden', 404);
    $f['parent_id'] === null ? sortMove('doc_folders', "SELECT id FROM doc_folders WHERE parent_id IS NULL ORDER BY CASE WHEN sort IS NULL THEN 1 ELSE 0 END, sort, name", [], $id, (int)($in['dir'] ?? 0))
      : sortMove('doc_folders', "SELECT id FROM doc_folders WHERE parent_id=? ORDER BY CASE WHEN sort IS NULL THEN 1 ELSE 0 END, sort, name", [$f['parent_id']], $id, (int)($in['dir'] ?? 0)); out(['ok' => true]);
  case 'doc/folder/count': need('docs'); // wie viele aktive Personen sehen einen Ordner mit diesen Freigaben (Vorschau im Dialog)
    $par = (int)($in['parent_id'] ?? 0) ?: null; $g = $in['read_grp'] ?? null; $r = $in['read_role'] ?? null; $g = $g === null ? null : idsIn($g); $r = $r === null ? null : idsIn($r); $n = 0; $all = docMem();
    foreach ($all as $m) if (($par === null || docSeeM($par, $m)) && docRule($g, $r, $m)) $n++; out(['n' => $n, 'all' => count($all)]);
  case 'doc/fav': $d = docRow($id); if (!docSee($d['folder_id'])) fail('Dafür fehlt dir das Recht', 403); $on = (int)($in['on'] ?? 0) ? 1 : 0;
    $db->prepare($GLOBALS['my'] ? "INSERT INTO doc_user(user_id,doc_id,fav) VALUES(?,?,?) ON DUPLICATE KEY UPDATE fav=VALUES(fav)" : "INSERT INTO doc_user(user_id,doc_id,fav) VALUES(?,?,?) ON CONFLICT(user_id,doc_id) DO UPDATE SET fav=excluded.fav")->execute([$uid, $id, $on]); out(['ok' => true]);
  case 'doc/ack': // gelesen und verstanden (verlangt oder freiwillig); bei verknüpfter Fähigkeit Eintrag in der Ausbildung
    $d = docRow($id); if (!$d['active'] || !docSee($d['folder_id'])) fail('Dafür fehlt dir das Recht', 403); if (!$d['ack']) fail('Für diesen Eintrag ist keine Lesebestätigung eingerichtet');
    $must = docAckMust($d, docMem($uid)) ? 1 : 0;
    $db->prepare($GLOBALS['my'] ? "INSERT INTO doc_user(user_id,doc_id,ack_at,ack_ver,ack_free) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE ack_at=VALUES(ack_at), ack_ver=VALUES(ack_ver), ack_free=VALUES(ack_free)" : "INSERT INTO doc_user(user_id,doc_id,ack_at,ack_ver,ack_free) VALUES(?,?,?,?,?) ON CONFLICT(user_id,doc_id) DO UPDATE SET ack_at=excluded.ack_at, ack_ver=excluded.ack_ver, ack_free=excluded.ack_free")->execute([$uid, $id, $now, $d['ver'], $must ? 0 : 1]);
    if ($d['ack_skill'] !== null && ($k = docSkill($d['ack_skill'])) && $k['active']) $db->prepare("INSERT INTO user_skills(user_id,skill_id,done_at,note,created_by,created_at) VALUES(?,?,?,?,?,?)")->execute([$uid, $k['id'], $now, mb_substr('Lesebestätigung «' . $d['title'] . '» Version ' . $d['ver'], 0, 180), $uid, $now]);
    out(['ok' => true, 'free' => $must ? 0 : 1]);
  case 'doc/acks': // Stand der Bestätigungen (Redaktion): verlangt (mit Stand), freiwillig, Zahl der Lesenden
    $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); docAckSync($d); [$st, $du] = docAckList($d);
    $nm = []; foreach ($db->query("SELECT id,name FROM users")->fetchAll() as $u) $nm[(int)$u['id']] = $u['name']; $l = []; foreach ($st as $u => $x) $l[] = ['user_id' => $u, 'name' => $nm[$u] ?? '?'] + $x;
    $free = []; foreach ($du as $u => $x) if (!isset($st[$u]) && $x['ack_at'] !== null && (int)$x['ack_ver'] >= $d['ack_ver']) $free[] = ['user_id' => $u, 'name' => $nm[$u] ?? '?', 'at' => (int)$x['ack_at']];
    $rd = 0; foreach (docMem() as $m) if (docSeeM($d['folder_id'], $m)) $rd++;
    usort($l, fn($a, $b) => [$a['st'] === 'ok', $a['name']] <=> [$b['st'] === 'ok', $b['name']]); out(['list' => $l, 'free' => $free, 'readers' => $rd, 'ack_ver' => $d['ack_ver'], 'ack_at' => $d['ack_at'], 'title' => $d['title']]);
  case 'doc/ack/cfg': // Lesebestätigung einstellen; dry = nur zählen; renew = jetzt erneut verlangen
    $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403);
    $c = ['ack' => (int)($in['ack'] ?? 0) ? 1 : 0, 'ack_by' => in_array($in['ack_by'] ?? '', ['read', 'list', 'skill'], true) ? $in['ack_by'] : 'read', 'ack_grp' => ($in['ack_grp'] ?? null) === null ? null : idsIn($in['ack_grp']), 'ack_role' => ($in['ack_role'] ?? null) === null ? null : idsIn($in['ack_role']),
      'ack_days' => !empty($in['ack_days']) ? max(1, min(3650, (int)$in['ack_days'])) : null, 'ack_new' => (int)($in['ack_new'] ?? 1) ? 1 : 0, 'ack_major' => (int)($in['ack_major'] ?? 0) ? 1 : 0, 'ack_skill' => !empty($in['ack_skill']) ? (int)$in['ack_skill'] : null];
    if ($c['ack_skill'] !== null && !docSkill($c['ack_skill'])) fail('Fähigkeit nicht gefunden'); if ($c['ack_by'] === 'skill' && $c['ack_skill'] === null) fail('Bitte eine Fähigkeit wählen');
    if ($c['ack_by'] === 'list' && !$c['ack_grp'] && !$c['ack_role']) fail('Bitte mindestens eine Einsatzgruppe oder Benutzerrolle wählen');
    $t = $c + $d; $n = 0; $rd = 0; foreach (docMem() as $m) { if (docSeeM($d['folder_id'], $m)) $rd++; if (docAckMust(['ack' => 1] + $t, $m)) $n++; }
    if (!empty($in['dry'])) out(['n' => $n, 'readers' => $rd]);
    $first = !$d['ack'] && $c['ack'];
    $db->prepare("UPDATE docs SET ack=?, ack_by=?, ack_grp=?, ack_role=?, ack_days=?, ack_new=?, ack_major=?, ack_skill=? WHERE id=?")->execute([$c['ack'], $c['ack_by'], $c['ack_grp'] === null ? null : json_encode($c['ack_grp']), $c['ack_role'] === null ? null : json_encode($c['ack_role']), $c['ack_days'], $c['ack_new'], $c['ack_major'], $c['ack_skill'], $id]);
    if ($c['ack'] && ($first || !empty($in['renew']))) docAckRelease($id); elseif ($c['ack']) docAckSync(docRow($id));
    if ($c['ack'] && ($first || !empty($in['renew']))) { [$st] = docAckList(docRow($id)); $us = array_keys(array_filter($st, fn($x) => $x['st'] !== 'ok')); if ($us) pushTo($us, ['t' => '📚 ' . $d['title'], 'b' => 'Bitte lesen und bestätigen', 'tag' => 'd:' . $id] + pushLvl('normal')); }
    out(['ok' => true, 'n' => $n, 'readers' => $rd]);
  case 'doc/remind': $d = docRow($id); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); [$st] = docAckList($d); $us = array_keys(array_filter($st, fn($x) => $x['st'] !== 'ok'));
    if ($us) pushTo($us, ['t' => '📚 ' . $d['title'], 'b' => 'Erinnerung: bitte lesen und bestätigen', 'tag' => 'd:' . $id] + pushLvl('normal')); out(['ok' => true, 'n' => count($us)]);
  case 'doc/sug': // Vorschlag ohne Datei (Hinweis, neuer Link/Eintrag als Text)
    $fid = (int)($in['folder_id'] ?? 0); if (!docSugOk($fid)) fail('In diesem Ordner darfst du nichts vorschlagen', 403); out(docSugAdd($in, null));
  case 'doc/sugs': // Eingang (Redaktion) bzw. eigene (mine=1)
    if (!empty($_GET['mine'])) out(['list' => docSugRows(true)]); if (!docEditAny()) fail('Dafür fehlt dir die Redaktion eines Ordners', 403); out(['list' => docSugRows(false)]);
  case 'doc/sug/take': $x = docSugRow($id); if ($x['state'] !== 'open') fail('Dieser Vorschlag ist schon erledigt'); $res = null;
    if ($x['kind'] === 'ver' && $x['file']) { $d = docRow($x['doc_id']); if (!docEdit($d['folder_id'])) fail('Dafür fehlt dir die Redaktion dieses Ordners', 403); if ($d['kind'] !== 'file') fail('Der Eintrag hat keine Dateien');
      docAddVersion($d, docDir() . '/sug/' . basename($x['file']), $x['name'], $x['ext'], (int)$x['bytes'], $x['sha256'], null, null, !empty($in['major'])); $res = $d['id']; }
    elseif ($x['kind'] === 'new') { $kind = $x['file'] ? 'file' : 'page';
      $db->prepare("INSERT INTO docs(folder_id,kind,title,descr,body,kinds,offline,active,ver,ack_by,created,created_by,updated,updated_by) VALUES(?,?,?,?,?,'[]',1,0,?,'read',?,?,?,?)")->execute([(int)$x['folder_id'], $kind, (string)($x['title'] ?: ($x['name'] ?: 'Vorschlag')), $kind === 'file' ? (string)$x['note'] : null, $kind === 'page' ? (string)$x['note'] : null, $kind === 'file' ? 0 : 1, $now, $x['user_id'], $now, $uid]);
      $res = (int)$db->lastInsertId(); if ($x['file']) docAddVersion(docRow($res), docDir() . '/sug/' . basename($x['file']), $x['name'], $x['ext'], (int)$x['bytes'], $x['sha256'], null, null, false); }
    $db->prepare("UPDATE doc_sug SET state=?, done_by=?, done_at=?, result_id=?, file=NULL, bytes=NULL WHERE id=?")->execute([$x['kind'] === 'note' ? 'done' : 'taken', $uid, $now, $res, $id]);
    if ($x['user_id'] !== null && (int)$x['user_id'] !== $uid) pushTo([(int)$x['user_id']], ['t' => '📚 Vorschlag übernommen', 'b' => (string)($x['title'] ?: $x['name'] ?: 'Dein Vorschlag'), 'tag' => 's:' . $id] + pushLvl('low'));
    out(['ok' => true, 'doc_id' => $res]);
  case 'doc/sug/reject': case 'doc/sug/done': $x = docSugRow($id); if ($x['state'] !== 'open') fail('Dieser Vorschlag ist schon erledigt'); $rej = $route === 'doc/sug/reject';
    $why = mb_substr(trim((string)($in['reason'] ?? '')), 0, 1000); if ($rej && $why === '') fail('Bitte eine Begründung angeben');
    $db->prepare("UPDATE doc_sug SET state=?, reason=?, done_by=?, done_at=? WHERE id=?")->execute([$rej ? 'rejected' : 'done', $why === '' ? null : $why, $uid, $now, $id]);
    if ($x['user_id'] !== null && (int)$x['user_id'] !== $uid) pushTo([(int)$x['user_id']], ['t' => '📚 Vorschlag ' . ($rej ? 'abgelehnt' : 'erledigt'), 'b' => ($x['title'] ?: $x['name'] ?: 'Dein Vorschlag') . ($why !== '' ? ': ' . $why : ''), 'tag' => 's:' . $id] + pushLvl('low'));
    out(['ok' => true]);
  case 'doc/sug/remove': // eigener offener Vorschlag zurückziehen
    $s = $db->prepare("SELECT * FROM doc_sug WHERE id=? AND user_id=? AND state='open'"); $s->execute([$id, $uid]); $x = $s->fetch() ?: fail('Vorschlag nicht gefunden', 404); docSugDelete($x); out(['ok' => true]);
  case 'doc/view': if (!docsCfg()['own']) fail('Die Reihenfolge lässt sich nicht persönlich ändern', 403); $kid = (int)($in['kid'] ?? 0);
    $db->prepare("DELETE FROM doc_view WHERE user_id=? AND kid=?")->execute([$uid, $kid]);
    if (isset($in['secs']) && is_array($in['secs'])) $db->prepare("INSERT INTO doc_view(user_id,kid,secs) VALUES(?,?,?)")->execute([$uid, $kid, json_encode(docSecsIn($in['secs']))]); out(['ok' => true]);
  case 'docs/cfg': need('docs'); $c = docsCfg(); $c['view'] = (object)$c['view']; out(['cfg' => $c, 'types' => array_map(fn($t) => ['label' => $t[0], 'ext' => $t[1], 'show' => $t[2], 'def' => $t[3]], DOC_TYPES), 'never' => DOC_NEVER, 'free' => docFree(),
    'usage' => docUsage(), 'n_docs' => (int)$db->query("SELECT COUNT(*) FROM docs")->fetchColumn(), 'secs' => array_map(fn($s) => ['label' => $s[0], 'def' => $s[1]], DOC_SECS), 'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color']], kindsActive()))]);
  case 'docs/cfg/save': need('docs'); $v = is_array($in['cfg'] ?? null) ? $in['cfg'] : fail('Einstellung fehlt'); $o = json_decode((string)setting('docs', ''), true) ?: [];
    foreach (['types', 'free', 'max_mb', 'quota_mb', 'keep', 'off_mb', 'n', 'menu_n', 'own', 'view', 'ext'] as $k) if (array_key_exists($k, $v)) $o[$k] = $v[$k];
    if (isset($o['free']) && is_string($o['free'])) { $bad = array_values(array_intersect(array_map(fn($x) => strtolower(trim($x, " .\t")), explode(',', $o['free'])), DOC_NEVER)); if ($bad) fail('Nie erlaubt: .' . implode(', .', $bad)); }
    setSetting('docs', json_encode($o, JSON_UNESCAPED_UNICODE)); out(['ok' => true]);
  case 'incident/doc/ext/save': // Einsatzleitung gibt einen Eintrag für Externe frei: doc_id, guest_id (leer = alle Funk-Gäste), link = eigener Link
    $inc = activeIncident() ?: fail('Kein laufender Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung darf das', 403); $c = docsCfg()['ext']; if (!$c['on']) fail('Dokumente für Externe sind ausgeschaltet', 403);
    $d = docRow($in['doc_id'] ?? 0); if (!$d['active'] || !docSee($d['folder_id'])) fail('Dafür fehlt dir das Recht', 403); if ($c['folder_only'] && !docExtFolder($d['folder_id'])) fail('Dieser Ordner ist nicht mit Externen teilbar', 403);
    if ($d['kind'] === 'file' && !docFile($d)) fail('Der Eintrag hat noch keine Datei');
    $gid = !empty($in['guest_id']) ? (int)$in['guest_id'] : null; $link = !empty($in['link']) ? 1 : 0; if ($link && !$c['link']) fail('Links für Externe sind ausgeschaltet', 403); if (!$link && !$c['guest']) fail('Dokumente auf der Gastseite sind ausgeschaltet', 403);
    $gl = null; if ($gid) { $s = $db->prepare("SELECT label FROM voice_guests WHERE id=? AND incident_id=? AND removed IS NULL"); $s->execute([$gid, $inc['id']]); $gl = $s->fetchColumn() ?: fail('Funk-Link nicht gefunden'); }
    $tok = $link ? bin2hex(random_bytes(16)) : null;
    $db->prepare("INSERT INTO doc_ext(incident_id,doc_id,ver,guest_id,token,by_user,at) VALUES(?,?,?,?,?,?,?)")->execute([$inc['id'], $d['id'], $d['ver'], $link ? null : $gid, $tok, $uid, $now]);
    logev((int)$inc['id'], $uid, 'doc', 'gibt das Dokument «' . $d['title'] . '» für Externe frei (' . ($link ? 'eigener Link' : ($gl ? 'Funk-Link «' . $gl . '»' : 'alle Funk-Links')) . ')');
    out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'token' => $tok]);
  case 'incident/doc/ext/remove': $inc = activeIncident() ?: fail('Kein laufender Einsatz'); if (!canManage($inc)) fail('Nur die Einsatzleitung darf das', 403);
    $s = $db->prepare("SELECT x.*,d.title FROM doc_ext x JOIN docs d ON d.id=x.doc_id WHERE x.id=? AND x.incident_id=? AND x.removed IS NULL"); $s->execute([$id, $inc['id']]); $x = $s->fetch() ?: fail('Freigabe nicht gefunden', 404);
    $db->prepare("UPDATE doc_ext SET removed=? WHERE id=?")->execute([$now, $id]); logev((int)$inc['id'], $uid, 'doc', 'nimmt die Freigabe des Dokuments «' . $x['title'] . '» für Externe zurück (' . (int)$x['hits'] . ' Abrufe)'); out(['ok' => true]);
  }
}
// Vorschlag speichern (mit bzw. ohne Datei); höchstens DOC_SUG_MAX offene je Person, Push an die Redaktion
function docSugAdd($sg, $file) { global $db, $uid, $now, $me;
  $fid = (int)($sg['folder_id'] ?? 0); $kind = in_array($sg['kind'] ?? '', ['new', 'ver', 'note'], true) ? $sg['kind'] : 'note'; $did = !empty($sg['doc_id']) ? (int)$sg['doc_id'] : null;
  if ($kind !== 'new' && $did === null) fail('Eintrag fehlt'); if ($did !== null) { $d = docRow($did); if (!docSee($d['folder_id'])) fail('Dafür fehlt dir das Recht', 403); $fid = $d['folder_id']; if (!docSugOk($fid)) fail('In diesem Ordner darfst du nichts vorschlagen', 403); }
  if ($kind === 'ver' && !$file) fail('Für eine neue Version bitte eine Datei wählen');
  $title = mb_substr(trim((string)($sg['title'] ?? '')), 0, 200); $note = mb_substr(trim((string)($sg['note'] ?? '')), 0, 4000); if ($kind !== 'ver' && $title === '' && $note === '' && !$file) fail('Bitte einen Titel oder Hinweis angeben');
  $s = $db->prepare("SELECT COUNT(*) FROM doc_sug WHERE user_id=? AND state='open'"); $s->execute([$uid]); if ((int)$s->fetchColumn() >= DOC_SUG_MAX) { if ($file) @unlink($file[0]); fail('Du hast schon ' . DOC_SUG_MAX . ' offene Vorschläge; bitte warten, bis die Redaktion sie bearbeitet hat', 429); }
  $fn = null; if ($file) { [$p, $name, $ext, $sz, $sha] = $file; $d2 = docDir() . '/sug'; if (!is_dir($d2)) @mkdir($d2, 0775, true); $fn = bin2hex(random_bytes(8)) . '.' . $ext; if (!@rename($p, "$d2/$fn")) fail('Datei konnte nicht gespeichert werden', 500); }
  $db->prepare("INSERT INTO doc_sug(folder_id,doc_id,kind,title,note,file,name,ext,mime,bytes,sha256,user_id,at,state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'open')")->execute([$fid, $did, $kind, $title === '' ? null : $title, $note === '' ? null : $note, $fn, $file[1] ?? null, $file[2] ?? null, $file ? docMimeOf($file[2]) : null, $file[3] ?? null, $file[4] ?? null, $uid, $now]);
  $sid = (int)$db->lastInsertId(); $ed = array_values(array_diff(docEditors($fid), [$uid]));
  if ($ed) pushTo($ed, ['t' => '📚 Neuer Vorschlag', 'b' => $me['name'] . ': ' . ($title ?: ($file[1] ?? '') ?: mb_substr($note, 0, 80)), 'tag' => 'g:' . $sid] + pushLvl('normal'));
  return ['ok' => true, 'id' => $sid]; }
// Angaben für die Dialoge der Redaktion
function docMeta() { global $db; $rAll = $db->query("SELECT id,name,perms,sys FROM uroles ORDER BY id")->fetchAll();
  return ['groups' => array_map(fn($g) => ints($g, ['id']), $db->query("SELECT id,name,color FROM inc_groups WHERE active=1 ORDER BY pos,name")->fetchAll()),
    'roles' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'ok' => permsMine(rolePerms($r)) ? 1 : 0], $rAll),
    'skills' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'req' => $k['req']], array_filter(skillRows(true), fn($k) => $k['active']))),
    'kinds' => array_values(array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'color' => $k['color']], kindsActive())),
    'places' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'plan_id' => $p['plan_id'] === null ? null : (int)$p['plan_id'], 'plan' => $p['title']], $db->query("SELECT p.id,p.name,p.plan_id,x.title FROM places p LEFT JOIN ex_plans x ON x.id=p.plan_id WHERE p.active=1 ORDER BY CASE WHEN p.plan_id IS NULL THEN 0 ELSE 1 END, p.name")->fetchAll()),
    'is_admin' => can('docs') ? 1 : 0]; }
