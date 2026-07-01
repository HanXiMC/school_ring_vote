<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
date_default_timezone_set('Asia/Shanghai');
// 处理预检请求
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// 默认头像函数
function getDefaultAvatar($type) {
    switch ($type) {
        case 'developer':
            return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiMwMDRkOTkiLz4KPHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4PSI4IiB5PSI4Ij4KPHBhdGggZD0iTTEyIDEyQzE0LjIwOTEgMTIgMTYgMTAuMjA5MSAxNiA4QzE2IDUuNzkwODYgMTQuMjA5MSA0IDEyIDRDOS43OTA4NiA0IDggNS43OTA4NiA4IDhDOCAxMC4yMDkxIDkuNzkwODYgMTIgMTIgMTJaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTIgMTRDOC42ODYyOSAxNCA2IDE2LjY4NjMgNiAyMEg2VjIwSDZIMThIMThWMjBDMTggMTYuNjg2MyAxNS4zMTM3IDE0IDEyIDE0WiIgZmlsbD0id2hpdGUiLz4KPC9zdmc+Cjwvc3ZnPgo=';
        case 'specialThanks':
            return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9Im9yYW5nZSIvPgo8c3ZnIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHg9IjgiIHk9IjgiPgo8cGF0aCBkPSJNMTIgMTJDMTQuMjA5MSAxMiAxNiAxMC4yMDkxIDE2IDhDMTYgNS43OTA4NiAxNC4yMDkxIDQgMTIgNEM5Ljc5OTA2IDQgOCA1Ljc5MDg2IDggOEM4IDEwLjIwOTEgOS43OTA4NiAxMiAxMiAxMloiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xMiAxNEM4LjY4NjI5IDE0IDYgMTYuNjg2MyA2IDIwSDZWMjBINkgxOEgxOFYyMEMxOCAxNi42ODYzIDE1LjMxMzcgMTQgMTIgMTRaIiBmaWlsbC0zPSJ3aGl0ZSIvPgo8L3N2Zz4KPC9zdmc+Cg==';
        default:
            return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiMyZWNjNzEiLz4KPHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4PSI4IiB5PSI4Ij4KPHBhdGggZD0iTTEyIDEyQzE0LjIwOTEgMTIgMTYgMTAuMjA5MSAxNiA4QzE2IDUuNzkwODYgMTQuMjA5MSA0IDEyIDRDOS43OTA4NiA0IDggNS43OTA4NiA4IDhDOCAxMC4yMDkxIDkuNzkwODYgMTIgMTIgMTJaIiBmaWxsPSJ3aGl0ZSIvPgo8cGF0aCBkPSJNMTIgMTRDOC42ODYyOSAxNCA2IDE2LjY4NjMgNiAyMEg2VjIwSDZIMThIMThWMjBDMTggMTYuNjg2MyAxNS4zMTM3IDE0IDEyIDE0WiIgZmlsbD0id2hpdGUiLz4KPC9zdmc+Cjwvc3ZnPgo=';
    }
}

// 配置文件路径
$profilesFilePath = __DIR__ . "/profiles.json";

if (file_exists($profilesFilePath)) {
    $profilesJson = file_get_contents($profilesFilePath);
    $profilesData = json_decode($profilesJson, true);

    if ($profilesData === null && json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Error decoding profiles.json: ' . json_last_error_msg()]);
        exit;
    }

    $processedData = [
        'developers' => [],
        'specialThanks' => []
    ];

    if (isset($profilesData['developers']) && is_array($profilesData['developers'])) {
        foreach ($profilesData['developers'] as $member) {
            $member['avatar'] = $member['avatar'] ?? getDefaultAvatar('developer');
            $member['category'] = 'developer';
            $processedData['developers'][] = $member;
        }
    }

    if (isset($profilesData['specialThanks']) && is_array($profilesData['specialThanks'])) {
        foreach ($profilesData['specialThanks'] as $member) {
            $member['avatar'] = $member['avatar'] ?? getDefaultAvatar('specialThanks');
            $member['category'] = 'specialThanks';
            $processedData['specialThanks'][] = $member;
        }
    }

    echo json_encode(['success' => true, 'data' => $processedData]);
} else {
    echo json_encode(['success' => false, 'message' => 'profiles.json not found.']);
}
?>
