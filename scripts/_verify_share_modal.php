<?php
// "Paylas" modali dogrulamasi (grid.php'de team_members.php yonlendirmesinin
// yerini alan in-page dialog).
//
// Kapsam: (A) paylasilan uye mutasyon yardimcilari, (B) payload sozlesmesi ve
// rol bayraklari, (C) uc noktalar (yetki matrisi + is kurallari), (D) grid.php
// render (yonlendirme GITTI, modal GELDI), (E) team_members.php regresyonu
// (ayni yardimcilara tasindi, davranis degismedi), (F) kod tekrari yok.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_share_modal.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

// bcc_share_modal_payload() current_user() cagirir, o da $_SESSION okur —
// oturum CIKTIDAN ONCE baslatilmali (yoksa "headers already sent" uyarisi).
session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';   // src/auth.php'yi kendisi yukler
require __DIR__ . '/../src/audit.php';    // log_audit — mutasyon yardimcilari cagiriyor
require __DIR__ . '/../src/share_modal_payload.php';

define('BASE_URL', 'http://localhost');
define('TEST_PASS', 'ShareModal!2026');
define('REAL_BASE_ID', 15);

$emails = array(
    'owner'   => 'sharemodal.owner@bcc-test.local',
    'owner2'  => 'sharemodal.owner2@bcc-test.local',
    'editor'  => 'sharemodal.editor@bcc-test.local',
    'viewer'  => 'sharemodal.viewer@bcc-test.local',
    'pending' => 'sharemodal.pending@bcc-test.local',
    'outside' => 'sharemodal.outside@bcc-test.local',
    'free'    => 'sharemodal.free@bcc-test.local',
);

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0; $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf_field($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function extract_csrf_meta($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array('email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body'])));
    return $r['cookie'] ? $r['cookie'] : $c;
}

// bcc_fetch_column() satir yoksa false doner (null DEGIL) — testin "artik uye
// degil" karsilastirmasi tek bir degere (null) indirgensin.
function member_role($teamId, $userId)
{
    $role = bcc_fetch_column(
        'SELECT role FROM team_members WHERE team_id = :t AND user_id = :u',
        array(':t' => $teamId, ':u' => $userId)
    );

    return ($role === false || $role === null) ? null : (string) $role;
}

$cleanup = function () use ($emails) {
    foreach ($emails as $e) {
        $teamIds = array_column(bcc_fetch_all("SELECT id FROM teams WHERE name = 'ShareModal Test'"), 'id');
        foreach ($teamIds as $tid) {
            $baseIds = array_column(bcc_fetch_all('SELECT id FROM bases WHERE team_id = :t', array(':t' => $tid)), 'id');
            foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
            bcc_execute('DELETE FROM team_members WHERE team_id = :t', array(':t' => $tid));
            bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $tid));
        }
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $e));
    }
};

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

$cleanup();

try {
    // ---- Fikstur: KENDI ekibi (gercek 'TY' ekibine DOKUNULMUYOR) ----------
    bcc_execute("INSERT INTO teams (name) VALUES ('ShareModal Test')");
    $teamId = (int) bcc_last_insert_id();

    $userIds = array();
    $mkUser = function ($key, $email, $name, $role, $isActive) use ($teamId, &$userIds) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, :a)',
            array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => $name, ':a' => $isActive));
        $uid = (int) bcc_last_insert_id();
        $userIds[$key] = $uid;
        if ($role !== null) {
            bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
                array(':t' => $teamId, ':u' => $uid, ':r' => $role));
        }
        return $uid;
    };

    $mkUser('owner',   $emails['owner'],   'SM Owner',   'owner',    1);
    $mkUser('owner2',  $emails['owner2'],  'SM Owner2',  'owner',    1);
    $mkUser('editor',  $emails['editor'],  'SM Editor',  'editor',   1);
    $mkUser('viewer',  $emails['viewer'],  'SM Viewer',  'viewer',   1);
    // is_active = 0 -> register.php akisi tamamlanmamis hesap = "bekleyen davet"
    $mkUser('pending', $emails['pending'], 'SM Pending', 'commenter', 0);
    $mkUser('outside', $emails['outside'], 'SM Outside', null,       1);   // ekipte DEGIL
    $mkUser('free',    $emails['free'],    'SM Free',    null,       1);   // eklenecek aday

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'ShareModal Base', ':u' => $userIds['owner']));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => 'Tablo'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $ownerCookie = login($emails['owner']);
    $editorCookie = login($emails['editor']);
    $viewerCookie = login($emails['viewer']);
    $outsideCookie = login($emails['outside']);
    check('Girisler yapildi', $ownerCookie && $editorCookie && $viewerCookie && $outsideCookie);

    // =====================================================================
    // A) PAYLASILAN UYE YARDIMCILARI (src/schema.php)
    // =====================================================================
    echo "\n--- A) Paylasilan yardimcilar ---\n";

    $ownerRank = $GLOBALS['BCC_ROLE_RANK']['owner'];
    $editorRank = $GLOBALS['BCC_ROLE_RANK']['editor'];
    $ownerAssignable = bcc_assignable_roles($ownerRank);
    $editorAssignable = bcc_assignable_roles($editorRank);

    $r = bcc_team_member_assign($teamId, $userIds['free'], 'editor', $ownerRank, $ownerAssignable);
    check('A) yeni uye eklendi (created=true)', $r['ok'] && $r['created'] && member_role($teamId, $userIds['free']) === 'editor', json_encode($r));

    $r = bcc_team_member_assign($teamId, $userIds['free'], 'viewer', $ownerRank, $ownerAssignable);
    check('A) mevcut uyenin rolu degisti (created=false)', $r['ok'] && !$r['created'] && member_role($teamId, $userIds['free']) === 'viewer', json_encode($r));

    $r = bcc_team_member_assign($teamId, $userIds['free'], 'owner', $editorRank, $editorAssignable);
    check('A) atanabilir rol whitelist i: editor OWNER atayamaz', !$r['ok'] && member_role($teamId, $userIds['free']) === 'viewer', json_encode($r));

    // Hiyerarsi: editor rutbesiyle bir OWNER'in rolu degistirilemez
    $r = bcc_team_member_assign($teamId, $userIds['owner2'], 'editor', $editorRank, $editorAssignable);
    check('A) hiyerarsi kapisi: rank(hedef) > rank(ben) reddediliyor',
        !$r['ok'] && $r['error'] === 'Bu kullaniciyi yonetme yetkiniz yok.' || (!$r['ok'] && member_role($teamId, $userIds['owner2']) === 'owner'),
        json_encode($r));

    $r = bcc_team_member_assign($teamId, 999999, 'viewer', $ownerRank, $ownerAssignable);
    check('A) var olmayan kullanici reddediliyor', !$r['ok'], json_encode($r));

    // is_active = 0 olan hesap ATANAMAZ (team_members.php ile AYNI kural)
    $r = bcc_team_member_assign($teamId, $userIds['pending'], 'viewer', $ownerRank, $ownerAssignable);
    check('A) pasif/dogrulanmamis hesap atanamiyor', !$r['ok'], json_encode($r));

    // Cikarma kurallari
    $rm = bcc_team_member_remove_many($teamId, array($userIds['owner']), $userIds['owner'], $ownerRank);
    check('A) kendini cikaramaz', count($rm['removed']) === 0 && $rm['skipped'] === 1);

    $rm = bcc_team_member_remove_many($teamId, array($userIds['owner2']), $userIds['owner'], $editorRank);
    check('A) yetki disi rutbe atlaniyor', count($rm['removed']) === 0 && member_role($teamId, $userIds['owner2']) === 'owner');

    $rm = bcc_team_member_remove_many($teamId, array($userIds['free']), $userIds['owner'], $ownerRank);
    check('A) cikarma calisiyor', $rm['removed'] === array($userIds['free']) && member_role($teamId, $userIds['free']) === null);

    // Son owner korumasi: owner2'yi cikar, sonra tek kalan owner'i cikarmayi dene
    bcc_team_member_remove_many($teamId, array($userIds['owner2']), $userIds['owner'], $ownerRank);
    $rm = bcc_team_member_remove_many($teamId, array($userIds['owner']), $userIds['editor'], $ownerRank);
    check('A) SON owner cikarilamaz', count($rm['removed']) === 0 && member_role($teamId, $userIds['owner']) === 'owner');
    // owner2'yi geri koy (sonraki testler icin)
    bcc_team_member_assign($teamId, $userIds['owner2'], 'owner', $ownerRank, $ownerAssignable);

    $msg = bcc_team_member_remove_message(array('removed' => array(1), 'skipped' => 0));
    check('A) tek kisi mesaji', $msg['success'] === 'Ekipten cikarildi.' || $msg['success'] === 'Ekipten çıkarıldı.', json_encode($msg));
    $msg = bcc_team_member_remove_message(array('removed' => array(), 'skipped' => 2));
    check('A) hicbiri cikarilamadi -> error', $msg['error'] !== null && $msg['success'] === null);

    // =====================================================================
    // B) PAYLOAD SOZLESMESI
    // =====================================================================
    echo "\n--- B) bcc_share_modal_payload ---\n";

    // NOT: payload current_user() cagirir; "ben kimim" bilgisi $_SESSION'dan
    // gelir (oturum betigin basinda baslatildi). HTTP tarafi bundan bagimsiz,
    // kendi cerezleriyle calisiyor.
    $_SESSION['user_id'] = $userIds['owner'];
    current_user(true);
    $ownerPayload = bcc_share_modal_payload($teamId, 'owner');

    check('B) can_manage owner icin true', $ownerPayload['can_manage'] === true);
    check('B) aktif uyeler collaborators ta',
        count($ownerPayload['collaborators']) === 4, // owner, owner2, editor, viewer
        'sayi=' . count($ownerPayload['collaborators']));
    check('B) is_active = 0 olan uye PENDING te',
        count($ownerPayload['pending']) === 1 && $ownerPayload['pending'][0]['email'] === $emails['pending'],
        json_encode($ownerPayload['pending']));
    check('B) owner tum rolleri atayabiliyor', count($ownerPayload['assignable_roles']) === 4);

    $selfRow = null; $editorRow = null;
    foreach ($ownerPayload['collaborators'] as $row) {
        if ($row['id'] === $userIds['owner']) { $selfRow = $row; }
        if ($row['id'] === $userIds['editor']) { $editorRow = $row; }
    }
    check('B) kendi satiri: rol degistirilebilir ama CIKARILAMAZ',
        $selfRow && $selfRow['is_self'] === true && $selfRow['can_change_role'] === true && $selfRow['can_remove'] === false,
        json_encode($selfRow));
    check('B) baskasinin satiri: ikisi de acik', $editorRow['can_change_role'] === true && $editorRow['can_remove'] === true);
    check('B) satirda ad/e-posta/bas harf/rol etiketi var',
        $editorRow['name'] === 'SM Editor' && $editorRow['email'] === $emails['editor']
        && $editorRow['initial'] !== '' && $editorRow['role_label'] === 'Editor',
        json_encode($editorRow));

    $_SESSION['user_id'] = $userIds['viewer'];
    current_user(true);
    $viewerPayload = bcc_share_modal_payload($teamId, 'viewer');
    check('B) viewer: can_manage false', $viewerPayload['can_manage'] === false);
    check('B) viewer: atanabilir rol YOK (bos dizi)', $viewerPayload['assignable_roles'] === array());
    $allReadonly = true;
    foreach ($viewerPayload['collaborators'] as $row) {
        if ($row['can_change_role'] || $row['can_remove']) { $allReadonly = false; }
    }
    check('B) viewer: HER satir salt-okunur', $allReadonly);
    check('B) viewer: listeyi yine de GORUYOR', count($viewerPayload['collaborators']) === 4);

    $_SESSION['user_id'] = $userIds['editor'];
    current_user(true);
    $editorPayload = bcc_share_modal_payload($teamId, 'editor');
    check('B) editor: can_manage false (uye yonetimi owner-only)', $editorPayload['can_manage'] === false);
    $editorAllReadonly = true;
    foreach ($editorPayload['collaborators'] as $row) {
        if ($row['can_change_role'] || $row['can_remove']) { $editorAllReadonly = false; }
    }
    check('B) editor: HER satir salt-okunur', $editorAllReadonly);

    unset($_SESSION['user_id']);
    current_user(true);

    // =====================================================================
    // C) UC NOKTALAR
    // =====================================================================
    echo "\n--- C) api/team_member_assign.php + team_member_remove.php ---\n";

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $ownerCookie);
    check('C) grid.php 200 (owner)', $g['status'] === 200, 'HTTP ' . $g['status']);
    $ownerCsrf = extract_csrf_meta($g['body']);

    $vg = http_request('GET', '/grid.php?table_id=' . $tableId, $viewerCookie);
    $viewerCsrf = extract_csrf_meta($vg['body']);
    $eg = http_request('GET', '/grid.php?table_id=' . $tableId, $editorCookie);
    $editorCsrf = extract_csrf_meta($eg['body']);

    // GET reddi
    $r = http_request('GET', '/api/team_member_assign.php', $ownerCookie);
    check('C) GET -> 405', $r['status'] === 405, 'HTTP ' . $r['status']);

    // CSRF
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'viewer',
    ));
    check('C) CSRF yoksa 403', $r['status'] === 403, 'HTTP ' . $r['status']);

    // Ekip disindaki kullanici (KVKK izolasyonu)
    $og = http_request('GET', '/dashboard.php', $outsideCookie);
    $outsideCsrf = extract_csrf_meta($og['body']);
    if ($outsideCsrf !== null) {
        $r = http_request('POST', '/api/team_member_assign.php', $outsideCookie, array(
            'csrf_token' => $outsideCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'viewer',
        ));
        check('C) ekip disi kullanici 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    } else {
        check('C) ekip disi kullanici 403 (csrf meta okunamadi, atlandi)', true);
    }

    // Viewer / editor: uye yonetimi yok
    $r = http_request('POST', '/api/team_member_assign.php', $viewerCookie, array(
        'csrf_token' => $viewerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'viewer',
    ));
    check('C) viewer assign -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);

    $r = http_request('POST', '/api/team_member_assign.php', $editorCookie, array(
        'csrf_token' => $editorCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'viewer',
    ));
    check('C) editor assign -> 403 (uye yonetimi owner-only)', $r['status'] === 403, 'HTTP ' . $r['status']);
    check('C) reddedilen istekler DB yi degistirmedi', member_role($teamId, $userIds['free']) === null);

    $r = http_request('POST', '/api/team_member_remove.php', $viewerCookie, array(
        'csrf_token' => $viewerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['editor'],
    ));
    check('C) viewer remove -> 403', $r['status'] === 403 && member_role($teamId, $userIds['editor']) === 'editor', 'HTTP ' . $r['status']);

    // Owner: e-posta ile davet
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'email' => $emails['free'], 'role' => 'editor',
    ));
    $data = json_decode($r['body'], true);
    check('C) owner E-POSTA ile ekleyebiliyor',
        $r['status'] === 200 && isset($data['ok']) && $data['ok'] && member_role($teamId, $userIds['free']) === 'editor',
        $r['body']);
    check('C) yanit GUNCEL listeyi doner',
        isset($data['collaborators']) && count($data['collaborators']) === 5
        && isset($data['pending']) && count($data['pending']) === 1,
        isset($data['collaborators']) ? count($data['collaborators']) : 'YOK');
    check('C) yanitta mesaj var', isset($data['message']) && $data['message'] !== '');

    // Bilinmeyen e-posta
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'email' => 'yok@bcc-test.local', 'role' => 'viewer',
    ));
    $data = json_decode($r['body'], true);
    check('C) hesabi olmayan e-posta -> 404 + aciklayici mesaj',
        $r['status'] === 404 && isset($data['error']) && strpos($data['error'], 'hesap') !== false,
        $r['body']);

    // Dogrulanmamis hesabin e-postasi
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'email' => $emails['pending'], 'role' => 'viewer',
    ));
    check('C) dogrulanmamis hesap -> 422', $r['status'] === 422, 'HTTP ' . $r['status'] . ' ' . $r['body']);

    // Rol degistirme (user_id yolu)
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'commenter',
    ));
    check('C) rol degistirme calisiyor',
        $r['status'] === 200 && member_role($teamId, $userIds['free']) === 'commenter', $r['body']);

    // Gecersiz rol
    $r = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'], 'role' => 'superuser',
    ));
    check('C) gecersiz rol -> 422 + DB degismedi',
        $r['status'] === 422 && member_role($teamId, $userIds['free']) === 'commenter', $r['body']);

    // Kendini cikarma
    $r = http_request('POST', '/api/team_member_remove.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['owner'],
    ));
    check('C) kendini cikarma -> 422 + hala uye',
        $r['status'] === 422 && member_role($teamId, $userIds['owner']) === 'owner', $r['body']);

    // Cikarma
    $r = http_request('POST', '/api/team_member_remove.php', $ownerCookie, array(
        'csrf_token' => $ownerCsrf, 'team_id' => $teamId, 'user_id' => $userIds['free'],
    ));
    $data = json_decode($r['body'], true);
    check('C) cikarma calisiyor + guncel liste donuyor',
        $r['status'] === 200 && member_role($teamId, $userIds['free']) === null
        && isset($data['collaborators']) && count($data['collaborators']) === 4,
        $r['body']);

    $auditCount = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM audit_log WHERE team_id = :t AND action IN ('team_member.assign','team_member.role_change','team_member.remove')",
        array(':t' => $teamId)
    );
    check('C) audit satirlari yazildi', $auditCount >= 3, 'sayi=' . $auditCount);

    // =====================================================================
    // D) grid.php RENDER — yonlendirme GITTI, modal GELDI
    // =====================================================================
    echo "\n--- D) grid.php render ---\n";

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $ownerCookie);
    $html = $g['body'];

    check('D) "N kisinin erisimi var" ARTIK <a href> DEGIL',
        preg_match('#<a[^>]*class="collab-popover-people"#', $html) === 0);
    check('D) modal tetikleyicisi <button data-share-modal-open>',
        preg_match('#<button[^>]*class="collab-popover-people"[^>]*data-share-modal-open#', $html) === 1);
    check('D) popup ta team_members.php ye POST eden atama formu KALMADI',
        strpos($html, 'class="collab-popover-assign"') === false);
    check('D) modal iskeleti sayfada', strpos($html, 'id="gs-share-overlay"') !== false
        && strpos($html, 'class="gs-share-modal"') !== false);
    check('D) modal varsayilan olarak KAPALI (hidden)',
        preg_match('#id="gs-share-overlay" hidden#', $html) === 1);
    check('D) kapat (X) butonu var', strpos($html, 'id="gs-share-close"') !== false);
    check('D) iki sekme (Katilimcilar / Bekleyen davetler)',
        strpos($html, 'data-share-tab="collaborators"') !== false
        && strpos($html, 'data-share-tab="pending"') !== false);
    check('D) davet kutusu (e-posta + rol + buton) var',
        strpos($html, 'data-share-invite-email') !== false
        && strpos($html, 'data-share-invite-role') !== false
        && strpos($html, 'data-share-invite-btn') !== false);
    check('D) payload sayfaya gomuluyor', strpos($html, 'var BCC_SHARE_MODAL = {') !== false);
    check('D) share-modal.js dahil (dismissable-panel.js ten SONRA)',
        preg_match('#<script src="/assets/share-modal\.js\?v=\d+" defer>#', $html) === 1
        && strpos($html, 'dismissable-panel.js') < strpos($html, 'share-modal.js'));
    check('D) "Tum uye ayarlari" bagi HALA team_members.php ye gidiyor (ekran kaybolmadi)',
        strpos($html, 'class="gs-share-foot-link" href="/team_members.php?team_id=' . $teamId . '"') !== false);
    check('D) owner: davet onerileri (datalist) ekipte OLMAYAN kullanicilari iceriyor',
        strpos($html, $emails['outside']) !== false && strpos($html, $emails['editor']) === false
        || strpos($html, 'BCC_SHARE_CANDIDATES') !== false,
        'aday listesi');

    // Payload'in HTML icine dogru gomuldugu (owner)
    preg_match('/var BCC_SHARE_MODAL = (\{.*?\});\n/s', $html, $pm);
    $embedded = isset($pm[1]) ? json_decode($pm[1], true) : null;
    check('D) gomulu payload cozulebiliyor', is_array($embedded), isset($pm[1]) ? substr($pm[1], 0, 120) : 'YOK');
    check('D) gomulu payload owner icin can_manage=true', $embedded && $embedded['can_manage'] === true);
    check('D) gomulu payload pending listesini tasiyor', $embedded && count($embedded['pending']) === 1);

    // Viewer render
    $vg = http_request('GET', '/grid.php?table_id=' . $tableId, $viewerCookie);
    $vhtml = $vg['body'];
    check('D) viewer: modal YINE basiliyor (liste gormek yetki gerektirmez)',
        strpos($vhtml, 'id="gs-share-overlay"') !== false);
    preg_match('/var BCC_SHARE_MODAL = (\{.*?\});\n/s', $vhtml, $vpm);
    $vEmbedded = isset($vpm[1]) ? json_decode($vpm[1], true) : null;
    check('D) viewer: gomulu payload can_manage=false + assignable_roles bos',
        $vEmbedded && $vEmbedded['can_manage'] === false && $vEmbedded['assignable_roles'] === array(),
        isset($vpm[1]) ? substr($vpm[1], 0, 120) : 'YOK');
    check('D) viewer: "Katilimci ekle" butonu HIC basilmiyor',
        strpos($vhtml, 'collab-popover-add-btn') === false);

    // =====================================================================
    // E) team_members.php REGRESYONU (ayni yardimcilara tasindi)
    // =====================================================================
    echo "\n--- E) team_members.php regresyonu ---\n";

    $tm = http_request('GET', '/team_members.php?team_id=' . $teamId, $ownerCookie);
    check('E) sayfa 200', $tm['status'] === 200, 'HTTP ' . $tm['status']);
    $tmCsrf = extract_csrf_field($tm['body']);

    $r = http_request('POST', '/team_members.php?team_id=' . $teamId, $ownerCookie, array(
        'csrf_token' => $tmCsrf, 'action' => 'assign', 'user_id' => $userIds['free'], 'role' => 'viewer',
    ));
    check('E) tam sayfa atama HALA calisiyor',
        $r['status'] === 200 && member_role($teamId, $userIds['free']) === 'viewer'
        && strpos($r['body'], 'Atama kaydedildi.') !== false,
        'HTTP ' . $r['status']);

    $r = http_request('POST', '/team_members.php?team_id=' . $teamId, $ownerCookie, array(
        'csrf_token' => $tmCsrf, 'action' => 'remove', 'user_id' => $userIds['free'],
    ));
    check('E) tam sayfa cikarma HALA calisiyor',
        $r['status'] === 200 && member_role($teamId, $userIds['free']) === null, 'HTTP ' . $r['status']);

    $r = http_request('POST', '/team_members.php?team_id=' . $teamId, $viewerCookie, array(
        'csrf_token' => $viewerCsrf, 'action' => 'assign', 'user_id' => $userIds['free'], 'role' => 'viewer',
    ));
    check('E) viewer tam sayfa POST -> 403 (degismedi)', $r['status'] === 403, 'HTTP ' . $r['status']);

    $r = http_request('POST', '/team_members.php?team_id=' . $teamId, $ownerCookie, array(
        'csrf_token' => $tmCsrf, 'action' => 'remove_bulk', 'user_ids' => array($userIds['owner'], $userIds['editor']),
    ));
    check('E) toplu cikarma HALA calisiyor (kendisi atlanir, digeri cikar)',
        member_role($teamId, $userIds['owner']) === 'owner' && member_role($teamId, $userIds['editor']) === null,
        'HTTP ' . $r['status']);
    // editor'u geri koy
    bcc_team_member_assign($teamId, $userIds['editor'], 'editor', $ownerRank, $ownerAssignable);

    // =====================================================================
    // F) KOD TEKRARI YOK
    // =====================================================================
    // Bu bolum KAYNAK duzeyinde dogruluyor. Modalin DAVRANISI ayrica tarayicida
    // dogrulandi (scripts/_share_modal_browse_fixture.php ile kurulan GECICI
    // ekip/base uzerinde, gercek veriye dokunmadan):
    //   - "N kisinin erisimi var" tiklamasi SAYFADAN CIKMADAN modali aciyor,
    //     popover kapaniyor; 3 katilimci + 1 bekleyen, sekme sayaclari 3/1,
    //   - "Bekleyen davetler" sekmesi is_active=0 hesabi gosteriyor,
    //   - kapanma: Escape / backdrop / X calisiyor; modalin ICINE tiklamak
    //     KAPATMIYOR,
    //   - davet: e-posta + rol -> liste 4'e cikti, durum satiri "Katilimci
    //     eklendi.", arkadaki popover etiketi de "4 kisinin erisimi var"a
    //     tazelendi,
    //   - rol <select> degisimi DB'ye yazildi (editor -> commenter, F5 sonrasi
    //     da commenter),
    //   - "Cikar" -> liste 3'e dondu, "Ekipten cikarildi." mesaji,
    //   - viewer olarak: davet kutusu YOK, rol <select> ve "Cikar" YOK (0/0),
    //     roller duz metin, gerekce notu gorunuyor, liste yine de goruluyor,
    //   - konsolda hata yok.
    echo "\n--- F) Tek kaynak ---\n";
    $tmSrc = file_get_contents(__DIR__ . '/../public/team_members.php');
    $assignSrc = file_get_contents(__DIR__ . '/../public/api/team_member_assign.php');
    $removeSrc = file_get_contents(__DIR__ . '/../public/api/team_member_remove.php');
    $gridSrc = file_get_contents(__DIR__ . '/../public/grid.php');
    $modalJs = file_get_contents(__DIR__ . '/../public/assets/share-modal.js');
    $partial = file_get_contents(__DIR__ . '/../src/partials/share_modal.php');

    check('F) team_members.php artik INSERT/DELETE yazmiyor (yardimciya tasindi)',
        stripos($tmSrc, 'ON DUPLICATE KEY UPDATE') === false
        && stripos($tmSrc, 'DELETE FROM team_members') === false);
    check('F) her iki uc nokta da paylasilan yardimciyi cagiriyor',
        strpos($assignSrc, 'bcc_team_member_assign(') !== false
        && strpos($removeSrc, 'bcc_team_member_remove_many(') !== false);
    check('F) team_members.php de AYNI yardimcilari cagiriyor',
        strpos($tmSrc, 'bcc_team_member_assign(') !== false
        && strpos($tmSrc, 'bcc_team_member_remove_many(') !== false);
    // Uc noktalar rol esigini KENDI yazmiyor ("=== 'owner'" gibi), yetenek
    // fonksiyonundan okuyor (src/auth.php yetenek haritasi disiplini).
    check('F) uc noktalar owner kapisini bcc_can_manage_members ile aciyor',
        strpos($assignSrc, 'if (!bcc_can_manage_members($myRole))') !== false
        && strpos($removeSrc, 'if (!bcc_can_manage_members($myRole))') !== false
        && strpos($assignSrc, "=== 'owner'") === false
        && strpos($removeSrc, "=== 'owner'") === false);
    check('F) yanit payload i TEK fonksiyondan (bcc_share_modal_payload)',
        strpos($assignSrc, 'bcc_share_modal_payload(') !== false
        && strpos($removeSrc, 'bcc_share_modal_payload(') !== false
        && strpos($gridSrc, 'bcc_share_modal_payload(') !== false);
    check('F) liste sablonu YALNIZCA JS te (partial satir basmiyor)',
        strpos($partial, 'gs-share-row') === false && strpos($modalJs, 'gs-share-row') !== false);

    // Yorumlar haric: istemci rutbe/rol esigi HESAPLAMIYOR, sunucudan gelen
    // can_change_role / can_remove bayraklarini okuyor.
    $modalJsCode = preg_replace('#(^|\n)\s*//[^\n]*#', '', $modalJs);
    check('F) JS rutbeyi yeniden yorumlamiyor (rank/rol esigi yok)',
        stripos($modalJsCode, 'ROLE_RANK') === false
        && stripos($modalJsCode, "'owner'") === false
        && strpos($modalJsCode, 'can_change_role') !== false
        && strpos($modalJsCode, 'can_remove') !== false);

    // Escape + backdrop ortak yardimcidan gelir; JS'te ikinci bir Escape
    // dinleyicisi YOK (keydown yalnizca davet kutusundaki Enter icin).
    // DIKKAT: "escapeHtml" yardimcisi da "escape" iceriyor — aranan sey tus
    // KARSILASTIRMASI ("Escape" tusunun kendi dinleyicisi), metin degil.
    check('F) kapanma ortak yardimcidan (bcc_bindDismissable)',
        strpos($modalJs, 'window.bcc_bindDismissable') !== false
        && strpos($modalJsCode, "'Escape'") === false);
    check('F) backdrop sinifi yeniden kullaniliyor (.gs-view-desc-overlay)',
        strpos($partial, 'gs-view-desc-overlay gs-share-overlay') !== false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
