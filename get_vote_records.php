<?php
// 设置响应头
header("Content-Type: application/json");
header("Cache-Control: no-cache, must-revalidate");
header("Access-Control-Allow-Origin: *");

// 投票记录文件路径
$recordsFile = __DIR__ . '/vote_records.txt';

// 获取文件修改时间
$lastModified = file_exists($recordsFile) ? filemtime($recordsFile) : 0;

// 检查客户端是否发送了If-Modified-Since头
$ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? 
                  strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

// 检查客户端是否发送了If-None-Match头（ETag）
$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';
$etag = '"' . md5_file($recordsFile) . '"';

// 如果文件未修改，返回304状态码
if (($ifModifiedSince && $lastModified <= $ifModifiedSince) || 
    ($ifNoneMatch && $ifNoneMatch == $etag)) {
    header("HTTP/1.1 304 Not Modified");
    exit;
}

// 设置Last-Modified和ETag头
header("Last-Modified: " . gmdate("D, d M Y H:i:s", $lastModified) . " GMT");
header("ETag: $etag");

// 读取文件内容
if (file_exists($recordsFile)) {
    $content = file_get_contents($recordsFile);
    $lines = explode("\n", $content);
    $records = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode(' | ', $line);
        if (count($parts) >= 4) {
            $records[] = [
                'time' => $parts[0],
                'ip' => $parts[1],
                'songId' => (int)$parts[2],
                'songName' => $parts[3]
            ];
        }
    }
    
    // 返回JSON格式的数据
    echo json_encode([
        'success' => true,
        'lastModified' => $lastModified,
        'records' => $records
    ]);
} else {
    // 文件不存在
    echo json_encode([
        'success' => false,
        'message' => 'Records file not found'
    ]);
}
?>
