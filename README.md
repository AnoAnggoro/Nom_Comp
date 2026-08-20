# ChemNama LMS

LMS tata nama senyawa berbasis PHP + MySQL (XAMPP) dengan tampilan profesional, login guru/siswa, dan seed data dari konten referensi.

## Cara menjalankan

1. Letakkan folder project di htdocs XAMPP.
2. Pasang dependency: `composer install` (folder `vendor/` tidak ikut di repo).
3. Nyalakan Apache dan MySQL di XAMPP Control Panel.
4. Buka aplikasi: http://localhost/PROJECT/Nom_Comp/

## Database MySQL (XAMPP)

- Nama database default: nomcomp_db
- Host default: 127.0.0.1
- Port default: 3306
- User default: root
- Password default: (kosong)

Database dan tabel akan dibuat otomatis saat aplikasi pertama kali dibuka, selama MySQL aktif.

Jika ingin mengubah konfigurasi, atur environment variable berikut:

- CHEMNAMA_DB_HOST
- CHEMNAMA_DB_PORT
- CHEMNAMA_DB_NAME
- CHEMNAMA_DB_USER
- CHEMNAMA_DB_PASS

Konfigurasi juga bisa ditaruh di file `.env` (sudah didukung otomatis dari `config/bootstrap.php`).

## Setup Email Reset ke Inbox Asli

Fitur lupa password mengirim email via SMTP (PHPMailer). Untuk inbox asli (Gmail/Yahoo/dll), gunakan SMTP provider yang benar-benar mengirim email.

### Opsi 1 (direkomendasikan): Gmail SMTP

Isi file `.env`:

- CHEMNAMA_DEBUG_MODE=0
- CHEMNAMA_SMTP_HOST=smtp.gmail.com
- CHEMNAMA_SMTP_PORT=587
- CHEMNAMA_SMTP_USERNAME=email_anda@gmail.com
- CHEMNAMA_SMTP_PASSWORD=app_password_16_karakter
- CHEMNAMA_SMTP_ENCRYPTION=tls
- CHEMNAMA_SMTP_FROM_EMAIL=email_anda@gmail.com
- CHEMNAMA_SMTP_FROM_NAME=ChemNama

Cara dapat App Password Gmail:

1. Aktifkan 2-Step Verification di akun Google.
2. Buka Google Account > Security > App passwords.
3. Buat app password baru, lalu pakai nilainya di `CHEMNAMA_SMTP_PASSWORD`.

### Opsi 2: Mailtrap Sandbox (testing)

Mailtrap sandbox hanya untuk testing, tidak mengirim ke inbox asli. Email hanya muncul di inbox Mailtrap dashboard.

## Akun demo

- Guru: guru@chemnama.id / guru123
- Siswa: siswa@chemnama.id / siswa123
