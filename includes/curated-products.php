<?php
/**
 * Distinct photographed catalog previews, November 2026.
 * Every row maps to a specific source photograph of the NAMED product,
 * not to a recycled category picture. Shop prices are illustrative AZN
 * proposals. Stock stays 0 until the seller confirms availability.
 */
function add_curated_catalog(PDO $db): void
{
    $categories = $db->query('SELECT slug, id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [
        ['destler','Qara krujevalı iç geyim dəsti',59,'https://myblacktree.com/cdn/shop/products/product-image-1669656349.jpg?v=1614161379&width=1946','https://myblacktree.com/products/women-lace-bra-sets-seamless-underwear-backless-vest-sexy-panties-padded-bralette-lingerie-ultrathin-briefs-female-intimates-f'],
        ['gece-geyimleri','Clovia çəhrayı saten gecəlik',49,'https://cdn.media.amplience.net/i/lmg/NS1145A13Black-NS1145A13CP23-10-2024_01-2100.jpg','https://www.centrepointstores.com/ae/en/buy-clovia-chic-basic-short-womens-night-dress-in-baby-pink-pretty-floral-robe-in-black-satin/p/NS1145A13CP23-10-2024'],
        ['bustqalterler','Victoria Secret ağ straplez lifçik',69,'https://images-static.nykaa.com/media/catalog/product/d/7/d709d5f1123744095D1_1.jpg?tr=w-500','https://www.nykaa.com/victoria-s-secret-body-by-victoria-lightly-lined-smooth-strapless-bra/p/18933516'],
        ['ev-geyimi','Allegra K çəhrayı saten pijama dəsti',79,'https://target.scene7.com/is/image/Target/GUEST_b4d572a6-bd25-4f7c-bead-bb80f4fae456','https://www.target.com/p/-/A-90000400'],
        ['bodi','Triumph Amourette 300 qara krujevalı bodi',85,'https://img01.ztat.net/article/spp-media-p1/150480ce12123efcab4855da4cad719e/8a3f6a1ddedb4af6806202f57eb45139.jpg?imwidth=1800','https://en.zalando.de/triumph-amourette-300-body-black-tr581s000-q11.html'],
        ['bustqalterler','Journelle qara krujevalı bralette',65,'https://www.journelle.com/cdn/shop/files/200976-3-a.jpg?v=1757366584&width=2048','https://www.journelle.com/products/jou-777884-black'],
        ['destler','Bordo dantelli lifçik və trusik dəsti',75,'https://image.made-in-china.com/2f0j00WYAbDjhrEnuq/Ladies-Sexy-Padded-Bra-Underwear-Design-and-Cutting-Delicate-Lace-Lingerie-Set.webp','https://m.made-in-china.com/product/Ladies-Sexy-Padded-Bra-Underwear-Design-and-Cutting-Delicate-Lace-Lingerie-Set-2016964362.html'],
        ['ev-geyimi','Qara saten kimono xalat',74,'https://i5.walmartimages.com/seo/Women-Black-Silk-Satin-Kimono-Robe-Lace-Bathrobe-Lingerie-Sleepwear-Pajamas_829d4e7e-c597-4ba7-bce6-0d4a2d2a7896_1.8afe6cbb342613691e1354d79de2ef77.jpeg?odnBg=FFFFFF&odnHeight=768&odnWidth=768','https://www.walmart.com/ip/Women-Black-Silk-Satin-Kimono-Robe-Lace-Bathrobe-Lingerie-Sleepwear-Pajamas/389666570'],
        ['gece-geyimleri','JUSLIO göy saten gecəlik',54,'https://i5.walmartimages.com/seo/JUSLIO-Women-Sleeveless-Satin-Sleep-Dress-Floral-Lace-Trim-V-Neck-Adjustable-Spaghetti-Straps-Short-Loose-Fit-Home-Nightgown_efbf278d-b7de-4efd-98b1-a71fbb400104.d8e292b235a4be69692f7aab4b7dcb6c.jpeg?odnBg=FFFFFF&odnHeight=573&odnWidth=573','https://www.walmart.com/ip/20993320896'],
    ];
    $find = $db->prepare('SELECT id FROM products WHERE slug = ?');
    $insert = $db->prepare('INSERT INTO products (category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $imageClaimed = [];
    foreach ($rows as $i => $item) {
        [$cat, $name, $price, $image, $source] = $item;
        if (!isset($categories[$cat]) || isset($imageClaimed[$image])) continue;
        $imageClaimed[$image] = true;
        $slug = 'yeni-foto-secim-2026-' . sprintf('%03d',$i+1);
        $find->execute([$slug]);
        if ($find->fetchColumn()) continue; // Preserve admin edits on subsequent requests.
        $description = 'Foto mənbədə göstərilən modelə aiddir: ' . $source . '. Qiymət Zərif Shop nümunə qiymətidir; anbarda mövcudluq və ölçülər təsdiqlənməyib.';
        $insert->execute([$categories[$cat],$name,$slug,$description,'',$price,null,'','',0,$image,'[]',0,1,1,date('Y-m-d H:i:s')]);
    }
    // Prefer explicitly selected new photo listings; preserve old order items.
    $products = $db->query("SELECT id, image, slug FROM products WHERE is_active = 1 AND image IS NOT NULL AND TRIM(image) != '' ORDER BY CASE WHEN slug LIKE 'yeni-foto-secim-2026-%' THEN 0 ELSE 1 END, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $hide = $db->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
    foreach ($products as $p) {
        $key = preg_replace('/\\?.*$/', '', trim($p['image']));
        if (isset($seen[$key])) $hide->execute([$p['id']]);
        else $seen[$key] = true;
    }
}
