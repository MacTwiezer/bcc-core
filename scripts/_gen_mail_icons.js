// E-posta footer ikonlarini uretir (public/assets/mail/*.png).
//
// NEDEN BU BETIK: sunucuda GD YOK (extension_loaded('gd') === false), yani
// PHP tarafinda gorsel uretilemiyor. Node'un yerlesik zlib'i ile PNG'yi
// bayt bayt yaziyoruz — harici bir paket/CDN gerekmiyor.
//
// Cizim: her ikon bir "isaretli mesafe fonksiyonu" (SDF) olarak taniml; her
// piksel 4x4 supersampling ile ornekleniyor, boylece kenarlar YUMUSAK cikiyor
// (blok blok degil). 36x36 uretilip mailde 18x18 gosteriliyor -> retina'da net.
//
// Calistirma:  node scripts/_gen_mail_icons.js
const zlib = require('zlib');
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, '..', 'public', 'assets', 'mail');
const SIZE = 36;          // gercek piksel
const SS = 4;             // supersampling
// Footer metin rengi (BCC_MAIL_C_MUTED, src/mail_template.php). Ikonlar
// metinle AYNI mürekkep tonunda olmali; farkli gri, izgarada iki ayri
// gorsel agirlik yaratiyordu.
const INK = [0x47, 0x55, 0x69];   // #475569

// ---- SDF yardimcilari (birim: piksel, 0..SIZE) ----
const len = (x, y) => Math.sqrt(x * x + y * y);
// halka (cember cizgisi)
const ring = (x, y, cx, cy, r, w) => Math.abs(len(x - cx, y - cy) - r) - w / 2;
// dolu daire
const disc = (x, y, cx, cy, r) => len(x - cx, y - cy) - r;
// eksenleri farkli elips halkasi (yaklasik)
const ringE = (x, y, cx, cy, rx, ry, w) => {
    const dx = (x - cx) / rx, dy = (y - cy) / ry;
    const k = len(dx, dy);
    return Math.abs(k - 1) * Math.min(rx, ry) - w / 2;
};
// kalin cizgi parcasi
function seg(x, y, ax, ay, bx, by, w) {
    const vx = bx - ax, vy = by - ay;
    const wx = x - ax, wy = y - ay;
    const t = Math.max(0, Math.min(1, (wx * vx + wy * vy) / (vx * vx + vy * vy)));
    return len(wx - t * vx, wy - t * vy) - w / 2;
}
// yuvarlak kosheli dikdortgen halkasi
function rectRing(x, y, cx, cy, hw, hh, r, w) {
    const qx = Math.abs(x - cx) - (hw - r), qy = Math.abs(y - cy) - (hh - r);
    const d = len(Math.max(qx, 0), Math.max(qy, 0)) + Math.min(Math.max(qx, qy), 0) - r;
    return Math.abs(d) - w / 2;
}
const uni = (a, b) => Math.min(a, b);
const sub = (a, b) => Math.max(a, -b);   // a'dan b'yi cikar

const S = 36 / 24;   // 24'luk izgaradan 36'ya olcek (uygulamanin ikon dili)
const W = 1.7 * S;   // cizgi kalinligi

const ICONS = {
    // Kure: dis cember + yatay ekvator + dikey meridyen
    'icon-web': (x, y) => {
        let d = ring(x, y, 18, 18, 13.8, W);
        d = uni(d, seg(x, y, 18 - 13.8, 18, 18 + 13.8, 18, W));
        d = uni(d, ringE(x, y, 18, 18, 6.4, 13.8, W));
        return d;
    },
    // Ahize: kose kose iki ucu kalin, ortasi ince klasik telefon silueti
    'icon-phone': (x, y) => {
        let d = rectRing(x, y, 18, 18, 8.6, 12.4, 3.4, W);
        // ust hoparlor cizgisi + alt tus noktasi: sade "handset" yerine
        // modern telefon govdesi (Outlook'ta da net okunur)
        d = uni(d, seg(x, y, 18 - 3.2, 11.4, 18 + 3.2, 11.4, W * 0.9));
        d = uni(d, disc(x, y, 18, 25.4, 1.5));
        return d;
    },
    // Zarf: dikdortgen + kapak (V)
    'icon-mail': (x, y) => {
        let d = rectRing(x, y, 18, 18, 13.2, 9.6, 2.6, W);
        d = uni(d, uni(
            seg(x, y, 18 - 13.2 + 1.4, 18 - 9.6 + 1.6, 18, 19.2, W),
            seg(x, y, 18, 19.2, 18 + 13.2 - 1.4, 18 - 9.6 + 1.6, W)
        ));
        return d;
    },
    // Konum ignesi: damla + ic delik
    'icon-map': (x, y) => {
        const cx = 18, cy = 14.6, r = 7.4;
        let head = ring(x, y, cx, cy, r, W);
        // govdeden asagi inen ucgen kenarlar
        const tipY = 31.6;
        head = uni(head, seg(x, y, cx - r * 0.72, cy + r * 0.70, cx, tipY, W));
        head = uni(head, seg(x, y, cx + r * 0.72, cy + r * 0.70, cx, tipY, W));
        // cemberin ALT yayini sil (damla agzi acik olsun)
        const mouth = (Math.abs(x - cx) < r * 0.80 && y > cy + r * 0.52) ? -1 : 1;
        if (mouth < 0) head = ring(x, y, cx, cy, r, W) > 0 ? head : 1e9;
        head = uni(head, ring(x, y, cx, cy - 0.2, 2.9, W));
        return head;
    },
};

function encodePng(rgba, w, h) {
    const raw = Buffer.alloc((w * 4 + 1) * h);
    let p = 0;
    for (let y = 0; y < h; y++) {
        raw[p++] = 0;                                  // filtre: None
        rgba.copy(raw, p, y * w * 4, (y + 1) * w * 4);
        p += w * 4;
    }
    const chunk = (type, data) => {
        const l = Buffer.alloc(4); l.writeUInt32BE(data.length, 0);
        const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
        const c = Buffer.alloc(4); c.writeInt32BE(crc32(td), 0);
        return Buffer.concat([l, td, c]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4);
    ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;  // 8-bit RGBA
    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

let TBL = null;
function crc32(buf) {
    if (!TBL) {
        TBL = new Int32Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
            TBL[n] = c;
        }
    }
    let c = -1;
    for (let i = 0; i < buf.length; i++) c = TBL[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
    return c ^ -1;
}

fs.mkdirSync(OUT, { recursive: true });
for (const [name, sdf] of Object.entries(ICONS)) {
    const rgba = Buffer.alloc(SIZE * SIZE * 4);
    for (let y = 0; y < SIZE; y++) {
        for (let x = 0; x < SIZE; x++) {
            let cov = 0;
            for (let sy = 0; sy < SS; sy++) {
                for (let sx = 0; sx < SS; sx++) {
                    const px = x + (sx + 0.5) / SS, py = y + (sy + 0.5) / SS;
                    if (sdf(px, py) <= 0) cov++;
                }
            }
            const a = Math.round((cov / (SS * SS)) * 255);
            const o = (y * SIZE + x) * 4;
            rgba[o] = INK[0]; rgba[o + 1] = INK[1]; rgba[o + 2] = INK[2]; rgba[o + 3] = a;
        }
    }
    const file = path.join(OUT, name + '.png');
    fs.writeFileSync(file, encodePng(rgba, SIZE, SIZE));
    console.log(name + '.png  ' + fs.statSync(file).size + ' bayt');
}
