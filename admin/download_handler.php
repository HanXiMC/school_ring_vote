<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0); 

while (ob_get_level() > 0) {
    ob_end_clean();
}

$apiContextOptions = [
    'http' => [
        'timeout' => 30,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36\r\n" .
                    "Referer: https://music.163.com/\r\n"
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
];
$apiContext = stream_context_create($apiContextOptions);

if (isset($_GET['action'])) {
    header("Content-Type: application/json; charset=utf-8");
    if ($_GET['action'] === 'search') {
        $keywords = isset($_GET['keywords']) ? urlencode($_GET['keywords']) : '';
        echo @file_get_contents("https://music.rczx.asia/search?keywords={$keywords}", false, $apiContext);
        exit;
    }
    if ($_GET['action'] === 'detail') {
        $ids = isset($_GET['ids']) ? urlencode($_GET['ids']) : '';
        echo @file_get_contents("https://music.rczx.asia/song/detail?ids={$ids}", false, $apiContext);
        exit;
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$level = isset($_GET['level']) ? $_GET['level'] : 'exhigh';

if ($id <= 0) die("Error: 无效的歌曲 ID");

// 读取音乐数据进行自动命名
$possiblePaths = [
    __DIR__ . '/../vote/music_data.json',
    __DIR__ . '/music_data.json',
    dirname(__DIR__) . '/vote/music_data.json'
];

$musicDataFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) { $musicDataFile = $path; break; }
}

$targetSong = null;
if ($musicDataFile) {
    $data = json_decode(file_get_contents($musicDataFile), true);
    if (is_array($data)) {
        foreach ($data as $song) {
            if (isset($song['id']) && (int)$song['id'] === $id) {
                $targetSong = $song; break;
            }
        }
    }
}

$sourceId = $targetSong && !empty($targetSong['source_id']) ? $targetSong['source_id'] : $id;
$songTitle = $targetSong && !empty($targetSong['title']) ? $targetSong['title'] : "Music_" . $id;
$artistName = $targetSong && !empty($targetSong['artist']) ? $targetSong['artist'] : "Unknown";

// 获取直链
$apiResponse = @file_get_contents("https://api.byfuns.top/1/?id={$sourceId}&level={$level}", false, $apiContext);
$downloadUrl = '';

if ($apiResponse) {
    $apiData = json_decode($apiResponse, true);
    if (is_array($apiData)) {
        if (!empty($apiData['data'][0]['url'])) $downloadUrl = $apiData['data'][0]['url'];
        elseif (!empty($apiData['url'])) $downloadUrl = $apiData['url'];
    }
    if (empty($downloadUrl)) $downloadUrl = trim($apiResponse);
}

if (empty($downloadUrl) || !filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
    die("Error: 接口未返回有效链接。");
}

// 自动重命名逻辑
$ext = 'mp3';
$pathInfo = parse_url($downloadUrl, PHP_URL_PATH);
if ($pathInfo) {
    $foundExt = strtolower(pathinfo($pathInfo, PATHINFO_EXTENSION));
    if (in_array($foundExt, ['mp3', 'flac', 'wav', 'm4a', 'ogg'])) $ext = $foundExt;
}

$cleanArtist = preg_replace('/[\\/:"*?<>|]/', '', $artistName);
$cleanTitle = preg_replace('/[\\/:"*?<>|]/', '', $songTitle);
$finalFilename = (strpos($cleanTitle, $cleanArtist) !== false) ? $cleanTitle . '.' . $ext : $cleanArtist . ' - ' . $cleanTitle . '.' . $ext;

try {
    if (session_id()) session_write_close();

    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
    @ini_set('zlib.output_compression', 'Off');

    $headers = @get_headers($downloadUrl, 1);
    $fileSize = 0;
    if ($headers && isset($headers['Content-Length'])) {
        $fileSize = is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length'];
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $finalFilename . '"; filename*=utf-8\'\'' . rawurlencode($finalFilename));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: private, no-transform, no-store, must-revalidate'); 
    header('Pragma: public');
    if ($fileSize > 0) header('Content-Length: ' . $fileSize);

    $fp = @fopen($downloadUrl, 'rb', false, $apiContext);
    if ($fp) {
        fpassthru($fp); 
        fclose($fp);
    } else {
        header("Location: $downloadUrl");
    }
} catch (Exception $e) {
    header("Location: $downloadUrl");
}
exit;