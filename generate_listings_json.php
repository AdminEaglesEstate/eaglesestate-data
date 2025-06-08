<?php
/**
 * CRON: eksportuje ogłoszenia z Asari do listings.json i wrzuca je na GitHuba
 */

date_default_timezone_set('Europe/Warsaw');

$siteAuth = 'TWOJ_SITEAUTH'; // ← podmień!
$cookie = 'TWOJ_COOKIE';     // ← podmień!

function logInfo($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
}

function postForm($url, $fields, $siteAuth, $cookie, $maxRetries = 5) {
    $retryDelay = 3;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: multipart/form-data",
                "SiteAuth: $siteAuth",
                "Cookie: $cookie",
            ],
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) return $response;
        logInfo("[$attempt] HTTP $httpCode — retry...");
        sleep($retryDelay);
        $retryDelay *= 2;
    }
    return null;
}

logInfo("Start skryptu");

$idResponse = postForm(
    'https://api.asari.pro/site/exportedListingIdList',
    ['closedDays' => '5', 'blockedDays' => '10'],
    $siteAuth,
    $cookie
);
if (!$idResponse) exit("Brak ID\n");
$idJson = json_decode($idResponse, true);
$idList = $idJson['data'] ?? [];
logInfo("Pobrano " . count($idList) . " ID");

$listings = [];
foreach ($idList as $i => $item) {
    $id = $item['id'] ?? null;
    if (!$id) continue;

    logInfo("[$i] ID: $id");
    $resp = postForm('https://api.asari.pro/site/listing', ['id' => $id], $siteAuth, $cookie);
    $json = json_decode($resp, true);
    $l = $json['data'] ?? null;
    if (!$l) continue;

    $listings[] = [
        'id' => $l['id'],
        'title' => $l['name'] ?? '',
        'price' => $l['price']['amount'] ?? 0,
        'location_name' => $l['location']['name'] ?? '',
        'images' => $l['images'] ?? [],
        'total_area' => $l['totalArea'] ?? null,
        'no_of_rooms' => $l['noOfRooms'] ?? null,
        'short_description' => $l['headerAdvertisement'] ?? null,
    ];

    sleep(1);
}

$target = __DIR__ . '/eaglesestate-data/listings.json';
file_put_contents($target, json_encode($listings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
logInfo("Zapisano listings.json");

exec("cd " . escapeshellarg(__DIR__ . '/eaglesestate-data') .
    " && git add listings.json" .
    " && git commit -m 'Aktualizacja listings.json z CRON' || true" .
    " && git push origin main", $output, $exitCode);

logInfo("Push zakończony (exit=$exitCode)");
foreach ($output as $line) logInfo($line);

logInfo("Skrypt zakonczony");