<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Shanghai');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$settingsFile = __DIR__ . '/settings.json';
$adminPassword = 'xwbdszq';

function compileMessage($config) {
    $title = htmlspecialchars($config['title'] ?? '');
    $subtitle = htmlspecialchars($config['subtitle'] ?? '');
    $icon = htmlspecialchars($config['icon'] ?? 'fa-clock');
    $color = htmlspecialchars($config['color'] ?? '#004d99');
    $animate = !empty($config['animate']) ? 'animation:pulse 2s infinite;' : '';
    
    $html = "<div style='text-align:center;'>";
    if (!empty($icon)) {
        $html .= "<i class='fas {$icon}' style='font-size:80px;color:{$color};margin-bottom:20px;{$animate}'></i><br>";
    }
    $html .= "<p style='font-size:20px;color:{$color};font-weight:bold;margin-bottom:5px;'>{$title}</p>";
    if (!empty($subtitle)) {
        $html .= "<p style='font-size:14px;color:#64748b;'>{$subtitle}</p>";
    }
    $html .= "</div>";
    return $html;
}

function checkSchedules($mode, $schedules) {
    if ($mode === 'always_on') return true;
    if ($mode === 'always_off') return false;
    if ($mode === 'auto' && is_array($schedules)) {
        $curW = (int)date('N'); // 1 (周一) 到 7 (周日)
        $curD = (int)date('j'); // 1 到 31 号
        $curHi = date('H:i');   // 当前时分：如 "08:30"
        
        foreach ($schedules as $s) {
            $period = $s['period'] ?? 'daily';
            $start = $s['start_time'] ?? '00:00';
            $end = $s['end_time'] ?? '23:59';
            
            if ($curHi >= $start && $curHi <= $end) {
                if ($period === 'daily') return true;
                if ($period === 'weekly' && isset($s['days']) && in_array($curW, array_map('intval', $s['days']))) return true;
                if ($period === 'monthly' && isset($s['days']) && in_array($curD, array_map('intval', $s['days']))) return true;
            }
        }
    }
    return false;
}

/* 读取设置 */
function readSettings() {
    global $settingsFile;
    $defaults = [
        'vote_mode' => 'always_off',
        'submit_mode' => 'always_off',
        'vote_closed_config' => [
            'title' => '还未开启投票，请耐心等待',
            'subtitle' => '',
            'icon' => 'fa-clock',
            'color' => '#004d99',
            'animate' => true
        ],
        'submit_closed_config' => [
            'title' => '投稿已结束，请移步至投票页',
            'subtitle' => '',
            'icon' => 'fa-clock',
            'color' => '#004d99',
            'animate' => true
        ],
        'vote_schedules' => [],
        'submit_schedules' => []
    ];
    
    if (file_exists($settingsFile)) {
        $content = file_get_contents($settingsFile);
        $data = json_decode($content, true) ?: [];
        $data = array_merge($defaults, $data);
    } else {
        $data = $defaults;
    }
    
    // 动态计算运行时真/假状态值与 HTML 提示语，向前向下完美兼容
    $data['vote_enabled'] = checkSchedules($data['vote_mode'], $data['vote_schedules']);
    $data['submit_enabled'] = checkSchedules($data['submit_mode'], $data['submit_schedules']);
    $data['vote_closed_message'] = compileMessage($data['vote_closed_config']);
    $data['submit_closed_message'] = compileMessage($data['submit_closed_config']);
    return $data;
}

/* 保存设置 */
function saveSettings($data) {
    global $settingsFile;
    unset($data['vote_enabled'], $data['submit_enabled']);
    $data['vote_closed_message'] = compileMessage($data['vote_closed_config'] ?? []);
    $data['submit_closed_message'] = compileMessage($data['submit_closed_config'] ?? []);
    $data['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($settingsFile, $json) !== false;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
if (empty($action) && $method === 'POST') {
    $requestBody = json_decode(file_get_contents('php://input'), true);
    $action = $requestBody['action'] ?? '';
}

// 公开数据读取接口
if ($method === 'GET' && $action === 'get') {
    echo json_encode(['success' => true, 'data' => readSettings()]);
    exit;
}

// 管理员身份鉴权
if (!isset($requestBody)) {
    $requestBody = json_decode(file_get_contents('php://input'), true);
}
if (($requestBody['password'] ?? '') !== $adminPassword) {
    echo json_encode(['success' => false, 'message' => '密码验证失败']);
    exit;
}

// 统一更新全部控制项
if ($method === 'POST' && $action === 'update_all') {
    $settings = readSettings();
    $allowedFields = ['vote_mode', 'submit_mode', 'vote_closed_config', 'submit_closed_config', 'vote_schedules', 'submit_schedules'];
    
    foreach ($allowedFields as $field) {
        if (isset($requestBody[$field])) {
            $settings[$field] = $requestBody[$field];
        }
    }
    
    if (saveSettings($settings)) {
        echo json_encode(['success' => true, 'message' => '控制系统配置已全部成功同步']);
    } else {
        echo json_encode(['success' => false, 'message' => '文件写入失败，请检查数据完整性']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => '未知的高级操作指令']);