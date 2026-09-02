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
    return bcc_name_initial($user['full_name']);
}

// AYNI kural, elinde kullanıcı DİZİSİ değil yalnızca AD olan çağıranlar için
// (ilk tüketici: grid'deki kullanıcı hücrelerinin avatarı — orada değer
// id→ad haritasından çözülmüş düz bir string olarak geliyor). Baş harf mantığı
// iki yerde ayrı yazılsaydı biri "İ"yi doğru büyütürken diğeri bozabilirdi.
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
    // İkinci bir üyelik sorgusu YOK: kimlikler rol haritasının anahtarlarıdır
    // (bkz. current_user_team_roles). Anahtarlar (int) yazıldığı için
    // array_keys() int döndürür — çağıranların in_array((int) $teamId, ..., true)
    // KATI karşılaştırması bozulmaz.
    return array_keys(current_user_team_roles());
}

// Kullanıcının HER ekipteki rolü: team_id => 'owner'|'editor'|'commenter'|'viewer'.
//
// NEDEN VAR: current_user_role_in_team() ekip BAŞINA bir sorgu açar; "tüm
// ekiplerdeki rolüm ne?" sorusunu soran yerler (ilk tüketici: bildirim panelinin
// rol süzgeci, bkz. src/audit.php bcc_notification_scope_clause) bu yüzden N
// sorgu açmak zorunda kalırdı. Burası TEK sorguyla aynı bilgiyi verir ve
// current_user_team_ids() de bunun üzerine oturur — "kullanıcının takımları"
// sorgusu hâlâ TEK yerde, iki ayrı kaynak oluşmadı.
function current_user_team_roles()
{
    static $cache = null;

    $user = current_user();
    if ($user === null) {
        return array();
    }

    if ($cache !== null) {
        return $cache;
    }

    // ⚠️ PLATFORM ADMİNİ TÜM EKİPLERİ GÖRÜR — bilinçli bir ürün kararı.
    //
    // Ekip izolasyonu (KVKK) bu projenin temel güvencesi ve normal kullanıcı
    // için AYNEN duruyor: aşağıdaki üyelik sorgusu değişmedi. Değişen tek şey,
    // is_admin=1 olan kullanıcının kapsamının TÜM ekipler olması.
    //
    // Gerekçe: admin ekip oluşturabiliyor ama oluşturduğu ekibin üyesi
    // olmadığı için ona ERİŞEMİYORDU (require_team_access yalnızca üyeliğe
    // bakıyor). Çözüm olarak admini her ekibe üye YAPMAK yapay bir üyelik
    // kaydı üretirdi; kapsamı burada, TEK yerde genişletmek daha dürüst.
    //
    // Bu fonksiyon require_team_access() ve require_role()'un beslendiği yer
    // olduğu için genişletme tüm veri kapılarında otomatik geçerli olur —
    // sayfalara tek tek "ya da admin" koşulu SERPİLMEZ (projenin "rol eşiği
    // tek kaynakta" kuralı).
    //
    // ⚠️ İZ BIRAKIR: admin'in başka bir ekibin verisine dokunduğu her işlem
    // log_audit()'e o ekibin team_id'siyle düşmeye devam eder — erişim
    // genişledi, denetlenebilirlik azalmadı.
    //
    // Admin'in rolü her ekipte SANAL olarak 'owner'dır — current_user_role_in_team()
    // ile BİREBİR aynı kural (orada da team_members satırı okunmaz).
    if (is_platform_admin()) {
        $rows = bcc_fetch_all("SELECT id AS team_id, 'owner' AS role FROM teams");
    } else {
        $rows = bcc_fetch_all('SELECT team_id, role FROM team_members WHERE user_id = :uid', array('uid' => $user['id']));
    }

    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['team_id']] = $row['role'];
    }

    $cache = $map;

    return $map;
}

function current_user_role_in_team($teamId)
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    // Platform admini HER ekipte 'owner' sayılır — bkz. current_user_team_ids()
    // içindeki ayrıntılı gerekçe. Üyelik kaydı OKUNMAZ bile: admin bir ekipte
    // 'viewer' olarak kayıtlıysa bile platform yetkisi kısılmamalı, aksi hâlde
    // "admin ama bu ekipte bir şey yapamıyor" gibi tutarsız bir durum çıkardı.
    //
    // ⚠️ Bu SANAL bir roldür, team_members'ta satır YOKTUR. Üye listeleri,
    // "son owner silinemez" sayımı ve rol atama ekranları gerçek satırlara
    // bakmaya devam eder — admin oralarda üye olarak GÖRÜNMEZ (kullanıcının
    // "admin nasıl bir ekibe üye oluyor" itirazının karşılığı).
    if (is_platform_admin()) {
        return 'owner';
    }

    $row = bcc_fetch_one(
        'SELECT role FROM team_members WHERE user_id = :uid AND team_id = :tid LIMIT 1',
        array('uid' => $user['id'], 'tid' => $teamId)
    );

    return $row ? $row['role'] : null;
}

// Bir rütbenin ATAYABİLECEĞİ rolleri döndürür — OpsFlow davranışı ("at or
// below your permission level", eşit dahil, bkz. docs/GEREKSINIMLER.md — base yetkileri
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

// ---------------------------------------------------------------------------
// YETENEK (capability) haritası — RBAC'in TEK KAYNAĞI
// ---------------------------------------------------------------------------
// Kural: hiçbir sayfa/uçnokta "role === 'owner'" veya
// "in_array($role, array('editor','owner'))" gibi bir kontrolü KENDİ İÇİNDE
// YAZMAZ; hepsi aşağıdaki fonksiyonlardan birini çağırır. Böylece bir yeteneğin
// eşiği değiştiğinde arayüzdeki gizleme ile sunucudaki reddetme ASLA ayrışamaz —
// bu dosyada yaşanan asıl kusur buydu (bkz. bcc_can_manage_members notu).
//
// Rol rütbeleri: viewer(1) < commenter(2) < editor(3) < owner(4).
//
// SİSTEMDEKİ ROLLER BU DÖRTTÜR — başka rol yoktur ve bir rol adı yalnızca
// $BCC_ROLE_RANK'te varsa geçerlidir (bcc_assignable_roles() atanabilir listeyi
// oradan türetir, bcc_team_member_assign() gelen değeri o listeye karşı
// doğrular). Whitelist dışı bir rol — ör. istemcinin uydurduğu bir ad — hiçbir
// kapıyı açmaz ve atanamaz.
//
// KALDIRILDI — "Creator": bu uygulamada 'creator' diye bir rol HİÇBİR ZAMAN
// olmadı (ENUM'a eklenmedi). Yalnızca OpsFlow'un izin matrisindeki Creator
// satırının bizde 'owner'a denk düştüğünü anlatan bir eşleme notu vardı; o
// eşleme artık anlamsız olduğu için not da kaldırıldı.

// Base EKLEME/SİLME:
//   "Add and delete bases in the shared workspace" → Owner ✅
//                                                    Editor ✗ Commenter ✗ Viewer ✗
//   "Access all bases ... at your assigned permission level" → DÖRT rolde de ✅
// Yani base'i GÖRMEK üyelikle gelir (require_team_access + dashboard.php'nin
// team_id IN (...) süzgeci), OLUŞTURMAK/SİLMEK yalnızca Owner'a aittir.
// Çağıranlar: dashboard.php, bases.php, api/base_create.php, api/base_delete.php.
function bcc_can_manage_bases($role)
{
    return $role === 'owner';
}

// ÜYE yönetimi: ekibe kullanıcı ekleme, rol atama/değiştirme, üyeyi çıkarma.
//
// DİKKAT — bu, OpsFlow'un kendi matrisinden BİLEREK DAHA KATI: orada "Invite
// users at the same or below your permission level" satırı BEŞ rolde de ✅'dir
// (bir Read-only bile kendi seviyesinde davet edebilir). Bu uygulamada ürün
// kararı olarak üye yönetimi YALNIZCA Owner'a bırakıldı (kullanıcı talebi).
//
// Bulunan gerçek açık (bu fonksiyon eklenmeden önce): team_members.php sayfası
// require_role('viewer') ile herkese açıktı ve assign/remove POST'ları yalnızca
// "rank(hedef) <= rank(ben)" hiyerarşi kontrolünden geçiyordu. Sonuç: viewer
// rolündeki bir kullanıcı, ekibe İSTEDİĞİ aktif kullanıcıyı viewer olarak
// EKLEYEBİLİYOR ve diğer viewer'ları ekipten ÇIKARABİLİYORDU (canlı olarak
// doğrulandı: POST -> 200 + "Atama kaydedildi" + team_members satırı oluştu).
// Editor için de aynısı, üstelik commenter/editor rollerini de atayabiliyordu.
function bcc_can_manage_members($role)
{
    return $role === 'owner';
}

// ŞEMA değişikliği: alan (field) ve tablo oluşturma/silme/düzenleme.
// OpsFlow'da Editor kayıt düzenler ama şemaya dokunamaz — bu uygulamada zaten
// owner-only'di (table_fields.php, base_tables.php, api/field_create.php);
// burası o dağınık kontrolleri tek isim altında toplar.
function bcc_can_manage_schema($role)
{
    return $role === 'owner';
}

// KAYIT düzenleme: satır ekleme/güncelleme/silme, içe aktarma, görünüm
// yapılandırması. OpsFlow: Editor ve üzeri.
function bcc_can_edit_records($role)
{
    return $role === 'editor' || $role === 'owner';
}

// YORUM yazma. OpsFlow: Commenter ve üzeri (Read-only hariç).
function bcc_can_comment($role)
{
    return $role === 'commenter' || $role === 'editor' || $role === 'owner';
}

// TEMSİLCİ tespiti - "not inceleme takipi"
function bcc_is_representative($role)
{
    return $role === 'commenter';
}
// İnceleme geçmişini GÖRÜNTÜLEME yetkisi
function bcc_can_view_record_audits($role)
{
    return $role === 'owner';
}

// Bir ekibin verisine (base/tablo/kayıt) erişen HER sorgudan önce çağrılmalı.
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

// ---------------------------------------------------------------------------
// GİRİŞ DENEME SINIRI (kaba kuvvet freni) — bkz. migrations/024_login_attempts.sql
// ---------------------------------------------------------------------------
// Buraya kadar tek fren bcrypt'in maliyetiydi: bu makinede ölçüldü, başarısız
// bir deneme ~55 ms (attempt_login'deki sahte hash sayesinde e-posta var olsa
// da olmasa da AYNI). 55 ms → saniyede ~18, günde ~1,5 milyon deneme. Zayıf bir
// parola için yeterli bir fren değil.
//
// İKİ KURAL, İKİ AYRI SALDIRI:
//   (ip + e-posta)  5 hata / 15 dk → tek hesabı deneme yanılma ile kırma
//   (ip)           20 hata / 15 dk → aynı kaynaktan çok hesabı tarama
//
// ⚠️ "Yalnızca e-postaya göre" global kilit BİLEREK YOK: öyle olsaydı bir
// saldırgan, hedefinin adresine 5 yanlış parola göndererek O KİŞİYİ sistemden
// kilitleyebilirdi (hizmet engelleme / DoS). Anahtarda IP'nin bulunması kilidi
// saldırganın kendi kaynağına hapseder — kurban başka bir ağdan girebilir.
define('BCC_LOGIN_WINDOW_MINUTES', 15);
define('BCC_LOGIN_MAX_PER_ACCOUNT', 5);
define('BCC_LOGIN_MAX_PER_IP', 20);

/**
 * İsteği yapan istemcinin IP'si, inet_pton() ikili biçiminde (VARBINARY(16)).
 *
 * ⚠️ X-Forwarded-For BİLEREK OKUNMUYOR. O başlık İSTEMCİDEN gelir; ona güvenmek
 * saldırganın her istekte sahte bir IP yazarak sınırı tamamen atlamasına izin
 * verirdi — yani frenin hiç olmaması demekti. Uygulama bir ters vekilin
 * (nginx/Cloudflare) arkasına alınırsa doğru çözüm burada başlık okumak değil,
 * VEKİLİN REMOTE_ADDR'i düzeltmesidir (Apache: mod_remoteip + RemoteIPTrustedProxy).
 *
 * CLI'da (scripts/) REMOTE_ADDR yoktur; sabit bir yer tutucu döner, böylece
 * regresyon betikleri deterministik tek bir "IP" üzerinden çalışır.
 */
function bcc_client_ip_binary()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $packed = @inet_pton($ip);

    return $packed === false ? inet_pton('0.0.0.0') : $packed;
}

/**
 * Bu istek şu an kilitli mi? Kilitliyse KAÇ SANİYE kaldığını, değilse 0 döner.
 *
 * KAYAN PENCERE: "son BCC_LOGIN_WINDOW_MINUTES dakikadaki hata sayısı eşiği
 * aştı mı" diye bakılır. Kilit, o penceredeki EN ESKİ hatanın pencereden
 * düştüğü anda kalkar — saldırgan denemeye devam ettikçe pencere kayar ve kilit
 * uzar, dürüst kullanıcı beklediğinde kendiliğinden açılır. Sabit süreli bir
 * "15 dk ceza" alanı yerine bu seçildi: elle kilit açma veya cron gerekmiyor.
 */
function bcc_login_retry_after($email)
{
    $ip = bcc_client_ip_binary();
    $wait = 0;

    // (WHERE parçası, parametreler, eşik)
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

/**
 * Başarısız bir denemeyi kaydeder.
 *
 * Var OLMAYAN e-postalar da kaydedilir (tabloda users'a FK yok) — aksi hâlde
 * saldırgan var olmayan bir adresle sınırsız deneme yapıp sınırın nasıl
 * davrandığını serbestçe ölçebilirdi.
 */
function bcc_login_record_failure($email)
{
    bcc_execute(
        'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (:ip, :email, NOW())',
        array('ip' => bcc_client_ip_binary(), 'email' => $email)
    );

    // Fırsatçı temizlik: ~50 kayıtta bir, penceresi dolmuş TÜM satırlar silinir.
    // Ayrı bir cron/zamanlanmış görev gerekmesin diye böyle — tablo yalnızca
    // "son 15 dakikanın hataları" kadar büyür. Her INSERT'te silmek gereksiz
    // yazma maliyeti, hiç silmemek sonsuz büyüyen bir tablo olurdu.
    if (random_int(1, 50) === 1) {
        bcc_execute(
            'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL :mins MINUTE)',
            array('mins' => BCC_LOGIN_WINDOW_MINUTES)
        );
    }
}

/**
 * Başarılı girişte bu (ip, e-posta) çiftinin hata geçmişini siler — parolasını
 * üçüncü denemede doğru hatırlayan kullanıcı, bir sonraki girişine iki hata
 * borcuyla başlamasın.
 */
function bcc_login_clear_failures($email)
{
    bcc_execute(
        'DELETE FROM login_attempts WHERE ip = :ip AND email = :email',
        array('ip' => bcc_client_ip_binary(), 'email' => $email)
    );
}

// Dönüş: 'ok' (giriş yapıldı), 'inactive' (şifre doğru ama hesap onay bekliyor),
// 'invalid' (e-posta/şifre hatalı), 'throttled' (deneme sınırı aşıldı — kalan
// süre için bcc_login_retry_after()). Parola önce doğrulanır; böylece hesabın
// var olup olmadığı veya onay durumu, doğru şifre bilinmeden sızdırılmaz.
function attempt_login($email, $password)
{
    // Sınır kontrolü EN BAŞTA: kilitliyken parola doğrulaması hiç çalışmaz, yani
    // istek ~55 ms yerine ~1 ms'de döner. Bu ölçülebilir bir fark ama SIR DEĞİL:
    // kullanıcıya zaten "çok fazla deneme" mesajı gösteriliyor. Sızan tek bilgi
    // "bu IP+adres kilitli mi", o da yalnızca kilidin sahibi IP'den ölçülebilir.
    if (bcc_login_retry_after($email) > 0) {
        return 'throttled';
    }

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
        // YALNIZCA yanlış parola sayılır. Aşağıdaki 'inactive' dalına düşen biri
        // parolayı zaten DOĞRU bilmiştir — bu kaba kuvvet değildir, onay bekleyen
        // kullanıcı kendi hesabını kilitlememeli.
        bcc_login_record_failure($email);

        return 'invalid';
    }

    if ((int) $row['is_active'] !== 1) {
        return 'inactive';
    }

    bcc_login_clear_failures($email);

    // Oturum sabitleme (session fixation) savunması: saldırganın kurbana önceden
    // yerleştirdiği oturum kimliği burada ölür (true = eski oturum dosyasını SİL).
    session_regenerate_id(true);

    // CSRF jetonu da yenilenir. session_regenerate_id() oturum VERİSİNİ yeni
    // kimliğe KOPYALAR — yani giriş öncesi üretilmiş jeton, oturum kimliği
    // değişse bile yaşamaya devam ederdi. Yetki yükselmesi anında yalnızca
    // oturum kimliğinin değil, oturumla ilişkili TÜM sırların tazelenmesi
    // gerekir. unset yeterli: csrf_token() bir sonraki çağrıda yenisini üretir
    // (bkz. src/csrf.php:5).
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

/**
 * "Çevrimiçi" tanımının TEK kaynağı: aktif hesap + son
 * BCC_PRESENCE_WINDOW_MINUTES dakika içinde istek yapmış.
 *
 * NEDEN FONKSİYON: bu üç koşul bcc_online_user_count() ve bcc_online_users()
 * içinde AYNEN iki kez yazılıydı. İkisi aynı ekranda yan yana kullanılıyor
 * (sayı + liste); biri değiştirilip diğeri unutulsaydı kullanıcı "5 kişi
 * çevrimiçi" yazısının altında 4 kişi görürdü — sessiz, açıklaması zor bir
 * tutarsızlık. :mins yer tutucusu çağırana ait, değer yine parametreyle gider.
 */
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

/**
 * Çevrimiçi kullanıcıların kendisi (en son aktif olan en üstte).
 *
 * LIMIT YOK — istenen davranış bu: son BCC_PRESENCE_WINDOW_MINUTES dakikada
 * etkin olan HERKES dönüyor. Sonuç kümesi zaten doğal olarak sınırlı, çünkü
 * WHERE koşulu yalnızca son birkaç dakikada istek yapmış aktif kullanıcıları
 * seçiyor — üst sınır, o an sistemi kullanan kişi sayısı kadar.
 */
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