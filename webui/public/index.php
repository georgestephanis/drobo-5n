<?php
/** The page shell. The React app (assets/app.js) does the rest through api.php. */
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$v = rawurlencode(DroboWebui\VERSION . '-' . @filemtime(__DIR__ . '/assets/app.js'));
$host = htmlspecialchars((string) gethostname(), ENT_QUOTES);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $host ?> · Drobo 5N</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="root"><noscript>This dashboard needs JavaScript.</noscript></div>
<script type="module" src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
