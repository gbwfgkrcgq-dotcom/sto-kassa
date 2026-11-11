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
if ($method === 'GET') out(200, array('ok' => true, 'app' => 'sto-kassa', 'ready' => is_file(AUTH_FILE)));
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
if (!$changed && $rev !== '' && is_string($known) && $known === $rev) out(200, array('ok' => true, 'rev' => $rev, 'same' => true));
out(200, array('ok' => true, 'rev' => $rev, 'orders' => $live['orders'], 'expenses' => $live['expenses']));
