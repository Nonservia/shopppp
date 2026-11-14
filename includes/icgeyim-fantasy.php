<?php
/**
 * Incremental catalog mirror of the public WooCommerce Store API.
 *
 * Source: https://icgeyim.az/wp-json/wc/store/v1/products
 * Category: fantaziya-geyimleri
 * Imports one category page per request (up to 100 products). The cursor is
 * persisted in SQLite and repeated requests finish the full catalog safely.
 * Source images, names and AZN prices are kept without fabricated variants.
 * Existing orders/products are never deleted.
 */
function sync_icgeyim_fantasy(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS icgeyim_fantasy_sync (
        id INTEGER PRIMARY KEY CHECK(id=1),
        category_id INTEGER NOT NULL DEFAULT 0,
        next_page INTEGER NOT NULL DEFAULT 1,
        imported INTEGER NOT NULL DEFAULT 0,
        done INTEGER NOT NULL DEFAULT 0,
        last_attempt INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("INSERT OR IGNORE INTO icgeyim_fantasy_sync(id) VALUES(1)");
    $state = $db->query('SELECT * FROM icgeyim_fantasy_sync WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$state || (int)$state['done'] === 1 || time() - (int)$state['last_attempt'] < 10) return;

    // Claim the next attempt before calling the remote API to prevent traffic spikes.
    $db->prepare('UPDATE icgeyim_fantasy_sync SET last_attempt=? WHERE id=1')->execute([time()]);

    $request = static function (string $url): ?array {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => false,
            'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; ZerifShopCatalog/1.0)\r\n",
        ]]);
        $json = @file_get_contents($url, false, $context);
        if ($json === false || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) && array_is_list($data) ? $data : null;
    };
    $error = static function (string $message) use ($db): void {
        $db->prepare('UPDATE icgeyim_fantasy_sync SET last_error=? WHERE id=1')->execute([$message]);
        error_log('Icgeyim fantasy import: ' . $message);
    };

    $categoryId = (int)$state['category_id'];
    if ($categoryId <= 0) {
        // Find the real WooCommerce category ID rather than guessing it.
        for ($page = 1; $page <= 4 && $categoryId <= 0; $page++) {
            $endpoint = 'https://icgeyim.az/wp-json/wc/store/v1/products/categories?per_page=100&page=' . $page;
            $categories = $request($endpoint);
            if ($categories === null) {
                $error('Category API unavailable; next request retries');
                return;
            }
            foreach ($categories as $cat) {
                if (!is_array($cat)) continue;
                $slug = strtolower(trim((string)($cat['slug'] ?? '')));
                if (in_array($slug, ['fantaziya-geyimleri','fantazi-geyimler','fantazi-geyimleri'], true)) {
                    $categoryId = (int)($cat['id'] ?? 0);
                    break;
                }
            }
            if (count($categories) < 100) break;
        }
        if ($categoryId <= 0) {
            $error('Fantazi Geyimlər category ID not returned by source API');
            return;
        }
        $db->prepare('UPDATE icgeyim_fantasy_sync SET category_id=? WHERE id=1')->execute([$categoryId]);
    }

    // Finish the full category in a single sync attempt when the API responds.
    // Each page has a strict network timeout; cursor saves completed progress.
    if (function_exists('set_time_limit')) @set_time_limit(90);
    for ($batch = 0; $batch < 8; $batch++) {
    $page = max(1, (int)$state['next_page']);
    $url = 'https://icgeyim.az/wp-json/wc/store/v1/products?category=' . $categoryId . '&per_page=100&page=' . $page;
    $items = $request($url);
    if ($items === null) {
        // Keep the cursor unchanged if the source is temporarily unreachable.
        $error('Could not load fantasy category page ' . $page);
        return;
    }

    $db->exec("INSERT OR IGNORE INTO categories(name,slug,description,image,sort_order)
               VALUES('Fantazi Geyimlər','fantaziya-geyimleri','Fantazi iç geyimi və xüsusi gecə geyimləri','',7)");
    $categoryDbId = (int)$db->query("SELECT id FROM categories WHERE slug='fantaziya-geyimleri'")->fetchColumn();
    $find = $db->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
    $insert = $db->prepare('INSERT INTO products(category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active,created_at)
                           VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $reserved = [];
    $rows = $db->query("SELECT image FROM products WHERE is_active=1 AND image IS NOT NULL AND TRIM(image)<>''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $image) {
        $key = preg_replace('/[?#].*$/', '', trim((string)$image));
        if ($key !== '') $reserved[$key] = true;
    }
    $added = 0;
    foreach ($items as $product) {
        if (!is_array($product)) continue;
        $id = (int)($product['id'] ?? 0);
        $name = trim(html_entity_decode(strip_tags((string)($product['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $priceData = $product['prices'] ?? null;
        $imageData = $product['images'] ?? null;
        if ($id <= 0 || $name === '' || !is_array($priceData) || !is_array($imageData) || !$imageData) continue;
        if (strtoupper((string)($priceData['currency_code'] ?? '')) !== 'AZN') continue;
        $minor = (int)($priceData['currency_minor_unit'] ?? 2);
        if ($minor < 0 || $minor > 4 || !is_numeric($priceData['price'] ?? null)) continue;
        $price = round((float)$priceData['price'] / (10 ** $minor), 2);
        if ($price <= 0) continue;
        $oldPrice = null;
        if (is_numeric($priceData['regular_price'] ?? null)) {
            $regular = round((float)$priceData['regular_price'] / (10 ** $minor), 2);
            if ($regular > $price) $oldPrice = $regular;
        }
        $image = trim((string)($imageData[0]['src'] ?? ''));
        if (!filter_var($image, FILTER_VALIDATE_URL) || !str_starts_with($image, 'https://')) continue;
        if (preg_match('/\.svg(?:[?#]|$)/i', $image)) continue;
        $key = preg_replace('/[?#].*$/', '', $image);
        if (isset($reserved[$key])) continue;
        $slug = 'icgeyim-fantazi-' . $id;
        $find->execute([$slug]);
        if ($find->fetchColumn()) continue; // Never replace an admin-edited listing.
        $source = (string)($product['permalink'] ?? '');
        if (!str_starts_with($source, 'https://icgeyim.az/')) {
            $source = 'https://icgeyim.az/product-category/fantaziya-geyimleri/';
        }
        $desc = 'Məhsul və foto mənbəsi: ' . $source . '. Qiymət mənbənin AZN göstəricisidir.';
        $inStock = !array_key_exists('is_in_stock', $product) || (bool)$product['is_in_stock'];
        $stock = $inStock ? 10 : 0;
        $insert->execute([$categoryDbId,$name,$slug,$desc,'',$price,$oldPrice,'','',$stock,$image,'[]',0,1,1,date('Y-m-d H:i:s')]);
        $reserved[$key] = true;
        $added++;
    }

    $done = count($items) < 100 ? 1 : 0;
    $db->prepare('UPDATE icgeyim_fantasy_sync SET next_page=?, imported=imported+?, done=?, last_error=? WHERE id=1')
       ->execute([$page + 1,$added,$done,'']);
    $state['next_page'] = $page + 1;
    if ($done) break;
    }
}
