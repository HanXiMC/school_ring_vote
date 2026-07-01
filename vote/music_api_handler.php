<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
date_default_timezone_set('Asia/Shanghai');
// 开启错误日志
ini_set('log_errors', 1);
ini_set('error_log', 'api_debug.log');

// 处理预检请求
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// 前端通过此文件中转获取网易云歌词
if (isset($_GET['action']) && $_GET['action'] === 'get_lyric' && isset($_GET['url'])) {
    $url = $_GET['url'];
    if (strpos($url, 'music.163.com') !== false) {
        echo fetchApiData($url);
    } else {
        echo json_encode(['code' => 400, 'lrc' => ['lyric' => '[00:00.000]无法获取歌词']]);
    }
    exit();
}
// ==========================

$response = ['success' => false, 'message' => ''];

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $platform = $_POST['platform'] ?? '';
    $artistSong = $_POST['artist_song'] ?? '';
    $musicLink = $_POST['music_link'] ?? '';
    // 获取自动识别标记
    $autoIdentify = isset($_POST['auto_identify']) && $_POST['auto_identify'] == '1';

    if (!$platform) {
        returnJson(['success' => false, 'message' => '请指定音乐平台']);
    }

    if (!$autoIdentify && !$artistSong && !$musicLink) {
        returnJson(['success' => false, 'message' => '请提供歌手和歌曲名或音乐链接']);
    }

    try {
        if ($platform === 'netease') {
            $result = handleNetEaseMusic($artistSong, $musicLink, $autoIdentify);
        } else if ($platform === 'qq') {
            $result = handleQQMusic($artistSong, $musicLink);
        } else {
            throw new Exception('不支持的音乐平台');
        }

        if ($result['success']) {
            $savedResult = saveMusicToData($result['data']);
            if ($savedResult) {
                $response['success'] = true;
                $response['message'] = '音乐添加成功：' . $result['data']['title'];
            } else {
                $response['message'] = '音乐信息获取成功，但保存失败';
            }
        } else {
            $response['message'] = $result['message'];
        }

    } catch (Exception $e) {
        $response['message'] = '处理失败：' . $e->getMessage();
    }
} else {
    $response['message'] = '请求方法错误';
}

echo json_encode($response);
exit();

function returnJson($data) {
    echo json_encode($data);
    exit();
}

function fetchApiData($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // 扩大超时时间到 20 秒
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // 扩大连接超时到 15 秒
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_REFERER, 'https://music.163.com/');
    // 添加 TCP Keep-Alive 保活
    curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return false;
    return $result;
}

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 扩大超时时间到 15 秒
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 扩大连接超时到 10 秒
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_REFERER, 'https://music.163.com/');
    // 添加 TCP Keep-Alive 保活
    curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL); 
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error || $httpCode == 0) return false;
    
    if (($httpCode >= 300 && $httpCode < 400) && !empty($redirectUrl)) {
        return $redirectUrl;
    }
    
    if ($httpCode == 200 && !empty($result)) {
        $json = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($json['data']) && is_array($json['data']) && isset($json['data'][0]['url'])) {
                return $json['data'][0]['url'];
            }
            if (isset($json['url'])) {
                return $json['url'];
            }
        }
        return trim($result);
    }
    
    return false;
}

// 获取网易云详情
function getNetEaseSongDetail($id) {
    // 获取音乐详情
    $url = "https://music.rczx.asia/song/detail?ids=" . $id;
    
    $json = fetchApiData($url);
    
    if ($json) {
        $data = json_decode($json, true);
        if (isset($data['songs']) && !empty($data['songs'])) {
            $song = $data['songs'][0];
            
            // 提取基础信息
            $name = $song['name'] ?? '';
            $cover = $song['al']['picUrl'] ?? $song['album']['picUrl'] ?? '';
            
            // 提取专辑名称
            $album = $song['al']['name'] ?? $song['album']['name'] ?? '';
            // 提取并拼接歌手
            $artists = [];
            $arList = $song['ar'] ?? $song['artists'] ?? [];
            if (!empty($arList)) {
                foreach ($arList as $ar) {
                    $artists[] = $ar['name'];
                }
            }
            $artistName = implode('/', $artists);
            
            // 组合标题：歌手 - 歌名
            $title = ($artistName ? $artistName . ' - ' : '') . $name;
            
            return [
                'title' => $title,
                'cover' => $cover,
                'album' => $album,    
                'artist' => $artistName 
            ];
        }
    }
    return null;
}

function handleNetEaseMusic($artistSong, $musicLink, $autoIdentify) {
    $musicId = null;
    
    if ($musicLink) {
        $musicId = extractNetEaseMusicId($musicLink);
        if (!$musicId) {
            return ['success' => false, 'message' => '无法从链接中提取ID，请检查链接格式'];
        }
    } elseif ($artistSong) {
        // 无链接时 搜索接口通过关键词获取 id
        $searchUrl = "https://music.rczx.asia/search?keywords=" . urlencode($artistSong);
        $searchRes = fetchApiData($searchUrl);
        if ($searchRes) {
            $searchData = json_decode($searchRes, true);
            if (isset($searchData['result']['songs'][0]['id'])) {
                $musicId = $searchData['result']['songs'][0]['id'];
            }
        }
        if (!$musicId) {
            return ['success' => false, 'message' => '未能搜索到相关歌曲，请尝试提供音乐链接'];
        }
    } else {
        return ['success' => false, 'message' => '请提供网易云音乐链接或输入歌手及歌曲名'];
    }
    
    // 初始化变量
    $title = '';
    $cover = '';
    $album = '';
    $artist = '';
    
    // 自动识别
    if ($autoIdentify) {
        $detail = getNetEaseSongDetail($musicId);
        if ($detail) {
            $title = $detail['title'];
            $cover = $detail['cover'];
            $album = $detail['album']; // 获取专辑
            $artist = $detail['artist']; // 获取歌手
        } else {
            return ['success' => false, 'message' => '自动识别歌曲信息失败，请检查链接或稍后重试'];
        }
    } else {
        $title = $artistSong ?: "网易云音乐_" . $musicId;
        $parts = explode(' - ', $title);
        if (count($parts) > 1) {
            $artist = trim($parts[0]);
        }
    }
    
    // 获取音频链接
    $apiStandard = "https://api.byfuns.top/1/?id=" . $musicId . "&level=exhigh"; 
    $apiHigh = "https://api.byfuns.top/1/?id" . $musicId . "&level=lossless&unblock=true"; 
    $officialFallback = "http://music.163.com/song/media/outer/url?id=" . $musicId . ".mp3";
    

    $lyricUrl = "https://music.163.com/api/song/lyric?os=pc&id=" . $musicId . "&lv=-1";
    // ====================================
    
    $finalUrlStandard = null;
    $finalUrlHigh = null;

    $resStandard = fetchUrl($apiStandard);
    if ($resStandard && filter_var($resStandard, FILTER_VALIDATE_URL)) {
        $finalUrlStandard = $resStandard; 
    } else {
        $finalUrlStandard = $officialFallback;
    }

    $resHigh = fetchUrl($apiHigh);
    if ($resHigh && filter_var($resHigh, FILTER_VALIDATE_URL)) {
        $finalUrlHigh = $resHigh;
    } else {
        $finalUrlHigh = $finalUrlStandard; 
    }
    
    $data = [
        'title' => $title,
        'source' => 'netease',
        'source_id' => $musicId,
        'cover' => $cover,
        'album' => $album,   
        'artist' => $artist,
        'lyric_url' => $lyricUrl 
    ];
    
    if ($finalUrlStandard) {
        $data['url_standard'] = $finalUrlStandard;
        $data['quality_standard'] = 'standard';
        $data['url'] = $finalUrlStandard;
        $data['quality'] = 'standard';
    }
    
    if ($finalUrlHigh) {
        $data['url_high'] = $finalUrlHigh;
        $data['quality_high'] = 'high';
        $data['url'] = $finalUrlHigh;
        $data['quality'] = 'high';
    }
    
    return [
        'success' => true,
        'data' => $data
    ];
}

function handleQQMusic($artistSong, $musicLink) {
    $musicId = null; $musicMid = null;
    if ($musicLink) {
        $extracted = extractQQMusicId($musicLink);
        $musicId = $extracted['id'];
        $musicMid = $extracted['mid'];
    }
    $title = $artistSong ?: "QQ音乐_" . ($musicMid ?: $musicId);
    $playUrl = $musicLink ?: "https://y.qq.com/n/ryqq/songDetail/" . ($musicMid ?: $musicId);
    
    return [
        'success' => true,
        'data' => [
            'title' => $title,
            'url' => $playUrl,
            'source' => 'qq_fallback',
            'source_id' => $musicMid ?: $musicId,
            'note' => 'QQ音乐暂仅支持保存信息'
        ]
    ];
}

function extractNetEaseMusicId($link) {
    $patterns = ['/[?&]id=(\d+)/', '/song\?id=(\d+)/', '/song\/(\d+)/'];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $link, $matches)) return $matches[1];
    }
    return null;
}

function extractQQMusicId($link) {
    $result = ['id' => null, 'mid' => null];
    if (preg_match('/songDetail\/([A-Za-z0-9]+)/', $link, $matches)) $result['mid'] = $matches[1];
    if (preg_match('/songid=(\d+)/', $link, $matches)) $result['id'] = $matches[1];
    if (preg_match('/[?&]id=(\d+)/', $link, $matches)) $result['id'] = $matches[1];
    return $result;
}

function saveMusicToData($musicData) {
    $musicDataFile = 'music_data.json';
    $existingData = [];
    if (file_exists($musicDataFile)) {
        $existingData = json_decode(file_get_contents($musicDataFile), true) ?: [];
    }
    $nextId = 1;
    if (!empty($existingData)) $nextId = max(array_column($existingData, 'id')) + 1;
    
    $newMusicEntry = array_merge(['id' => $nextId], $musicData);
    $existingData[] = $newMusicEntry;
    return file_put_contents($musicDataFile, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}
?>
