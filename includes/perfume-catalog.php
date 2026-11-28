<?php
/**
 * Qadın ətirləri — təkrar icrada adminin dəyişiklikləri qorunur.
 * Şəkillər flakonun orijinal fotosu deyil, aydın işarələnmiş illüstrasiyalardır.
 * Stok satıcı təsdiqləyənədək 0 saxlanılır.
 */
function ensure_perfume_catalog(PDO $db): void
{
    $db->exec("INSERT OR IGNORE INTO categories (name, slug, description, image, sort_order)
        VALUES ('Ətirlər', 'etirler', 'Tanınmış qadın ətirləri', 'assets/img/perfumes/etir-2026-001.svg', 9)");
    $cat = $db->prepare('SELECT id FROM categories WHERE slug = ?');
    $cat->execute(['etirler']);
    $catId = (int)$cat->fetchColumn();
    $items = [
        ['Yves Saint Laurent Libre', 39, 'assets/img/perfumes/etir-2026-001.svg'],
        ['Lancôme La Vie Est Belle', 38, 'assets/img/perfumes/etir-2026-002.svg'],
        ['Dior J\'adore', 40, 'assets/img/perfumes/etir-2026-003.svg'],
        ['Chanel Coco Mademoiselle', 40, 'assets/img/perfumes/etir-2026-004.svg'],
        ['Carolina Herrera Good Girl', 37, 'assets/img/perfumes/etir-2026-005.svg'],
        ['Yves Saint Laurent Black Opium', 37, 'assets/img/perfumes/etir-2026-006.svg'],
        ['Versace Bright Crystal', 26, 'assets/img/perfumes/etir-2026-007.svg'],
        ['Versace Crystal Noir', 28, 'assets/img/perfumes/etir-2026-008.svg'],
        ['Giorgio Armani Sì', 36, 'assets/img/perfumes/etir-2026-009.svg'],
        ['Prada Paradoxe', 39, 'assets/img/perfumes/etir-2026-010.svg'],
        ['Valentino Donna Born In Roma', 38, 'assets/img/perfumes/etir-2026-011.svg'],
        ['Gucci Flora Gorgeous Gardenia', 35, 'assets/img/perfumes/etir-2026-012.svg'],
        ['Marc Jacobs Daisy', 30, 'assets/img/perfumes/etir-2026-013.svg'],
        ['Dolce & Gabbana Light Blue', 29, 'assets/img/perfumes/etir-2026-014.svg'],
        ['Burberry Her', 36, 'assets/img/perfumes/etir-2026-015.svg'],
        ['Mugler Alien', 34, 'assets/img/perfumes/etir-2026-016.svg'],
        ['Viktor & Rolf Flowerbomb', 38, 'assets/img/perfumes/etir-2026-017.svg'],
        ['Givenchy L\'Interdit', 36, 'assets/img/perfumes/etir-2026-018.svg'],
        ['Narciso Rodriguez For Her', 34, 'assets/img/perfumes/etir-2026-019.svg'],
        ['Chloé Eau de Parfum', 32, 'assets/img/perfumes/etir-2026-020.svg'],
        ['Kenzo Flower By Kenzo', 25, 'assets/img/perfumes/etir-2026-021.svg'],
        ['Calvin Klein Euphoria', 23, 'assets/img/perfumes/etir-2026-022.svg'],
        ['Elizabeth Arden 5th Avenue', 18, 'assets/img/perfumes/etir-2026-023.svg'],
        ['Lanvin Éclat d\'Arpège', 22, 'assets/img/perfumes/etir-2026-024.svg'],
        ['Britney Spears Fantasy', 17, 'assets/img/perfumes/etir-2026-025.svg'],
        ['Ariana Grande Cloud', 29, 'assets/img/perfumes/etir-2026-026.svg'],
        ['Moschino Toy 2', 26, 'assets/img/perfumes/etir-2026-027.svg'],
        ['Lattafa Yara', 19, 'assets/img/perfumes/etir-2026-028.svg'],
        ['Zara Red Vanilla', 15, 'assets/img/perfumes/etir-2026-029.svg'],
        ['Jimmy Choo Eau de Parfum', 27, 'assets/img/perfumes/etir-2026-030.svg']
    ];
    $exists = $db->prepare('SELECT id FROM products WHERE slug = ?');
    $insert = $db->prepare('INSERT INTO products
        (category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($items as $i => $item) {
        [$name, $price, $image] = $item;
        $slug = 'etir-2026-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT);
        $exists->execute([$slug]);
        if ($exists->fetchColumn()) continue;
        $insert->execute([
            $catId, $name, $slug,
            'İllüstrativ flakon görüntüsü. Məhsulun həcmi, orijinallığı və faktiki mövcudluğu satışdan əvvəl təsdiqlənməlidir.',
            'Ətir', $price, null, '', '', 0, $image, '[]', 0, 1, 1,
        ]);
    }
}
