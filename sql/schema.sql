-- sql/schema.sql (NOWA STRUKTURA)
-- Uruchom w phpMyAdmin / MySQL CLI (XAMPP).
-- Tworzy bazę 'librus' i wszystkie potrzebne tabele.

CREATE DATABASE IF NOT EXISTS librus
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE librus;

-- ===== ROLE =====
CREATE TABLE IF NOT EXISTS roles (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (id, name) VALUES
  (1, 'rodzic'),
  (2, 'uczeń'),
  (3, 'nauczyciel'),
  (4, 'dyrektor'),
  (5, 'admin');

-- ===== UŻYTKOWNICY (z rozbitym adresem) =====
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  login VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,

  -- Adres (rozbity)
  country VARCHAR(64) NOT NULL,          -- np. "Polska"
  voivodeship VARCHAR(40) NOT NULL,      -- województwo (walidowane w aplikacji z listy 16)
  city VARCHAR(120) NOT NULL,
  street VARCHAR(120) NOT NULL,
  building_no VARCHAR(16) NOT NULL,      -- np. 12, 12A
  apartment_no VARCHAR(16) NULL,         -- np. 7, 7a (opcjonalne)

  pesel CHAR(11) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX (last_name),
  INDEX (city)
) ENGINE=InnoDB;

-- Relacja wiele-do-wielu user -> role
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Reset haseł (tokeny)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (user_id),
  CONSTRAINT fk_pw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Uwaga: Reguły budynku/lokalu (np. 12, 12A, 7, 7a) są
-- egzekwowane w aplikacji (PHP + pattern HTML) dla zgodności z XAMPP/MariaDB.
