<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

define('ANNOUNCEMENT_FILE', '/www/sites/rczx.asia/index/announcement.txt');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $content = file_exists(ANNOUNCEMENT_FILE) ? file_get_contents(ANNOUNCEMENT_FILE) : '';
        echo json_encode(['content' => $content]);
        exit;
    }

    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        if (empty($input)) throw new Exception('未接收到数据');

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON 解析失败：' . json_last_error_msg());
        }

        if (!isset($data['content'])) throw new Exception('缺少 content 字段');

        $content = $data['content'];

        // 检查目录是否可写
        $dir = dirname(ANNOUNCEMENT_FILE);
        if (!is_dir($dir)) {
            throw new Exception("目录不存在: $dir");
        }
        if (!is_writable($dir)) {
            throw new Exception("目录不可写: $dir");
        }

        // 如果文件不存在，尝试创建
        if (!file_exists(ANNOUNCEMENT_FILE)) {
            if (touch(ANNOUNCEMENT_FILE) === false) {
                throw new Exception("无法创建文件: " . ANNOUNCEMENT_FILE);
            }
        }

        // 写入文件
        $result = file_put_contents(ANNOUNCEMENT_FILE, $content, LOCK_EX);
        if ($result === false) {
            $error = error_get_last();
            $errMsg = $error ? $error['message'] : '未知写入错误';
            error_log('公告写入失败：' . $errMsg);
            throw new Exception('无法写入公告文件，请检查目录权限。详细错误：' . $errMsg);
        }

        echo json_encode(['success' => true, 'message' => '公告已保存']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}