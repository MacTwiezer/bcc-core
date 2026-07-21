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

// BCC-Core — mysqli veritabanı bağlantısı (PDO'nun yanına, FAZ 0)

function bcc_get_mysqli()
{
    static $mysqli = null;

    if ($mysqli !== null) {
        return $mysqli;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $mysqli = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int) $DB_PORT);
    $mysqli->set_charset($DB_CHARSET);

    return $mysqli;
}

/**
 * :isim tarzı named parametreleri sırayla ? ile değiştirir ve
 * $params dizisinden ilgili değerleri sıraya göre toplar.
 * Aynı isim birden çok kez geçerse değeri her seferinde tekrar bağlar.
 */
function bcc_prepare_positional($sql, array $params)
{
    // Zaten positional (?) yazılmışsa dokunma, $params sırayla bağlanacak.
    if (strpos($sql, ':') === false) {
        return array($sql, array_values($params));
    }

    $bound = array();

    $converted = preg_replace_callback(
        '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
        function ($m) use ($params, &$bound) {
            $name = $m[1];

            if (array_key_exists($name, $params)) {
                $bound[] = $params[$name];
            } elseif (array_key_exists(':' . $name, $params)) {
                $bound[] = $params[':' . $name];
            } else {
                throw new InvalidArgumentException("bcc_query: eksik parametre :{$name}");
            }

            return '?';
        },
        $sql
    );

    return array($converted, $bound);
}

function bcc_bind_type($value)
{
    if (is_int($value)) {
        return 'i';
    }

    if (is_float($value)) {
        return 'd';
    }

    // null dahil geri kalan her şey string olarak bağlanır (NULL da bu şekilde geçer).
    return 's';
}

/**
 * $sql içindeki :isim ya da ? parametrelerini $params ile bağlayıp çalıştırır.
 * SELECT sorguları için mysqli_result (get_result), diğerleri için etkilenen
 * satır sayısını taşıyan mysqli_stmt döndürür.
 *
 * @return mysqli_result|mysqli_stmt
 */
function bcc_query($sql, $params = array())
{
    $mysqli = bcc_get_mysqli();

    list($sql, $bound) = bcc_prepare_positional($sql, $params);

    $stmt = $mysqli->prepare($sql);

    if (!empty($bound)) {
        $types = '';
        foreach ($bound as $value) {
            $types .= bcc_bind_type($value);
        }

        $stmt->bind_param($types, ...$bound);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result instanceof mysqli_result) {
        return $result;
    }

    return $stmt;
}

function bcc_fetch_all($sql, $params = array())
{
    $result = bcc_query($sql, $params);

    return $result->fetch_all(MYSQLI_ASSOC);
}

function bcc_fetch_one($sql, $params = array())
{
    $result = bcc_query($sql, $params);
    $row = $result->fetch_assoc();

    return $row === null ? false : $row;
}

function bcc_fetch_column($sql, $params = array())
{
    $row = bcc_fetch_one($sql, $params);

    if ($row === false) {
        return false;
    }

    return reset($row);
}

function bcc_execute($sql, $params = array())
{
    $result = bcc_query($sql, $params);

    // SELECT dışı sorgularda bcc_query mysqli_stmt döndürür; affected_rows oradan okunur.
    if ($result instanceof mysqli_stmt) {
        return $result->affected_rows;
    }

    return $result->num_rows;
}

function bcc_last_insert_id()
{
    return bcc_get_mysqli()->insert_id;
}

function bcc_begin_transaction()
{
    return bcc_get_mysqli()->begin_transaction();
}

function bcc_commit()
{
    return bcc_get_mysqli()->commit();
}

function bcc_rollback()
{
    return bcc_get_mysqli()->rollback();
}
