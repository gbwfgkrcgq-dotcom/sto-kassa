<?php
// СТО Касса: хранит заказы и расходы на вашем хостинге и отдаёт их приложению.
// Первый пароль, с которым подключится приложение, становится паролем кассы.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

define('DATA_DIR', __DIR__ . '/data');
define('GUARD', "<?php http_response_code(403); exit; ?>\n");
define('AUTH_FILE', DATA_DIR . '/auth.php');
define('STORE_FILE', DATA_DIR . '/store.php');

function out($code, $obj) {
    http_response_code($code);
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_guarded($file) {
    if (!is_file($file)) return null;
    $s = file_get_contents($file);
    if ($s === false) return null;
    if (strpos($s, GUARD) === 0) $s = substr($s, strlen(GUARD));
    $j = json_decode($s, true);
    return is_array($j) ? $j : null;
}

function write_guarded($file, $data) {
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, GUARD . json_encode($data, JSON_UNESCAPED_UNICODE)) === false) return false;
    return rename($tmp, $file);
}

function num($v) {
    return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) && $v > 0 ? $v + 0 : 0;
}

function field($arr, $key, $default) {
    return is_array($arr) && array_key_exists($key, $arr) ? $arr[$key] : $default;
}

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
if (is_dir(DATA_DIR) && !is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// Срок оплаты: дата из license.php. После неё данные только читаются.
date_default_timezone_set('Europe/Moscow');
$paid = '';
if (is_file(__DIR__ . '/license.php')) {
    $lic = include __DIR__ . '/license.php';
    if (is_string($lic) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lic)) $paid = $lic;
}
$expired = $paid !== '' && date('Y-m-d') > $paid;

// Фото чеков: api.php?photo=<номер заказа>. Пароль кассы передаётся в заголовке X-Kassa-Key.
if (isset($_GET['photo'])) {
    $pid = $_GET['photo'];
    if (!is_string($pid) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pid)) out(400, array('ok' => false, 'error' => 'bad'));
    $key = isset($_SERVER['HTTP_X_KASSA_KEY']) ? rawurldecode($_SERVER['HTTP_X_KASSA_KEY']) : '';
    $auth = read_guarded(AUTH_FILE);
    $hash = field($auth, 'hash', '');
    if (!is_string($hash) || $hash === '' || !is_string($key) || !password_verify($key, $hash)) {
        sleep(1);
        out(401, array('ok' => false, 'error' => 'auth'));
    }
    $dir = DATA_DIR . '/photos';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . $pid . '.jpg';
    if ($method === 'GET') {
        if (!is_file($file)) out(404, array('ok' => false, 'error' => 'none'));
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    if ($method !== 'POST') out(405, array('ok' => false, 'error' => 'method'));
    if ($expired) out(403, array('ok' => false, 'error' => 'locked'));
    if (isset($_GET['del'])) {
        if (is_file($file)) @unlink($file);
        out(200, array('ok' => true));
    }
    $img = file_get_contents('php://input');
    if ($img === false || strlen($img) < 4 || strlen($img) > 4 * 1024 * 1024) out(413, array('ok' => false, 'error' => 'size'));
    if (substr($img, 0, 2) !== "\xFF\xD8") out(400, array('ok' => false, 'error' => 'type'));
    if (!is_dir($dir) || file_put_contents($file, $img) === false) out(500, array('ok' => false, 'error' => 'storage'));
    out(200, array('ok' => true));
}

if ($method === 'GET') out(200, array('ok' => true, 'app' => 'sto-kassa', 'ready' => is_file(AUTH_FILE), 'paidUntil' => $paid));
if ($method !== 'POST') out(405, array('ok' => false, 'error' => 'method'));

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 8 * 1024 * 1024) out(413, array('ok' => false, 'error' => 'size'));
$req = json_decode($raw, true);
if (!is_array($req) || field($req, 'app', '') !== 'sto-kassa') out(400, array('ok' => false, 'error' => 'bad'));
$pw = field($req, 'password', '');
if (!is_string($pw) || strlen($pw) < 6 || strlen($pw) > 200) out(400, array('ok' => false, 'error' => 'short'));
if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) out(500, array('ok' => false, 'error' => 'storage'));

$lock = fopen(DATA_DIR . '/lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) out(500, array('ok' => false, 'error' => 'storage'));

if (!is_file(AUTH_FILE)) {
    if (!write_guarded(AUTH_FILE, array('hash' => password_hash($pw, PASSWORD_DEFAULT)))) out(500, array('ok' => false, 'error' => 'storage'));
} else {
    $auth = read_guarded(AUTH_FILE);
    $hash = field($auth, 'hash', '');
    if (!is_string($hash) || $hash === '' || !password_verify($pw, $hash)) {
        sleep(1);
        out(401, array('ok' => false, 'error' => 'auth'));
    }
}

$store = read_guarded(STORE_FILE);
if ($store === null) {
    if (is_file(STORE_FILE)) out(500, array('ok' => false, 'error' => 'corrupt'));
    $store = array('orders' => array(), 'expenses' => array());
}

$changed = false;
$live = array();
$deleted = field($req, 'deleted', array());
if (!is_array($deleted)) $deleted = array();
if ($expired) {
    // Оплата истекла: ничего не принимаем, только отдаём то, что уже есть.
    $deleted = array();
    $req['orders'] = array();
    $req['expenses'] = array();
}

foreach (array('orders', 'expenses') as $kind) {
    $map = array();
    $have = field($store, $kind, array());
    if (!is_array($have)) $have = array();
    foreach ($have as $r) {
        if (is_array($r) && isset($r['id']) && is_string($r['id'])) $map[$r['id']] = $r;
    }

    $incoming = field($req, $kind, array());
    if (!is_array($incoming)) $incoming = array();
    foreach ($incoming as $r) {
        if (!is_array($r) || !isset($r['id']) || !is_string($r['id']) || $r['id'] === '' || strlen($r['id']) > 64) continue;
        $id = $r['id'];
        if (!isset($map[$id]) || num(field($r, 'updatedAt', 0)) >= num(field($map[$id], 'updatedAt', 0))) {
            $map[$id] = $r;
            $changed = true;
        }
    }

    foreach ($deleted as $d) {
        if (!is_array($d) || field($d, 'kind', '') !== $kind || !isset($d['id']) || !is_string($d['id'])) continue;
        $id = $d['id'];
        if (isset($map[$id]) && empty($map[$id]['deleted']) && num(field($d, 'updatedAt', 0)) >= num(field($map[$id], 'updatedAt', 0))) {
            $map[$id] = array('id' => $id, 'deleted' => true, 'updatedAt' => num(field($d, 'updatedAt', 0)));
            $changed = true;
        }
    }

    $store[$kind] = array_values($map);
    $alive = array();
    foreach ($store[$kind] as $r) {
        if (empty($r['deleted'])) $alive[] = $r;
    }
    $live[$kind] = $alive;
}

if ($changed) {
    // Копия на каждый день, хранится около месяца.
    $day = DATA_DIR . '/backup-' . date('Y-m-d') . '.php';
    if (is_file(STORE_FILE) && !is_file($day)) @copy(STORE_FILE, $day);
    $old = glob(DATA_DIR . '/backup-*.php');
    if (is_array($old)) {
        foreach ($old as $f) {
            if (@filemtime($f) < time() - 31 * 86400) @unlink($f);
        }
    }
    if (!write_guarded(STORE_FILE, $store)) out(500, array('ok' => false, 'error' => 'storage'));
}

// Отпечаток данных: если у приложения такой же и оно ничего не прислало, отвечаем коротко.
$rev = is_file(STORE_FILE) ? md5_file(STORE_FILE) : 'empty';
if (!is_string($rev)) $rev = '';
flock($lock, LOCK_UN);
$known = field($req, 'rev', '');
if (!$changed && $rev !== '' && is_string($known) && $known === $rev) out(200, array('ok' => true, 'rev' => $rev, 'same' => true, 'paidUntil' => $paid, 'locked' => $expired));
out(200, array('ok' => true, 'rev' => $rev, 'paidUntil' => $paid, 'locked' => $expired, 'orders' => $live['orders'], 'expenses' => $live['expenses']));
