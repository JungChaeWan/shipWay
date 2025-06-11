<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

//echo "=== API 처리 시작 ===\n";

// KHOA API for tidal current
$API_KEY = "lGFE/I4jx/FoaJr1C7S6kg==";
$BASE_URL_CURRENT = "http://www.khoa.go.kr/api/oceangrid/fcTidalCurrent/search.do";

$currentObsCode = $_GET['currentObsCode'] ?? "08JJ13";

$windCoordMap = [
    "08JJ13" => ['lat' => 33.4996, 'lon' => 126.5312],
    "23LTC02" => ['lat' => 33.216, 'lon' => 126.252],
    "02JJ-1"  => ['lat' => 33.520, 'lon' => 126.510],
    "08JJ07"  => ['lat' => 33.389, 'lon' => 126.870],
];
$coords = $windCoordMap[$currentObsCode] ?? ['lat' => 33.4996, 'lon' => 126.5312];

$date = $_GET['targetDate'] ?? date("Y-m-d");
$dateParam = str_replace('-', '', $date); // Ymd로 변환
//echo "[INFO] 사용 좌표: ";
//print_r($coords);

$dayType = $_GET['dayType'] ?? 'day';
$wind_data = fetchWindDataCurl($coords['lat'], $coords['lon'], $date, $dayType);
$current_data = fetchCurrentDataCurl($currentObsCode, $dateParam);

if (empty($wind_data)) {
    echo json_encode(["status" => "error", "message" => "풍향 데이터 없음"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($current_data)) {
    echo json_encode(["status" => "error", "message" => "조류 데이터 없음"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$forecast_data = [];

$dayType = $_GET['dayType'] ?? 'day';

if ($dayType === 'night') {
    // 오늘 18~23시, 내일 0~5시
    $timelist = [];
    foreach (range(18, 23) as $h) $timelist[] = sprintf("%s %02d:00", $date, $h);
    // 다음날 0~5시
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    foreach (range(0, 5) as $h) $timelist[] = sprintf("%s %02d:00", $nextDate, $h);
} else {
    // 오늘 07~17시
    $timelist = [];
    foreach (range(7, 17) as $h) $timelist[] = sprintf("%s %02d:00", $date, $h);
}

foreach ($timelist as $display_time) {
    $hour = intval(substr($display_time, 11, 2));
    $wind_entry = getEntryByTime($wind_data, $display_time, "record_time");
    $current_entry = getEntryByTime($current_data, $display_time, "pred_time");

    if (!empty($wind_entry) && isset($wind_entry['record_time'])) {
        // "2024-06-12T03:00" → "2024-06-12 03:00"
        $display_time = str_replace('T', ' ', substr($wind_entry['record_time'], 0, 16));
    } elseif (!empty($current_entry) && isset($current_entry['pred_time'])) {
        // "2024-06-12 03:00:00" → "2024-06-12 03:00"
        $display_time = substr($current_entry['pred_time'], 0, 16);
    } else {
        $display_time = "시간 데이터 없음";
    }

    $windSpeed = "데이터 없음";
    $windDir = "❓";
    $currentDir = "❓";
    $shipEntryDir = "❓";
    $direction = "데이터 없음";

    // 풍향(바람)
    if (!empty($wind_entry)) {
        $windSpeedVal = isset($wind_entry["wind_speed"]) ? floatval($wind_entry["wind_speed"]) : null;
        $windSpeed = ($windSpeedVal !== null) ? $windSpeedVal . " m/s" : "데이터 없음";
        $windArrow = isset($wind_entry["wind_dir"]) ? convertDirectionToArrow($wind_entry["wind_dir"]) : "❓";
        // 풍속 5 이상이면 화살표 두 개
        if ($windSpeedVal !== null) {
            if ($windSpeedVal >= 5) {
                $windDir = $windArrow . $windArrow . " (" . $windSpeedVal . "m/s)";
            } else {
                $windDir = $windArrow . " (" . $windSpeedVal . "m/s)";
            }
        } else {
            $windDir = $windArrow;
        }
        // 선수방향은 항상 화살표 1개
        $shipEntryDir = isset($wind_entry["wind_dir"]) ? convertDirectionToArrow(($wind_entry["wind_dir"] + 180) % 360) : "❓";
    }

    // 조류
    if (!empty($current_entry)) {
        $currentSpeedVal = isset($current_entry["current_speed"]) ? floatval($current_entry["current_speed"]) : null;
        $currentArrow = isset($current_entry["current_dir"]) ? convertDirectionToArrow($current_entry["current_dir"]) : "❓";
        // cm/s → kt 변환
        $ktVal = ($currentSpeedVal !== null) ? round($currentSpeedVal / 51.4444, 2) : null;

        if ($ktVal !== null) {
            if ($ktVal >= 2) { // 1노트 이상이면 화살표 두 개
                $currentDir = $currentArrow . $currentArrow . " (" . $ktVal . "kt)";
            } else {
                $currentDir = $currentArrow . " (" . $ktVal . "kt)";
            }
        } else {
            $currentDir = $currentArrow;
        }

        if (isset($wind_entry["wind_dir"], $current_entry["current_dir"])) {
            $direction = calculateShipDirection($wind_entry["wind_dir"], $current_entry["current_dir"]);
        }
    }

    $forecast_data[] = [
        "time" => $display_time,
        "wind_speed" => $windSpeed,
        "wind_dir" => $windDir,
        "ship_entry_dir" => $shipEntryDir,
        "current_dir" => $currentDir,
        "direction" => $direction
    ];
}


//echo "=== 최종 예측 데이터 ===\n";
//print_r($forecast_data);

//echo "\n[INFO] API 완료. 결과는 JSON으로 반환됩니다.\n";

echo json_encode(["status" => "success", "forecast" => $forecast_data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

// ======= 함수 영역 =======

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

// ======= curl 사용 fetch 함수 =======

function fetchWindDataCurl($lat, $lon, $date, $dayType='day') {
    $day = DateTime::createFromFormat('Y-m-d', $date);
    if (!$day) $day = new DateTime();
    $start = $day->format('Y-m-d');
    // 야간이면 +1일까지 end_date
    $end = ($dayType === 'night') ? $day->modify('+1 day')->format('Y-m-d') : $start;

    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=wind_speed_10m,wind_direction_10m&timezone=Asia%%2FSeoul&start_date=%s&end_date=%s',
        $lat, $lon, $start, $end
    );
    // echo "[INFO] 풍향 API URL: $url\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [];
    }
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


function fetchCurrentDataCurl($obsCode, $date) {
    global $API_KEY, $BASE_URL_CURRENT;
    $url = "$BASE_URL_CURRENT?ServiceKey=$API_KEY&ObsCode=$obsCode&Date=$date&ResultType=json";
    //echo "[INFO] 조류 API URL: $url\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);

    // User-Agent 추가!
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36');

    // 필요하면 Referer도 추가
    // curl_setopt($ch, CURLOPT_REFERER, "http://www.khoa.go.kr/");

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [];
    }
    $json = json_decode($response, true);
    if (empty($json["result"]["data"])) {
        return [];
    }
    return $json["result"]["data"];
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
    return $closestEntry;
}

function calculateShipDirection($wind_dir, $current_dir) {
    // wind_dir이 숫자라면 그대로, 아니라면 변환
    if (!is_numeric($wind_dir)) {
        $wind_dir = convertDirectionToDegree($wind_dir);
    } else {
        $wind_dir = floatval($wind_dir);
    }
    // current_dir도 마찬가지
    if (!is_numeric($current_dir)) {
        $current_dir = convertDirectionToDegree($current_dir);
    } else {
        $current_dir = floatval($current_dir);
    }

    if ($wind_dir === "" || $current_dir === "" || !is_numeric($wind_dir) || !is_numeric($current_dir)) {
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
function getEntryByTime($data, $datetime, $timeKey) {
    foreach ($data as $entry) {
        $t = isset($entry[$timeKey]) ? $entry[$timeKey] : null;
        // "2025-06-12 03:00:00" → "2025-06-12 03:00"
        if ($t !== null && substr($t,0,16) === $datetime) return $entry;
        // "2025-06-12T03:00" → "2025-06-12 03:00"
        if ($t !== null && str_replace('T', ' ', substr($t,0,16)) === $datetime) return $entry;
    }
    return [];
}