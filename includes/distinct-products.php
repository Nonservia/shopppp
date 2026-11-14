<?php
/**
 * Verified model photographs from public product pages.
 * Do not reuse a single image for multiple SKUs. Source-market prices
 * are used where verified in AZN; other prices are clearly shop proposals.
 * Shop inventory displayed for these manually selected products is 10 each.
 */
function add_distinct_photo_products(PDO $db): void
{
    $items = [
        ['destler','Nurteks 4484 ağ krujevalı lifçik dəsti',33.60,42.00,'https://www.mark-ha.com/images/thumbs/0002502_nurteks-sutyen-takim-4484.webp','https://www.mark-ha.com/en/nurteks-bra-set-4484','Mənbə qiyməti (icgeyim.az)'],
        ['gece-geyimleri','Nurteks 5491 qırmızı saten gecəlik',32.25,43.00,'https://www.icgiyimperisi.com/dantelli-saten-gecelik-5491-saten-gecelik-nurteks-46512-13-B.jpg','https://www.icgiyimperisi.com/dantelli-saten-gecelik-5491','Mənbə qiyməti (icgeyim.az)'],
        ['destler','Nurteks 4584 bordo straplez dəst',31.50,null,'https://www.icgiyimperisi.com/bordo-destekli-straplez-dantelli-sutyen-kulot-takim-destekli-sutyen-takim-nurteks-54769-15-B.jpg','https://www.icgiyimperisi.com/bordo-destekli-straplez-dantelli-sutyen-kulot-takim','Mağaza təklif qiyməti'],
        ['gece-geyimleri','Nurteks 5355 göy saten gecəlik və sabahlıq',57.00,76.00,'https://www.mark-ha.com/images/thumbs/0002423_nurteks-5355-saten-kimono-takim.webp','https://www.mark-ha.com/tr/nurteks-5355-saten-kimono-takim','Mənbə qiyməti (icgeyim.az)'],
        ['ev-geyimi','Nurteks 5184-3 bordo saten sabahlıq dəsti',78.00,null,'https://www.mark-ha.com/images/thumbs/0002542_nurteks-gecelik-sabahlik-takim-5184-3.webp','https://www.mark-ha.com/en/nurteks-satin-nightwear-set-5184-3','Mağaza təklif qiyməti'],
        ['destler','Nurteks 4591 ağ dantelli dəst',31.50,42.00,'https://www.mark-ha.com/images/thumbs/0002450_nurteks-4591-destekli-sutyen-takim.webp','https://www.mark-ha.com/en/nurteks-4591-push-up-bra-set','Mənbə qiyməti (icgeyim.az)'],
        ['gece-geyimleri','Nurteks 5436 qara tül gecəlik',33.00,44.00,'https://www.icgiyimperisi.com/siyah-destekli-sutyen-kapli-transparan-babydoll-gecelik-takimi-sifon-tul-gecelik-nurteks-61737-17-B.jpg','https://www.icgiyimperisi.com/siyah-destekli-sutyen-kapli-transparan-babydoll-gecelik-takimi','Mənbə qiyməti (icgeyim.az)'],
        ['ev-geyimi','Nurteks 5344 ağ saten gecəlik və xalat',79.00,null,'https://www.mark-ha.com/images/thumbs/0002568_nurteks-gecelik-sabahlik-takim-5344.webp','https://www.mark-ha.com/en/nurteks-satin-nightgown-set-5344','Mağaza təklif qiyməti'],
        ['gece-geyimleri','Nurteks uzun qırmızı saten gecəlik',54.00,null,'https://b2b.pinartekstil.com.tr/nurteks-kadin-gecelik-uzun-saten-gogus-dantelli-yirtmacli-kirmizi-m-ceyizlik-ve-fantezi-gecelik-305251-12-B.jpg','https://b2b.pinartekstil.com.tr/nurteks-kadin-gecelik-uzun-saten-gogus-dantelli-yirtmacli-kirmizi-m','Mağaza təklif qiyməti'],
        ['gece-geyimleri','Nurteks bənövşəyi krujevalı saten gecəlik',49.00,null,'https://b2b.pinartekstil.com.tr/nurteks-kadin-saten-fantazi-gecelik-dantelli-gogus-detay-mor-l-ceyizlik-ve-fantezi-babydoll-takim-295437-10-B.jpg','https://b2b.pinartekstil.com.tr/nurteks-kadin-saten-fantazi-gecelik-dantelli-gogus-detay-mor-l','Mağaza təklif qiyməti']
    ];
    $categories = $db->query('SELECT slug, id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $find = $db->prepare('SELECT id FROM products WHERE slug=?');
    $insert = $db->prepare('INSERT INTO products (category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $update = $db->prepare('UPDATE products SET is_active=1, stock=10 WHERE id=?');
    foreach ($items as $i => $v) {
        [$category,$name,$price,$old,$image,$source,$priceType] = $v;
        if (!isset($categories[$category])) continue;
        $slug = 'real-model-2026-' . sprintf('%03d',$i+1);
        $find->execute([$slug]);
        if ($id = $find->fetchColumn()) { $update->execute([$id]); continue; }
        $desc = 'Model və şəkil mənbəsi: ' . $source . '. ' . $priceType . '. Məhsul stok və ölçü təsdiqindən sonra satışa açılacaq.';
        $insert->execute([$categories[$category],$name,$slug,$desc,'',$price,$old,'','',10,$image,'[]',0,1,1,date('Y-m-d H:i:s')]);
    }
    // Deterministic photo de-duplication: new genuine model photos have priority.
    // Never delete past products because order records can still reference them.
    $all = $db->query("SELECT id,image FROM products WHERE is_active=1 AND TRIM(COALESCE(image,''))<>'' ORDER BY CASE WHEN slug LIKE 'real-model-2026-%' THEN 0 ELSE 1 END, id")->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $deactivate = $db->prepare('UPDATE products SET is_active=0 WHERE id=?');
    foreach ($all as $product) {
        $key = strtolower(preg_replace('/[?#].*$/', '', trim($product['image'])));
        if (isset($seen[$key])) $deactivate->execute([$product['id']]);
        else $seen[$key] = true;
    }
}
