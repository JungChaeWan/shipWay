<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'log.txt');

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// KHOA API for tidal current
$API_KEY = "lGFE/I4jx/FoaJr1C7S6kg==";
$BASE_URL_CURRENT = "http://www.khoa.go.kr/api/oceangrid/fcTidalCurrent/search.do";

// tidal current station code
$currentObsCode = $_GET['currentObsCode'] ?? "08JJ13";

// map station to approximate wind coordinates
$windCoordMap = [
    "08JJ13" => ['lat' => 33.4996, 'lon' => 126.5312],
    "23LTC02" => ['lat' => 33.216, 'lon' => 126.252],
    "02JJ-1"  => ['lat' => 33.520, 'lon' => 126.510],
    "08JJ07"  => ['lat' => 33.389, 'lon' => 126.870],
];
$coords = $windCoordMap[$currentObsCode] ?? ['lat' => 33.4996, 'lon' => 126.5312];

$date = date("Ymd");

$wind_data = fetchWindData($coords['lat'], $coords['lon'], $date);
$current_data = fetchCurrentData($currentObsCode, $date);

file_put_contents("log.txt", "✅ 풍향 데이터: " . print_r($wind_data, true) . "\n", FILE_APPEND);
file_put_contents("log.txt", "✅ 조류 데이터: " . print_r($current_data, true) . "\n", FILE_APPEND);

if (empty($wind_data)) {
    echo json_encode(["status" => "error", "message" => "풍향 데이터 없음"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($current_data)) {
    echo json_encode(["status" => "error", "message" => "조류 데이터 없음"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$forecast_data = [];
$selected_hours = range(7, 17);

foreach ($selected_hours as $hour) {
    $time = str_pad($hour, 2, "0", STR_PAD_LEFT) . ":00";

    $wind_entry = getClosestData($wind_data, $hour, "record_time");
    $current_entry = getClosestData($current_data, $hour, "pred_time");

    $windSpeed = "데이터 없음";
    $windDir = "❓";
    $currentDir = "❓";
    $shipEntryDir = "❓";
    $direction = "데이터 없음";

    if (!empty($wind_entry)) {
        $windSpeed = isset($wind_entry["wind_speed"]) ? $wind_entry["wind_speed"] . " m/s" : "데이터 없음";
        $windDir = isset($wind_entry["wind_dir"]) ? convertDirectionToArrow($wind_entry["wind_dir"]) : "❓";
        $shipEntryDir = isset($wind_entry["wind_dir"]) ? convertDirectionToArrow(($wind_entry["wind_dir"] + 180) % 360) : "❓";
    }

    if (!empty($current_entry)) {
        $currentDir = isset($current_entry["current_dir"]) ? convertDirectionToArrow($current_entry["current_dir"]) : "❓";
        if (isset($wind_entry["wind_dir"], $current_entry["current_dir"])) {
            $direction = calculateShipDirection($wind_entry["wind_dir"], $current_entry["current_dir"]);
        }
    }

    $forecast_data[] = [
        "time" => substr($date, 6, 2) . "일 " . $time,
        "wind_speed" => $windSpeed,
        "wind_dir" => $windDir,
        "ship_entry_dir" => $shipEntryDir,
        "current_dir" => $currentDir,
        "direction" => $direction
    ];
}

echo json_encode(["status" => "success", "forecast" => $forecast_data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

function convertDirectionToDegree($dir) {
    $directions = [
        "북" => 0, "북북동" => 22.5, "북동" => 45, "동북동" => 67.5, "동" => 90,
        "동남동" => 112.5, "남동" => 135, "남남동" => 157.5, "남" => 180,
        "남남서" => 202.5, "남서" => 225, "서남서" => 247.5, "서" => 270,
        "서북서" => 292.5, "북서" => 315, "북북서" => 337.5
    ];
    return $directions[$dir] ?? "";
}

function convertDirectionToArrow($degree) {
    if (!is_numeric($degree)) {
        $degree = convertDirectionToDegree($degree);
        if ($degree === "") return "❓";
    }

    $degree = intval(round($degree));
    $arrows = [
        0 => "↑", 23 => "↗", 45 => "↗", 68 => "↗", 90 => "→",
        113 => "↘", 135 => "↘", 158 => "↘", 180 => "↓",
        203 => "↙", 225 => "↙", 248 => "↙", 270 => "←",
        293 => "↖", 315 => "↖", 338 => "↖"
    ];

    $closest = 0;
    foreach ($arrows as $key => $arrow) {
        if (abs($degree - $key) < abs($degree - $closest)) {
            $closest = $key;
        }
    }
    return $arrows[$closest];
}

function fetchWindData($lat, $lon, $date) {
    $day = DateTime::createFromFormat('Ymd', $date);
    if (!$day) {
        $day = new DateTime();
    }
    $start = $day->format('Y-m-d');

    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=wind_speed_10m,wind_direction_10m&timezone=Asia%%2FSeoul&start_date=%s&end_date=%s',
        $lat,
        $lon,
        $start,
        $start
    );

    $response = @file_get_contents($url);
    $json = json_decode($response, true);
    if (empty($json['hourly']['time'])) {
        return [];
    }
    $result = [];
    foreach ($json['hourly']['time'] as $idx => $t) {
        $result[] = [
            'record_time' => $t,
            'wind_speed' => $json['hourly']['wind_speed_10m'][$idx] ?? null,
            'wind_dir' => $json['hourly']['wind_direction_10m'][$idx] ?? null
        ];
    }
    return $result;
}

function fetchCurrentData($obsCode, $date) {
    global $API_KEY, $BASE_URL_CURRENT;
    $url = "$BASE_URL_CURRENT?ServiceKey=$API_KEY&ObsCode=$obsCode&Date=$date&ResultType=json";

    $response = @file_get_contents($url);
    return json_decode($response, true)["result"]["data"] ?? [];
}

function getClosestData($data, $targetHour, $timeKey) {
    $closestEntry = null;
    $minDiff = PHP_INT_MAX;
    $currentTime = date("H:i");

    foreach ($data as $entry) {
        if (!isset($entry[$timeKey])) continue;

        $entryTime = date("H:i", strtotime($entry[$timeKey]));
        $entryHour = intval(date("H", strtotime($entry[$timeKey])));
        $hourDiff = abs($entryHour - $targetHour);

        if ($entryTime < $currentTime) continue;

        if ($hourDiff < $minDiff) {
            $minDiff = $hourDiff;
            $closestEntry = $entry;
        }
    }

    file_put_contents("log.txt", "🔍 [$targetHour 시] 가장 가까운 데이터: " . print_r($closestEntry, true) . "\n", FILE_APPEND);

    return $closestEntry;
}

function calculateShipDirection($wind_dir, $current_dir) {
    $wind_dir = convertDirectionToDegree($wind_dir);
    $current_dir = convertDirectionToDegree($current_dir);

    file_put_contents("log.txt", "📌 풍향 각도: $wind_dir, 조류 각도: $current_dir\n", FILE_APPEND);

    if ($wind_dir === "" || $current_dir === "") {
        return "데이터 없음";
    }

    $ship_bow = ($wind_dir + 180) % 360;
    $angle_diff = abs($ship_bow - $current_dir);

    if ($angle_diff > 180) {
        $angle_diff = 360 - $angle_diff;
    }

    if ($angle_diff <= 20) return "선수";
    if ($angle_diff >= 160) return "선미";
    if ($angle_diff <= 30) return ($current_dir > $ship_bow) ? "선수(우현)" : "선수(좌현)";
    if ($angle_diff >= 150) return ($current_dir > $ship_bow) ? "선미(우현)" : "선미(좌현)";

    return ($current_dir > $ship_bow) ? "우현" : "좌현";
}
