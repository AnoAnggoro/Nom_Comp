-- Migration: Create Quick Quiz Tables
-- Date: 2026-05-07
-- Description: Menambahkan tabel quick_quizzes dan quick_quiz_attempts untuk fitur 5 step pembelajaran

-- Tabel untuk menyimpan soal quick quiz
CREATE TABLE IF NOT EXISTS quick_quizzes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    question_text MEDIUMTEXT NOT NULL,
    image_url VARCHAR(255) NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_option ENUM('a', 'b', 'c', 'd') NOT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quick_quizzes_material (material_id),
    KEY idx_quick_quizzes_created_by (created_by),
    CONSTRAINT fk_quick_quizzes_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    CONSTRAINT fk_quick_quizzes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel untuk menyimpan jawaban siswa terhadap quick quiz
CREATE TABLE IF NOT EXISTS quick_quiz_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quick_quiz_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    selected_option ENUM('a', 'b', 'c', 'd') NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quick_quiz_attempt_student (quick_quiz_id, student_id),
    KEY idx_quick_quiz_attempts_student (student_id),
    CONSTRAINT fk_quick_quiz_attempts_quiz FOREIGN KEY (quick_quiz_id) REFERENCES quick_quizzes(id) ON DELETE CASCADE,
    CONSTRAINT fk_quick_quiz_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
