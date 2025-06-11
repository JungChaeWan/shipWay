<?php
// ship_position.php
// Fetch wind and current direction data for Jeju sea and compute contact side of a boat.

$lat = 33.4996; // Jeju latitude
$lon = 126.5312; // Jeju longitude

function callApi(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $result = curl_exec($ch);
    curl_close($ch);
    if ($result === false) {
        return null;
    }
    return json_decode($result, true);
}

function mapAngleToArea(float $angle): string {
    $areas = [
        '선수',       // 0 deg
        '우측 선수',  // 45 deg
        '우현',       // 90 deg
        '우측 선미', // 135 deg
        '선미',       // 180 deg
        '좌측 선미', // 225 deg
        '좌현',       // 270 deg
        '좌측 선수'   // 315 deg
    ];
    $index = (int)round($angle / 45) % 8;
    return $areas[$index];
}

// Fetch hourly wind direction
$windUrl = sprintf(
    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=winddirection_10m&timezone=Asia%%2FSeoul',
    $lat,
    $lon
);
$windData = callApi($windUrl);

// Fetch hourly current direction using KHOA fcTidalCurrent API
$obsCode = '08JJ13'; // forecast station near Jeju
$serviceKey = 'lGFE/I4jx/FoaJr1C7S6kg==';
$date = date('Ymd');
$currentUrl = sprintf(
    'http://www.khoa.go.kr/api/oceangrid/fcTidalCurrent/search.do?ServiceKey=%s&ObsCode=%s&Date=%s&ResultType=json',
    $serviceKey,
    $obsCode,
    $date
);
$currentData = callApi($currentUrl);

if (!$windData || !$currentData) {
    echo 'API 데이터를 가져오지 못했습니다.';
    exit;
}

$windHours = $windData['hourly']['time'];
$windDir = $windData['hourly']['winddirection_10m'];
$currentList = [];
if (isset($currentData['result']['data'])) {
    $currentList = $currentData['result']['data'];
} elseif (isset($currentData['data'])) {
    $currentList = $currentData['data'];
} elseif (isset($currentData['list'])) {
    $currentList = $currentData['list'];
}

$rows = [];
foreach ($windHours as $idx => $time) {
    if (!isset($windDir[$idx]) || !isset($currentList[$idx])) {
        continue;
    }
    $wind = $windDir[$idx];
    $bowDir = fmod($wind + 180, 360);
    $currEntry = $currentList[$idx];
    $currentDir = $currEntry['current_direction'] ?? $currEntry['direction'] ?? null;
    if ($currentDir === null) {
        continue;
    }
    $inflow = fmod($currentDir + 180, 360);
    $angle = fmod($inflow - $bowDir + 360, 360);
    $area = mapAngleToArea($angle);
    $rows[] = [
        'time' => $time,
        'wind' => $wind,
        'current' => $currentDir,
        'area' => $area
    ];
}

?><!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>선박 위치 안내</title>
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 8px; }
    </style>
</head>
<body>
<h1>제주도 인근 해역 선박 접촉 면</h1>
<table>
    <thead>
    <tr><th>시간</th><th>풍향 (deg)</th><th>조류 방향 (deg)</th><th>접촉 면</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['time']) ?></td>
            <td style="text-align:right;"><?= htmlspecialchars($row['wind']) ?></td>
            <td style="text-align:right;"><?= htmlspecialchars($row['current']) ?></td>
            <td><?= htmlspecialchars($row['area']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
