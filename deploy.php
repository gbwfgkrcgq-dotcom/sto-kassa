<?php
// СТО Касса: обновляет файлы приложения на хостинге из GitHub.
// Запрос: POST deploy.php с заголовком X-Deploy-Key и полем ref (номер коммита, 40 символов).
// Ключ хранится только в виде хеша в deploy-key.php, который загружается на хостинг вручную и не лежит в GitHub.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const REPO = 'gbwfgkrcgq-dotcom/sto-kassa';
// Только эти файлы можно обновить. Каждый проверяется перед записью.
const FILES = [
    'index.html'    => ['max' => 2000000, 'must' => '<title>СТО Касса</title>'],
    'sw.js'         => ['max' => 50000,   'must' => 'CACHE'],
    'api.php'       => ['max' => 200000,  'must' => '<?php'],
    'license.php'   => ['max' => 5000,    'must' => '<?php'],
    'manifest.json' => ['max' => 20000,   'must' => '{'],
    'icon.png'      => ['max' => 2000000, 'must' => "\x89PNG"],
];

function out($code, $obj) {
    http_response_code($code);
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

function fetch($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'sto-kassa-deploy']);
        $body = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $code === 200 && is_string($body) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: sto-kassa-deploy\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Нужен POST']);

$keyFile = __DIR__ . '/deploy-key.php';
$hash = is_file($keyFile) ? include $keyFile : '';
if (!is_string($hash) || strlen($hash) !== 64) out(503, ['error' => 'Ключ обновления не установлен']);
$key = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '';
if (!is_string($key) || $key === '' || !hash_equals($hash, hash('sha256', $key))) {
    usleep(500000);
    out(403, ['error' => 'Неверный ключ']);
}

$ref = $_POST['ref'] ?? '';
if (!is_string($ref) || !preg_match('/^[0-9a-f]{40}$/', $ref)) out(400, ['error' => 'Нужен номер коммита']);

// Сначала скачиваем и проверяем всё, потом пишем: либо обновится всё, либо ничего.
$got = [];
foreach (FILES as $name => $rule) {
    $body = fetch('https://raw.githubusercontent.com/' . REPO . '/' . $ref . '/' . $name);
    if ($body === null) out(502, ['error' => 'Не удалось скачать ' . $name]);
    if (strlen($body) === 0 || strlen($body) > $rule['max'] || strpos($body, $rule['must']) === false)
        out(422, ['error' => 'Файл ' . $name . ' не прошёл проверку']);
    $got[$name] = $body;
}

$done = [];
foreach ($got as $name => $body) {
    $path = __DIR__ . '/' . $name;
    if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $body)) continue;
    $tmp = $path . '.deploy-tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        out(500, ['error' => 'Не удалось записать ' . $name, 'updated' => $done]);
    }
    $done[] = $name;
}
if (function_exists('opcache_reset')) @opcache_reset();
out(200, ['ok' => true, 'ref' => $ref, 'updated' => $done]);
