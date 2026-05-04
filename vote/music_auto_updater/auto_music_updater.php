<?php
ignore_user_abort(true);
set_time_limit(0);

// 配置文件
define('MUSIC_DATA_FILE', '../music_data.json');  // 上级目录的music_data.json
define('LOG_FILE', 'music_update.log');
define('STATUS_FILE', 'update_status.json');
define('UPDATE_INTERVAL', 1000); // 1小时 = 3600
define('BACKUP_DIR', 'backups');

/**
 * 写入日志
 */
function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * 更新状态信息
 */
function updateStatus($status, $lastUpdate = null) {
    $statusData = [
        'status' => $status,
        'last_update' => $lastUpdate ?: date('Y-m-d H:i:s'),
        'next_update' => date('Y-m-d H:i:s', time() + UPDATE_INTERVAL)
    ];
    file_put_contents(STATUS_FILE, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 获取网易云直链
 */
function getDualQualityUrls($musicId) {
    $urls = ['standard' => null, 'high' => null];
    
    $apiUrls = [
        'standard' => "https://api.byfuns.top/1/?id={$musicId}&level=standard",
        'high' => "https://api.byfuns.top/1/?id={$musicId}&level=lossless"
    ];
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'method' => 'GET',
            'header' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Referer: https://music.163.com/',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8'
            ]
        ]
    ]);
    
    // 获取每种音质的URL
    foreach ($apiUrls as $quality => $apiUrl) {
        try {
            $response = @file_get_contents($apiUrl, false, $context);
            
            if ($response !== false) {
                $response = trim($response);
                if (filter_var($response, FILTER_VALIDATE_URL) && !empty($response)) {
                    $urls[$quality] = $response;
                }
            }
            

            usleep(500000); // 0.5秒
            
        } catch (Exception $e) {
            writeLog("获取{$quality}音质失败 (ID: {$musicId}): " . $e->getMessage());
            continue;
        }
    }
    
    return $urls;
}

/**
 * 获取单个音质
 */
function getNeteaseMusicUrl($musicId) {
    $dualUrls = getDualQualityUrls($musicId);
    
    // 优先返回无损音质，失败时返回标准音质
    return $dualUrls['high'] ?: $dualUrls['standard'];
}

/**
 * 创建备份
 */
function createBackup() {
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }
    
    $backupFile = BACKUP_DIR . '/music_data_' . date('Y-m-d_H-i-s') . '.json';
    
    if (file_exists(MUSIC_DATA_FILE)) {
        copy(MUSIC_DATA_FILE, $backupFile);
        
        // 只保留最近10个备份
        $backups = glob(BACKUP_DIR . '/music_data_*.json');
        if (count($backups) > 10) {
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            for ($i = 0; $i < count($backups) - 10; $i++) {
                unlink($backups[$i]);
            }
        }
        
        return $backupFile;
    }
    
    return false;
}

/**
 * 更新音乐URL
 */
function updateMusicUrls() {
    writeLog("开始更新音乐URL...");
    
    if (!file_exists(MUSIC_DATA_FILE)) {
        writeLog("错误：music_data.json 文件不存在");
        updateStatus('error', null);
        return false;
    }
    
    // 创建备份
    $backupFile = createBackup();
    if ($backupFile) {
        writeLog("备份创建成功：{$backupFile}");
    }
    
    // 读取音乐数据
    $musicData = json_decode(file_get_contents(MUSIC_DATA_FILE), true);
    
    if (!$musicData) {
        writeLog("错误：无法解析music_data.json");
        updateStatus('error', null);
        return false;
    }
    
    $updateCount = 0;
    $errorCount = 0;
    
    // 遍历所有音乐，更新网易云音乐的URL
    foreach ($musicData as &$song) {
        if (isset($song['source']) && $song['source'] === 'netease' && isset($song['source_id'])) {
            // 获取双音质链接
            $dualUrls = getDualQualityUrls($song['source_id']);
            $updated = false;
            
            // 更新标准音质
            if ($dualUrls['standard']) {
                $song['url_standard'] = $dualUrls['standard'];
                $song['quality_standard'] = 'standard';
                $updated = true;
            }
            
            // 更新无损音质
            if ($dualUrls['high']) {
                $song['url_high'] = $dualUrls['high'];
                $song['quality_high'] = 'high';
                $updated = true;
            }
            
            // 设置默认url字段
            if ($dualUrls['high']) {
                $song['url'] = $dualUrls['high'];
                $song['quality'] = 'high';
            } else if ($dualUrls['standard']) {
                $song['url'] = $dualUrls['standard'];
                $song['quality'] = 'standard';
            }
            
            if ($updated) {
                $updateCount++;
                $qualityInfo = [];
                if ($dualUrls['standard']) $qualityInfo[] = '标准';
                if ($dualUrls['high']) $qualityInfo[] = '无损';
                writeLog("更新成功：{$song['title']} (ID: {$song['source_id']}) - " . implode('、', $qualityInfo) . "音质");
            } else {
                $errorCount++;
                writeLog("更新失败：{$song['title']} (ID: {$song['source_id']}) - 所有音质获取失败");
            }
            
            // 避免请求过于频繁
            usleep(500000); // 0.5秒延迟
        }
    }
    
    // 保存更新后的数据
    if (file_put_contents(MUSIC_DATA_FILE, json_encode($musicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $message = "更新完成：成功更新 {$updateCount} 首歌曲";
        if ($errorCount > 0) {
            $message .= "，{$errorCount} 首歌曲更新失败";
        }
        writeLog($message);
        updateStatus('success', date('Y-m-d H:i:s'));
        return true;
    } else {
        writeLog("错误：无法保存更新后的数据到 music_data.json");
        updateStatus('error', null);
        return false;
    }
}

/**
 * 持续运行并定时更新
 */
function startAutoUpdater() {
    writeLog("音乐URL自动更新服务启动");
    updateStatus('running', null);
    
    while (true) {
        updateMusicUrls();
        
        writeLog("等待下次更新... (" . UPDATE_INTERVAL . "秒后)");
        
        // 等待指定的时间间隔
        sleep(UPDATE_INTERVAL);
    }
}

// 检查运行模式
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'start':
            // 启动自动更新服务
            header('Content-Type: text/html; charset=utf-8');
            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>启动更新服务</title></head><body>";
            echo "<h3>正在启动音乐URL自动更新服务...</h3>";
            echo "<p>服务已在后台启动，将每小时自动更新音乐链接。</p>";
            echo "<p><a href='music_status.php'>查看更新状态</a></p>";
            echo "</body></html>";
            
            // 输出缓冲区并关闭连接，让服务在后台运行
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
            
            // 关闭用户连接，但脚本继续运行
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // 开始自动更新循环
            startAutoUpdater();
            break;
            
        case 'update_now':
            // 立即执行一次更新
            header('Content-Type: application/json; charset=utf-8');
            $result = updateMusicUrls();
            echo json_encode([
                'success' => $result,
                'message' => $result ? '更新成功' : '更新失败，请查看日志',
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => '无效的操作'], JSON_UNESCAPED_UNICODE);
    }
} else {
    // 显示服务管理页面
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>音乐URL更新服务管理</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        .btn-warning { background-color: #ffc107; color: black; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .status-running { background-color: #d4edda; border: 1px solid #c3e6cb; }
        .status-error { background-color: #f8d7da; border: 1px solid #f5c6cb; }
        .status-success { background-color: #d1ecf1; border: 1px solid #bee5eb; }
        .path-info { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 15px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🎵 音乐URL自动更新服务</h2>
        <p>此服务会每小时自动更新音乐链接，确保播放链接始终有效。</p>
        
        <div class="path-info">
            <strong>📁 文件路径说明：</strong><br>
            • 此服务将更新上级目录的 <code>music_data.json</code> 文件<br>
            • 日志和备份文件保存在当前目录下<br>
            • 请确保有足够的文件读写权限
        </div>
        
        <div class="actions">
            <a href="?action=start" class="btn btn-primary">🚀 启动自动更新服务</a>
            <button onclick="updateNow()" class="btn btn-success">🔄 立即更新一次</button>
            <a href="music_status.php" class="btn btn-warning">📊 查看状态和日志</a>
        </div>
        
        <div id="result"></div>
        
        <div class="info">
            <h3>📋 服务说明</h3>
            <ul>
                <li><strong>自动更新：</strong>每小时自动获取新的音乐播放链接</li>
                <li><strong>后台运行：</strong>服务在后台静默运行，不影响前端使用</li>
                <li><strong>数据备份：</strong>每次更新前自动备份数据</li>
                <li><strong>错误处理：</strong>更新失败时会记录日志，不影响其他歌曲</li>
                <li><strong>兼容性：</strong>完全兼容现有的投票和播放功能</li>
            </ul>
            
            <h3>⚠️ 注意事项</h3>
            <ul>
                <li>首次启动服务后，会在后台持续运行</li>
                <li>如需停止服务，请重启服务器或联系管理员</li>
                <li>建议定期检查更新状态和日志</li>
                <li>确保 <code>../music_data.json</code> 文件存在且可写</li>
            </ul>
        </div>
    </div>
    
    <script>
    function updateNow() {
        const resultDiv = document.getElementById('result');
        resultDiv.innerHTML = '<div class="status">⏳ 正在更新...</div>';
        
        fetch('?action=update_now')
            .then(response => response.json())
            .then(data => {
                const statusClass = data.success ? 'status-success' : 'status-error';
                const icon = data.success ? '✅' : '❌';
                resultDiv.innerHTML = `<div class="status ${statusClass}">${icon} ${data.message} (${data.timestamp})</div>`;
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="status status-error">❌ 更新请求失败</div>';
            });
    }
    </script>
</body>
</html>
<?php
}
?>