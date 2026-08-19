-- record_view_log "temsilci not inceleme takibi" (Duyuru ekranı, interface.php).
--
-- NE KAYDEDER: bir kullanıcının bir kaydı (notu) AÇTIĞI an, KAPATTIĞI an ve
-- arada geçen süre. Yalnızca 'commenter' rolündeki kullanıcılar için yazılır
-- (temsilci tanımı, bkz. src/auth.php bcc_is_representative()) - kısıt UYGULAMA
-- katmanlarındandır, şemada DEĞİL: rol politikası ileride değişirse tabloyu
-- değiştirmek gerekmesin.
--
-- ADLANDIRMA: arayüzde "not" denen şey veritabanındaki records satırıdır; proje
-- genelinde varlık "record" olarak adlandırılıyor (records, record_id, bcc_find_record()).
-- Tablo adı o terime uyuyor, arayüz metni "Not" kalabilir.

CREATE TABLE IF NOT EXISTS record_view_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED DEFAULT NULL,
    role_at_view ENUM('owner', 'editor', 'commenter', 'viewer') NOT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    duration_seconds INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_rvl_record_opened (record_id, opened_at),
    KEY idx_rvl_user (user_id),
    KEY idx_rvl_opened (opened_at),
    CONSTRAINT fk_rvl_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_rvl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rvl_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;