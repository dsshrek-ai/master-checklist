-- Master Checklist — adds to the shared MyDataWorld database.
-- `users` and `sessions` already exist there (created for T-Minus) — do NOT
-- recreate them. One login already works across every MyDataWorld app, so
-- existing family accounts can log into Master Checklist as-is.
--
-- Run only the two CREATE TABLE statements below in phpMyAdmin's SQL tab.

CREATE TABLE checklist_lists (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  name        VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- parent_id + depth model the indent hierarchy (depth 0 = top level, 1 = child,
-- 2 = grandchild — the app enforces that cap, the column doesn't).
-- cleared_at implements "clear checked / restore": clearing sets it, leaving
-- the row intact; restoring sets it back to NULL. Rows are otherwise only
-- removed by an explicit delete.
CREATE TABLE checklist_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  list_id     INT NOT NULL,
  user_id     INT NOT NULL,
  parent_id   INT NULL,
  depth       TINYINT NOT NULL DEFAULT 0,
  what        VARCHAR(500) NOT NULL,
  where_text  VARCHAR(255) NULL,
  when_date   DATE NULL,
  when_time   TIME NULL,
  who         VARCHAR(255) NULL,
  notes       TEXT NULL,
  complete    TINYINT(1) NOT NULL DEFAULT 0,
  cleared_at  TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (list_id)   REFERENCES checklist_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES checklist_items(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 2026-10-12: require a My Apps Hub grant (app_access) ----------
-- From this version on, api.php only lets in logins that have an app_access
-- row for 'master-checklist' (the app is already registered in the Hub's
-- `apps` table). Run this ONCE, BEFORE uploading the new api.php, so everyone
-- who already has lists keeps access. Safe to re-run.

INSERT INTO app_access (user_id, app_id)
SELECT DISTINCT l.user_id, a.id
FROM checklist_lists l JOIN apps a ON a.app_key = 'master-checklist'
ON DUPLICATE KEY UPDATE user_id = app_access.user_id;

-- To give someone new access later:
-- INSERT INTO app_access (user_id, app_id)
-- SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'master-checklist'
-- WHERE u.username = 'someone@example.com'
-- ON DUPLICATE KEY UPDATE user_id = app_access.user_id;

-- ---------- 2026-10-16: free-text notes per item ----------
-- Existing installs only (a fresh install already has `notes` from the CREATE
-- TABLE above). Run this ONCE, BEFORE uploading the new api.php.

ALTER TABLE checklist_items ADD COLUMN notes TEXT NULL AFTER who;

-- ---------- 2026-10-16 (2): contact/phone, ordering, files, sharing ----------
-- Run this whole block ONCE, BEFORE uploading the matching api.php.
-- (A fresh install: run the CREATE TABLEs at the top first, then this block.)

-- Contact name + phone (tap to call / text), and sort_order for move up/down.
-- sort_order only orders an item among its siblings; seeding it from id keeps
-- every existing list in the order it shows today.
ALTER TABLE checklist_items
  ADD COLUMN sort_order   INT NOT NULL DEFAULT 0 AFTER depth,
  ADD COLUMN contact_name VARCHAR(150) NULL AFTER who,
  ADD COLUMN phone_number VARCHAR(40)  NULL AFTER contact_name;

UPDATE checklist_items SET sort_order = id * 10;

-- Photos/files on an item. The files themselves live in api/uploads/ (served
-- only through api.php to people who can see the list); this is the index.
-- user_id = who uploaded it.
CREATE TABLE checklist_attachments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  item_id      INT NOT NULL,
  user_id      INT NOT NULL,
  file_name    VARCHAR(255) NOT NULL,
  file_type    VARCHAR(100) NOT NULL,
  file_size    INT NOT NULL DEFAULT 0,
  stored_name  VARCHAR(64) NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY (item_id),
  FOREIGN KEY (item_id) REFERENCES checklist_items(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live sharing: each row lets one more login see and edit a list. The owner
-- stays checklist_lists.user_id; only the owner can rename/delete/share it.
CREATE TABLE checklist_list_shares (
  list_id     INT NOT NULL,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id, user_id),
  KEY (user_id),
  FOREIGN KEY (list_id) REFERENCES checklist_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
