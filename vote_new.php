<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 204 No Content");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type");
    exit;
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', 0); 

$dataFile = __DIR__ . '/vote_records.txt';
$musicDataFile = __DIR__ . '/music_data.json';

// 确保记录文件存在
if (!file_exists($dataFile)) {
    if (@touch($dataFile)) {
        @chmod($dataFile, 0666);
    }
}

// 读取音乐列表用于验证
function readMusicData() {
    global $musicDataFile;
    if (file_exists($musicDataFile)) {
        $content = file_get_contents($musicDataFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    return [];
}

try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // GET: 获取投票信息
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userUniqueId = $_GET['userUniqueId'] ?? '';
        $lines = file_exists($dataFile) ? file($dataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        
        $votedSongs = [];
        $totalUserVotes = 0;
        
        foreach ($lines as $line) {
            $parts = explode(' | ', $line);
            if (count($parts) < 4) continue;
            
            $recordUser = $parts[4] ?? '';
            $recordIp = $parts[1];
            
            // 匹配用户标识或IP
            if (($userUniqueId && $recordUser === $userUniqueId) || (!$userUniqueId && $recordIp === $ip)) {
                $votedSongs[] = (int)$parts[2];
                $totalUserVotes++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'votedSongs' => array_unique($votedSongs),
            'totalVotes' => $totalUserVotes,
            'maxVotes' => 5,
            'remainingVotes' => max(0, 5 - $totalUserVotes)
        ]);
        exit;
    }

    // POST: 提交投票
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $songId = (int)($input['songId'] ?? 0);
        $userUniqueId = trim($input['userUniqueId'] ?? '');
        
        if ($songId <= 0) throw new Exception('无效的歌曲ID');
        if (empty($userUniqueId)) throw new Exception('缺少用户标识');

        // 验证歌曲名称
        $musicList = readMusicData();
        $songName = '';
        foreach ($musicList as $music) {
            if ($music['id'] == $songId) {
                $songName = $music['title'];
                break;
            }
        }
        if (!$songName) throw new Exception('歌曲不存在或已被删除');

        // 使用独占锁进行文件写入
        $fp = fopen($dataFile, 'a+');
        if (flock($fp, LOCK_EX)) {
            // 重新读取文件检查状态
            $fileContent = '';
            rewind($fp);
            while (!feof($fp)) {
                $fileContent .= fread($fp, 8192);
            }
            
            $lines = explode("\n", $fileContent);
            $userVotes = 0;
            $hasVotedThisSong = false;
            
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $parts = explode(' | ', $line);
                if (count($parts) < 4) continue;
                
                $recordIp = $parts[1];
                $recordId = (int)$parts[2];
                $recordUser = $parts[4] ?? '';
                
                $isSameUser = ($userUniqueId && $recordUser === $userUniqueId) || (!$userUniqueId && $recordIp === $ip);
                
                if ($isSameUser) {
                    $userVotes++;
                    if ($recordId === $songId) {
                        $hasVotedThisSong = true;
                    }
                }
            }
            
            if ($hasVotedThisSong) {
                flock($fp, LOCK_UN);
                fclose($fp);
                throw new Exception('您已投过这首歌');
            }
            
            if ($userVotes >= 5) {
                flock($fp, LOCK_UN);
                fclose($fp);
                throw new Exception('票数已用完 (限5票)');
            }
            
            // 写入记录
            $time = date('Y-m-d H:i:s');
            $newLine = "$time | $ip | $songId | $songName | $userUniqueId\n";
            fwrite($fp, $newLine);
            
            flock($fp, LOCK_UN);
            fclose($fp);
            
            echo json_encode([
                'success' => true,
                'message' => '投票成功',
                'totalVotes' => $userVotes + 1,
                'remainingVotes' => 5 - ($userVotes + 1)
            ]);
            
        } else {
            fclose($fp);
            throw new Exception('系统繁忙，请重试');
        }
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
