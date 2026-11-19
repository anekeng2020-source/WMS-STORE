<?php
/* =====================================================================
 *  mywms — Supabase backend  (api_supabase.php)
 *  ---------------------------------------------------------------------
 *  Stores data in a Supabase PostgreSQL database (via the PostgREST REST
 *  API) and product images in Supabase Storage.
 *     ?action=health | login | verify | setpin | changepw
 *            | load | save | get | put | savebatch | upload | delimg
 *
 *  SECURITY MODEL
 *   - Every action except health/login needs a session token (header
 *     X-Auth-Token) issued by ?action=login. Tokens are HMAC-signed and
 *     re-checked against the user list on every request, so deleting a
 *     user or changing their password revokes their tokens immediately.
 *   - Passwords and PINs are stored only as password_hash() hashes and are
 *     never sent to clients. Legacy plaintext values are hashed on the
 *     first login after upgrading.
 *   - Company isolation + roles are enforced HERE, not in the browser:
 *     Super Admin -> all companies; everyone else -> own company only.
 *     Only Admin/Super Admin can change users; only Super Admin can
 *     change the company list.
 *   - Login attempts are rate-limited per IP and per IP+username.
 *
 *  DATA MODEL (see supabase_schema.sql — run it once in the SQL editor):
 *     kv(name, data jsonb)                         -- globals: companies / seq
 *     "userMaster"(username PK, company, position, full_name, department,
 *                  pass_hash, pin_hash, data jsonb)  -- one row per user account
 *     settings(company, data jsonb)                -- per-company settings
 *     recs(company, tbl, rk, data jsonb)           -- one row per record
 *
 *  SETUP
 *   1) Create a Supabase project, run supabase_schema.sql.
 *   2) Copy config_supabase.sample.php -> config_supabase.php and fill in
 *      url, service_key, token_secret and allowed_origins.
 *   3) Upload api_supabase.php + config_supabase.php to the server.
 *   4) Verify: open api_supabase.php?action=health  -> "backend":"supabase".
 * ===================================================================== */

/* Copy to config_supabase.php (same folder as api_supabase.php) and fill in.
 * Keep this file server-side only — never commit real keys. */

/* ---------- config ---------- */
$CFG = [
    'url'             => 'https://ljximlcmikpvdtvodwwt.supabase.co',
    'service_key'     => '',                                    // set in config_supabase.php only — never hard-code it here
    'bucket'          => 'mywms',
    'api_key'         => 'smywms',                        // optional legacy app key (public, NOT a security control)
    'token_secret'    => '',                                    // set in config_supabase.php only — never hard-code it here
    'token_ttl'       => 12 * 3600,                 // session lifetime in seconds
    'allowed_origins' => ['https://myib.online'],   // browser origins allowed to call this API (CORS)
    'bootstrap_password' => '',                     // only used when NO users exist yet: lets "superadmin" sign in once
];
if (is_file(__DIR__ . '/config_supabase.php')) { $CFG = array_merge($CFG, (array)(require __DIR__ . '/config_supabase.php')); }
$CFG['url'] = rtrim($CFG['url'], '/');
if ((string)($CFG['service_key'] ?? '') === '') {
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'server not configured: put service_key in config_supabase.php']); exit;
}

/* ---------- headers ---------- */
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = (array)($CFG['allowed_origins'] ?? []);
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Auth-Token, X-ERP-Key');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok($d)         { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail($c, $m)   { http_response_code($c); echo json_encode(['ok'=>false, 'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

/* ---------- optional legacy app key (header only; never in the URL) ---------- */
$need = (string)($CFG['api_key'] ?? 'smywms');
if ($need !== '') {
    $got = $_SERVER['HTTP_X_API_KEY'] ?? 'smywms';
    if (!hash_equals($need, (string)$got)) fail(401, 'invalid api key');
}

/* ---------- body ---------- */
$method = $_SERVER['REQUEST_METHOD'];
$body = null;
if ($method === 'POST' || $method === 'PUT') {
    $raw  = file_get_contents('php://input');
    $clen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($raw === '' && $clen > 0) fail(413, 'request body dropped — exceeds post_max_size ('.ini_get('post_max_size').')');
    $body = ($raw === '') ? null : json_decode($raw, true);
    if ($raw !== '' && $body === null) fail(400, 'invalid JSON body');
}
/* $body is for reading the request (arrays everywhere); $bodyKeep is the same JSON with every empty {} kept as {} —
   the stored data comes from $bodyKeep so an empty object is never turned into an empty list [] */
$bodyKeep = ($body === null) ? null : jdec($raw);
/* an OAuth platform may append "?code=..." to a redirect URL that already has a query
   ("?action=mp_auth_callback?code=..."); treat every later "?" as "&" so it parses */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if (substr_count($qs, '?') > 0) { parse_str(str_replace('?', '&', $qs), $_GET); }
$action = $_GET['action'] ?? '';

/* ---------- constants (match the client) ---------- */
const PARTITION_TABLES = [
    'itemMaster','stock','locationMaster','labelMaster','history','schedule',
    'bookings','logisticMaster','customerMaster','supplierMaster','ngMaster','bomMaster','stocktake','returns',
    'sales','shifts','promotions','giftcards','purchaseOrders','salesOrders','mpOrders'
];
const MERGE_KEYS = [
    'stock'=>'code|loc|lotNo|lotSup|mfd|exp|sn|hu?','history'=>'id','huMaster'=>'code','labelLog'=>'id','schedule'=>'id','bookings'=>'id',
    'sales'=>'id','shifts'=>'id','returns'=>'id','stocktake'=>'id','promotions'=>'id','giftcards'=>'code',
    'itemMaster'=>'code','locationMaster'=>'code','customerMaster'=>'code','supplierMaster'=>'code',
    'ngMaster'=>'code','logisticMaster'=>'name','purchaseOrders'=>'id','salesOrders'=>'id',
    'mpOrders'=>'id','withdrawals'=>'id','rentals'=>'id','addonRecords'=>'id'
];
/* tables added later: used only when they exist in the database (their sql_updates file was run) —
   until then load skips them and doesn't announce them, so clients never send rows the DB can't take */
const OPTIONAL_TABLES = ['withdrawals','huMaster','labelLog','rentals','addonRecords'];
/* numbers that clients send as +/- changes (mode "delta"), added up in the database by wms_apply_delta */
const DELTA_FIELDS = ['stock'=>['qty'], 'giftcards'=>['balance'], 'customerMaster'=>['points','storeCredit','totalSpent']];
const WEAK_PASSWORDS = ['super123','admin123','password','12345678','123456','1234','0000','admin','superadmin','qwerty'];
const SECRET_FIELDS  = ['password','pin','passHash','pinHash'];

/* ---------- packages (settings.pkg — picked by the Super Admin on the web) ----------
 * The web, phone and POS hide every menu outside the company's package; the server backs that up:
 *   - only a Super Admin may change settings.pkg (guards above keep the stored value for everyone else);
 *   - writes to tables that only features outside the package use are skipped — the stored rows stay as they are;
 *   - the ERP API needs the Full System package, the online-store actions need "WMS + Business".
 * Tables every package uses (stock, history, items, locations, schedule, …) are never blocked. The Super Admin is never
 * blocked. No package stored = Full System, so companies set up before packages existed keep working.
 * Keep PKG_TABLE_MIN in step with PKG_DEF in index.html (the lowest package whose menus write that table). */
const PKG_RANK = ['basic'=>1, 'standard'=>2, 'advanced'=>3, 'business'=>4, 'full'=>5];
const PKG_TABLE_MIN = [
    'bookings'=>'standard', 'returns'=>'standard', 'huMaster'=>'standard', 'labelMaster'=>'standard', 'labelLog'=>'standard',
    'ngMaster'=>'standard', 'logisticMaster'=>'standard', 'purchaseOrders'=>'standard', 'salesOrders'=>'standard',
    'bomMaster'=>'advanced',
    'sales'=>'business', 'shifts'=>'business', 'promotions'=>'business', 'giftcards'=>'business', 'mpOrders'=>'business', 'rentals'=>'business',
    'addonRecords'=>'full'
];
function pkg_of($settings){ $p = is_array($settings) ? (string)($settings['pkg'] ?? '') : ''; return isset(PKG_RANK[$p]) ? $p : 'full'; }
function pkg_settings($cid){
    static $cache = [];
    if (!$cid) return [];
    if (!array_key_exists($cid, $cache)) {
        $r = sb_req('GET', '/settings?select=data&company=eq.' . rawurlencode($cid));
        $cache[$cid] = (isset($r[0]['data']) && is_array($r[0]['data'])) ? $r[0]['data'] : [];
    }
    return $cache[$cid];
}
function pkg_has_rank($settings, $min, $me = null){ if ($me && is_super($me)) return true; return PKG_RANK[pkg_of($settings)] >= PKG_RANK[$min]; }
function pkg_table_ok($cid, $table, $me){
    if (is_super($me) || !isset(PKG_TABLE_MIN[$table])) return true;
    return pkg_has_rank(pkg_settings($cid), PKG_TABLE_MIN[$table]);
}


function safe_id($s){ return preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)$s) ? (string)$s : null; }
function rowkey($row, $key){
    if (!is_array($row)) return '';
    if (strpos($key,'|') !== false){                   // a trailing "field?" is part of the key only when the row has it (stock in a pallet / tote: hu)
        $s = ''; foreach (explode('|',$key) as $i => $f){ $opt = substr($f,-1) === '?'; if ($opt) $f = substr($f,0,-1);
            $v = isset($row[$f]) ? (string)$row[$f] : ''; if ($opt && $v === '') continue; $s .= ($i ? '|' : '') . $v; }
        return $s; }
    return isset($row[$key]) ? (string)$row[$key] : '';
}
/* JSON -> PHP like json_decode(..., true), except that an empty object stays an object (stdClass) so it is written
   back as {} and not [] (json_encode cannot tell an empty map from an empty list). An object whose keys are
   exactly "0","1",… also stays an object, otherwise it would come back as a list. */
function jdec($s){ return jfix(json_decode((string)$s, false)); }
function jfix($v){
    if (is_object($v)) {
        $a = get_object_vars($v);
        if (!$a) return new stdClass();
        foreach ($a as $k => $x) $a[$k] = jfix($x);
        if (array_keys($a) === range(0, count($a) - 1)) return (object)$a;
        return $a;
    }
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = jfix($x); return $v; }
    return $v;
}
/* a map read with jdec may be an object: as an array for editing */
function as_map($v){ return ($v instanceof stdClass) ? get_object_vars($v) : (is_array($v) ? $v : []); }
function empty_partition(){ $p=[]; foreach (PARTITION_TABLES as $t) $p[$t]=[]; return $p; }

/* =====================================================================
 *  Data log (table audit_log, filled by database triggers — see supabase_schema.sql)
 *  Every write carries who made it; only this file can reach the database, so a browser can't fake it.
 * ===================================================================== */
function audit_who(){ $me = $GLOBALS['ME'] ?? null; return is_array($me) ? (string)($me['username'] ?? '') : (string)($GLOBALS['AUDIT_USER'] ?? 'system'); }
function audit_req(){ if (!isset($GLOBALS['AUDIT_REQ'])) $GLOBALS['AUDIT_REQ'] = bin2hex(random_bytes(8)); return $GLOBALS['AUDIT_REQ']; }
function audit_src(){
    $page = preg_match('#/([A-Za-z0-9_-]+)\.html#', (string)($_SERVER['HTTP_REFERER'] ?? ''), $m) ? $m[1] . ' · ' : '';
    return substr($page . (string)($GLOBALS['action'] ?? ''), 0, 60);
}
function audit_header(){
    return 'X-WMS-Actor: ' . base64_encode(json_encode(['u'=>audit_who(), 'src'=>audit_src(), 'ip'=>client_ip(), 'req'=>audit_req()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}
/* something that changes no table row (sign-in, manager approval, picture). Never stops the request: a failure only goes to the PHP log. */
function audit_event($op, $company = null, $key = null, $detail = null){
    $row = ['company'=>$company, 'actor'=>audit_who(), 'source'=>audit_src(), 'ip'=>client_ip(), 'req_id'=>audit_req(), 'op'=>$op, 'row_key'=>$key, 'changes'=>$detail];
    if (isset($GLOBALS['__SB_HOOK'])) { call_user_func($GLOBALS['__SB_HOOK'], 'POST', '/audit_log', $row, [], false, null); return; }
    global $CFG;
    $k = $CFG['service_key']; $headers = ['apikey: ' . $k, 'Content-Type: application/json', 'Prefer: return=minimal'];
    if (strncmp($k, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $k;
    $ch = curl_init($CFG['url'] . '/rest/v1/audit_log');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>'POST', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $http < 200 || $http >= 300) error_log('mywms: audit_event ' . $op . ' failed: http ' . $http);
}

/* =====================================================================
 *  Supabase REST (PostgREST) + Storage over cURL
 * ===================================================================== */
function sb_req($method, $path, $body = null, $extraHeaders = [], $isStorage = false, $rawBody = null, $keepEmpty = false){
    if (isset($GLOBALS['__SB_HOOK'])) return call_user_func($GLOBALS['__SB_HOOK'], $method, $path, $body, $extraHeaders, $isStorage, $rawBody);
    global $CFG;
    $url = $CFG['url'] . ($isStorage ? '/storage/v1' : '/rest/v1') . $path;
    $key = $CFG['service_key'];
    $headers = ['apikey: ' . $key, audit_header()];
    // Legacy service_role is a JWT (starts with eyJ) -> also send as Bearer to elevate to service role.
    // New secret keys (sb_secret_...) are NOT JWTs; the gateway grants access via the apikey header alone.
    if (strncmp($key, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $key;
    $payload = null;
    if ($rawBody !== null) { $payload = $rawBody; }
    elseif ($body !== null) { $headers[] = 'Content-Type: application/json'; $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    foreach ($extraHeaders as $h) $headers[] = $h;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $resp = curl_exec($ch);
    if ($resp === false) { error_log('mywms: supabase connection failed: ' . curl_error($ch)); curl_close($ch); fail(502, 'database connection failed'); }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) {
        // log details server-side only; the client gets a generic message
        error_log('mywms: supabase error http ' . $http . ' ' . $method . ' ' . $path . ': ' . substr((string)$resp, 0, 300));
        sb_fail($http);
    }
    if ($resp === '' ) return [];
    $j = $keepEmpty ? jdec($resp) : json_decode($resp, true);
    return ($j === null) ? $resp : $j;
}
/* like sb_req, but returns [http, body] instead of failing — for calls that have a fallback */
function sb_try($method, $path, $body = null){
    if (isset($GLOBALS['__SB_HOOK'])) return [200, call_user_func($GLOBALS['__SB_HOOK'], $method, $path, $body, [], false, null)];
    global $CFG;
    $key = $CFG['service_key']; $headers = ['apikey: ' . $key, 'Content-Type: application/json', audit_header()];
    if (strncmp($key, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($CFG['url'] . '/rest/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $resp = curl_exec($ch);
    if ($resp === false) { error_log('mywms: supabase connection failed: ' . curl_error($ch)); curl_close($ch); fail(502, 'database connection failed'); }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$http, $resp];
}
/* generic client message; 401/403/404 get a setup hint (no internals leaked) */
function sb_fail($http){
    if ($http == 401 || $http == 403) fail(502, 'database error (http ' . $http . '): the server key was rejected — config_supabase.php must use the service_role (or sb_secret_) key, not the anon/publishable key');
    if ($http == 404) fail(502, 'database error (http 404): a table is missing — run supabase_schema.sql (safe to re-run)');
    fail(502, 'database error (http ' . $http . ')');
}
/* fetch many GET paths in PARALLEL (one round-trip instead of N) -> fast load */
function sb_multi_get($paths, $optional = []){
    if (isset($GLOBALS['__SB_HOOK'])) { $o=[]; foreach ($paths as $k=>$p) $o[$k]=call_user_func($GLOBALS['__SB_HOOK'],'GET',$p,null,[],false,null); return $o; }
    global $CFG;
    $key = $CFG['service_key'];
    $headers = ['apikey: ' . $key];
    if (strncmp($key, 'eyJ', 3) === 0) $headers[] = 'Authorization: Bearer ' . $key;
    $mh = curl_multi_init(); $chs = [];
    foreach ($paths as $k => $p) {
        $ch = curl_init($CFG['url'] . '/rest/v1' . $p);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_HTTPHEADER=>$headers]);
        curl_multi_add_handle($mh, $ch); $chs[$k] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running > 0) curl_multi_select($mh, 1.0); } while ($running > 0);
    $out = [];
    foreach ($chs as $k => $ch) {
        $resp = curl_multi_getcontent($ch); $j = jdec($resp);           // rows' data: empty {} stays {}
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http == 404 && in_array($k, $optional, true)) { $out[$k] = null; curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }   // optional table not created yet
        if ($http < 200 || $http >= 300) {   // never treat a failed read as an empty table
            error_log('mywms: supabase error http ' . $http . ' GET ' . $paths[$k] . ': ' . substr((string)$resp, 0, 300));
            sb_fail($http);
        }
        $out[$k] = is_array($j) ? $j : [];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/* upsert an array of rows into a table (on conflict merge), chunked to keep each request small */
function sb_upsert($table, $rows, $onConflict){
    if (!$rows) return;
    $rows = array_values($rows);
    // Postgres rejects an INSERT..ON CONFLICT that touches the same conflict key twice in one
    // statement ("cannot affect row a second time"). De-duplicate by the conflict columns,
    // keeping the LAST occurrence, before sending each batch.
    $cols = array_map('trim', explode(',', $onConflict));
    $seen = [];
    foreach ($rows as $r) {
        $k = ''; foreach ($cols as $c) { $k .= '|' . (isset($r[$c]) ? (string)$r[$c] : ''); }
        $seen[$k] = $r;                 // later rows overwrite earlier -> last wins
    }
    $dedup = array_values($seen);
    $CHUNK = 500;
    for ($i = 0; $i < count($dedup); $i += $CHUNK) {
        $slice = array_slice($dedup, $i, $CHUNK);
        sb_req('POST', "/$table?on_conflict=$onConflict", $slice,
            ['Prefer: resolution=merge-duplicates,return=minimal']);
    }
}
function kv_get($name, $default){
    $r = sb_req('GET', '/kv?select=data&name=eq.' . rawurlencode($name));
    return (isset($r[0]) && array_key_exists('data', $r[0]) && $r[0]['data'] !== null) ? $r[0]['data'] : $default;
}
function kv_put($name, $data){ sb_upsert('kv', [['name'=>$name, 'data'=>$data]], 'name'); }

/* =====================================================================
 *  Users, roles, tokens
 * ===================================================================== */
function user_company($u){ $c = (string)($u['company'] ?? ''); return $c === '' ? 'C1' : $c; }
function is_super($u){ return ($u['position'] ?? '') === 'Super Admin'; }
function is_admin($u){ $p = $u['position'] ?? ''; return $p === 'Admin' || $p === 'Super Admin'; }
function is_mgr($u){ $p = strtolower((string)($u['position'] ?? '')); return strpos($p,'admin') !== false || strpos($p,'manager') !== false || strpos($p,'super') !== false; }
function can_company($u, $cid){ return is_super($u) || user_company($u) === $cid; }
function need_company($u, $cid){ if (!$cid) fail(400, 'bad company'); if (!can_company($u, $cid)) fail(403, 'no access to this company'); }

function find_user($users, $username){
    $n = strtolower(trim((string)$username));
    if ($n === '') return null;
    foreach ((array)$users as $i => $u) if (is_array($u) && strtolower((string)($u['username'] ?? '')) === $n) return $i;
    return null;
}
function public_user($u){
    $o = $u; foreach (SECRET_FIELDS as $f) unset($o[$f]);
    $o['hasPin'] = !empty($u['pinHash']);
    return $o;
}
/* hash any legacy plaintext password/pin in place; returns true when something changed */
function migrate_users(&$users){
    $changed = false;
    if (!is_array($users)) { $users = []; return false; }
    foreach ($users as &$u) {
        if (!is_array($u)) continue;
        if (isset($u['password'])) { if ((string)$u['password'] !== '' && empty($u['passHash'])) $u['passHash'] = password_hash((string)$u['password'], PASSWORD_DEFAULT); unset($u['password']); $changed = true; }
        if (isset($u['pin']))      { if ((string)$u['pin'] !== '' && empty($u['pinHash']))      $u['pinHash']  = password_hash((string)$u['pin'], PASSWORD_DEFAULT);      unset($u['pin']);      $changed = true; }
        unset($u['hasPin']);
    }
    unset($u);
    return $changed;
}
/* ---------- user storage: table public."userMaster" (one row per user) ----------
 *  username (PK) | company | position | full_name | department | pass_hash | pin_hash | data jsonb | updated_at
 *  "data" holds every other user field (menus, etc.); secrets live ONLY in pass_hash / pin_hash. */
const USER_CORE = ['username','company','position','fullName','department','passHash','pinHash','password','pin','hasPin'];
function row_to_user($r){
    $u = is_array($r['data'] ?? null) ? $r['data'] : [];
    foreach (USER_CORE as $f) unset($u[$f]);
    $u['username']   = (string)($r['username'] ?? '');
    $u['company']    = (string)($r['company'] ?? '');
    $u['position']   = (string)($r['position'] ?? '');
    $u['fullName']   = (string)($r['full_name'] ?? '');
    $u['department'] = (string)($r['department'] ?? '');
    if (!empty($r['pass_hash'])) $u['passHash'] = $r['pass_hash'];
    if (!empty($r['pin_hash']))  $u['pinHash']  = $r['pin_hash'];
    return $u;
}
function user_to_row($u){
    $data = $u; foreach (USER_CORE as $f) unset($data[$f]);
    return [
        'username'   => (string)$u['username'],
        'company'    => (string)($u['company'] ?? ''),
        'position'   => (string)($u['position'] ?? ''),
        'full_name'  => (string)($u['fullName'] ?? ''),
        'department' => (string)($u['department'] ?? ''),
        'pass_hash'  => !empty($u['passHash']) ? (string)$u['passHash'] : null,
        'pin_hash'   => !empty($u['pinHash'])  ? (string)$u['pinHash']  : null,
        'data'       => (object)$data,
        'updated_at' => gmdate('c'),
    ];
}
/* write one user (insert or update) */
function user_put_one($u){ sb_upsert('userMaster', [user_to_row($u)], 'username'); }
/* write the full list: upsert every user, delete the ones that were removed */
function users_save_all($after, $before){
    $keep = [];
    foreach ((array)$after as $u) if (is_array($u) && trim((string)($u['username'] ?? '')) !== '') $keep[strtolower($u['username'])] = true;
    foreach ((array)$before as $u) {
        $n = (string)($u['username'] ?? '');
        if ($n !== '' && !isset($keep[strtolower($n)])) sb_req('DELETE', '/userMaster?username=eq.' . rawurlencode($n));
    }
    $rows = []; foreach ((array)$after as $u) if (is_array($u) && trim((string)($u['username'] ?? '')) !== '') $rows[] = user_to_row($u);
    if ($rows) sb_upsert('userMaster', $rows, 'username');
}
/* read all users; on the first run, import the legacy kv 'users' JSON (hashing any plaintext) and delete it */
function load_users(){
    $rows = sb_req('GET', '/userMaster?select=*&order=username');
    $users = []; foreach ((array)$rows as $r) if (is_array($r)) $users[] = row_to_user($r);
    if (!$users) {
        $legacy = kv_get('users', []);
        if (is_array($legacy) && $legacy) {
            migrate_users($legacy);
            $legacy = array_values(array_filter($legacy, function($u){ return is_array($u) && trim((string)($u['username'] ?? '')) !== ''; }));
            users_save_all($legacy, []);
            sb_req('DELETE', '/kv?name=eq.users');      // plaintext copy gone for good
            $users = $legacy;
        }
    }
    return $users;
}

function b64u($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s){ return base64_decode(strtr($s, '-_', '+/')); }
function token_secret(){ global $CFG; $s = (string)($CFG['token_secret'] ?? ''); return $s !== '' ? $s : hash('sha256', 'mywms-token|' . $CFG['service_key'], true); }
function token_ver($u){ return substr(hash('sha256', (string)($u['passHash'] ?? '') . '|' . user_company($u) . '|' . ($u['position'] ?? '')), 0, 16); }
function make_token($u){
    global $CFG;
    $p = ['u'=>(string)$u['username'], 'v'=>token_ver($u), 'exp'=>time() + (int)$CFG['token_ttl']];
    $b = b64u(json_encode($p));
    return $b . '.' . b64u(hash_hmac('sha256', $b, token_secret(), true));
}
/* returns [users, index, user] for the caller, or fails with 401 */
function require_auth(){
    $t = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    $parts = explode('.', $t);
    if (count($parts) !== 2) fail(401, 'not signed in');
    if (!hash_equals(b64u(hash_hmac('sha256', $parts[0], token_secret(), true)), $parts[1])) fail(401, 'invalid session');
    $p = json_decode((string)b64u_dec($parts[0]), true);
    if (!is_array($p) || ($p['exp'] ?? 0) < time()) fail(401, 'session expired');
    $users = load_users();
    $i = find_user($users, $p['u'] ?? '');
    if ($i === null || !hash_equals(token_ver($users[$i]), (string)($p['v'] ?? ''))) fail(401, 'session revoked');
    return [$users, $i, $users[$i]];
}

/* merge a users list sent by a client into the stored list, honouring the caller's role */
function merge_users($incoming, $existing, $me){
    if (!is_array($incoming)) return $existing;
    $existing = array_values((array)$existing);
    $inScope = function($u) use ($me){ return is_super($me) || (is_admin($me) && !is_super($u) && user_company($u) === user_company($me)); };   // an Admin never edits a Super Admin

    if (!is_admin($me)) return $existing;        // non-admins can't edit users (use ?action=setpin / changepw)

    $out = []; $handled = [];
    foreach ($existing as $u) if (is_array($u) && !$inScope($u)) $out[] = $u;   // untouched: outside caller's scope
    foreach ($incoming as $nu) {
        if (!is_array($nu)) continue;
        $name = trim((string)($nu['username'] ?? ''));
        if ($name === '' || isset($handled[strtolower($name)])) continue;
        $ei  = find_user($existing, $name);
        $old = $ei !== null ? $existing[$ei] : null;
        if ($old !== null && !$inScope($old)) continue;   // can't touch users outside scope
        $rec = $nu; foreach (array_merge(SECRET_FIELDS, ['hasPin']) as $f) unset($rec[$f]);
        $rec['username'] = $old !== null ? (string)$old['username'] : $name;   // keep stored spelling (PK)
        if (!is_super($me)) {                            // Admin: own company only, can't mint Super Admins
            $rec['company'] = user_company($me);
            if (($rec['position'] ?? '') === 'Super Admin') $rec['position'] = ($old['position'] ?? 'Admin') === 'Super Admin' ? 'Super Admin' : 'Admin';
        }
        $pw  = (string)($nu['password'] ?? '');
        $pin = (string)($nu['pin'] ?? '');
        if ($pw !== '')  $rec['passHash'] = password_hash($pw, PASSWORD_DEFAULT);
        elseif (!empty($old['passHash'])) $rec['passHash'] = $old['passHash'];
        if ($pin !== '' && preg_match('/^\d{4,6}$/', $pin)) $rec['pinHash'] = password_hash($pin, PASSWORD_DEFAULT);
        elseif (!empty($old['pinHash'])) $rec['pinHash'] = $old['pinHash'];
        $out[] = $rec; $handled[strtolower($name)] = true;
    }
    // never let the system end up without a Super Admin
    $hasSuper = false; foreach ($out as $u) if (is_super($u)) { $hasSuper = true; break; }
    if (!$hasSuper) foreach ($existing as $u) if (is_super($u)) { $out[] = $u; }
    return array_values($out);
}
/* merge a companies list honouring the caller's role (only Super Admin can add/remove) */
function merge_companies($incoming, $existing, $me){
    if (!is_array($incoming)) return $existing;
    if (is_super($me)) {
        $out = []; foreach ($incoming as $c) if (is_array($c) && safe_id($c['id'] ?? '')) $out[] = $c;
        return $out;
    }
    if (!is_admin($me)) return $existing;
    $mine = user_company($me);
    foreach ($incoming as $c) {
        if (!is_array($c) || ($c['id'] ?? '') !== $mine) continue;
        foreach ($existing as &$e) if (is_array($e) && ($e['id'] ?? '') === $mine && isset($c['name'])) $e['name'] = (string)$c['name'];
        unset($e);
    }
    return $existing;
}
function scoped_users($users, $me){
    $o = [];
    foreach ((array)$users as $u) if (is_array($u) && (is_super($me) || user_company($u) === user_company($me))) $o[] = public_user($u);
    return $o;
}
function scoped_companies($companies, $me){
    if (is_super($me)) return array_values((array)$companies);
    $o = []; foreach ((array)$companies as $c) if (is_array($c) && ($c['id'] ?? '') === user_company($me)) $o[] = $c;
    return $o;
}

/* ---------- login rate limiting (file based, per IP and per IP+username) ---------- */
function rl_file($key){ $d = sys_get_temp_dir() . '/mywms_rl'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d . '/' . hash('sha256', $key) . '.json'; }
function rl_read($key, $window){ $j = @json_decode((string)@file_get_contents(rl_file($key)), true); if (!is_array($j) || ($j['t'] ?? 0) < time() - $window) return ['n'=>0, 't'=>time()]; return $j; }
function rl_blocked($key, $max, $window){ return rl_read($key, $window)['n'] >= $max; }
function rl_hit($key, $window){ $j = rl_read($key, $window); $j['n']++; @file_put_contents(rl_file($key), json_encode($j), LOCK_EX); }
function rl_clear($key){ @unlink(rl_file($key)); }
function client_ip(){ return (string)($_SERVER['REMOTE_ADDR'] ?? '0'); }
const RL_WINDOW = 900;     // 15 minutes
function login_guard($uname, $isPin = false){
    $ip = client_ip();
    if ($isPin && (rl_blocked('pin:' . $ip, 10, RL_WINDOW) || ($uname !== '' && rl_blocked('pinu:' . $uname, 10, 3600))))
        fail(429, 'too many attempts — try again later');
    if (rl_blocked('ip:' . $ip, 30, RL_WINDOW) || ($uname !== '' && rl_blocked('iu:' . $ip . '|' . $uname, 6, RL_WINDOW)) || ($uname !== '' && rl_blocked('u:' . $uname, 20, RL_WINDOW)))
        fail(429, 'too many attempts — try again in 15 minutes');
}
function login_failed($uname){
    $ip = client_ip();
    rl_hit('ip:' . $ip, RL_WINDOW);
    if ($uname !== '') { rl_hit('iu:' . $ip . '|' . $uname, RL_WINDOW); rl_hit('u:' . $uname, RL_WINDOW); }
    usleep(random_int(250000, 500000));
}
function is_weak_password($pw){ return strlen($pw) < 8 || in_array(strtolower($pw), WEAK_PASSWORDS, true); }

/* =====================================================================
 *  ACTIONS
 * ===================================================================== */

if ($action === 'health') {
    ok(['ok'=>true, 'app'=>'wms-supabase-api', 'version'=>3, 'backend'=>'supabase', 'auth'=>'token',
        'post_max_size'=>ini_get('post_max_size'), 'upload_max_filesize'=>ini_get('upload_max_filesize')]);
}

/* ---------- version: fingerprint of the deployed files (no session) ----------
 * The pages poll this; when it changes they show a "software update" card and hard-reload on confirm.
 * Only file names, sizes and times go into the hash — nothing secret is returned. */
if ($action === 'version') {
    clearstatcache();
    $parts = [];
    foreach (['index.html', 'mobile.html', 'pos.html', 'api_supabase.php', 'marketplace.php', 'sw.js', 'install.js'] as $f) {
        $p = __DIR__ . '/' . $f;
        if (is_file($p)) $parts[] = $f . ':' . filemtime($p) . ':' . filesize($p);
    }
    ok(['ok' => true, 'version' => substr(sha1(implode('|', $parts)), 0, 16)]);
}

/* ---------- ERP API (add-on "ERP Integration") ----------
 * For an ERP / middleware, not for people: no session, a per-company key instead.
 *   Header  X-ERP-Key: erp.<company>.<secret>   (made on the web: Add-ons → ERP → API; only its SHA-256 is stored, in settings.erp.keyHash)
 *   GET  ?action=erp&op=export&dataset=items|stock|movements|po|so|customers|suppliers|sales[&from=YYYY-MM-DD&to=YYYY-MM-DD][&after=<movement id>]
 *   POST ?action=erp&op=import&dataset=items|customers|suppliers|po|so   body {"rows":[ {...}, ... ]}  (max 2000 rows, upsert by code / id)
 * Works only while the add-on is switched on for that company (settings.addons.erp === true). */
if ($action === 'erp') {
    $key = (string)($_SERVER['HTTP_X_ERP_KEY'] ?? '');
    if (!preg_match('/^erp\.([A-Za-z0-9_-]{1,40})\.([A-Za-z0-9]{32,128})$/', $key, $km)) fail(401, 'missing or malformed X-ERP-Key');
    $cid = $km[1];
    $ipk = 'erp:' . client_ip();
    if (rl_blocked($ipk, 30, 600)) fail(429, 'too many failed ERP key attempts — wait 10 minutes');
    $sr = sb_req('GET', '/settings?select=data&company=eq.' . rawurlencode($cid));
    $st = (isset($sr[0]['data']) && is_array($sr[0]['data'])) ? $sr[0]['data'] : [];
    $hash = (string)($st['erp']['keyHash'] ?? '');
    if ($hash === '' || !hash_equals($hash, hash('sha256', $key))) { rl_hit($ipk, 600); fail(401, 'invalid ERP key'); }
    if (empty($st['addons']['erp'])) fail(403, 'the ERP add-on is switched off for this company');
    if (!pkg_has_rank($st, 'full')) fail(403, 'the ERP add-on is not in this company\'s package (Full System)');
    $GLOBALS['AUDIT_USER'] = 'erp-api';
    $DS = ['items'=>['itemMaster','code'], 'stock'=>['stock',null], 'movements'=>['history',null], 'po'=>['purchaseOrders','id'],
           'so'=>['salesOrders','id'], 'customers'=>['customerMaster','code'], 'suppliers'=>['supplierMaster','code'], 'sales'=>['sales',null]];
    $ds = (string)($_GET['dataset'] ?? ''); if (!isset($DS[$ds])) fail(400, 'unknown dataset — use ' . implode(', ', array_keys($DS)));
    $table = $DS[$ds][0]; $op = (string)($_GET['op'] ?? 'export');
    if ($op === 'export') {
        if ($method !== 'GET') fail(405, 'use GET for export');
        $rows = sb_req('GET', '/' . rawurlencode($table) . '?select=data&company=eq.' . rawurlencode($cid));
        $out = []; foreach ((array)$rows as $r) if (isset($r['data']) && is_array($r['data'])) $out[] = $r['data'];
        $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); $after = (string)($_GET['after'] ?? '');
        if ($from !== '' || $to !== '') $out = array_values(array_filter($out, function($d) use ($from, $to){ $x = (string)($d['date'] ?? ''); return ($from === '' || $x >= $from) && ($to === '' || $x <= $to); }));
        if ($after !== '' && is_numeric($after)) $out = array_values(array_filter($out, function($d) use ($after){ $n = preg_replace('/\D/', '', (string)($d['id'] ?? '')); return $n !== '' && (float)$n > (float)$after; }));
        if ($ds === 'items' || $ds === 'customers' || $ds === 'suppliers') foreach ($out as &$o) { unset($o['img']); } unset($o);
        ok(['ok'=>true, 'company'=>$cid, 'dataset'=>$ds, 'count'=>count($out), 'rows'=>$out]);
    }
    if ($op === 'import') {
        if ($method !== 'POST') fail(405, 'use POST for import');
        if (in_array($ds, ['stock','movements','sales'], true)) fail(400, 'stock, movements and POS sales are read-only — they change only through warehouse work');
        $in = (is_array($body) && isset($body['rows']) && is_array($body['rows'])) ? $body['rows'] : null;
        if ($in === null) fail(400, 'body must be {"rows":[...]}');
        if (count($in) > 2000) fail(413, 'max 2000 rows per call');
        $kf = $DS[$ds][1]; $up = []; $bad = 0; $locked = 0;
        /* what is stored now: imports merge into it (fields not sent are kept) and orders are matched by their number */
        $stored = []; foreach ((array)sb_req('GET', '/' . rawurlencode($table) . '?select=rk,data&company=eq.' . rawurlencode($cid)) as $r)
            if (isset($r['data']) && is_array($r['data'])) $stored[] = $r;
        $byKey = []; foreach ($stored as $r) { $k = $kf === 'id' ? trim((string)($r['data']['no'] ?? '')) : (string)$r['rk']; if ($k !== '') $byKey[$k] = $r; }
        $moved = [];                                             // orders whose receiving / picking has started: not overwritten
        if ($kf === 'id') foreach ((array)sb_req('GET', '/schedule?select=data&company=eq.' . rawurlencode($cid)) as $r) {
            $d = $r['data'] ?? null; if (!is_array($d)) continue;
            foreach ((array)($d['lines'] ?? []) as $l) if (is_array($l) && (float)($l['done'] ?? 0) > 0) { $moved[(string)($d['id'] ?? '')] = true; break; } }
        $seenK = [];
        foreach ($in as $row) {
            if (!is_array($row)) { $bad++; continue; }
            unset($row['passHash'], $row['pinHash'], $row['scheduleId']);
            foreach (['code','no','name'] as $tf) if (isset($row[$tf]) && is_string($row[$tf])) $row[$tf] = trim($row[$tf]);
            if ($kf === 'id') {
                $no = (string)($row['no'] ?? ''); if ($no === '') { $bad++; continue; }
                if (isset($row['lines']) && !is_array($row['lines'])) { $bad++; continue; }
                $old = $byKey[$no] ?? null;
                if ($old) {
                    $od = $old['data'];
                    if (isset($od['scheduleId']) && isset($moved[(string)$od['scheduleId']])) { $locked++; continue; }
                    unset($row['id'], $row['status']);              // keep the stored id, status and schedule link
                    $data = array_merge($od, $row); $rk = (string)$old['rk'];
                } else {
                    $data = $row; $data['id'] = 'ERP-' . preg_replace('/[^A-Za-z0-9_-]/', '', $no);
                    if (!isset($data['lines'])) $data['lines'] = [];
                    $data['status'] = (string)($row['status'] ?? 'Ordered'); $rk = $data['id'];
                }
                $data['src'] = 'erp-api'; $data['erpDirty'] = true;   // the web app refreshes the linked schedule on its next load
                if (isset($seenK[$no])) { $bad++; continue; } $seenK[$no] = true;
            } else {
                $rk = (string)($row[$kf] ?? ''); if ($rk === '') { $bad++; continue; }
                $data = isset($byKey[$rk]) ? array_merge($byKey[$rk]['data'], $row) : $row;
                $data[$kf] = $rk;
            }
            $up[] = ['company'=>$cid, 'rk'=>$rk, 'data'=>$data];
        }
        if ($up) sb_upsert($table, $up, 'company,rk');
        audit_event('ERP_IMPORT', $cid, $ds, ['rows'=>count($up), 'skipped'=>$bad, 'locked'=>$locked]);
        ok(['ok'=>true, 'dataset'=>$ds, 'upserted'=>count($up), 'skipped'=>$bad, 'locked_in_progress'=>$locked]);
    }
    fail(400, 'op must be export or import');
}

/* ---------- login: {username,password} | {username,pin} | {pin, users:[known usernames]} ---------- */
if ($action === 'login') {
    $b = is_array($body) ? $body : [];
    $uname = strtolower(trim((string)($b['username'] ?? '')));
    $pw  = (string)($b['password'] ?? '');
    $pin = preg_replace('/\D/', '', (string)($b['pin'] ?? ''));
    if ($pw === '' && $pin === '') fail(400, 'missing credentials');
    login_guard($uname, $pw === '');
    $GLOBALS['AUDIT_USER'] = substr($uname, 0, 60);

    $users = load_users();

    // first-run bootstrap: no users at all -> allow "superadmin" with the configured bootstrap password
    $bp = (string)($CFG['bootstrap_password'] ?? '');
    if (!$users && $bp !== '' && $uname === 'superadmin' && hash_equals($bp, $pw)) {
        $users = [['username'=>'superadmin', 'fullName'=>'Super Administrator', 'department'=>'HQ', 'position'=>'Super Admin', 'company'=>'*',
                   'passHash'=>password_hash($pw, PASSWORD_DEFAULT)]];
        user_put_one($users[0]);
    }

    $found = null;
    if ($pw !== '') {
        $i = find_user($users, $uname);
        if ($i !== null && !empty($users[$i]['passHash']) && password_verify($pw, $users[$i]['passHash'])) $found = $i;
    } elseif (preg_match('/^\d{4,6}$/', $pin)) {
        $names = $uname !== '' ? [$uname] : array_slice(array_map('strval', (array)($b['users'] ?? [])), 0, 20);
        foreach ($names as $n) {
            $i = find_user($users, $n);
            if ($i !== null && !empty($users[$i]['pinHash']) && password_verify($pin, $users[$i]['pinHash'])) { $found = $i; break; }
        }
        if ($found === null) { rl_hit('pin:' . client_ip(), RL_WINDOW); if ($uname !== '') rl_hit('pinu:' . $uname, 3600); }
    }
    if ($found === null) {
        $fi = find_user($users, $uname);
        audit_event('LOGIN_FAIL', $fi !== null ? user_company($users[$fi]) : null, substr($uname, 0, 60), ['method'=>$pw !== '' ? 'password' : 'pin']);
        login_failed($uname); fail(401, 'invalid credentials');
    }

    $u = $users[$found];
    $GLOBALS['AUDIT_USER'] = (string)$u['username'];
    audit_event('LOGIN', user_company($u), (string)$u['username'], ['method'=>$pw !== '' ? 'password' : 'pin']);
    rl_clear('iu:' . client_ip() . '|' . strtolower((string)$u['username']));
    if (!empty($u['passHash']) && password_needs_rehash($u['passHash'], PASSWORD_DEFAULT) && $pw !== '') {
        $users[$found]['passHash'] = password_hash($pw, PASSWORD_DEFAULT); user_put_one($users[$found]); $u = $users[$found];
    }
    ok(['ok'=>true, 'token'=>make_token($u), 'user'=>public_user($u), 'weak'=>($pw !== '' && is_weak_password($pw))]);
}

/* everything below requires a valid session */
/* ---- Marketplace (Shopee / Lazada): the OAuth callback has no session ---- */
if (is_file(__DIR__ . '/marketplace.php')) require_once __DIR__ . '/marketplace.php';
if ($action === 'mp_auth_callback' && function_exists('mp_handle')) mp_handle($action, null, $body);

list($USERS, $ME_I, $ME) = require_auth();

/* ---------- verify: manager override (checks another user's password without switching session) ---------- */
if ($action === 'verify') {
    $b = is_array($body) ? $body : [];
    $uname = strtolower(trim((string)($b['username'] ?? '')));
    login_guard($uname);
    $i = find_user($USERS, $uname);
    $okPw = $i !== null && !empty($USERS[$i]['passHash']) && password_verify((string)($b['password'] ?? ''), $USERS[$i]['passHash']);
    if (!$okPw) { audit_event('MANAGER_APPROVE_FAIL', user_company($ME), substr($uname, 0, 60)); login_failed($uname); fail(401, 'invalid credentials'); }
    $u = $USERS[$i];
    if (!is_mgr($u) || !(is_super($u) || user_company($u) === user_company($ME))) { audit_event('MANAGER_APPROVE_FAIL', user_company($ME), substr($uname, 0, 60)); fail(403, 'not a manager of this company'); }
    audit_event('MANAGER_APPROVE', user_company($ME), (string)$u['username']);
    ok(['ok'=>true, 'username'=>$u['username'], 'position'=>$u['position'] ?? '']);
}

/* ---------- setpin: {pin} for the signed-in user ---------- */
if ($action === 'setpin') {
    $pin = (string)(is_array($body) ? ($body['pin'] ?? '') : '');
    if (!preg_match('/^\d{4,6}$/', $pin)) fail(400, 'PIN must be 4–6 digits');
    $USERS[$ME_I]['pinHash'] = password_hash($pin, PASSWORD_DEFAULT);
    user_put_one($USERS[$ME_I]);
    ok(['ok'=>true]);
}

/* ---------- changepw: {old,new} for the signed-in user -> returns a fresh token ---------- */
if ($action === 'changepw') {
    $b = is_array($body) ? $body : [];
    $old = (string)($b['old'] ?? ''); $new = (string)($b['new'] ?? '');
    login_guard(strtolower((string)$ME['username']));
    if (empty($ME['passHash']) || !password_verify($old, $ME['passHash'])) { login_failed(strtolower((string)$ME['username'])); fail(401, 'current password is wrong'); }
    if (is_weak_password($new)) fail(400, 'new password must be at least 8 characters and not a common password');
    $USERS[$ME_I]['passHash'] = password_hash($new, PASSWORD_DEFAULT);
    user_put_one($USERS[$ME_I]);
    ok(['ok'=>true, 'token'=>make_token($USERS[$ME_I])]);
}

if ($action === 'load') {
    $super = is_super($ME); $mine = user_company($ME);
    $flt = $super ? '' : '&company=eq.' . rawurlencode($mine);
    $paths = ['kv' => '/kv?select=name,data&name=in.(companies,seq,billing)', 'settings' => '/settings?select=company,data' . $flt];
    foreach (array_merge(PARTITION_TABLES, OPTIONAL_TABLES) as $t) $paths[$t] = '/' . rawurlencode($t) . '?select=company,data' . $flt;
    $res = sb_multi_get($paths, OPTIONAL_TABLES);
    $haveOpt = []; foreach (OPTIONAL_TABLES as $t) if (array_key_exists($t, $res) && $res[$t] !== null) $haveOpt[] = $t;

    $kv = []; foreach ((array)($res['kv'] ?? []) as $r) $kv[$r['name']] = $r['data'];
    $companies = is_array($kv['companies'] ?? null) ? $kv['companies'] : [];
    $seq       = as_map($kv['seq'] ?? []);

    $settings = new stdClass();
    foreach ((array)($res['settings'] ?? []) as $r) if ($super || $r['company'] === $mine) $settings->{$r['company']} = $r['data'] ?? new stdClass();

    $partitions = [];
    foreach ((array)$companies as $co) { $cid = is_array($co) ? ($co['id'] ?? null) : null; if ($cid && ($super || $cid === $mine)) $partitions[$cid] = empty_partition(); }
    foreach (array_merge(PARTITION_TABLES, $haveOpt) as $t) {
        foreach ((array)($res[$t] ?? []) as $r) {
            $c = $r['company'];
            if (!$super && $c !== $mine) continue;
            if (!isset($partitions[$c])) $partitions[$c] = empty_partition();
            $partitions[$c][$t][] = $r['data'];
        }
    }

    // if companies were never persisted but data exists, reconstruct them from the data
    if (empty($companies)) {
        $ids = array_keys($partitions);
        foreach (array_keys((array)$settings) as $sid) if (!in_array($sid, $ids, true)) $ids[] = $sid;
        $companies = array_map(function($id){ return ['id'=>$id, 'name'=>$id]; }, $ids);
    }

    ok([
        'companies'=>scoped_companies($companies, $ME), 'users'=>scoped_users($USERS, $ME), 'me'=>public_user($ME),
        'partitions'=>(object)$partitions, 'settings'=>$settings,
        'txnSeq'  => $seq['txnSeq']  ?? 100, 'schedSeq'=> $seq['schedSeq'] ?? 100,
        'bookSeq' => $seq['bookSeq'] ?? 0,   'bomSeq'  => $seq['bomSeq']  ?? 0,
        'caps'    => array_merge(['settingsPatch','delta'], array_map(function($t){ return 'table:' . $t; }, $haveOpt)),   // delta: quantities may be sent as +/- changes       // clients may send settings as {mode:"patch", set:[[path,value]], unset:[path]}
        'billing' => billing_for($kv['billing'] ?? [], $ME),   // service-fee bills: Super Admin sees all, others only their own company
    ]);
}

/* write helper for globals (companies / users / seq) with role checks */
/* service-fee billing (kv 'billing' = {cfg, co:{cid:{start,off,billTo,paid}}}): only a Super Admin writes it */
function billing_for($b, $me){
    $b = is_array($b) ? $b : [];
    if (is_super($me)) return $b ?: new stdClass();
    $mine = user_company($me); $co = (isset($b['co']) && is_array($b['co'])) ? $b['co'] : [];
    return ['cfg' => $b['cfg'] ?? new stdClass(), 'co' => isset($co[$mine]) ? [$mine => $co[$mine]] : new stdClass()];
}
function write_global($name, $data){
    global $USERS, $ME;
    if ($name === 'billing')   { if (!is_super($ME) || !is_array($data)) return; kv_put('billing', $data); return; }
    if ($name === 'users')     { if (!is_admin($ME)) return; users_save_all(merge_users($data, $USERS, $ME), $USERS); return; }
    if ($name === 'companies') { if (!is_admin($ME)) return; kv_put('companies', merge_companies($data, kv_get('companies', []), $ME)); return; }
    if ($name === 'seq')       { if (!is_array($data)) return; $old = kv_get('seq', []); $old = is_array($old) ? $old : [];
        foreach ($data as $k => $v) if (is_numeric($v) && (!isset($old[$k]) || !is_numeric($old[$k]) || $v > $old[$k])) $old[$k] = 0 + $v;   // keep the larger value per counter
        kv_put('seq', $old); return; }
}
function replace_table($cid, $tb, $arr){
    sb_req('DELETE', '/' . rawurlencode($tb) . '?company=eq.' . rawurlencode($cid));
    $arr = is_array($arr) ? $arr : [];
    $keyDef = MERGE_KEYS[$tb] ?? null; $i = 0; $rows = [];
    foreach ($arr as $row) {
        $rk = $keyDef ? rowkey($row, $keyDef) : (string)$i; if ($rk === '') $rk = (string)$i;
        $rows[] = ['company'=>$cid, 'rk'=>$rk, 'data'=>$row]; $i++;
    }
    if ($rows) sb_upsert($tb, $rows, 'company,rk');
}

/* ---------- add-on switches and the ERP key are Super Admin only ----------
 * settings.addons (which add-ons are on) and settings.erp.keyHash/keyHint/keyAt/keyBy (the ERP API key) decide what the
 * ERP API may read and write, so only a Super Admin may change them — whatever the client sends. Everyone else's writes
 * keep the stored values. */
const ERP_KEY_FIELDS = ['keyHash','keyHint','keyAt','keyBy'];
function settings_guard_patch($op, $me){
    if (is_super($me) || !is_array($op)) return $op;
    $locked = function($p){ $p = array_values((array)$p); if (!$p) return false; if ((string)$p[0] === 'addons' || (string)$p[0] === 'pkg') return true;
        return (string)$p[0] === 'erp' && (count($p) === 1 || in_array((string)$p[1], ERP_KEY_FIELDS, true)); };
    $op['set']   = array_values(array_filter((array)($op['set'] ?? []), function($pv) use ($locked){ return !(is_array($pv) && count($pv) === 2 && $locked($pv[0])); }));
    $op['unset'] = array_values(array_filter((array)($op['unset'] ?? []), function($p) use ($locked){ return !$locked($p); }));
    return $op;
}
function settings_guard_full($cid, $data, $me){
    if (is_super($me)) return $data;
    $r = sb_req('GET', '/settings?select=data&company=eq.' . rawurlencode($cid));
    $old = (isset($r[0]['data']) && is_array($r[0]['data'])) ? $r[0]['data'] : [];
    $new = as_map($data);
    if (array_key_exists('addons', $old)) $new['addons'] = $old['addons']; else unset($new['addons']);
    if (array_key_exists('pkg', $old)) $new['pkg'] = $old['pkg']; else unset($new['pkg']);      // the package is Super Admin only too
    $oe = (isset($old['erp']) && is_array($old['erp'])) ? $old['erp'] : [];
    $ne = isset($new['erp']) ? as_map($new['erp']) : null;
    if ($ne !== null || $oe) { $ne = $ne ?? [];
        foreach (ERP_KEY_FIELDS as $f) { if (array_key_exists($f, $oe)) $ne[$f] = $oe[$f]; else unset($ne[$f]); }
        $new['erp'] = $ne ?: new stdClass(); }
    return $new ?: new stdClass();
}

if ($action === 'save') {
    if (!is_array($body)) fail(400, 'save body must be the full state');
    if (array_key_exists('companies', $body)) write_global('companies', $body['companies']);
    if (array_key_exists('users', $body))     write_global('users', $body['users']);
    write_global('seq', ['txnSeq'=>$body['txnSeq']??100,'schedSeq'=>$body['schedSeq']??100,'bookSeq'=>$body['bookSeq']??0,'bomSeq'=>$body['bomSeq']??0]);
    $parts = as_map($bodyKeep['partitions'] ?? []); $sets = as_map($bodyKeep['settings'] ?? []);
    $settingRows = [];
    foreach ($parts as $cid => $tables) {
        $cid = safe_id($cid); if (!$cid || !can_company($ME, $cid)) continue;   // silently skip companies the caller can't write
        $settingRows[] = ['company'=>$cid, 'data'=>settings_guard_full($cid, isset($sets[$cid]) ? $sets[$cid] : new stdClass(), $ME)];
        $tables = as_map($tables);
        foreach (PARTITION_TABLES as $tb) { if (!pkg_table_ok($cid, $tb, $ME)) continue;   // outside the package: leave the stored rows alone
            replace_table($cid, $tb, isset($tables[$tb]) ? $tables[$tb] : []); }
    }
    if ($settingRows) sb_upsert('settings', $settingRows, 'company');
    ok(['ok'=>true]);
}

/* settings patch: set / unset single fields (path = list of keys) on the stored settings, leaving the rest alone */
function settings_walk(&$data, $path, $val, $del){
    if (!is_array($path) || !$path || count($path) > 8) return;
    $path = array_map('strval', array_values($path));
    $last = array_pop($path);
    if ($data instanceof stdClass) $data = get_object_vars($data);
    $ref =& $data;
    foreach ($path as $k) {
        if (isset($ref[$k]) && $ref[$k] instanceof stdClass) $ref[$k] = get_object_vars($ref[$k]);
        if (!isset($ref[$k]) || !is_array($ref[$k])) { if ($del) return; $ref[$k] = []; }
        $ref =& $ref[$k];
    }
    if ($del) { unset($ref[$last]); if (!$ref && $ref !== $data) $ref = new stdClass(); }   // a map emptied by the patch stays a map
    else $ref[$last] = $val;
}
function settings_patch(&$data, $op){
    $data = as_map($data);
    foreach ((array)($op['unset'] ?? []) as $p) settings_walk($data, $p, null, true);
    foreach ((array)($op['set'] ?? []) as $pv) if (is_array($pv) && count($pv) === 2) settings_walk($data, $pv[0], $pv[1], false);
}

if ($action === 'savebatch') {
    if (!is_array($body)) fail(400, 'batch body required');
    $cid = safe_id($body['company'] ?? ''); need_company($ME, $cid);
    $settingRows = []; $settingPatches = []; $pkgSkipped = [];
    $opsKeep = (is_array($bodyKeep) && isset($bodyKeep['ops']) && is_array($bodyKeep['ops'])) ? $bodyKeep['ops'] : [];
    foreach ((isset($body['ops']) && is_array($body['ops']) ? $body['ops'] : []) as $oi => $op) {
        $opK = (isset($opsKeep[$oi]) && is_array($opsKeep[$oi])) ? $opsKeep[$oi] : $op;   // the same op, empty {} kept
        $table = $op['table'] ?? ''; $mode = $op['mode'] ?? 'replace';
        if ($table === 'settings') {
            if ($mode === 'patch') { $settingPatches[] = settings_guard_patch($opK, $ME); continue; }
            $settingRows[] = ['company'=>$cid, 'data'=>settings_guard_full($cid, $opK['data'] ?? new stdClass(), $ME)]; continue;
        }
        if (!in_array($table, PARTITION_TABLES, true) && !in_array($table, OPTIONAL_TABLES, true)) continue;
        if (!pkg_table_ok($cid, $table, $ME)) { $pkgSkipped[] = $table; continue; }   // feature outside the package: stored rows stay as they are
        $ep = '/' . rawurlencode($table);

        if ($mode === 'merge') {
            $key = MERGE_KEYS[$table] ?? ($op['key'] ?? 'id');
            foreach ((array)($op['deletes'] ?? []) as $dk) {
                sb_req('DELETE', $ep . "?company=eq." . rawurlencode($cid) . "&rk=eq." . rawurlencode((string)$dk));
            }
            $rows = []; $upK = (isset($opK['upserts']) && is_array($opK['upserts'])) ? $opK['upserts'] : [];
            foreach ((array)($op['upserts'] ?? []) as $ri => $row) $rows[] = ['company'=>$cid,'rk'=>rowkey($row,$key),'data'=>$upK[$ri] ?? $row];
            if ($rows) sb_upsert($table, $rows, 'company,rk');
        } elseif ($mode === 'delta') {
            /* quantities as +/- changes: the database adds them to what it has (row-locked, once per change-set id) */
            if (!isset(DELTA_FIELDS[$table])) continue;
            $key = MERGE_KEYS[$table]; $rows = []; $plain = []; $dels = [];
            $rowsK = (isset($opK['rows']) && is_array($opK['rows'])) ? $opK['rows'] : [];
            foreach ((array)($op['rows'] ?? []) as $xi => $x) {
                if (!is_array($x) || !is_array($x['data'] ?? null)) continue;
                $rk = rowkey($x['data'], $key); if ($rk === '') continue;
                $xK = (isset($rowsK[$xi]) && is_array($rowsK[$xi])) ? $rowsK[$xi] : $x;
                $dataK = (isset($xK['data']) && is_array($xK['data'])) ? $xK['data'] : $x['data'];
                $d = []; foreach (DELTA_FIELDS[$table] as $f) if (isset($x['d'][$f]) && is_numeric($x['d'][$f])) $d[$f] = 0 + $x['d'][$f];
                $set = (isset($xK['set']) && is_array($xK['set'])) ? $xK['set'] : []; foreach (DELTA_FIELDS[$table] as $f) unset($set[$f]);
                $del = !empty($x['del']);
                $rows[] = ['rk'=>$rk, 'd'=>(object)$d, 'set'=>(object)$set, 'data'=>$dataK, 'del'=>$del];
                if ($del) $dels[] = $rk; else $plain[] = ['company'=>$cid, 'rk'=>$rk, 'data'=>$dataK];
            }
            if (!$rows) continue;
            list($http, $resp) = sb_try('POST', '/rpc/wms_apply_delta', ['p_id'=>substr((string)($op['id'] ?? ''), 0, 120), 'p_table'=>$table, 'p_company'=>$cid, 'p_rows'=>$rows]);
            if ($http >= 200 && $http < 300) continue;
            if ($http == 404) {                          // sql_updates/2026-10-24_stock_delta.sql not run yet: the old way (whole values)
                foreach ($dels as $dk) sb_req('DELETE', $ep . "?company=eq." . rawurlencode($cid) . "&rk=eq." . rawurlencode((string)$dk));
                if ($plain) sb_upsert($table, $plain, 'company,rk');
                continue;
            }
            error_log('mywms: wms_apply_delta http ' . $http . ': ' . substr((string)$resp, 0, 300));
            sb_fail($http);
        } else {
            replace_table($cid, $table, $opK['data'] ?? []);
        }
    }
    if ($settingPatches) {                       // apply on the latest stored settings (or on a replace sent in this same batch)
        if ($settingRows) { $last = end($settingRows); $base = as_map($last['data']); $settingRows = []; }
        else { $r = sb_req('GET', "/settings?select=data&company=eq." . rawurlencode($cid), null, [], false, null, true); $base = as_map($r[0]['data'] ?? []); }
        foreach ($settingPatches as $sp) settings_patch($base, $sp);
        $settingRows[] = ['company'=>$cid, 'data'=>$base ?: new stdClass()];
    }
    if ($settingRows) sb_upsert('settings', $settingRows, 'company');
    $g = (isset($body['globals']) && is_array($body['globals'])) ? $body['globals'] : [];
    $gKeep = (is_array($bodyKeep) && isset($bodyKeep['globals']) && is_array($bodyKeep['globals'])) ? $bodyKeep['globals'] : [];
    foreach (['companies','users','seq','billing'] as $gk) if (array_key_exists($gk, $g))
        write_global($gk, ($gk === 'billing' && isset($gKeep['billing']) && is_array($gKeep['billing'])) ? $gKeep['billing'] : $g[$gk]);
    if ($pkgSkipped) { $pkgSkipped = array_values(array_unique($pkgSkipped)); audit_event('PKG_BLOCKED', $cid, null, ['tables'=>$pkgSkipped, 'pkg'=>pkg_of(pkg_settings($cid))]); ok(['ok'=>true, 'skipped'=>$pkgSkipped]); }
    ok(['ok'=>true]);
}

if ($action === 'get' || $action === 'put') {
    $cid = safe_id($_GET['company'] ?? ''); $table = $_GET['table'] ?? '';
    if (in_array($table, ['companies','users','seq'], true)) {
        if ($action === 'get') {
            if ($table === 'users')     ok(scoped_users($USERS, $ME));
            if ($table === 'companies') ok(scoped_companies(kv_get('companies', []), $ME));
            ok(kv_get('seq', new stdClass()));
        }
        write_global($table, $body ?? []); ok(['ok'=>true,'table'=>$table]);
    }
    need_company($ME, $cid);
    if ($table === 'settings') {
        if ($action === 'get') { $r = sb_req('GET', "/settings?select=data&company=eq." . rawurlencode($cid), null, [], false, null, true); ok(isset($r[0]['data']) ? $r[0]['data'] : new stdClass()); }
        sb_upsert('settings', [['company'=>$cid,'data'=>settings_guard_full($cid, $bodyKeep ?? new stdClass(), $ME)]], 'company'); ok(['ok'=>true]);
    }
    if (!in_array($table, PARTITION_TABLES, true)) fail(400, 'unknown table');
    if ($action === 'put' && !pkg_table_ok($cid, $table, $ME)) fail(403, 'this table is not in the company\'s package');
    if ($action === 'get') {
        $out = []; foreach ((array)sb_req('GET', '/' . rawurlencode($table) . "?select=data&company=eq." . rawurlencode($cid), null, [], false, null, true) as $r) $out[] = $r['data'];
        ok($out);
    }
    replace_table($cid, $table, $bodyKeep);
    ok(['ok'=>true,'company'=>$cid,'table'=>$table]);
}

/* ---------- auditlog: read the data log (Admin: own company · Super Admin: all); there is no way to write it from here ---------- */
if ($action === 'auditlog') {
    if (!is_admin($ME)) fail(403, 'admins only');
    $q = '/audit_log?select=id,at,company,actor,source,ip,req_id,op,table_name,row_key,changes&order=id.desc';
    $cid = is_super($ME) ? (string)($_GET['company'] ?? '') : user_company($ME);
    if ($cid !== '') { if (!safe_id($cid)) fail(400, 'bad company'); $q .= '&company=eq.' . rawurlencode($cid); }
    foreach (['from'=>'gte', 'to'=>'lt'] as $f => $cmp) if (!empty($_GET[$f])) {
        $ts = strtotime((string)$_GET[$f]); if ($ts === false) fail(400, 'bad date');
        $q .= '&at=' . $cmp . '.' . gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
    foreach (['actor'=>'actor', 'table'=>'table_name', 'op'=>'op'] as $f => $col) if (($_GET[$f] ?? '') !== '') $q .= '&' . $col . '=eq.' . rawurlencode(substr((string)$_GET[$f], 0, 80));
    if (($_GET['key'] ?? '') !== '') $q .= '&row_key=ilike.*' . rawurlencode(str_replace('*', '', substr((string)$_GET['key'], 0, 80))) . '*';
    $limit = 100; $offset = max(0, (int)($_GET['offset'] ?? 0));
    $rows = (array)sb_req('GET', $q . '&limit=' . ($limit + 1) . '&offset=' . $offset);
    $more = count($rows) > $limit;
    ok(['ok'=>true, 'rows'=>array_slice($rows, 0, $limit), 'more'=>$more, 'offset'=>$offset, 'limit'=>$limit]);
}

/* ---------- upload / delimg : Supabase Storage ---------- */
if ($action === 'upload' || $action === 'delimg') {
    $cid  = safe_id(isset($body['company']) ? $body['company'] : ($_GET['company'] ?? ''));
    need_company($ME, $cid);
    $code = preg_replace('/[^A-Za-z0-9_-]/', '_', isset($body['code']) ? (string)$body['code'] : (string)($_GET['code'] ?? ''));
    if ($code === '' || strlen($code) > 60) fail(400, 'invalid code');
    $bucket = $CFG['bucket']; $objPath = "images/$cid/$code.jpg";
    $publicUrl = $CFG['url'] . "/storage/v1/object/public/$bucket/$objPath";

    if ($action === 'delimg') {
        sb_req('DELETE', "/object/$bucket/$objPath", null, [], true);
        audit_event('IMAGE_DELETE', $cid, $code);
        ok(['ok'=>true, 'deleted'=>$objPath]);
    }

    $data = isset($body['data']) ? (string)$body['data'] : '';
    if ($data === '') fail(400, 'no image data');
    if (strpos($data, 'base64,') !== false) $data = substr($data, strpos($data, 'base64,') + 7);
    $bytes = base64_decode($data, true);
    if ($bytes === false || strlen($bytes) < 8) fail(400, 'invalid base64 image');
    if (strlen($bytes) > 6 * 1024 * 1024) fail(413, 'image too large (max 6MB)');

    // must be a real image; always re-encode to JPEG (strips anything that isn't pixels)
    if (!function_exists('imagecreatefromstring')) fail(500, 'GD extension is required for image uploads');
    $sz = @getimagesizefromstring($bytes);                       // reject huge pixel counts before decoding (memory)
    if ($sz === false) fail(400, 'not a valid image');
    if ((int)$sz[0] * (int)$sz[1] > 25000000) fail(413, 'image dimensions too large');
    $im = @imagecreatefromstring($bytes);
    if ($im === false) fail(400, 'not a valid image');
    $w = imagesx($im); $h = imagesy($im); $max = 1000;
    if ($w > $max || $h > $max) { $r = min($max/$w, $max/$h); $nw=(int)round($w*$r); $nh=(int)round($h*$r);
        $d = imagecreatetruecolor($nw,$nh); imagecopyresampled($d,$im,0,0,0,0,$nw,$nh,$w,$h); imagedestroy($im); $im=$d; }
    ob_start(); imagejpeg($im, null, 85); $bytes = ob_get_clean(); imagedestroy($im);

    sb_req('POST', "/object/$bucket/$objPath", null, ['Content-Type: image/jpeg', 'x-upsert: true'], true, $bytes);
    audit_event('IMAGE_UPLOAD', $cid, $code);
    ok(['ok'=>true, 'path'=>$publicUrl . '?v=' . time()]);
}

/* ---- Marketplace (Shopee / Lazada) — online store is in "WMS + Business" and up ---- */
if (strncmp($action, 'mp_', 3) === 0 && function_exists('mp_handle')) {
    if (!pkg_has_rank(pkg_settings(user_company($ME)), 'business', $ME)) fail(403, 'the online store is not in this company\'s package');
    mp_handle($action, $ME, $body);
}

fail(400, 'unknown action');
