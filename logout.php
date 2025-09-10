<?php
// logout.php
require_once __DIR__ . '/includes/functions.php';
start_secure_session();
$_SESSION = [];
session_destroy();
$base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
if ($base === '') { $base = '.'; }
header("Location: $base/index.php");
exit;
