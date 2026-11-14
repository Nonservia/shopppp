<?php
require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fresh = !file_exists(DB_PATH) || filesize(DB_PATH) === 0;

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    if ($fresh) {
        require_once __DIR__ . '/install.php';
        install_schema($pdo);
        seed_data($pdo);
    }
    require_once __DIR__ . '/new-collection.php';
    add_new_collection($pdo);
    // Remove the latest incorrect imported and photo-sample listings.
    // Keep historical rows so previous order references remain intact.
    $pdo->exec("UPDATE products SET is_active = 0 WHERE slug LIKE 'menbe-%' OR slug LIKE 'ferqli-foto-numune-%' OR slug LIKE 'zerif-foto-kolleksiya-2026-%' OR slug LIKE 'foto-numune-%' OR slug LIKE 'birmarket-ref-2026-%'");
    // Never display a listing without a genuine image. Preserve order references
    // and product records for the admin rather than deleting transactional data.
    $pdo->exec("UPDATE products SET is_active = 0 WHERE image IS NULL OR TRIM(image) = '' OR image LIKE '%placeholder.svg%'");
    return $pdo;
}
