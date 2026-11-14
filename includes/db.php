<?php
require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        // Enforce the shop's 30 AZN minimum across every product, including
    // inactive listings, existing inventory and future supplier imports.
    // If a former comparison price is no longer above the new selling price,
    // remove it rather than advertising a misleading discount.
    $pdo->exec("UPDATE products SET price = 30,
        old_price = CASE WHEN old_price IS NOT NULL AND old_price <= 30
            THEN NULL ELSE old_price END
        WHERE price < 30");
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
    require_once __DIR__ . '/distinct-products.php';
    add_distinct_photo_products($pdo);
    // Import one source page per request; errors never block the shop.
    try {
        require_once __DIR__ . '/icgeyim-fantasy.php';
        sync_icgeyim_fantasy($pdo);
        require_once __DIR__ . '/icgeyim-other.php';
        sync_icgeyim_other_categories($pdo);
    } catch (Throwable $e) {
        error_log('Fantasy catalog sync: ' . $e->getMessage());
    }
    return $pdo;
}
