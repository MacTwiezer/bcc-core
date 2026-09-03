<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'rtlink.owner@bcc-test.local');
define('TEST_PASS', 'RtLink!2026');
define('REAL_BASE_ID', 15);

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
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

function js_code_only($js)
{
    $js = preg_replace('#/\*.*?\*/#s', '', $js);
    return preg_replace('#^\s*//.*$#m', '', $js);
}

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => OWNER_EMAIL)
    ), 'id');
    foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));
};

$cleanup();
register_shutdown_function($cleanup);

if ((int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE id = :b', array(':b' => REAL_BASE_ID)) !== 1) {
    echo 'HATA: gercek base (id ' . REAL_BASE_ID . ') bulunamadi; dokunulmazlik nobetcisi anlamsiz olurdu.' . PHP_EOL;
    exit(1);
}

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $assetsDir = __DIR__ . '/../public/assets';
    $gridJsRaw = file_get_contents($assetsDir . '/grid.js');
    $gridJs    = js_code_only($gridJsRaw);
    $styleCss  = css_rules(file_get_contents($assetsDir . '/style.css'));
    $themeCss  = css_rules(file_get_contents($assetsDir . '/theme.css'));

    echo "--- A) window.prompt yerine satir ici URL cubugu ---\n";

    check('A) grid.js te window.prompt( CAGRISI KALMADI',
        strpos($gridJs, 'window.prompt(') === false);

    check('A) link hatasi window.alert ILE DEGIL satir ici gosteriliyor',
        strpos($gridJs, "window.alert('Link") === false
        && strpos($gridJs, 'linkError.textContent =') !== false
        && strpos($gridJs, 'linkError.hidden = false;') !== false);
    check('A) URL girisi var (type=url, placeholder https://)',
        strpos($gridJs, "linkInput.type = 'url';") !== false
        && strpos($gridJs, "linkInput.placeholder = 'https://';") !== false);
    check('A) "Ekle" ve "Kaydet" buton etiketleri var',
        strpos($gridJs, "linkAddBtn.textContent = 'Ekle';") !== false
        && strpos($gridJs, "editingAnchor ? 'Kaydet' : 'Ekle'") !== false);
    check('A) "Iptal" carpi butonu var',
        strpos($gridJs, "linkCancelBtn.textContent = '×';") !== false
        && strpos($gridJs, "linkCancelBtn.title = 'İptal';") !== false);

    $posToolbar  = strpos($gridJs, 'popover.appendChild(toolbar);');
    $posLinkBar  = strpos($gridJs, 'popover.appendChild(linkBar);');
    $posEditable = strpos($gridJs, 'popover.appendChild(editable);');
    check('A) cubuk arac cubugu ile editor ARASINA ekleniyor',
        $posToolbar !== false && $posLinkBar !== false && $posEditable !== false
        && $posToolbar < $posLinkBar && $posLinkBar < $posEditable,
        "toolbar={$posToolbar} linkBar={$posLinkBar} editable={$posEditable}");
    check('A) https?:// disi sema hala REDDEDILIYOR (istemci tarafi on kontrol)',
        strpos($gridJs, "/^https?:\\/\\//i.test(url)") !== false);
    check('A) cubuk buyuyunce popover YENIDEN konumlaniyor (tasma)',
        substr_count($gridJs, 'positionPopover();') >= 3,
        'cagri sayisi=' . substr_count($gridJs, 'positionPopover();'));

    echo "\n--- B) Secim (Range) ve odak korunmasi ---\n";
    check('B) cubuk acilirken secim SAKLANIYOR',
        strpos($gridJs, 'savedRange = editableSelectionRange();') !== false);
    check('B) ekleme aninda secim GERI YUKLENIYOR',
        preg_match('/if \(savedRange\) \{\s*selectRange\(savedRange\);/', $gridJs) === 1);
    check('B) secimin editable ICINDE oldugu dogrulaniyor (disariya yazilmaz)',
        strpos($gridJs, 'editable.contains(range.commonAncestorContainer)') !== false);
    check('B) secili metin <a> ile SARILIYOR (execCommand createLink korundu)',
        strpos($gridJs, "document.execCommand('createLink', false, url)") !== false);
    check('B) secim YOKSA URL nin kendisi link metni olur',
        strpos($gridJs, 'anchor.textContent = url;') !== false
        && strpos($gridJs, 'sel.getRangeAt(0).insertNode(anchor);') !== false);
    check('B) ekleme sonrasi imlec linkin ARKASINA aliniyor',
        strpos($gridJs, 'afterRange.setStartAfter(anchor);') !== false);
    check('B) islem sonunda odak editore geri veriliyor',
        preg_match('/function closeLinkBar\(\) \{[\s\S]{0,400}editable\.focus\(\);/', $gridJs) === 1);
    check('B) butonlar mousedown ta odagi CALMIYOR (preventDefault)',
        preg_match('/\[linkAddBtn, linkCancelBtn\]\.forEach[\s\S]{0,200}e\.preventDefault\(\);/', $gridJs) === 1);

    check('B) Enter ekler, Escape yalnizca cubugu kapatir',
        preg_match(
            "/linkInput\.addEventListener\('keydown'[\s\S]{0,600}applyLink\(\);[\s\S]{0,300}e\.stopPropagation\(\);\s*cancelLinkBar\(\);/",
            $gridJs
        ) === 1);

    check('B) mevcut link duzenlenirken href guncelleniyor (ikinci <a> yok)',
        strpos($gridJs, "editingAnchor.setAttribute('href', url);") !== false);

    echo "\n--- C) Grid hucresinde link tiklamasi ---\n";
    check('C) .rich-text-view icindeki <a> tiklamasi ozel ele aliniyor',
        strpos($gridJs, "e.target.closest('.rich-text-view a')") !== false);
    check('C) yeni sekmede acilir (target=_blank + rel=noopener)',
        strpos($gridJs, "richLink.target = '_blank';") !== false
        && strpos($gridJs, "richLink.rel = 'noopener noreferrer';") !== false);

    check('C) link tiklamasi duzenleyiciyi ACMIYOR (td.editable ten ONCE return)',
        strpos($gridJs, "e.target.closest('.rich-text-view a')")
        < strpos($gridJs, "var td = e.target.closest('td.editable');"));
    check('C) startRichTextEdit hala baglanmis (diger tiklamalar duzenlemeyi acar)',
        strpos($gridJs, 'startRichTextEdit(td);') !== false);

    echo "\n--- D) CSS: link gorunumu + cubuk ---\n";
    $linkRule = null;
    if (preg_match('/\.rich-text-view a,[^{]*\{([^}]*)\}/s', $styleCss, $m)) { $linkRule = $m[1]; }
    check('D) link kurali hem hucreyi hem editoru kapsiyor',
        $linkRule !== null && strpos($styleCss, '.richtext-editable a') !== false);
    check('D) link rengi token uzerinden (--bcc-link)',
        $linkRule !== null && strpos($linkRule, 'color: var(--bcc-link);') !== false);
    check('D) alti cizili',
        $linkRule !== null && strpos($linkRule, 'text-decoration: underline;') !== false);
    check('D) cursor: pointer',
        $linkRule !== null && strpos($linkRule, 'cursor: pointer;') !== false);
    check('D) hover da alti cizgi KAYBOLMUYOR, kalinlasiyor',
        preg_match('/\.rich-text-view a:hover,[^{]*\{[^}]*text-decoration-thickness: 2px;/s', $styleCss) === 1
        && preg_match('/\.rich-text-view a:hover,[^{]*\{[^}]*text-decoration: none;/s', $styleCss) === 0);

    check('D) cıplak "td a" secicisi YOK (chip/ikon <a> lari bozulmasin)',
        preg_match('/(^|[\s,])td a\s*[,{]/m', $styleCss) === 0);

    check('D) secici ozgullugu body.gs-body a kuralini GECIYOR (iki sinif)',
        strpos($styleCss, '.cell-view.rich-text-view a') !== false
        && strpos($styleCss, '.richtext-popover .richtext-editable a') !== false);
    check('D) grid-shell.css teki genis "a" reset i hala yerinde (kural degismedi)',
        strpos(css_rules(file_get_contents($assetsDir . '/grid-shell.css')), 'body.gs-body a {') !== false);
    check('D) cubuk stilleri var',
        strpos($styleCss, '.richtext-link-bar') !== false
        && strpos($styleCss, '.richtext-link-input') !== false
        && strpos($styleCss, '.richtext-link-error') !== false);

    check('D) .richtext-link-bar[hidden] gercekten gizliyor',
        preg_match('/\.richtext-link-bar \{([^}]*)\}/s', $styleCss, $mb) === 1
        && strpos($mb[1], 'display:') === false
        && preg_match('/\.richtext-link-bar\[hidden\] \{[^}]*display: none;/s', $styleCss) === 1);
    check('D) .richtext-link-error[hidden] gercekten gizliyor',
        preg_match('/\.richtext-link-error\[hidden\] \{[^}]*display: none;/s', $styleCss) === 1);

    echo "\n--- E) theme.css --bcc-link token'i ---\n";
    check('E) acik temada istenen mavi (#1d4ed8)',
        preg_match('/:root \{[^}]*--bcc-link: #1d4ed8;/s', $themeCss) === 1);
    check('E) koyu tema (data-theme="dark") kendi tonunu tanimliyor',
        preg_match('/:root\[data-theme="dark"\] \{[^}]*--bcc-link: #/s', $themeCss) === 1);
    check('E) "Sistem" koyu tema bloguna da eklendi',
        substr_count($themeCss, '--bcc-link: #7ea9ff;') === 2,
        'sayim=' . substr_count($themeCss, '--bcc-link: #7ea9ff;'));
    check('E) hover token i uc blokta da var',
        substr_count($themeCss, '--bcc-link-hover:') === 3,
        'sayim=' . substr_count($themeCss, '--bcc-link-hover:'));

    echo "\n--- F) Uctan uca: link kaydet + geri oku ---\n";
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'RtLink Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'RichText Link Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Link Tablo'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Notlar', ':ft' => 'long_text'));
    $fieldId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
        array(':t' => $tableId, ':u' => $ownerId));
    $recordId = (int) bcc_last_insert_id();

    $cookie = login(OWNER_EMAIL);
    check('F) Giris yapildi', $cookie !== null);

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('F) grid.php 200', $g['status'] === 200, 'HTTP ' . $g['status']);
    $csrf = extract_csrf_meta($g['body']);

    $editorHtml = 'Detaylar <a href="https://ornek.com/rapor">burada</a> yer aliyor.';
    $r = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrf, 'record_id' => $recordId, 'field_id' => $fieldId, 'value' => $editorHtml,
    ));
    check('F) cell_update 200', $r['status'] === 200, 'HTTP ' . $r['status']);
    $json = json_decode($r['body'], true);
    $display = is_array($json) && isset($json['display']) ? $json['display'] : '';

    check('F) yanit <a> yi KORUYOR (href bozulmadi)',
        strpos($display, 'href="https://ornek.com/rapor"') !== false, $display);
    check('F) sunucu target="_blank" EKLIYOR (yeni sekme)',
        strpos($display, 'target="_blank"') !== false, $display);
    check('F) sunucu rel="noopener noreferrer" EKLIYOR',
        strpos($display, 'rel="noopener noreferrer"') !== false, $display);
    check('F) link METNI korundu',
        strpos($display, '>burada</a>') !== false, $display);

    $stored = bcc_fetch_column(
        'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
        array(':r' => $recordId, ':f' => $fieldId)
    );
    check('F) DB de de target/rel ile SAKLANDI (grid yeniden yuklendiginde de gecerli)',
        strpos((string) $stored, 'target="_blank"') !== false
        && strpos((string) $stored, 'rel="noopener noreferrer"') !== false,
        (string) $stored);

    $r2 = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrf, 'record_id' => $recordId, 'field_id' => $fieldId,
        'value' => 'Tikla <a href="javascript:alert(1)">buraya</a>',
    ));
    $json2 = json_decode($r2['body'], true);
    $display2 = is_array($json2) && isset($json2['display']) ? $json2['display'] : '';
    check('F) javascript: semasi hala soyuluyor (metin kaliyor, <a> gitmiyor)',
        strpos($display2, 'javascript:') === false
        && strpos($display2, '<a ') === false
        && strpos($display2, 'buraya') !== false,
        $display2);

    $g2 = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('F) hucre .rich-text-view sinifiyla render ediliyor',
        strpos($g2['body'], 'cell-view rich-text-view') !== false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- G) Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
