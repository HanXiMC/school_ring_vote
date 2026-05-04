<?php
/**
 * 备份管理 - 提供备份文件管理和数据还原功能
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

// 获取操作类型
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 获取请求体
$input = json_decode(file_get_contents('php://input'), true);

// 配置文件路径
define('DATA_DIR', __DIR__);
define('MUSIC_DATA_FILE', DATA_DIR . '/music_data.json');
define('VOTE_RECORDS_FILE', DATA_DIR . '/vote_records.txt');
define('BACKUP_DIR', DATA_DIR);

// 验证管理员密码
function verifyPassword($password) {
    // 从环境变量或配置文件获取密码
    $adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin123'; // 默认密码，生产环境应使用更安全的密码
    
    if (empty($password)) {
        return false;
    }
    
    return password_verify($password, crypt($adminPassword, '$2y$10$')) || $password === $adminPassword;
}

// 获取备份文件列表
function listBackups() {
    global $method;
    
    if ($method !== 'GET') {
        return ['success' => false, 'message' => '仅支持 GET 请求'];
    }
    
    $files = [];
    
    // 扫描备份文件
    $backupFiles = glob(BACKUP_DIR . '/*.backup.*');
    
    if ($backupFiles) {
        foreach ($backupFiles as $file) {
            if (is_file($file)) {
                $filename = basename($file);
                $filemtime = filemtime($file);
                $files[] = [
                    'name' => $filename,
                    'date' => date('Y-m-d H:i:s', $filemtime),
                    'timestamp' => $filemtime,
                    'size' => filesize($file)
                ];
            }
        }
    }
    
    // 按时间排序（最新的在前）
    usort($files, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return ['success' => true, 'files' => $files];
}

// 删除所有备份文件
function deleteAllBackups($password) {
    global $method;
    
    if ($method !== 'POST') {
        return ['success' => false, 'message' => '仅支持 POST 请求'];
    }
    
    // 验证密码
    if (!verifyPassword($password)) {
        return ['success' => false, 'message' => '密码验证失败'];
    }
    
    $backupFiles = glob(BACKUP_DIR . '/*.backup.*');
    $deletedCount = 0;
    $errors = [];
    
    if ($backupFiles) {
        foreach ($backupFiles as $file) {
            if (is_file($file) && is_writable($file)) {
                if (unlink($file)) {
                    $deletedCount++;
                } else {
                    $errors[] = basename($file);
                }
            }
        }
    }
    
    if ($deletedCount > 0) {
        return [
            'success' => true, 
            'deleted_count' => $deletedCount,
            'errors' => $errors,
            'message' => "成功删除 {$deletedCount} 个备份文件"
        ];
    } else {
        return [
            'success' => false, 
            'message' => '没有备份文件可删除或删除失败',
            'errors' => $errors
        ];
    }
}

// 查找最新的备份文件
function findLatestBackup($prefix) {
    $backupFiles = glob(BACKUP_DIR . '/' . $prefix . '.backup.*');
    
    if (!$backupFiles || count($backupFiles) === 0) {
        return null;
    }
    
    // 按修改时间排序，返回最新的
    usort($backupFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    return $backupFiles[0];
}

// 从备份还原数据
function restoreFromBackup($password) {
    global $method;
    
    if ($method !== 'POST') {
        return ['success' => false, 'message' => '仅支持 POST 请求'];
    }
    
    // 验证密码
    if (!verifyPassword($password)) {
        return ['success' => false, 'message' => '密码验证失败'];
    }
    
    $restoredFiles = [];
    $errors = [];
    
    // 还原 music_data.json
    $musicBackup = findLatestBackup('music_data.json');
    if ($musicBackup && file_exists($musicBackup)) {
        $content = file_get_contents($musicBackup);
        
        // 验证 JSON 格式是否有效
        $testJson = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // 创建当前文件的备份（覆盖前先备份）
            if (file_exists(MUSIC_DATA_FILE)) {
                $timestamp = date('Y-m-d_H-i-s');
                $tempBackup = MUSIC_DATA_FILE . '.temp_backup.' . $timestamp;
                copy(MUSIC_DATA_FILE, $tempBackup);
            }
            
            if (file_put_contents(MUSIC_DATA_FILE, $content)) {
                $restoredFiles[] = 'music_data.json';
            } else {
                $errors[] = 'music_data.json: 写入失败';
            }
        } else {
            $errors[] = 'music_data.json: 备份文件格式无效';
        }
    }
    
    // 还原 vote_records.txt
    $voteBackup = findLatestBackup('vote_records.txt');
    if ($voteBackup && file_exists($voteBackup)) {
        $content = file_get_contents($voteBackup);
        
        // 创建当前文件的备份
        if (file_exists(VOTE_RECORDS_FILE)) {
            $timestamp = date('Y-m-d_H-i-s');
            $tempBackup = VOTE_RECORDS_FILE . '.temp_backup.' . $timestamp;
            copy(VOTE_RECORDS_FILE, $tempBackup);
        }
        
        if (file_put_contents(VOTE_RECORDS_FILE, $content)) {
            $restoredFiles[] = 'vote_records.txt';
        } else {
            $errors[] = 'vote_records.txt: 写入失败';
        }
    }
    
    if (count($restoredFiles) > 0) {
        return [
            'success' => true,
            'restored_files' => $restoredFiles,
            'errors' => $errors,
            'message' => '成功还原 ' . count($restoredFiles) . ' 个文件'
        ];
    } else {
        return [
            'success' => false,
            'message' => '没有可还原的备份或还原失败',
            'errors' => $errors
        ];
    }
}

// 路由处理
$result = [];

switch ($action) {
    case 'list':
        $result = listBackups();
        break;
        
    case 'delete_all':
        $password = isset($input['password']) ? $input['password'] : '';
        $result = deleteAllBackups($password);
        break;
        
    case 'restore':
        $password = isset($input['password']) ? $input['password'] : '';
        $result = restoreFromBackup($password);
        break;
        
    default:
        $result = [
            'success' => false,
            'message' => '未知操作',
            'available_actions' => ['list', 'delete_all', 'restore']
        ];
        break;
}

// 输出结果
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
