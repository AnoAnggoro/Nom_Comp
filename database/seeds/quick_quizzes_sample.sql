-- Sample Data for Quick Quizzes
-- Contoh data untuk testing fitur 5 step pembelajaran

-- CATATAN: 
-- Sebelum menjalankan script ini, pastikan:
-- 1. Database sudah di-setup
-- 2. Tabel materials sudah ada dengan minimal 1 material dengan id=1
-- 3. Tabel users sudah ada dengan guru user dengan id minimal 1

-- Contoh Quick Quiz 1: Tebak Gambar - Konsep Dasar Senyawa
INSERT INTO quick_quizzes 
(material_id, created_by, question_text, image_url, option_a, option_b, option_c, option_d, correct_option, is_published) 
VALUES 
(
    1, 
    1, 
    'Berdasarkan gambar di atas, manakah yang merupakan ikatan ion?',
    NULL,
    'Ikatan antara dua atom non-logam',
    'Ikatan antara atom logam dan non-logam',
    'Ikatan yang melibatkan berbagi elektron',
    'Ikatan yang terbentuk dalam gas',
    'b',
    1
);

-- Contoh Quick Quiz 2
INSERT INTO quick_quizzes 
(material_id, created_by, question_text, image_url, option_a, option_b, option_c, option_d, correct_option, is_published) 
VALUES 
(
    1, 
    1, 
    'Senyawa NaCl terbentuk dari ikatan apa?',
    NULL,
    'Ikatan kovalen',
    'Ikatan ion',
    'Ikatan koordinasi',
    'Ikatan metalik',
    'b',
    1
);

-- Contoh Quick Quiz 3
INSERT INTO quick_quizzes 
(material_id, created_by, question_text, image_url, option_a, option_b, option_c, option_d, correct_option, is_published) 
VALUES 
(
    2, 
    1, 
    'Apa nama proses pembentukan senyawa baru dari dua zat?',
    NULL,
    'Reaksi Sintesis',
    'Reaksi Dekomposisi',
    'Reaksi Pertukaran',
    'Reaksi Redoks',
    'a',
    1
);

-- CATATAN: Untuk menggunakan quick quiz dengan gambar:
-- 1. Upload gambar ke folder: storage/uploads/
-- 2. Gunakan path relatif: storage/uploads/nama-file.jpg
-- Contoh:
-- INSERT INTO quick_quizzes 
-- (material_id, created_by, question_text, image_url, ...)
-- VALUES (1, 1, 'Pertanyaan?', 'storage/uploads/tebak-gambar.jpg', ...);
