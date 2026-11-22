<?php
require_once __DIR__ . '/../config.php';

/**
 * Keep every fantasy-category listing at 40 AZN or more with a distinct price.
 * Never lower an existing price. Stable ordering makes repeat requests idempotent.
 * The same rule covers existing listings and newly imported supplier products.
 */
function ensure_fantasy_prices(PDO $pdo): void
{
    $rows = $pdo->query("SELECT p.id, p.price FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE c.slug = 'fantaziya-geyimleri'
        ORDER BY p.price ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $used = [];
    $update = $pdo->prepare('UPDATE products SET price = ?, old_price = CASE
        WHEN old_price IS NOT NULL AND old_price <= ? THEN NULL
        ELSE old_price END WHERE id = ?');
    foreach ($rows as $row) {
        $price = max(40, (int)ceil((float)$row['price']));
        while (isset($used[$price])) $price++;
        $used[$price] = true;
        if ((float)$row['price'] !== (float)$price) {
            $update->execute([$price, $price, (int)$row['id']]);
        } else {
            $pdo->prepare('UPDATE products SET old_price = NULL WHERE id = ? AND old_price <= price')
                ->execute([(int)$row['id']]);
        }
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
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
    $pdo->exec("UPDATE products SET is_active = 0 WHERE slug LIKE 'menbe-%' OR slug LIKE 'ferqli-foto-numune-%' OR slug LIKE 'zerif-foto-kolleksiya-2026-%' OR slug LIKE 'foto-numune-%' OR slug LIKE 'birmarket-ref-2026-%'");
    $pdo->exec("UPDATE products SET is_active = 0 WHERE image IS NULL OR TRIM(image) = '' OR image LIKE '%placeholder.svg%'");
    require_once __DIR__ . '/distinct-products.php';
    add_distinct_photo_products($pdo);
    try {
        require_once __DIR__ . '/icgeyim-fantasy.php';
        sync_icgeyim_fantasy($pdo);
        require_once __DIR__ . '/icgeyim-other.php';
        sync_icgeyim_other_categories($pdo);
    } catch (Throwable $e) {
        error_log('Fantasy catalog sync: ' . $e->getMessage());
    }
    ensure_fantasy_prices($pdo);
    return $pdo;
}
