<?php
// Cek cepat: php test_helpers.php
declare(strict_types=1);
require __DIR__ . '/app/helpers.php';

assert(chemnama_e(null) === '');
assert(chemnama_e('<b>') === '&lt;b&gt;');

$_SESSION = [];
assert(chemnama_current_user() === null);

$_SESSION['user'] = ['id' => 1, 'role' => 'siswa']; // sesi lama tanpa "name"
assert(chemnama_current_user() === null);
assert(!isset($_SESSION['user']));

$_SESSION['user'] = ['id' => 1, 'name' => 'Budi', 'role' => 'siswa'];
assert(chemnama_current_user()['name'] === 'Budi');

echo "OK\n";
