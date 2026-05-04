/**
 * 全局音乐管理器 - 跨页面音乐播放状态管理
 * 使用 localStorage 实现播放状态的持久化和同步
 */

(function() {
    'use strict';
    
    // ==================== 常量定义 ====================
    const STORAGE_KEYS = {
        CURRENT_SONG: 'global_music_current_song',
        PLAY_STATE: 'global_music_play_state',
        PROGRESS: 'global_music_progress',
        TIMESTAMP: 'global_music_timestamp'
    };
    
    const SYNC_INTERVAL = 1000; // 状态同步间隔（毫秒）
    
    // ==================== 全局状态 ====================
    let globalAudio = null;
    let syncTimer = null;
    let isInitializing = false;
    
    // ==================== 核心功能 ====================
    
    /**
     * 初始化全局音乐管理器
     */
    function initGlobalMusicManager() {
        if (isInitializing) return;
        isInitializing = true;
        
        console.log('[GlobalMusic] 初始化全局音乐管理器');
        
        // 创建隐藏的音频元素
        createGlobalAudioElement();
        
        // 恢复之前的播放状态
        restorePlayState();
        
        // 启动状态同步
        startStateSync();
        
        // 监听其他标签页的状态变化
        window.addEventListener('storage', handleStorageChange);
        
        // 页面卸载前保存状态
        window.addEventListener('beforeunload', savePlayState);
        
        isInitializing = false;
        console.log('[GlobalMusic] 初始化完成');
    }
    
    /**
     * 创建全局音频元素
     */
    function createGlobalAudioElement() {
        // 优先使用页面中已存在的音频元素
        let audio = document.getElementById('preview-audio') || 
                    document.getElementById('global-music-audio');
        
        if (!audio) {
            // 如果没有现有音频元素，创建一个新的
            audio = document.createElement('audio');
            audio.id = 'global-music-audio';
            audio.preload = 'auto';
            document.body.appendChild(audio);
            console.log('[GlobalMusic] 创建新的音频元素');
        } else {
            console.log('[GlobalMusic] 使用现有音频元素:', audio.id);
        }
        
        globalAudio = audio;
        
        // 绑定事件（先移除旧的事件监听器，避免重复）
        audio.removeEventListener('play', onAudioPlay);
        audio.removeEventListener('pause', onAudioPause);
        audio.removeEventListener('ended', onAudioEnded);
        audio.removeEventListener('timeupdate', onAudioTimeUpdate);
        audio.removeEventListener('error', onAudioError);
        
        audio.addEventListener('play', onAudioPlay);
        audio.addEventListener('pause', onAudioPause);
        audio.addEventListener('ended', onAudioEnded);
        audio.addEventListener('timeupdate', onAudioTimeUpdate);
        audio.addEventListener('error', onAudioError);
    }
    
    /**
     * 播放指定歌曲
     * @param {Object} songData - 歌曲数据 {id, name, artist, url, coverUrl}
     */
    function playSong(songData) {
        if (!songData || !songData.url) {
            console.error('[GlobalMusic] 无效的歌曲数据');
            return;
        }
        
        console.log('[GlobalMusic] 同步歌曲信息:', songData.name);
        
        // 只保存歌曲信息，不直接播放（由页面自己的逻辑控制播放）
        saveCurrentSong(songData);
        
        // 如果当前音频元素没有设置src，或者src不同，则更新
        if (globalAudio.src !== songData.url) {
            globalAudio.src = songData.url;
        }
        
        // 广播状态变化（供其他页面监听）
        broadcastStateChange('play');
    }
    
    /**
     * 暂停播放
     */
    function pauseSong() {
        if (globalAudio && !globalAudio.paused) {
            globalAudio.pause();
            console.log('[GlobalMusic] 暂停播放');
        }
    }
    
    /**
     * 切换播放/暂停状态
     */
    function togglePlay() {
        if (!globalAudio || !globalAudio.src) {
            console.warn('[GlobalMusic] 没有正在播放的歌曲');
            return;
        }
        
        if (globalAudio.paused) {
            console.log('[GlobalMusic] 继续播放');
            globalAudio.play().catch(err => {
                console.error('[GlobalMusic] 播放失败:', err);
            });
        } else {
            console.log('[GlobalMusic] 暂停播放');
            globalAudio.pause();
        }
    }
    
    /**
     * 跳转到指定时间
     * @param {number} time - 时间（秒）
     */
    function seekTo(time) {
        if (globalAudio && !isNaN(time)) {
            globalAudio.currentTime = time;
            saveProgress(time);
        }
    }
    
    // ==================== 状态管理 ====================
    
    /**
     * 保存当前歌曲信息
     */
    function saveCurrentSong(songData) {
        try {
            localStorage.setItem(STORAGE_KEYS.CURRENT_SONG, JSON.stringify({
                id: songData.id || '',
                name: songData.name || '未知歌曲',
                artist: songData.artist || '未知歌手',
                url: songData.url || '',
                coverUrl: songData.coverUrl || '',
                timestamp: Date.now()
            }));
        } catch (e) {
            console.error('[GlobalMusic] 保存歌曲信息失败:', e);
        }
    }
    
    /**
     * 保存播放状态
     */
    function savePlayState() {
        if (!globalAudio) return;
        
        try {
            const state = {
                isPlaying: !globalAudio.paused,
                currentTime: globalAudio.currentTime,
                duration: globalAudio.duration,
                timestamp: Date.now()
            };
            
            localStorage.setItem(STORAGE_KEYS.PLAY_STATE, JSON.stringify(state));
            localStorage.setItem(STORAGE_KEYS.PROGRESS, state.currentTime.toString());
            localStorage.setItem(STORAGE_KEYS.TIMESTAMP, state.timestamp.toString());
        } catch (e) {
            console.error('[GlobalMusic] 保存播放状态失败:', e);
        }
    }
    
    /**
     * 保存进度
     */
    function saveProgress(time) {
        try {
            localStorage.setItem(STORAGE_KEYS.PROGRESS, time.toString());
        } catch (e) {
            console.error('[GlobalMusic] 保存进度失败:', e);
        }
    }
    
    /**
     * 恢复播放状态
     */
    function restorePlayState() {
        try {
            const songDataStr = localStorage.getItem(STORAGE_KEYS.CURRENT_SONG);
            const playStateStr = localStorage.getItem(STORAGE_KEYS.PLAY_STATE);
            
            if (!songDataStr) {
                console.log('[GlobalMusic] 没有待恢复的播放状态');
                return;
            }
            
            const songData = JSON.parse(songDataStr);
            const playState = playStateStr ? JSON.parse(playStateStr) : null;
            
            console.log('[GlobalMusic] 恢复歌曲:', songData.name);
            
            // 设置音频源
            if (globalAudio.src !== songData.url) {
                globalAudio.src = songData.url;
            }
            
            // 恢复进度
            if (playState && playState.currentTime) {
                globalAudio.currentTime = playState.currentTime;
            }
            
            // 更新UI
            updatePlayBarUI(songData);
            
            // 如果之前是播放状态，尝试继续播放
            if (playState && playState.isPlaying) {
                console.log('[GlobalMusic] 尝试恢复播放...');
                // 延迟播放，确保音频已加载
                setTimeout(() => {
                    globalAudio.play().then(() => {
                        console.log('[GlobalMusic] 恢复播放成功');
                        updatePlayStateUI(true);
                    }).catch(err => {
                        console.warn('[GlobalMusic] 自动播放被阻止:', err);
                        updatePlayStateUI(false);
                    });
                }, 300); // 缩短延迟时间
            }
            
        } catch (e) {
            console.error('[GlobalMusic] 恢复播放状态失败:', e);
        }
    }
    
    /**
     * 启动状态同步定时器
     */
    function startStateSync() {
        if (syncTimer) {
            clearInterval(syncTimer);
        }
        
        syncTimer = setInterval(() => {
            if (globalAudio && !globalAudio.paused) {
                savePlayState();
            }
        }, SYNC_INTERVAL);
    }
    
    // ==================== 事件处理 ====================
    
    /**
     * 音频播放事件
     */
    function onAudioPlay() {
        console.log('[GlobalMusic] 音频开始播放');
        updatePlayStateUI(true);
        broadcastStateChange('play');
    }
    
    /**
     * 音频暂停事件
     */
    function onAudioPause() {
        console.log('[GlobalMusic] 音频暂停');
        updatePlayStateUI(false);
        broadcastStateChange('pause');
        savePlayState();
    }
    
    /**
     * 音频播放结束事件
     */
    function onAudioEnded() {
        console.log('[GlobalMusic] 音频播放结束');
        updatePlayStateUI(false);
        broadcastStateChange('ended');
        savePlayState();
    }
    
    /**
     * 音频时间更新事件
     */
    function onAudioTimeUpdate() {
        if (globalAudio && !globalAudio.paused) {
            // 每5秒保存一次进度，避免频繁写入
            if (Math.floor(globalAudio.currentTime) % 5 === 0) {
                saveProgress(globalAudio.currentTime);
            }
        }
    }
    
    /**
     * 音频错误事件
     */
    function onAudioError(e) {
        console.error('[GlobalMusic] 音频播放错误:', e);
        alert('音频播放失败，请检查网络连接');
    }
    
    /**
     * 监听 storage 事件（其他标签页的变化）
     */
    function handleStorageChange(e) {
        if (e.key === STORAGE_KEYS.PLAY_STATE || 
            e.key === STORAGE_KEYS.CURRENT_SONG) {
            console.log('[GlobalMusic] 检测到其他页面状态变化');
            // 可以选择在这里同步UI，但通常不需要
        }
    }
    
    /**
     * 广播状态变化（用于同页面内的组件通信）
     */
    function broadcastStateChange(action) {
        // 触发自定义事件，供页面内其他组件监听
        const event = new CustomEvent('globalMusicStateChange', {
            detail: {
                action: action,
                isPlaying: !globalAudio.paused,
                currentTime: globalAudio.currentTime,
                duration: globalAudio.duration
            }
        });
        window.dispatchEvent(event);
    }
    
    // ==================== UI 更新 ====================
    
    /**
     * 更新播放条UI
     */
    function updatePlayBarUI(songData) {
        // 触发事件，让页面自己更新UI
        const event = new CustomEvent('globalMusicSongLoaded', {
            detail: songData
        });
        window.dispatchEvent(event);
    }
    
    /**
     * 更新播放状态UI
     */
    function updatePlayStateUI(isPlaying) {
        const event = new CustomEvent('globalMusicPlayStateChange', {
            detail: { isPlaying: isPlaying }
        });
        window.dispatchEvent(event);
    }
    
    // ==================== 公共API ====================
    
    window.GlobalMusicManager = {
        init: initGlobalMusicManager,
        play: playSong,
        pause: pauseSong,
        toggle: togglePlay,
        seek: seekTo,
        getAudio: () => globalAudio,
        getCurrentSong: () => {
            try {
                const data = localStorage.getItem(STORAGE_KEYS.CURRENT_SONG);
                return data ? JSON.parse(data) : null;
            } catch (e) {
                return null;
            }
        },
        isPlaying: () => globalAudio ? !globalAudio.paused : false
    };
    
    // ==================== 自动初始化 ====================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGlobalMusicManager);
    } else {
        initGlobalMusicManager();
    }
    
})();
