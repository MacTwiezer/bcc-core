-- user_starred_bases — kullanıcı bazlı favori/yıldızlı base'ler.
-- Neden: dashboard'daki base kartına yıldız ikonu eklendi (hover'da görünür,
-- tıklanınca kalıcı favori); sol panelin "Starred" bölümü bu tabloya göre
-- dolduruluyor. Kullanıcı bazlı (team_id değil), bu yüzden mevcut hiçbir
-- tabloya tek kolon olarak sığmıyor — team_members/views gibi ilişki
-- tablolarıyla aynı desen: UNIQUE(user_id, base_id) + iki yönlü CASCADE.
-- Takımdan ayrılan ama base silinmeyen kullanıcı için CASCADE devreye
-- girmez; sorgu tarafında her zaman team_members ile yeniden süzülür
-- (bkz. public/dashboard.php), tıpkı ana base listesinin zaten yaptığı gibi.

CREATE TABLE IF NOT EXISTS user_starred_bases (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    base_id     INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_starred_bases (user_id, base_id),
    KEY idx_user_starred_bases_user (user_id),
    CONSTRAINT fk_user_starred_bases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_starred_bases_base FOREIGN KEY (base_id) REFERENCES bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
