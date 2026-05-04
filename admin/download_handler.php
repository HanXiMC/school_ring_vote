<?php
header("Content-Type: text/html; charset=utf-8");
ini_set('display_errors', 0); 
set_time_limit(0); 

$apiContextOptions = [
    'http' => ['timeout' => 15],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
];
$apiContext = stream_context_create($apiContextOptions);

if (isset($_GET['action'])) {
    if ($_GET['action'] === 'search') {
        $keywords = isset($_GET['keywords']) ? urlencode($_GET['keywords']) : '';
        $searchUrl = "https://music.rczx.asia/search?keywords={$keywords}";
        header("Content-Type: application/json; charset=utf-8");
        echo @file_get_contents($searchUrl, false, $apiContext);
        exit;
    }
    if ($_GET['action'] === 'detail') {
        $ids = isset($_GET['ids']) ? urlencode($_GET['ids']) : '';
        $detailUrl = "https://music.rczx.asia/song/detail?ids={$ids}";
        header("Content-Type: application/json; charset=utf-8");
        echo @file_get_contents($detailUrl, false, $apiContext);
        exit;
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("无效的歌曲ID");
}

$possiblePaths = [
    __DIR__ . '/../vote/music_data.json',
    __DIR__ . '/music_data.json',
    '../vote/music_data.json'
];

$musicDataFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $musicDataFile = $path;
        break;
    }
}

$targetSong = null;

if ($musicDataFile) {
    $jsonContent = file_get_contents($musicDataFile);
    $data = json_decode($jsonContent, true);
    
    if (is_array($data)) {
        foreach ($data as $song) {
            if (isset($song['id']) && (int)$song['id'] === $id) {
                $targetSong = $song;
                break;
            }
        }
    }
}

$sourceId = $id;
$displayTitle = 'music_' . $id;

if ($targetSong) {
    $sourceId = !empty($targetSong['source_id']) ? $targetSong['source_id'] : $id;
    $displayTitle = !empty($targetSong['title']) ? $targetSong['title'] : $displayTitle;
}

// 请求 API 获取直链
$level = isset($_GET['level']) ? $_GET['level'] : 'exhigh'; 
$apiUrl = "https://api.byfuns.top/1/?id={$sourceId}&level={$level}";

$apiResponse = @file_get_contents($apiUrl, false, $apiContext);
$downloadUrl = '';

if ($apiResponse) {
    $apiData = json_decode($apiResponse, true);
    if (is_array($apiData) && !empty($apiData['data'][0]['url'])) {
        $downloadUrl = $apiData['data'][0]['url'];
    } elseif (is_array($apiData) && !empty($apiData['url'])) {
        $downloadUrl = $apiData['url']; 
    } else {
        $downloadUrl = trim($apiResponse);
    }
}

if (empty($downloadUrl) || !filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
    $errorMsg = $apiResponse ? htmlspecialchars($apiResponse) : "API 未返回任何数据";
    $debugInfo = "内部ID: {$id}, 映射ID: {$sourceId}, 数据文件: " . ($musicDataFile ? "已找到" : "未找到");
    die("解析失败，API 未返回有效的下载直链。<br>调试信息: {$debugInfo}<br>错误详情: " . $errorMsg);
}

$ext = 'mp3';
$parsedPath = parse_url($downloadUrl, PHP_URL_PATH);
if ($parsedPath) {
    $pathExt = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION));
    if (in_array($pathExt, ['mp3', 'flac', 'wav', 'm4a', 'ogg'])) {
        $ext = $pathExt;
    }
}

$fileTitle = preg_replace('/[\\/:"*?<>|]/', '', $displayTitle); 
$filename = $fileTitle . '.' . $ext;

try {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=utf-8\'\'' . rawurlencode($filename));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    if (ob_get_level()) ob_end_clean();

    $contextOptions = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                        "Referer: https://music.163.com/\r\n"
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($contextOptions);

    $headers = @get_headers($downloadUrl, 1);
    if ($headers && isset($headers['Content-Length'])) {
        $size = is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length'];
        header('Content-Length: ' . $size);
    }

    $stream = @fopen($downloadUrl, 'rb', false, $context);
    if ($stream) {
        while (!feof($stream)) {
            echo fread($stream, 8192);
            flush();
        }
        fclose($stream);
    } else {
        header("Location: $downloadUrl");
    }
} catch (Exception $e) {
    die("下载出错: " . $e->getMessage());
}
?>
