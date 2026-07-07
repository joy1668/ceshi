<?php
require_once __DIR__ . '/components/data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/components/header.php'; ?>

    <div class="page-layout">
        <?php include __DIR__ . '/components/sidebar.php'; ?>
        <?php include __DIR__ . '/components/video-grid.php'; ?>
    </div>
</body>
</html>
