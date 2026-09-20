<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fetch_posts.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$feedUrl = trim($_REQUEST['url'] ?? '');

if ($feedUrl === '') {
    echo json_encode([
        'ok' => false,
        'status' => 'empty_url',
        'message' => 'অনুগ্রহ করে একটি ফিড URL দিন।'
    ]);
    exit;
}

if (!filter_var($feedUrl, FILTER_VALIDATE_URL)) {
    echo json_encode([
        'ok' => false,
        'status' => 'invalid_url',
        'message' => 'অবৈধ URL ফরম্যাট।'
    ]);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $feedUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 4,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.8',
        'Cache-Control: no-cache',
    ],
]);

$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode([
        'ok' => false,
        'status' => 'curl_error',
        'message' => 'কানেকশন এরর: ' . $curlErr
    ]);
    exit;
}

if ($httpCode >= 400) {
    echo json_encode([
        'ok' => false,
        'status' => 'http_error',
        'http_code' => $httpCode,
        'message' => "সার্ভার এরর (HTTP {$httpCode})"
    ]);
    exit;
}

if (!is_string($content) || trim($content) === '') {
    echo json_encode([
        'ok' => false,
        'status' => 'empty_response',
        'message' => 'সার্ভার থেকে কোনো কনটেন্ট পাওয়া যায়নি।'
    ]);
    exit;
}

// Check for explicit RSS-Bridge error embedded in XML or HTML
if (stripos($content, 'Bridge returned error') !== false) {
    preg_match('/Bridge returned error\s*([0-9!]+(?:\s*\([0-9]+\))?)/i', $content, $m);
    $detail = $m[0] ?? 'Bridge Error';
    echo json_encode([
        'ok' => false,
        'status' => 'bridge_error',
        'raw_error' => $detail,
        'message' => "❌ ব্রিজ এরর: {$detail} (এই পেজটি RSS-Bridge রিড করতে পারছে না, সম্ভবত লগইন প্রয়োজন বা পেজটি রেস্ট্রিক্টেড)"
    ]);
    exit;
}

if (stripos($content, 'Bridge-Error') !== false || stripos($content, 'class="error"') !== false) {
    echo json_encode([
        'ok' => false,
        'status' => 'bridge_error',
        'message' => '❌ ব্রিজ এরর: পেজটি স্ক্র্যাপ করতে ব্যর্থ হয়েছে।'
    ]);
    exit;
}

$items = parseFeedItems($content);

if (empty($items)) {
    echo json_encode([
        'ok' => false,
        'status' => 'no_posts',
        'message' => '⚠️ ফিড রেসপন্স করেছে কিন্তু কোনো পোস্ট পাওয়া যায়নি।'
    ]);
    exit;
}

$latestItem = end($items);

echo json_encode([
    'ok' => true,
    'status' => 'working',
    'message' => "✅ ফিড সচল! (" . count($items) . " টি পোস্ট পাওয়া গেছে)",
    'post_count' => count($items),
    'latest_title' => $latestItem['title'] ?? 'New Post',
    'latest_url' => $latestItem['url'] ?? '',
    'published_at' => $latestItem['published_at'] ?? '',
]);

