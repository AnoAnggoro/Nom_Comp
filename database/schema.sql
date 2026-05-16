CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    login_id VARCHAR(40) NOT NULL,
    email VARCHAR(190) NOT NULL,
    role ENUM('guru', 'siswa') NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    avatar_color VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    avatar_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login_id (login_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_stats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(120) NOT NULL,
    value VARCHAR(120) NOT NULL,
    subtitle VARCHAR(190) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hero_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(190) NOT NULL,
    owner VARCHAR(120) NOT NULL,
    meta VARCHAR(120) NOT NULL,
    icon VARCHAR(60) NOT NULL,
    accent VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS featured_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    badge VARCHAR(60) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    accent VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    icon VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    badge VARCHAR(60) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    accent VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    icon VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS use_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    summary VARCHAR(190) NOT NULL,
    accent VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    icon VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_tips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    accent VARCHAR(20) NOT NULL DEFAULT '#0f9d58',
    icon VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    kelas VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_progress (
    user_id BIGINT UNSIGNED NOT NULL,
    material_count INT NOT NULL DEFAULT 0,
    quiz_count INT NOT NULL DEFAULT 0,
    average_score DECIMAL(5,2) NULL,
    status_label VARCHAR(60) NOT NULL DEFAULT 'Aktif',
    sort_order INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_student_progress_sort (sort_order),
    CONSTRAINT fk_student_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    type ENUM('teks', 'pdf', 'presentasi', 'video', 'animasi') NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    content_text MEDIUMTEXT NULL,
    youtube_url VARCHAR(255) NULL,
    animation_style VARCHAR(80) NULL,
    file_name VARCHAR(190) NULL,
    file_path VARCHAR(255) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_materials_module (module_id),
    KEY idx_materials_type (type),
    CONSTRAINT fk_materials_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_materials_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_sets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    question_time_limit_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quiz_sets_module (module_id),
    KEY idx_quiz_sets_created_by (created_by),
    KEY idx_quiz_sets_published (is_published),
    CONSTRAINT fk_quiz_sets_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_sets_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    quiz_set_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    question_text MEDIUMTEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_option ENUM('a', 'b', 'c', 'd') NOT NULL,
    difficulty ENUM('mudah', 'sedang', 'sulit') NOT NULL DEFAULT 'sedang',
    points INT NOT NULL DEFAULT 10,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_questions_module (module_id),
    KEY idx_questions_quiz_set (quiz_set_id),
    KEY idx_questions_published (is_published),
    CONSTRAINT fk_questions_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_questions_quiz_set FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE SET NULL,
    CONSTRAINT fk_questions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_set_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT NOT NULL DEFAULT 1,
    score INT NULL,
    correct_count INT NOT NULL DEFAULT 0,
    total_questions INT NOT NULL DEFAULT 0,
    total_points INT NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_run_student_attempt (quiz_set_id, student_id, attempt_number),
    KEY idx_quiz_runs_student (student_id),
    KEY idx_quiz_runs_set (quiz_set_id),
    CONSTRAINT fk_quiz_runs_set FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_runs_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_run_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    selected_option ENUM('a', 'b', 'c', 'd') NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_attempt_answer_question (attempt_run_id, question_id),
    KEY idx_quiz_answers_question (question_id),
    CONSTRAINT fk_quiz_answers_run FOREIGN KEY (attempt_run_id) REFERENCES quiz_attempt_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answers_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_repeat_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_set_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'used') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quiz_repeat_quiz_set (quiz_set_id),
    KEY idx_quiz_repeat_student (student_id),
    KEY idx_quiz_repeat_status (status),
    CONSTRAINT fk_quiz_repeat_set FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_repeat_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_repeat_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    selected_option ENUM('a', 'b', 'c', 'd') NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_attempt_student_question (student_id, question_id),
    KEY idx_quiz_attempt_question (question_id),
    CONSTRAINT fk_quiz_attempt_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_attempt_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS essay_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(60) NULL,
    prompt_text MEDIUMTEXT NOT NULL,
    due_at DATETIME NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_essay_tasks_module (module_id),
    KEY idx_essay_tasks_created_by (created_by),
    KEY idx_essay_tasks_class_name (class_name),
    CONSTRAINT fk_essay_tasks_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_essay_tasks_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS essay_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    answer_text MEDIUMTEXT NOT NULL,
    score INT NULL,
    feedback TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    graded_at DATETIME NULL,
    graded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_essay_answers_task_student (task_id, student_id),
    KEY idx_essay_answers_student (student_id),
    KEY idx_essay_answers_graded_by (graded_by),
    CONSTRAINT fk_essay_answers_task FOREIGN KEY (task_id) REFERENCES essay_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_essay_answers_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_essay_answers_graded_by FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS homework_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(60) NULL,
    title VARCHAR(190) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    due_at DATETIME NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_homework_tasks_module (module_id),
    KEY idx_homework_tasks_created_by (created_by),
    KEY idx_homework_tasks_class_name (class_name),
    CONSTRAINT fk_homework_tasks_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_homework_tasks_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS homework_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(190) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    teacher_feedback TEXT NULL,
    is_checked TINYINT(1) NOT NULL DEFAULT 0,
    checked_at DATETIME NULL,
    checked_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_homework_submission_task_student (task_id, student_id),
    KEY idx_homework_submission_student (student_id),
    KEY idx_homework_submission_checked_by (checked_by),
    CONSTRAINT fk_homework_submission_task FOREIGN KEY (task_id) REFERENCES homework_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_homework_submission_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_homework_submission_checked_by FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NULL,
    class_name VARCHAR(60) NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    thread_type ENUM('question', 'announcement') NOT NULL DEFAULT 'question',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_forum_threads_module (module_id),
    KEY idx_forum_threads_class (class_name),
    KEY idx_forum_threads_author (author_id),
    CONSTRAINT fk_forum_threads_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE SET NULL,
    CONSTRAINT fk_forum_threads_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_forum_replies_thread (thread_id),
    KEY idx_forum_replies_author (author_id),
    CONSTRAINT fk_forum_replies_thread FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_replies_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS simulations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(60) NULL,
    atom_first VARCHAR(80) NOT NULL,
    atom_second VARCHAR(80) NOT NULL,
    product_formula VARCHAR(80) NOT NULL,
    product_name VARCHAR(190) NOT NULL,
    bond_type VARCHAR(80) NOT NULL,
    molecule_layout VARCHAR(80) NOT NULL,
    reaction_energy VARCHAR(120) NOT NULL,
    reaction_type VARCHAR(80) NOT NULL,
    intro_description TEXT NULL,
    description_about TEXT NOT NULL,
    found_in TEXT NULL,
    daily_usage TEXT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_simulations_module (module_id),
    KEY idx_simulations_created_by (created_by),
    KEY idx_simulations_class_name (class_name),
    CONSTRAINT fk_simulations_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_simulations_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS simulation_reads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    simulation_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_simulation_reads (simulation_id, student_id),
    KEY idx_simulation_reads_student (student_id),
    CONSTRAINT fk_simulation_reads_simulation FOREIGN KEY (simulation_id) REFERENCES simulations(id) ON DELETE CASCADE,
    CONSTRAINT fk_simulation_reads_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chem_match_games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(60) NULL,
    left_term VARCHAR(120) NOT NULL,
    right_term VARCHAR(120) NOT NULL,
    hint VARCHAR(190) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chem_match_module (module_id),
    KEY idx_chem_match_created_by (created_by),
    KEY idx_chem_match_class_name (class_name),
    CONSTRAINT fk_chem_match_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_chem_match_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chem_match_reads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chem_match_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chem_match_reads (chem_match_id, student_id),
    KEY idx_chem_match_reads_student (student_id),
    CONSTRAINT fk_chem_match_reads_game FOREIGN KEY (chem_match_id) REFERENCES chem_match_games(id) ON DELETE CASCADE,
    CONSTRAINT fk_chem_match_reads_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS word_search_games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(60) NULL,
    target_word VARCHAR(120) NOT NULL,
    hint VARCHAR(190) NOT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_word_search_module (module_id),
    KEY idx_word_search_created_by (created_by),
    KEY idx_word_search_class_name (class_name),
    CONSTRAINT fk_word_search_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_word_search_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS word_search_reads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    word_search_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_word_search_reads (word_search_id, student_id),
    KEY idx_word_search_reads_student (student_id),
    CONSTRAINT fk_word_search_reads_game FOREIGN KEY (word_search_id) REFERENCES word_search_games(id) ON DELETE CASCADE,
    CONSTRAINT fk_word_search_reads_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_reads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_material_reads (material_id, student_id),
    KEY idx_material_reads_student (student_id),
    CONSTRAINT fk_material_reads_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_reads_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quick_quizzes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
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
    KEY idx_quick_quizzes_module (module_id),
    KEY idx_quick_quizzes_created_by (created_by),
    CONSTRAINT fk_quick_quizzes_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_quick_quizzes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS student_intros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    class_name VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_intros_creator (created_by),
    KEY idx_student_intros_class (class_name),
    CONSTRAINT fk_student_intros_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
