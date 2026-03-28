<?php
header("Content-Type: text/html; charset=utf-8");
ini_set('display_errors', 0); 
set_time_limit(0); 

$apiContextOptions = [
    'http' => ['timeout' => 15], // API 超时设为 15 秒
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
];
$apiContext = stream_context_create($apiContextOptions);

if (isset($_GET['action'])) {
    // 音乐搜索
    if ($_GET['action'] === 'search') {
        $keywords = isset($_GET['keywords']) ? urlencode($_GET['keywords']) : '';
        $searchUrl = "https://music.rczx.asia/search?keywords={$keywords}";
        header("Content-Type: application/json; charset=utf-8");
        echo @file_get_contents($searchUrl, false, $apiContext);
        exit;
    }

    // 音乐详情
    if ($_GET['action'] === 'detail') {
        $ids = isset($_GET['ids']) ? urlencode($_GET['ids']) : '';
        $detailUrl = "https://music.rczx.asia/song/detail?ids={$ids}";
        header("Content-Type: application/json; charset=utf-8");
        echo @file_get_contents($detailUrl, false, $apiContext);
        exit;
    }
}
// ==========================================

$musicDataFile = __DIR__ . '/music_data.json';

// 获取请求ID 
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die("无效的歌曲ID");
}

if (!file_exists($musicDataFile)) {
    die("数据文件不存在");
}

$data = json_decode(file_get_contents($musicDataFile), true);
$targetSong = null;

foreach ($data as $song) {
    if ($song['id'] === $id) {
        $targetSong = $song;
        break;
    }
}

if (!$targetSong) {
    die("未找到该歌曲");
}

if (empty($targetSong['source_id'])) {
    die("该歌曲没有有效的网易云源 ID (source_id)");
}

//请求 API 获取直链 
$sourceId = $targetSong['source_id'];

// 通过 ?level=standard 传参，如果没有传则默认请求 exhigh(极高)
$level = isset($_GET['level']) ? $_GET['level'] : 'exhigh'; 
$apiUrl = "https://music.rczx.asia/song/url/v1?id={$sourceId}&level={$level}";

$apiResponse = @file_get_contents($apiUrl, false, $apiContext);
$downloadUrl = '';

$apiData = json_decode($apiResponse, true);
if (is_array($apiData) && !empty($apiData['data'][0]['url'])) {
    $downloadUrl = $apiData['data'][0]['url'];
} elseif (is_array($apiData) && !empty($apiData['url'])) {
    $downloadUrl = $apiData['url']; 
} else {
    $downloadUrl = trim($apiResponse);
}
// ==========================================

if (empty($downloadUrl) || !filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
    die("解析失败，API 未返回有效的下载直链: " . htmlspecialchars($apiResponse));
}

$ext = 'mp3'; // 默认后缀
$parsedPath = parse_url($downloadUrl, PHP_URL_PATH);
if ($parsedPath) {
    $pathExt = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION));
    // 限制仅捕获常见音频后缀
    if (in_array($pathExt, ['mp3', 'flac', 'wav', 'm4a', 'ogg'])) {
        $ext = $pathExt;
    }
}

$fileTitle = trim($targetSong['title']);
// 替换文件名中的非法字符
$fileTitle = preg_replace('/[\\/:"*?<>|]/', '', $fileTitle); 
$filename = $fileTitle . '.' . $ext;

try {
    // 设置HTTP头强制下载
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    // 兼容各类浏览器处理中文名
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=utf-8\'\'' . rawurlencode($filename));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    // 清除缓冲
    if (ob_get_level()) ob_end_clean();

    // 远程链接
    $contextOptions = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                        "Referer: https://music.163.com/\r\n" .
                        "Accept: */*\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];
    
    $context = stream_context_create($contextOptions);

    // 获取远程文件大小
    $headers = @get_headers($downloadUrl, 1);
    if ($headers && isset($headers['Content-Length'])) {
        $size = is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length'];
        header('Content-Length: ' . $size);
    }

    // 打开远程流
    $stream = @fopen($downloadUrl, 'rb', false, $context);
    
    if ($stream) {
        // 读取并输出远程文件
        while (!feof($stream)) {
            echo fread($stream, 8192);
            flush();
        }
        fclose($stream);
    } else {
        // 如果流打开失败，回退到重定向
        header("Location: $downloadUrl");
    }

} catch (Exception $e) {
    die("下载出错: " . $e->getMessage());
}
?>
