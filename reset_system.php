<?php

//设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

ini_set('display_errors', 0);
error_reporting(0);

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 文件路径配置
define('BASE_PATH', __DIR__); // 当前脚本所在目录
$musicDataFile = BASE_PATH . '/music_data.json';
$voteRecordsFile = BASE_PATH . '/vote_records.txt';
$audioDir = BASE_PATH . '/audio/';

// 辅助函数
function verifyAdmin($password) {
    $correctPasswordHash = 'f6769640e30ed7da6de559649453d701'; // xwbdszq的MD5值
    return md5($password) === $correctPasswordHash;
}

/**
 * 备份文件
 * @param string $filePath 原文件路径
 * @return bool|string 成功返回备份文件路径，失败返回 false
 */
function backupFile($filePath) {
    if (!file_exists($filePath)) {
        return true; // 文件不存在，无需备份
    }
    $backupPath = $filePath . '.backup.' . date('Y-m-d_H-i-s');
    if (copy($filePath, $backupPath)) {
        return $backupPath;
    }
    return false;
}

/**
 * 清空音乐数据
 */
function resetMusicData() {
    global $musicDataFile;
    
    // 备份原文件
    $backup = backupFile($musicDataFile);
    if ($backup === false) {
        throw new Exception('备份音乐数据文件失败，请检查文件权限');
    }
    
    // 创建空的音乐数据文件
    $emptyData = [];
    $json = json_encode($emptyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($musicDataFile, $json, LOCK_EX) === false) {
        throw new Exception('重置音乐数据失败，无法写入文件');
    }
    
    return true;
}

/**
 * 清空投票记录
 */
function resetVoteRecords() {
    global $voteRecordsFile;
    
    // 备份原文件
    $backup = backupFile($voteRecordsFile);
    if ($backup === false) {
        throw new Exception('备份投票记录文件失败，请检查文件权限');
    }
    
    // 清空投票记录文件
    if (file_put_contents($voteRecordsFile, '', LOCK_EX) === false) {
        throw new Exception('重置投票记录失败，无法写入文件');
    }
    
    return true;
}

/**
 * 清空音频文件
 */
function resetAudioFiles() {
    global $audioDir;
    
    if (!is_dir($audioDir)) {
        // 目录不存在，尝试创建
        if (!mkdir($audioDir, 0755, true)) {
            throw new Exception('音频目录不存在且无法创建，请检查权限');
        }
        return 0; // 没有文件可移动
    }
    
    // 创建备份目录
    $backupDir = $audioDir . 'backup_' . date('Y-m-d_H-i-s') . '/';
    if (!mkdir($backupDir, 0755, true)) {
        throw new Exception('创建音频备份目录失败，请检查音频目录权限');
    }
    
    $files = glob($audioDir . '*');
    $movedCount = 0;
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $filename = basename($file);
            if (rename($file, $backupDir . $filename)) {
                $movedCount++;
            } else {
                error_log("移动文件失败: $file -> $backupDir$filename");
            }
        }
    }
    
    return $movedCount;
}

// 处理请求
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'POST':
            // 执行重置
            $input = file_get_contents('php://input');
            if (empty($input)) {
                throw new Exception('未接收到任何数据');
            }
            
            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('无效的 JSON 格式：' . json_last_error_msg());
            }
            
            if (!isset($data['action']) || $data['action'] !== 'reset') {
                throw new Exception('无效的操作');
            }
            
            if (!isset($data['password'])) {
                throw new Exception('缺少管理员密码');
            }
            
            // 验证管理员权限
            if (!verifyAdmin($data['password'])) {
                throw new Exception('管理员密码错误');
            }
            
            $resetOptions = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
            if (empty($resetOptions)) {
                throw new Exception('请选择要重置的内容');
            }
            
            $results = [];
            
            // 重置音乐数据
            if (in_array('music', $resetOptions)) {
                resetMusicData();
                $results[] = '音乐数据已重置';
            }
            
            // 重置投票记录
            if (in_array('votes', $resetOptions)) {
                resetVoteRecords();
                $results[] = '投票记录已重置';
            }
            
            // 重置音频文件
            if (in_array('audio', $resetOptions)) {
                $movedCount = resetAudioFiles();
                $results[] = "音频文件已备份并清理（共 {$movedCount} 个文件）";
            }
            
            echo json_encode([
                'success' => true,
                'message' => '系统重置成功',
                'details' => $results,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'GET':
            // 获取系统状态信息
            $status = [];
            
            // 音乐数据状态
            if (file_exists($musicDataFile)) {
                $musicData = json_decode(file_get_contents($musicDataFile), true) ?: [];
                $status['music'] = [
                    'count' => count($musicData),
                    'lastModified' => date('Y-m-d H:i:s', filemtime($musicDataFile))
                ];
            } else {
                $status['music'] = ['count' => 0, 'lastModified' => null];
            }
            
            // 投票记录状态
            if (file_exists($voteRecordsFile)) {
                $voteLines = file($voteRecordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $status['votes'] = [
                    'count' => count($voteLines),
                    'lastModified' => date('Y-m-d H:i:s', filemtime($voteRecordsFile))
                ];
            } else {
                $status['votes'] = ['count' => 0, 'lastModified' => null];
            }
            
            // 音频文件状态
            if (is_dir($audioDir)) {
                $audioFiles = [];
                foreach (['mp3', 'wav', 'ogg', 'm4a'] as $ext) {
                    $files = glob($audioDir . '*.' . $ext);
                    if (is_array($files)) {
                        $audioFiles = array_merge($audioFiles, $files);
                    }
                }
                $status['audio'] = [
                    'count' => count($audioFiles),
                    'totalSize' => count($audioFiles) > 0 ? array_sum(array_map('filesize', $audioFiles)) : 0
                ];
            } else {
                $status['audio'] = ['count' => 0, 'totalSize' => 0];
            }
            
            echo json_encode([
                'success' => true,
                'status' => $status
            ]);
            break;
            
        default:
            throw new Exception('不支持的请求方法');
    }
    
} catch (Exception $e) {
    // 记录错误到日志
    error_log('Reset System Error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}