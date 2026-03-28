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
$audioDir = 'audio/';

// 初始化环境
if (!is_dir($audioDir)) {
    if (!mkdir($audioDir, 0755, true)) {
        error_log("无法创建audio目录");
    }
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
    // 使用 JSON_PRETTY_PRINT 保持文件可读性，JSON_UNESCAPED_UNICODE 保证中文不乱码
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($musicDataFile, $jsonData) !== false;
}

// 生成新ID
function getNextId($musicData) {
    if (empty($musicData)) return 1;
    $ids = array_column($musicData, 'id');
    return max($ids) + 1;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // 获取列表
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => readMusicData()]);
    } 
    
    // 上传音乐
    else if ($method === 'POST') {
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
                @unlink($targetPath); // 数据保存失败则回滚删除文件
                throw new Exception('数据保存失败');
            }
        } else {
            throw new Exception('文件保存失败，请检查目录权限');
        }
    } 
    
    // 修改标题
    else if ($method === 'PUT') {
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
    
    // 删除音乐
    else if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;

        if (!$id) throw new Exception('ID缺失');

        $musicData = readMusicData();
        $newData = [];
        $found = false;

        foreach ($musicData as $music) {
            if ($music['id'] == $id) {
                $found = true;
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
                echo json_encode(['success' => true, 'message' => '删除成功']);
            } else {
                throw new Exception('保存数据失败');
            }
        } else {
            throw new Exception('未找到指定音乐');
        }
    } 
    else {
        throw new Exception('不支持的请求方法');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
