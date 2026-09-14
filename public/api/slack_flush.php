<?php

/*
 * Slack toplu bildirimi icin "bosaltma" pingi — 2026-09-08.
 *
 * Tarayici bu uc noktayi iki durumda cagirir (public/assets/grid-slack-flush.js):
 *   1. Kullanici grid sayfasindan ayrilirken (sekmeyi kapatma / baska sayfaya
 *      gecme / sekmeyi arka plana atma) — navigator.sendBeacon ile.
 *   2. Sayfa acik ama bir sure hicbir hucreye dokunulmadiginda.
 *
 * Yani "isini bitirince tek mesaj" davranisini saglayan tetikleyici burasi.
 * Tek basina guvenilmez (tarayici cokebilir, internet gidebilir); emniyet agi
 * olarak ayni bosaltma sonraki her yazma isteginde ve grid/arayuz sayfa
 * yuklemesinde de calisir. Cron/zamanlanmis gorev YOKTUR.
 *
 * Islemin kendisi src/slack.php'deki bcc_slack_flush_table() icinde; bu dosya
 * yalnizca yetki kontrolu yapip onu cagirir.
 */

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

/* Diger API uc noktalariyla ayni yardimci (cells_bulk_update.php:19). */
$table = find_table_or_404($tableId);

/* Bosaltma bir "okuma" degil, kanala mesaj gonderiyor — ama gonderilen sey
   kullanicinin zaten gorebildigi kendi ekibinin verisi. Bu yuzden yazma degil,
   ekibe erisim yetkisi araniyor: izleyici (viewer) rolundeki biri sayfadan
   ayrilirken de bekleyen ozetin gitmesi DOGRU. */
require_team_access($table['team_id']);

/* Bekleme suresi ipucu (2026-09-09). Onceden buradan hic gonderilmiyordu:
   ikinci parametre verilmedigi icin bcc_slack_flush_table() varsayilan 180
   saniyeyi uyguluyordu, yani "ayriliyorum" pingi de bekleme kuralina takiliyor
   ve kullanici cikar cikmaz mesaj GITMIYORDU. Artik istemci hangi olaydan
   geldigini soyluyor:
     pagehide         -> 0  (gercekten ayrildi, hemen gonder)
     visibilitychange -> 30 (sekme degisimi de olabilir, kisa tolerans)
   Deger istemciden geldigi icin sunucuda sinirlanip tam sayiya cevriliyor:
   ust sinir varsayilan bekleme, alt sinir 0. Boylece bir istemci beklemeyi
   UZATAMAZ, yalnizca "artik bekleme" diyebilir — gonderilecek sey zaten
   kullanicinin kendi ekibinin gorebildigi veri. */
$idle = BCC_SLACK_BATCH_IDLE_SECONDS;
if (isset($_POST['idle']) && $_POST['idle'] !== '') {
    $idle = (int) $_POST['idle'];
    if ($idle < 0) {
        $idle = 0;
    }
    if ($idle > BCC_SLACK_BATCH_IDLE_SECONDS) {
        $idle = BCC_SLACK_BATCH_IDLE_SECONDS;
    }
}

$sent = bcc_slack_flush_table((int) $table['id'], $idle);

echo json_encode(array('ok' => true, 'sent' => $sent));
