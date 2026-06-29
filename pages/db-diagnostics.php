<?php
/**
 * ============================================================
 * Database Connection Diagnostics
 * ============================================================
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db.php';

$config = db_config();

// Mask password for security
$masked_config = $config;
if (!empty($masked_config['pass'])) {
    $masked_config['pass'] = str_repeat('*', min(8, strlen($masked_config['pass'])));
}

echo "<h1>FleetKE Database Diagnostics</h1>";
echo "<h3>Configuration:</h3>";
echo "<pre>";
print_r($masked_config);
echo "</pre>";

echo "<h3>1. DNS Resolution Test:</h3>";
$host = $config['host'];
$ip = gethostbyname($host);
if ($ip === $host) {
    echo "<span style='color:red; font-weight:bold;'>❌ DNS Resolution Failed.</span> Could not resolve host '$host' to an IP address.<br>";
} else {
    echo "<span style='color:green; font-weight:bold;'>✔ DNS Resolution Successful.</span> '$host' resolved to IP: <strong>$ip</strong><br>";
}

echo "<h3>2. Network Port Reachability Test:</h3>";
$port = $config['port'];
$timeout = 3; // seconds
$t1 = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
$t2 = microtime(true);
$duration = round(($t2 - $t1) * 1000, 2);

if ($fp) {
    echo "<span style='color:green; font-weight:bold;'>✔ Network Connection Successful.</span> Opened port $port on '$host' in $duration ms.<br>";
    fclose($fp);
} else {
    echo "<span style='color:red; font-weight:bold;'>❌ Network Connection Failed.</span> Port $port on '$host' is unreachable.<br>";
    echo "Error [$errno]: $errstr (Took $duration ms)<br>";
    echo "<p><em>Note: This usually means a firewall (like Aiven IP allowed list) is blocking connections from Render, or the host/port is incorrect.</em></p>";
}

echo "<h3>3. MySQL Connection Test:</h3>";
try {
    $conn = db_connect();
    echo "<span style='color:green; font-weight:bold;'>✔ MySQL Connection Successful!</span> Connected to database successfully.<br>";
    $conn->close();
} catch (Throwable $e) {
    echo "<span style='color:red; font-weight:bold;'>❌ MySQL Connection Failed.</span><br>";
    echo "Error Message: <strong>" . htmlspecialchars($e->getMessage()) . "</strong><br>";
}
?>
