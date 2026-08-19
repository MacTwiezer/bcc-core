-- comments — kayıt yorumları (OpsFlow "Comment on records" davranışı, MVP).
-- team_id YOK: KVKK izolasyonu record_id -> records.table_id -> tables_meta.base_id
-- -> bases.team_id zinciriyle sorgu zamanında türetilir (cell_values/attachments
-- ile AYNI desen — bkz. bcc_find_record(), src/schema.php).
-- fk_comments_user ON DELETE SET NULL (CASCADE DEĞİL): account_delete.php artık
-- gerçek bir hard-delete yapıyor — bir kullanıcı hesabını silerse yorumları
-- kaybolmamalı (OpsFlow'un "deaktif kullanıcının yorumu kalır" davranışı).
-- user_id NULL ise arayüz "Silinmiş kullanıcı" gösterir.
-- deleted_at ile soft-delete: bases/attachments ile AYNI desen.
CREATE TABLE IF NOT EXISTS comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED DEFAULT NULL,
    body        TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_comments_record (record_id, created_at),
    CONSTRAINT fk_comments_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
