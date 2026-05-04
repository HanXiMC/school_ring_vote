<?php
/**
 * 音乐URL更新状态查看页面
 */

define('STATUS_FILE', 'update_status.json');
define('LOG_FILE', 'music_update.log');

// 读取状态信息
$status = [];
if (file_exists(STATUS_FILE)) {
    $status = json_decode(file_get_contents(STATUS_FILE), true) ?: [];
}

// 读取日志（最后100行）
$logs = [];
if (file_exists(LOG_FILE)) {
    $allLogs = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logs = array_slice($allLogs, -100); // 获取最后100行
    $logs = array_reverse($logs); // 最新的在前面
}

// 处理AJAX请求
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $status,
        'logs' => array_slice($logs, 0, 20) // 只返回最近20条
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取状态显示信息
function getStatusDisplay($status) {
    if (empty($status)) {
        return ['class' => 'status-unknown', 'icon' => '❓', 'text' => '未知'];
    }
    
    switch ($status['status']) {
        case 'running':
            return ['class' => 'status-running', 'icon' => '🔄', 'text' => '运行中'];
        case 'success':
            return ['class' => 'status-success', 'icon' => '✅', 'text' => '更新成功'];
        case 'error':
            return ['class' => 'status-error', 'icon' => '❌', 'text' => '更新失败'];
        default:
            return ['class' => 'status-unknown', 'icon' => '❓', 'text' => $status['status']];
    }
}

$statusDisplay = getStatusDisplay($status);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>音乐更新状态监控</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background-color: #f5f5f5; 
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .status-card {
            padding: 20px;
            margin: 10px 0;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .status-running { background-color: #e3f2fd; border-left-color: #2196f3; }
        .status-success { background-color: #e8f5e8; border-left-color: #4caf50; }
        .status-error { background-color: #ffebee; border-left-color: #f44336; }
        .status-unknown { background-color: #f5f5f5; border-left-color: #9e9e9e; }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .info-item {
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .info-label {
            font-weight: bold;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .info-value {
            color: #212529;
            font-size: 1.1em;
        }
        
        .logs-container {
            margin-top: 30px;
        }
        
        .logs {
            background-color: #1e1e1e;
            color: #fff;
            padding: 15px;
            border-radius: 6px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .log-entry {
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .btn-primary { background-color: #007bff; color: white; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        
        .auto-refresh {
            float: right;
            margin-bottom: 20px;
        }
        
        .refresh-indicator {
            display: inline-block;
            margin-left: 10px;
            color: #6c757d;
        }
        
        .loading {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .path-info {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 10px;
            margin: 15px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            .auto-refresh {
                float: none;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>📊 音乐URL更新状态监控</h2>
        
        <div class="auto-refresh">
            <label>
                <input type="checkbox" id="autoRefresh" checked> 自动刷新 (30秒)
            </label>
            <span class="refresh-indicator" id="refreshIndicator"></span>
        </div>
        
        <div style="clear: both;"></div>
        
        <div class="path-info">
            <strong>📁 文件路径：</strong>当前监控上级目录的 <code>music_data.json</code> 文件更新状态
        </div>
        
        <div class="status-card <?php echo $statusDisplay['class']; ?>">
            <h3><?php echo $statusDisplay['icon']; ?> 服务状态：<?php echo $statusDisplay['text']; ?></h3>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">最后更新时间</div>
                <div class="info-value" id="lastUpdate">
                    <?php echo isset($status['last_update']) ? $status['last_update'] : '未知'; ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">下次更新时间</div>
                <div class="info-value" id="nextUpdate">
                    <?php echo isset($status['next_update']) ? $status['next_update'] : '未知'; ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">当前时间</div>
                <div class="info-value" id="currentTime">
                    <?php echo date('Y-m-d H:i:s'); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">日志文件大小</div>
                <div class="info-value">
                    <?php 
                    if (file_exists(LOG_FILE)) {
                        echo number_format(filesize(LOG_FILE) / 1024, 2) . ' KB';
                    } else {
                        echo '文件不存在';
                    }
                    ?>
                </div>
            </div>
        </div>
        
        <div style="margin: 20px 0;">
            <a href="auto_music_updater.php" class="btn btn-primary">🔧 返回管理页面</a>
            <a href="auto_music_updater.php?action=update_now" class="btn btn-success">🔄 立即更新</a>
            <button onclick="downloadLogs()" class="btn btn-secondary">📥 下载完整日志</button>
        </div>
        
        <div class="logs-container">
            <h3>📝 最新日志 (最近100条)</h3>
            <div class="logs" id="logsContainer">
                <?php if (empty($logs)): ?>
                    <div class="log-entry">暂无日志记录</div>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-entry"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    let autoRefreshInterval;
    let refreshCountdown = 30;
    
    function updateStatus() {
        const indicator = document.getElementById('refreshIndicator');
        indicator.innerHTML = '<span class="loading">🔄</span> 正在刷新...';
        
        fetch('?ajax=status')
            .then(response => response.json())
            .then(data => {
                // 更新状态信息
                if (data.status.last_update) {
                    document.getElementById('lastUpdate').textContent = data.status.last_update;
                }
                if (data.status.next_update) {
                    document.getElementById('nextUpdate').textContent = data.status.next_update;
                }
                document.getElementById('currentTime').textContent = new Date().toLocaleString('zh-CN');
                
                // 更新日志
                const logsContainer = document.getElementById('logsContainer');
                if (data.logs && data.logs.length > 0) {
                    logsContainer.innerHTML = data.logs.map(log => 
                        `<div class="log-entry">${escapeHtml(log)}</div>`
                    ).join('');
                }
                
                indicator.textContent = '✅ 已更新';
                
                // 重新开始倒计时
                startCountdown();
            })
            .catch(error => {
                indicator.textContent = '❌ 刷新失败';
                console.error('刷新失败:', error);
                startCountdown();
            });
    }
    
    function startCountdown() {
        refreshCountdown = 30;
        const indicator = document.getElementById('refreshIndicator');
        
        const countdownInterval = setInterval(() => {
            refreshCountdown--;
            if (refreshCountdown > 0) {
                indicator.textContent = `⏱️ ${refreshCountdown}秒后刷新`;
            } else {
                clearInterval(countdownInterval);
                if (document.getElementById('autoRefresh').checked) {
                    updateStatus();
                }
            }
        }, 1000);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function downloadLogs() {
        window.open('music_update.log', '_blank');
    }
    
    // 初始化自动刷新
    document.getElementById('autoRefresh').addEventListener('change', function() {
        if (this.checked) {
            startCountdown();
        } else {
            document.getElementById('refreshIndicator').textContent = '';
        }
    });
    
    // 页面加载时开始倒计时
    if (document.getElementById('autoRefresh').checked) {
        startCountdown();
    }
    </script>
</body>
</html>