<?php

require_once __DIR__ . '/config/session.php';

if (isset($_SESSION['user'])) {
    header('Location: /keuangan_nme/dashboard.php');
    exit;
}

header('Location: /keuangan_nme/login.php');
exit;
