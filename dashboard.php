<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/app.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);
date_default_timezone_set('Asia/Jakarta');

$totalSantri = (int) $pdo->query('SELECT COUNT(*) FROM santri')->fetchColumn();
$santriLevelExpr = "''";
$santriJoinKelas = '';
if (column_exists($pdo, 'santri', 'tingkatan')) {
    $santriLevelExpr = 's.tingkatan';
} elseif (column_exists($pdo, 'santri', 'nama_kelas')) {
    $santriLevelExpr = 's.nama_kelas';
} elseif (column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
    $santriJoinKelas = ' LEFT JOIN kelas kls ON kls.id = s.kelas_id ';
    $santriLevelExpr = 'kls.nama_kelas';
}
$totalTingkatan = (int) $pdo->query(
    'SELECT COUNT(DISTINCT lvl) FROM (
        SELECT ' . $santriLevelExpr . ' AS lvl
        FROM santri s ' . $santriJoinKelas . '
    ) t WHERE lvl IS NOT NULL AND lvl <> ""'
)->fetchColumn();
$izinAktif = 0;
$kegiatanAktif = 'Tidak ada kegiatan aktif saat ini.';
$kegiatanAktifHint = '';
$jadwalAktifRows = [];
$jadwalNextRow = null;
$rowsJadwal = [];
$topAlpha = [];
$santriIzinAktif = [];
$aktivitasTingkatan = [];
$poinPerluTindakan = [];
$poinAmbangMin = 10;

if (table_exists($pdo, 'perizinan')) {
    $izinAktif = (int) $pdo->query("SELECT COUNT(*) FROM perizinan WHERE status_izin = 'IZIN'")->fetchColumn();
    $santriIzinAktif = $pdo->query('
        SELECT s.nama_santri, ' . $santriLevelExpr . ' AS tingkatan, i.tanggal_mulai, i.tanggal_selesai
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        ' . $santriJoinKelas . '
        WHERE i.status_izin = "IZIN"
        ORDER BY i.id DESC
        LIMIT 8
    ')->fetchAll();
}

if (table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
    ensure_jadwal_kegiatan_tempat($pdo);
    $today = date('N');
    $now = date('H:i:s');
    $statement = $pdo->prepare('
        SELECT k.nama_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND k.is_active = 1
        ORDER BY j.jam_mulai ASC
    ');
    $statement->execute([
        'hari_ke' => $today,
    ]);
    $rowsJadwal = $statement->fetchAll();
    $itemsAktif = [];
    $next = null;
    foreach ($rowsJadwal as $row) {
        $mulai = substr((string) ($row['jam_mulai'] ?? '00:00:00'), 0, 8);
        $selesai = substr((string) ($row['jam_selesai'] ?? '00:00:00'), 0, 8);
        if ($selesai < $mulai) {
            $selesai = '23:59:59';
        }
        if ($now >= $mulai && $now <= $selesai) {
            $itemsAktif[] = $row;
            continue;
        }
        if ($now < $mulai && $next === null) {
            $next = $row;
        }
    }

    if ($itemsAktif) {
        $kegiatanAktif = 'Sedang berjalan saat ini';
        $first = $itemsAktif[0];
        $kegiatanAktifHint = 'Jam ' . substr((string) $first['jam_mulai'], 0, 5) . ' - ' . substr((string) $first['jam_selesai'], 0, 5);
        $jadwalAktifRows = $itemsAktif;
    } else {
        if ($next) {
            $kegiatanAktif = 'Belum mulai. Jadwal terdekat: ' . $next['nama_kegiatan'];
            $kegiatanAktifHint = 'Jam ' . substr((string) $next['jam_mulai'], 0, 5) . ' - ' . substr((string) $next['jam_selesai'], 0, 5);
            $jadwalNextRow = $next;
        } elseif ($rowsJadwal) {
            $kegiatanAktif = 'Jadwal hari ini sudah selesai.';
        }
    }
}

$userRoleDash = (string) ($_SESSION['user']['role'] ?? '');
if (in_array($userRoleDash, ['admin', 'pengurus'], true)) {
    ensure_point_tables($pdo);
    if (table_exists($pdo, 'point_ledger')) {
        $poinAmbangMin = poin_ambang_sanksi_minimum($pdo);
        $poinPerluTindakan = poin_santri_perlu_tindakan($pdo, (int) date('n'), (int) date('Y'));
    }
}

if (table_exists($pdo, 'presensi')) {
    $startMonth = date('Y-m-01');
    $endMonth = date('Y-m-t');
    $alphaStatement = $pdo->prepare("
        SELECT s.nama_santri, {$santriLevelExpr} AS tingkatan, COUNT(p.id) AS total_alpha
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        {$santriJoinKelas}
        WHERE p.status_presensi = 'ALPA'
          AND p.tanggal_presensi BETWEEN :start_date AND :end_date
        GROUP BY s.id, s.nama_santri, {$santriLevelExpr}
        ORDER BY total_alpha DESC
        LIMIT 5
    ");
    $alphaStatement->execute([
        'start_date' => $startMonth,
        'end_date' => $endMonth,
    ]);
    $topAlpha = $alphaStatement->fetchAll();

    $activityStmt = $pdo->prepare("
        SELECT {$santriLevelExpr} AS tingkatan,
               SUM(CASE WHEN p.status_presensi = 'HADIR' THEN 1 ELSE 0 END) AS hadir,
               SUM(CASE WHEN p.status_presensi = 'IZIN' THEN 1 ELSE 0 END) AS izin,
               SUM(CASE WHEN p.status_presensi = 'SAKIT' THEN 1 ELSE 0 END) AS sakit,
               SUM(CASE WHEN p.status_presensi = 'ALPA' THEN 1 ELSE 0 END) AS alpa,
               COUNT(*) AS total
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        {$santriJoinKelas}
        WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date
        GROUP BY {$santriLevelExpr}
        ORDER BY {$santriLevelExpr} ASC
    ");
    $activityStmt->execute([
        'start_date' => $startMonth,
        'end_date' => $endMonth,
    ]);
    $aktivitasTingkatan = $activityStmt->fetchAll();
}

$namaUserDash = (string) ($_SESSION['user']['nama'] ?? 'Pengguna');
$daysId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$monthsId = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tanggalLabelDash = $daysId[(int) date('w')] . ', ' . (int) date('j') . ' ' . $monthsId[(int) date('n') - 1] . ' ' . date('Y');
$kegiatanLive = $jadwalAktifRows !== [];
$kegiatanUpcoming = !$kegiatanLive && $jadwalNextRow !== null;

$dashNamaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$dashJenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$dashAlamatPonpes = app_setting($pdo, 'alamat_ponpes', '');
$dashLogoPath = app_setting($pdo, 'logo_path', '');
$dashLogoUrl = app_setting($pdo, 'logo_url', '');
$dashLogo = $dashLogoPath !== '' ? '/keuangan_nme/' . $dashLogoPath : $dashLogoUrl;

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="dash-hero mb-4">
    <div class="dash-hero-inner">
        <div class="dash-hero-brand dash-hero-brand--top">
            <?php if ($dashLogo): ?>
                <div class="dash-hero-logo-wrap">
                    <img class="dash-hero-logo" src="<?= htmlspecialchars($dashLogo) ?>" alt="Logo <?= htmlspecialchars($dashNamaPonpes) ?>">
                </div>
            <?php else: ?>
                <div class="dash-hero-logo-wrap dash-hero-logo-wrap--placeholder">
                    <span class="dash-hero-logo-fallback"><?= htmlspecialchars(mb_strtoupper(mb_substr($dashNamaPonpes, 0, 1))) ?></span>
                </div>
            <?php endif; ?>
            <div class="dash-hero-brand-text">
                <?php if ($dashJenisPendidikan !== ''): ?>
                    <p class="dash-hero-pesantren-kicker mb-1"><?= htmlspecialchars($dashJenisPendidikan) ?></p>
                <?php endif; ?>
                <h2 class="dash-hero-pesantren mb-1"><?= htmlspecialchars($dashNamaPonpes) ?></h2>
                <?php if ($dashAlamatPonpes !== ''): ?>
                    <p class="dash-hero-pesantren-sub mb-0"><?= htmlspecialchars($dashAlamatPonpes) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="dash-hero-divider"></div>
        <div class="dash-hero-grid">
            <div class="dash-hero-greeting">
                <p class="dash-hero-kicker mb-1">Ringkasan operasional</p>
                <h1 class="dash-hero-title h3 mb-2">Halo, <?= htmlspecialchars($namaUserDash) ?></h1>
                <p class="dash-hero-date mb-0"><?= htmlspecialchars($tanggalLabelDash) ?> · <span class="font-monospace fw-semibold dash-live-clock">--:--:--</span> WIB</p>
            </div>
            <div class="dash-hero-summary">
                <span class="dash-hero-badge">Dashboard</span>
                <p class="small mb-0 mt-2 opacity-90">Pantau santri, presensi, perizinan, jadwal, dan poin kedisiplinan dari satu halaman.</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 g-md-4 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm dash-kpi h-100 dash-kpi--santri">
            <div class="card-body">
                <div class="dash-kpi-label">Santri terdaftar</div>
                <div class="dash-kpi-value"><?= $totalSantri ?></div>
                <div class="dash-kpi-hint">Total di basis data</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm dash-kpi h-100 dash-kpi--tingkat">
            <div class="card-body">
                <div class="dash-kpi-label">Kelompok tingkatan</div>
                <div class="dash-kpi-value"><?= $totalTingkatan ?></div>
                <div class="dash-kpi-hint">Tingkatan unik yang dipakai</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm dash-kpi h-100 dash-kpi--izin">
            <div class="card-body">
                <div class="dash-kpi-label">Sedang izin</div>
                <div class="dash-kpi-value"><?= $izinAktif ?></div>
                <div class="dash-kpi-hint">Perizinan status &ldquo;Izin&rdquo; aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm dash-kpi h-100 dash-kpi--waktu">
            <div class="card-body">
                <div class="dash-kpi-label">Jam server</div>
                <div class="dash-kpi-value dash-kpi-value--clock font-monospace dash-live-clock">--:--:--</div>
                <div class="dash-kpi-hint">Sinkron presensi &amp; jadwal</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4 dash-status-card <?= $kegiatanLive ? 'dash-status-card--live' : ($kegiatanUpcoming ? 'dash-status-card--soon' : 'dash-status-card--idle') ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h5 mb-0">Jadwal &amp; kegiatan hari ini</h2>
                    <?php if ($kegiatanLive): ?>
                        <span class="badge rounded-pill dash-pill-live">Berlangsung sekarang</span>
                    <?php elseif ($kegiatanUpcoming): ?>
                        <span class="badge rounded-pill text-bg-info text-dark">Berikutnya</span>
                    <?php elseif ($rowsJadwal ?? []): ?>
                        <span class="badge rounded-pill text-bg-secondary">Jadwal hari ini selesai</span>
                    <?php else: ?>
                        <span class="badge rounded-pill text-bg-light text-dark border">Tidak ada jadwal</span>
                    <?php endif; ?>
                </div>
                <p class="dash-status-lead mb-2"><?= htmlspecialchars($kegiatanAktif) ?></p>
                <?php if ($kegiatanAktifHint !== ''): ?>
                    <p class="small text-muted mb-3 mb-md-4"><span class="fw-semibold text-body-secondary">Waktu:</span> <?= htmlspecialchars($kegiatanAktifHint) ?></p>
                <?php endif; ?>
                <?php if ($jadwalAktifRows): ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($jadwalAktifRows as $jadwalRow): ?>
                            <?php $tp = trim((string) ($jadwalRow['tempat'] ?? '')); ?>
                            <div class="dash-jadwal-row">
                                <div class="dash-jadwal-row-main">
                                    <span class="dash-jadwal-nama"><?= htmlspecialchars((string) $jadwalRow['nama_kegiatan']) ?></span>
                                    <span class="dash-jadwal-meta"><?= htmlspecialchars((string) ($jadwalRow['tingkatan'] ?: 'Semua tingkatan')) ?></span>
                                </div>
                                <div class="dash-jadwal-time font-monospace">
                                    <?= htmlspecialchars(substr((string) $jadwalRow['jam_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $jadwalRow['jam_selesai'], 0, 5)) ?>
                                </div>
                                <?php if ($tp !== ''): ?>
                                    <div class="dash-jadwal-tempat small text-muted">Lokasi: <?= htmlspecialchars($tp) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($jadwalNextRow): ?>
                    <?php $tpn = trim((string) ($jadwalNextRow['tempat'] ?? '')); ?>
                    <div class="dash-jadwal-row dash-jadwal-row--muted">
                        <div class="dash-jadwal-row-main">
                            <span class="dash-jadwal-nama"><?= htmlspecialchars((string) $jadwalNextRow['nama_kegiatan']) ?></span>
                            <span class="dash-jadwal-meta">Tingkat <?= htmlspecialchars((string) ($jadwalNextRow['tingkatan'] ?: 'semua')) ?></span>
                        </div>
                        <div class="dash-jadwal-time font-monospace">
                            <?= htmlspecialchars(substr((string) $jadwalNextRow['jam_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $jadwalNextRow['jam_selesai'], 0, 5)) ?>
                        </div>
                        <?php if ($tpn !== ''): ?>
                            <div class="dash-jadwal-tempat small text-muted">Lokasi: <?= htmlspecialchars($tpn) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="small text-muted mb-0">Tidak ada slot jadwal untuk hari ini, atau semua kegiatan sudah lewat.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 pb-0 pt-3 px-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h2 class="h5 mb-1">Sedang izin</h2>
                        <p class="small text-muted mb-0">Santri dengan periode izin yang masih berjalan.</p>
                    </div>
                    <span class="badge text-bg-warning"><?= count($santriIzinAktif) ?> santri</span>
                </div>
            </div>
            <div class="card-body pt-3">
                <?php if ($santriIzinAktif): ?>
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                        <?php foreach ($santriIzinAktif as $izin): ?>
                            <li class="dash-izin-row">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($izin['nama_santri']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($izin['tingkatan'] ?: '-') ?></div>
                                </div>
                                <div class="dash-izin-dates text-end small">
                                    <span class="text-nowrap"><?= htmlspecialchars($izin['tanggal_mulai']) ?></span>
                                    <span class="text-muted"> → </span>
                                    <span class="text-nowrap"><?= htmlspecialchars($izin['tanggal_selesai']) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="dash-empty-side text-muted small mb-0">Tidak ada santri dengan status izin aktif saat ini.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white border-0 pb-0 pt-3 px-3">
                <h2 class="h5 mb-1">Presensi bulan ini per tingkatan</h2>
                <p class="small text-muted mb-0">Batang bertumpuk: hadir, izin, sakit, alpa (<?= htmlspecialchars($monthsId[(int) date('n') - 1]) ?> <?= date('Y') ?>).</p>
            </div>
            <div class="card-body pt-2">
                <?php if ($aktivitasTingkatan): ?>
                    <div class="dash-chart-wrap">
                        <canvas id="chart-keaktifan-tingkatan"></canvas>
                    </div>
                <?php else: ?>
                    <div class="dash-empty-chart text-muted small">Belum ada data presensi di bulan ini untuk digrafikkan.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 dash-sidebar-card">
            <div class="card-header bg-transparent border-0 pt-3 pb-2 px-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h2 class="h5 mb-1">Aksi cepat</h2>
                        <p class="small text-muted mb-0">Tugas yang paling sering dipakai.</p>
                    </div>
                    <button class="btn btn-sm btn-outline-primary dash-quick-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#dash-quick-actions" aria-expanded="false" aria-controls="dash-quick-actions">
                        <span class="dash-quick-toggle-label">Tampilkan</span>
                        <span class="dash-quick-toggle-caret ms-1" aria-hidden="true">&#9662;</span>
                    </button>
                </div>
            </div>
            <div class="collapse" id="dash-quick-actions">
                <div class="card-body pt-2">
                    <div class="row g-2">
                        <div class="col-12">
                            <a class="dash-quick-tile dash-quick-tile--primary w-100" href="/keuangan_nme/presensi/scan.php">
                                <span class="dash-quick-tile-title">Scan presensi</span>
                                <span class="dash-quick-tile-desc">Catat kehadiran lewat QR</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a class="dash-quick-tile w-100" href="/keuangan_nme/perizinan/index.php">
                                <span class="dash-quick-tile-title">Input izin</span>
                                <span class="dash-quick-tile-desc">Izin &amp; surat</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a class="dash-quick-tile w-100" href="/keuangan_nme/jadwal/index.php">
                                <span class="dash-quick-tile-title">Jadwal</span>
                                <span class="dash-quick-tile-desc">Kegiatan &amp; lokasi</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a class="dash-quick-tile w-100" href="/keuangan_nme/rekap/index.php">
                                <span class="dash-quick-tile-title">Rekap bulanan</span>
                                <span class="dash-quick-tile-desc">Presensi</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a class="dash-quick-tile w-100" href="/keuangan_nme/settings/index.php">
                                <span class="dash-quick-tile-title">Pengaturan</span>
                                <span class="dash-quick-tile-desc">Sistem</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($poinPerluTindakan): ?>
        <div class="card shadow-sm mt-4 dashboard-poin-alert">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                    <div class="d-flex gap-3 align-items-center">
                        <div class="dashboard-poin-count"><?= count($poinPerluTindakan) ?></div>
                        <div>
                            <div class="fw-semibold text-danger mb-1">Perlu tindakan poin</div>
                            <div class="small text-muted">≥ <?= (int) $poinAmbangMin ?> poin · belum <em>Selesai</em> (bulan ini)</div>
                        </div>
                    </div>
                    <a class="btn btn-danger btn-sm flex-shrink-0" href="/keuangan_nme/poin/rekap.php?month=<?= (int) date('n') ?>&year=<?= (int) date('Y') ?>#perlu-tindakan">Buka rekap poin</a>
                </div>
                <hr class="my-3 opacity-25">
                <div class="small text-uppercase text-muted mb-2 fw-semibold" style="letter-spacing: 0.05em;">Daftar singkat</div>
                <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                    <?php foreach (array_slice($poinPerluTindakan, 0, 6) as $p): ?>
                        <?php
                        $pt = (int) $p['total_poin'];
                        $bc = $pt >= 75 ? 'danger' : ($pt >= 50 ? 'warning' : ($pt >= 25 ? 'warning' : 'secondary'));
                        ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 rounded border dashboard-poin-list-item px-2 py-2">
                            <span class="text-truncate"><?= htmlspecialchars((string) $p['nama_santri']) ?> <span class="text-muted">(<?= htmlspecialchars((string) ($p['tingkatan'] ?: '-')) ?>)</span></span>
                            <span class="badge text-bg-<?= $bc ?> flex-shrink-0"><?= $pt ?> poin</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($poinPerluTindakan) > 6): ?>
                    <p class="small text-muted mb-0 mt-2">+ <?= count($poinPerluTindakan) - 6 ?> santri lainnya di rekap.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white border-0 pb-0 pt-3">
                <h2 class="h5 mb-1">Alpa terbanyak</h2>
                <p class="small text-muted mb-0">Bulan <?= htmlspecialchars($monthsId[(int) date('n') - 1]) ?> — perlu perhatian.</p>
            </div>
            <div class="card-body pt-3">
                <?php if ($topAlpha): ?>
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                        <?php $rank = 0; foreach ($topAlpha as $item): $rank++; ?>
                            <li class="dash-alert-row">
                                <span class="dash-alert-rank"><?= $rank ?></span>
                                <span class="dash-alert-body">
                                    <span class="dash-alert-name"><?= htmlspecialchars($item['nama_santri']) ?></span>
                                    <span class="dash-alert-sub"><?= htmlspecialchars($item['tingkatan'] ?: '-') ?></span>
                                </span>
                                <span class="badge text-bg-danger flex-shrink-0"><?= (int) $item['total_alpha'] ?> alpa</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="dash-empty-side text-muted small mb-0">Belum ada alpa tercatat bulan ini — atau data presensi masih kosong.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const toggle = document.querySelector('.dash-quick-toggle');
        const target = document.getElementById('dash-quick-actions');
        if (!toggle || !target) return;
        const labelEl = toggle.querySelector('.dash-quick-toggle-label');
        function applyLabel(expanded) {
            if (!labelEl) return;
            labelEl.textContent = expanded ? 'Sembunyikan' : 'Tampilkan';
        }
        target.addEventListener('shown.bs.collapse', function () { applyLabel(true); });
        target.addEventListener('hidden.bs.collapse', function () { applyLabel(false); });
        applyLabel(target.classList.contains('show'));
    })();

    function updateClock() {
        const now = new Date();
        const text = now.toLocaleTimeString('id-ID');
        document.querySelectorAll('.dash-live-clock').forEach(function (el) {
            el.textContent = text;
        });
    }
    updateClock();
    setInterval(updateClock, 1000);

    <?php if ($aktivitasTingkatan): ?>
    (function () {
        const labels = <?= json_encode(array_map(static fn($row) => (string) ($row['tingkatan'] ?: '-'), $aktivitasTingkatan)) ?>;
        const hadir = <?= json_encode(array_map(static fn($row) => (int) $row['hadir'], $aktivitasTingkatan)) ?>;
        const izin = <?= json_encode(array_map(static fn($row) => (int) $row['izin'], $aktivitasTingkatan)) ?>;
        const sakit = <?= json_encode(array_map(static fn($row) => (int) $row['sakit'], $aktivitasTingkatan)) ?>;
        const alpa = <?= json_encode(array_map(static fn($row) => (int) $row['alpa'], $aktivitasTingkatan)) ?>;

        const canvas = document.getElementById('chart-keaktifan-tingkatan');
        if (!canvas) return;
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Hadir', data: hadir, backgroundColor: '#16a34a' },
                    { label: 'Izin', data: izin, backgroundColor: '#f59e0b' },
                    { label: 'Sakit', data: sakit, backgroundColor: '#3b82f6' },
                    { label: 'Alpa', data: alpa, backgroundColor: '#ef4444' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    })();
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
