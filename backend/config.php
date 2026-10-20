<?php
declare(strict_types=1);

/* Local development config. Keep real secrets in backend/.env (never commit it). */
$envFile = __DIR__ . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key,$value] = explode('=', $line, 2);
        $key = trim($key); $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}
const DB_HOST = '127.0.0.1';
const DB_NAME = 'eventease';
const DB_USER = 'root';
const DB_PASS = '';
const TOKEN_TTL_HOURS = 8;
if (!defined('JWT_SECRET')) define('JWT_SECRET', (string)(getenv('EVENTEASE_JWT_SECRET') ?: 'LOCAL-ONLY-change-this-secret-before-deployment-EventEase-2026'));
if (!defined('API_KEY')) define('API_KEY', (string)(getenv('EVENTEASE_API_KEY') ?: 'LOCAL-ONLY-eventease-demo-api-key-2026'));

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
