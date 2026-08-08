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
  complete    TINYINT(1) NOT NULL DEFAULT 0,
  cleared_at  TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (list_id)   REFERENCES checklist_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES checklist_items(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
