<?php
require __DIR__ . '/config.php';
$pdo = db();

logoutUser($pdo);
header('Location: login.php?logged_out=1');
exit;

