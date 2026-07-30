-- Trash özelliği (Airtable workspace trash referansı): base silme artık
-- gerçek DELETE değil, geri alınabilir soft-delete. deleted_by ON DELETE
-- SET NULL — mevcut created_by/uploaded_by/audit_log.user_id ile AYNI desen
-- (silen kullanıcının hesabı silinirse base'in kendisi/trash kaydı bozulmaz,
-- yalnızca "kim sildi" bilgisi anonimleşir).
ALTER TABLE bases
    ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD CONSTRAINT fk_bases_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_bases_deleted_at ON bases (deleted_at);
