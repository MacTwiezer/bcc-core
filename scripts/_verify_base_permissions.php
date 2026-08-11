<?php
// Home (dashboard.php) base olusturma yetkisi + kart ikonu/rol rozeti testi.
//
// Neyi dogruluyor:
//   A) Airtable izin matrisi karsiligi (src/auth.php bcc_can_manage_bases):
//      base EKLEME/SILME yalnizca 'owner'a acik, editor/commenter/viewer'a kapali.
//   B) SUNUCU TARAFI RENDER: yetkisi olmayan kullanicinin HTML'inde ne "+ Yeni
//      Base Olustur" kutucugu ne de "Sil" ogesi bulunur (CSS ile gizleme YOK).
//   C) UC NOKTA (public/api/base_create.php) — asil kapi. Kutucugu hic gormeyen
//      bir kullanici elle POST atsa da 403 alir. Bu testler GERCEK uc nokta
//      dosyasini alt surecte calistirir (bkz. _base_create_case.php), kopyasini
//      degil; "gizleme != yetkilendirme" boylece kanitlanir.
//   D) Ikon sistemi: kategori base ADINDAN, renk base ID'sinden deterministik
//      turer; ayni base her sayfada ayni renk/glifi alir.
//
// Betik kendi test takimini/kullanicilarini kurar ve sonunda (basarili ya da
// basarisiz fark etmeksizin) SADECE kendi olusturdugu id'leri siler;
// veritabanindaki gercek veriye DOKUNMAZ.
//
// Calistirma: C:\php73\php.exe scripts\_verify_base_permissions.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

const FIX_PREFIX = 'YETKI_TEST_';
const FIX_TEAM = 'YETKI_TEST_TAKIM';

$results = array();

// bcc_fetch_one() satir bulamayinca null DEGIL false doner (bkz.
// config/database.php) — "olusmamis olmali" testleri bu yuzden ikisini de kabul
// eden bu yardimciyla yazilir; `=== null` yazan bir kontrol satir olusmus olsa
// bile "gecti" derdi.
function no_row($row)
{
    return $row === false || $row === null;
}

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

// ---------------------------------------------------------------------------
// A) Yetki esigi — saf fonksiyon
// ---------------------------------------------------------------------------
echo "--- A) Yetki esigi (bcc_can_manage_bases) ---\n";

check('owner base ekleyebilir', bcc_can_manage_bases('owner') === true);
check('editor base EKLEYEMEZ (Airtable: Editor bu satirda yok)', bcc_can_manage_bases('editor') === false);
check('commenter base ekleyemez', bcc_can_manage_bases('commenter') === false);
check('viewer base ekleyemez', bcc_can_manage_bases('viewer') === false);
check('rolsuz (null) base ekleyemez', bcc_can_manage_bases(null) === false);
check('bilinmeyen rol base ekleyemez', bcc_can_manage_bases('creator') === false);

// ---------------------------------------------------------------------------
// D) Ikon sistemi — saf fonksiyon
// ---------------------------------------------------------------------------
echo "\n--- D) Ikon kategorisi ve rengi ---\n";

check('"Bcc-Core" -> varsayilan veritabani glifi', bcc_base_icon_category('Bcc-Core') === 'database', bcc_base_icon_category('Bcc-Core'));
check('"Export Test" -> disa aktarma glifi (export, testten ONCE taranir)', bcc_base_icon_category('Export Test') === 'export', bcc_base_icon_category('Export Test'));
check('"RoleTest Base" -> yetki/kalkan glifi', bcc_base_icon_category('RoleTest Base') === 'shield', bcc_base_icon_category('RoleTest Base'));
check('"Satis CRM" -> kisiler glifi', bcc_base_icon_category('Satis CRM') === 'users', bcc_base_icon_category('Satis CRM'));
check('"Bütçe 2026" -> fis glifi (sapkali yazim)', bcc_base_icon_category('Bütçe 2026') === 'receipt', bcc_base_icon_category('Bütçe 2026'));
check('"butce 2026" -> fis glifi (sapkasiz yazim)', bcc_base_icon_category('butce 2026') === 'receipt', bcc_base_icon_category('butce 2026'));
check('"DENEME" -> test glifi (buyuk harf duyarsiz)', bcc_base_icon_category('DENEME') === 'flask', bcc_base_icon_category('DENEME'));
check('bos ad -> varsayilan glif (hata degil)', bcc_base_icon_category('') === 'database');

$svg = bcc_base_icon_svg(20, 'Export Test');
check('SVG currentColor ile cizilir (tek glif hem pastel hem dolu zeminde calisir)', strpos($svg, 'stroke="currentColor"') !== false);
check('SVG 24x24 izgarada', strpos($svg, 'viewBox="0 0 24 24"') !== false);
check('bcc_base_icon_svg(14) tek argumanla hala calisir (geriye donuk)', strpos(bcc_base_icon_svg(14), 'width="14"') !== false);

$t7a = bcc_base_icon_theme(7);
$t7b = bcc_base_icon_theme(7);
check('ayni base id -> ayni tema (deterministik)', $t7a === $t7b);
check('solid rengi ile pastel zemin AYNI satirdan gelir', bcc_base_icon_color(7) === $t7a['solid']);
$styleAttr = bcc_base_icon_style_attr(7);
check('style attr dort degiskeni de basar (acik+koyu tema)',
    strpos($styleAttr, '--bi-bg:') !== false && strpos($styleAttr, '--bi-fg:') !== false
    && strpos($styleAttr, '--bi-bg-dark:') !== false && strpos($styleAttr, '--bi-fg-dark:') !== false, $styleAttr);
check('style attr ham `background:` BASMAZ (koyu tema kuralini yenerdi)', strpos($styleAttr, 'background') === false, $styleAttr);

// ---------------------------------------------------------------------------
// B) Sunucu tarafi render — rol basina HTML
// ---------------------------------------------------------------------------
echo "\n--- B) Sunucu tarafi render (bcc_render_home_base_grid) ---\n";

function render_grid_for($role, $canCreate)
{
    $bases = array(array(
        'id' => 7, 'team_id' => 3, 'name' => 'Export Test',
        'description' => null, 'created_at' => '2026-01-01 10:00:00', 'last_opened' => null,
    ));

    ob_start();
    bcc_render_home_base_grid($bases, array(), array(3 => 'Takim'), 'bos', array(3 => $role), $canCreate);
    return ob_get_clean();
}

$ownerHtml = render_grid_for('owner', bcc_can_manage_bases('owner'));
$editorHtml = render_grid_for('editor', bcc_can_manage_bases('editor'));
$viewerHtml = render_grid_for('viewer', bcc_can_manage_bases('viewer'));

check('owner: "+ Yeni Base Olustur" kutucugu basilir', strpos($ownerHtml, 'home-create-base-btn') !== false);
check('owner: "Sil" ogesi basilir', strpos($ownerHtml, 'data-base-delete') !== false);
check('owner: Owner rozeti basilir', strpos($ownerHtml, 'home-base-role--owner') !== false);

check('editor: kutucuk HTML\'de HIC YOK (CSS ile gizlenmiyor)', strpos($editorHtml, 'home-create-base-btn') === false);
check('editor: "Sil" ogesi HTML\'de HIC YOK', strpos($editorHtml, 'data-base-delete') === false);
check('editor: Editor rozeti basilir', strpos($editorHtml, 'home-base-role--editor') !== false);

check('viewer: kutucuk yok', strpos($viewerHtml, 'home-create-base-btn') === false);
check('viewer: "Sil" yok', strpos($viewerHtml, 'data-base-delete') === false);
check('viewer: Viewer rozeti basilir', strpos($viewerHtml, 'home-base-role--viewer') !== false);

check('kart ikonu base id\'sinin CSS degiskenlerini tasir', strpos($ownerHtml, '--bi-bg:') !== false);
check('kart ikonu ad\'dan turetilen export glifini kullanir', strpos($ownerHtml, 'M12 17V3') !== false);

ob_start();
bcc_render_home_base_grid(array(), array(), array(), 'Henuz base yok.', array(), true);
$emptyCanCreate = ob_get_clean();
ob_start();
bcc_render_home_base_grid(array(), array(), array(), 'Henuz base yok.', array(), false);
$emptyCannot = ob_get_clean();

check('bos durum + yetki: olustur butonu var (cikmaz sokak yok)', strpos($emptyCanCreate, 'home-create-base-btn') !== false);
check('bos durum + yetki YOK: buton yok', strpos($emptyCannot, 'home-create-base-btn') === false);

// starred.php imzayi degistirmeden cagiriyor — 6. parametre varsayilani false olmali.
ob_start();
bcc_render_home_base_grid(array(), array(), array(), 'bos', array());
$legacy = ob_get_clean();
check('starred.php\'nin 5 argumanli cagrisi kutucuk BASMAZ (varsayilan false)', strpos($legacy, 'home-create-base-btn') === false);

// ---------------------------------------------------------------------------
// C) Uc nokta — gercek dosya, alt surecte
// ---------------------------------------------------------------------------
echo "\n--- C) public/api/base_create.php (gercek uc nokta) ---\n";

$createdUserIds = array();
$createdTeamIds = array();
$createdBaseIds = array();

function cleanup()
{
    global $createdUserIds, $createdTeamIds, $createdBaseIds;

    // SADECE bu betigin olusturdugu id'ler — gercek veriye dokunulmaz.
    foreach ($createdBaseIds as $id) {
        bcc_execute('DELETE FROM audit_log WHERE entity_type = :et AND entity_id = :id', array('et' => 'base', 'id' => $id));
        bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $id));
    }
    foreach ($createdTeamIds as $id) {
        // Bu takimda testin ARTIK BILMEDIGI (dogrulama testlerinde olusan) base
        // kalmis olabilir; takim id'si bu betige ait oldugu icin guvenli.
        bcc_execute('DELETE FROM bases WHERE team_id = :id', array('id' => $id));
        bcc_execute('DELETE FROM team_members WHERE team_id = :id', array('id' => $id));
        bcc_execute('DELETE FROM teams WHERE id = :id', array('id' => $id));
    }
    foreach ($createdUserIds as $id) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $id));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $id));
    }
}

register_shutdown_function('cleanup');

// --- fikstur kurulumu ---
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => FIX_TEAM));
$teamId = (int) bcc_last_insert_id();
$createdTeamIds[] = $teamId;

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => FIX_TEAM . '_YABANCI'));
$otherTeamId = (int) bcc_last_insert_id();
$createdTeamIds[] = $otherTeamId;

$hash = password_hash('yetki-test-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
$userIdByRole = array();

foreach (array('owner', 'editor', 'commenter', 'viewer') as $role) {
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :p, :f, 0, 1)',
        array(
            'e' => strtolower(FIX_PREFIX) . $role . '@bcc-test.local',
            'p' => $hash,
            'f' => FIX_PREFIX . $role,
        )
    );
    $uid = (int) bcc_last_insert_id();
    $createdUserIds[] = $uid;
    $userIdByRole[$role] = $uid;

    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $teamId, 'u' => $uid, 'r' => $role)
    );
}

function run_case($userId, $teamId, $name, $flag = '')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_base_create_case.php')
        . ' ' . escapeshellarg((string) $userId)
        . ' ' . escapeshellarg((string) $teamId)
        . ' ' . escapeshellarg($name);

    if ($flag !== '') {
        $cmd .= ' ' . escapeshellarg($flag);
    }

    $out = shell_exec($cmd . ' 2>&1');
    $status = 0;
    if (preg_match('/HTTP_STATUS=(\d+)/', (string) $out, $m)) {
        $status = (int) $m[1];
    }

    return array('status' => $status, 'body' => (string) $out);
}

$editorCase = run_case($userIdByRole['editor'], $teamId, 'YETKI_TEST_EDITOR_BASE');
check('editor POST -> 403 (kutucugu gormese de elle deneyebilir)', $editorCase['status'] === 403, $editorCase['body']);
check('editor icin base OLUSMADI',
    no_row(bcc_fetch_one('SELECT id FROM bases WHERE name = :n', array('n' => 'YETKI_TEST_EDITOR_BASE'))));

$commenterCase = run_case($userIdByRole['commenter'], $teamId, 'YETKI_TEST_COMMENTER_BASE');
check('commenter POST -> 403', $commenterCase['status'] === 403, $commenterCase['body']);

$viewerCase = run_case($userIdByRole['viewer'], $teamId, 'YETKI_TEST_VIEWER_BASE');
check('viewer POST -> 403', $viewerCase['status'] === 403, $viewerCase['body']);

// Uye OLMADIGI takim: owner rolu baska bir takimda olmak bu takimda yetki vermez.
$foreignCase = run_case($userIdByRole['owner'], $otherTeamId, 'YETKI_TEST_YABANCI_BASE');
check('owner, UYE OLMADIGI takimda -> 403 (KVKK izolasyonu once)', $foreignCase['status'] === 403, $foreignCase['body']);
check('yabanci takimda base OLUSMADI',
    no_row(bcc_fetch_one('SELECT id FROM bases WHERE name = :n', array('n' => 'YETKI_TEST_YABANCI_BASE'))));

$csrfCase = run_case($userIdByRole['owner'], $teamId, 'YETKI_TEST_CSRF_BASE', 'bozuk_csrf');
check('owner + bozuk CSRF -> 403', $csrfCase['status'] === 403, $csrfCase['body']);

$anonCase = run_case(0, $teamId, 'YETKI_TEST_ANON_BASE');
check('oturumsuz POST -> 401', $anonCase['status'] === 401, $anonCase['body']);

// Dogrulama: bos ad ve 151 karakter -> 422, INSERT YOK.
$emptyNameCase = run_case($userIdByRole['owner'], $teamId, '   ');
check('owner + bos ad -> 422 (dogrulama, 500 degil)', $emptyNameCase['status'] === 422, $emptyNameCase['body']);

$longName = str_repeat('a', 151);
$longCase = run_case($userIdByRole['owner'], $teamId, $longName);
check('owner + 151 karakter ad -> 422 (sessiz kirpilma yok)', $longCase['status'] === 422, $longCase['body']);
check('151 karakterlik ad DB\'ye kirpilarak yazilmadi',
    no_row(bcc_fetch_one('SELECT id FROM bases WHERE name = :n', array('n' => substr($longName, 0, 150)))));

// Mutlu yol: owner gercekten olusturabiliyor.
$okCase = run_case($userIdByRole['owner'], $teamId, 'YETKI_TEST_OWNER_BASE');
check('owner POST -> 200', $okCase['status'] === 200, $okCase['body']);

$newBase = bcc_fetch_one(
    'SELECT id, team_id, created_by FROM bases WHERE name = :n AND deleted_at IS NULL',
    array('n' => 'YETKI_TEST_OWNER_BASE')
);
check('owner icin base GERCEKTEN olustu', !no_row($newBase));

if (!no_row($newBase)) {
    $createdBaseIds[] = (int) $newBase['id'];
    check('base dogru takima yazildi', (int) $newBase['team_id'] === $teamId);
    check('created_by dogru kullanici', (int) $newBase['created_by'] === $userIdByRole['owner']);
    check('yanit govdesi yeni base id\'sini dondurur', strpos($okCase['body'], '"id":' . (int) $newBase['id']) !== false, $okCase['body']);

    $audit = bcc_fetch_one(
        'SELECT id FROM audit_log WHERE action = :a AND entity_type = :t AND entity_id = :id',
        array('a' => 'base.create', 't' => 'base', 'id' => (int) $newBase['id'])
    );
    check('audit kaydi yazildi (base.create)', !no_row($audit));
}

// ---------------------------------------------------------------------------
// Kapsam korumasi: uc giris noktasi da AYNI fonksiyondan beslenmeli.
// ---------------------------------------------------------------------------
echo "\n--- Kapsam korumasi (tek yetki kaynagi) ---\n";

$root = __DIR__ . '/..';
$dash = file_get_contents($root . '/public/dashboard.php');
$basesPage = file_get_contents($root . '/public/bases.php');
$api = file_get_contents($root . '/public/api/base_create.php');

check('dashboard.php esigi bcc_can_manage_bases()\'ten alir', strpos($dash, 'bcc_can_manage_bases(') !== false);
check('bases.php esigi bcc_can_manage_bases()\'ten alir', strpos($basesPage, 'bcc_can_manage_bases(') !== false);
check('api/base_create.php esigi bcc_can_manage_bases()\'ten alir', strpos($api, 'bcc_can_manage_bases(') !== false);
check('bases.php artik editor esigini KULLANMIYOR', strpos($basesPage, "require_role(\$teamId, 'editor')") === false);
check('bases.php INSERT\'i kendi yazmiyor (bcc_create_base ortak)', strpos($basesPage, 'INSERT INTO bases') === false);

// ---------------------------------------------------------------------------
echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
exit($failed === 0 ? 0 : 1);
