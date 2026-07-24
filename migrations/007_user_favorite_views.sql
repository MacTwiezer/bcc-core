-- user_favorite_views — kullanıcı bazlı favori/yıldızlı görünümler.
-- Neden: sol Views panelinde yıldız ikonu (hover'da görünür, tıklanınca
-- kalıcı favori) — favorilenen view'lar listenin en üstünde sabit kalır.
-- user_starred_bases (migrations/004) ile BİREBİR AYNI desen: UNIQUE(user_id,
-- view_id), iki yönlü CASCADE — yeni bir mekanizma icat edilmedi.

CREATE TABLE IF NOT EXISTS user_favorite_views (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    view_id     INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_favorite_views (user_id, view_id),
    KEY idx_user_favorite_views_user (user_id),
    CONSTRAINT fk_user_favorite_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_favorite_views_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
