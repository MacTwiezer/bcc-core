<?php

/* Calisma alani (ekip) resmi (2026-09-15). Profil fotografiyla ayni desen:
   veritabaninda kolon YOK, dosya ekip id'si ile adlandiriliyor, varligi =
   resmin varligi — deploy'da DDL gerekmiyor. Dosya web kokunun DISINDA durur,
   yalnizca api/team_image.php uyelik kontrolunden gecirerek sunar. */
function bcc_team_image_storage_dir()
{
    return __DIR__ . '/../storage/team_images';
}

function bcc_team_image_path($teamId)
{
    return bcc_team_image_storage_dir() . '/t' . (int) $teamId;
}

/* KVKK ekip izolasyonu: resmi yalnizca ekibin uyeleri (ve butun ekipleri
   goren platform admini) gorebilir. */
function bcc_can_view_team_image($teamId)
{
    $teamId = (int) $teamId;

    return $teamId > 0 && in_array($teamId, array_map('intval', current_user_team_ids()), true);
}

/* Istek basina onbellek: kenar cubugu, ana sayfa ve calisma alanlari sayfasi
   ayni ekibi birkac kez basiyor. $refresh yalnizca dosyayi ayni istekte
   yazan/silen uclar icin. Yetkisi olmayana null doner. */
function bcc_team_image_url($teamId, $refresh = false)
{
    static $memo = array();

    $teamId = (int) $teamId;
    if (!bcc_can_view_team_image($teamId)) {
        return null;
    }
    if (!$refresh && array_key_exists($teamId, $memo)) {
        return $memo[$teamId];
    }

    $path = bcc_team_image_path($teamId);
    clearstatcache(true, $path);
    $mtime = @filemtime($path);

    $memo[$teamId] = ($mtime === false)
        ? null
        : '/api/team_image.php?team_id=' . $teamId . '&v=' . $mtime . '-' . (int) @filesize($path);

    return $memo[$teamId];
}

/* Ekip resim kutusu: resim varsa <img>, yoksa $fallbackHtml (cagiranin kendi
   ikonu/noktasi — guvenilir, sabit HTML). Ikisi de basiliyor, CSS hangisinin
   gorunecegini .has-image ile seciyor; boylece arayuz yukleme/kaldirmadan
   sonra sayfayi yenilemeden kutuyu degistirebiliyor (data-team-face). */
function bcc_team_face_html($teamId, $class, $fallbackHtml)
{
    $teamId = (int) $teamId;
    $url = bcc_team_image_url($teamId);

    $html = '<span class="bcc-team-face ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ($url !== null ? ' has-image' : '') . '" data-team-face="' . $teamId . '">';
    $html .= ($url !== null)
        ? '<img class="bcc-team-face-img" alt="" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        : '<img class="bcc-team-face-img" alt="" hidden>';
    $html .= '<span class="bcc-team-face-fallback">' . $fallbackHtml . '</span>';
    $html .= '</span>';

    return $html;
}
