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
$categoryIds = $pdo->query('SELECT name, id FROM vehicle_categories')->fetchAll(PDO::FETCH_KEY_PAIR);
$check = $pdo->prepare('SELECT id FROM vehicles WHERE brand = ? AND model = ? LIMIT 1');
$update = $pdo->prepare(
    'UPDATE vehicles SET category_id = ?, transmission = ?, seats = ?, daily_rate = ?, status = ?, image = ? WHERE id = ?'
);
$insert = $pdo->prepare(
    'INSERT INTO vehicles (category_id, brand, model, transmission, seats, daily_rate, plate_number, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

function fetch_json(string $query): ?array {
    $apiKey = trim((string)(getenv('PEXELS_API_KEY') ?: ''));
    if ($apiKey === '') {
        return null;
    }
    $url = 'https://api.pexels.com/v1/search?query=' . rawurlencode($query) . '&per_page=15&orientation=landscape';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nAuthorization: {$apiKey}\r\nUser-Agent: VELO-VRMS/1.0\r\n",
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $payload = json_decode($response, true);
    return is_array($payload) ? $payload : null;
}

function find_vehicle_image(string $brand, string $model): ?string {
    $payload = fetch_json($brand . ' ' . $model . ' car');
    $image = $payload['photos'][0]['src']['large2x'] ?? $payload['photos'][0]['src']['large'] ?? null;
    if ($image) {
        return $image;
    }
    // Demo fallback keeps the local presentation usable without an API key.
    return getenv('PEXELS_DEMO_IMAGE') ?: 'https://images.pexels.com/photos/170811/pexels-photo-170811.jpeg?auto=compress&cs=tinysrgb&w=1200';
}

foreach ($makeModels as [$make, $model, $category, $seats, $rate]) {
    if (!isset($categoryIds[$category])) {
        fwrite(STDERR, "Unknown vehicle category {$category}; skipping {$make} {$model}.\n");
        $skipped++;
        continue;
    }
    $check->execute([$make, $model]);
    $existing = $check->fetch();
    $image = find_vehicle_image($make, $model);
    if ($image === null) {
        $skipped++;
        fwrite(STDERR, "No Pexels image found for {$make} {$model}; using the demo fallback image.\n");
        if ($existing) {
            $update->execute([$categoryIds[$category], 'Automatic', $seats, $rate, 'operational', $existing['image'], $existing['id']]);
            $updated++;
            continue;
        }
        $image = 'vehicle-placeholder.svg';
    }
    if ($existing) {
        $update->execute([$categoryIds[$category], 'Automatic', $seats, $rate, 'operational', $image, $existing['id']]);
        $updated++;
        continue;
    }
    $insert->execute([$categoryIds[$category], $make, $model, 'Automatic', $seats, $rate, 'VELO-' . strtoupper(bin2hex(random_bytes(3))), $image]);
    $inserted++;
}

if (PHP_SAPI === 'cli') {
    echo "Inserted: {$inserted}; updated: {$updated}; images missing: {$skipped}\n";
    exit;
}
header('Location: /admin/admin-vehicles.php?seeded=' . $inserted . '&updated=' . $updated);
exit;
