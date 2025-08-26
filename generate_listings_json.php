<?php
/**
 * CRON: eksportuje wszystkie ogłoszenia z Asari do listings.json i wrzuca je automatycznie na GitHuba
 */


if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env');
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) continue;
        putenv($trimmed);
    }
}
$token = getenv('GITHUB_TOKEN');


date_default_timezone_set('Europe/Warsaw');

$siteAuth = '72663:b4l86pBydW4yn53tgl1Hk03csh1YCN113Yv2mZb7';
$cookie = 'JSESSIONID=0214D1861713C577FCB924260AF728CB';

function logInfo($msg)
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
}

function mapToBuildingType($section)
{
    if (!$section) return null;
    
    if (strpos($section, 'House') === 0) {
        return 'Dom';
    } elseif (strpos($section, 'Apartment') === 0) {
        return 'Mieszkanie';
    } elseif (strpos($section, 'CommercialSpace') === 0) {
        return 'Lokal';
    } elseif (strpos($section, 'Lot') === 0) {
        return 'Dzialka';
    } elseif (strpos($section, 'CommercialObject') === 0) {
        return 'Obiekt';
    }
    
    return null;
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
    $listingId = isset($item['id']) ? $item['id'] : null;
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
    $l = isset($json['data']) ? $json['data'] : null;
    if (!$l || !isset($l['id'])) continue;

    if (isset($l['section']) && $l['section'] === 'Investment') {
        logInfo("Pomijam listing $listingId - sekcja Investment");
        continue;
    }

    if (isset($l['nestedListings']) && is_array($l['nestedListings']) && count($l['nestedListings']) > 0) {
        logInfo("Pomijam listing $listingId - posiada nestedListings");
        continue;
    }

    $plotAreaHa = null;
    if (isset($l['lotArea']) && $l['lotArea'] !== null && $l['lotArea'] !== '') {
        $area = (float)$l['lotArea'];
        $unit = isset($l['lotAreaUnit']) ? $l['lotAreaUnit'] : null;
        if ($area > 0) {
            switch ($unit) {
                case null:
                case 'Sqm':
                    $plotAreaHa = $area / 10000.0;
                    break;
                case 'Ha':
                    $plotAreaHa = $area;
                    break;
                case 'A':
                    $plotAreaHa = $area / 100.0;
                    break;
                default:
                    $plotAreaHa = null;
            }
        }
    }

    $listings[] = [
        'id' => $l['id'],
        'title' => isset($l['name']) ? $l['name'] : '',
        'price' => isset($l['price']['amount']) ? $l['price']['amount'] : 0,
        'is_special' => isset($l['isSpecial']) ? (int)(bool)$l['isSpecial'] : 0,
        'building_type' => mapToBuildingType(isset($l['section']) ? $l['section'] : null),
        'isForSaleOrRental' => isset($l['section']) ? (strpos($l['section'], 'Sale') !== false ? true : (strpos($l['section'], 'Rental') !== false ? false : null)) : null,
        'country_name' => isset($l['country']['name']) ? $l['country']['name'] : null,
        'description' => isset($l['description']) ? $l['description'] : null,
        'elevator' => isset($l['elevator']) ? (int)(bool)$l['elevator'] : null,
        'floor_no' => isset($l['floorNo']) ? $l['floorNo'] : null,
        'garage' => isset($l['garage']) ? (int)(bool)$l['garage'] : null,
        'garage_parking_price' => isset($l['garageParkingPrice']['amount']) ? $l['garageParkingPrice']['amount'] : null,
        'short_description' => isset($l['headerAdvertisement']) ? $l['headerAdvertisement'] : null,
        'headerAdvertisement' => isset($l['headerAdvertisement']) ? $l['headerAdvertisement'] : null,
        'kitchen_type' => isset($l['kitchenType']) ? $l['kitchenType'] : null,
        'location_name' => isset($l['location']['name']) ? $l['location']['name'] : null,
        'location_province' => isset($l['location']['province']) ? $l['location']['province'] : null,
        'location_locality' => isset($l['location']['locality']) ? $l['location']['locality'] : null,
        'location_quarter' => isset($l['location']['quarter']) ? $l['location']['quarter'] : null,
        'material' => isset($l['material']) ? $l['material'] : null,
        'mortgage_market' => isset($l['mortgageMarket']) ? $l['mortgageMarket'] : null,
        'no_of_rooms' => isset($l['noOfRooms']) ? $l['noOfRooms'] : null,
        'ownership_type' => isset($l['ownershipType']) ? $l['ownershipType'] : null,
        'parking_spaces_no' => isset($l['parkingSpacesNo']) ? $l['parkingSpacesNo'] : null,
        'price_m2' => isset($l['priceM2']['amount']) ? $l['priceM2']['amount'] : null,
        'provision_amount' => isset($l['provisionAmount']) ? $l['provisionAmount'] : null,
        'section' => isset($l['section']) ? $l['section'] : null,
        'status' => isset($l['status']) ? $l['status'] : null,
        'total_area' => isset($l['totalArea']) ? $l['totalArea'] : null,
        'plot_area_ha' => $plotAreaHa,
        'year_built' => isset($l['yearBuilt']) ? $l['yearBuilt'] : null,
        'street_name' => isset($l['street']['name']) ? $l['street']['name'] : null,
        'street_full_name' => isset($l['street']['fullName']) ? $l['street']['fullName'] : null,
        'images' => isset($l['images']) ? $l['images'] : [],
        'listing_offer_id' => isset($l['listingId']) ? $l['listingId'] : null,
        'no_of_floors' => isset($l['noOfFloors']) ? $l['noOfFloors'] : null,
        'actualisation_date' => isset($l['actualisationDate']) ? $l['actualisationDate'] : null,
        'agentId' => isset($l['agent']) && isset($l['agent']['id']) ? $l['agent']['id'] : null,
        'last_updated' => date('Y-m-d H:i:s'),
    ];

    sleep(1);
}

// Sortuj po actualisation_date (najnowsze pierwsze)
usort($listings, function($a, $b) {
    $dateA = $a['actualisation_date'] ?? '';
    $dateB = $b['actualisation_date'] ?? '';
    return strcmp($dateB, $dateA); // sortowanie malejąco (najnowsze pierwsze)
});

$target = __DIR__ . '/listings.json';
file_put_contents($target, json_encode($listings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
logInfo("Zapisano " . count($listings) . " ogłoszeń do $target");

function githubPush($token, $repo, $path, $content, $message)
{
    $url = "https://api.github.com/repos/$repo/contents/$path";

    $headers = [
        "Authorization: token $token",
        "User-Agent: AdminEaglesEstate",
        "Accept: application/vnd.github.v3+json"
    ];

    // Pobierz sha istniejącego pliku
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $existing = json_decode($response, true);
    $sha = isset($existing['sha']) ? $existing['sha'] : null;

    $data = json_encode([
        "message" => $message,
        "content" => base64_encode($content),
        "branch" => "main",
        "sha" => $sha
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => array_merge($headers, [
            "Content-Type: application/json"
        ])
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logInfo("GitHub API response code: $httpCode");
    if ($httpCode !== 200 && $httpCode !== 201) {
        logInfo("GitHub API error: $response");
    } else {
        logInfo("Wysłano listings.json do GitHuba przez API");
    }
}

// Wywołanie
$token = getenv('GITHUB_TOKEN');
$repo = 'AdminEaglesEstate/eaglesestate-data';
$path = 'listings.json';
$content = file_get_contents($target);
$message = "Auto update " . date('Y-m-d H:i:s');
githubPush($token, $repo, $path, $content, $message);