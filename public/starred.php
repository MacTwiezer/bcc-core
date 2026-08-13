<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// KVKK izolasyonu: dashboard.php ile AYNI desen (bkz. orada).
$teams = bcc_fetch_all(
    'SELECT t.id, t.name, m.role
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

$teamIds = array();
foreach ($teams as $t) {
    $teamIds[] = (int) $t['id'];
}

$teamNamesById = array();
$roleByTeamId = array();
foreach ($teams as $t) {
    $teamNamesById[(int) $t['id']] = $t['name'];
    $roleByTeamId[(int) $t['id']] = $t['role'];
}

// Bu sayfanın TAMAMI zaten "kullanıcının yıldızladığı base'ler" — sol
// panelin Starred alt-listesi İLE ana kart grid'i AYNI sorgudan beslenir
// (ikinci bir sorgu YOK). Takımdan ayrılan ama base'i silinmemiş kullanıcı
// için CASCADE devreye girmez; b.team_id IN (...) her seferinde yeniden
// süzer (dashboard.php'deki ana base sorgusuyla aynı ilke).
$bases = array();
$starredBaseIds = array();
if (!empty($teamIds)) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $bases = bcc_fetch_all(
        "SELECT b.id, b.team_id, b.name, b.created_at, al.last_opened
         FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($placeholders) AND b.deleted_at IS NULL
         LEFT JOIN (
             SELECT entity_id, MAX(created_at) AS last_opened
             FROM audit_log
             WHERE action = 'base.open' AND entity_type = 'base'
             GROUP BY entity_id
         ) al ON al.entity_id = b.id
         WHERE usb.user_id = ?
         ORDER BY b.name",
        array_merge($teamIds, array((int) $user['id']))
    );
    foreach ($bases as $b) {
        $starredBaseIds[(int) $b['id']] = true;
    }
}

$starredBases = $bases;

$homeActiveNav = 'starred';
$homePageTitle = 'BCC-Core — Yıldızlılar';
// Dashboard ile AYNI görsel katman: tipografi ölçeği, zemin ve kart cilası
// burada da geçerli olsun (bento ızgarası açılmıyor, bkz. grid çağrısı).
$homeExtraCss = array('home-bento.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Yıldızlılar</h1>
        </div>

        <div class="home-toolbar home-toolbar-end">
            <div class="home-view-toggle">
                <button type="button" class="home-icon-btn" data-view-mode-btn="list" aria-label="Liste görünümü" aria-pressed="false">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 5.5h12M4 10h12M4 14.5h12" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="home-icon-btn" data-view-mode-btn="card" aria-label="Kart görünümü" aria-pressed="true">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="3" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/></svg>
                </button>
            </div>
        </div>

        <?php
        // Dashboard ile AYNI kart bileşeni ve AYNI "N tablo" rozeti — tek
        // GROUP BY sorgusu. Bento KAPALI ($bento=false): burası "yıldızlılar"
        // listesi, öne çıkarılacak tek bir base yok, hepsi eşit ağırlıkta.
        $starredTableCounts = bcc_base_table_counts(array_column($bases, 'id'));
        bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, 'Henüz yıldızladığınız bir base yok.', $roleByTeamId, false, false, $starredTableCounts);
        ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
