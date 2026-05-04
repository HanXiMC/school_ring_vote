<?php
/**
 * 功能开关管理 API
 * 处理投票和投稿功能的开启/关闭设置
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$settingsFile = __DIR__ . '/settings.json';

// ================= 管理员密码 =================
$adminPassword = 'xwbdszq';

/**
 * 读取设置
 */
function readSettings() {
    global $settingsFile;
    if (!file_exists($settingsFile)) {
        return [
            'vote_enabled' => true,
            'submit_enabled' => true,
            'vote_closed_message' => '<div style="text-align:center"><i class="fas fa-clock" style="font-size:80px;color:#004d99;margin-bottom:20px;animation:pulse 2s infinite;"></i><br><p style="font-size:20px;color:#004d99;font-weight:bold;">暂未开启投票，请耐心等待</p></div>',
            'submit_closed_message' => '<div style="text-align:center"><i class="fas fa-clock" style="font-size:80px;color:#004d99;margin-bottom:20px;animation:pulse 2s infinite;"></i><br><p style="font-size:20px;color:#004d99;font-weight:bold;">暂未开启投稿，请耐心等待</p></div>',
            'vote_closed_icon' => '',
            'submit_closed_icon' => '',
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    $content = file_get_contents($settingsFile);
    return json_decode($content, true) ?: [];
}

/**
 * 保存设置
 */
function saveSettings($data) {
    global $settingsFile;
    $data['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($settingsFile, $json) !== false;
}

/**
 * 验证管理员密码
 */
function verifyPassword($password) {
    global $adminPassword;
    return $password === $adminPassword;
}

// 路由处理
$method = $_SERVER['REQUEST_METHOD'];
// 同时支持 GET 参数和 POST body 中的 action
$action = $_GET['action'] ?? '';
if (empty($action) && $method === 'POST') {
    $requestBody = json_decode(file_get_contents('php://input'), true);
    $action = $requestBody['action'] ?? '';
}

// 获取设置（公开接口）
if ($method === 'GET' && $action === 'get') {
    $settings = readSettings();
    echo json_encode([
        'success' => true,
        'data' => $settings
    ]);
    exit;
}

// 以下操作需要管理员权限
if (!isset($requestBody)) {
    $requestBody = json_decode(file_get_contents('php://input'), true);
}
$password = $requestBody['password'] ?? '';

if (!verifyPassword($password)) {
    echo json_encode([
        'success' => false,
        'message' => '密码验证失败'
    ]);
    exit;
}

// 更新功能开关
if ($method === 'POST' && $action === 'update_switches') {
    $settings = readSettings();
    
    if (isset($requestBody['vote_enabled'])) {
        $settings['vote_enabled'] = (bool)$requestBody['vote_enabled'];
    }
    if (isset($requestBody['submit_enabled'])) {
        $settings['submit_enabled'] = (bool)$requestBody['submit_enabled'];
    }
    
    if (saveSettings($settings)) {
        echo json_encode([
            'success' => true,
            'message' => '功能开关已更新'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '保存失败'
        ]);
    }
    exit;
}

// 更新关闭提示消息
if ($method === 'POST' && $action === 'update_messages') {
    $settings = readSettings();
    
    if (isset($requestBody['vote_closed_message'])) {
        $settings['vote_closed_message'] = $requestBody['vote_closed_message'];
    }
    if (isset($requestBody['submit_closed_message'])) {
        $settings['submit_closed_message'] = $requestBody['submit_closed_message'];
    }
    if (isset($requestBody['vote_closed_icon'])) {
        $settings['vote_closed_icon'] = $requestBody['vote_closed_icon'];
    }
    if (isset($requestBody['submit_closed_icon'])) {
        $settings['submit_closed_icon'] = $requestBody['submit_closed_icon'];
    }
    
    if (saveSettings($settings)) {
        echo json_encode([
            'success' => true,
            'message' => '提示消息已更新'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '保存失败'
        ]);
    }
    exit;
}

// 更新全部设置
if ($method === 'POST' && $action === 'update_all') {
    $settings = readSettings();
    
    $allowedFields = ['vote_enabled', 'submit_enabled', 'vote_closed_message', 'submit_closed_message', 'vote_closed_icon', 'submit_closed_icon'];
    
    foreach ($allowedFields as $field) {
        if (isset($requestBody[$field])) {
            $settings[$field] = $requestBody[$field];
        }
    }
    
    if (saveSettings($settings)) {
        echo json_encode([
            'success' => true,
            'message' => '设置已全部更新'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '保存失败'
        ]);
    }
    exit;
}

// 默认响应
echo json_encode([
    'success' => false,
    'message' => '未知操作'
]);
