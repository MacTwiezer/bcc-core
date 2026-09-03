<?php

require_once __DIR__ . '/../config/database.php';

$GLOBALS['BCC_ROLE_RANK'] = array(
    'viewer' => 1,
    'commenter' => 2,
    'editor' => 3,
    'owner' => 4,
);

$GLOBALS['BCC_ROLE_LABELS'] = array(
    'viewer' => 'Viewer',
    'commenter' => 'Commenter',
    'editor' => 'Editor',
    'owner' => 'Owner',
);

function current_user($forceReload = false)
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$forceReload) {
        return $user;
    }

    $loaded = true;
    $user = null;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $row = bcc_fetch_one(
        'SELECT id, email, full_name, is_admin, is_active, last_seen_notifications_at FROM users WHERE id = :id LIMIT 1',
        array('id' => $_SESSION['user_id'])
    );

    if ($row && (int) $row['is_active'] === 1) {
        $user = $row;
    }

    return $user;
}

function is_logged_in()
{
    return current_user() !== null;
}

function bcc_user_initial($user)
{
    return bcc_name_initial($user['full_name']);
}

function bcc_name_initial($name)
{
    return mb_strtoupper(mb_substr((string) $name, 0, 1, 'UTF-8'), 'UTF-8');
}

function is_platform_admin()
{
    $user = current_user();

    return $user !== null && (int) $user['is_admin'] === 1;
}

function require_login()
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_admin()
{
    require_login();

    if (!is_platform_admin()) {
        bcc_error_page('Yetkiniz yok', 'Bu sayfa yalnızca platform yöneticilerine açık.', 403);
    }
}

function current_user_team_ids()
{

    return array_keys(current_user_team_roles());
}

function current_user_team_roles()
{

    static $cache = array();

    $user = current_user();
    if ($user === null) {
        return array();
    }

    $uid = (int) $user['id'];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }

    if (is_platform_admin()) {
        $rows = bcc_fetch_all("SELECT id AS team_id, 'owner' AS role FROM teams");
    } else {
        $rows = bcc_fetch_all('SELECT team_id, role FROM team_members WHERE user_id = :uid', array('uid' => $user['id']));
    }

    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['team_id']] = $row['role'];
    }

    $cache[$uid] = $map;

    return $map;
}

function current_user_role_in_team($teamId)
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    if (is_platform_admin()) {
        return 'owner';
    }

    $row = bcc_fetch_one(
        'SELECT role FROM team_members WHERE user_id = :uid AND team_id = :tid LIMIT 1',
        array('uid' => $user['id'], 'tid' => $teamId)
    );

    return $row ? $row['role'] : null;
}

function bcc_assignable_roles($myRank)
{
    $roles = array();
    foreach ($GLOBALS['BCC_ROLE_RANK'] as $roleName => $rank) {
        if ($rank <= $myRank) {
            $roles[] = $roleName;
        }
    }

    return $roles;
}

function bcc_can_manage_bases($role)
{
    return $role === 'owner';
}

function bcc_can_manage_members($role)
{
    return $role === 'owner';
}

function bcc_can_manage_schema($role)
{
    return $role === 'owner';
}

function bcc_can_edit_records($role)
{
    return $role === 'editor' || $role === 'owner';
}

function bcc_can_comment($role)
{
    return $role === 'commenter' || $role === 'editor' || $role === 'owner';
}

function bcc_is_representative($role)
{
    return $role === 'commenter';
}

function bcc_can_view_record_audits($role)
{
    return $role === 'owner';
}

function require_team_access($teamId)
{
    require_login();

    if (!in_array((int) $teamId, current_user_team_ids(), true)) {
        bcc_error_page('Yetkiniz yok', 'Bu ekibin verisine erişim yetkiniz yok.', 403);
    }
}

function require_role($teamId, $minRole)
{
    require_team_access($teamId);

    $role = current_user_role_in_team($teamId);
    $ranks = $GLOBALS['BCC_ROLE_RANK'];

    if ($role === null || !isset($ranks[$role]) || !isset($ranks[$minRole]) || $ranks[$role] < $ranks[$minRole]) {
        bcc_error_page('Yetkiniz yok', 'Bu işlem için yetkiniz yeterli değil.', 403);
    }
}

define('BCC_LOGIN_WINDOW_MINUTES', 15);
define('BCC_LOGIN_MAX_PER_ACCOUNT', 5);
define('BCC_LOGIN_MAX_PER_IP', 20);

function bcc_client_ip_binary()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $packed = @inet_pton($ip);

    return $packed === false ? inet_pton('0.0.0.0') : $packed;
}

function bcc_login_retry_after($email)
{
    $ip = bcc_client_ip_binary();
    $wait = 0;

    $rules = array(
        array('ip = :ip AND email = :email', array('ip' => $ip, 'email' => $email), BCC_LOGIN_MAX_PER_ACCOUNT),
        array('ip = :ip',                    array('ip' => $ip),                    BCC_LOGIN_MAX_PER_IP),
    );

    foreach ($rules as $rule) {
        $params = $rule[1];
        $params['mins'] = BCC_LOGIN_WINDOW_MINUTES;

        $row = bcc_fetch_one(
            'SELECT COUNT(*) AS hata_sayisi,
                    UNIX_TIMESTAMP(MIN(attempted_at)) AS ilk_hata
             FROM login_attempts
             WHERE ' . $rule[0] . '
               AND attempted_at >= (NOW() - INTERVAL :mins MINUTE)',
            $params
        );

        if (!$row || (int) $row['hata_sayisi'] < $rule[2]) {
            continue;
        }

        $kalan = ((int) $row['ilk_hata'] + BCC_LOGIN_WINDOW_MINUTES * 60) - time();

        if ($kalan > $wait) {
            $wait = $kalan;
        }
    }

    return $wait > 0 ? $wait : 0;
}

function bcc_login_record_failure($email)
{
    bcc_execute(
        'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (:ip, :email, NOW())',
        array('ip' => bcc_client_ip_binary(), 'email' => $email)
    );

    if (random_int(1, 50) === 1) {
        bcc_execute(
            'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL :mins MINUTE)',
            array('mins' => BCC_LOGIN_WINDOW_MINUTES)
        );
    }
}

function bcc_login_clear_failures($email)
{
    bcc_execute(
        'DELETE FROM login_attempts WHERE ip = :ip AND email = :email',
        array('ip' => bcc_client_ip_binary(), 'email' => $email)
    );
}

function attempt_login($email, $password)
{

    if (bcc_login_retry_after($email) > 0) {
        return 'throttled';
    }

    $row = bcc_fetch_one(
        'SELECT id, password_hash, is_active FROM users WHERE email = :email LIMIT 1',
        array('email' => $email)
    );

    $hashToCheck = $row ? $row['password_hash'] : '$2y$10$kS.GapggyqU6tsmsQyBFjOLHiSr9yvm8s7BTkPere9dlqXWf3MAoa';
    $passwordOk = password_verify($password, $hashToCheck);

    if (!$row || !$passwordOk) {

        bcc_login_record_failure($email);

        return 'invalid';
    }

    if ((int) $row['is_active'] !== 1) {
        return 'inactive';
    }

    bcc_login_clear_failures($email);

    session_regenerate_id(true);

    unset($_SESSION['csrf_token']);

    $_SESSION['user_id'] = (int) $row['id'];
    current_user(true);

    return 'ok';
}

function logout_user()
{
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

define('BCC_PRESENCE_TOUCH_INTERVAL', 60);

define('BCC_PRESENCE_WINDOW_MINUTES', 5);

function bcc_touch_user_activity() {
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $now = time();
    $last = isset($_SESSION['bcc_activity_touched_at']) ? (int) $_SESSION['bcc_activity_touched_at'] : 0;

    if ($now - $last < BCC_PRESENCE_TOUCH_INTERVAL) {
        return;
    }

    $_SESSION['bcc_activity_touched_at'] = $now;

    bcc_execute(
        'UPDATE users SET last_activity_at = NOW() WHERE id = :id',
        array('id' => $_SESSION['user_id'])
    );
}

function bcc_online_where_sql()
{
    return 'is_active = 1
            AND last_activity_at IS NOT NULL
            AND last_activity_at >= (NOW() - INTERVAL :mins MINUTE)';
}

function bcc_online_user_count() {
    static $count = null;

    if ($count === null) {
        $count = (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM users WHERE ' . bcc_online_where_sql(),
            array('mins' => BCC_PRESENCE_WINDOW_MINUTES)
        );
    }
    return $count;
}

function bcc_online_users()
{
    return bcc_fetch_all(
        'SELECT id, full_name, email, last_activity_at
         FROM users
         WHERE ' . bcc_online_where_sql() . '
         ORDER BY last_activity_at DESC',
        array('mins' => BCC_PRESENCE_WINDOW_MINUTES)
    );
}
