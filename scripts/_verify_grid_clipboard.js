// Grid pano mantiginin SAF fonksiyonlari — gercekten calistirilarak test edilir.
//
// Calistirma:  node scripts/_verify_grid_clipboard.js
//
// ⚠️ NEDEN KAYNAKTAN CIKARIP EVAL EDIYORUZ: bu fonksiyonlar tarayici
// modullerinin (IIFE + DOMContentLoaded) icinde yasiyor ve disa AKTARILMIYOR.
// Sirf test edilebilsinler diye uretim kodunu modul sistemine tasimak, calisan
// bir sayfayi test ugruna yeniden yapilandirmak olurdu. Bunun yerine dosya
// okunuyor, fonksiyon govdesi PARANTEZ ESLEYEREK cikariliyor ve calistiriliyor.
// Boylece test EDILEN sey gercekten tarayiciya giden kodun ta kendisi olur —
// kopyasi degil.
//
// KAPSAM DISI (tarayici gerektirir, sizin gozunuzle dogrulanmali):
//   - parseHtmlTable  -> DOMParser gerekiyor; makinede jsdom/linkedom YOK ve
//                        PHP projesine node_modules eklemek istemedik
//   - panoya yazma (execCommand/copy olayi)
//   - klavye gezinme, surukleyerek secim
'use strict';

const fs = require('fs');
const path = require('path');

const ASSETS = path.join(__dirname, '..', 'public', 'assets');

let pass = 0;
let total = 0;

function check(label, ok, detail) {
    total++;
    if (ok) { pass++; }
    console.log((ok ? '[GECTI] ' : '[KALDI] ') + label);
    if (!ok && detail !== undefined) {
        console.log('         detay: ' + detail);
    }
}

function eq(label, actual, expected) {
    check(label, actual === expected, 'beklenen ' + JSON.stringify(expected) + ', gelen ' + JSON.stringify(actual));
}

// Bir fonksiyonun kaynagini adiyla cikarir (parantez esleyerek, regex ile
// DEGIL — ic ice suslu parantezlerde regex guvenilmez).
function extractFn(src, name) {
    const start = src.indexOf('function ' + name + '(');
    if (start === -1) {
        throw new Error('Fonksiyon bulunamadi: ' + name);
    }
    let i = src.indexOf('{', start);
    let depth = 0;
    for (; i < src.length; i++) {
        const ch = src[i];
        if (ch === '{') { depth++; }
        else if (ch === '}') {
            depth--;
            if (depth === 0) { return src.slice(start, i + 1); }
        }
    }
    throw new Error('Fonksiyon govdesi kapanmadi: ' + name);
}

const pasteSrc = fs.readFileSync(path.join(ASSETS, 'grid-paste.js'), 'utf8');
const copySrc = fs.readFileSync(path.join(ASSETS, 'grid-copy.js'), 'utf8');

const sandbox = {};
// eslint-disable-next-line no-new-func
new Function('exports', [
    extractFn(pasteSrc, 'parseTsv'),
    extractFn(pasteSrc, 'coerceForField'),
    extractFn(copySrc, 'tsvCell'),
    extractFn(copySrc, 'esc'),
    'exports.parseTsv = parseTsv;',
    'exports.coerceForField = coerceForField;',
    'exports.tsvCell = tsvCell;',
    'exports.esc = esc;',
].join('\n'))(sandbox);

const { parseTsv, coerceForField, tsvCell, esc } = sandbox;

// ===========================================================================
console.log('\n--- A) TSV ayristirma ---');
// ===========================================================================
eq('A) duz 2x2', JSON.stringify(parseTsv('a\tb\nc\td')), JSON.stringify([['a', 'b'], ['c', 'd']]));
eq('A) sondaki satir sonu bos satir birakmiyor',
    JSON.stringify(parseTsv('a\tb\n')), JSON.stringify([['a', 'b']]));
eq('A) CRLF (Windows Excel) temizleniyor',
    JSON.stringify(parseTsv('a\tb\r\nc\td')), JSON.stringify([['a', 'b'], ['c', 'd']]));
// ⚠️ ASIL TUZAK: hucre icindeki satir sonu. Duz split(\n) tabloyu kaydirirdi.
eq('A) tirnakli hucrede SATIR SONU tabloyu kaydirmiyor',
    JSON.stringify(parseTsv('a\t"iki\nsatir"\nb\tc')),
    JSON.stringify([['a', 'iki\nsatir'], ['b', 'c']]));
eq('A) tirnakli hucrede SEKME tabloyu kaydirmiyor',
    JSON.stringify(parseTsv('a\t"x\ty"')), JSON.stringify([['a', 'x\ty']]));
eq('A) ikilenmis tirnak tek tirnaga cozuluyor',
    JSON.stringify(parseTsv('"o ""dedi"""')), JSON.stringify([['o "dedi"']]));
eq('A) hucre ORTASINDAKI tirnak duz karakter (Excel de boyle uretir)',
    JSON.stringify(parseTsv('12" boru')), JSON.stringify([['12" boru']]));
eq('A) bos hucreler korunuyor (hizalama bozulmasin)',
    JSON.stringify(parseTsv('a\t\tc')), JSON.stringify([['a', '', 'c']]));

// ===========================================================================
console.log('\n--- B) Tarih donusumu ---');
// ===========================================================================
// Sunucu YALNIZCA Y-m-d kabul ediyor (normalize_cell_value, DateTime::
// createFromFormat('Y-m-d')). Bu donusum olmadan Turkce Excel'den gelen her
// tarih SESSIZCE atlaniyordu.
eq('B) kanonik Y-m-d degismiyor', coerceForField('2000-03-12', 'date'), '2000-03-12');
eq('B) gg.aa.yyyy (Turkce Excel)', coerceForField('12.03.2000', 'date'), '2000-03-12');
eq('B) g.a.yyyy tek haneli doldurulur', coerceForField('5.3.2000', 'date'), '2000-03-05');
eq('B) gg/aa/yyyy', coerceForField('12/03/2000', 'date'), '2000-03-12');
eq('B) gg-aa-yyyy', coerceForField('12-03-2000', 'date'), '2000-03-12');
eq('B) yyyy/aa/gg', coerceForField('2000/03/12', 'date'), '2000-03-12');
eq('B) saat kismi atiliyor', coerceForField('2000-03-12 14:30:00', 'date'), '2000-03-12');
eq('B) ISO saat kismi atiliyor', coerceForField('2000-03-12T14:30:00Z', 'date'), '2000-03-12');
// Taninmayan bicim OLDUGU GIBI birakilir — burada veri UYDURULMAZ, son sozu
// sunucu soyler ve hucre "atlandi" olarak raporlanir.
eq('B) taninmayan bicim degistirilmiyor', coerceForField('12 Mart 2000', 'date'), '12 Mart 2000');
eq('B) bos deger bos kalir', coerceForField('   ', 'date'), '');

// ===========================================================================
console.log('\n--- C) Sayi donusumu ---');
// ===========================================================================
eq('C) duz tamsayi', coerceForField('42', 'number'), '42');
eq('C) Turkce ondalik 1234,56', coerceForField('1234,56', 'number'), '1234.56');
eq('C) Turkce binlik+ondalik 1.234,56', coerceForField('1.234,56', 'number'), '1234.56');
eq('C) Ingilizce binlik+ondalik 1,234.56', coerceForField('1,234.56', 'number'), '1234.56');
eq('C) para birimi simgesi atiliyor', coerceForField('₺1.234,50', 'currency'), '1234.50');
eq('C) bosluklu binlik', coerceForField('1 234,5', 'number'), '1234.5');
eq('C) negatif korunuyor', coerceForField('-12,5', 'number'), '-12.5');
// percent: kullanici "45" yazar, sunucu 100'e boler (0.45 saklar). Ekranda
// "%45" gorunur — o metni geri yapistirinca yine 45 olmali.
eq('C) yuzde isareti onde', coerceForField('%45', 'percent'), '45');
eq('C) yuzde isareti arkada', coerceForField('45%', 'percent'), '45');
eq('C) sayi olmayan metin degistirilmiyor', coerceForField('yok', 'number'), 'yok');

// ===========================================================================
console.log('\n--- D) Checkbox / cok secimli ---');
// ===========================================================================
// Sunucu yalnizca '1'/1'i dogru sayiyor; digerlerinin hepsi 0.
eq('D) "Evet" -> 1', coerceForField('Evet', 'checkbox'), '1');
eq('D) "evet" (kucuk harf) -> 1', coerceForField('evet', 'checkbox'), '1');
eq('D) "TRUE" -> 1', coerceForField('TRUE', 'checkbox'), '1');
eq('D) "X" -> 1', coerceForField('X', 'checkbox'), '1');
eq('D) "1" -> 1', coerceForField('1', 'checkbox'), '1');
eq('D) "Hayir" -> 0', coerceForField('Hayir', 'checkbox'), '0');
eq('D) "0" -> 0', coerceForField('0', 'checkbox'), '0');
eq('D) bos -> bos (hucre temizleme yolu bozulmasin)', coerceForField('', 'checkbox'), '');
eq('D) virgullu liste JSON dizisine', coerceForField('a, b, c', 'multiple_select'), '["a","b","c"]');
eq('D) noktali virgul de ayrac', coerceForField('a; b', 'multiple_select'), '["a","b"]');
eq('D) zaten JSON ise dokunulmuyor', coerceForField('["a","b"]', 'multiple_select'), '["a","b"]');

// ===========================================================================
console.log('\n--- E) Kopyalama bicimlendirme ---');
// ===========================================================================
eq('E) sade deger tirnaklanmiyor', tsvCell('abc'), 'abc');
eq('E) sekme iceren deger tirnaklanir', tsvCell('a\tb'), '"a\tb"');
eq('E) satir sonu iceren deger tirnaklanir', tsvCell('a\nb'), '"a\nb"');
eq('E) tirnak ikilenir', tsvCell('o "dedi"'), '"o ""dedi"""');
// Gidis-donus: kopyalanan TSV kendi ayristiricimizdan AYNI degeri dondurmeli.
const tricky = 'iki\nsatir\tve "tirnak"';
eq('E) TSV gidis-donus (kopyala -> yapistir) degeri koruyor',
    parseTsv(tsvCell(tricky))[0][0], tricky);
eq('E) HTML kacisi < > & " kapsiyor',
    esc('<a href="x">&</a>'), '&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;');

// ===========================================================================
console.log('\n--- F) Kaynak kodu sozlesmeleri ---');
// ===========================================================================
// Kendi kopyalamamizdan gelen deger, hedef tip AYNIYSA kanoniktir ve
// donusturulMEZ — yoksa "45" yuzdesi tekrar bolunur, "2000-03-12" bozulurdu.
check('F) kendi kaynagimizda tip AYNIYSA ham deger dokunulmadan kullaniliyor',
    /value\.type\s*===\s*targetType\s*\)\s*\?\s*value\.raw/.test(pasteSrc));
// Tip FARKLIYSA ham deger hedefte anlamsiz olabilir (checkbox hami "1", metin
// sutununda "Evet" olmali) — gorunen metin donusturulur.
check('F) tip FARKLIYSA gorunen metin donusturuluyor',
    /:\s*coerceForField\(value\.display,\s*targetType\)/.test(pasteSrc));
check('F) yabanci kaynak donusturuluyor (else if !isRaw)',
    /else if \(!isRaw\)[\s\S]{0,200}coerceForField\(value,\s*targetType\)/.test(pasteSrc));
// Kendi kopyalamamiz hucreyi NESNE olarak tasir: ham + gorunen + kaynak tip.
check('F) kendi HTML imiz kaynak alan tipini de tasiyor (data-bcc-type)',
    copySrc.includes('data-bcc-type="') && pasteSrc.includes("getAttribute('data-bcc-type')"));
check('F) kendi HTML isaretimiz data-bcc-grid ile yaziliyor',
    copySrc.includes('data-bcc-grid="1"'));
check('F) her hucre ham degeri data-bcc-raw ile tasiyor',
    copySrc.includes('data-bcc-raw="'));
check('F) yapistirma kendi isaretimizi ARIYOR',
    pasteSrc.includes("hasAttribute('data-bcc-grid')"));
check('F) yapistirma ham degeri TERCIH ediyor',
    pasteSrc.includes("getAttribute('data-bcc-raw')"));
// Pano icerigi guvenilmez veri: sayfaya innerHTML ile ENJEKTE EDILMEMELI.
check('F) yabanci HTML DOMParser ile ayristiriliyor (innerHTML DEGIL)',
    pasteSrc.includes('new DOMParser()'));
check('F) yabanci HTML sayfaya innerHTML ile basilmiyor',
    !/parsedHtml[\s\S]{0,200}innerHTML\s*=/.test(pasteSrc));
// Sunucu alan adi 'skipped_cells' — yanlis ad her zaman 0 okunur ve
// reddedilen hucreler sessizce "basarili" gorunurdu.
check('F) temizleme sunucunun skipped_cells alanini okuyor',
    copySrc.includes('data.skipped_cells'));
// Kopyalama okuma yetkisiyle de calismali; kes/temizle duzenleme ister.
check('F) kes/temizle CAN_EDIT kapisinin ardinda',
    /if\s*\(!CAN_EDIT\)\s*\{\s*return;\s*\}/.test(copySrc));

console.log('\n==================================');
console.log(pass === total ? `SONUC: GECTI (${pass}/${total})` : `SONUC: ${pass}/${total}`);
console.log('==================================');
process.exit(pass === total ? 0 : 1);
