<?php
require_once __DIR__ . '/config.php';

/**
 * Fetch and parse RSS/Atom feed content using cURL.
 */
function fetchFeedXml(string $url): ?string
{
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,bn;q=0.8',
            'Cache-Control: no-cache',
        ],
    ]);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 400 && is_string($content) && trim($content) !== '') {
        return $content;
    }

    return null;
}

/**
 * Parse an XML string into an array of standardized post items.
 */
function parseFeedItems(string $xmlString): array
{
    $items = [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        libxml_clear_errors();
        return [];
    }

    // 1. Standard RSS 2.0 (<rss><channel><item>)
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = trim((string)$item->title);
            $link = trim((string)$item->link);
            $guid = trim((string)$item->guid);
            if ($guid === '') {
                $guid = $link ?: md5($title);
            }
            if ($link === '') {
                $link = $guid;
            }

            $pubDateStr = trim((string)$item->pubDate);
            if ($pubDateStr === '') {
                $dc = $item->children('http://purl.org/dc/elements/1.1/');
                if (!empty($dc->date)) {
                    $pubDateStr = trim((string)$dc->date);
                }
            }
            $pubTs = $pubDateStr ? strtotime($pubDateStr) : time();
            $publishedAt = $pubTs ? date('Y-m-d H:i:s', $pubTs) : date('Y-m-d H:i:s');

            $desc = trim((string)$item->description);
            $snippet = strip_tags($desc);
            if (mb_strlen($snippet) > 280) {
                $snippet = mb_substr($snippet, 0, 277) . '...';
            }

            if ($link !== '' && stripos($title, 'Bridge returned error') === false && stripos($title, 'Bridge error') === false) {
                $items[] = [
                    'guid' => mb_substr($guid, 0, 255),
                    'url' => mb_substr($link, 0, 1000),
                    'title' => mb_substr($title ?: 'New Post', 0, 500),
                    'snippet' => $snippet,
                    'published_at' => $publishedAt,
                ];
            }
        }
        return $items;
    }

    // 2. Atom Feed (<feed><entry>) e.g. YouTube feeds, Blogger, etc.
    if (isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $title = trim((string)$entry->title);
            $guid = trim((string)$entry->id);

            // Ignore bridge error entries
            if (stripos($title, 'Bridge returned error') !== false || stripos($title, 'Bridge error') !== false) {
                continue;
            }

            $link = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $l) {
                    $attrs = $l->attributes();
                    if ((string)($attrs['rel'] ?? '') === 'alternate' || !empty($attrs['href'])) {
                        $link = (string)$attrs['href'];
                        if ((string)($attrs['rel'] ?? '') === 'alternate') {
                            break;
                        }
                    }
                }
                if ($link === '' && isset($entry->link['href'])) {
                    $link = (string)$entry->link['href'];
                }
            }
            if ($link === '') {
                $link = (string)$entry->link ?: $guid;
            }
            if ($guid === '') {
                $guid = $link ?: md5($title);
            }

            $pubDateStr = trim((string)($entry->published ?? $entry->updated ?? ''));
            $pubTs = $pubDateStr ? strtotime($pubDateStr) : time();
            $publishedAt = $pubTs ? date('Y-m-d H:i:s', $pubTs) : date('Y-m-d H:i:s');

            $snippet = '';
            if (isset($entry->summary)) {
                $snippet = strip_tags((string)$entry->summary);
            } elseif (isset($entry->content)) {
                $snippet = strip_tags((string)$entry->content);
            } else {
                $media = $entry->children('http://search.yahoo.com/mrss/');
                if (isset($media->group->description)) {
                    $snippet = strip_tags((string)$media->group->description);
                }
            }
            if (mb_strlen($snippet) > 280) {
                $snippet = mb_substr($snippet, 0, 277) . '...';
            }

            if ($link !== '') {
                $items[] = [
                    'guid' => mb_substr($guid, 0, 255),
                    'url' => mb_substr($link, 0, 1000),
                    'title' => mb_substr($title ?: 'New Post', 0, 500),
                    'snippet' => $snippet,
                    'published_at' => $publishedAt,
                ];
            }
        }
    }

    return $items;
}

/**
 * Fetch feeds for all eligible social links (or a specific brand/social link),
 * save new posts with social_link_id, and update latest_post_url.
 */
function fetchBrandPosts(PDO $pdo, ?int $brandId = null, bool $force = false, ?int $socialLinkId = null): array
{
    $sql = "SELECT s.id AS social_link_id, s.brand_id, s.platform, s.url AS profile_url, s.rss_feed_url, s.last_feed_check_at,
                   b.name AS brand_name, b.latest_post_url AS brand_latest_post_url
            FROM social_links s
            INNER JOIN brands b ON b.id = s.brand_id AND b.status = 1
            WHERE s.status = 1 AND s.rss_feed_url IS NOT NULL AND TRIM(s.rss_feed_url) != ''";
    $params = [];
    if ($brandId !== null && $brandId > 0) {
        $sql .= " AND s.brand_id = :brand_id";
        $params['brand_id'] = $brandId;
    }
    if ($socialLinkId !== null && $socialLinkId > 0) {
        $sql .= " AND s.id = :social_link_id";
        $params['social_link_id'] = $socialLinkId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $feeds = $stmt->fetchAll();

    $results = [
        'checked_feeds' => 0,
        'new_posts' => 0,
        'feeds_updated' => [],
        'errors' => [],
    ];

    if (!$feeds) {
        return $results;
    }

    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO brand_posts (brand_id, social_link_id, post_url, post_guid, title, content_snippet, published_at, is_notified, is_engaged)
        VALUES (:brand_id, :social_link_id, :post_url, :post_guid, :title, :snippet, :published_at, :is_notified, 0)
    ");

    $updateSocialStmt = $pdo->prepare("
        UPDATE social_links SET last_feed_check_at = NOW(), last_feed_status = :status WHERE id = :id
    ");

    $updateBrandStmt = $pdo->prepare("
        UPDATE brands SET last_feed_check_at = NOW(), latest_post_url = :latest_url WHERE id = :id
    ");

    foreach ($feeds as $feed) {
        $results['checked_feeds']++;
        $feedUrl = trim($feed['rss_feed_url']);
        $xml = fetchFeedXml($feedUrl);

        if (!$xml) {
            $updateSocialStmt->execute(['id' => $feed['social_link_id'], 'status' => 'error']);
            $results['errors'][] = "Failed to fetch feed for {$feed['brand_name']} - {$feed['platform']} ({$feedUrl})";
            continue;
        }

        $items = parseFeedItems($xml);
        if (empty($items)) {
            $isError = (stripos($xml, 'Bridge-Error') !== false || stripos($xml, 'Exception') !== false || stripos($xml, '500 Internal') !== false);
            $updateSocialStmt->execute(['id' => $feed['social_link_id'], 'status' => $isError ? 'error' : 'ok']);
            continue;
        }

        // Sort items by published_at DESC so the 1st item ($items[0]) is the newest/latest post
        usort($items, fn($a, $b) => strcmp($b['published_at'], $a['published_at']));

        $newForThisFeed = 0;
        $isFirstCheck = empty($feed['last_feed_check_at']);
        $latestItem = $items[0] ?? null;
        $latestUrl = $latestItem['url'] ?? ($feed['brand_latest_post_url'] ?? '');

        foreach ($items as $idx => $item) {
            // CRITICAL: Only the single latest post ($idx === 0) can ever trigger a notification ($notifiedVal = 0),
            // and only if this is NOT the very first scan of the feed.
            // All other older/historical items in the feed ($idx > 0) are ALWAYS marked as already notified ($notifiedVal = 1).
            $notifiedVal = ($isFirstCheck || $idx > 0) ? 1 : 0;

            $insertStmt->execute([
                'brand_id' => $feed['brand_id'],
                'social_link_id' => $feed['social_link_id'],
                'post_url' => $item['url'],
                'post_guid' => $item['guid'],
                'title' => $item['title'],
                'snippet' => $item['snippet'],
                'published_at' => $item['published_at'],
                'is_notified' => $notifiedVal,
            ]);

            if ($insertStmt->rowCount() > 0) {
                $newForThisFeed++;
                if ($notifiedVal === 0) {
                    $results['new_posts']++;
                }
            }
        }

        $updateSocialStmt->execute(['id' => $feed['social_link_id'], 'status' => 'ok']);
        if (!empty($latestUrl)) {
            $updateBrandStmt->execute([
                'latest_url' => $latestUrl,
                'id' => $feed['brand_id'],
            ]);
        }

        if ($newForThisFeed > 0) {
            $results['feeds_updated'][] = [
                'brand_id' => $feed['brand_id'],
                'brand_name' => $feed['brand_name'],
                'platform' => $feed['platform'],
                'new_posts' => $newForThisFeed,
                'latest_url' => $latestUrl,
            ];
        }
    }

    return $results;
}

// Handle direct web request or CLI invocation
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $pdo = db();
    $brandId = isset($_REQUEST['brand_id']) ? (int)$_REQUEST['brand_id'] : null;
    $force = !empty($_REQUEST['force']);

    $res = fetchBrandPosts($pdo, $brandId, $force);

    if (php_sapi_name() === 'cli') {
        echo "=== Social Links Feed Checker ===" . PHP_EOL;
        echo "Feeds checked: {$res['checked_feeds']}" . PHP_EOL;
        echo "New posts found: {$res['new_posts']}" . PHP_EOL;
        if (!empty($res['feeds_updated'])) {
            foreach ($res['feeds_updated'] as $b) {
                echo " - {$b['brand_name']} ({$b['platform']}): {$b['new_posts']} new post(s)" . PHP_EOL;
            }
        }
        if (!empty($res['errors'])) {
            echo "Errors:" . PHP_EOL;
            foreach ($res['errors'] as $err) {
                echo " ! {$err}" . PHP_EOL;
            }
        }
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'result' => $res]);
    }
    exit;
}
