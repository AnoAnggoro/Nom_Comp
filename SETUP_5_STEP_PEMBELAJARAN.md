# Panduan Setup 5 Step Pembelajaran

## 📋 Persyaratan

- Database MySQL/MariaDB sudah berjalan
- Tabel-tabel dasar sudah ada (users, materials, modules, simulations, quiz_sets, questions)
- File schema.sql sudah diperbarui dengan tabel quick_quizzes

## 🚀 Setup Database

### Step 1: Import Migration File

Jalankan migration file untuk membuat tabel quick_quizzes:

```bash
# Menggunakan command line MySQL
mysql -u username -p database_name < database/migrations/001_create_quick_quiz_tables.sql

# Atau menggunakan PhpMyAdmin
# 1. Buka PhpMyAdmin
# 2. Pilih database yang digunakan
# 3. Klik tab "SQL"
# 4. Copy-paste isi file 001_create_quick_quiz_tables.sql
# 5. Klik Execute
```

### Step 2: Verifikasi Tabel

Pastikan tabel sudah dibuat dengan menjalankan query:

```sql
SHOW TABLES LIKE 'quick_quiz%';
```

Harusnya menampilkan:
- `quick_quizzes`
- `quick_quiz_attempts`

### Step 3: Import Sample Data (Opsional)

Untuk testing, import sample data:

```bash
mysql -u username -p database_name < database/seeds/quick_quizzes_sample.sql
```

## 📝 Membuat Quick Quiz Baru

### Option 1: Langsung ke Database

```sql
INSERT INTO quick_quizzes 
(material_id, created_by, question_text, image_url, option_a, option_b, option_c, option_d, correct_option, is_published) 
VALUES 
(
    [MATERIAL_ID],  -- ID materi yang ingin ditambah quiz
    [GURU_ID],      -- ID guru pembuat quiz
    'Pertanyaan Anda di sini?',
    'storage/uploads/gambar.jpg', -- atau NULL jika tidak ada gambar
    'Jawaban opsi A',
    'Jawaban opsi B',
    'Jawaban opsi C',
    'Jawaban opsi D',
    'a',  -- Jawaban yang benar (a, b, c, atau d)
    1     -- 1=published, 0=unpublished
);
```

### Option 2: Melalui Admin Panel (Jika Tersedia)

1. Login sebagai guru
2. Buka halaman "Kelola Materi"
3. Pilih materi yang ingin ditambah quick quiz
4. Klik tombol "Tambah Quick Quiz"
5. Isi form:
   - Pertanyaan
   - Upload gambar (opsional)
   - 4 pilihan jawaban
   - Pilih jawaban yang benar
   - Klik "Simpan"

## 🖼️ Menambah Gambar untuk Tebak Gambar

### Step 1: Siapkan Gambar

- Format: JPG, PNG, GIF, WEBP
- Ukuran: Direkomendasikan max 2MB
- Dimensi: Min 300x300px (disarankan 600x600px)

### Step 2: Upload ke Folder

Upload gambar ke folder: `storage/uploads/`

Struktur folder:
```
storage/
└── uploads/
    ├── avatars/
    ├── homework/
    ├── materials/
    └── gambar-quiz-1.jpg  ← Tempat gambar quiz
```

### Step 3: Update Database

Update tabel quick_quizzes dengan path gambar:

```sql
UPDATE quick_quizzes 
SET image_url = 'storage/uploads/gambar-quiz-1.jpg'
WHERE id = [QUICK_QUIZ_ID];
```

## ✅ Testing Fitur

### Test 1: Verifikasi Database

1. Buka PhpMyAdmin
2. Query: `SELECT * FROM quick_quizzes LIMIT 5;`
3. Pastikan data muncul dengan benar

### Test 2: Verifikasi Tampilan Siswa

1. Login sebagai siswa
2. Buka menu "Materi Guru"
3. Pilih materi dengan quick quiz
4. Pastikan tampilan 5 step muncul:
   - Step 1: Video ✓
   - Step 2: Quick Quiz ✓
   - Step 3: Simulasi ✓
   - Step 4: Quiz PG ✓
   - Step 5: Games ✓

### Test 3: Test Quick Quiz

1. Klik Step 2: Quick Quiz
2. Verifikasi:
   - Pertanyaan muncul ✓
   - Gambar muncul (jika ada) ✓
   - 4 pilihan jawaban muncul ✓
3. Klik salah satu jawaban
4. Verifikasi:
   - Tombol disabled ✓
   - Feedback muncul (benar/salah) ✓
   - Data tersimpan di database ✓

### Test 4: Verifikasi Stored Data

Query untuk cek jawaban siswa:

```sql
SELECT 
    qqa.id,
    qqa.quick_quiz_id,
    u.name AS student_name,
    qqa.selected_option,
    qqa.is_correct,
    qqa.attempted_at
FROM quick_quiz_attempts qqa
JOIN users u ON qqa.student_id = u.id
ORDER BY qqa.attempted_at DESC;
```

## 📊 Monitoring Progress

### Query untuk melihat progress siswa

```sql
SELECT 
    u.name AS student_name,
    COUNT(DISTINCT m.id) AS total_materi_accessed,
    COUNT(DISTINCT CASE WHEN mr.id IS NOT NULL THEN m.id END) AS total_materi_read,
    COUNT(DISTINCT CASE WHEN qqa.id IS NOT NULL THEN qqa.quick_quiz_id END) AS total_quiz_done,
    COUNT(DISTINCT CASE WHEN qqa.is_correct = 1 THEN qqa.quick_quiz_id END) AS total_quiz_correct
FROM users u
LEFT JOIN material_reads mr ON mr.student_id = u.id
LEFT JOIN materials m ON m.id = mr.material_id
LEFT JOIN quick_quizzes qq ON qq.material_id = m.id
LEFT JOIN quick_quiz_attempts qqa ON qqa.quick_quiz_id = qq.id AND qqa.student_id = u.id
WHERE u.role = 'siswa'
GROUP BY u.id, u.name
ORDER BY u.name;
```

## 🐛 Troubleshooting

### Masalah: Tabel quick_quizzes tidak ditemukan

**Solusi:**
```sql
-- Jalankan migration file lagi
-- Atau manual create:
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
```

### Masalah: Quick Quiz tidak muncul di siswa_materi_detail.php

**Solusi:**
1. Verifikasi file siswa_materi_detail.php sudah diupdate dengan versi baru
2. Clear browser cache (Ctrl+Shift+Delete)
3. Pastikan material_id di URL benar
4. Verifikasi data quick_quizzes punya `is_published = 1`

### Masalah: Gambar quick quiz tidak muncul

**Solusi:**
1. Verifikasi path gambar di database benar
2. Verifikasi file gambar ada di `storage/uploads/`
3. Check permissions folder `storage/uploads/` (755)
4. Browser console check untuk error loading image

### Masalah: Jawaban quick quiz tidak tersimpan

**Solusi:**
1. Verifikasi tabel quick_quiz_attempts sudah ada
2. Check browser console untuk JavaScript errors
3. Verifikasi database connection masih aktif
4. Check form submission di Network tab browser

## 📚 File-file Penting

```
Project Root
├── siswa_materi_detail.php          ← File utama 5 step pembelajaran (DIMODIFIKASI)
├── database/
│   ├── schema.sql                   ← Schema database (DIPERBARUI dengan tabel quick_quizzes)
│   ├── migrations/
│   │   └── 001_create_quick_quiz_tables.sql  ← Migration file untuk quick quizzes
│   └── seeds/
│       └── quick_quizzes_sample.sql ← Sample data untuk testing
├── IMPLEMENTASI_5_STEP_PEMBELAJARAN.md      ← Dokumentasi implementasi
└── storage/
    └── uploads/                     ← Folder untuk upload gambar quiz
        └── [gambar-quiz-*.jpg]      ← Gambar untuk tebak gambar
```

## 📞 Support & FAQ

### Q: Bisakah saya membuat lebih dari 1 quick quiz per materi?
A: Tidak, sistem saat ini hanya mendukung 1 quick quiz per materi. Jika ingin lebih, bisa disesuaikan di querynya.

### Q: Bisakah siswa mengubah jawaban?
A: Tidak, setelah siswa menjawab, opsi akan disabled dan tidak bisa diubah.

### Q: Bagaimana jika siswa tidak menjawab quick quiz?
A: Data tidak akan tersimpan di tabel quick_quiz_attempts sampai siswa benar-benar memilih opsi.

### Q: Apakah nilai quick quiz mempengaruhi nilai akhir?
A: Tidak, quick quiz hanya untuk formative assessment. Nilai sebenarnya berasal dari Quiz PG atau tugas lain.

---

**Terakhir Updated**: 2026-05-07
**Status**: Ready for Production
