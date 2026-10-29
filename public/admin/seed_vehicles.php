<?php
require_once __DIR__ . '/../../config.php';
if (PHP_SAPI !== 'cli') {
    require_role('admin');
}

$makeModels = [
    ['Toyota', 'Vios', 'Sedan', 5, 1800],
    ['Toyota', 'Fortuner', 'SUV', 7, 3500],
    ['Toyota', 'Innova', 'SUV', 7, 2800],
    ['Toyota', 'RAV4', 'SUV', 5, 3200],
    ['Toyota', 'Camry', 'Premium', 5, 4200],
    ['Honda', 'City', 'Sedan', 5, 2000],
    ['Honda', 'Civic', 'Sedan', 5, 2500],
    ['Honda', 'BR-V', 'SUV', 7, 2800],
    ['Honda', 'CR-V', 'SUV', 5, 3500],
    ['Honda', 'Accord', 'Premium', 5, 4500],
    ['Mitsubishi', 'Montero Sport', 'SUV', 7, 3500],
    ['Mitsubishi', 'Xpander', 'SUV', 7, 2600],
    ['Mitsubishi', 'Mirage', 'Sedan', 5, 1700],
    ['Nissan', 'Almera', 'Sedan', 5, 1900],
    ['Nissan', 'Terra', 'SUV', 7, 3600],
    ['Nissan', 'Navara', 'SUV', 5, 3300],
    ['Ford', 'Everest', 'SUV', 7, 4000],
    ['Ford', 'Ranger', 'SUV', 5, 3500],
    ['Mazda', 'CX-5', 'Premium', 5, 3800],
    ['Hyundai', 'Tucson', 'SUV', 5, 3200],
];
$inserted = 0;
$updated = 0;
$skipped = 0;
$pdo = db();
$pdo->exec('ALTER TABLE vehicles MODIFY image TEXT NULL');
$check = $pdo->prepare('SELECT id FROM vehicles WHERE brand = ? AND model = ? LIMIT 1');
$update = $pdo->prepare(
    'UPDATE vehicles SET category = ?, transmission = ?, seats = ?, daily_rate = ?, status = ?, image = ? WHERE id = ?'
);
$insert = $pdo->prepare(
    'INSERT INTO vehicles (brand, model, category, transmission, seats, daily_rate, image) VALUES (?, ?, ?, ?, ?, ?, ?)'
);

function find_vehicle_image(string $brand, string $model): ?string {
    $query = rawurlencode($brand . ' ' . $model . ' car');
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrsearch='
        . $query . '&gsrnamespace=6&gsrlimit=10&prop=imageinfo&iiprop=url&iiurlwidth=900&format=json';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: VELO-VRMS/1.0\r\n",
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $payload = $response === false ? null : json_decode($response, true);
    $excludedTerms = ['interior', 'dashboard', 'police', 'patrol', 'concept', 'render', 'toy'];
    foreach (($payload['query']['pages'] ?? []) as $page) {
        $title = strtolower($page['title'] ?? '');
        $isExcluded = false;
        foreach ($excludedTerms as $term) {
            if (str_contains($title, $term)) {
                $isExcluded = true;
                break;
            }
        }
        if (!$isExcluded && str_contains($title, strtolower($brand)) && str_contains($title, strtolower($model))) {
            return $page['imageinfo'][0]['thumburl'] ?? $page['imageinfo'][0]['url'] ?? null;
        }
    }
    return null;
}

foreach ($makeModels as [$make, $model, $category, $seats, $rate]) {
    $api = 'https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMake/' . rawurlencode($make) . '?format=json';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: VELO-VRMS/1.0\r\n",
        ],
    ]);
    $response = @file_get_contents($api, false, $context);
    $check->execute([$make, $model]);
    $existing = $check->fetch();
    $image = find_vehicle_image($make, $model) ?? 'vehicle-placeholder.svg';
    if ($existing) {
        $update->execute([$category, 'Automatic', $seats, $rate, 'operational', $image, $existing['id']]);
        $updated++;
        continue;
    }
    $insert->execute([$make, $model, $category, 'Automatic', $seats, $rate, $image]);
    $inserted++;
}

if (PHP_SAPI === 'cli') {
    echo "Inserted: {$inserted}; updated: {$updated}; skipped: {$skipped}\n";
    exit;
}
header('Location: /admin/admin-vehicles.php?seeded=' . $inserted . '&updated=' . $updated);
exit;
