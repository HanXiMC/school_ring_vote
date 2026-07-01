<?php
// 更新日志管理接口

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
date_default_timezone_set('Asia/Shanghai');

ini_set('display_errors', 0);
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 文件路径配置
define('BASE_PATH', dirname(__DIR__));
$changelogFile = BASE_PATH . '/submit/changelog.json';

// 验证管理员密码
function verifyAdmin($password) {
    $correctPasswordHash = 'f6769640e30ed7da6de559649453d701'; // xwbdszq的MD5值
    return md5($password) === $correctPasswordHash;
}

// 读取更新日志
function getChangelog() {
    global $changelogFile;
    
    if (!file_exists($changelogFile)) {
        return [];
    }
    
    $content = file_get_contents($changelogFile);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// 保存更新日志
function saveChangelog($data) {
    global $changelogFile;
    
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($changelogFile, $json, LOCK_EX) !== false;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // 获取更新日志列表（公开接口）
            $changelog = getChangelog();
            
            // 按日期倒序排列
            usort($changelog, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
            
            echo json_encode([
                'success' => true,
                'data' => $changelog
            ]);
            break;
            
        case 'POST':
            // 添加新更新日志（需要验证管理员密码）
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!isset($data['password']) || !verifyAdmin($data['password'])) {
                throw new Exception('管理员密码错误');
            }
            
            if (empty($data['version']) || empty($data['title']) || empty($data['content'])) {
                throw new Exception('版本号、标题和内容不能为空');
            }
            
            $changelog = getChangelog();
            
            $newItem = [
                'id' => uniqid(),
                'version' => trim($data['version']),
                'date' => $data['date'] ?: date('Y-m-d'),
                'title' => trim($data['title']),
                'content' => trim($data['content']),
                'type' => $data['type'] ?: 'update'
            ];
            
            // 添加到数组开头
            array_unshift($changelog, $newItem);
            
            if (!saveChangelog($changelog)) {
                throw new Exception('保存更新日志失败');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '更新日志添加成功',
                'data' => $newItem
            ]);
            break;
            
        case 'PUT':
            // 编辑更新日志
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!isset($data['password']) || !verifyAdmin($data['password'])) {
                throw new Exception('管理员密码错误');
            }
            
            if (empty($data['id'])) {
                throw new Exception('缺少更新日志ID');
            }
            
            $changelog = getChangelog();
            $found = false;
            
            foreach ($changelog as &$item) {
                if ($item['id'] === $data['id']) {
                    if (isset($data['version'])) $item['version'] = trim($data['version']);
                    if (isset($data['date'])) $item['date'] = trim($data['date']);
                    if (isset($data['title'])) $item['title'] = trim($data['title']);
                    if (isset($data['content'])) $item['content'] = trim($data['content']);
                    if (isset($data['type'])) $item['type'] = $data['type'];
                    $found = true;
                    break;
                }
            }
            unset($item);
            
            if (!$found) {
                throw new Exception('未找到指定的更新日志');
            }
            
            if (!saveChangelog($changelog)) {
                throw new Exception('保存更新日志失败');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '更新日志修改成功'
            ]);
            break;
            
        case 'DELETE':
            // 删除更新日志
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!isset($data['password']) || !verifyAdmin($data['password'])) {
                throw new Exception('管理员密码错误');
            }
            
            if (empty($data['id'])) {
                throw new Exception('缺少更新日志ID');
            }
            
            $changelog = getChangelog();
            $originalCount = count($changelog);
            $changelog = array_filter($changelog, function($item) use ($data) {
                return $item['id'] !== $data['id'];
            });
            
            if (count($changelog) === $originalCount) {
                throw new Exception('未找到指定的更新日志');
            }
            
            $changelog = array_values($changelog); // 重新索引
            
            if (!saveChangelog($changelog)) {
                throw new Exception('保存更新日志失败');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '更新日志删除成功'
            ]);
            break;
            
        default:
            throw new Exception('不支持的请求方法');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
