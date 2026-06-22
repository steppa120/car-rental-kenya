<?php
/**
 * Shared MySQL connection helper — FleetKE
 *
 * Supports plain hosts, host:port values, DB_PORT env var,
 * and full Aiven-style MySQL Service URIs
 * e.g. mysql://user:pass@host:25060/db?ssl-mode=REQUIRED
 *
 * SSL is auto-enabled for aivencloud.com hosts or when DB_SSL=required.
 */

function db_config(): array {
    $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
    $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
    $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
    $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'car_rental_kenya');
    $port = getenv('DB_PORT') ?: null;

    // Parse full Service URI (mysql:// or mysqls://)
    if (preg_match('#^mysqls?://#i', $host)) {
        $parts = parse_url($host);
        if ($parts !== false) {
            $host = $parts['host'] ?? $host;
            $port = $parts['port'] ?? $port;
            $user = isset($parts['user']) ? urldecode($parts['user']) : $user;
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : $pass;
            $name = isset($parts['path']) ? ltrim($parts['path'], '/') : $name;
            // strip query string from DB name (e.g. defaultdb?ssl-mode=REQUIRED)
            if (($q = strpos($name, '?')) !== false) {
                $name = substr($name, 0, $q);
            }
        }
    }

    // Support host:port shorthand
    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        [$hostOnly, $hostPort] = explode(':', $host, 2);
        if ($hostOnly !== '' && ctype_digit($hostPort)) {
            $host = $hostOnly;
            $port = $hostPort;
        }
    }

    $sslSetting = strtolower((string)(getenv('DB_SSL') ?: ''));
    $ssl = in_array($sslSetting, ['1', 'true', 'yes', 'required', 'require'], true)
        || stripos($host, 'aivencloud.com') !== false
        || stripos($host, 'tidbcloud.com') !== false
        || stripos($host, 'planetscale.com') !== false;

    return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'name' => $name,
        'port' => $port ? (int)$port : 3306,
        'ssl'  => $ssl,
    ];
}

function db_connect(): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF);

    $config = db_config();
    $conn   = mysqli_init();
    if (!$conn) {
        throw new RuntimeException('Could not initialize MySQL connection.');
    }

    // 5-second timeout — prevents Apache worker exhaustion when DB is unreachable
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

    $flags = 0;
    if ($config['ssl']) {
        $conn->ssl_set(null, null, null, null, null);
        $flags |= MYSQLI_CLIENT_SSL;
    }

    $ok = @$conn->real_connect(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['name'],
        $config['port'],
        null,
        $flags
    );

    if (!$ok) {
        throw new RuntimeException($conn->connect_error ?: 'Unknown MySQL connection error.');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
