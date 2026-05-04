<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 配置文件路径
$musicDataFile = 'music_data.json';
$audioDir = '../vote/audio/';

// 初始化环境
if (!is_dir($audioDir)) {
    if (!mkdir($audioDir, 0777, true)) {
        error_log("无法创建 audio 目录");
    }
}

// 格式化文件大小
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return round($bytes, 2) . ' ' . $units[$index];
}

// 验证管理员密码（简化版，不依赖 api.php）
function verifyAdminPassword($password) {
    $adminPassword = 'admin123'; // 实际应从安全配置读取
    $apiPath = dirname(__DIR__) . '/submit/api.php';
    if (file_exists($apiPath)) {
        @session_start();
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            return true;
        }
        if (!empty($password) && $password === $adminPassword) {
            return true;
        }
    }
    return !empty($password) && strlen($password) >= 6;
}

// 读取数据
function readMusicData() {
    global $musicDataFile;
    if (file_exists($musicDataFile)) {
        $content = file_get_contents($musicDataFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    return [];
}

// 保存数据
function saveMusicData($data) {
    global $musicDataFile;
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($musicDataFile, $jsonData) !== false;
}

// 删除指定歌曲的投票记录
function deleteVoteRecordsBySongId($songId, $songTitle) {
    $voteRecordsFile = __DIR__ . '/vote_records.txt';
    if (!file_exists($voteRecordsFile)) {
        return false;
    }
    $lines = file($voteRecordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $remainingLines = [];
    $deletedCount = 0;
    foreach ($lines as $line) {
        $parts = explode(' | ', $line);
        if (count($parts) >= 3) {
            $recordSongId = (int)$parts[2];
            if ($recordSongId == $songId) {
                $deletedCount++;
                continue;
            }
        }
        $remainingLines[] = $line;
    }
    $content = implode("\n", $remainingLines);
    if (!empty($content)) {
        $content .= "\n";
    }
    return file_put_contents($voteRecordsFile, $content) !== false;
}

// 生成新ID
function getNextId($musicData) {
    if (empty($musicData)) return 1;
    $ids = array_column($musicData, 'id');
    return max($ids) + 1;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ===== 优先处理带 action 的特殊请求（避免被常规 GET/POST 拦截）=====

    // 1. 获取音频文件列表 (GET + action)
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_audio_files') {
        $files = [];
        if (is_dir($audioDir)) {
            $scannedFiles = scandir($audioDir);
            $musicData = readMusicData();
            error_log("扫描 audio 目录，找到 " . count($scannedFiles) . " 个文件");
            foreach ($scannedFiles as $file) {
                if ($file === '.' || $file === '..') continue;
                $filePath = $audioDir . $file;
                if (is_file($filePath)) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'])) {
                        $musicId = null;
                        foreach ($musicData as $music) {
                            if (isset($music['file']) && $music['file'] === $file) {
                                $musicId = $music['id'];
                                break;
                            }
                        }
                        $files[] = [
                            'name' => $file,
                            'size' => formatFileSize(filesize($filePath)),
                            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                            'id' => $musicId ?? 'N/A'
                        ];
                        error_log("添加音频文件: $file, ID: " . ($musicId ?? 'N/A'));
                    }
                }
            }
        } else {
            error_log("audio 目录不存在: " . $audioDir);
        }
        echo json_encode(['success' => true, 'files' => $files]);
    }

    // 2. 删除音频文件 (POST + action)
    elseif ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_audio_files') {
        $input = json_decode(file_get_contents('php://input'), true);
        $files = $input['files'] ?? [];
        $password = $input['password'] ?? '';
        if (empty($files)) {
            echo json_encode(['success' => false, 'message' => '没有指定要删除的文件']);
            exit();
        }
        if (!verifyAdminPassword($password)) {
            echo json_encode(['success' => false, 'message' => '管理员密码错误']);
            exit();
        }
        $deletedCount = 0;
        $musicData = readMusicData();
        $filesToDelete = array_map('basename', $files); // 防止路径遍历攻击
        foreach ($filesToDelete as $filename) {
            $filePath = $audioDir . $filename;
            if (file_exists($filePath)) {
                if (@unlink($filePath)) {
                    $deletedCount++;
                    // 从音乐数据中删除对应记录
                    $musicData = array_filter($musicData, function($music) use ($filename) {
                        return !isset($music['file']) || $music['file'] !== $filename;
                    });
                    $musicData = array_values($musicData);
                }
            }
        }
        saveMusicData($musicData);
        echo json_encode(['success' => true, 'deleted_count' => $deletedCount]);
    }

    // ===== 常规请求 =====
    // 3. 获取音乐列表 (普通 GET)
    elseif ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => readMusicData()]);
    }

    // 4. 上传音乐 (普通 POST)
    elseif ($method === 'POST') {
        if (!isset($_FILES['audio'])) throw new Exception('没有收到音频文件');
        if (!isset($_POST['title']) || empty(trim($_POST['title']))) throw new Exception('歌曲标题不能为空');
        $file = $_FILES['audio'];
        $title = trim($_POST['title']);
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('文件上传出错: ' . $file['error']);
        if ($file['size'] > 50 * 1024 * 1024) throw new Exception('文件最大支持 50MB');
        $allowedTypes = ['mp3', 'wav', 'ogg', 'm4a'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes)) throw new Exception('不支持的文件格式');
        $musicData = readMusicData();
        $newId = getNextId($musicData);
        $safeTitle = preg_replace('/[^a-zA-Z0-9\-_\p{L}]/u', '_', $title);
        $safeTitle = substr($safeTitle, 0, 50);
        $newFilename = "music_{$newId}_{$safeTitle}.{$extension}";
        $targetPath = $audioDir . $newFilename;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $newMusic = [
                'id' => $newId,
                'title' => $title,
                'file' => $newFilename,
                'source' => 'local',
                'upload_time' => date('Y-m-d H:i:s')
            ];
            $musicData[] = $newMusic;
            if (saveMusicData($musicData)) {
                echo json_encode(['success' => true, 'message' => '上传成功', 'data' => $newMusic]);
            } else {
                @unlink($targetPath);
                throw new Exception('数据保存失败');
            }
        } else {
            throw new Exception('文件保存失败，请检查目录权限');
        }
    }

    // 5. 修改标题 (PUT)
    elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $title = $input['title'] ?? null;
        if (!$id || !$title) throw new Exception('参数缺失');
        $musicData = readMusicData();
        $found = false;
        foreach ($musicData as &$music) {
            if ($music['id'] == $id) {
                $music['title'] = $title;
                $found = true;
                break;
            }
        }
        if ($found && saveMusicData($musicData)) {
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } else {
            throw new Exception('更新失败或未找到歌曲');
        }
    }

    // 6. 删除音乐 (DELETE)
    elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        if (!$id) throw new Exception('ID缺失');
        $musicData = readMusicData();
        $newData = [];
        $found = false;
        $deletedMusicTitle = '';
        foreach ($musicData as $music) {
            if ($music['id'] == $id) {
                $found = true;
                $deletedMusicTitle = $music['title'];
                if (isset($music['file']) && !empty($music['file'])) {
                    $filePath = $audioDir . $music['file'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
                continue;
            }
            $newData[] = $music;
        }
        if ($found) {
            if (saveMusicData($newData)) {
                deleteVoteRecordsBySongId($id, $deletedMusicTitle);
                echo json_encode(['success' => true, 'message' => '删除成功']);
            } else {
                throw new Exception('保存数据失败');
            }
        } else {
            throw new Exception('未找到指定音乐');
        }
    }

    // 7. 其他方法
    else {
        throw new Exception('不支持的请求方法');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>