<?php
/* =====================================================================
 *  MARKETPLACE CONNECTOR  —  Shopee Open Platform v2 + Lazada Open Platform
 *  Included by api_supabase.php.  Handles every action whose name starts
 *  with "mp_".  Returns silently if the action is not ours.
 *
 *  WHY THIS LIVES ON THE SERVER
 *   partner_key (Shopee) and app_secret (Lazada) sign every request. They must
 *   never reach the browser, so the browser only ever talks to this file and
 *   this file talks to Shopee / Lazada. Secrets are stored in the Supabase kv
 *   table under "mp:<company>", which ?action=load never reads, so they are
 *   never sent to a client. mp_config_get returns them masked.
 *
 *  ACTIONS
 *    mp_config_get   GET   -> current settings, secrets masked          (admin)
 *    mp_config_set   POST  -> save partner_id / keys / shop_id / region (admin)
 *    mp_auth_url     GET   ?platform=shopee|lazada -> URL to authorise  (admin)
 *    mp_auth_callback GET  <- the platform redirects the seller here    (public)
 *    mp_status       GET   -> which platforms are linked, token expiry
 *    mp_pull         POST  {from,to} -> fetch orders, store, return them
 *    mp_ship         POST  {id} -> tell the platform the parcel is ready
 *    mp_label        POST  {id} -> shipping label / airway bill
 *
 *  SETUP (per company, done once by an Admin in the app)
 *   Shopee  : Open Platform -> App -> partner_id + partner_key, then "Link".
 *   Lazada  : Open Platform -> App -> app_key + app_secret, then "Link".
 *   Both consoles need the redirect URL that mp_config_get reports back.
 * ===================================================================== */

if (!defined('MP_INCLUDED')) {
define('MP_INCLUDED', 1);

/* ---------- generic HTTPS call (the platforms, not Supabase) ---------- */
function mp_http($method, $url, $jsonBody = null, $headers = []) {
    $ch = curl_init($url);
    $h  = array_merge(['Accept: application/json'], $headers);
    if ($jsonBody !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($jsonBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['_http' => 0, '_error' => $err ?: 'connection failed'];
    $d = json_decode($raw, true);
    if (!is_array($d)) return ['_http' => $code, '_error' => 'bad response', '_raw' => substr((string)$raw, 0, 400)];
    $d['_http'] = $code;
    return $d;
}
/* POST a form body (Lazada's write APIs take their params form-encoded) */
function mp_http_form($url, $params) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['_http' => 0, '_error' => $err ?: 'connection failed'];
    $d = json_decode($raw, true);
    if (!is_array($d)) return ['_http' => $code, '_error' => 'bad response', '_raw' => substr((string)$raw, 0, 400)];
    $d['_http'] = $code;
    return $d;
}
/* POST JSON and keep the body as bytes (Shopee's label download returns a PDF, not JSON) */
function mp_http_bytes($url, $jsonBody) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($jsonBody, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['http' => $code, 'type' => $type, 'body' => $raw === false ? '' : $raw, 'error' => $raw === false ? ($err ?: 'connection failed') : ''];
}

/* ---------- config, stored server-side only ---------- */
function mp_cfg_key($cid) { return 'mp:' . $cid; }
function mp_cfg_load($cid) { $d = kv_get(mp_cfg_key($cid), []); return is_array($d) ? $d : []; }
function mp_cfg_save($cid, $d) { kv_put(mp_cfg_key($cid), $d); }
/* after a long call: write back only the token fields, onto the config as it is NOW, and only if the
   app (partner_id / app_key) and mode were not changed by an admin in the meantime */
function mp_cfg_save_tokens($cid, $platform, $c) {
    $cfg = mp_cfg_load($cid); $now = $cfg[$platform] ?? [];
    $idk = $platform === 'shopee' ? 'partner_id' : 'app_key';
    if ((string)($now[$idk] ?? '') !== (string)($c[$idk] ?? '') || !empty($now['sandbox']) !== !empty($c['sandbox'])) return;
    if (empty($now['access_token'])) return;                       // unlinked meanwhile: don't resurrect the token
    foreach (['access_token', 'refresh_token', 'expires_at'] as $k) if (isset($c[$k])) $now[$k] = $c[$k];
    $cfg[$platform] = $now; mp_cfg_save($cid, $cfg);
}
/* "1,299.00" -> 1299.0  (Lazada sends some amounts as strings with thousands separators) */
function mp_num($v) { return (float)str_replace([',', ' '], '', (string)$v); }
/* a date picked in the app (Thai time) -> unix time; $end = end of that day */
function mp_day_ts($d, $end) {
    $d = trim((string)$d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return 0;
    return (int)strtotime($d . ($end ? ' 23:59:59' : ' 00:00:00') . ' +07:00');
}

function mp_redirect_uri() {
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $proto === 'https'
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/api_supabase.php');
    return ($https ? 'https' : 'http') . '://' . $host . $path . '?action=mp_auth_callback';
}

/* the seller is sent back to the redirect URL, which has no session; carry the
   company in a short-lived signed state so the callback knows where to store tokens */
function mp_state_make($cid, $platform) {
    /* sign the exact url-safe text that travels, so the callback can verify it as received */
    $p = rtrim(strtr(base64_encode(json_encode(['c' => $cid, 'p' => $platform, 'exp' => time() + 900])), '+/', '-_'), '=');
    return $p . '.' . substr(hash_hmac('sha256', $p, token_secret()), 0, 24);
}
function mp_state_read($s) {
    $parts = explode('.', (string)$s);
    if (count($parts) !== 2) return null;
    if (!hash_equals(substr(hash_hmac('sha256', $parts[0], token_secret()), 0, 24), $parts[1])) return null;
    $d = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
    if (!is_array($d) || ($d['exp'] ?? 0) < time()) return null;
    return $d;
}
/* fallback when a platform drops the state: remember the last link started per platform */
function mp_pending_key($platform) { return 'mp_pending:' . $platform; }
function mp_pending_put($cid, $platform) { kv_put(mp_pending_key($platform), ['c' => $cid, 'p' => $platform, 'exp' => time() + 900]); }
function mp_pending_take($platform) {
    $d = kv_get(mp_pending_key($platform), null);
    if (!is_array($d) || ($d['exp'] ?? 0) < time() || !safe_id($d['c'] ?? '')) return null;
    kv_put(mp_pending_key($platform), ['exp' => 0]);                  // one use only
    return $d;
}

/* =====================================================================
 *  SHOPEE  —  https://open.shopee.com  (Open Platform v2)
 *  sign = HMAC-SHA256( partner_id + api_path + timestamp [+ access_token + shop_id] , partner_key )
 * ===================================================================== */
function mp_shopee_host($c) {
    return !empty($c['sandbox'])
        ? 'https://partner.test-stable.shopeemobile.com'
        : 'https://partner.shopeemobile.com';
}
function mp_shopee_sign($path, $ts, $partnerId, $partnerKey, $token = '', $shopId = '') {
    return hash_hmac('sha256', $partnerId . $path . $ts . $token . $shopId, $partnerKey);
}
/* shop-level call: access_token + shop_id are part of the signature */
function mp_shopee_call($c, $path, $query = [], $body = null) {
    return mp_http($body === null ? 'GET' : 'POST', mp_shopee_url($c, $path, $query), $body);
}
function mp_shopee_url($c, $path, $query = []) {
    $ts  = time();
    $pid = (string)($c['partner_id'] ?? '');
    $key = (string)($c['partner_key'] ?? '');
    $tok = (string)($c['access_token'] ?? '');
    $sid = (string)($c['shop_id'] ?? '');
    $q = array_merge([
        'partner_id'   => $pid,
        'timestamp'    => $ts,
        'access_token' => $tok,
        'shop_id'      => $sid,
        'sign'         => mp_shopee_sign($path, $ts, $pid, $key, $tok, $sid),
    ], $query);
    return mp_shopee_host($c) . $path . '?' . http_build_query($q);
}
/* public call (token exchange / refresh): no token or shop in the signature */
function mp_shopee_public($c, $path, $body) {
    $ts  = time();
    $pid = (string)($c['partner_id'] ?? '');
    $key = (string)($c['partner_key'] ?? '');
    $q = ['partner_id' => $pid, 'timestamp' => $ts, 'sign' => mp_shopee_sign($path, $ts, $pid, $key)];
    $url = mp_shopee_host($c) . $path . '?' . http_build_query($q);
    return mp_http('POST', $url, $body);
}
function mp_shopee_auth_url($c, $state) {
    $ts   = time();
    $path = '/api/v2/shop/auth_partner';
    $pid  = (string)($c['partner_id'] ?? '');
    $sign = mp_shopee_sign($path, $ts, $pid, (string)($c['partner_key'] ?? ''));
    $redirect = mp_redirect_uri() . '&state=' . rawurlencode($state);
    return mp_shopee_host($c) . $path . '?' . http_build_query([
        'partner_id' => $pid, 'timestamp' => $ts, 'sign' => $sign, 'redirect' => $redirect,
    ]);
}
/* tokens last ~4h; refresh when less than 10 minutes are left */
function mp_shopee_fresh(&$c) {
    if (empty($c['access_token'])) return 'not linked';
    if ((int)($c['expires_at'] ?? 0) > time() + 600) return '';
    $r = mp_shopee_public($c, '/api/v2/auth/access_token/get', [
        'refresh_token' => (string)($c['refresh_token'] ?? ''),
        'partner_id'    => (int)($c['partner_id'] ?? 0),
        'shop_id'       => (int)($c['shop_id'] ?? 0),
    ]);
    if (empty($r['access_token'])) return 'refresh failed: ' . (string)($r['message'] ?? $r['_error'] ?? 'unknown');
    $c['access_token']  = $r['access_token'];
    $c['refresh_token'] = $r['refresh_token'] ?? ($c['refresh_token'] ?? '');
    $c['expires_at']    = time() + (int)($r['expire_in'] ?? 14400);
    return '';
}

/* =====================================================================
 *  LAZADA  —  https://open.lazada.com
 *  sign = upper( HMAC-SHA256( api_path + concat(sorted k.v) , app_secret ) )
 * ===================================================================== */
function mp_lazada_host($c) {
    $hosts = [
        'th' => 'api.lazada.co.th',  'my' => 'api.lazada.com.my', 'sg' => 'api.lazada.sg',
        'id' => 'api.lazada.co.id',  'ph' => 'api.lazada.com.ph', 'vn' => 'api.lazada.vn',
    ];
    $r = strtolower((string)($c['region'] ?? 'th'));
    return 'https://' . ($hosts[$r] ?? $hosts['th']);
}
function mp_lazada_sign($path, $params, $secret) {
    ksort($params);
    $s = $path;
    foreach ($params as $k => $v) $s .= $k . $v;
    return strtoupper(hash_hmac('sha256', $s, $secret));
}
function mp_lazada_call($c, $path, $params = [], $withToken = true, $method = 'GET') {
    $p = array_merge($params, [
        'app_key'     => (string)($c['app_key'] ?? ''),
        'timestamp'   => (string)(round(microtime(true) * 1000)),
        'sign_method' => 'sha256',
    ]);
    if ($withToken) $p['access_token'] = (string)($c['access_token'] ?? '');
    $p['sign'] = mp_lazada_sign($path, $p, (string)($c['app_secret'] ?? ''));
    $base = $withToken ? mp_lazada_host($c) . '/rest' : 'https://auth.lazada.com/rest';
    if ($method === 'POST') return mp_http_form($base . $path, $p);
    return mp_http('GET', $base . $path . '?' . http_build_query($p));
}
function mp_lazada_auth_url($c, $state) {
    return 'https://auth.lazada.com/oauth/authorize?' . http_build_query([
        'response_type' => 'code',
        'force_auth'    => 'true',
        'redirect_uri'  => mp_redirect_uri(),             /* must equal the URL registered in the console */
        'client_id'     => (string)($c['app_key'] ?? ''),
        'state'         => $state,
    ]);
}
function mp_lazada_fresh(&$c) {
    if (empty($c['access_token'])) return 'not linked';
    if ((int)($c['expires_at'] ?? 0) > time() + 600) return '';
    $r = mp_lazada_call($c, '/auth/token/refresh', ['refresh_token' => (string)($c['refresh_token'] ?? '')], false);
    if (empty($r['access_token'])) return 'refresh failed: ' . (string)($r['message'] ?? $r['_error'] ?? 'unknown');
    $c['access_token']  = $r['access_token'];
    $c['refresh_token'] = $r['refresh_token'] ?? ($c['refresh_token'] ?? '');
    $c['expires_at']    = time() + (int)($r['expires_in'] ?? 2592000);
    return '';
}

/* =====================================================================
 *  Normalising both platforms into one order shape the app understands
 * ===================================================================== */
function mp_norm_row($platform, $orderNo, $ts, $status, $buyer, $total, $ship, $track, $lines, $raw) {
    return [
        'id'       => $platform . ':' . $orderNo,
        'platform' => $platform,
        'orderNo'  => (string)$orderNo,
        'date'     => $ts ? gmdate('Y-m-d', $ts + 7 * 3600) : '',    // ICT
        'time'     => $ts ? gmdate('H:i', $ts + 7 * 3600) : '',
        'status'   => (string)$status,
        'buyer'    => (string)$buyer,
        'total'    => (float)$total,
        'shipping' => (string)$ship,
        'tracking' => (string)$track,
        'lines'    => $lines,
        'picked'   => false,
        'raw'      => $raw,
    ];
}

/* '' when the call succeeded, otherwise a readable reason (API error, HTTP error or no connection) */
function mp_shopee_err($r) {
    if (!empty($r['error'])) return $r['error'] . ' ' . (string)($r['message'] ?? '');
    if (!empty($r['_error'])) return (string)$r['_error'] . (empty($r['_http']) ? '' : ' (http ' . $r['_http'] . ')');
    if (($r['_http'] ?? 200) >= 400) return 'http ' . $r['_http'];
    return '';
}
function mp_lazada_err($r) {
    if (!empty($r['_error'])) return (string)$r['_error'] . (empty($r['_http']) ? '' : ' (http ' . $r['_http'] . ')');
    if (isset($r['code']) && (string)$r['code'] !== '0') return (string)($r['message'] ?? $r['code']);
    /* the newer fulfillment APIs wrap their outcome in result{success,error_code,error_msg} */
    if (isset($r['result']['success']) && !$r['result']['success'])
        return (string)($r['result']['error_msg'] ?? $r['result']['error_code'] ?? 'failed');
    if (($r['_http'] ?? 200) >= 400) return 'http ' . $r['_http'];
    return '';
}

function mp_pull_shopee(&$c, $from, $to, &$errors) {
    $e = mp_shopee_fresh($c);
    if ($e !== '') { $errors[] = 'Shopee: ' . $e; return []; }
    $out = [];
    for ($wFrom = $from; $wFrom < $to; $wFrom += 15 * 86400) {
        $wTo = min($to, $wFrom + 15 * 86400 - 1);
        $part = mp_pull_shopee_window($c, $wFrom, $wTo, $errors);
        if ($part === null) break;                                   // API error: stop (already reported)
        $out = array_merge($out, $part);
    }
    return $out;
}
function mp_pull_shopee_window(&$c, $from, $to, &$errors) {
    $out = []; $cursor = ''; $guard = 0;
    do {
        $r = mp_shopee_call($c, '/api/v2/order/get_order_list', [
            'time_range_field' => 'create_time',
            'time_from'        => $from,
            'time_to'          => $to,
            'page_size'        => 50,
            'cursor'           => $cursor,
            'response_optional_fields' => 'order_status',
        ]);
        if ($m = mp_shopee_err($r)) { $errors[] = 'Shopee: ' . $m; return $out ?: null; }
        $list = $r['response']['order_list'] ?? [];
        $sns = [];
        foreach ($list as $o) if (!empty($o['order_sn'])) $sns[] = $o['order_sn'];
        /* detail: max 50 order_sn per call */
        foreach (array_chunk($sns, 50) as $chunk) {
            $d = mp_shopee_call($c, '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'buyer_username,item_list,total_amount,recipient_address,shipping_carrier',
            ]);
            if ($m = mp_shopee_err($d)) { $errors[] = 'Shopee detail: ' . $m; continue; }
            foreach (($d['response']['order_list'] ?? []) as $o) {
                $lines = [];
                foreach (($o['item_list'] ?? []) as $it) {
                    $lines[] = [
                        'sku'   => (string)($it['model_sku'] ?? $it['item_sku'] ?? ''),
                        'name'  => (string)($it['item_name'] ?? ''),
                        'qty'   => (float)($it['model_quantity_purchased'] ?? 0),
                        'price' => mp_num($it['model_discounted_price'] ?? $it['model_original_price'] ?? 0),
                    ];
                }
                $out[] = mp_norm_row('shopee', $o['order_sn'] ?? '', (int)($o['create_time'] ?? 0),
                    $o['order_status'] ?? '', $o['buyer_username'] ?? '', mp_num($o['total_amount'] ?? 0),
                    $o['shipping_carrier'] ?? '', '', $lines, ['sn' => $o['order_sn'] ?? '']);
            }
        }
        $cursor = (string)($r['response']['next_cursor'] ?? '');
        $more = !empty($r['response']['more']);
    } while ($more && $cursor !== '' && ++$guard < 40);
    return $out;
}

function mp_pull_lazada(&$c, $from, $to, &$errors) {
    $e = mp_lazada_fresh($c);
    if ($e !== '') { $errors[] = 'Lazada: ' . $e; return []; }
    $out = []; $offset = 0; $guard = 0;
    do {
        $r = mp_lazada_call($c, '/orders/get', [
            'created_after'  => gmdate('c', $from),
            'created_before' => gmdate('c', $to),
            'limit'          => 50,
            'offset'         => $offset,
            'sort_direction' => 'DESC',
        ]);
        if ($m = mp_lazada_err($r)) { $errors[] = 'Lazada: ' . $m; break; }
        $orders = $r['data']['orders'] ?? [];
        /* items for the whole page in one call (max 50 order ids) instead of one call per order */
        $itemsBy = [];
        $ids = [];
        foreach ($orders as $o) { $x = (string)($o['order_id'] ?? $o['order_number'] ?? ''); if ($x !== '') $ids[] = (int)$x; }
        if ($ids) {
            $it = mp_lazada_call($c, '/orders/items/get', ['order_ids' => json_encode($ids)]);
            if ($m = mp_lazada_err($it)) $errors[] = 'Lazada items: ' . $m;
            foreach (($it['data'] ?? []) as $grp) $itemsBy[(string)($grp['order_id'] ?? '')] = $grp['order_items'] ?? [];
        }
        foreach ($orders as $o) {
            $oid = (string)($o['order_id'] ?? $o['order_number'] ?? '');
            $lines = [];
            foreach (($itemsBy[$oid] ?? []) as $x) {
                $lines[] = [
                    'sku'   => (string)($x['sku'] ?? $x['shop_sku'] ?? ''),
                    'name'  => (string)($x['name'] ?? ''),
                    'qty'   => 1,                                   /* Lazada returns one row per unit */
                    'price' => mp_num($x['item_price'] ?? 0),
                ];
            }
            /* collapse the per-unit rows into one line per sku */
            $byKey = [];
            foreach ($lines as $l) {
                $k = $l['sku'] . '|' . $l['price'];
                if (isset($byKey[$k])) $byKey[$k]['qty'] += $l['qty'];
                else $byKey[$k] = $l;
            }
            $out[] = mp_norm_row('lazada', $oid, strtotime((string)($o['created_at'] ?? '')) ?: 0,
                $o['statuses'][0] ?? '', trim((string)($o['customer_first_name'] ?? '') . ' ' . (string)($o['customer_last_name'] ?? '')),
                mp_num($o['price'] ?? 0), $o['shipment_provider'] ?? '', '', array_values($byKey), ['order_id' => $oid]);
        }
        $offset += 50;
        $more = count($orders) >= 50;
    } while ($more && ++$guard < 40);
    return $out;
}

/* =====================================================================
 *  Ship + shipping label.  Each returns ['error'=>..] or
 *  ['tracking'=>.., 'url'=>.. | 'pdf'=>base64]
 * ===================================================================== */
define('MP_SHOPEE_DOC', 'THERMAL_AIR_WAYBILL');      // define(): const is not allowed inside the include guard's if-block

function mp_shopee_tracking($c, $sn) {
    $r = mp_shopee_call($c, '/api/v2/logistics/get_tracking_number', ['order_sn' => $sn]);
    return (string)($r['response']['tracking_number'] ?? '');
}
function mp_shopee_ship(&$c, $sn) {
    $par = mp_shopee_call($c, '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $sn]);
    if ($m = mp_shopee_err($par)) return ['error' => $m];
    $info = (array)($par['response']['info_needed'] ?? []);
    $payload = ['order_sn' => $sn];
    if (array_key_exists('pickup', $info) || in_array('pickup', $info, true)) {      // info_needed is {pickup:[..],dropoff:[..]}
        $addr = $par['response']['pickup']['address_list'][0] ?? null;
        if (!$addr) return ['error' => 'no pickup address set up in Shopee Seller Centre'];
        $payload['pickup'] = ['address_id' => (int)($addr['address_id'] ?? 0),
            'pickup_time_id' => (string)($addr['time_slot_list'][0]['pickup_time_id'] ?? '')];
    } elseif (array_key_exists('dropoff', $info) || in_array('dropoff', $info, true)) {
        $payload['dropoff'] = new stdClass();
    }
    $r = mp_shopee_call($c, '/api/v2/logistics/ship_order', [], $payload);
    if ($m = mp_shopee_err($r)) return ['error' => $m];
    return ['tracking' => mp_shopee_tracking($c, $sn)];          // may still be empty right after shipping
}
function mp_shopee_label(&$c, $sn) {
    $track = mp_shopee_tracking($c, $sn);
    $item  = ['order_sn' => $sn, 'shipping_document_type' => MP_SHOPEE_DOC];
    if ($track !== '') $item['tracking_number'] = $track;
    /* 1) ask Shopee to render it (an "already created" error is fine) */
    $cr = mp_shopee_call($c, '/api/v2/logistics/create_shipping_document', [], ['order_list' => [$item]]);
    $createErr = mp_shopee_err($cr) ?: (string)($cr['response']['result_list'][0]['fail_message'] ?? '');
    /* 2) wait until it is READY */
    $status = '';
    for ($i = 0; $i < 6; $i++) {
        $st = mp_shopee_call($c, '/api/v2/logistics/get_shipping_document_result', [],
            ['order_list' => [['order_sn' => $sn, 'shipping_document_type' => MP_SHOPEE_DOC]]]);
        $status = (string)($st['response']['result_list'][0]['status'] ?? '');
        if ($status === 'READY' || $status === 'FAILED') break;
        usleep(1500000);
    }
    if ($status !== 'READY') return ['error' => 'label not ready (' . ($status ?: 'unknown') . ')' . ($createErr ? ' — ' . $createErr : '')];
    /* 3) download the PDF bytes */
    $dl = mp_http_bytes(mp_shopee_url($c, '/api/v2/logistics/download_shipping_document'),
        ['shipping_document_type' => MP_SHOPEE_DOC, 'order_list' => [['order_sn' => $sn]]]);
    if ($dl['error'] !== '') return ['error' => $dl['error']];
    if ($dl['http'] >= 400 || strncmp($dl['body'], '%PDF', 4) !== 0) {
        $j = json_decode($dl['body'], true);
        return ['error' => is_array($j) ? (mp_shopee_err($j) ?: 'download failed') : 'download failed (http ' . $dl['http'] . ')'];
    }
    return ['tracking' => $track, 'pdf' => base64_encode($dl['body'])];
}

/* Lazada: order items carry the package_id once packed */
function mp_lazada_items($c, $orderId) {
    $r = mp_lazada_call($c, '/order/items/get', ['order_id' => $orderId]);
    if ($m = mp_lazada_err($r)) return ['error' => $m];
    return ['items' => (array)($r['data'] ?? [])];
}
function mp_lazada_packages($items) {
    $pk = [];
    foreach ($items as $x) { $p = (string)($x['package_id'] ?? ''); if ($p !== '') $pk[$p] = (string)($x['tracking_code'] ?? ''); }
    return $pk;                                                    // package_id => tracking
}
function mp_lazada_ship(&$c, $orderId) {
    $it = mp_lazada_items($c, $orderId);
    if (isset($it['error'])) return $it;
    $todo = [];
    foreach ($it['items'] as $x) {
        if ((string)($x['package_id'] ?? '') !== '') continue;
        if (in_array((string)($x['status'] ?? ''), ['canceled', 'returned', 'shipped', 'delivered'], true)) continue;
        $todo[] = (int)$x['order_item_id'];
    }
    $pk = mp_lazada_packages($it['items']);
    /* 1) pack whatever is not packed yet */
    if ($todo) {
        $r = mp_lazada_call($c, '/order/fulfill/pack', ['packReq' => json_encode([
            'pack_order_list'        => [['order_id' => (int)$orderId, 'order_item_list' => $todo]],
            'delivery_type'          => 'dropship',
            'shipping_allocate_type' => 'TFS',
        ])], true, 'POST');
        if ($m = mp_lazada_err($r)) return ['error' => 'pack: ' . $m];
        foreach (($r['result']['data']['pack_order_list'] ?? []) as $po)
            foreach (($po['order_item_list'] ?? []) as $x) {
                $p = (string)($x['package_id'] ?? '');
                if ($p === '') return ['error' => 'pack: ' . (string)($x['msg'] ?? 'no package created')];
                $pk[$p] = (string)($x['tracking_number'] ?? ($pk[$p] ?? ''));
            }
    }
    if (!$pk) return ['error' => 'nothing to ship'];
    /* 2) ready to ship (max 20 packages a call) */
    foreach (array_chunk(array_keys($pk), 20) as $chunk) {
        $r = mp_lazada_call($c, '/order/package/rts', ['readyToShipReq' => json_encode([
            'packages' => array_map(function ($p) { return ['package_id' => (string)$p]; }, $chunk),
        ])], true, 'POST');
        if ($m = mp_lazada_err($r)) return ['error' => 'ready to ship: ' . $m];
    }
    return ['tracking' => implode(', ', array_filter(array_values($pk)))];
}
function mp_lazada_label(&$c, $orderId) {
    $it = mp_lazada_items($c, $orderId);
    if (isset($it['error'])) return $it;
    $pk = mp_lazada_packages($it['items']);
    if (!$pk) return ['error' => 'not packed yet — press "Mark ready to ship" first'];
    $r = mp_lazada_call($c, '/order/package/document/get', ['getDocumentReq' => json_encode([
        'doc_type'        => 'PDF',
        'packages'        => array_map(function ($p) { return ['package_id' => (string)$p]; }, array_keys($pk)),
        'print_item_list' => false,
    ])], true, 'POST');
    if ($m = mp_lazada_err($r)) return ['error' => $m];
    $d = $r['result']['data'] ?? [];
    $out = ['tracking' => implode(', ', array_filter(array_values($pk)))];
    if (!empty($d['pdf_url'])) $out['url'] = (string)$d['pdf_url'];
    elseif (!empty($d['file'])) $out['pdf'] = (string)$d['file'];
    else return ['error' => 'no document returned'];
    return $out;
}

/* =====================================================================
 *  Dispatch
 * ===================================================================== */
function mp_handle($action, $ME, $body) {
    if (strncmp($action, 'mp_', 3) !== 0) return;

    /* the platform redirects the seller's browser here: no session, state carries the company */
    if ($action === 'mp_auth_callback') {
        header('Content-Type: text/html; charset=utf-8');
        $st = mp_state_read($_GET['state'] ?? '');
        /* Shopee always returns shop_id; Lazada returns only the code */
        if (!$st) $st = mp_pending_take(isset($_GET['shop_id']) ? 'shopee' : 'lazada');
        if (!$st) { http_response_code(400); echo 'Link expired — start again from Marketplace → Settings.'; exit; }
        $cid = $st['c']; $platform = $st['p'];
        $cfg = mp_cfg_load($cid);
        $c = $cfg[$platform] ?? [];
        $msg = '';
        if ($platform === 'shopee') {
            $c['shop_id'] = (string)($_GET['shop_id'] ?? ($c['shop_id'] ?? ''));
            $r = mp_shopee_public($c, '/api/v2/auth/token/get', [
                'code'       => (string)($_GET['code'] ?? ''),
                'shop_id'    => (int)$c['shop_id'],
                'partner_id' => (int)($c['partner_id'] ?? 0),
            ]);
            if (!empty($r['access_token'])) {
                $c['access_token']  = $r['access_token'];
                $c['refresh_token'] = $r['refresh_token'] ?? '';
                $c['expires_at']    = time() + (int)($r['expire_in'] ?? 14400);
                $msg = 'Shopee linked';
            } else $msg = 'Shopee link failed: ' . (string)($r['message'] ?? $r['_error'] ?? 'unknown');
        } else {
            $r = mp_lazada_call($c, '/auth/token/create', ['code' => (string)($_GET['code'] ?? '')], false);
            if (!empty($r['access_token'])) {
                $c['access_token']  = $r['access_token'];
                $c['refresh_token'] = $r['refresh_token'] ?? '';
                $c['expires_at']    = time() + (int)($r['expires_in'] ?? 2592000);
                $c['seller_id']     = (string)($r['account_id'] ?? ($c['seller_id'] ?? ''));
                $cty = strtolower((string)($r['country'] ?? ''));
                if (in_array($cty, ['th', 'my', 'sg', 'id', 'ph', 'vn'], true)) $c['region'] = $cty;
                $msg = 'Lazada linked';
            } else $msg = 'Lazada link failed: ' . (string)($r['message'] ?? $r['_error'] ?? 'unknown');
        }
        $cfg[$platform] = $c;
        mp_cfg_save($cid, $cfg);
        echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($msg) . '</title>'
           . '<body style="font:16px system-ui;padding:40px;text-align:center">'
           . '<h2>' . htmlspecialchars($msg) . '</h2>'
           . '<p>You can close this tab and go back to the app.</p>'
           . '<script>setTimeout(function(){window.close();},2500);</script>';
        exit;
    }

    /* everything else needs a signed-in user (api_supabase.php has already
       resolved one and passes it in; fall back if this is ever called earlier) */
    $me = is_array($ME) ? $ME : null;
    if ($me === null) { list($USERS, $i, $me) = require_auth(); }
    $cid = safe_id($_GET['company'] ?? ($body['company'] ?? user_company($me)));
    need_company($me, $cid);
    if (!is_mgr($me)) fail(403, 'manager or admin only');
    $cfg = mp_cfg_load($cid);

    if ($action === 'mp_config_get') {
        if (!is_admin($me)) fail(403, 'admin only');
        $mask = function ($c, $secretField) {
            $s = (string)($c[$secretField] ?? '');
            return [
                'linked'     => !empty($c['access_token']),
                'expires_at' => (int)($c['expires_at'] ?? 0),
                'hasSecret'  => $s !== '',
                'secretTail' => $s === '' ? '' : ('…' . substr($s, -4)),
                'sandbox'    => !empty($c['sandbox']),
            ];
        };
        ok(['ok' => true, 'redirect_uri' => mp_redirect_uri(),
            'shopee' => array_merge($mask($cfg['shopee'] ?? [], 'partner_key'), [
                'partner_id' => (string)($cfg['shopee']['partner_id'] ?? ''),
                'shop_id'    => (string)($cfg['shopee']['shop_id'] ?? ''),
            ]),
            'lazada' => array_merge($mask($cfg['lazada'] ?? [], 'app_secret'), [
                'app_key'   => (string)($cfg['lazada']['app_key'] ?? ''),
                'seller_id' => (string)($cfg['lazada']['seller_id'] ?? ''),
                'region'    => (string)($cfg['lazada']['region'] ?? 'th'),
            ])]);
    }

    if ($action === 'mp_config_set') {
        if (!is_admin($me)) fail(403, 'admin only');
        if (!is_array($body)) fail(400, 'body required');
        foreach (['shopee', 'lazada'] as $p) {
            if (!isset($body[$p]) || !is_array($body[$p])) continue;
            $c = $cfg[$p] ?? [];
            $idk = $p === 'shopee' ? 'partner_id' : 'app_key';
            $oldApp = (string)($c[$idk] ?? '');
            foreach (['partner_id', 'shop_id', 'app_key', 'seller_id', 'region'] as $k)
                if (isset($body[$p][$k])) $c[$k] = trim((string)$body[$p][$k]);
            if ($oldApp !== '' && (string)($c[$idk] ?? '') !== $oldApp)       // another app: its token is useless
                unset($c['access_token'], $c['refresh_token'], $c['expires_at']);
            /* a blank secret means "keep the one already stored" */
            foreach (['partner_key', 'app_secret'] as $k) {
                if (!isset($body[$p][$k])) continue;
                $v = trim((string)$body[$p][$k]);
                if ($v !== '') $c[$k] = $v;
            }
            if (isset($body[$p]['sandbox'])) {
                $sbx = !empty($body[$p]['sandbox']);
                /* test and live tokens are not interchangeable: switching mode disconnects the shop */
                if ($sbx !== !empty($c['sandbox'])) unset($c['access_token'], $c['refresh_token'], $c['expires_at']);
                $c['sandbox'] = $sbx;
            }
            if (!empty($body[$p]['unlink'])) { unset($c['access_token'], $c['refresh_token'], $c['expires_at']); }
            $cfg[$p] = $c;
        }
        mp_cfg_save($cid, $cfg);
        ok(['ok' => true]);
    }

    if ($action === 'mp_auth_url') {
        if (!is_admin($me)) fail(403, 'admin only');
        $p = (string)($_GET['platform'] ?? '');
        if ($p !== 'shopee' && $p !== 'lazada') fail(400, 'platform must be shopee or lazada');
        $c = $cfg[$p] ?? [];
        if ($p === 'shopee' && (empty($c['partner_id']) || empty($c['partner_key']))) fail(400, 'enter partner_id and partner_key first');
        if ($p === 'lazada' && (empty($c['app_key']) || empty($c['app_secret']))) fail(400, 'enter app_key and app_secret first');
        $state = mp_state_make($cid, $p);
        mp_pending_put($cid, $p);
        ok(['ok' => true, 'url' => $p === 'shopee' ? mp_shopee_auth_url($c, $state) : mp_lazada_auth_url($c, $state)]);
    }

    if ($action === 'mp_status') {
        ok(['ok' => true,
            'shopee' => ['linked' => !empty($cfg['shopee']['access_token']), 'expires_at' => (int)($cfg['shopee']['expires_at'] ?? 0)],
            'lazada' => ['linked' => !empty($cfg['lazada']['access_token']), 'expires_at' => (int)($cfg['lazada']['expires_at'] ?? 0)]]);
    }

    if ($action === 'mp_pull') {
        @set_time_limit(300);                                         // many pages × 45 s HTTP timeouts
        $to   = min(time(), mp_day_ts($body['to'] ?? '', true) ?: time());
        $from = mp_day_ts($body['from'] ?? '', false) ?: ($to - 7 * 86400);
        $errors = []; $rows = [];
        if ($from > $to) $from = $to - 86400;
        if ($to - $from > 90 * 86400) { $from = $to - 90 * 86400; $errors[] = 'range shortened to the last 90 days'; }
        $want = (string)($body['platform'] ?? 'all');
        if (($want === 'all' || $want === 'shopee') && !empty($cfg['shopee']['access_token']))
            $rows = array_merge($rows, mp_pull_shopee($cfg['shopee'], $from, $to, $errors));
        if (($want === 'all' || $want === 'lazada') && !empty($cfg['lazada']['access_token']))
            $rows = array_merge($rows, mp_pull_lazada($cfg['lazada'], $from, $to, $errors));
        foreach (['shopee', 'lazada'] as $pl) if (!empty($cfg[$pl]['access_token'])) mp_cfg_save_tokens($cid, $pl, $cfg[$pl]);   // keep refreshed tokens
        if ($rows) {
            /* never undo local work: keep picked=true (stock already deducted) and a tracking number set by "ready to ship" */
            $keep = [];
            foreach ((array)sb_req('GET', '/mpOrders?select=rk,pk:data->>picked,tr:data->>tracking&company=eq.' . rawurlencode($cid)
                     . '&or=(data->>picked.eq.true,data->>tracking.neq.)') as $x)
                if (isset($x['rk'])) $keep[(string)$x['rk']] = $x;
            foreach ($rows as &$r) {
                $k = $keep[$r['id']] ?? null;
                if ($k && (string)($k['pk'] ?? '') === 'true') $r['picked'] = true;
                if ($k && (string)($k['tr'] ?? '') !== '' && (string)($r['tracking'] ?? '') === '') $r['tracking'] = (string)$k['tr'];
                if (!empty($cfg[$r['platform']]['sandbox'])) $r['sandbox'] = true;      // from a sandbox / test shop
            }
            unset($r);
            $up = [];
            foreach ($rows as $r) $up[] = ['company' => $cid, 'rk' => $r['id'], 'data' => $r];
            sb_upsert('mpOrders', $up, 'company,rk');
        }
        ok(['ok' => true, 'count' => count($rows), 'orders' => $rows, 'errors' => $errors]);
    }

    if ($action === 'mp_ship' || $action === 'mp_label') {
        $id = (string)($body['id'] ?? '');
        $pos = strpos($id, ':');
        if ($pos === false) fail(400, 'bad order id');
        $platform = substr($id, 0, $pos); $no = substr($id, $pos + 1);
        if ($platform !== 'shopee' && $platform !== 'lazada') fail(400, 'bad order id');
        $c = $cfg[$platform] ?? [];
        if (empty($c['access_token'])) fail(400, $platform . ' is not linked');
        $e = $platform === 'shopee' ? mp_shopee_fresh($c) : mp_lazada_fresh($c);
        if ($e !== '') fail(400, $e);
        @set_time_limit(120);

        $res = $platform === 'shopee'
            ? ($action === 'mp_ship' ? mp_shopee_ship($c, $no) : mp_shopee_label($c, $no))
            : ($action === 'mp_ship' ? mp_lazada_ship($c, $no) : mp_lazada_label($c, $no));
        mp_cfg_save_tokens($cid, $platform, $c);                      // keep any refreshed token
        if (!empty($res['error'])) fail(400, $platform . ': ' . $res['error']);

        /* remember the tracking number on the stored order */
        if (!empty($res['tracking'])) {
            $rk = $platform . ':' . $no;
            $cur = sb_req('GET', '/mpOrders?select=data&company=eq.' . rawurlencode($cid) . '&rk=eq.' . rawurlencode($rk));
            if (isset($cur[0]['data']) && is_array($cur[0]['data'])) {
                $d = $cur[0]['data']; $d['tracking'] = $res['tracking'];
                if ($action === 'mp_ship') $d['status'] = 'READY_TO_SHIP';
                sb_upsert('mpOrders', [['company' => $cid, 'rk' => $rk, 'data' => $d]], 'company,rk');
            }
        }
        ok(array_merge(['ok' => true], $res));
    }

    fail(400, 'unknown marketplace action');
}

} /* MP_INCLUDED */
