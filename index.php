<?php
$obsCodeList = [
    "08JJ13" => "애월항북측",
    "23LTC02" => "제주도서측",
    "02JJ-1"  => "제주항",
    "08JJ07"  => "서귀포"
];
$defaultObs = "08JJ13";
$selectedObs = $_GET['currentObsCode'] ?? $defaultObs;

// 일자 기본값 (오늘, Ymd형식)
$today = date('Y-m-d');
$selectedDate = $_GET['targetDate'] ?? $today;

// 주간/야간 선택값 (기본 주간)
$dayType = $_GET['dayType'] ?? 'day';

$api_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/forecast.php?currentObsCode=" . urlencode($selectedObs) . "&targetDate=" . urlencode($selectedDate) . "&dayType=" . urlencode($dayType);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$response = curl_exec($ch);
if ($response === false) {
    echo "<div class='alert alert-danger mt-4'>[ERROR] API 호출 실패! (curl error: " . curl_error($ch) . ")</div>";
    exit;
}
curl_close($ch);

$data = json_decode($response, true);
if ($data === null) {
    echo "<div class='alert alert-danger mt-4'>[ERROR] JSON 파싱 실패! (json_last_error: " . json_last_error_msg() . ")</div>";
    exit;
}
if (!isset($data['forecast']) || !is_array($data['forecast'])) {
    echo "<div class='alert alert-danger mt-4'>[ERROR] forecast 데이터가 없습니다.</div>";
    echo "<pre>" . print_r($data, true) . "</pre>";
    exit;
}

// --- ★ direction 값 집계 및 최적 포지션 산출 ---
$dirCount = [];
foreach ($data['forecast'] as $row) {
    $d = $row['direction'];
    if ($d && $d !== "데이터 없음") {
        if (!isset($dirCount[$d])) $dirCount[$d] = 0;
        $dirCount[$d]++;
    }
}
if (count($dirCount) > 0) {
    arsort($dirCount);
    $bestDir = key($dirCount);
    $bestCnt = current($dirCount);
    $totalCnt = array_sum($dirCount);
    $percent = round($bestCnt / $totalCnt * 100);
    $bestSummary = "<div class='alert alert-danger fw-bold mb-3'>하루 최적 유리 포지션: <span style='font-size:1.2em;'>$bestDir</span> <span class='ms-2'>(빈도: $bestCnt/$totalCnt, $percent%)</span></div>";
} else {
    $bestSummary = "<div class='alert alert-danger fw-bold mb-3'>하루 최적 유리 포지션: 데이터 부족</div>";
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>선상낚시 조류/풍향 예측</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap-datepicker CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.10.0/css/bootstrap-datepicker.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">선집입 위치 예측</h2>

    <!-- 관측소/날짜/주야간 선택 폼 -->
    <form method="get" class="row g-3 mb-4 align-items-center" id="filterForm">
        <div class="col-auto">
            <label for="currentObsCode" class="col-form-label fw-bold">관측소 선택:</label>
        </div>
        <div class="col-auto">
            <select name="currentObsCode" id="currentObsCode" class="form-select" onchange="this.form.submit()">
                <?php foreach ($obsCodeList as $code => $name): ?>
                    <option value="<?=htmlspecialchars($code)?>" <?=$code == $selectedObs ? "selected" : ""?>>
                        <?=htmlspecialchars($name)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label for="targetDate" class="col-form-label fw-bold">일자 선택:</label>
        </div>
        <div class="col-auto">
            <input type="text" name="targetDate" id="targetDate" class="form-control" value="<?=htmlspecialchars($selectedDate)?>" autocomplete="off" readonly style="background:#fff; cursor:pointer; max-width:140px;">
        </div>
        <div class="col-auto">
            <div class="btn-group" role="group" aria-label="주야간선택">
                <input type="radio" class="btn-check" name="dayType" id="dayTypeDay" value="day" autocomplete="off" <?=$dayType=="day"?"checked":""?>>
                <label class="btn btn-outline-primary" for="dayTypeDay" onclick="$('#filterForm').submit()">주간</label>
                <input type="radio" class="btn-check" name="dayType" id="dayTypeNight" value="night" autocomplete="off" <?=$dayType=="night"?"checked":""?>>
                <label class="btn btn-outline-dark" for="dayTypeNight" onclick="$('#filterForm').submit()">야간</label>
            </div>
        </div>
    </form>

    <!-- ★ 최적 포지션 알림 -->
    <?=$bestSummary?>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle text-center">
            <thead class="table-primary">
            <tr>
                <th>시간</th>
                <th>풍향</th>
                <th>조류</th>
                <th>선수방향</th>
                <th>유리포지션</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($data['forecast'] as $row): ?>
                <tr>
                    <td><?=htmlspecialchars($row['time'])?></td>
                    <td><?=htmlspecialchars($row['wind_dir'])?></td>
                    <td><?=htmlspecialchars($row['current_dir'])?></td>
                    <td><?=htmlspecialchars($row['ship_entry_dir'])?></td>
                    <td><?=htmlspecialchars($row['direction'])?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Bootstrap JS, Popper, jQuery, Datepicker -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.10.0/js/bootstrap-datepicker.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.10.0/locales/bootstrap-datepicker.ko.min.js"></script>
<script>
    $(function(){
        $('#targetDate').datepicker({
            format: "yyyy-mm-dd",
            language: "ko",
            autoclose: true,
            todayHighlight: true,
            endDate: '+10d'
        }).on('changeDate', function(e){
            $('#filterForm').submit();
        });

        // radio 클릭 시 즉시 submit
        $('input[name=dayType]').on('change', function(){
            $('#filterForm').submit();
        });
    });
</script>
</body>
</html>
