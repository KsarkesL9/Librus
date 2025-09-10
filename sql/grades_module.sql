-- sql/grades_module.sql
-- Dodaje moduł ocen: przedmioty, klasy, zapisy, lata/okresy, kategorie ocen, przydziały
-- oraz tabele ocen + wskaźnik "ostatnio obejrzane" na użytkowniku (do badge).

USE librus;

-- 1) Metadane roku szkolnego i okresów
CREATE TABLE IF NOT EXISTS school_years (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(20) NOT NULL,          -- np. "2024/2025"
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS terms (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  school_year_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL,          -- np. "Okres 1", "Okres 2"
  ordinal TINYINT UNSIGNED NOT NULL,  -- 1, 2
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_terms_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_year_ord (school_year_id, ordinal)
) ENGINE=InnoDB;

-- 2) Klasy i zapisy uczniów do klas
CREATE TABLE IF NOT EXISTS school_classes (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,          -- np. "1A"
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  enrolled_at DATE DEFAULT NULL,
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_student_class (student_id, class_id)
) ENGINE=InnoDB;

-- 3) Przedmioty i kategorie ocen
CREATE TABLE IF NOT EXISTS subjects (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  short_name VARCHAR(20) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_subject (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grade_categories (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(8) NOT NULL,       -- np. "odg", "spr", "kart", "odp"
  name VARCHAR(60) NOT NULL,      -- "odpowiedź", "sprawdzian", ...
  weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  color CHAR(7) DEFAULT NULL,     -- np. #ef4444 (fallback w UI gdy NULL)
  counts_to_avg TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_gcat (code)
) ENGINE=InnoDB;

INSERT IGNORE INTO grade_categories (id, code, name, weight, color, counts_to_avg) VALUES
  (1,'odp','odpowiedź ustna',1.00,'#fde047',1),
  (2,'kart','kartkówka',1.00,'#f59e0b',1),
  (3,'spr','sprawdzian',2.00,'#ef4444',1),
  (4,'zad','zadanie domowe',0.50,'#86efac',1),
  (5,'akty','aktywność',0.50,'#a7f3d0',1),
  (6,'info','informacyjna',0.00,'#cbd5e1',0);

-- 4) Przydziały nauczyciel–przedmiot–klasa (przygotowanie pod wystawianie ocen)
CREATE TABLE IF NOT EXISTS teacher_subjects (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  teacher_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_tsc (teacher_id, class_id, subject_id)
) ENGINE=InnoDB;

-- 5) Oceny
CREATE TABLE IF NOT EXISTS grades (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED DEFAULT NULL,
  term_id INT UNSIGNED DEFAULT NULL,
  category_id TINYINT UNSIGNED DEFAULT NULL,
  kind ENUM('regular','midterm','final') NOT NULL DEFAULT 'regular',
  value_text VARCHAR(8) NOT NULL,       -- np. "5", "4+", "np", "bz"
  value_numeric DECIMAL(4,2) DEFAULT NULL, -- do średniej (NULL gdy np./bz)
  weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  counts_to_avg TINYINT(1) NOT NULL DEFAULT 1,
  comment TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME DEFAULT NULL,   -- możliwość publikacji z opóźnieniem
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_g_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_g_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_g_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_g_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_g_cat FOREIGN KEY (category_id) REFERENCES grade_categories(id) ON DELETE SET NULL,
  KEY idx_g_student_term (student_id, term_id),
  KEY idx_g_subject_term (subject_id, term_id),
  KEY idx_g_created (created_at)
) ENGINE=InnoDB;

-- 6) Ostatnia wizyta w module ocen – do licznika "nowych"
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS last_grades_seen_at DATETIME NULL AFTER updated_at;
