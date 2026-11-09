<?php
/*
  Amazon Orders Converter - shared number service
  Copyright (c) 2026 NS Nexus Limited. All rights reserved.

  Keeps, per Gardners account:
    - nextSeq : next HEADER sequence (unique for every order ever sent, never restarts)
    - nextRef : next DETAIL unique reference (9 digits, 000000001-999999999, then restarts)
    - orders  : numbers already issued to each Amazon order, so re-converting an order
                reuses them (Gardners' duplicate check then blocks a double order)

  Data is stored in data/counters.json (blocked from the web by data/.htaccess).
  Protect this folder with a password in hPanel (Password Protect Directories).
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_REF = 999999999;
const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/counters.json';

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

function clean_account($a) {
  $a = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$a));
  if ($a === '' || strlen($a) > 10) fail(400, 'Invalid Gardners Account ID');
  return $a;
}

function clean_order_id($id) {
  $id = trim((string)$id);
  if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $id)) fail(400, 'Invalid order ID');
  return $id;
}

function next_ref($n) { return $n >= MAX_REF ? 1 : $n + 1; }

/* Open the data file with an exclusive lock, run $fn on the decoded data, save, unlock. */
function with_data($fn) {
  if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true)) fail(500, 'Cannot create data folder');
  $ht = DATA_DIR . '/.htaccess';
  if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
  $fh = @fopen(DATA_FILE, 'c+');
  if (!$fh) fail(500, 'Cannot open counter file');
  if (!flock($fh, LOCK_EX)) { fclose($fh); fail(500, 'Cannot lock counter file'); }
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : [];
  if (!is_array($data)) { flock($fh, LOCK_UN); fclose($fh); fail(500, 'Counter file is damaged - restore it from backup'); }
  $result = $fn($data);
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  ftruncate($fh, 0); rewind($fh); fwrite($fh, $json); fflush($fh);
  flock($fh, LOCK_UN); fclose($fh);
  return $result;
}

function &account_bucket(&$data, $acct) {
  if (!isset($data[$acct]) || !is_array($data[$acct])) $data[$acct] = ['nextSeq' => 1, 'nextRef' => 1, 'orders' => []];
  if (!isset($data[$acct]['orders']) || !is_array($data[$acct]['orders'])) $data[$acct]['orders'] = [];
  return $data[$acct];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) fail(400, 'Invalid request');
} else {
  $in = $_GET;
}
$action = $in['action'] ?? 'status';
$acct = clean_account($in['account'] ?? '');

switch ($action) {

  /* Current next numbers */
  case 'status':
    $out = with_data(function (&$data) use ($acct) {
      $b = &account_bucket($data, $acct);
      return ['nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef'], 'orderCount' => count($b['orders'])];
    });
    echo json_encode(['ok' => true] + $out);
    break;

  /* Numbers already issued to these orders (if any) */
  case 'lookup':
    $ids = array_map('clean_order_id', array_slice((array)($in['ids'] ?? []), 0, 500));
    $out = with_data(function (&$data) use ($acct, $ids) {
      $b = &account_bucket($data, $acct);
      $found = [];
      foreach ($ids as $id) if (isset($b['orders'][$id])) $found[$id] = $b['orders'][$id];
      return $found;
    });
    echo json_encode(['ok' => true, 'orders' => (object)$out]);
    break;

  /* Issue numbers to orders. Orders that already have numbers keep them,
     unless listed in "renew" (deliberate resend with new numbers). */
  case 'reserve':
    if ($method !== 'POST') fail(405, 'Use POST');
    $list = array_slice((array)($in['orders'] ?? []), 0, 500);
    $renew = array_flip(array_map('clean_order_id', (array)($in['renew'] ?? [])));
    $req = [];
    foreach ($list as $o) {
      $id = clean_order_id($o['id'] ?? '');
      $lines = (int)($o['lines'] ?? 0);
      if ($lines < 1 || $lines > 999) fail(400, 'Invalid line count for ' . $id);
      $req[$id] = $lines;
    }
    $out = with_data(function (&$data) use ($acct, $req, $renew) {
      $b = &account_bucket($data, $acct);
      $res = [];
      foreach ($req as $id => $lines) {
        $prev = $b['orders'][$id] ?? null;
        if ($prev && !isset($renew[$id]) && count($prev['refs']) === $lines) {
          $res[$id] = $prev + ['reused' => true];
          continue;
        }
        $seq = (int)$b['nextSeq'];
        $refs = [];
        $r = (int)$b['nextRef'];
        for ($i = 0; $i < $lines; $i++) { $refs[] = $r; $r = next_ref($r); }
        $b['nextSeq'] = $seq + 1;
        $b['nextRef'] = $r;
        $rec = ['seq' => $seq, 'refs' => $refs, 'issued' => gmdate('c')];
        if ($prev) $rec['previous'] = $prev;   // keep history of the replaced numbers
        $b['orders'][$id] = $rec;
        $res[$id] = $rec + ['reused' => false];
      }
      return ['orders' => $res, 'nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef']];
    });
    echo json_encode(['ok' => true, 'orders' => (object)$out['orders'], 'nextSeq' => $out['nextSeq'], 'nextRef' => $out['nextRef']]);
    break;

  /* Set the next numbers by hand. The sequence can only go up, never down. */
  case 'set':
    if ($method !== 'POST') fail(405, 'Use POST');
    $out = with_data(function (&$data) use ($acct, $in) {
      $b = &account_bucket($data, $acct);
      $msg = null;
      if (isset($in['nextSeq'])) {
        $s = (int)preg_replace('/\D/', '', (string)$in['nextSeq']);
        if ($s > (int)$b['nextSeq']) $b['nextSeq'] = $s;
        elseif ($s < (int)$b['nextSeq']) $msg = 'The sequence number can only be increased, so it was kept at ' . $b['nextSeq'];
      }
      if (isset($in['nextRef'])) {
        $r = (int)preg_replace('/\D/', '', (string)$in['nextRef']);
        if ($r >= 1 && $r <= MAX_REF) $b['nextRef'] = $r;
      }
      return ['nextSeq' => $b['nextSeq'], 'nextRef' => $b['nextRef'], 'message' => $msg];
    });
    echo json_encode(['ok' => true] + $out);
    break;

  default:
    fail(400, 'Unknown action');
}
