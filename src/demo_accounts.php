<?php
// Demo/test hesapları — rol tabanlı yetki denemeleri için sabit kimlik bilgileri.
//
// TEK KAYNAK: hem scripts/seed_demo_users.php (hesapları DB'ye yazar) hem
// public/login.php'nin "Hızlı Demo Girişi" butonları bu listeden beslenir.
// E-posta/şifre/rol üçlüsü ikinci bir yere KOPYALANMAZ — kopyalansaydı biri
// değişince diğeri sessizce yanlış kimlik bilgisi doldurmaya devam ederdi.
//
// ---------------------------------------------------------------------------
// Liste, team_members.role ENUM'undaki DÖRT ROLÜ birebir karşılar:
// ('owner','editor','commenter','viewer'). Her demo hesabı GERÇEKTEN farklı
// davranan bir yetki seviyesini temsil eder — aynı davranışı iki kez gösteren
// hesap YOKTUR.
//
// KALDIRILDI — "Creator": listede bir zamanlar creator@bcc.local vardı ama
// GERÇEK ROLÜ 'owner' idi (OpsFlow'un izin matrisinde Owner ve Creator "Add
// and delete bases in the shared workspace" satırında aynı hücreyi paylaşır,
// bu uygulamada ikisinin karşılığı tek 'owner' rolü). Yani owner@bcc.local
// ile DAVRANIŞ OLARAK BİREBİR AYNIYDI, ayrı bir seviye test etmiyordu ve
// ekranda var olmayan bir rol varmış izlenimi bırakıyordu. 'creator' DİYE BİR
// ROL HİÇBİR ZAMAN OLMADI (ENUM'a eklenmedi, DDL yok).
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
// değerdir; 'label' yalnızca ekranda görünen etikettir (OpsFlow adlandırması).
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
            'email' => 'editor@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Editor',
            'role' => 'editor',
            'label' => 'Editor',
            'hint' => 'Kayıt/alan düzenler, base OLUŞTURAMAZ',
        ),
        array(
            // owner/editor/viewer'dan gerçekten AYRI davranan seviye: yorum
            // yazar ama kayıt/alan düzenleyemez (bkz. src/auth.php
            // bcc_can_comment() ile bcc_can_edit_records() farkı). Ayrıca
            // "temsilci" tanımıdır — not inceleme takibi YALNIZCA bu rol için
            // kaydedilir (bkz. bcc_is_representative()). Liste rol rütbesine
            // göre azalan sırada olduğu için editor(3) ile viewer(1) ARASINA
            // konuldu.
            'email' => 'commenter@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Commenter',
            'role' => 'commenter',
            'label' => 'Commenter',
            'hint' => 'Yorum yazar, kayıt/alan DÜZENLEYEMEZ',
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
