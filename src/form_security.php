<?php
// Herkese açık form görünümünün (Grup View-Form) güvenlik yardımcıları.
//
// ⚠️ BU DOSYA PROJENİN TEK KİMLİK DOĞRULAMASIZ YAZMA YOLUNU KORUYOR.
// Bugüne kadar public/api/ altındaki HER yazma uç noktası api_require_login()
// ile başlıyordu; form_submit.php bunu kırıyor. Auth-suz korumanın TAMAMI
// bilerek tek dosyada toplandı ki denetlenebilir olsun — bu mantık uç noktalara
// dağıtılırsa hangi katmanın nerede uygulandığı takip edilemez hale gelir.
//
// KATMANLAR (onaylanan kapsam):
//   1. Honeypot        — insanın görmediği bir alan; doluysa bot demektir
//   2. Zaman-bazlı HMAC nonce — "bu POST gerçek bir sayfa render'ından sonra geldi"
//   3. (IP rate-limit tablosu — BU TURDA KAPSAM DIŞI, ayrı bir karar)
//
// KLASİK CSRF TOKEN'I BURADA BİLEREK YOK. Gerekçe: CSRF, KURBANIN yetkisini
// kötüye kullanmaktır. Herkese açık bir formda gönderen zaten yetkisiz ve form
// zaten herkese açık — saldırgan kurbanı kandırmak yerine formu doğrudan
// POST'lar. Üstelik csrf_token() token'ı $_SESSION'da tutuyor, yani anonim
// ziyaretçi için oturum başlatmak gerekirdi (her bot ziyaretine bir session
// dosyası). Korunması gereken şey "yetki kötüye kullanımı" değil, "otomasyonun
// ucuzluğu" — nonce tam da bunu pahalılaştırıyor.

// HMAC anahtarı. config/database.local.php / mail.local.php ile AYNI desen:
// git'e GİRMEZ (.gitignore), makineye özeldir, ilk kullanımda otomatik üretilir.
// Sunucuda elle bir kurulum adımı gerektirmemesi kasıtlı — unutulan bir adım,
// sabit/boş bir anahtarla çalışan bir kuruluma yol açardı.
function bcc_form_secret()
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    $path = __DIR__ . '/../config/form_secret.local.php';

    if (is_file($path)) {
        $value = require $path;
        if (is_string($value) && strlen($value) >= 32) {
            $secret = $value;
            return $secret;
        }
    }

    // Yoksa (ya da bozuksa) üret. LOCK_EX: iki eşzamanlı istek aynı anda
    // üretmeye kalkarsa dosya yarım yazılmış halde okunmasın.
    $secret = bin2hex(random_bytes(32));
    @file_put_contents(
        $path,
        "<?php\n// Otomatik üretildi (src/form_security.php). GIT'E GİRMEZ.\n"
            . "// Silinirse yenisi üretilir — o an açık olan form sayfalarının\n"
            . "// nonce'ları geçersiz olur, kullanıcı sayfayı yenileyince düzelir.\n"
            . 'return ' . var_export($secret, true) . ";\n",
        LOCK_EX
    );

    return $secret;
}

// Formun HTML'ine gömülen nonce: "<zaman damgası>.<imza>".
// Durumsuz (stateless) — sunucuda hiçbir şey saklanmaz, bu yüzden anonim
// ziyaretçi için oturum açmak GEREKMEZ.
function bcc_form_nonce()
{
    $ts = (string) time();

    return $ts . '.' . hash_hmac('sha256', $ts, bcc_form_secret());
}

// Nonce doğrulaması. İki ayrı şeyi birden kontrol eder:
//   (a) İMZA — nonce'u bu sunucu üretmiş mi? Üretmediyse istek formu hiç
//       render etmeden doğrudan POST'lanmış demektir (tipik bot davranışı).
//   (b) YAŞ — render ile gönderim arasında geçen süre.
//       * $minSeconds'tan HIZLI ise reddedilir: bir insan formu 3 saniyeden kısa
//         sürede dolduramaz. Sayfayı çekip anında POST'layan script buraya takılır.
//       * $maxSeconds'tan ESKİ ise reddedilir. Kullanıcı formu açık unutup sonra
//         gönderirse sayfayı yenilemesi gerekir — kabul edilen bir maliyet.
//
// ⚠️ BİLİNEN SINIR — NONCE TEK KULLANIMLIK DEĞİLDİR. Durumsuz olduğu için
// sunucu "bu nonce daha önce kullanıldı mı" bilgisini tutmuyor: geçerli bir
// nonce $maxSeconds boyunca DEFALARCA kullanılabilir. Yani nonce, "sayfayı hiç
// açmadan POST'layan" saldırganı engeller ama "bir kez açıp nonce'u alan, sonra
// 2 saat boyunca döngüye sokan" saldırganı ENGELLEMEZ.
// Tek kullanımlık yapmak sunucu tarafında durum (kullanılmış nonce tablosu ya
// da IP/nonce sayacı) gerektirir — bu, onaylanan kapsamda BİLEREK dışarıda
// bırakılan Katman 3'ün (rate-limit tablosu) işidir. Spam gerçekleşirse
// çözüm sırası: (1) form_edit.php'den formu kapat, (2) Katman 3'ü ekle.
//
// hash_equals: zamanlama saldırısına karşı sabit süreli karşılaştırma
// (csrf_verify() ile AYNI disiplin).
function bcc_form_nonce_valid($nonce, $minSeconds = 3, $maxSeconds = 7200)
{
    $parts = explode('.', (string) $nonce, 2);

    if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[0])) {
        return false;
    }

    $expected = hash_hmac('sha256', $parts[0], bcc_form_secret());

    if (!hash_equals($expected, $parts[1])) {
        return false;
    }

    $age = time() - (int) $parts[0];

    // Negatif yaş = gelecekten gelen damga (saat kayması ya da kurcalama) —
    // imza geçerli olsa bile kabul edilmez.
    if ($age < $minSeconds || $age > $maxSeconds) {
        return false;
    }

    return true;
}

// Honeypot alanının adı. Gerçekçi görünmeli ki bot doldurmaya değer bulsun;
// "website" tipik bir seçim. TEK kaynak — form.php bu adla input basar,
// form_submit.php AYNI adla okur.
define('BCC_FORM_HONEYPOT_FIELD', 'website');

// Honeypot doluysa true. İnsan bu alanı GÖREMEZ (CSS ile gizli + aria-hidden +
// tabindex=-1), yani dolu gelmesi otomasyon demektir.
//
// ⚠️ Çağıran taraf, dolu olduğunda kullanıcıya HATA GÖSTERMEMELİ — başarılı
// yanıt dönüp kaydı SESSİZCE ATMALI. Bot'a "yakalandın" geri bildirimi vermek,
// saldırganın honeypot'u tespit edip bir sonraki denemede boş bırakmasını sağlar.
function bcc_form_honeypot_tripped($postData)
{
    if (!isset($postData[BCC_FORM_HONEYPOT_FIELD])) {
        return false;
    }

    return trim((string) $postData[BCC_FORM_HONEYPOT_FIELD]) !== '';
}
