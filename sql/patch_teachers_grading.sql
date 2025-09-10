/* Upewnij się, że tabele referencjonowane są w InnoDB */
ALTER TABLE users            ENGINE=InnoDB;
ALTER TABLE school_classes   ENGINE=InnoDB;
ALTER TABLE subjects         ENGINE=InnoDB;
ALTER TABLE terms            ENGINE=InnoDB;
ALTER TABLE grade_categories ENGINE=InnoDB;
ALTER TABLE grades           ENGINE=InnoDB;

/* Jeżeli wcześniej próbowano i coś zostało — czyścimy */
DROP TABLE IF EXISTS assessments;

/* Tworzymy tabelę assessments */
CREATE TABLE assessments (
  id           INT NOT NULL AUTO_INCREMENT,
  teacher_id   INT NOT NULL,
  class_id     INT NOT NULL,
  subject_id   INT NOT NULL,
  term_id      INT NULL,
  title        VARCHAR(120) NOT NULL,
  category_id  INT NULL,
  weight       DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  counts_to_avg TINYINT(1) NOT NULL DEFAULT 1,
  color        VARCHAR(7) NULL,            -- np. #FDE68A
  issue_date   DATE NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ass_teacher   (teacher_id),
  KEY idx_ass_class     (class_id),
  KEY idx_ass_subject   (subject_id),
  KEY idx_ass_term      (term_id),
  KEY idx_ass_category  (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ---- DODAJEMY brakujące kolumny do grades (tylko jeśli ich nie ma) ---- */
SET @db := DATABASE();

/* assessment_id */
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='grades' AND COLUMN_NAME='assessment_id'
);
SET @sql := IF(@exists=0,
  'ALTER TABLE grades ADD COLUMN assessment_id INT NULL AFTER kind',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* improved_of_id (self-FK do grades.id) */
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='grades' AND COLUMN_NAME='improved_of_id'
);
SET @sql := IF(@exists=0,
  'ALTER TABLE grades ADD COLUMN improved_of_id INT NULL AFTER assessment_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* Indeksy pomocnicze */
ALTER TABLE grades
  ADD INDEX idx_gr_assessment (assessment_id),
  ADD INDEX idx_gr_improved   (improved_of_id);

/* ---- KLUCZE OBCE (po utworzeniu i zindeksowaniu) ---- */
ALTER TABLE assessments
  ADD CONSTRAINT fk_ass_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
  ADD CONSTRAINT fk_ass_class    FOREIGN KEY (class_id)    REFERENCES school_classes(id)   ON DELETE CASCADE,
  ADD CONSTRAINT fk_ass_subject  FOREIGN KEY (subject_id)  REFERENCES subjects(id)         ON DELETE CASCADE,
  ADD CONSTRAINT fk_ass_term     FOREIGN KEY (term_id)     REFERENCES terms(id)            ON DELETE SET NULL,
  ADD CONSTRAINT fk_ass_cat      FOREIGN KEY (category_id) REFERENCES grade_categories(id) ON DELETE SET NULL;

ALTER TABLE grades
  ADD CONSTRAINT fk_gr_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_gr_improved   FOREIGN KEY (improved_of_id) REFERENCES grades(id)      ON DELETE SET NULL;
