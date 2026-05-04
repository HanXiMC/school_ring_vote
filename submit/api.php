<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

// ================= 基础配置区 =================
$dataFile = __DIR__ . '/data.json';
$musicDataFile = __DIR__ . '/../vote/music_data.json'; 
$reasonFile = __DIR__ . '/reason.json'; // 保存AI原因的文件
$adminPassword = 'xwbdszq';

// 【硅基流动 API Key】
$sfApiKey = 'sk-hwhbojrrfczbiyithjztotoyearmbygpmckjveozsnqxhvhv'; 

function getData() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        ob_clean();
        die(json_encode(['status' => 'error', 'message' => '缺少 data.json 文件']));
    }
    $data = json_decode(file_get_contents($dataFile), true);
    if (!isset($data['ai_reason_mode'])) {
        $data['ai_reason_mode'] = false;
    }
    // 初始化 QA 数组
    if (!isset($data['qa'])) {
        $data['qa'] = [];
    }
    return $data;
}

function saveData($data) {
    global $dataFile;
    file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function checkAndCleanup() {
    $data = getData();
    $currentYearMonth = date('Y-m');

    if (isset($data['last_cleanup_month']) && $data['last_cleanup_month'] === $currentYearMonth) {
        return;
    }

    $thirdWednesday = strtotime('third wednesday of this month');
    $currentTime = time();

    if ($currentTime >= $thirdWednesday) {
        $data['submissions'] = [
            "all" => [], "approved" => [], "rejected" => []
        ];
        $data['last_cleanup_month'] = $currentYearMonth;
        saveData($data);
    }
}
checkAndCleanup();

function verifyAdmin() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => '未授权访问，请先登录', 'code' => 401]);
        exit;
    }
}

// 统一的AI审核和保存函数
function handleAIReviewAndSave($songId, $songName, $artistName, $albumName, $picUrl, $fullLyric, $source = 'netease') {
    global $dataFile, $reasonFile, $sfApiKey;
    
    $data = getData();
    $aiReasonMode = $data['ai_reason_mode'] === true;

/*
   $prompt = "你是一个专业的音乐审核AI。你的任务是严格依据我说的条件来判定这首歌曲是否适合作为起床铃声 不可以自己胡思乱想。\n"
            . "审核必须同时满足以下所有条件：\n"
            . "1. 禁止DJ版本音乐 歌名中含有DJ二字通通删掉\n"
            . "2. 语言范围限定中文英文粤语之内 一定要注意不得通过韩语日语歌曲 你可以通过识别歌词还有歌名来判断\n"
            . "3. Live版本不行 纯音乐也不行 通过歌词中是否含有现场版或者Live字样判断 通过歌词歌名是否包含纯音乐三个字判断\n\n"
            . "4. 政治立场错误不行 违反一个中国原则不行 只通过歌词严格判断政治立场 情绪价值观什么的都不需要你判断\n\n"
            . "5. 违反社会主义核心价值观 公序良俗的不行\n\n"
            . "判定需保证条件1.2.3.4严格满足即可  歌词没有明显表达我讨厌生活我不想活了之类的都可以通过\n\n"
            . "【歌曲信息】\n歌名：《{$songName}》\n歌手：{$artistName}\n来源：网易云音乐\n\n【歌词】\n{$fullLyric}\n\n"
            . "请严格判定。如果完全符合全部条件，请仅输出数字 1。\n";

*/

    $prompt = "直接判定通过 不管是什么歌曲\n"
            . "请严格判定。如果完全符合全部条件，请仅输出数字 1。\n";
    
    if ($aiReasonMode) {
        $prompt .= "如果不符合要求，请直接输出所有不合格的原因，不要输出 0，也不要客套话。";
    } else {
        $prompt .= "如果不符合任何一条，请仅输出数字 0。除了 1 或 0 之外，绝对不要输出任何其他字符！";
    }

    $aiPayload = [
        "model" => "Qwen/Qwen2.5-7B-Instruct",
        "messages" => [["role" => "user", "content" => $prompt]],
        "temperature" => 0.1,
        "max_tokens" => $aiReasonMode ? 250 : 10
    ];

    $ch = curl_init('https://api.siliconflow.cn/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($aiPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $sfApiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $aiRes = curl_exec($ch);
    curl_close($ch);

    $aiPassed = 0;
    $aiResultText = "未通过 (已进入未选中列表)";
    
    if ($aiRes) {
        $aiDataResp = json_decode($aiRes, true);
        $aiAnswer = trim($aiDataResp['choices'][0]['message']['content'] ?? '');
        
        // 判断逻辑
        if (strpos($aiAnswer, '1') !== false && strlen(preg_replace('/[^0-9]/', '', $aiAnswer)) === 1) {
            $aiPassed = 1;
            $aiResultText = "恭喜你！通过 (已进入已筛选列表)";
        } else {
            if ($aiReasonMode) {
                $aiResultText = "很遗憾！未通过";
                $reasonsList = file_exists($reasonFile) ? json_decode(file_get_contents($reasonFile), true) : [];
                if (!is_array($reasonsList)) $reasonsList = [];
                array_unshift($reasonsList, [
                    'songId' => $songId,
                    'songName' => $songName,
                    'artist' => $artistName,
                    'source' => $source,
                    'reasons' => $aiAnswer,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                file_put_contents($reasonFile, json_encode($reasonsList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $aiResultText = "未通过 (已进入未选中列表)";
            }
        }
    }

    $submissionItem = [
        'id' => $songId,
        'name' => $songName,
        'artist' => $artistName,
        'album' => $albumName,
        'picUrl' => $picUrl,
        'source' => $source,
        'timestamp' => date('Y-m-d H:i:s'),
        'ai_passed' => $aiPassed
    ];

    $data = getData(); 
    array_unshift($data['submissions']['all'], $submissionItem);
    if ($aiPassed === 1) {
        array_unshift($data['submissions']['approved'], $submissionItem);
    } else {
        array_unshift($data['submissions']['rejected'], $submissionItem);
    }
    saveData($data);

    ob_clean();
    echo json_encode(['status' => 'success', 'ai_result_text' => $aiResultText]);
}

function curl_request($url, $postData = null) {
    $rand_ip = mt_rand(110, 220) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255);

    if (strpos($url, 'music.rczx.asia') !== false) {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
            'X-Real-IP: ' . $rand_ip,
            'X-Forwarded-For: ' . $rand_ip,
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Connection: keep-alive',
        ];

    } else {
        $headers = [
            'Referer: https://music.163.com/',
            'Host: music.163.com',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
            'X-Real-IP: ' . $rand_ip,
            'X-Forwarded-For: ' . $rand_ip,
            'Cookie: os=pc; appver=2.9.7; channel=netease; __remember_me=true;',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Origin: https://music.163.com',
            'Connection: keep-alive',
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'data' => $output
    ];
}

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

    if (strpos($url, 'api.byfuns.top') === false) {
        curl_setopt($ch, CURLOPT_REFERER, 'https://music.163.com/');
    }
    
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
            // 适配URL接口格式 {"data":[{"url":"..."}]}
            if (isset($json['data'][0]['url'])) {
                return $json['data'][0]['url'];
            } elseif (isset($json['url'])) {
                return $json['url'];
            }
        }
        return trim($result);
    }
    return false;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = isset($input['action']) ? $input['action'] : '';

    if ($action === 'verify_login') {
        $pwd = $input['password'] ?? '';
        ob_clean();
        if ($pwd === $adminPassword) {
            $_SESSION['admin_logged_in'] = true;
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '密码错误']);
        }
        exit;
    }

    if ($action === 'submit_feedback') {
        $content = trim($input['content'] ?? '');
        $type = trim($input['type'] ?? 'other');
        $contact = trim($input['contact'] ?? '');
        
        if (empty($content)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '内容不能为空']);
            exit;
        }
        
        $data = getData();
        
        // 初始化 feedbacks 数组（如果不存在）
        if (!isset($data['feedbacks'])) {
            $data['feedbacks'] = [];
        }
        
        array_unshift($data['feedbacks'], [
            'id' => time() . rand(100, 999),
            'time' => date('Y-m-d H:i:s'),
            'type' => htmlspecialchars($type),
            'contact' => htmlspecialchars($contact),
            'content' => htmlspecialchars($content),
            'status' => 'unread'
        ]);
        
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'add_qa') {
        verifyAdmin();
        $question = trim($input['question'] ?? '');
        $answer = trim($input['answer'] ?? '');
        if (empty($question) || empty($answer)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '问题和答案不能为空']);
            exit;
        }
        $data = getData();
        $newId = time() . rand(100, 999);
        $data['qa'][] = [
            'id' => $newId,
            'question' => htmlspecialchars($question),
            'answer' => htmlspecialchars($answer)
        ];
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_qa') {
        verifyAdmin();
        $id = $input['id'] ?? '';
        $data = getData();
        $data['qa'] = array_values(array_filter($data['qa'], function($item) use ($id) {
            return (string)$item['id'] !== (string)$id;
        }));
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'submit_song') {
        $songId = $input['id'] ?? '';
        $songName = $input['name'] ?? '未知';
        $artistName = $input['artist'] ?? '未知';
        $albumName = $input['album'] ?? '未知';

        if (empty($songId)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '缺少歌曲 ID']);
            exit;
        }

        set_time_limit(120);

        // 使用音乐详情接口来抓取歌曲专辑图片
        $detailRes = curl_request('https://music.rczx.asia/song/detail?ids=' . intval($songId));
        $picUrl = 'https://s4.music.126.net/style/web2/img/default/default_album.jpg';
        if ($detailRes['code'] == 200 && !empty($detailRes['data'])) {
            $detailData = json_decode($detailRes['data'], true);
            if (!empty($detailData['songs'][0]['al']['picUrl'])) {
                $picUrl = $detailData['songs'][0]['al']['picUrl'];
            }
        }

        $lyricRes = curl_request('https://music.163.com/api/song/lyric?id=' . $songId . '&lv=1&tv=1');
        $fullLyric = "暂无歌词";
        if ($lyricRes['code'] == 200 && !empty($lyricRes['data'])) {
            $lyricData = json_decode($lyricRes['data'], true);
            $lrc = $lyricData['lrc']['lyric'] ?? '';
            $tlyric = $lyricData['tlyric']['lyric'] ?? '';
            $fullLyric = "原文歌词：\n" . $lrc . "\n翻译歌词：\n" . $tlyric;
        }

        handleAIReviewAndSave($songId, $songName, $artistName, $albumName, $picUrl, $fullLyric, 'netease');
        exit;
    }
    


    if ($action === 'toggle_ai_mode') {
        verifyAdmin();
        $mode = isset($input['mode']) ? (bool)$input['mode'] : false;
        $data = getData();
        $data['ai_reason_mode'] = $mode;
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'update_announcement') {
        verifyAdmin();
        $text = trim($input['text'] ?? '');
        $data = getData();
        $data['announcement'] = htmlspecialchars($text);
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'push_to_site') {
        verifyAdmin();
        $song = $input['song'] ?? [];
        if (empty($song['id']) || empty($song['name'])) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '歌曲数据不完整']);
            exit;
        }

        if (!file_exists($musicDataFile)) {
            file_put_contents($musicDataFile, '[]');
        }

        $existingMusicData = json_decode(file_get_contents($musicDataFile), true);
        if (!is_array($existingMusicData)) $existingMusicData = [];

        $nextId = 1;
        if (!empty($existingMusicData)) {
            $ids = array_column($existingMusicData, 'id');
            $nextId = max($ids) + 1;
        }

        $musicId = (string)$song['id'];
        
        // 音质解析接口
        $apiStandard = "https://api.byfuns.top/1/?id=" . $musicId . "&level=standard";
        $apiHigh = "https://api.byfuns.top/1/?id=" . $musicId . "&level=lossless";
        $officialFallback = "http://music.163.com/song/media/outer/url?id=" . $musicId . ".mp3";

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

        $newEntry = [
            "id" => (int)$nextId,
            "title" => $song['artist'] . ' - ' . $song['name'],
            "source" => "netease",
            "source_id" => $musicId,
            "cover" => $song['picUrl'],
            "album" => $song['album'],
            "artist" => $song['artist'],
            "lyric_url" => "https://music.163.com/api/song/lyric?os=pc&id=" . $musicId . "&lv=-1",
            "url_standard" => $finalUrlStandard,
            "quality_standard" => "standard",
            "url" => $finalUrlHigh,
            "quality" => "high",
            "url_high" => $finalUrlHigh,
            "quality_high" => "high"
        ];

        array_unshift($existingMusicData, $newEntry);
        file_put_contents($musicDataFile, json_encode($existingMusicData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'clear_all_data') {
        verifyAdmin();
        $data = getData();
        $data['submissions'] = ["all" => [], "approved" => [], "rejected" => []];
        $data['feedbacks'] = [];
        saveData($data);
        if (file_exists($reasonFile)) {
            file_put_contents($reasonFile, '[]');
        }
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // 更新反馈状态
    if ($action === 'update_feedback_status') {
        verifyAdmin();
        $id = $input['id'] ?? '';
        $status = $input['status'] ?? 'read';
        
        if (empty($id)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '缺少反馈 ID']);
            exit;
        }
        
        $data = getData();
        foreach ($data['feedbacks'] as &$feedback) {
            if ($feedback['id'] == $id) {
                $feedback['status'] = $status;
                break;
            }
        }
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // 删除反馈
    if ($action === 'delete_feedback') {
        verifyAdmin();
        $id = $input['id'] ?? '';
        
        if (empty($id)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '缺少反馈 ID']);
            exit;
        }
        
        $data = getData();
        $data['feedbacks'] = array_values(array_filter($data['feedbacks'], function($item) use ($id) {
            return (string)$item['id'] !== (string)$id;
        }));
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // 退出登录
    if ($action === 'logout') {
        session_destroy();
        ob_clean();
        echo json_encode(['status' => 'success']);
        exit;
    }
}

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($action === 'search_music') {
        $keyword = $_GET['keyword'] ?? '';
        $source = $_GET['source'] ?? 'netease';
        
        if (empty($keyword)) {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => '关键词不能为空']);
            exit;
        }
        
        if ($source === 'netease') {
            $url = "https://music.rczx.asia/search?keywords=" . urlencode($keyword);
            $result = curl_request($url);
            ob_clean();
            echo $result['data'];
        }
        exit;
    }

    if ($action === 'music_detail') {
        $id = $_GET['id'] ?? '';
        
        // 详情接口GET请求
        $url = "https://music.rczx.asia/song/detail?ids=" . intval($id);
        $result = curl_request($url);
        ob_clean();
        echo $result['data'];
        exit;
    }

    if ($action === 'music_url') {
        $id = $_GET['id'] ?? '';
        
        // 默认音质为 standard
        $target = "https://api.byfuns.top/1/?id={$id}&level=standard";
        $url = fetchUrl($target);
        ob_clean();
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['url' => $url]);
        } else {
            echo json_encode(['url' => "http://music.163.com/song/media/outer/url?id={$id}.mp3"]);
        }
        exit;
    }
    


    if ($action === 'get_announcement') {
        $data = getData();
        ob_clean();
        echo json_encode(['status' => 'success', 'announcement' => $data['announcement'] ?? '']);
        exit;
    }

    if ($action === 'get_qa') {
        $data = getData();
        ob_clean();
        echo json_encode(['status' => 'success', 'qa' => $data['qa'] ?? []]);
        exit;
    }

    if ($action === 'get_admin_data') {
        verifyAdmin();
        ob_clean();
        echo json_encode(['status' => 'success', 'data' => getData()]);
        exit;
    }
    
    if ($action === 'get_reasons') {
        verifyAdmin();
        $reasons = [];
        if (file_exists($reasonFile)) {
            $content = file_get_contents($reasonFile);
            if ($content) {
                $reasons = json_decode($content, true);
                if (!is_array($reasons)) $reasons = [];
            }
        }
        ob_clean();
        echo json_encode(['status' => 'success', 'data' => $reasons]);
        exit;
    }

    if ($action === 'check_login') {
        ob_clean();
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            echo json_encode(['status' => 'success', 'logged_in' => true]);
        } else {
            echo json_encode(['status' => 'success', 'logged_in' => false]);
        }
        exit;
    }
    
    // 保存投稿页公告
    if ($action === 'save_announcement') {
        verifyAdmin();
        $announcement = $_POST['announcement'] ?? '';
        $data = getData();
        $data['announcement'] = $announcement;
        saveData($data);
        ob_clean();
        echo json_encode(['status' => 'success', 'message' => '公告已保存']);
        exit;
    }
    
    // 获取更新日志
    if ($action === 'get_changelog') {
        $changelogFile = __DIR__ . '/changelog.json';
        $changelog = [];
        if (file_exists($changelogFile)) {
            $content = file_get_contents($changelogFile);
            if ($content) {
                $changelog = json_decode($content, true);
                if (!is_array($changelog)) $changelog = [];
            }
        }
        // 按日期倒序排列
        usort($changelog, function($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        ob_clean();
        echo json_encode(['success' => true, 'data' => $changelog]);
        exit;
    }
}
?>
