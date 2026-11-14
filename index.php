<?php
require __DIR__ . '/includes/functions.php';

$q = fn(string $sql) => db()->query($sql)->fetchAll();
$deals = $q('SELECT * FROM products WHERE is_active = 1 AND old_price > price ORDER BY (old_price - price) / old_price DESC LIMIT 10');
$best = $q('SELECT * FROM products WHERE is_active = 1 AND is_featured = 1 ORDER BY sold_count DESC, id DESC LIMIT 10');
$newest = $q('SELECT * FROM products WHERE is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 10');
$catCounts = db()->query('SELECT category_id, COUNT(*) c FROM products WHERE is_active = 1 GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
// Use an active product's actual photograph for every category and promo.
// Avoid the old SVG category placeholders and missing uploads/demo files.
$categoryPhotos = [];
$photoRows = db()->query("SELECT category_id, image FROM products
    WHERE is_active = 1 AND image LIKE 'https://%' AND image NOT LIKE '%.svg%'
    ORDER BY is_new DESC, id DESC")->fetchAll();
foreach ($photoRows as $row) {
    $catId = (int)$row['category_id'];
    if (!isset($categoryPhotos[$catId])) $categoryPhotos[$catId] = $row['image'];
}
$bannerPhotos = [];
foreach (categories() as $c) {
    if (!empty($categoryPhotos[(int)$c['id']])) {
        $bannerPhotos[$c['slug']] = $categoryPhotos[(int)$c['id']];
    }
}

function product_row(string $title, array $items, string $more, string $icon = ''): void
{
    if (!$items) return; ?>
    <section class="block">
        <div class="block-head">
            <h2><?= $icon ?><?= e($title) ?></h2>
            <a class="more" href="<?= e($more) ?>">Hamısına bax ›</a>
        </div>
        <div class="grid grid-5"><?php foreach ($items as $p) echo product_card($p); ?></div>
    </section>
<?php }

$pageTitle = '';
require __DIR__ . '/includes/header.php';
?>
<div class="container home">
    <section class="banners" aria-label="Mağaza təklifləri">
        <a class="banner banner-main" href="<?= url('shop.php?sale=1') ?>">
            <div class="banner-text">
                <span class="banner-tag">Mövsüm endirimi</span>
                <h1>Alt paltarlarında <strong>40%-dək endirim</strong></h1>
                <span class="btn btn-white banner-cta">İndi al <span aria-hidden="true">›</span></span>
            </div>
            <?php if (!empty($bannerPhotos['destler'])): ?>
                <img src="<?= e(img_url($bannerPhotos['destler'])) ?>" alt="Alt geyim dəsti" class="banner-img" fetchpriority="high">
            <?php endif; ?>
        </a>
        <div class="banner-side">
            <a class="banner banner-small banner-dark" href="<?= url('shop.php?cat=gece-geyimleri') ?>">
                <div class="banner-text">
                    <h3>Gecə geyimləri</h3>
                    <span class="link-arrow">Kəşf et ›</span>
                </div>
                <?php if (!empty($bannerPhotos['gece-geyimleri'])): ?>
                    <img src="<?= e(img_url($bannerPhotos['gece-geyimleri'])) ?>" alt="Gecə geyimi" class="banner-img" loading="lazy">
                <?php endif; ?>
            </a>
            <a class="banner banner-small banner-light" href="<?= url('cart.php') ?>">
                <div class="banner-text">
                    <h3>İlk sifarişə <strong>10%</strong></h3>
                    <span class="code">XOS10</span>
                </div>
                <span class="banner-gift" aria-hidden="true">🎁</span>
            </a>
        </div>
    </section>

    <section class="cat-circles" aria-label="Kateqoriyalar">
        <?php foreach (categories() as $c): ?>
            <?php $photo = $categoryPhotos[(int)$c['id']] ?? ''; ?>
            <a href="<?= url('shop.php?cat=' . urlencode($c['slug'])) ?>" class="cat-circle">
                <span class="cat-circle-img">
                    <?php if ($photo): ?>
                        <img src="<?= e(img_url($photo)) ?>" alt="<?= e($c['name']) ?> kateqoriyasından məhsul" loading="lazy">
                    <?php else: ?>
                        <span class="cat-circle-fallback" aria-hidden="true"><?= '✦' ?></span>
                    <?php endif; ?>
                </span>
                <strong><?= e($c['name']) ?></strong>
                <small><?= (int)($catCounts[$c['id']] ?? 0) ?> məhsul</small>
            </a>
        <?php endforeach; ?>
        <a href="<?= url('shop.php?sale=1') ?>" class="cat-circle">
            <span class="cat-circle-img cat-circle-sale">%</span>
            <strong>Endirimlər</strong>
            <small>Ən sərfəli</small>
        </a>
    </section>

    <?php product_row('Flaş endirimlər', $deals, url('shop.php?sale=1'), '<span class="flash-ico">' . icon('bolt', 18) . '</span>'); ?>
    <?php product_row('Çox satılanlar', $best, url('shop.php?sort=popular')); ?>

    <a class="strip" href="<?= url('info.php?p=delivery') ?>">
        <?= icon('box', 22) ?> <span><strong>Gizli qablaşdırma</strong> — bağlamanın üzərində mağaza və məhsul adı yazılmır</span> <em>Ətraflı ›</em>
    </a>

    <?php product_row('Yeni gələnlər', $newest, url('shop.php?sort=new')); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
