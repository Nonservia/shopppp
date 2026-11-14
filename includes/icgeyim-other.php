<?php
/**
 * Mirror products priced >= 30 AZN from icgeyim.az into the existing shop
 * categories. Source product IDs and image URLs are unique; never reuse photos.
 * Uses a saved cursor for each source category to finish all API pages over
 * successive requests. Existing listings and order records are retained.
 */
function sync_icgeyim_other_categories(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS icgeyim_other_sync (
        source_id INTEGER PRIMARY KEY,
        source_slug TEXT NOT NULL,
        target_slug TEXT NOT NULL,
        next_page INTEGER NOT NULL DEFAULT 1,
        imported INTEGER NOT NULL DEFAULT 0,
        done INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS icgeyim_other_meta (
        id INTEGER PRIMARY KEY CHECK(id=1),
        category_loaded INTEGER NOT NULL DEFAULT 0,
        last_attempt INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("INSERT OR IGNORE INTO icgeyim_other_meta(id) VALUES(1)");
    $meta = $db->query("SELECT * FROM icgeyim_other_meta WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$meta || time() - (int)$meta['last_attempt'] < 8) return;
    $db->prepare('UPDATE icgeyim_other_meta SET last_attempt=? WHERE id=1')->execute([time()]);

    $fetch = static function (string $url): ?array {
        $ctx = stream_context_create(['http'=>[
            'timeout'=>5,
            'ignore_errors'=>false,
            'header'=>"Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; ZerifShop/1.0)\r\n"
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || $body === '') return null;
        $data = json_decode($body, true);
        return is_array($data) && array_is_list($data) ? $data : null;
    };

    // Explicit source taxonomy matching: do not accidentally import fantasy or
    // swimwear into ordinary lingerie categories.
    $targetOf = static function (string $slug, string $name): ?string {
        $s = strtolower($slug . ' ' . $name);
        if (preg_match('/fantazi|fantasy|cimerlik|çimərlik|plaj|bikini|kupalnik|aksesuar|corab|çorab|sock|kisi|kişi|usaq|uşaq/iu',$s)) return null;
        if (preg_match('/bodi|body|bodysuit/iu',$s)) return 'bodi';
        if (preg_match('/pijama|pyjama|ev.geyim|homewear/iu',$s)) return 'ev-geyimi';
        if (preg_match('/gece|gecə|gecelik|gecəlik|sabahl|nightwear/iu',$s)) return 'gece-geyimleri';
        if (preg_match('/dest|dəst|takim|takım|komplekt/iu',$s)) return 'destler';
        if (preg_match('/bust|büst|sutyen|sütyen|bra(?!nd)/iu',$s)) return 'bustqalterler';
        if (preg_match('/alt.geyim|külot|kulot|trusik|panty|slip/iu',$s)) return 'trusikler';
        return null;
    };

    if ((int)$meta['category_loaded'] !== 1) {
        $found=0;
        for ($page=1;$page<=5;$page++) {
            $cats=$fetch('https://icgeyim.az/wp-json/wc/store/v1/products/categories?per_page=100&page='.$page);
            if ($cats === null) {
                error_log('Other categories: source category endpoint unavailable');
                return;
            }
            $store=$db->prepare('INSERT OR IGNORE INTO icgeyim_other_sync(source_id,source_slug,target_slug) VALUES(?,?,?)');
            foreach ($cats as $c) {
                if (!is_array($c)) continue;
                $id=(int)($c['id']??0);
                $slug=trim((string)($c['slug']??''));
                if ($id<=0 || $slug==='') continue;
                $target=$targetOf($slug,(string)($c['name']??''));
                if ($target===null) continue;
                // Only import specifically matching leaf/top-level collections.
                $store->execute([$id,$slug,$target]);
                $found++;
            }
            if (count($cats)<100) break;
        }
        if ($found===0) {
            error_log('Other categories: no matching taxonomy IDs were found');
            return;
        }
        $db->exec('UPDATE icgeyim_other_meta SET category_loaded=1 WHERE id=1');
    }

    $targetIds=$db->query('SELECT slug,id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $sourceState=$db->query('SELECT category_id FROM icgeyim_fantasy_sync WHERE id=1')->fetchColumn();
    $fantasyId=(int)($sourceState?:0);
    $claim=$db->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
    $add=$db->prepare('INSERT INTO products(category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $used=[];
    foreach($db->query("SELECT image FROM products WHERE is_active=1 AND TRIM(COALESCE(image,''))<>''")->fetchAll(PDO::FETCH_COLUMN) as $image) {
        $used[strtolower(preg_replace('/[?#].*$/','',trim((string)$image)))] = true;
    }
    $states=$db->query("SELECT * FROM icgeyim_other_sync WHERE done=0 ORDER BY imported ASC, source_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Two pages per category per sync, capped to avoid long storefront requests.
    $requests=0;
    foreach ($states as $state) {
        if ($requests>=6) break;
        $target=(string)$state['target_slug'];
        if (!isset($targetIds[$target])) continue;
        $sourceId=(int)$state['source_id'];
        for ($pass=0;$pass<2 && $requests<6;$pass++) {
            $page=(int)$state['next_page'];
            $endpoint='https://icgeyim.az/wp-json/wc/store/v1/products?category='.$sourceId.'&per_page=100&page='.$page;
            $products=$fetch($endpoint);
            $requests++;
            if ($products===null) {
                $db->prepare('UPDATE icgeyim_other_sync SET last_error=? WHERE source_id=?')->execute(['API page '.$page.' unavailable',$sourceId]);
                break;
            }
            $n=0;
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $id=(int)($p['id']??0);
                $name=trim(html_entity_decode(strip_tags((string)($p['name']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                $prices=$p['prices']??[];
                $images=$p['images']??[];
                if ($id<=0 || $name==='' || !is_array($prices) || !is_array($images)) continue;
                if (strtoupper((string)($prices['currency_code']??''))!=='AZN' || !is_numeric($prices['price']??null)) continue;
                $minor=(int)($prices['currency_minor_unit']??2);
                if ($minor<0 || $minor>4) continue;
                $price=round((float)$prices['price']/(10**$minor),2);
                if ($price<30) continue; // Do not inflate a lower supplier price.
                // Ignore fantasy products even when the source cross-lists them.
                $productCategories=is_array($p['categories']??null)?$p['categories']:[];
                $isFantasy=false;
                foreach ($productCategories as $c) {
                    if (!is_array($c)) continue;
                    if ($fantasyId>0 && (int)($c['id']??0)===$fantasyId) {$isFantasy=true;break;}
                    if (preg_match('/fantazi|fantasy/iu',(string)($c['slug']??''))) {$isFantasy=true;break;}
                }
                if ($isFantasy) continue;
                $photo=trim((string)($images[0]['src']??''));
                if (!str_starts_with($photo,'https://') || !filter_var($photo,FILTER_VALIDATE_URL) || preg_match('/\.svg(?:[?#]|$)/i',$photo)) continue;
                $key=strtolower(preg_replace('/[?#].*$/','',$photo));
                if (isset($used[$key])) continue;
                $slug='icgeyim-model-'.$id; // one original source product = one listing
                $claim->execute([$slug]);
                if ($claim->fetchColumn()) continue;
                // Avoid duplicate source product under fantasy category.
                $claim->execute(['icgeyim-fantazi-'.$id]);
                if ($claim->fetchColumn()) continue;
                $regular=null;
                if (is_numeric($prices['regular_price']??null)) {
                    $original=round((float)$prices['regular_price']/(10**$minor),2);
                    if ($original>$price) $regular=$original;
                }
                $permalink=(string)($p['permalink']??'');
                if (!str_starts_with($permalink,'https://icgeyim.az/')) $permalink='https://icgeyim.az/';
                $stock=!empty($p['is_in_stock'])?10:0;
                $description='Orijinal məhsul və foto: '.$permalink.'. Qiymət mənbədə göstərilən AZN məbləğidir.';
                $add->execute([$targetIds[$target],$name,$slug,$description,'',$price,$regular,'','',$stock,$photo,'[]',0,1,1,date('Y-m-d H:i:s')]);
                $used[$key]=true;
                $n++;
            }
            $done=count($products)<100?1:0;
            $db->prepare('UPDATE icgeyim_other_sync SET next_page=next_page+1,imported=imported+?,done=?,last_error=? WHERE source_id=?')
               ->execute([$n,$done,'',$sourceId]);
            $state['next_page']++;
            error_log(sprintf('Other catalog: source=%s category=%s page=%d added=%d done=%d',$state['source_slug'],$target,$page,$n,$done));
            if ($done) break;
        }
    }
}
