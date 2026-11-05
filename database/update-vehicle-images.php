<?php
/**
 * Updates vehicle images in VRMS with real car photographs from public automotive APIs.
 * Saves local copies to public/assets/vehicles/ and updates the database records.
 */
require_once __DIR__ . '/../config.php';

$outputDir = __DIR__ . '/../public/assets/vehicles';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Map each vehicle ID to its specific Wikipedia article title / slug
$vehicleMap = [
    1 => ['slug' => 'toyota-vios', 'wiki' => 'Toyota_Vios'],
    2 => ['slug' => 'honda-city', 'wiki' => 'Honda_City'],
    3 => ['slug' => 'mitsubishi-montero-sport', 'wiki' => 'Mitsubishi_Pajero_Sport'],
    4 => ['slug' => 'toyota-fortuner', 'wiki' => 'Toyota_Fortuner'],
    5 => ['slug' => 'toyota-innova', 'wiki' => 'Toyota_Innova'],
    6 => ['slug' => 'toyota-rav4', 'wiki' => 'Toyota_RAV4'],
    7 => ['slug' => 'toyota-camry', 'wiki' => 'Toyota_Camry'],
    8 => ['slug' => 'honda-civic', 'wiki' => 'Honda_Civic'],
    9 => ['slug' => 'honda-br-v', 'wiki' => 'Honda_BR-V'],
    10 => ['slug' => 'honda-cr-v', 'wiki' => 'Honda_CR-V'],
    11 => ['slug' => 'honda-accord', 'wiki' => 'Honda_Accord'],
    12 => ['slug' => 'mitsubishi-xpander', 'wiki' => 'Mitsubishi_Xpander'],
    13 => ['slug' => 'mitsubishi-mirage', 'wiki' => 'Mitsubishi_Mirage'],
    14 => ['slug' => 'nissan-almera', 'wiki' => 'Nissan_Almera'],
    15 => ['slug' => 'nissan-terra', 'wiki' => 'Nissan_Terra'],
    16 => ['slug' => 'nissan-navara', 'wiki' => 'Nissan_Navara'],
    17 => ['slug' => 'ford-everest', 'wiki' => 'Ford_Everest'],
    18 => ['slug' => 'ford-ranger', 'wiki' => 'Ford_Ranger'],
    19 => ['slug' => 'mazda-cx-5', 'wiki' => 'Mazda_CX-5'],
    20 => ['slug' => 'hyundai-tucson', 'wiki' => 'Hyundai_Tucson'],
];

$pdo = db();
$stmt = $pdo->prepare('UPDATE vehicles SET image = ? WHERE id = ?');

echo "Fetching and updating real vehicle images...\n";

foreach ($vehicleMap as $id => $info) {
    $slug = $info['slug'];
    $wiki = $info['wiki'];
    $localFile = "{$outputDir}/{$slug}.jpg";
    $webPath = "/assets/vehicles/{$slug}.jpg";

    $apiUrl = "https://en.wikipedia.org/w/api.php?action=query&titles=" . urlencode($wiki) . "&prop=pageimages&format=json&pithumbsize=900";
    
    // Fetch image metadata from Wikipedia API
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "VRMS-Fleet/1.0 (fleet@vrms.local)");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $thumbUrl = null;
    if ($response) {
        $json = json_decode($response, true);
        $pages = $json['query']['pages'] ?? [];
        $page = reset($pages);
        $thumbUrl = $page['thumbnail']['source'] ?? null;
    }

    if ($thumbUrl) {
        // Download image binary
        $imgCh = curl_init($thumbUrl);
        curl_setopt($imgCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($imgCh, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36");
        curl_setopt($imgCh, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($imgCh, CURLOPT_TIMEOUT, 15);
        $imgData = curl_exec($imgCh);
        $httpCode = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
        curl_close($imgCh);

        if ($httpCode === 200 && strlen($imgData) > 5000) {
            file_put_contents($localFile, $imgData);
            $stmt->execute([$webPath, $id]);
            echo " [OK] Vehicle #{$id} ({$slug}): Saved local image ({$webPath})\n";
        } else {
            // If download failed, store high quality CDN URL directly
            $stmt->execute([$thumbUrl, $id]);
            echo " [CDN] Vehicle #{$id} ({$slug}): Set CDN image URL\n";
        }
    } else {
        echo " [WARN] Vehicle #{$id} ({$slug}): Could not find thumbnail\n";
    }

    usleep(500000); // 0.5s pause to respect API limits
}

echo "Completed updating vehicle images.\n";
