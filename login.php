<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

if (isset($_SESSION['user'])) {
    header('Location: /keuangan_nme/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $isValidLogin = false;
    $userName = 'Administrator';

    if (table_exists($pdo, 'users')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi') NOT NULL DEFAULT 'pengurus'");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
        $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $isValidLogin = true;
            $userName = $user['nama'];
        }
    }

    // Akun darurat tetap bisa dipakai meskipun tabel users sudah ada.
    if (!$isValidLogin && $username === 'admin' && $password === 'admin123') {
        $isValidLogin = true;
    }

    if ($isValidLogin) {
        $isSuperAdmin = (int) ($user['is_super_admin'] ?? 0) === 1;
        if ($username === 'admin') {
            $isSuperAdmin = true;
        }
        $_SESSION['user'] = [
            'id' => (int) ($user['id'] ?? 1),
            'nama' => $userName,
            'username' => $username,
            'role' => $user['role'] ?? 'admin',
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
        ];

        set_flash('success', 'Login berhasil.');
        header('Location: /keuangan_nme/dashboard.php');
        exit;
    }

    set_flash('error', 'Username atau password salah.');
    header('Location: /keuangan_nme/login.php');
    exit;
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h3 mb-3 text-center">Login Pengurus / Petugas</h1>
                <p class="text-muted text-center">Masuk untuk mengelola data santri dan presensi.</p>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Login</button>
                </form>
                <div class="mt-3 d-grid gap-2">
                    <a href="/keuangan_nme/presensi/login.php" class="btn btn-outline-primary btn-sm">Login Petugas Presensi</a>
                    <div class="small text-muted">Akun awal: <strong>admin</strong> / <strong>admin123</strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
