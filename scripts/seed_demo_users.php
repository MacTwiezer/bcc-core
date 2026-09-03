<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

const DEMO_TEAM = 'Demo Calisma Alani';

const DEMO_BASE_MAIN = 'Demo CRM';
const DEMO_BASE_SECOND = 'Demo Proje Plani';
const DEMO_TABLE = 'Musteriler';

$DEMO_LEGACY_EMAILS = array('creator@bcc.local');

$remove = in_array('--remove', $argv, true);
$accounts = bcc_demo_accounts();

if (empty($accounts)) {
    line('HATA: Demo hesap sifresi tanimli degil.');
    line('');
    line('config/app.local.php dosyasina su satiri ekleyin (bu dosya git\'e girmez):');
    line('    $BCC_DEMO_PASSWORD = \'yerel-bir-sifre\';');
    exit(1);
}

function line($msg)
{
    echo $msg . "\n";
}

if ($remove) {
    $team = bcc_fetch_one('SELECT id FROM teams WHERE name = :n LIMIT 1', array('n' => DEMO_TEAM));

    if ($team) {
        $teamId = (int) $team['id'];

        foreach (bcc_fetch_all('SELECT id FROM bases WHERE team_id = :t', array('t' => $teamId)) as $b) {
            bcc_execute('DELETE FROM audit_log WHERE entity_type = :e AND entity_id = :i', array('e' => 'base', 'i' => $b['id']));
        }
        bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
        line('- ekip silindi: ' . DEMO_TEAM);
    }

    $silinecek = array_merge(array_column($accounts, 'email'), $DEMO_LEGACY_EMAILS);
    foreach ($silinecek as $mail) {
        $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e LIMIT 1', array('e' => $mail));
        if ($u) {
            bcc_execute('DELETE FROM audit_log WHERE user_id = :i', array('i' => $u['id']));
            bcc_execute('DELETE FROM users WHERE id = :i', array('i' => $u['id']));
            line('- kullanici silindi: ' . $mail);
        }
    }

    line('Temizlik tamam.');
    exit(0);
}

$team = bcc_fetch_one('SELECT id FROM teams WHERE name = :n LIMIT 1', array('n' => DEMO_TEAM));

if ($team) {
    $teamId = (int) $team['id'];
    line('= ekip zaten var: ' . DEMO_TEAM . ' (#' . $teamId . ')');
} else {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => DEMO_TEAM));
    $teamId = (int) bcc_last_insert_id();
    line('+ ekip olusturuldu: ' . DEMO_TEAM . ' (#' . $teamId . ')');
}

$userIds = array();

foreach ($accounts as $acc) {
    $hash = password_hash($acc['password'], PASSWORD_DEFAULT);
    $existing = bcc_fetch_one('SELECT id FROM users WHERE email = :e LIMIT 1', array('e' => $acc['email']));

    if ($existing) {
        $userId = (int) $existing['id'];

        bcc_execute(
            'UPDATE users SET password_hash = :p, full_name = :f, is_active = 1, is_admin = 0,
                    email_verify_token = NULL, email_verify_expires_at = NULL
             WHERE id = :i',
            array('p' => $hash, 'f' => $acc['full_name'], 'i' => $userId)
        );
        line('= kullanici tazelendi: ' . $acc['email']);
    } else {
        bcc_execute(
            'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :p, :f, 0, 1)',
            array('e' => $acc['email'], 'p' => $hash, 'f' => $acc['full_name'])
        );
        $userId = (int) bcc_last_insert_id();
        line('+ kullanici olusturuldu: ' . $acc['email']);
    }

    $userIds[$acc['email']] = $userId;

    $member = bcc_fetch_one(
        'SELECT id, role FROM team_members WHERE team_id = :t AND user_id = :u LIMIT 1',
        array('t' => $teamId, 'u' => $userId)
    );

    if ($member) {
        if ($member['role'] !== $acc['role']) {
            bcc_execute('UPDATE team_members SET role = :r WHERE id = :i', array('r' => $acc['role'], 'i' => $member['id']));
            line('  rol duzeltildi: ' . $member['role'] . ' -> ' . $acc['role']);
        }
    } else {
        bcc_execute(
            'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array('t' => $teamId, 'u' => $userId, 'r' => $acc['role'])
        );
        line('  role atandi: ' . $acc['role']);
    }
}

$ownerId = $userIds['owner@bcc.local'];

function ensure_base($teamId, $name, $description, $ownerId)
{
    $row = bcc_fetch_one(
        'SELECT id FROM bases WHERE team_id = :t AND name = :n AND deleted_at IS NULL LIMIT 1',
        array('t' => $teamId, 'n' => $name)
    );

    if ($row) {
        line('= base zaten var: ' . $name . ' (#' . $row['id'] . ')');
        return (int) $row['id'];
    }

    bcc_execute(
        'INSERT INTO bases (team_id, name, description, created_by) VALUES (:t, :n, :d, :c)',
        array('t' => $teamId, 'n' => $name, 'd' => $description, 'c' => $ownerId)
    );
    $id = (int) bcc_last_insert_id();
    line('+ base olusturuldu: ' . $name . ' (#' . $id . ')');

    return $id;
}

$baseId = ensure_base($teamId, DEMO_BASE_MAIN, 'Rol testleri icin ornek musteri base\'i.', $ownerId);
ensure_base($teamId, DEMO_BASE_SECOND, 'Ikinci demo base (ikon/renk cesitliligi icin).', $ownerId);

$table = bcc_fetch_one(
    'SELECT id FROM tables_meta WHERE base_id = :b AND name = :n LIMIT 1',
    array('b' => $baseId, 'n' => DEMO_TABLE)
);

if ($table) {
    $tableId = (int) $table['id'];
    line('= tablo zaten var: ' . DEMO_TABLE . ' (#' . $tableId . ')');
} else {
    bcc_execute(
        'INSERT INTO tables_meta (base_id, name, description, position) VALUES (:b, :n, :d, 0)',
        array('b' => $baseId, 'n' => DEMO_TABLE, 'd' => 'Demo kayitlar')
    );
    $tableId = (int) bcc_last_insert_id();
    line('+ tablo olusturuldu: ' . DEMO_TABLE . ' (#' . $tableId . ')');
}

$fieldSpecs = array(
    array('name' => 'Musteri', 'type' => 'single_line_text', 'options' => null),
    array('name' => 'Sehir', 'type' => 'single_line_text', 'options' => null),
    array(
        'name' => 'Durum',
        'type' => 'single_select',

        'options' => json_encode(array(
            'choices' => array('Yeni', 'Gorusuluyor', 'Kazanildi'),
            'colors' => array('Yeni' => 'blue', 'Gorusuluyor' => 'yellow', 'Kazanildi' => 'green'),
        ), JSON_UNESCAPED_UNICODE),
    ),
    array('name' => 'Butce', 'type' => 'number', 'options' => null),
    array('name' => 'Notlar', 'type' => 'long_text', 'options' => null),
);

$fieldIds = array();
$pos = 0;

foreach ($fieldSpecs as $spec) {
    $existing = bcc_fetch_one(
        'SELECT id, options FROM fields WHERE table_id = :t AND name = :n LIMIT 1',
        array('t' => $tableId, 'n' => $spec['name'])
    );

    if ($existing) {
        $fieldIds[$spec['name']] = (int) $existing['id'];

        if ($spec['options'] !== null && $existing['options'] !== $spec['options']) {
            bcc_execute('UPDATE fields SET options = :o WHERE id = :i',
                array('o' => $spec['options'], 'i' => $fieldIds[$spec['name']]));
            line('  secenekler tazelendi: ' . $spec['name']);
        }
    } else {
        bcc_execute(
            'INSERT INTO fields (table_id, name, field_type, options, position, is_required)
             VALUES (:t, :n, :ft, :o, :p, 0)',
            array('t' => $tableId, 'n' => $spec['name'], 'ft' => $spec['type'], 'o' => $spec['options'], 'p' => $pos)
        );
        $fieldIds[$spec['name']] = (int) bcc_last_insert_id();
        line('+ alan olusturuldu: ' . $spec['name'] . ' (' . $spec['type'] . ')');
    }

    $pos++;
}

$recordCount = (int) bcc_fetch_column(
    'SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL',
    array('t' => $tableId)
);

if ($recordCount > 0) {
    line('= tabloda zaten ' . $recordCount . ' kayit var, ornek veri yazilmadi');
} else {
    $rows = array(
        array('Acme A.S.', 'Istanbul', 'Kazanildi', 125000, 'Yillik sozlesme yenilendi.'),
        array('Beta Yazilim', 'Ankara', 'Gorusuluyor', 48000, 'Teklif gonderildi, geri donus bekleniyor.'),
        array('Ceta Lojistik', 'Izmir', 'Yeni', 15000, 'Fuardan gelen ilk temas.'),
        array('Delta Enerji', 'Bursa', 'Gorusuluyor', 92000, 'Teknik ekip demo istedi.'),
        array('Efe Insaat', 'Antalya', 'Yeni', 30000, null),
    );

    $position = 0;
    foreach ($rows as $r) {
        bcc_execute(
            'INSERT INTO records (table_id, position, created_by, updated_by) VALUES (:t, :p, :c, :c)',
            array('t' => $tableId, 'p' => $position, 'c' => $ownerId)
        );
        $recordId = (int) bcc_last_insert_id();

        $cells = array(
            array($fieldIds['Musteri'], 'value_text', $r[0]),
            array($fieldIds['Sehir'], 'value_text', $r[1]),
            array($fieldIds['Durum'], 'value_text', $r[2]),
            array($fieldIds['Butce'], 'value_number', $r[3]),
        );
        if ($r[4] !== null) {
            $cells[] = array($fieldIds['Notlar'], 'value_text', $r[4]);
        }

        foreach ($cells as $c) {
            bcc_execute(
                'INSERT INTO cell_values (record_id, field_id, ' . $c[1] . ') VALUES (:r, :f, :v)',
                array('r' => $recordId, 'f' => $c[0], 'v' => $c[2])
            );
        }

        $position++;
    }

    line('+ ' . count($rows) . ' ornek kayit yazildi');
}

line('');
line('Demo hesaplari hazir (ekip: ' . DEMO_TEAM . ' #' . $teamId . '):');
line('');

foreach ($accounts as $acc) {
    line(sprintf(
        '  %-9s %-20s %-13s rol=%s',
        $acc['label'],
        $acc['email'],
        '(app.local)',
        $acc['role']
    ));
}
line('');
line('login.php\'deki "Hizli Demo Girisi" butonlari icin config/app.local.php\'de');
line('$BCC_DEMO_LOGIN = true olmali (su an: ' . (bcc_demo_login_enabled() ? 'ACIK' : 'KAPALI') . ').');
