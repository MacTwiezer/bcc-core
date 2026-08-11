<?php
// Demo/test hesapları — rol tabanlı yetki denemeleri için sabit kimlik bilgileri.
//
// TEK KAYNAK: hem scripts/seed_demo_users.php (hesapları DB'ye yazar) hem
// public/login.php'nin "Hızlı Demo Girişi" butonları bu listeden beslenir.
// E-posta/şifre/rol üçlüsü ikinci bir yere KOPYALANMAZ — kopyalansaydı biri
// değişince diğeri sessizce yanlış kimlik bilgisi doldurmaya devam ederdi.
//
// ---------------------------------------------------------------------------
// "Creator" hakkında (bilinçli karar, kullanıcı onayladı)
// ---------------------------------------------------------------------------
// team_members.role sabit bir ENUM'dur: ('owner','editor','commenter','viewer')
// — 'creator' DİYE BİR ROL YOK ve eklenmedi (DDL yok). Airtable'ın izin
// matrisinde Owner ve Creator, "Add and delete bases in the shared workspace"
// satırında AYNI hücreyi paylaşır (ikisi de ✅); bu uygulamada o ikilinin
// karşılığı tek 'owner' rolüdür (bkz. src/auth.php bcc_can_manage_bases()).
// Bu yüzden creator@bcc.local rolü de 'owner'dır ve owner@bcc.local ile
// DAVRANIŞ OLARAK BİREBİR AYNIDIR — ayrı bir yetki seviyesi test etmez,
// yalnızca "Airtable'daki Creator bizde Owner'a denk düşüyor" eşlemesini
// ekranda görünür kılar. Gerçekten FARKLI davranan dördüncü bir seviye test
// etmek istenirse 'commenter' rolü kullanılmalıdır.
// ---------------------------------------------------------------------------

// Demo butonları YALNIZCA bu bayrak açıkken basılır. Varsayılan KAPALI
// (config/app.php) — böylece sabit kimlik bilgileri hiçbir zaman kazara canlıya
// çıkmaz; yerel makinede config/app.local.php (git'e girmez) ile açılır.
function bcc_demo_login_enabled()
{
    global $BCC_DEMO_LOGIN;

    return isset($BCC_DEMO_LOGIN) && $BCC_DEMO_LOGIN === true;
}

// Demo hesaplarının ORTAK şifresi — depoda LİTERAL OLARAK BULUNMAZ.
//
// GÜVENLİK GEREKÇESİ (denetimde bulundu): bu dosya daha önce dört hesabın
// şifresini DÜZ METİN taşıyordu. Depo AÇIK (public) bir GitHub deposu olduğu
// için bu, veritabanında GERÇEKTEN var olan ve AKTİF olan dört hesabın
// çalışan kimlik bilgisini yayınlamak demekti — üstelik $BCC_DEMO_LOGIN
// kapalı olsa bile, çünkü hesaplar normal giriş formundan da denenebilir.
// Şifre artık YALNIZCA git'e girmeyen config/app.local.php içinde yaşıyor
// (bkz. .gitignore). Eski değer bu yorumda da TEKRARLANMAZ: yorum da depoya
// girer, yani literali burada anmak açığı kapatmaz.
//
// Değer tanımlı değilse null döner: bcc_demo_accounts() o durumda BOŞ liste
// verir, yani login.php demo butonlarını hiç basmaz ve seed betiği çalışmayı
// reddeder — "şifresiz demo hesabı" gibi bir ara durum OLUŞAMAZ.
function bcc_demo_password()
{
    global $BCC_DEMO_PASSWORD;

    return (isset($BCC_DEMO_PASSWORD) && is_string($BCC_DEMO_PASSWORD) && $BCC_DEMO_PASSWORD !== '')
        ? $BCC_DEMO_PASSWORD
        : null;
}

// Tüm demo hesapları. 'role' değeri team_members.role ENUM'undaki GERÇEK
// değerdir; 'label' yalnızca ekranda görünen etikettir (Airtable adlandırması).
//
// Şifre yerelde tanımlı değilse BOŞ dizi döner (yukarıdaki nota bakın).
function bcc_demo_accounts()
{
    $password = bcc_demo_password();
    if ($password === null) {
        return array();
    }

    return array(
        array(
            'email' => 'owner@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Owner',
            'role' => 'owner',
            'label' => 'Owner',
            'hint' => 'Base oluşturur/siler, rol atar',
        ),
        array(
            'email' => 'creator@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Creator',
            // Airtable Creator -> bu uygulamada 'owner' (yukarıdaki nota bakın).
            'role' => 'owner',
            'label' => 'Creator',
            'hint' => "Airtable'da Creator = bizde Owner",
        ),
        array(
            'email' => 'editor@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Editor',
            'role' => 'editor',
            'label' => 'Editor',
            'hint' => 'Kayıt/alan düzenler, base OLUŞTURAMAZ',
        ),
        array(
            'email' => 'viewer@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Viewer',
            'role' => 'viewer',
            'label' => 'Viewer',
            'hint' => 'Yalnızca görüntüler',
        ),
    );
}
