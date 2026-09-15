<?php

require __DIR__ . '/../../src/bootstrap.php';

require_login();

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;

/* Uye olmayan ile "resmi yok" ayni cevabi aliyor (404): baska bir ekibin
   resmi olup olmadigi bile sizmasin. */
if (!bcc_can_view_team_image($teamId)) {
    http_response_code(404);
    exit;
}

bcc_serve_stored_image(bcc_team_image_path($teamId));
