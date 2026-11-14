<?php
/** Catalog importer: use each supplier's actual name, image and AZN price.
 * Import only unique real photo URLs. Stock remains zero until confirmed.
 */
function import_source_catalog(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS catalog_sync (source TEXT PRIMARY KEY, last_attempt INTEGER NOT NULL)");
    $db->exec("UPDATE products SET is_active=0 WHERE slug LIKE 'zerif-foto-kolleksiya-2026-%' OR slug LIKE 'ferqli-foto-numune-%'");
    $sources = [
        'icgeyim.az' => 'https://icgeyim.az/wp-json/wc/store/v1/products?per_page=100&page=',
        'vixenbaku.az' => 'https://vixenbaku.az/wp-json/wc/store/v1/products?per_page=100&page='
    ];
    $cats = $db->query('SELECT slug,id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $find = $db->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
    $imageOwner = $db->prepare('SELECT id FROM products WHERE image=? AND is_active=1 LIMIT 1');
    $insert = $db->prepare('INSERT INTO products(category_id,name,slug,description,material,price,old_price,sizes,colors,stock,image,gallery,is_featured,is_new,is_active,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $update = $db->prepare('UPDATE products SET category_id=?,name=?,description=?,price=?,image=?,stock=0,is_active=1 WHERE slug=?');
    foreach ($sources as $host => $api) {
        $q = $db->prepare('SELECT last_attempt FROM catalog_sync WHERE source=?');
        $q->execute([$host]);
        if (time() - (int)($q->fetchColumn() ?: 0) < 86400) continue;
        $db->prepare('INSERT INTO catalog_sync(source,last_attempt) VALUES(?,?) ON CONFLICT(source) DO UPDATE SET last_attempt=excluded.last_attempt')->execute([$host,time()]);
        $imported=0;
        for ($page=1;$page<=3;$page++) {
            $context=stream_context_create(['http'=>['timeout'=>4,'header'=>"Accept: application/json\r\nUser-Agent: ZerifShopCatalog/1.0\r\n"]]);
            $json=@file_get_contents($api.$page,false,$context);
            if (!$json) break;
            $products=json_decode($json,true);
            if (!is_array($products) || !array_is_list($products) || !$products) break;
            foreach ($products as $p) {
                if (!is_array($p) || !isset($p['id'],$p['name'],$p['prices'],$p['images'][0]['src'])) continue;
                $name=trim(html_entity_decode(strip_tags((string)$p['name']),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                $money=$p['prices'];
                if ($name==='' || ($money['currency_code']??'')!=='AZN' || !is_numeric($money['price']??null)) continue;
                $price=round((float)$money['price']/(10**(int)($money['currency_minor_unit']??2)),2);
                $image=(string)$p['images'][0]['src'];
                if ($price<30 || $price>100 || !filter_var($image,FILTER_VALIDATE_URL) || !str_starts_with($image,'https://')) continue;
                $labels=$name;
                foreach (($p['categories']??[]) as $c) if (is_array($c)) $labels.=' '.($c['name']??'');
                $labels=strtolower($labels);
                if (preg_match('/çimərlik|kupalnik|swimwear/iu',$labels)) continue;
                $category=null;
                if (preg_match('/bodi|body|bodysuit/iu',$labels)) $category='bodi';
                elseif (preg_match('/gecə|gecelik|nightwear|sabahlıq/iu',$labels)) $category='gece-geyimleri';
                elseif (preg_match('/pijama|pyjama|xalat|ev geyim|homewear/iu',$labels)) $category='ev-geyimi';
                elseif (preg_match('/dəst|dest|set|komplekt|xonça/iu',$labels)) $category='destler';
                elseif (preg_match('/büstqalter|bustqalter|sütyen|sutyen|bralette|lifçik/iu',$labels)) $category='bustqalterler';
                elseif (preg_match('/alt geyim|tuman|trusik|külot|slip|knicker/iu',$labels)) $category='trusikler';
                if (!$category || !isset($cats[$category])) continue;
                $slug='menbe-'.str_replace('.','-',$host).'-'.(int)$p['id'];
                $find->execute([$slug]);$own=$find->fetchColumn();
                $imageOwner->execute([$image]);$other=$imageOwner->fetchColumn();
                if ($other && (int)$other!==(int)$own) continue;
                $sourceUrl=(string)($p['permalink']??'https://'.$host);
                $desc='Mənbə: '.$sourceUrl.'. Şəkil və qiymət həmin məhsulundur. Zərif Shop stoku təsdiqlənməyib.';
                if ($own) $update->execute([$cats[$category],$name,$desc,$price,$image,$slug]);
                else $insert->execute([$cats[$category],$name,$slug,$desc,'',$price,null,'','',0,$image,'[]',0,1,1,date('Y-m-d H:i:s')]);
                if (++$imported>=150) break 2;
            }
            if (count($products)<100) break;
        }
    }
}
