# Implementasi 6 Step Pembelajaran Materi

## Overview
Sistem pembelajaran materi telah diperbarui dengan tampilan **6 step yang terstruktur dan terhubung seperti timeline** untuk memberikan pengalaman belajar yang lebih baik bagi siswa.

## 6 Langkah Pembelajaran

### Step 1: Video Pembelajaran
- Menampilkan video dari YouTube atau file lokal
- Guru dapat mengunggah video pembelajaran
- Siswa dapat menonton video langsung di platform

### Step 2: Quick Quiz
- Terdiri dari 1 soal pilihan ganda
- Mendukung gambar/tebak gambar
- Siswa mendapat feedback langsung (benar/salah)
- Hanya boleh dijawab sekali per siswa

### Step 3: Simulasi Reaksi
- Simulasi interaktif reaksi kimia
- Menampilkan atom, ikatan, dan produk reaksi
- Siswa dapat mengeklik atom untuk melihat reaksi terjadi
- Menampilkan informasi lengkap tentang reaksi

### Step 4: Materi (NEW!)
- Menampilkan catatan materi lengkap
- Content text dengan format yang menarik
- Opsional - dapat kosong jika tidak ada catatan
- Tempat untuk penjelasan detail konsep

### Step 5: Quiz Pilihan Ganda (PG)
- Kumpulan soal lengkap pilihan ganda
- Multiple soal sesuai yang guru buat
- Scoring dan feedback otomatis
- Dapat diulang sesuai kebijakan guru

### Step 6: Games
Terdiri dari dua tipe game:
- **Tarik Garis**: Pasangkan istilah dengan jawaban yang benar
- **Cari Kata**: Cari kata target berdasarkan petunjuk yang diberikan

## Database Schema

### Tabel Baru yang Ditambahkan

#### quick_quizzes
```sql
CREATE TABLE quick_quizzes (
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
)
```

#### quick_quiz_attempts
```sql
CREATE TABLE quick_quiz_attempts (
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
)
```

## Cara Menggunakan

### Untuk Guru - Membuat Quick Quiz

1. Buka halaman materi yang ingin ditambahkan quick quiz
2. Siapkan:
   - Pertanyaan soal
   - Gambar (opsional - untuk tebak gambar)
   - 4 pilihan jawaban (A, B, C, D)
   - Jawaban yang benar

3. Insert data ke tabel `quick_quizzes` melalui admin panel (jika tersedia) atau langsung ke database:

```sql
INSERT INTO quick_quizzes 
(material_id, created_by, question_text, image_url, option_a, option_b, option_c, option_d, correct_option, is_published) 
VALUES 
(1, 1, 'Pertanyaan soal?', 'storage/uploads/quiz-image.jpg', 'Jawaban A', 'Jawaban B', 'Jawaban C', 'Jawaban D', 'a', 1);
```

### Untuk Siswa - Mengerjakan Materi

1. Masuk ke halaman Materi Guru
2. Pilih materi yang ingin dipelajari
3. Ikuti 5 langkah pembelajaran secara berurutan:
   - Tonton video pembelajaran
   - Kerjakan quick quiz
   - Lihat simulasi reaksi
   - Kerjakan quiz PG lengkap
   - Mainkan games pembelajaran
4. Navigasi menggunakan tombol "Sebelumnya" dan "Selanjutnya" atau klik nomor step

## File yang Dimodifikasi

### siswa_materi_detail.php
- Struktur utama 5 step pembelajaran
- Logika pengambilan data dari database
- Rendering konten untuk setiap step
- JavaScript untuk interaksi quick quiz

### database/schema.sql
- Tambahan tabel quick_quizzes
- Tambahan tabel quick_quiz_attempts

## Styling dan Animasi

### Animasi Step
- Fade in saat step berpindah
- Transisi smooth antar step
- Highlight pada button step yang aktif

### Responsive Design
- Layout responsif untuk mobile dan desktop
- Scrollbar custom untuk step navigation
- Touch-friendly buttons

## Fitur Tambahan

### Quick Quiz Features
- Tampilan gambar untuk tebak gambar
- Feedback instant (benar/salah)
- Status attempt terrekam di database
- Opsi tidak bisa dijawab 2x

### Step Navigation
- Tombol next/prev untuk navigasi linear
- Direct navigation ke step tertentu
- Auto-scroll ke button step yang aktif

## Testing Checklist

- [ ] Database tabel quick_quizzes dan quick_quiz_attempts sudah dibuat
- [ ] Materi dapat ditampilkan dengan 5 step
- [ ] Video loading dengan baik
- [ ] Quick quiz menampilkan soal dan gambar
- [ ] Jawaban quick quiz tersimpan ke database
- [ ] Simulasi menampilkan dengan baik
- [ ] Quiz PG link berfungsi
- [ ] Games link berfungsi
- [ ] Navigasi antar step berfungsi
- [ ] Responsive design tampil dengan baik di mobile

## Troubleshooting

### Quick Quiz tidak menampilkan soal
- Pastikan data quick_quizzes sudah ada untuk material tersebut
- Pastikan field `is_published` = 1

### Image di quick quiz tidak muncul
- Verifikasi path image_url benar
- Pastikan file image ada di storage/uploads/

### Database error
- Jalankan script SQL untuk membuat tabel quick_quizzes dan quick_quiz_attempts
- Pastikan semua foreign keys sudah benar

## Pengembangan Selanjutnya

Fitur yang bisa ditambahkan:
- Admin panel untuk CRUD quick quiz
- Leaderboard berdasarkan performa quick quiz
- Analitik performa siswa per step
- Notifikasi progress ke guru
- Sertifikat selesai per materi

---

**Dibuat**: 2026-05-07
**Versi**: 1.0
