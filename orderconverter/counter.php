<?php
/*
  Amazon Orders Converter v2.3 - account and number service
  Copyright (c) 2026 NS Nexus Limited. All rights reserved.

  Per Gardners Account ID it keeps ONLY:
    - pinHash       : bcrypt hash of the PIN (####.##); the PIN itself is never stored
    - pinChanged    : when the PIN was created or last reset
    - nextSeq       : next HEADER sequence (unique for every order ever sent, never restarts)
    - nextRef       : next DETAIL unique reference (000000001-999999999, then restarts)
    - orders        : numbers issued to each Amazon order ID (so re-converting reuses them)
    - sessions      : hashed log-in tokens with expiry
    - failed/lockUntil, resets : counters that slow down PIN guessing and repeated resets
  No company names, people, e-mails, addresses or other customer data are stored.

  Data: data/counters.php - JSON after a PHP guard line, so opening it in a browser shows nothing;
  data/.htaccess blocks the folder as well.
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_REF = 999999999;
const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/counters.php';        // JSON behind a PHP guard line, so it can never be read from the web
const OLD_FILE = DATA_DIR . '/counters.json';        // v1.4 file, moved into DATA_FILE on first use
const GUARD = "<?php http_response_code(404); exit; ?>\n";
const SESSION_HOURS = 12;          // a log-in lasts 12 hours from the last use
const MAX_FAILED = 5;              // wrong PINs in a row before a lock
const LOCK_MINUTES = 15;           // lock length after too many wrong PINs
const MAX_RESETS_PER_DAY = 3;      // PIN resets allowed per account in 24 hours
const MAX_SESSIONS = 10;

function fail($code, $msg, $extra = []) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra);
  exit;
}

function clean_account($a) {
  $a = strtoupper(trim((string)$a));
  if (!preg_match('/^[A-Z0-9]{1,10}$/', $a)) fail(400, 'Enter a valid Gardners Account ID (letters and numbers, up to 10)');
  return $a;
}

function clean_pin($p) {
  $p = trim((string)$p);
  if (!preg_match('/^\d{4}\.\d{2}$/', $p)) fail(400, 'The PIN must be 4 digits, a dot and 2 digits, e.g. 1234.56');
  return $p;
}

function clean_order_id($id) {
  $id = trim((string)$id);
  if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $id)) fail(400, 'Invalid order ID');
  return $id;
}

function next_ref($n) { return $n >= MAX_REF ? 1 : $n + 1; }
function now() { return time(); }
function iso($t) { return gmdate('c', $t); }

/* Open the data file with an exclusive lock, run $fn on the data, save, unlock. */
function with_data($fn) {
  if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true)) fail(500, 'Cannot create data folder');
  $ht = DATA_DIR . '/.htaccess';
  if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
  $fh = @fopen(DATA_FILE, 'c+');
  if (!$fh) fail(500, 'Cannot open counter file');
  if (!flock($fh, LOCK_EX)) { fclose($fh); fail(500, 'Cannot lock counter file'); }
  $raw = stream_get_contents($fh);
  if (strpos($raw, GUARD) === 0) $raw = substr($raw, strlen(GUARD));
  if ($raw === '' && file_exists(OLD_FILE)) {          // move the v1.4 counters across, then remove the old file
    $raw = (string)file_get_contents(OLD_FILE);
    @file_put_contents(DATA_DIR . '/counters-v1-backup.php', GUARD . $raw);
    @unlink(OLD_FILE);
  }
  $data = $raw ? json_decode($raw, true) : [];
  if (!is_array($data)) { flock($fh, LOCK_UN); fclose($fh); fail(500, 'Counter file is damaged - restore it from backup'); }
  $result = null; $error = null;
  try { $result = $fn($data); } catch (ServiceError $e) { $error = $e; }
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  ftruncate($fh, 0); rewind($fh); fwrite($fh, GUARD . $json); fflush($fh);
  flock($fh, LOCK_UN); fclose($fh);
  if ($error) fail($error->status, $error->getMessage(), $error->extra);
  return $result;
}

/* Thrown inside with_data so changes (e.g. a failed-PIN count) are still saved */
class ServiceError extends Exception {
  public $status; public $extra;
  function __construct($status, $msg, $extra = []) { parent::__construct($msg); $this->status = $status; $this->extra = $extra; }
}

function new_bucket() { return ['nextSeq' => 1, 'nextRef' => 1, 'orders' => [], 'sessions' => []]; }

function &bucket(&$data, $acct) {
  if (!isset($data[$acct]) || !is_array($data[$acct])) $data[$acct] = new_bucket();
  foreach (new_bucket() as $k => $v) if (!isset($data[$acct][$k])) $data[$acct][$k] = $v;
  return $data[$acct];
}

function new_session(&$b) {
  $token = bin2hex(random_bytes(32));
  $t = now();
  $b['sessions'] = array_values(array_filter($b['sessions'], function ($s) use ($t) { return ($s['exp'] ?? 0) > $t; }));
  $b['sessions'][] = ['h' => hash('sha256', $token), 'exp' => $t + SESSION_HOURS * 3600];
  if (count($b['sessions']) > MAX_SESSIONS) $b['sessions'] = array_slice($b['sessions'], -MAX_SESSIONS);
  return $token;
}

/* Find the account a token belongs to; refresh its expiry. */
function &authed(&$data, $token) {
  $h = hash('sha256', (string)$token);
  $t = now();
  foreach ($data as $acct => &$b) {
    if (!is_array($b) || empty($b['sessions'])) continue;
    foreach ($b['sessions'] as &$s) {
      if (hash_equals($s['h'], $h) && ($s['exp'] ?? 0) > $t) {
        $s['exp'] = $t + SESSION_HOURS * 3600;
        $b['_acct'] = $acct;
        return $b;
      }
    }
    unset($s);
  }
  unset($b);
  throw new ServiceError(401, 'Your session has ended. Please log in again.', ['loggedOut' => true]);
}

function public_info($b, $acct) {
  return ['account' => $acct, 'nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef'],
          'pinChanged' => $b['pinChanged'] ?? null];
}

/* ---------- request ---------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail(405, 'Use POST');
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'Invalid request');
$action = $in['action'] ?? '';
$token = $_SERVER['HTTP_X_SESSION'] ?? ($in['token'] ?? '');

switch ($action) {

  /* Does this Gardners Account ID exist (with a PIN)? */
  case 'exists': {
    $acct = clean_account($in['account'] ?? '');
    $out = with_data(function (&$data) use ($acct) {
      $has = isset($data[$acct]) && !empty($data[$acct]['pinHash']);
      return ['exists' => $has];
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  /* Create an account (or give an older account without a PIN its first PIN) */
  case 'create': {
    $acct = clean_account($in['account'] ?? '');
    $pin = clean_pin($in['pin'] ?? '');
    if (isset($in['pinConfirm']) && clean_pin($in['pinConfirm']) !== $pin) fail(400, 'The two PINs do not match');
    $out = with_data(function (&$data) use ($acct, $pin) {
      if (isset($data[$acct]) && !empty($data[$acct]['pinHash'])) throw new ServiceError(409, 'This customer already exists. Please log in.');
      $b = &bucket($data, $acct);
      $b['pinHash'] = password_hash($pin, PASSWORD_DEFAULT);
      $b['pinChanged'] = iso(now());
      $b['created'] = $b['created'] ?? iso(now());
      $b['failed'] = 0; unset($b['lockUntil']);
      return ['token' => new_session($b)] + public_info($b, $acct);
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  /* Log in with Gardners Account ID + PIN */
  case 'login': {
    $acct = clean_account($in['account'] ?? '');
    $pin = clean_pin($in['pin'] ?? '');
    $out = with_data(function (&$data) use ($acct, $pin) {
      if (!isset($data[$acct]) || empty($data[$acct]['pinHash'])) throw new ServiceError(404, 'The customer is not existing.', ['notFound' => true]);
      $b = &bucket($data, $acct);
      $t = now();
      if (!empty($b['lockUntil']) && $b['lockUntil'] > $t) {
        $mins = (int)ceil(($b['lockUntil'] - $t) / 60);
        throw new ServiceError(429, "Too many wrong PINs. Try again in $mins minute" . ($mins === 1 ? '' : 's') . '.');
      }
      if (!password_verify($pin, $b['pinHash'])) {
        $b['failed'] = (int)($b['failed'] ?? 0) + 1;
        if ($b['failed'] >= MAX_FAILED) { $b['lockUntil'] = $t + LOCK_MINUTES * 60; $b['failed'] = 0;
          throw new ServiceError(429, 'Too many wrong PINs. Log-in is locked for ' . LOCK_MINUTES . ' minutes.'); }
        $left = MAX_FAILED - $b['failed'];
        throw new ServiceError(401, "Wrong Gardners Account ID or PIN. $left attempt" . ($left === 1 ? '' : 's') . ' left before a ' . LOCK_MINUTES . '-minute lock.');
      }
      $b['failed'] = 0; unset($b['lockUntil']);
      if (password_needs_rehash($b['pinHash'], PASSWORD_DEFAULT)) $b['pinHash'] = password_hash($pin, PASSWORD_DEFAULT);
      return ['token' => new_session($b)] + public_info($b, $acct);
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  /* Reset the PIN: Account ID twice, new PIN twice */
  case 'resetpin': {
    $acct = clean_account($in['account'] ?? '');
    if (clean_account($in['accountConfirm'] ?? '') !== $acct) fail(400, 'The two Gardners Account IDs do not match');
    $pin = clean_pin($in['pin'] ?? '');
    if (clean_pin($in['pinConfirm'] ?? '') !== $pin) fail(400, 'The two PINs do not match');
    $out = with_data(function (&$data) use ($acct, $pin) {
      if (!isset($data[$acct]) || empty($data[$acct]['pinHash'])) throw new ServiceError(404, 'The customer is not existing.', ['notFound' => true]);
      $b = &bucket($data, $acct);
      $t = now();
      $b['resets'] = array_values(array_filter((array)($b['resets'] ?? []), function ($r) use ($t) { return $r > $t - 86400; }));
      if (count($b['resets']) >= MAX_RESETS_PER_DAY) throw new ServiceError(429, 'The PIN for this customer has been reset too many times today. Try again tomorrow.');
      $b['resets'][] = $t;
      $b['pinHash'] = password_hash($pin, PASSWORD_DEFAULT);
      $b['pinChanged'] = iso($t);
      $b['failed'] = 0; unset($b['lockUntil']);
      $b['sessions'] = [];                      // log out everywhere
      return ['token' => new_session($b)] + public_info($b, $acct);
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  case 'logout': {
    with_data(function (&$data) use ($token) {
      $h = hash('sha256', (string)$token);
      foreach ($data as &$b) if (is_array($b) && !empty($b['sessions']))
        $b['sessions'] = array_values(array_filter($b['sessions'], function ($s) use ($h) { return !hash_equals($s['h'], $h); }));
      unset($b);
      return null;
    });
    echo json_encode(['ok' => true]);
    break;
  }

  /* ----- everything below needs a log-in; the account comes from the session ----- */

  case 'status': {
    $out = with_data(function (&$data) use ($token) {
      $b = &authed($data, $token); $acct = $b['_acct']; unset($b['_acct']);
      return public_info($b, $acct);
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  case 'lookup': {
    $ids = array_map('clean_order_id', array_slice((array)($in['ids'] ?? []), 0, 500));
    $out = with_data(function (&$data) use ($token, $ids) {
      $b = &authed($data, $token); unset($b['_acct']);
      $found = [];
      foreach ($ids as $id) if (isset($b['orders'][$id])) {
        $o = $b['orders'][$id];
        $found[$id] = ['seq' => $o['seq'], 'refs' => $o['refs'], 'issued' => $o['issued']];
      }
      return $found;
    });
    echo json_encode(['ok' => true, 'orders' => (object)$out]);
    break;
  }

  case 'reserve': {
    $list = array_slice((array)($in['orders'] ?? []), 0, 500);
    $renew = array_flip(array_map('clean_order_id', (array)($in['renew'] ?? [])));
    $req = [];
    foreach ($list as $o) {
      $id = clean_order_id($o['id'] ?? '');
      $lines = (int)($o['lines'] ?? 0);
      if ($lines < 1 || $lines > 999) fail(400, 'Invalid line count for ' . $id);
      $req[$id] = $lines;
    }
    $out = with_data(function (&$data) use ($token, $req, $renew) {
      $b = &authed($data, $token); unset($b['_acct']);
      $res = [];
      foreach ($req as $id => $lines) {
        $prev = $b['orders'][$id] ?? null;
        if ($prev && !isset($renew[$id]) && count($prev['refs']) === $lines) {
          $res[$id] = ['seq' => $prev['seq'], 'refs' => $prev['refs'], 'issued' => $prev['issued'], 'reused' => true];
          continue;
        }
        $seq = (int)$b['nextSeq'];
        $refs = []; $r = (int)$b['nextRef'];
        for ($i = 0; $i < $lines; $i++) { $refs[] = $r; $r = next_ref($r); }
        $b['nextSeq'] = $seq + 1;
        $b['nextRef'] = $r;
        $rec = ['seq' => $seq, 'refs' => $refs, 'issued' => iso(now())];
        if ($prev) $rec['previous'] = $prev;
        $b['orders'][$id] = $rec;
        $res[$id] = ['seq' => $seq, 'refs' => $refs, 'issued' => $rec['issued'], 'reused' => false];
      }
      return ['orders' => $res, 'nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef']];
    });
    echo json_encode(['ok' => true, 'orders' => (object)$out['orders'], 'nextSeq' => $out['nextSeq'], 'nextRef' => $out['nextRef']]);
    break;
  }

  /* Set the next numbers by hand. The sequence can only go up. */
  case 'set': {
    $out = with_data(function (&$data) use ($token, $in) {
      $b = &authed($data, $token); unset($b['_acct']);
      $msg = null;
      if (isset($in['nextSeq']) && $in['nextSeq'] !== '') {
        $s = (int)preg_replace('/\D/', '', (string)$in['nextSeq']);
        if ($s > (int)$b['nextSeq']) $b['nextSeq'] = $s;
        elseif ($s < (int)$b['nextSeq']) $msg = 'The sequence number can only be increased, so it was kept at ' . $b['nextSeq'];
      }
      if (isset($in['nextRef']) && $in['nextRef'] !== '') {
        $r = (int)preg_replace('/\D/', '', (string)$in['nextRef']);
        if ($r >= 1 && $r <= MAX_REF) $b['nextRef'] = $r;
      }
      return ['nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef'], 'message' => $msg];
    });
    echo json_encode(['ok' => true] + $out);
    break;
  }

  default:
    fail(400, 'Unknown action');
}
