<?php
// php -S router: serves the fake cPanel account described in $STATE_FILE.
$st = json_decode(file_get_contents(getenv('SHFWD_STATE')), true);
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']));
$base = strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
$uri = $_SERVER['REQUEST_URI'];
$path = explode('?', $uri, 2);
$query = isset($path[1]) ? $path[1] : '';
$path = $path[0];

if (isset($st['addon'][$base])) {
    $dir = $st['home'] . '/' . $st['addon'][$base]['dir'];
    if (is_file($dir . '/index.php')) {
        $_SERVER['QUERY_STRING'] = $query;
        chdir($dir);
        include $dir . '/index.php';
        return true;
    }
    http_response_code(200);
    echo 'old addon site';
    return true;
}
if (isset($st['parked'][$base])) {
    // cPanel-style redirects stored in the main docroot .htaccess
    foreach ($st['redirects'] as $r) {
        if ($r['domain'] !== $base && $r['domain'] !== 'www.' . $base) {
            continue;
        }
        $hostOk = $r['domain'] === $host || (!empty($r['matchwww']) && ($host === $base || $host === 'www.' . $base));
        if (!$hostOk) {
            continue;
        }
        $src = rtrim('/' . ltrim($r['sourceurl'], '/'), '/');
        $rest = null;
        if (rtrim($path, '/') === $src) {
            $rest = '';
        } elseif (!empty($r['wildcard']) && strpos($path, $src . '/') === 0) {
            $rest = substr($path, strlen($src) + 1);
        }
        if ($rest === null) {
            continue;
        }
        $loc = $rest !== '' ? rtrim($r['destination'], '/') . '/' . $rest : $r['destination'];
        if ($query !== '' && strpos($r['destination'], '?') === false) {
            $loc .= '?' . $query;
        }
        header('Location: ' . $loc, true, $r['type'] === 'permanent' ? 301 : 302);
        return true;
    }
    echo 'main site';
    return true;
}
http_response_code(404);
echo 'unknown host';
return true;
