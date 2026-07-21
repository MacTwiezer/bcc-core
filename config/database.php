<?php
// BCC-Core — PDO veritabanı bağlantısı
// Ortam: MariaDB 10.4 (XAMPP MySQL), 127.0.0.1:3306, DB: bcc_core, user: root, şifre: yok.

$DB_HOST = '127.0.0.1';
$DB_PORT = '3306';
$DB_NAME = 'bcc_core';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

function bcc_get_pdo()
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$DB_CHARSET}";

    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$DB_CHARSET}",
    );

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

    return $pdo;
}
