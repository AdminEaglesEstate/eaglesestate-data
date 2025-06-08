<?php
/**
 * CRON: eksportuje wszystkie ogłoszenia z Asari do listings.json i wrzuca je automatycznie na GitHuba
 */

date_default_timezone_set('Europe/Warsaw');

$siteAuth = '72663:b4l86pBydW4yn53tgl1Hk03csh1YCN113Yv2mZb7';
$cookie = 'JSESSIONID=0214D1861713C577FCB924260AF728CB';

function logInfo($msg)
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
}

function postForm($url, $fields, $siteAuth, $cookie, $maxRetries = 7)
{
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
        if (curl_errno($ch)) {
            logInfo("[CURL błąd próba $attempt]: " . curl_error($ch));
            curl_close($ch);
            sleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        curl_close($ch);
        if ($httpCode === 429) {
            logInfo("[HTTP 429 próba $attempt]: za dużo zapytań, czekam {$retryDelay}s");
            sleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        if ($httpCode !== 200) {
            logInfo("[HTTP $httpCode] przy zapytaniu do $url (próba $attempt)");
            return null;
        }
        return $response;
    }
    logInfo("[Niepowodzenie] po $maxRetries próbach do $url");
    return null;
}

$idResponse = postForm(
    'https://api.asari.pro/site/exportedListingIdList',
    ['closedDays' => '5', 'blockedDays' => '10'],
    $siteAuth,
    $cookie
);
if (!$idResponse) exit("Brak odpowiedzi z exportedListingIdList\n");
$idJson = json_decode($idResponse, true);
if (!is_array($idJson) || !isset($idJson['data'])) exit("Błąd JSON\n");
$idList = $idJson['data'];

logInfo("Pobrano " . count($idList) . " ID ogłoszeń");

$listings = [];

foreach ($idList as $index => $item) {
    $listingId = $item['id'] ?? null;
    if (!$listingId) continue;

    logInfo("Przetwarzam $index / " . count($idList) . " (ID: $listingId)");

    $response = postForm(
        'https://api.asari.pro/site/listing',
        ['id' => $listingId],
        $siteAuth,
        $cookie
    );
    if (!$response) continue;
    $json = json_decode($response, true);
    $l = $json['data'] ?? null;
    if (!$l || !isset($l['id'])) continue;

    $listings[] = [
        'id' => $l['id'],
        'title' => $l['name'] ?? '',
        'price' => $l['price']['amount'] ?? 0,
        'is_special' => isset($l['isSpecial']) ? (int)(bool)$l['isSpecial'] : 0,
        'building_type' => $l['buildingType'] ?? null,
        'country_name' => $l['country']['name'] ?? null,
        'description' => $l['description'] ?? null,
        'elevator' => isset($l['elevator']) ? (int)(bool)$l['elevator'] : null,
        'floor_no' => $l['floorNo'] ?? null,
        'garage' => isset($l['garage']) ? (int)(bool)$l['garage'] : null,
        'garage_parking_price' => $l['garageParkingPrice']['amount'] ?? null,
        'short_description' => $l['headerAdvertisement'] ?? null,
        'kitchen_type' => $l['kitchenType'] ?? null,
        'location_name' => $l['location']['name'] ?? null,
        'location_province' => $l['location']['province'] ?? null,
        'location_locality' => $l['location']['locality'] ?? null,
        'location_quarter' => $l['location']['quarter'] ?? null,
        'material' => $l['material'] ?? null,
        'mortgage_market' => $l['mortgageMarket'] ?? null,
        'no_of_rooms' => $l['noOfRooms'] ?? null,
        'ownership_type' => $l['ownershipType'] ?? null,
        'parking_spaces_no' => $l['parkingSpacesNo'] ?? null,
        'price_m2' => $l['priceM2']['amount'] ?? null,
        'provision_amount' => $l['provisionAmount'] ?? null,
        'section' => $l['section'] ?? null,
        'status' => $l['status'] ?? null,
        'total_area' => $l['totalArea'] ?? null,
        'year_built' => $l['yearBuilt'] ?? null,
        'street_name' => $l['street']['name'] ?? null,
        'street_full_name' => $l['street']['fullName'] ?? null,
        'images' => $l['images'] ?? [],
        'listing_offer_id' => $l['listingId'] ?? null,
        'no_of_floors' => $l['noOfFloors'] ?? null,
        'last_updated' => date('Y-m-d H:i:s'),
    ];

    sleep(1);
}

$target = __DIR__ . '/listings.json';
file_put_contents($target, json_encode($listings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
logInfo("Zapisano " . count($listings) . " ogłoszeń do $target");

exec("git add listings.json && git commit -m 'Auto update '" . date('Y-m-d H:i:s') . " && git push origin main 2>&1", $output, $code);
logInfo("Git push zakończony kodem $code");
foreach ($output as $line) logInfo($line);
