-- Notes schema (robust CRM-like)
-- Run on your APPS database.

CREATE TABLE IF NOT EXISTS notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  note_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  title VARCHAR(200) NOT NULL,
  body LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX user_idx (user_id),
  INDEX date_idx (note_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extended metadata for “page” style properties (icon, cover, status, etc.)
CREATE TABLE IF NOT EXISTS note_pages_meta (
  note_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  icon VARCHAR(32) NULL,
  cover_url VARCHAR(1000) NULL,
  status VARCHAR(32) NULL,
  properties LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_note_pages_meta_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS note_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  s3_key VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_note_pos (note_id, position),
  INDEX note_idx (note_id),
  CONSTRAINT fk_note_photo_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IMPORTANT: sharing table uses column name `user_id`
CREATE TABLE IF NOT EXISTS notes_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_note_user (note_id, user_id),
  INDEX idx_user (user_id),
  CONSTRAINT fk_notes_shares_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notion-style blocks (stored as JSON payload per block)
CREATE TABLE IF NOT EXISTS note_blocks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  block_uid VARCHAR(64) NOT NULL,
  position INT UNSIGNED NOT NULL,
  block_type VARCHAR(32) NOT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_block_uid (block_uid),
  INDEX idx_note (note_id),
  INDEX idx_note_pos (note_id, position),
  CONSTRAINT fk_note_blocks_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lightweight tag catalog dedicated to notes UI
CREATE TABLE IF NOT EXISTS note_tags_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  color VARCHAR(24) NOT NULL DEFAULT '#6366F1',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS note_tag_assignments (
  note_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (note_id, tag_id),
  INDEX idx_tag (tag_id),
  CONSTRAINT fk_note_tag_assign_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
  CONSTRAINT fk_note_tag_assign_tag FOREIGN KEY (tag_id) REFERENCES note_tags_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reusable page templates (Notion-style)
CREATE TABLE IF NOT EXISTS note_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  title VARCHAR(200) NULL,
  icon VARCHAR(32) NULL,
  cover_url VARCHAR(1000) NULL,
  status VARCHAR(32) NULL,
  properties LONGTEXT NULL,
  tags LONGTEXT NULL,
  blocks LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_name (user_id, name),
  INDEX idx_template_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Template sharing (Obsidian-style vault sharing)
CREATE TABLE IF NOT EXISTS note_template_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_user (template_id, user_id),
  INDEX idx_template_share_user (user_id),
  CONSTRAINT fk_template_shares_template FOREIGN KEY (template_id) REFERENCES note_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Legacy helpers (kept for backward compatibility; safe to ignore if already provisioned)
CREATE TABLE IF NOT EXISTS tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS note_tags (
  note_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (note_id, tag_id),
  CONSTRAINT fk_note_tags_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
  CONSTRAINT fk_note_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: polymorphic links to other entities (company/contact/task/etc)
CREATE TABLE IF NOT EXISTS note_links (
  note_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (note_id, entity_type, entity_id),
  CONSTRAINT fk_note_links_note FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fulltext for fast search (ignore if fails; your SQL may already have it)
ALTER TABLE notes ADD FULLTEXT INDEX ft_notes_title_body (title, body);
