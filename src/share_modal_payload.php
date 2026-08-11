<?php
// "Paylaş" modalının (src/partials/share_modal.php + assets/share-modal.js)
// VERİ SÖZLEŞMESİ — TEK kaynak.
//
// Aynı yapı iki yerde kullanılıyor:
//   1) grid.php ilk render'da BCC_SHARE_MODAL global'i olarak sayfaya gömer,
//   2) api/team_member_assign.php ve api/team_member_remove.php mutasyondan
//      SONRA aynı yapıyı geri döner.
// İstemci listeyi TEK bir fonksiyonla basıyor (share-modal.js renderLists) —
// yani "ilk render" ile "mutasyon sonrası render" arasında ikinci bir şablon,
// dolayısıyla ayrışma riski YOK. Bu yüzden liste PHP'de HTML olarak
// basılmıyor; partial yalnızca değişmeyen iskeleti (başlık, sekmeler, davet
// formu) taşıyor.
//
// KRİTİK: satır başına yetki kararları (rolü değiştirilebilir mi, çıkarılabilir
// mi) BURADA, sunucuda hesaplanır ve bayrak olarak gönderilir. İstemci
// BCC_ROLE_RANK'i yeniden yorumlamaz — arayüzün gizlediği ile uçnoktanın
// reddettiği (bcc_team_member_assign / bcc_team_member_remove_many) ASLA
// ayrışmasın diye. Bayraklar yalnızca GÖRSEL; asıl kapı her zaman uçnoktadadır.

// Ekipte "bekleyen davet" ayrı bir tablo DEĞİL: bu uygulamada hesap akışı
// register.php (is_active = 0 + email_verify_token) -> verify_email.php
// (is_active = 1) şeklinde. Yani ekibe eklenmiş ama hesabını henüz
// etkinleştirmemiş bir kullanıcı tam olarak "davet gönderildi, kabul
// edilmedi" durumudur ve modalda "Bekleyen davetler" sekmesinde görünür.
// (team_members.php aynı satırları tek listede "Pasif" rozetiyle gösteriyor.)
function bcc_share_modal_payload($teamId, $myRole)
{
    $teamId = (int) $teamId;
    $myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];
    $canManage = bcc_can_manage_members($myRole);
    $currentUser = current_user();
    $myUserId = (int) $currentUser['id'];

    // Yetkisi olmayan için BOŞ dizi — modal rol <select>'ini zaten basmaz, bu
    // ikinci savunma katmanı (grid.php'nin $shareAssignableRoles'undaki AYNI
    // gerekçe).
    $assignableRoles = $canManage ? bcc_assignable_roles($myRank) : array();
    $assignable = array();
    foreach ($assignableRoles as $r) {
        $assignable[] = array('value' => $r, 'label' => $GLOBALS['BCC_ROLE_LABELS'][$r]);
    }

    $collaborators = array();
    $pending = array();

    foreach (bcc_team_members_with_roles($teamId) as $m) {
        $memberRank = $GLOBALS['BCC_ROLE_RANK'][$m['role']];
        $isSelf = (int) $m['id'] === $myUserId;
        // İki koşul BİRLİKTE: önce yetenek (owner mıyım), sonra hiyerarşi
        // (hedef benden yüksek rütbede mi) — team_members.php'deki $manageable
        // ile AYNI ifade.
        $manageable = $canManage && $memberRank <= $myRank;

        $row = array(
            'id' => (int) $m['id'],
            'name' => $m['full_name'],
            'email' => $m['email'],
            'initial' => bcc_user_initial($m),
            'role' => $m['role'],
            'role_label' => $GLOBALS['BCC_ROLE_LABELS'][$m['role']],
            'is_self' => $isSelf,
            'can_change_role' => $manageable,
            // Kendini çıkarma KOŞULSUZ engelli (bcc_team_member_remove_many
            // ile aynı kural) — buton hiç basılmasın.
            'can_remove' => $manageable && !$isSelf,
        );

        if ((int) $m['is_active'] === 1) {
            $collaborators[] = $row;
        } else {
            $pending[] = $row;
        }
    }

    return array(
        'team_id' => $teamId,
        'can_manage' => $canManage,
        'my_role' => $myRole,
        'my_role_label' => $GLOBALS['BCC_ROLE_LABELS'][$myRole],
        'my_user_id' => $myUserId,
        'assignable_roles' => $assignable,
        'collaborators' => $collaborators,
        'pending' => $pending,
    );
}
