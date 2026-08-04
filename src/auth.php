<?php
// Kimlik doğrulama, oturum ve KVKK ekip-izolasyon yardımcıları.
// Kural: bir ekibin verisini görmek için o ekibin üyesi olmak gerekir.
// Platform admin kullanıcı/ekip yönetir ama üye olmadığı ekibin verisini göremez.

require_once __DIR__ . '/../config/database.php';

$GLOBALS['BCC_ROLE_RANK'] = array(
    'viewer' => 1,
    'commenter' => 2,
    'editor' => 3,
    'owner' => 4,
);

// Rol adlarının ekranda gösterilecek karşılıkları (kullanıcı isteğiyle
// İngilizce'ye çevrildi) — DB'deki İngilizce değerler (require_role()/
// BCC_ROLE_RANK'in çalışması için) zaten değişmedi, yalnızca EKRANDA
// gösterilecek metin burada tek yerden tanımlı.
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

// Hesap menüsü avatarında gösterilen tek harfli baş harf (UTF-8 güvenli — ör. "İ", "Ö").
function bcc_user_initial($user)
{
    return mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1, 'UTF-8'), 'UTF-8');
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
        http_response_code(403);
        die('Bu sayfaya erişim yetkiniz yok (admin gerekli).');
    }
}

function current_user_team_ids()
{
    static $cache = null;

    $user = current_user();
    if ($user === null) {
        return array();
    }

    if ($cache !== null) {
        return $cache;
    }

    $rows = bcc_fetch_all('SELECT team_id FROM team_members WHERE user_id = :uid', array('uid' => $user['id']));

    $ids = array();
    foreach ($rows as $row) {
        $ids[] = (int) $row['team_id'];
    }

    $cache = $ids;

    return $ids;
}

function current_user_role_in_team($teamId)
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $row = bcc_fetch_one(
        'SELECT role FROM team_members WHERE user_id = :uid AND team_id = :tid LIMIT 1',
        array('uid' => $user['id'], 'tid' => $teamId)
    );

    return $row ? $row['role'] : null;
}

// Bir rütbenin ATAYABİLECEĞİ rolleri döndürür — Airtable paritesi ("at or
// below your permission level", eşit dahil, bkz. support.airtable.com/docs/base-permissions
// + managing-billable-collaborators FAQ). team_members.php (tam Collaborators
// paneli) VE grid.php'nin Paylaş popup'ı (hızlı atama) AYNI mantığı kullanır —
// kopya YOK. $myRank çağıran tarafından hesaplanır (current_user_role_in_team()
// zaten bir DB sorgusu; burada TEKRAR çağırıp ikinci bir sorgu açmak yerine
// hazır rütbe alınır).
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

// Bir ekibin verisine (base/tablo/kayıt) erişen HER sorgudan önce çağrılmalı.
function require_team_access($teamId)
{
    require_login();

    if (!in_array((int) $teamId, current_user_team_ids(), true)) {
        http_response_code(403);
        die('Bu ekibin verisine erişim yetkiniz yok.');
    }
}

function require_role($teamId, $minRole)
{
    require_team_access($teamId);

    $role = current_user_role_in_team($teamId);
    $ranks = $GLOBALS['BCC_ROLE_RANK'];

    if ($role === null || !isset($ranks[$role]) || !isset($ranks[$minRole]) || $ranks[$role] < $ranks[$minRole]) {
        http_response_code(403);
        die('Bu işlem için yetkiniz yeterli değil.');
    }
}

// Dönüş: 'ok' (giriş yapıldı), 'inactive' (şifre doğru ama hesap onay bekliyor),
// 'invalid' (e-posta/şifre hatalı). Parola önce doğrulanır; böylece hesabın var
// olup olmadığı veya onay durumu, doğru şifre bilinmeden sızdırılmaz.
function attempt_login($email, $password)
{
    $row = bcc_fetch_one(
        'SELECT id, password_hash, is_active FROM users WHERE email = :email LIMIT 1',
        array('email' => $email)
    );

    // Bulunan gerçek bug: yukarıdaki yorum "parola önce doğrulanır" diyordu ama
    // `!$row || !password_verify(...)` kısa devre yaptığı için $row yoksa
    // password_verify() HİÇ ÇAĞRILMIYORDU — bcrypt hesaplaması atlanan bu istekler
    // ölçülebilir şekilde daha hızlı dönüyordu (canlı ölçüm: ~6ms vs ~141ms, var
    // olmayan e-posta / var olan e-posta + yanlış şifre). Hata mesajı ikisinde de
    // aynı olsa bile, bu zamanlama farkı bir saldırganın yanıt sürelerini ölçerek
    // hangi e-postaların kayıtlı olduğunu tespit etmesine (user enumeration) izin
    // veriyordu. Düzeltme: $row yoksa da GERÇEK bir bcrypt hash'ine karşı
    // password_verify() çağrılır (sahte parola her zaman reddedilir), süre sabit kalır.
    $hashToCheck = $row ? $row['password_hash'] : '$2y$10$kS.GapggyqU6tsmsQyBFjOLHiSr9yvm8s7BTkPere9dlqXWf3MAoa';
    $passwordOk = password_verify($password, $hashToCheck);

    if (!$row || !$passwordOk) {
        return 'invalid';
    }

    if ((int) $row['is_active'] !== 1) {
        return 'inactive';
    }

    session_regenerate_id(true);
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
