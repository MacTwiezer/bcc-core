<?php

/*
 * Temsilci durumu nabzi — 2026-10-05.
 *
 * Girisli her sayfa dakikada bir buraya istek atar (public/assets/presence.js).
 * Iki isi var:
 *   1. Sekme acik kaldikca oturumu canli tutar — temsilci beklerken cikisa
 *      dusmez; cikis yalnizca "Cikis yap" ya da tarayici/bilgisayar kapaninca.
 *   2. Temsilcinin o arada fare/klavye kullanip kullanmadigini bildirir
 *      (active=1/0). Kullanmadiysa yalnizca last_seen_at tazelenir ve temsilci
 *      BCC_PRESENCE_PASSIVE_MINUTES sonra "Pasif" gorunur.
 *
 * Durumun tanimi src/auth.php'de (bcc_presence_case_sql).
 */

define('BCC_BACKGROUND_REQUEST', true);

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

if (isset($_POST['active']) && $_POST['active'] === '1') {
    bcc_touch_user_activity(true);
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
