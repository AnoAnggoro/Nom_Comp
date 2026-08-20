<?php
declare(strict_types=1);

function chemnama_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function chemnama_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function chemnama_home_data(PDO $pdo): array
{
    return [
        'stats' => $pdo->query('SELECT * FROM site_stats ORDER BY sort_order, id')->fetchAll(),
        'hero_updates' => $pdo->query('SELECT * FROM hero_updates ORDER BY sort_order, id')->fetchAll(),
        'featured_modules' => $pdo->query('SELECT * FROM featured_modules ORDER BY sort_order, id')->fetchAll(),
        'modules' => $pdo->query('SELECT * FROM modules ORDER BY sort_order, id')->fetchAll(),
        'use_cases' => $pdo->query('SELECT * FROM use_cases ORDER BY sort_order, id')->fetchAll(),
        'knowledge_tips' => $pdo->query('SELECT * FROM knowledge_tips ORDER BY sort_order, id')->fetchAll(),
    ];
}

function chemnama_current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;

    // Sesi lama/rusak tanpa data wajib dianggap belum login.
    if (!is_array($user) || !isset($user['id'], $user['name'], $user['role'])) {
        unset($_SESSION['user']);

        return null;
    }

    return $user;
}

function chemnama_is_demo_user(?array $user): bool
{
    if (!$user) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $loginId = (string) ($user['login_id'] ?? '');
    $email = strtolower(trim((string) ($user['email'] ?? '')));

    if ($role === 'guru') {
        return $loginId === '9000000001' || $email === 'guru@chemnama.id';
    }

    if ($role === 'siswa') {
        return $loginId === '1000000002' || $email === 'siswa@chemnama.id';
    }

    return false;
}

function chemnama_require_auth(?string $role = null): void
{
    $user = chemnama_current_user();
    
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    
    if ($role !== null && $user['role'] !== $role) {
        http_response_code(403);
        die('Akses ditolak. Halaman ini hanya untuk ' . chemnama_role_label($role));
    }
}

function chemnama_role_label(string $role): string
{
    return $role === 'guru' ? 'Guru' : 'Siswa';
}

function chemnama_role_login_label(string $role): string
{
    return $role === 'guru' ? 'NIP' : 'NIS';
}

function chemnama_role_access_code(string $role): string
{
    if ($role === 'guru') {
        return (string) (getenv('CHEMNAMA_GURU_ACCESS_CODE') ?: 'GURU2026');
    }

    return (string) (getenv('CHEMNAMA_SISWA_ACCESS_CODE') ?: 'SISWA2026');
}

function chemnama_normalize_login_id(string $loginId): string
{
    $normalized = preg_replace('/\s+/', '', strtoupper(trim($loginId)));
    return (string) $normalized;
}

function chemnama_is_valid_login_id(string $role, string $loginId): bool
{
    $normalized = chemnama_normalize_login_id($loginId);
    if ($role === 'guru') {
        return preg_match('/^[0-9]{8,20}$/', $normalized) === 1;
    }

    return preg_match('/^[0-9]{5,20}$/', $normalized) === 1;
}

function chemnama_generate_login_id_from_user(string $role, int $userId): string
{
    if ($role === 'guru') {
        return '9' . str_pad((string) $userId, 9, '0', STR_PAD_LEFT);
    }

    return '1' . str_pad((string) $userId, 9, '0', STR_PAD_LEFT);
}

function chemnama_generate_temporary_password(int $length = 10): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function chemnama_reset_user_password(PDO $pdo, int $userId, string $newPassword): bool
{
    try {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        return $stmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function chemnama_icon(string $name, string $accent = '#0f9d58'): string
{
    $stroke = chemnama_e($accent);

    $icons = [
        'brand' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5 19 7v10l-7 3.5L5 17V7l7-3.5Z" stroke="currentColor" stroke-width="1.8"/><path d="M8 12h8M12 8v8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'flask' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 3h6M10 3v5.2L5.9 16a4 4 0 0 0 3.5 6h5.2a4 4 0 0 0 3.5-6L14 8.2V3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.5 14h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'atom' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="2.6" fill="currentColor"/><ellipse cx="12" cy="12" rx="8.5" ry="3.5" stroke="currentColor" stroke-width="1.6"/><ellipse cx="12" cy="12" rx="8.5" ry="3.5" stroke="currentColor" stroke-width="1.6" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="8.5" ry="3.5" stroke="currentColor" stroke-width="1.6" transform="rotate(120 12 12)"/></svg>',
        'link' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 14.5 8 16.5a4 4 0 0 1-5.7 0 4 4 0 0 1 0-5.7l2.5-2.5a4 4 0 0 1 5.7 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 9.5 16 7.5a4 4 0 1 1 5.7 5.7l-2.5 2.5a4 4 0 0 1-5.7 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'compare' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="7" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="8" width="7" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M11 12h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'nodes' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="6" cy="6" r="2" fill="currentColor"/><circle cx="18" cy="6" r="2" fill="currentColor"/><circle cx="12" cy="18" r="2" fill="currentColor"/><path d="M8 7.5 10.8 11M16 7.5 13.2 11M12 16v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'droplet' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3c3.5 4.1 6 7.2 6 10.3A6 6 0 1 1 6 13.3C6 10.2 8.5 7.1 12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
        'drop' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4c2.8 3.2 5 5.8 5 8.2A5 5 0 1 1 7 12.2C7 9.8 9.2 7.2 12 4Z" fill="currentColor"/></svg>',
        'pill' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7.5 16.5 16.5 7.5a4 4 0 1 1 5.7 5.7l-9 9a4 4 0 1 1-5.7-5.7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13.5 10.5 18 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'burger' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12.5h16M5 9.5c0-2.2 3.1-4 7-4s7 1.8 7 4M5 15.5c0 2.2 3.1 4 7 4s7-1.8 7-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'home' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5 12 5l8 6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 10.5V19h11V10.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 19v-5h4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'rocket' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 4c3.4.3 5.7 2.6 6 6-1.6.2-2.9.5-4.2 1.6l-3.4 3.4-2.4-2.4 3.4-3.4C13.5 6.9 13.8 5.6 14 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9.8 12.6-3.6 1.2L4 20l6.2-2.2 1.2-3.6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="16.5" cy="7.5" r="1.2" fill="currentColor"/></svg>',
        'dna' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4c4 4 6 6 10 10M17 4C13 8 11 10 7 14M8 9h8M8 15h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'cap' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m4 9 8-4 8 4-8 4-8-4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 11v3c0 1.2 1.8 2.2 4 2.2s4-1 4-2.2v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 9v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'eye' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.8"/></svg>',
        'eye-off' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m4 4 16 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M10.6 10.6A2.6 2.6 0 0 0 13.4 13.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M6.2 6.8C4.5 8.2 3.4 10 2.5 12c0 0 3.5 6.5 9.5 6.5 1.4 0 2.7-.2 3.8-.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.6 5.5A10.4 10.4 0 0 1 12 5.2C18 5.2 21.5 12 21.5 12c-.7 1.5-1.6 2.9-2.8 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'idea' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 18h6M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 3a7 7 0 0 0-4 12.7V17h8v-1.3A7 7 0 0 0 12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
        'file' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.8h6l4 4V20H7z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13 3.8V8h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'video' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="6" width="13" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="m17 10 3-2v8l-3-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'task' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="4" width="14" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="m8 11 2 2 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="2.4" stroke="currentColor" stroke-width="1.8"/><circle cx="16.5" cy="8.5" r="2" stroke="currentColor" stroke-width="1.8"/><path d="M4.5 18c.6-2.5 2.6-4 4.5-4s3.9 1.5 4.5 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 17c.4-1.8 1.8-2.9 3.3-2.9 1.1 0 2.2.6 2.9 1.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'stack' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><ellipse cx="12" cy="6.5" rx="6" ry="2.8" stroke="currentColor" stroke-width="1.8"/><path d="M6 6.5v4c0 1.5 2.7 2.8 6 2.8s6-1.3 6-2.8v-4" stroke="currentColor" stroke-width="1.8"/><path d="M6 12v4c0 1.5 2.7 2.8 6 2.8s6-1.3 6-2.8v-4" stroke="currentColor" stroke-width="1.8"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/><path d="M12 8v4l2.8 1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 19V8M11 19V5M17 19v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M3.8 19.5h16.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 16.8 9.6-9.6 2.2 2.2L7.2 19H5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m13.4 8.4 2.2 2.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'chat' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 6.2h14v9.2H9.2L5 18.8V6.2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 10h6M9 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.8"/><path d="M5 19c.7-3 3.2-4.8 7-4.8s6.3 1.8 7 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'switch' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 7h11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m14 4 3 3-3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 17H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m10 14-3 3 3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 5H6v14h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 8 18 12 14 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'bell' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6.8 15.5h10.4l-1-1.6v-3.1a4.2 4.2 0 1 0-8.4 0v3.1l-1 1.6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10.2 18a2 2 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'trash' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 7h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M9 7V5h6v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 9v9m4-9v9m4-9v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M6.5 7h11l-1 13h-9l-1-13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
        'upload' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 16V6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m8.8 9.2 3.2-3.2 3.2 3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 18.5h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'magic' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20 14.5 9.5l2 2L6 22z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m13 4 1 2 2 1-2 1-1 2-1-2-2-1 2-1zM19 10l.8 1.4L21 12l-1.2.6L19 14l-.8-1.4L17 12l1.2-.6z" fill="currentColor"/></svg>',
        'clip' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m8.8 12.5 5.9-5.9a3 3 0 1 1 4.2 4.2l-7.6 7.6a5 5 0 1 1-7.1-7.1l7.9-7.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'check' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6 18 18M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'info' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><path d="M12 8v0.01M12 12v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    ];

    $markup = $icons[$name] ?? $icons['brand'];

    return '<span class="icon-mark" style="color:' . $stroke . '">' . $markup . '</span>';
}

function chemnama_flash(?string $message = null, string $type = 'info'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function chemnama_format_date(?string $dateString, string $format = 'd M Y'): string
{
    if (empty($dateString)) {
        return '-';
    }
    
    try {
        $date = new DateTime($dateString);
        $locale = 'id_ID.UTF-8';
        setlocale(LC_TIME, $locale);
        
        // Map format and month names to Indonesian
        $monthNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];
        
        $month = $monthNames[(int)$date->format('n')];
        $day = $date->format('d');
        $year = $date->format('Y');
        
        return "$day $month $year";
    } catch (Exception $e) {
        return '-';
    }
}

function chemnama_normalize_class_name(string $kelas): string
{
    $kelas = trim(preg_replace('/\s+/', ' ', $kelas) ?? '');
    return $kelas;
}

function chemnama_is_valid_class_name(string $kelas): bool
{
    $kelas = chemnama_normalize_class_name($kelas);
    if ($kelas === '' || strlen($kelas) > 30) {
        return false;
    }

    return preg_match('/^[A-Za-z0-9 .\/-]+$/', $kelas) === 1;
}

function chemnama_parse_class_list(string $rawClassList): array
{
    $classes = [];
    foreach (explode(',', $rawClassList) as $rawClass) {
        $kelas = chemnama_normalize_class_name($rawClass);
        if ($kelas !== '') {
            $classes[] = $kelas;
        }
    }

    return array_values(array_unique($classes));
}

function chemnama_available_student_classes(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT TRIM(up.kelas) AS kelas
         FROM user_profiles up
         JOIN users u ON u.id = up.user_id
         WHERE u.role = "siswa" AND TRIM(up.kelas) <> ""
         ORDER BY up.kelas'
    );

    $classes = [];
    foreach ($stmt->fetchAll() as $row) {
        $kelas = chemnama_normalize_class_name((string) ($row['kelas'] ?? ''));
        if ($kelas !== '') {
            $classes[] = $kelas;
        }
    }

    return array_values(array_unique($classes));
}

function chemnama_standard_class_options(): array
{
    return [
        'X IPA A',
        'X IPA B',
        'X IPA C',
        'X IPA D',
        'X IPA E',
        'X IPA F',
        'X IPA G',
        'XI IPA A',
        'XI IPA B',
        'XI IPA C',
        'XI IPA D',
    ];
}

function chemnama_register_guru_class(PDO $pdo, int $guruId, string $kelas): bool
{
    $kelas = chemnama_normalize_class_name($kelas);
    if (!chemnama_is_valid_class_name($kelas)) {
        return false;
    }

    $profileStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $guruId]);
    $rawExistingClasses = (string) ($profileStmt->fetchColumn() ?: '');

    $classes = chemnama_parse_class_list($rawExistingClasses);
    if (!in_array($kelas, $classes, true)) {
        $classes[] = $kelas;
    }

    $mergedClasses = implode(', ', $classes);
    $upsertProfileStmt = $pdo->prepare(
        'INSERT INTO user_profiles (user_id, kelas)
         VALUES (:user_id, :kelas)
         ON DUPLICATE KEY UPDATE kelas = VALUES(kelas)'
    );

    return $upsertProfileStmt->execute([
        'user_id' => $guruId,
        'kelas' => $mergedClasses,
    ]);
}

function chemnama_unregister_guru_class(PDO $pdo, int $guruId, string $kelas): bool
{
    $kelas = chemnama_normalize_class_name($kelas);
    if ($kelas === '') {
        return false;
    }

    $profileStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $guruId]);
    $rawExistingClasses = (string) ($profileStmt->fetchColumn() ?: '');

    $classes = chemnama_parse_class_list($rawExistingClasses);
    $filteredClasses = array_values(array_filter(
        $classes,
        static fn (string $className): bool => $className !== $kelas
    ));

    if (count($filteredClasses) === count($classes) || count($filteredClasses) === 0) {
        return false;
    }

    $updatedClasses = implode(', ', $filteredClasses);
    $updateStmt = $pdo->prepare('UPDATE user_profiles SET kelas = :kelas WHERE user_id = :user_id');

    return $updateStmt->execute([
        'kelas' => $updatedClasses,
        'user_id' => $guruId,
    ]);
}

function chemnama_available_classes_for_guru(PDO $pdo, int $guruId): array
{
    $classes = [];

    // Get only the class(es) assigned to this guru from their profile.
    $guruClassStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
    $guruClassStmt->execute(['user_id' => $guruId]);
    $guruClass = (string) ($guruClassStmt->fetchColumn() ?: '');
    $classes = chemnama_parse_class_list($guruClass);

    if (count($classes) === 0) {
        $classes[] = 'X IPA 1';
    }

    return $classes;
}

function chemnama_set_guru_active_class(PDO $pdo, int $guruId, string $kelas): bool
{
    $kelas = trim($kelas);
    if ($kelas === '') {
        return false;
    }

    $availableClasses = chemnama_available_classes_for_guru($pdo, $guruId);
    if (!in_array($kelas, $availableClasses, true)) {
        return false;
    }

    $_SESSION['guru_active_class'] = $kelas;
    return true;
}

function chemnama_get_guru_active_class(PDO $pdo, int $guruId): string
{
    $availableClasses = chemnama_available_classes_for_guru($pdo, $guruId);
    $sessionClass = trim((string) ($_SESSION['guru_active_class'] ?? ''));

    if ($sessionClass !== '' && in_array($sessionClass, $availableClasses, true)) {
        return $sessionClass;
    }

    $defaultClass = $availableClasses[0] ?? 'X IPA 1';
    $_SESSION['guru_active_class'] = $defaultClass;
    return $defaultClass;
}

function chemnama_guru_notification_summary(PDO $pdo, int $guruId, ?string $activeClass = null): array
{
    $quizPendingStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM quiz_repeat_requests qr
         JOIN quiz_sets qs ON qs.id = qr.quiz_set_id
         WHERE qs.created_by = :guru_id AND qr.status = "pending"'
    );
    $quizPendingStmt->execute(['guru_id' => $guruId]);
    $quizPending = (int) $quizPendingStmt->fetchColumn();

    $essayParams = ['guru_id' => $guruId];
    $essaySql =
        'SELECT COUNT(*)
         FROM essay_answers ea
         JOIN essay_tasks et ON et.id = ea.task_id
         LEFT JOIN user_profiles up ON up.user_id = ea.student_id
         WHERE et.created_by = :guru_id
           AND ea.graded_at IS NULL';
    if ($activeClass !== null && trim($activeClass) !== '') {
        $essaySql .= ' AND et.class_name = :kelas_task';
        $essayParams['kelas_task'] = $activeClass;
    }
    $essayPendingStmt = $pdo->prepare($essaySql);
    $essayPendingStmt->execute($essayParams);
    $essayPending = (int) $essayPendingStmt->fetchColumn();

    $homeworkParams = ['guru_id' => $guruId];
    $homeworkSql =
        'SELECT COUNT(*)
         FROM homework_submissions hs
         JOIN homework_tasks ht ON ht.id = hs.task_id
         LEFT JOIN user_profiles up ON up.user_id = hs.student_id
         WHERE ht.created_by = :guru_id
           AND hs.is_checked = 0';
    if ($activeClass !== null && trim($activeClass) !== '') {
        $homeworkSql .= ' AND ht.class_name = :kelas_task';
        $homeworkParams['kelas_task'] = $activeClass;
    }
    $homeworkPendingStmt = $pdo->prepare($homeworkSql);
    $homeworkPendingStmt->execute($homeworkParams);
    $homeworkPending = (int) $homeworkPendingStmt->fetchColumn();

    return [
        'quiz_repeat_pending' => $quizPending,
        'essay_pending' => $essayPending,
        'homework_pending' => $homeworkPending,
        'total' => $quizPending + $essayPending + $homeworkPending,
    ];
}

function chemnama_ensure_material_reads_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS material_reads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            material_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_material_reads_student_material (material_id, student_id),
            KEY idx_material_reads_student (student_id),
            CONSTRAINT fk_material_reads_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
            CONSTRAINT fk_material_reads_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function chemnama_mark_material_as_read(PDO $pdo, int $materialId, int $studentId): bool
{
    chemnama_ensure_material_reads_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO material_reads (material_id, student_id, read_at)
         VALUES (:material_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
    );

    return $stmt->execute([
        'material_id' => $materialId,
        'student_id' => $studentId,
    ]);
}

function chemnama_is_material_read(PDO $pdo, int $materialId, int $studentId): bool
{
    chemnama_ensure_material_reads_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM material_reads
         WHERE material_id = :material_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'material_id' => $materialId,
        'student_id' => $studentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function chemnama_ensure_simulation_reads_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS simulation_reads (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function chemnama_mark_simulation_as_read(PDO $pdo, int $simulationId, int $studentId): bool
{
    chemnama_ensure_simulation_reads_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO simulation_reads (simulation_id, student_id, read_at)
         VALUES (:simulation_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
    );

    return $stmt->execute([
        'simulation_id' => $simulationId,
        'student_id' => $studentId,
    ]);
}

function chemnama_is_simulation_read(PDO $pdo, int $simulationId, int $studentId): bool
{
    chemnama_ensure_simulation_reads_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM simulation_reads
         WHERE simulation_id = :simulation_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'simulation_id' => $simulationId,
        'student_id' => $studentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function chemnama_ensure_chem_match_reads_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chem_match_reads (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function chemnama_mark_chem_match_as_read(PDO $pdo, int $chemMatchId, int $studentId): bool
{
    chemnama_ensure_chem_match_reads_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO chem_match_reads (chem_match_id, student_id, read_at)
         VALUES (:chem_match_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
    );

    return $stmt->execute([
        'chem_match_id' => $chemMatchId,
        'student_id' => $studentId,
    ]);
}

function chemnama_is_chem_match_read(PDO $pdo, int $chemMatchId, int $studentId): bool
{
    chemnama_ensure_chem_match_reads_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM chem_match_reads
         WHERE chem_match_id = :chem_match_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'chem_match_id' => $chemMatchId,
        'student_id' => $studentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function chemnama_ensure_word_search_reads_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS word_search_reads (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function chemnama_mark_word_search_as_read(PDO $pdo, int $wordSearchId, int $studentId): bool
{
    chemnama_ensure_word_search_reads_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO word_search_reads (word_search_id, student_id, read_at)
         VALUES (:word_search_id, :student_id, NOW())
         ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
    );

    return $stmt->execute([
        'word_search_id' => $wordSearchId,
        'student_id' => $studentId,
    ]);
}

function chemnama_is_word_search_read(PDO $pdo, int $wordSearchId, int $studentId): bool
{
    chemnama_ensure_word_search_reads_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM word_search_reads
         WHERE word_search_id = :word_search_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'word_search_id' => $wordSearchId,
        'student_id' => $studentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function chemnama_ensure_completion_time_column(PDO $pdo, string $tableName): void
{
    static $checkedTables = [];

    if (isset($checkedTables[$tableName])) {
        return;
    }

    $columnStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = "completion_time"'
    );
    $columnStmt->execute(['table_name' => $tableName]);

    if ((int) $columnStmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN completion_time INT NULL AFTER read_at');
    }

    $checkedTables[$tableName] = true;
}

function chemnama_ensure_quick_quiz_score_column(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $columnStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "quick_quiz_attempts"
           AND COLUMN_NAME = "score"'
    );
    $columnStmt->execute();

    if ((int) $columnStmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE quick_quiz_attempts ADD COLUMN score INT NULL AFTER is_correct');
    }

    $ready = true;
}

function chemnama_record_chem_match_completion_time(PDO $pdo, int $chemMatchId, int $studentId, int $completionSeconds): bool
{
    chemnama_ensure_chem_match_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'chem_match_reads');

    $stmt = $pdo->prepare(
        'INSERT INTO chem_match_reads (chem_match_id, student_id, read_at, completion_time)
         VALUES (:chem_match_id, :student_id, NOW(), :completion_time)
         ON DUPLICATE KEY UPDATE
             read_at = VALUES(read_at),
             completion_time = VALUES(completion_time)'
    );

    return $stmt->execute([
        'completion_time' => $completionSeconds,
        'chem_match_id' => $chemMatchId,
        'student_id' => $studentId,
    ]);
}

function chemnama_record_word_search_completion_time(PDO $pdo, int $wordSearchId, int $studentId, int $completionSeconds): bool
{
    chemnama_ensure_word_search_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'word_search_reads');

    $stmt = $pdo->prepare(
        'INSERT INTO word_search_reads (word_search_id, student_id, read_at, completion_time)
         VALUES (:word_search_id, :student_id, NOW(), :completion_time)
         ON DUPLICATE KEY UPDATE
             read_at = VALUES(read_at),
             completion_time = VALUES(completion_time)'
    );

    return $stmt->execute([
        'completion_time' => $completionSeconds,
        'word_search_id' => $wordSearchId,
        'student_id' => $studentId,
    ]);
}

function chemnama_record_simulation_completion_time(PDO $pdo, int $simulationId, int $studentId, int $completionSeconds): bool
{
    chemnama_ensure_simulation_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'simulation_reads');

    $stmt = $pdo->prepare(
        'INSERT INTO simulation_reads (simulation_id, student_id, read_at, completion_time)
         VALUES (:simulation_id, :student_id, NOW(), :completion_time)
         ON DUPLICATE KEY UPDATE
             read_at = VALUES(read_at),
             completion_time = VALUES(completion_time)'
    );

    return $stmt->execute([
        'completion_time' => $completionSeconds,
        'simulation_id' => $simulationId,
        'student_id' => $studentId,
    ]);
}

function chemnama_get_chem_match_leaderboard(PDO $pdo, int $chemMatchId, int $limit = 10): array
{
    chemnama_ensure_chem_match_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'chem_match_reads');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, cmr.completion_time, cmr.read_at
         FROM chem_match_reads cmr
         JOIN users u ON u.id = cmr.student_id
         WHERE cmr.chem_match_id = :chem_match_id
           AND cmr.completion_time IS NOT NULL
         ORDER BY cmr.completion_time ASC, cmr.read_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':chem_match_id', $chemMatchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chemnama_get_word_search_leaderboard(PDO $pdo, int $wordSearchId, int $limit = 10): array
{
    chemnama_ensure_word_search_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'word_search_reads');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, wsr.completion_time, wsr.read_at
         FROM word_search_reads wsr
         JOIN users u ON u.id = wsr.student_id
         WHERE wsr.word_search_id = :word_search_id
           AND wsr.completion_time IS NOT NULL
         ORDER BY wsr.completion_time ASC, wsr.read_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':word_search_id', $wordSearchId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chemnama_get_simulation_leaderboard(PDO $pdo, int $simulationId, int $limit = 10): array
{
    chemnama_ensure_simulation_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'simulation_reads');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, sr.completion_time, sr.read_at
         FROM simulation_reads sr
         JOIN users u ON u.id = sr.student_id
         WHERE sr.simulation_id = :simulation_id
           AND sr.completion_time IS NOT NULL
         ORDER BY sr.completion_time ASC, sr.read_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':simulation_id', $simulationId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chemnama_get_module_chem_match_leaderboard(PDO $pdo, int $moduleId, int $limit = 10): array
{
    chemnama_ensure_chem_match_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'chem_match_reads');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, cmr.completion_time, cmr.read_at, g.left_term, g.right_term
         FROM chem_match_reads cmr
         JOIN users u ON u.id = cmr.student_id
         JOIN chem_match_games g ON g.id = cmr.chem_match_id
         WHERE g.module_id = :module_id
           AND cmr.completion_time IS NOT NULL
         ORDER BY cmr.completion_time ASC, cmr.read_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':module_id', $moduleId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chemnama_get_module_word_search_leaderboard(PDO $pdo, int $moduleId, int $limit = 10): array
{
    chemnama_ensure_word_search_reads_table($pdo);
    chemnama_ensure_completion_time_column($pdo, 'word_search_reads');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, wsr.completion_time, wsr.read_at, g.target_word
         FROM word_search_reads wsr
         JOIN users u ON u.id = wsr.student_id
         JOIN word_search_games g ON g.id = wsr.word_search_id
         WHERE g.module_id = :module_id
           AND wsr.completion_time IS NOT NULL
         ORDER BY wsr.completion_time ASC, wsr.read_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':module_id', $moduleId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function chemnama_student_realtime_progress(PDO $pdo, int $studentId, string $studentClass): array
{
    chemnama_ensure_material_reads_table($pdo);

    $totalMaterialCountStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT m.id)
         FROM materials m
         JOIN users u ON u.id = m.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE m.is_published = 1
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0'
    );
    $totalMaterialCountStmt->execute(['kelas' => $studentClass]);
    $totalMaterialCount = (int) $totalMaterialCountStmt->fetchColumn();

    $quizStatsStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT qar.quiz_set_id) AS quiz_set_count,
                COALESCE(AVG(qar.score), 0) AS average_score
         FROM quiz_attempt_runs qar
         JOIN quiz_sets qs ON qs.id = qar.quiz_set_id
         JOIN users u ON u.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE qar.student_id = :student_id
           AND qar.submitted_at IS NOT NULL
           AND qs.is_published = 1
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0'
    );
    $quizStatsStmt->execute([
        'student_id' => $studentId,
        'kelas' => $studentClass,
    ]);
    $quizStats = $quizStatsStmt->fetch() ?: ['quiz_set_count' => 0, 'average_score' => 0];

    $quizCount = (int) ($quizStats['quiz_set_count'] ?? 0);
    $averageScore = (float) ($quizStats['average_score'] ?? 0);

    return [
        'material_count' => $totalMaterialCount,
        'unread_material_count' => $totalMaterialCount,
        'quiz_count' => $quizCount,
        'average_score' => round($averageScore, 2),
        'status_label' => ($totalMaterialCount + $quizCount) > 0 ? 'Aktif' : 'Baru',
    ];
}

function chemnama_get_student_game_completion_stats(PDO $pdo, int $studentId): array
{
    chemnama_ensure_completion_time_column($pdo, 'chem_match_reads');
    chemnama_ensure_completion_time_column($pdo, 'word_search_reads');
    chemnama_ensure_completion_time_column($pdo, 'simulation_reads');

    $chemMatchStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM chem_match_reads WHERE student_id = :student_id AND completion_time IS NOT NULL'
    );
    $chemMatchStmt->execute(['student_id' => $studentId]);
    $chemMatchCount = (int) $chemMatchStmt->fetchColumn();

    $wordSearchStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM word_search_reads WHERE student_id = :student_id AND completion_time IS NOT NULL'
    );
    $wordSearchStmt->execute(['student_id' => $studentId]);
    $wordSearchCount = (int) $wordSearchStmt->fetchColumn();

    $simulationStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM simulation_reads WHERE student_id = :student_id AND completion_time IS NOT NULL'
    );
    $simulationStmt->execute(['student_id' => $studentId]);
    $simulationCount = (int) $simulationStmt->fetchColumn();

    $totalGames = $chemMatchCount + $wordSearchCount + $simulationCount;

    return [
        'total_games_completed' => $totalGames,
        'chem_match_completed' => $chemMatchCount,
        'word_search_completed' => $wordSearchCount,
        'simulation_completed' => $simulationCount,
    ];
}

function chemnama_get_student_game_modules(PDO $pdo, int $studentId, string $gameType): array
{
    chemnama_ensure_completion_time_column($pdo, 'chem_match_reads');
    chemnama_ensure_completion_time_column($pdo, 'word_search_reads');
    chemnama_ensure_completion_time_column($pdo, 'simulation_reads');

    // Return modules that either have published games OR where the student has completion records.
    if ($gameType === 'chem_match') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT m.id, m.badge, m.title
             FROM modules m
             WHERE EXISTS (
                 SELECT 1 FROM chem_match_games cmg WHERE cmg.module_id = m.id AND cmg.is_published = 1
             )
             OR EXISTS (
                 SELECT 1 FROM chem_match_reads cmr JOIN chem_match_games cmg2 ON cmg2.id = cmr.chem_match_id
                 WHERE cmr.student_id = :student_id AND cmg2.module_id = m.id AND cmr.completion_time IS NOT NULL
             )
             ORDER BY m.sort_order, m.id'
        );
    } elseif ($gameType === 'word_search') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT m.id, m.badge, m.title
             FROM modules m
             WHERE EXISTS (
                 SELECT 1 FROM word_search_games wsg WHERE wsg.module_id = m.id AND wsg.is_published = 1
             )
             OR EXISTS (
                 SELECT 1 FROM word_search_reads wsr JOIN word_search_games wsg2 ON wsg2.id = wsr.word_search_id
                 WHERE wsr.student_id = :student_id AND wsg2.module_id = m.id AND wsr.completion_time IS NOT NULL
             )
             ORDER BY m.sort_order, m.id'
        );
    } elseif ($gameType === 'simulation') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT m.id, m.badge, m.title
             FROM modules m
             WHERE EXISTS (
                 SELECT 1 FROM simulations s WHERE s.module_id = m.id AND s.is_published = 1
             )
             OR EXISTS (
                 SELECT 1 FROM simulation_reads sr JOIN simulations s2 ON s2.id = sr.simulation_id
                 WHERE sr.student_id = :student_id AND s2.module_id = m.id AND sr.completion_time IS NOT NULL
             )
             ORDER BY m.sort_order, m.id'
        );
    } else {
        return [];
    }

    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
