// ==================== 综合管理后台 - 统一 JavaScript ====================

const API_BASE = '/submit/api.php';
const AUTH_API = '/submit/auth_provider.php';
const VOTE_API_BASE = '/vote/';
const SETTINGS_API = '/vote/settings_api.php';

let currentGlobalAudio = null;

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

function createDocumentFragment() {
    return document.createDocumentFragment();
}

function lazyLoadImage(imgElement, src) {
    if ('loading' in HTMLImageElement.prototype) {
        imgElement.loading = 'lazy';
    }
    imgElement.src = src;
}

async function hashPassword(password) {
    const msgUint8 = new TextEncoder().encode(password);
    const hashBuffer = await crypto.subtle.digest('SHA-256', msgUint8);
    return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
}

function checkLoginStatus() {
    fetch(AUTH_API + '?action=check_login')
        .then(res => res.json())
        .then(data => {
            if (data.logged_in) {
                document.getElementById('login-overlay').style.display = 'none';
                if (data.is_first_login) {
                    document.getElementById('force-password-modal').style.display = 'flex';
                    return;
                }
                document.getElementById('app-container').style.display = 'flex';
                const userDisplay = document.getElementById('current-user-display');
                if (userDisplay) {
                    let expireText = '永久有效';
                    if (data.expires_at) {
                        const remainSec = data.expires_at - Math.floor(Date.now() / 1000);
                        const remainDays = Math.ceil(remainSec / 86400);
                        expireText = remainDays > 0 ? `${remainDays} 天` : '已到期';
                    }
                    userDisplay.textContent = `当前身份: ${data.username} (${data.role === 'admin' ? '普通管理员' : data.role === 'super' ? '超级管理员' : '部员'}) | 剩余时效: ${expireText}`;
                }
                
                const navAccounts = document.getElementById('nav-section-accounts');
                if (navAccounts) {
                    navAccounts.style.display = (data.role === 'admin' || data.role === 'super') ? 'block' : 'none';
                }
                const mobileNavAccounts = document.getElementById('mobile-nav-section-accounts');
                if (mobileNavAccounts) {
                    mobileNavAccounts.style.display = (data.role === 'admin' || data.role === 'super') ? 'flex' : 'none';
                }

                const navTransfer = document.getElementById('nav-section-transfer');
                if (navTransfer) {
                    navTransfer.style.display = (data.role === 'admin') ? 'block' : 'none';
                }
                const mobileNavTransfer = document.getElementById('mobile-nav-section-transfer');
                if (mobileNavTransfer) {
                    mobileNavTransfer.style.display = (data.role === 'admin') ? 'flex' : 'none';
                }
                loadAllData();
            } else {
                document.getElementById('login-overlay').style.display = 'flex';
                document.getElementById('app-container').style.display = 'none';
            }
        })
        .catch(err => console.error('检查登录状态失败', err));
}

async function login() {
    const user = document.getElementById('admin-user').value.trim();
    const pwd = document.getElementById('admin-pwd').value;
    if (!user || !pwd) return alert('请输入账号和密码');
    
    const secretHash = await hashPassword(pwd);
    
    fetch(AUTH_API + '?action=login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username: user, password: secretHash})
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('login-overlay').style.display = 'none';
            if (data.is_first_login) {
                document.getElementById('force-password-modal').style.display = 'flex';
            } else {
                if (data.role === 'super') {
                    window.location.href = 'super_admin.html';
                } else {
                    location.reload();
                }
            }
        } else {
            alert(data.message || '账号或密码错误！');
        }
    })
    .catch(err => alert('登录失败，请稍后重试'));
}

async function forceChangePassword() {
    const p1 = document.getElementById('new-password').value;
    const p2 = document.getElementById('confirm-password').value;
    if (!p1 || p1.length < 6) return alert('新密码不可少于6位');
    if (p1 !== p2) return alert('两次输入的密码不一致');
    
    const statusRes = await fetch(AUTH_API + '?action=check_login').then(r => r.json());
    if (!statusRes.logged_in) return alert('当前登录会话已失效，请刷新页面重新登录');
    
    const finalClientHash = await hashPassword(p1);

    fetch(AUTH_API + '?action=change_password', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({new_password: finalClientHash})
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert('密码修改成功，管理系统访问权限已成功激活！');
            document.getElementById('force-password-modal').style.display = 'none';
            if (statusRes.role === 'super') {
                window.location.href = 'super_admin.html';
            } else {
                location.reload();
            }
        } else {
            alert(data.message);
        }
    })
    .catch(() => alert('网络通信异常'));
}

function logout() {
    if (!confirm('确定要退出登录吗？')) return;
    fetch(AUTH_API + '?action=logout')
    .then(() => {
        location.reload();
    });
}

function loadSubAccounts() {
    fetch(AUTH_API + '?action=list_accounts')
        .then(res => res.json())
        .then(res => {
            const list = document.getElementById('temp-account-list');
            if (!list) return;
            list.innerHTML = '';
            if (res.status === 'success' && res.data) {
                res.data.forEach(user => {
                    list.innerHTML += `
                        <tr>
                            <td><b>${user.username}</b></td>
                            <td>${user.expires_at}</td>
                            <td>${user.is_first_login ? '<span style="color:orange">未改密</span>':'<span style="color:green">已激活</span>'}</td>
                            <td><button class="btn btn-danger btn-small" style="padding:4px 8px;font-size:12px;" onclick="deleteSubAccount('${user.username}')">收回权限</button></td>
                        </tr>`;
                });
            }
        });
}

function createTempAccount() {
    const username = document.getElementById('temp-username').value.trim();
    const days = parseInt(document.getElementById('temp-days').value);
    if (!username) return alert('请指定部员账号名');

    fetch(AUTH_API + '?action=add_account', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username, role: 'temp', days})
    }).then(res => res.json()).then(res => {
        if (res.status === 'success') {
            alert('新部员临时账号已下发！默认初始密码为 6个1，部员首次登录将被强制要求修改密码。');
            document.getElementById('temp-username').value = '';
            loadSubAccounts();
        } else alert(res.message);
    });
}

function deleteSubAccount(username) {
    if (!confirm('确定提前收回该部员的所有管理后台访问权限吗？')) return;
    fetch(AUTH_API + '?action=delete_account', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username})
    }).then(() => loadSubAccounts());
}

function switchModule(moduleId) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    
    document.getElementById(moduleId).classList.add('active');
    
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('active');
    }
    
    const titleMap = {
        'submit-approved': '已筛选投稿',
        'submit-all': '全部投稿',
        'submit-rejected': '未选中投稿',
        'submit-qa': 'Q&A 管理',
        'feedback-list': '用户反馈',
        'vote-music': '音乐管理',
        'vote-stats': '投票统计',
        'vote-records': '投票记录',
        'vote-reset': '音频管理',
        'vote-download': '歌曲下载管理',
        'settings-announcement': '公告管理',
        'settings-feature': '功能控制',
        'settings-backup': '备份管理',
        'settings-system': '系统设置',
        'settings-changelog': '更新日志',
        'account-mgmt': '下发临时账号',
        'account-transfer': '账号权属转让'
    };
    
    document.getElementById('pageTitle').innerHTML = '<i class="fas fa-home"></i> ' + titleMap[moduleId];
    loadModuleData(moduleId);
}

function loadAllData() {
    loadSubmitData();
    loadFeedbackData();
    loadMusicList();
    loadVoteRecords();
    loadAnnouncement();
    loadSystemSettings();
}

function loadModuleData(moduleId) {
    switch(moduleId) {
        case 'submit-approved':
        case 'submit-all':
        case 'submit-rejected':
            loadSubmitData();
            break;
        case 'feedback-list':
            loadFeedbackData();
            break;
        case 'vote-music':
            loadMusicList();
            break;
        case 'vote-stats':
        case 'vote-records':
            loadVoteRecords();
            break;
        case 'vote-download':
            loadDownloadMusicList();
            break;
        case 'settings-announcement':
            loadAnnouncement();  
            break;
        case 'settings-feature':
            loadFeatureSettings();
            break;
        case 'settings-backup':
            loadBackupList();
            break;
        case 'settings-system':
            loadSystemSettings();
            break;
        case 'settings-changelog':
            loadChangelogList();
            break;
        case 'account-transfer':
            loadTransferModuleData();
            break;
    }
}

let globalSubmitData = null;
let globalReasons = [];
let cachedSubmissions = { approved: null, all: null, rejected: null };

function loadSubmitData() {
    fetch(API_BASE + '?action=get_admin_data')
        .then(res => res.json())
        .then(res => {
            if(res.code === 401) { location.reload(); return; }
            globalSubmitData = res.data;
            if (JSON.stringify(globalSubmitData.submissions) !== JSON.stringify(cachedSubmissions)) {
                cachedSubmissions = {...globalSubmitData.submissions};
                renderSubmitLists();
            }
        })
        .catch(err => console.error('加载投稿数据失败', err));
    
    loadReasons();
    loadQA();
}

function renderSubmitLists() {
    if (!globalSubmitData) return;
    
    const render = (songs, containerId) => {
        const container = document.getElementById(containerId);
        if (!songs || songs.length === 0) {
            container.innerHTML = '<div style="padding:20px;color:#999;text-align:center;">暂无数据</div>';
            return;
        }
        
        const fragment = document.createDocumentFragment();
        const tempContainer = document.createElement('ul');
        tempContainer.className = 'data-list';
        
        songs.forEach(song => {
            const li = document.createElement('li');
            li.className = 'data-item';
            
            const imgId = `img-${containerId}-${song.id}`;
            const audioId = `audio-${containerId}-${song.id}`;
            
            li.innerHTML = `
                <img id="${imgId}" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="封面" style="background:#f0f0f0;">
                <div class="data-info">
                    <h4>${song.name}</h4>
                    <p>${song.artist} - ${song.album} | 投稿：${song.timestamp}</p>
                </div>
                <div class="data-controls">
                    <audio id="${audioId}" controls preload="metadata" style="flex:1;min-width:200px;max-width:100%;">
                        <source src="" type="audio/mpeg">
                    </audio>
                    <button class="btn btn-info btn-small preview-song-btn" data-song-id="${song.id}" data-container-id="${containerId}" style="flex-shrink:0;white-space:nowrap;"><i class="fas fa-play"></i> 试听</button>
                    <button class="btn btn-success btn-small" onclick="pushToSite('${encodeURIComponent(JSON.stringify(song))}')" style="flex-shrink:0;white-space:nowrap;"><i class="fas fa-share-square"></i> 推送</button>
                </div>`;
            tempContainer.appendChild(li);
        });
        
        container.innerHTML = '';
        while (tempContainer.firstChild) {
            container.appendChild(tempContainer.firstChild);
        }
        
        requestAnimationFrame(() => {
            songs.forEach(song => {
                const imgElem = document.getElementById(`img-${containerId}-${song.id}`);
                if (imgElem) { lazyLoadImage(imgElem, song.picUrl); }
            });
        });
    };
    render(globalSubmitData.submissions.all, 'list-all');
}

function loadAudioUrls(containerId, songs) {
    const batchSize = 5;
    for (let i = 0; i < songs.length; i += batchSize) {
        const batch = songs.slice(i, i + batchSize);
        setTimeout(() => {
            batch.forEach(song => {
                const audioElem = document.getElementById(`audio-${containerId}-${song.id}`);
                if (audioElem) {
                    fetch(API_BASE + '?action=music_url&id=' + song.id)
                        .then(res => res.json())
                        .then(data => { if (data.url && audioElem) audioElem.src = data.url; })
                        .catch(err => console.error('音频加载失败', err));
                }
            });
        }, i * 200);
    }
}

window.previewAdminSong = function(songId, songName, containerId) {
    const audioId = `audio-${containerId}-${songId}`;
    const audioElem = document.getElementById(audioId);
    
    if (!audioElem) return alert('找不到音频元素');

    document.querySelectorAll('audio').forEach(a => { if (a !== audioElem && !a.paused) a.pause(); });
    if (currentGlobalAudio && !currentGlobalAudio.paused) currentGlobalAudio.pause();
    
    if (!audioElem.src || audioElem.src === window.location.href) {
        fetch('/submit/api.php?action=music_url&id=' + songId)
            .then(res => res.json())
            .then(data => {
                if (data.url) {
                    audioElem.src = data.url;
                    audioElem.play().catch(err => alert('音频播放失败'));
                } else {
                    alert('API未返回有效URL');
                }
            })
            .catch(err => alert('获取音频失败: ' + err.message));
    } else {
        audioElem.play().catch(err => alert('音频播放失败'));
    }
};

function pushToSite(encodedSongStr) {
    if(!confirm("确定将此歌曲推送到网站主页吗？")) return;
    const song = JSON.parse(decodeURIComponent(encodedSongStr));
    
    fetch(API_BASE, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'push_to_site', song: song })
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alert('✅ 推送成功！已拉取直链并追加到起床铃网页。');
            loadSubmitData();
        } else {
            alert('推送失败：' + data.message);
        }
    })
    .catch(() => alert('网络错误'));
}

function loadQA() {
    fetch(API_BASE + '?action=get_qa')
        .then(res => res.json())
        .then(data => { if (data.status === 'success') renderQA(data.qa || []); })
        .catch(err => console.error('加载 Q&A 失败', err));
}

function renderQA(qaList) {
    const container = document.getElementById('qa-admin-list');
    if (!qaList || qaList.length === 0) {
        container.innerHTML = '<div style="color:#999;padding:20px;">暂无 Q&A</div>';
        return;
    }
    const fragment = document.createDocumentFragment();
    qaList.forEach(item => {
        const div = document.createElement('div');
        div.style.cssText = 'background:#f8f9fa;border-left:4px solid var(--primary);padding:15px;margin-bottom:10px;border-radius:4px;';
        div.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div style="color:var(--primary);font-weight:600;"><i class="fas fa-question"></i> ${item.question}</div>
                <button class="btn btn-danger btn-small" onclick="deleteQA('${item.id}')"><i class="fas fa-trash"></i> 删除</button>
            </div>
            <div style="color:#555;background:white;padding:10px;border-radius:4px;"><i class="fas fa-answer"></i> ${item.answer}</div>`;
        fragment.appendChild(div);
    });
    container.innerHTML = '';
    container.appendChild(fragment);
}

function addQA() {
    const question = document.getElementById('qa-question').value.trim();
    const answer = document.getElementById('qa-answer').value.trim();
    if (!question || !answer) return alert('问题和回答不能为空');
    
    fetch(API_BASE, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'add_qa', question, answer })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert('Q&A 添加成功！');
            document.getElementById('qa-question').value = '';
            document.getElementById('qa-answer').value = '';
            loadQA();
        } else {
            alert('添加失败：' + data.message);
        }
    })
    .catch(() => alert('网络错误'));
}

function deleteQA(id) {
    if (!confirm('确定删除此 Q&A 吗？')) return;
    fetch(API_BASE, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'delete_qa', id })
    })
    .then(res => res.json())
    .then(data => { if (data.status === 'success') loadQA(); else alert('删除失败'); })
    .catch(() => alert('网络错误'));
}

function loadReasons() {
    fetch(API_BASE + '?action=get_reasons')
        .then(res => res.json())
        .then(res => { if(res.status === 'success') globalReasons = res.data || []; })
        .catch(err => console.error('加载失败原因失败', err));
}

function loadFeedbackData() {
    fetch(API_BASE + '?action=get_admin_data')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const feedbacks = data.data.feedbacks || [];
                renderFeedbacks(feedbacks);
                updateFeedbackStats(feedbacks);
            }
        })
        .catch(err => console.error('加载反馈失败', err));
}

function renderFeedbacks(feedbacks) {
    const container = document.getElementById('feedback-container');
    if (feedbacks.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#999;"><i class="fas fa-inbox" style="font-size:3rem;margin-bottom:20px;opacity:0.5;"></i><p>暂无反馈</p></div>';
        return;
    }
    const fragment = document.createDocumentFragment();
    feedbacks.forEach(f => {
        const typeColors = { 'bug': '#e74c3c', 'suggestion': '#3498db', 'music': '#9b59b6', 'ui': '#f39c12', 'other': '#95a5a6' };
        const typeNames = { 'bug': '功能异常', 'suggestion': '功能建议', 'music': '音乐问题', 'ui': '界面体验', 'other': '其他问题' };
        
        const div = document.createElement('div');
        div.style.cssText = 'background:white;border:1px solid #e0e0e0;border-radius:8px;padding:15px;margin-bottom:15px;';
        div.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span style="padding:5px 12px;border-radius:20px;font-size:12px;font-weight:bold;background:${typeColors[f.type] || '#95a5a6'};color:white;">${typeNames[f.type] || '其他问题'}</span>
                    <span style="color:#999;font-size:13px;"><i class="far fa-clock"></i> ${f.time}</span>
                    ${f.contact ? `<span style="color:var(--primary);font-size:13px;"><i class="fas fa-envelope"></i> ${f.contact}</span>` : ''}
                    <span style="color:${f.status === 'unread' ? 'var(--accent)' : '#999'};font-size:12px;"><i class="fas fa-circle" style="font-size:8px;"></i> ${f.status === 'unread' ? '未读' : '已读'}</span>
                </div>
                <div style="display:flex;gap:10px;">
                    ${f.status === 'unread' ? `<button class="btn btn-success btn-small" onclick="markFeedbackRead('${f.id}')"><i class="fas fa-check"></i> 标记已读</button>` : `<button class="btn btn-warning btn-small" onclick="markFeedbackUnread('${f.id}')"><i class="fas fa-undo"></i> 标记未读</button>`}
                    <button class="btn btn-danger btn-small" onclick="deleteFeedback('${f.id}')"><i class="fas fa-trash"></i> 删除</button>
                </div>
            </div>
            <div style="color:#333;line-height:1.6;white-space:pre-wrap;">${f.content}</div>`;
        fragment.appendChild(div);
    });
    container.innerHTML = '';
    container.appendChild(fragment);
}

function updateFeedbackStats(feedbacks) {
    const total = feedbacks.length;
    const unread = feedbacks.filter(f => f.status === 'unread').length;
    const today = feedbacks.filter(f => {
        const feedbackDate = new Date(f.time.replace(' ', 'T'));
        const today = new Date();
        return feedbackDate.toDateString() === today.toDateString();
    }).length;
    
    document.getElementById('feedbackTotal').textContent = total;
    document.getElementById('feedbackUnread').textContent = unread;
    document.getElementById('feedbackToday').textContent = today;
}

function markFeedbackRead(id) { updateFeedbackStatus(id, 'read'); }
function markFeedbackUnread(id) { updateFeedbackStatus(id, 'unread'); }

function updateFeedbackStatus(id, status) {
    fetch(API_BASE + '?action=update_feedback_status', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, status })
    })
    .then(res => res.json())
    .then(data => { if (data.status === 'success') loadFeedbackData(); else alert('操作失败'); })
    .catch(() => alert('网络错误'));
}

function deleteFeedback(id) {
    if (!confirm('确定要删除这条反馈吗？')) return;
    fetch(API_BASE + '?action=delete_feedback', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
    })
    .then(res => res.json())
    .then(data => { if (data.status === 'success') loadFeedbackData(); else alert('删除失败'); })
    .catch(() => alert('网络错误'));
}

function showAddMusicForm() { document.getElementById('music-upload-form').style.display = 'block'; }
function hideAddMusicForm() { document.getElementById('music-upload-form').style.display = 'none'; }

function toggleMusicUploadFields() {
    const source = document.getElementById('musicSource').value;
    document.getElementById('local-fields').style.display = source === 'local' ? 'block' : 'none';
    document.getElementById('netease-fields').style.display = source === 'netease' ? 'block' : 'none';
}

function uploadLocalMusic() {
    const title = document.getElementById('musicTitle').value.trim();
    const fileInput = document.getElementById('musicFile');
    
    if (!title) return alert('请输入歌曲标题');
    if (!fileInput.files[0]) return alert('请选择音频文件');

    const file = fileInput.files[0];
    if (file.size > 30 * 1024 * 1024) {
        return alert(`文件过大！请上传小于 30MB 的音频。`);
    }
    
    const formData = new FormData();
    formData.append('title', title);
    formData.append('audio', fileInput.files[0]);
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 上传中...';
    
    fetch(VOTE_API_BASE + 'music_manager.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('上传成功！');
            hideAddMusicForm();
            loadMusicList();
            loadAudioFilesList();
            document.getElementById('musicTitle').value = '';
            fileInput.value = '';
        } else {
            alert('上传失败：' + data.message);
        }
    })
    .catch(err => alert('上传失败：' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> 上传'; });
}

function addOnlineMusic(platform) {
    const link = platform === 'netease' ? document.getElementById('neteaseLink').value : '';
    if (!link) return alert('请输入音乐链接或歌手歌曲名');
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
    
    const isUrl = link.startsWith('http://') || link.startsWith('https://');
    let bodyData = isUrl ? `platform=${platform}&music_link=${encodeURIComponent(link)}&auto_identify=1` : `platform=${platform}&artist_song=${encodeURIComponent(link)}&auto_identify=1`;
    
    fetch(VOTE_API_BASE + 'music_api_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: bodyData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('添加成功！');
            hideAddMusicForm();
            loadMusicList();
            if (platform === 'netease') document.getElementById('neteaseLink').value = '';
        } else {
            alert('添加失败：' + data.message);
        }
    })
    .catch(err => alert('请求失败：' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> 添加'; });
}

function loadMusicList() {
    fetch(VOTE_API_BASE + 'music_manager.php')
        .then(res => res.json())
        .then(data => { if (data.success) { musicList = data.data || []; renderMusicList(); } else { throw new Error(data.message); } })
        .catch(err => { document.getElementById('music-list-container').innerHTML = '<div style="color:red;padding:20px;">加载失败：' + err.message + '</div>'; });
}

function renderMusicList() {
    const container = document.getElementById('music-list-container');
    if (musicList.length === 0) { container.innerHTML = '<div style="text-align:center;padding:40px;color:#999;">暂无音乐</div>'; return; }
    
    const fragment = document.createDocumentFragment();
    musicList.forEach(music => {
        const sourceInfo = music.file ? `本地 | ${music.file}` : `在线 | ${music.source}`;
        const playFn = music.file ? `playLocalMusic('${music.file}')` : `playOnlineMusic('${music.url || ''}')`;
        
        const div = document.createElement('div');
        div.style.cssText = 'background:white;border:1px solid #e0e0e0;border-radius:8px;padding:15px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;';
        div.innerHTML = `
            <div>
                <h4 style="margin:0 0 5px 0;color:var(--primary);">${music.title}</h4>
                <p style="margin:0;color:#999;font-size:13px;">ID: ${music.id} | ${sourceInfo}</p>
            </div>
            <div style="display:flex;gap:10px;">
                <button class="btn btn-success btn-small" onclick="${playFn}"><i class="fas fa-play"></i> 播放</button>
                <button class="btn btn-primary btn-small" onclick="editMusic(${music.id})"><i class="fas fa-edit"></i> 编辑</button>
                <button class="btn btn-danger btn-small" onclick="deleteMusic(${music.id})"><i class="fas fa-trash"></i> 删除</button>
            </div>`;
        fragment.appendChild(div);
    });
    container.innerHTML = '';
    container.appendChild(fragment);
}

window.playLocalMusic = function(file) {
    if (currentGlobalAudio) currentGlobalAudio.pause();
    document.querySelectorAll('audio').forEach(a => { if (!a.paused) a.pause(); });
    requestAnimationFrame(() => {
        currentGlobalAudio = new Audio('../vote/audio/' + file);
        currentGlobalAudio.play().catch(err => console.error('播放失败:', err));
    });
};

window.playOnlineMusic = function(url) {
    if (!url) return alert('暂无播放链接');
    if (currentGlobalAudio) currentGlobalAudio.pause();
    document.querySelectorAll('audio').forEach(a => { if (!a.paused) a.pause(); });
    requestAnimationFrame(() => {
        currentGlobalAudio = new Audio(url);
        currentGlobalAudio.play().catch(err => console.error('播放失败:', err));
    });
};

window.editMusic = async function(id) {
    const m = musicList.find(i => i.id === id);
    const newTitle = prompt('新标题:', m.title);
    if (newTitle && newTitle.trim() !== m.title) {
        try {
            const res = await fetch(VOTE_API_BASE + 'music_manager.php', { method: 'PUT', body: JSON.stringify({id, title: newTitle.trim()}) });
            const data = await res.json();
            if(data.success) { alert('更新成功'); loadMusicList(); } else { throw new Error(data.message); }
        } catch(e) { alert('更新失败：' + e.message); }
    }
};

window.deleteMusic = async function(id) {
    if (!confirm('确定删除？')) return;
    try {
        const res = await fetch(VOTE_API_BASE + 'music_manager.php', { method: 'DELETE', body: JSON.stringify({id}) });
        const data = await res.json();
        if(data.success) { alert('删除成功'); loadMusicList(); } else { throw new Error(data.message); }
    } catch(e) { alert('删除失败：' + e.message); }
};

function loadVoteRecords() {
    fetch(VOTE_API_BASE + 'get_vote_records.php')
        .then(res => res.json())
        .then(data => { if(data.success) { voteRecords = data.records || []; updateVoteStatistics(); renderVoteRecordsTable(); } })
        .catch(err => console.error('加载投票记录失败', err));
}

function updateVoteStatistics() {
    const today = new Date().toISOString().split('T')[0];
    const ips = new Set();
    const votes = {};
    let todayCount = 0;
    
    voteRecords.forEach(r => {
        ips.add(r.ip);
        if(r.time.startsWith(today)) todayCount++;
        votes[r.songId] = (votes[r.songId] || 0) + 1;
    });
    
    document.getElementById('totalVotes').textContent = voteRecords.length;
    document.getElementById('uniqueVoters').textContent = ips.size;
    document.getElementById('todayVotes').textContent = todayCount;
    document.getElementById('topSongVotes').textContent = Math.max(...Object.values(votes), 0);
    
    updateSongRanking(votes);
}

function updateSongRanking(votes) {
    const songNameMap = {};
    voteRecords.forEach(r => { songNameMap[r.songId] = r.songName; });
    
    const sorted = Object.entries(votes).map(([id, n]) => {
        return { name: songNameMap[id] || `ID:${id}`, votes: n, pct: voteRecords.length ? ((n/voteRecords.length)*100).toFixed(1) : 0 };
    }).sort((a,b) => b.votes - a.votes);
    
    const tbody = document.getElementById('songRankingBody');
    const fragment = document.createDocumentFragment();
    
    sorted.forEach((s, i) => {
        const tr = document.createElement('tr');
        tr.style.cssText = 'border-bottom:1px solid #eee;';
        tr.innerHTML = `<td style="padding:12px;">${i+1}</td><td style="padding:12px;">${s.name}</td><td style="padding:12px;">${s.votes}</td><td style="padding:12px;">${s.pct}%</td>`;
        fragment.appendChild(tr);
    });
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
}

function renderVoteRecordsTable() {
    const tbody = document.getElementById('voteRecordsBody');
    const pageRecs = voteRecords.slice(0, 100);
    const fragment = document.createDocumentFragment();
    
    pageRecs.forEach(r => {
        const tr = document.createElement('tr');
        tr.style.cssText = 'border-bottom:1px solid #eee;';
        tr.innerHTML = `<td style="padding:12px;">${r.time}</td><td style="padding:12px;">${r.ip}</td><td style="padding:12px;">${r.songId}</td><td style="padding:12px;">${r.songName}</td>`;
        fragment.appendChild(tr);
    });
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
}

function showResetConfirm() {
    const resetMusic = document.getElementById('resetMusic').checked;
    const resetVotes = document.getElementById('resetVotes').checked;
    const resetSubmit = document.getElementById('resetSubmit')?.checked;
    const resetAudio = document.getElementById('resetAudio')?.checked;
    if (!resetMusic && !resetVotes && !resetSubmit && !resetAudio) return alert('请至少选择一个重置项');
    
    const pwd = prompt('请输入管理员密码确认重置：');
    if (!pwd) return;
    
    const options = [];
    if (resetMusic) options.push('music');
    if (resetVotes) options.push('votes');
    if (resetSubmit) options.push('submit');
    if (resetAudio) options.push('audio');
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 重置中...';
    
    fetch(VOTE_API_BASE + 'reset_system.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'reset', password:pwd, options:options})
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            let msg = '重置成功！\n\n';
            data.details.forEach(item => { msg += '✓ ' + item + '\n'; });
            alert(msg); loadMusicList(); loadVoteRecords();
        } else {
            alert('重置失败：' + data.message);
        }
    })
    .catch(err => alert('请求失败：' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> 执行系统重置'; });
}

let audioFilesList = [];

function loadAudioFilesList() {
    const container = document.getElementById('audioFilesList');
    if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;"></i><div>正在加载音频文件列表...</div></div>';
    
    fetch(VOTE_API_BASE + 'music_manager.php?action=list_audio_files')
        .then(res => res.json())
        .then(data => { if (data.success) { audioFilesList = data.files || []; renderAudioFilesList(); } else { throw new Error(data.message || '加载失败'); } })
        .catch(err => { container.innerHTML = '<div style="text-align:center;padding:30px;color:#c0392b;"><i class="fas fa-exclamation-circle" style="font-size:24px;margin-bottom:10px;"></i><div>加载失败：' + err.message + '</div></div>'; });
}

function renderAudioFilesList() {
    const container = document.getElementById('audioFilesList');
    if (!container) return;
    if (audioFilesList.length === 0) { container.innerHTML = '<div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-folder-open" style="font-size:3rem;margin-bottom:15px;opacity:0.5;"></i><div>暂无本地音频文件</div></div>'; return; }
    
    const fragment = document.createDocumentFragment();
    audioFilesList.forEach((file) => {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;padding:15px;background:rgba(255, 255, 255, 0.9);border:2px solid rgba(74, 108, 247, 0.1);border-radius:12px;margin-bottom:10px;cursor:pointer;';
        label.innerHTML = `
            <input type="checkbox" class="audio-file-checkbox" value="${file.name}" style="margin-right:15px;transform:scale(1.3);">
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                    <strong style="color:var(--text);font-size:15px;">${file.name}</strong>
                    <span style="color:var(--text-light);font-size:13px;">${file.size}</span>
                </div>
                <div style="color:var(--text-light);font-size:13px;"><i class="far fa-clock"></i> ${file.modified} | <i class="fas fa-music"></i> ID: ${file.id || 'N/A'}</div>
            </div>`;
        fragment.appendChild(label);
    });
    container.innerHTML = ''; container.appendChild(fragment);
}

function deleteSelectedAudioFiles() {
    const checkboxes = document.querySelectorAll('.audio-file-checkbox:checked');
    if (checkboxes.length === 0) return alert('请至少选择一个音频文件');
    
    const selectedFiles = Array.from(checkboxes).map(cb => cb.value);
    if (!confirm(`确定要删除选中的 ${selectedFiles.length} 个音频文件吗？`)) return;
    
    const pwd = prompt('请输入管理员密码确认删除：');
    if (!pwd) return;
    
    const btn = event.target; btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 删除中...';
    
    fetch(VOTE_API_BASE + 'music_manager.php?action=delete_audio_files', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ files: selectedFiles, password: pwd })
    })
    .then(res => res.json())
    .then(data => { if (data.success) { alert(`成功删除 ${data.deleted_count || selectedFiles.length} 个文件`); loadAudioFilesList(); } else { alert('删除失败：' + data.message); } })
    .catch(err => alert('删除失败：' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> 删除选中的音频文件'; });
}

const DOWNLOAD_API = './download_handler.php';
let downloadMusicList = [];

function downloadSongById(internalId) {
    if (!internalId) return false;
    window.open(`${DOWNLOAD_API}?id=${internalId}&level=exhigh`, '_blank');
    return true;
}
window.downloadSingleSong = function(internalId) { downloadSongById(internalId); };

async function batchDownload(songs, btn) {
    if (!songs.length) return alert('没有歌曲可下载');
    let originalText = btn ? btn.innerHTML : ''; if (btn) btn.disabled = true;
    let successCount = 0;
    for (let i = 0; i < songs.length; i++) {
        if (btn) btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> 触发下载 ${i+1}/${songs.length}`;
        downloadSongById(songs[i].id); successCount++;
        if (i < songs.length - 1) await new Promise(r => setTimeout(r, 300));
    }
    if (btn) { btn.disabled = false; btn.innerHTML = originalText; }
    alert(`已触发 ${successCount} 个下载，请检查浏览器下载记录。`);
}

window.downloadAllSongs = async function(event) {
    if (!downloadMusicList.length) return alert('没有歌曲');
    if (!confirm(`确定下载全部 ${downloadMusicList.length} 首吗？`)) return;
    await batchDownload(downloadMusicList, event?.currentTarget);
};

window.downloadSelectedSongs = async function(event) {
    const checkboxes = document.querySelectorAll('.download-music-checkbox:checked');
    if (!checkboxes.length) return alert('请至少选择一首歌曲');
    const selectedIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    const selectedSongs = downloadMusicList.filter(s => selectedIds.includes(s.id));
    if (!confirm(`确定下载选中的 ${selectedSongs.length} 首吗？`)) return;
    await batchDownload(selectedSongs, event?.currentTarget);
};

window.loadDownloadMusicList = async function() {
    const container = document.getElementById('download-music-list-container');
    if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:20px;">加载中...</div>';
    try {
        const resp = await fetch(VOTE_API_BASE + 'music_manager.php');
        const data = await resp.json();
        if (!data.success) throw new Error(data.message);
        downloadMusicList = data.data || []; renderDownloadList();
    } catch (err) { container.innerHTML = `<div style="color:red;padding:20px;">加载失败: ${err.message}</div>`; }
};

function renderDownloadList() {
    const container = document.getElementById('download-music-list-container');
    if (!container) return;
    if (downloadMusicList.length === 0) { container.innerHTML = '<div style="padding:20px;">暂无歌曲</div>'; return; }
    let html = '';
    downloadMusicList.forEach(music => {
        const title = music.title || `music_${music.id}`;
        html += `
            <label style="display:flex;align-items:center;padding:15px;background:rgba(255,255,255,0.9);border-radius:12px;margin-bottom:10px;cursor:pointer;">
                <input type="checkbox" class="download-music-checkbox" value="${music.id}" style="margin-right:15px;">
                <div style="flex:1;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong>${escapeHtml(title)}</strong>
                        <button class="btn btn-success btn-small" onclick="event.stopPropagation();downloadSingleSong(${music.id})"><i class="fas fa-download"></i> 下载</button>
                    </div>
                    <div style="font-size:13px;color:#666;">ID: ${music.id}</div>
                </div>
            </label>`;
    });
    container.innerHTML = html;
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; return '&gt;'; });
}

let richEditorInitialized = false; let savedSelectionRange = null;

function saveCurrentSelection() {
    const editor = document.getElementById('voteAnnouncementEditorRich'); if (!editor) return;
    const selection = window.getSelection();
    if (selection.rangeCount > 0 && editor.contains(selection.anchorNode)) savedSelectionRange = selection.getRangeAt(0).cloneRange();
    else savedSelectionRange = null;
}

function execEditorCommand(cmd, value = null) {
    const editor = document.getElementById('voteAnnouncementEditorRich');
    if (document.activeElement !== editor) {
        editor.focus();
        if (savedSelectionRange) { const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(savedSelectionRange); }
    }
    document.execCommand(cmd, false, value);
    setTimeout(() => { saveCurrentSelection(); }, 10);
    editor.dispatchEvent(new Event('input'));
}

function initRichEditor() {
    if (richEditorInitialized) return;
    const editor = document.getElementById('voteAnnouncementEditorRich');
    const toolbar = document.getElementById('richEditorToolbar');
    if (!editor || !toolbar) return;

    toolbar.querySelectorAll('[data-cmd]').forEach(btn => {
        btn.addEventListener('mousedown', (e) => {
            e.preventDefault(); saveCurrentSelection();
            const cmd = btn.getAttribute('data-cmd');
            if (cmd === 'createLink') {
                let url = prompt('请输入链接地址', 'https://');
                if (url) execEditorCommand('createLink', url);
            } else execEditorCommand(cmd);
        });
    });
    const colorPicker = document.getElementById('foreColorPicker');
    if (colorPicker) colorPicker.addEventListener('change', (e) => { saveCurrentSelection(); execEditorCommand('foreColor', e.target.value); });
    
    editor.addEventListener('mouseup', saveCurrentSelection);
    editor.addEventListener('keyup', saveCurrentSelection);
    editor.addEventListener('focus', saveCurrentSelection);
    richEditorInitialized = true;
}

function loadAnnouncement() {
    fetch(VOTE_API_BASE + 'announcement_manager.php')
        .then(res => res.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                const editor = document.getElementById('voteAnnouncementEditorRich');
                if (editor) editor.innerHTML = data.content || '';
            } catch(e) {
                const editor = document.getElementById('voteAnnouncementEditorRich');
                if (editor) editor.innerHTML = '<p>暂无公告内容，请编辑后保存。</p>';
            }
        });
}

function saveVoteAnnouncement() {
    const editor = document.getElementById('voteAnnouncementEditorRich'); if (!editor) return alert('编辑器未初始化');
    let htmlContent = editor.innerHTML.trim(); if (!htmlContent) return alert('公告内容不能为空');
    
    const btn = event ? event.currentTarget : document.querySelector('#settings-announcement .btn-primary');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...'; }
    
    fetch(VOTE_API_BASE + 'announcement_manager.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: htmlContent })
    })
    .then(res => res.text())
    .then(text => {
        try { const data = JSON.parse(text); if (data.success) alert('✅ 投票页公告已更新'); else alert('保存失败：' + data.message); } 
        catch(e) { alert('后端返回异常，但公告可能已保存。'); }
    })
    .catch(err => alert('请求错误：' + err.message))
    .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> 保存'; } });
}

let featureSettings = { vote_enabled: true, submit_enabled: true, vote_closed_message: '', submit_closed_message: '' };

function loadFeatureSettings() {
    fetch(SETTINGS_API + '?action=get')
        .then(res => res.json())
        .then(data => { if (data.success) { featureSettings = data.data; renderFeatureSettings(); } });
}

function renderFeatureSettings() {
    const voteToggle = document.getElementById('voteEnabledToggle');
    const submitToggle = document.getElementById('submitEnabledToggle');
    if (voteToggle) { voteToggle.checked = featureSettings.vote_enabled; updateVoteToggleVisual(voteToggle); }
    if (submitToggle) { submitToggle.checked = featureSettings.submit_enabled; updateSubmitToggleVisual(submitToggle); }
    
    const voteMessage = document.getElementById('voteClosedMessage');
    const submitMessage = document.getElementById('submitClosedMessage');
    if (voteMessage) voteMessage.value = featureSettings.vote_closed_message || '';
    if (submitMessage) submitMessage.value = featureSettings.submit_closed_message || '';
}

function updateVoteToggleVisual(checkbox) {
    document.getElementById('voteStatusText').innerHTML = checkbox.checked ? '<span class="status-enabled"><i class="fas fa-check-circle"></i> 当前状态：已开启</span>' : '<span class="status-disabled"><i class="fas fa-times-circle"></i> 当前状态：已关闭</span>';
}
function updateSubmitToggleVisual(checkbox) {
    document.getElementById('submitStatusText').innerHTML = checkbox.checked ? '<span class="status-enabled"><i class="fas fa-check-circle"></i> 当前状态：已开启</span>' : '<span class="status-disabled"><i class="fas fa-times-circle"></i> 当前状态：已关闭</span>';
}

function saveFeatureSettings() {
    const voteToggle = document.getElementById('voteEnabledToggle');
    const submitToggle = document.getElementById('submitEnabledToggle');
    const voteMessage = document.getElementById('voteClosedMessage');
    const submitMessage = document.getElementById('submitClosedMessage');
    if (!voteToggle || !submitToggle || !voteMessage || !submitMessage) return alert('页面元素不完整，请刷新重试');
    
    const pwd = prompt('请输入管理员密码确认保存：'); if (!pwd) return;
    const btn = event.target; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    
    fetch(SETTINGS_API, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_all', password: pwd,
            vote_enabled: voteToggle.checked, submit_enabled: submitToggle.checked,
            vote_closed_message: voteMessage.value, submit_closed_message: submitMessage.value
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('功能控制设置已保存！');
            featureSettings.vote_enabled = voteToggle.checked; featureSettings.submit_enabled = submitToggle.checked;
            featureSettings.vote_closed_message = voteMessage.value; featureSettings.submit_closed_message = submitMessage.value;
        } else { alert('保存失败：' + data.message); }
    })
    .catch(err => alert('保存失败：' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> 保存设置'; });
}

let backupFiles = []; let backupListController = null;

function loadBackupList() {
    const container = document.getElementById('backupFilesList'); const countSpan = document.getElementById('backupCount'); if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i><div>正在加载...</div></div>';
    if (backupListController) backupListController.abort();
    backupListController = new AbortController();
    
    fetch(VOTE_API_BASE + 'backup_manager.php?action=list', { signal: backupListController.signal })
        .then(res => res.json())
        .then(data => { if (data.success) { backupFiles = data.files || []; renderBackupList(); if (countSpan) countSpan.textContent = backupFiles.length; } })
        .catch(err => { if (err.name !== 'AbortError') container.innerHTML = '加载失败'; });
}

function renderBackupList() {
    const container = document.getElementById('backupFilesList'); if (!container || backupFiles.length === 0) { container.innerHTML = '暂无备份'; return; }
    const musicBackups = backupFiles.filter(f => f.name.includes('music_data.json'));
    const voteBackups = backupFiles.filter(f => f.name.includes('vote_records.txt'));
    let html = '';
    if (musicBackups.length > 0) {
        html += '<div style="margin-bottom:15px;"><b>音乐备份</b>';
        musicBackups.forEach(f => { html += `<div style="font-size:13px;">${f.name} (${f.date})</div>`; });
        html += '</div>';
    }
    if (voteBackups.length > 0) {
        html += '<div><b>投票备份</b>';
        voteBackups.forEach(f => { html += `<div style="font-size:13px;">${f.name} (${f.date})</div>`; });
        html += '</div>';
    }
    container.innerHTML = html;
}

function deleteAllBackups() {
    if (backupFiles.length === 0) return alert('App没有备份文件需要删除');
    if (!confirm('确定删除所有备份？')) return;
    if (prompt('请输入 "DELETE" 确认：') !== 'DELETE') return;
    const pwd = prompt('请输入密码确认：'); if (!pwd) return;
    
    fetch(VOTE_API_BASE + 'backup_manager.php?action=delete_all', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ password: pwd }) }).then(res => res.json()).then(data => { if(data.success) { alert('清理完毕'); loadBackupList(); } });
}

function showRestoreConfirm() {
    if (backupFiles.length === 0) return alert('没有备份文件可用');
    if (!confirm('确定还原最新数据？')) return;
    if (prompt('请输入 "RESTORE" 确认：') !== 'RESTORE') return;
    const pwd = prompt('请输入密码：'); if (!pwd) return;
    
    fetch(VOTE_API_BASE + 'backup_manager.php?action=restore', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ password: pwd }) }).then(res => res.json()).then(data => {
        if(data.success) { alert('还原成功'); loadBackupList(); loadMusicList(); loadVoteRecords(); }
    });
}

function loadSystemSettings() {
    fetch(API_BASE + '?action=get_admin_data')
        .then(res => res.json())
        .then(res => { if(res.code === 401) { location.reload(); return; } const aiToggle = document.getElementById('aiModeToggle'); if (aiToggle) aiToggle.checked = res.data.ai_reason_mode === true; });
}
function toggleAiMode(isChecked) {
    fetch(API_BASE, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'toggle_ai_mode', mode: isChecked }) });
}
function clearAllData() {
    if(!confirm("【危险】确定清空所有投稿和反馈吗？不可逆！")) return;
    const pwd = prompt('请输入密码：'); if (!pwd) return;
    fetch(API_BASE, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'clear_all_data', password: pwd }) }).then(res => res.json()).then(() => { alert('数据已清空'); loadAllData(); });
}

let changelogList = [];
function loadChangelogList() {
    fetch(API_BASE + '?action=get_changelog')
        .then(res => res.json())
        .then(data => { if (data.success) { changelogList = data.data || []; renderChangelogList(); } });
}

function renderChangelogList() {
    const container = document.getElementById('changelog-admin-list'); if (changelogList.length === 0) { container.innerHTML = '暂无日志'; return; }
    const typeNames = { 'update': '功能更新', 'fix': '问题修复', 'optimize': '优化改进', 'security': '安全更新' };
    const fragment = document.createDocumentFragment();
    changelogList.forEach(item => {
        const div = document.createElement('div'); div.style.cssText = 'background:white;border:1px solid #e0e0e0;border-radius:8px;padding:15px;margin-bottom:15px;';
        div.innerHTML = `
            <div style="display:flex;justify-content:space-between;">
                <div><b>[${typeNames[item.type] || '更新'}]</b> <b>${item.version}</b> <small>${item.date}</small></div>
                <div>
                    <button class="btn btn-primary btn-small" onclick="editChangelog('${item.id}')">编辑</button>
                    <button class="btn btn-danger btn-small" onclick="deleteChangelog('${item.id}')">删除</button>
                </div>
            </div>
            <div style="font-weight:600;margin:10px 0;">${item.title}</div>
            <div style="white-space:pre-wrap;font-size:14px;">${item.content}</div>`;
        fragment.appendChild(div);
    });
    container.innerHTML = ''; container.appendChild(fragment);
}

function showAddChangelogForm() {
    const modal = document.getElementById('changelog-form-modal'); modal.querySelector('h3').innerHTML = '<i class="fas fa-plus"></i> 添加更新日志';
    document.getElementById('changelog-version').value = ''; document.getElementById('changelog-title').value = ''; document.getElementById('changelog-content').value = '';
    document.getElementById('changelog-date').value = new Date().toISOString().split('T')[0]; modal.dataset.editId = ''; modal.style.display = 'flex';
}
function editChangelog(id) {
    const item = changelogList.find(c => c.id === id); if (!item) return;
    const modal = document.getElementById('changelog-form-modal'); modal.querySelector('h3').innerHTML = '<i class="fas fa-edit"></i> 编辑更新日志';
    document.getElementById('changelog-version').value = item.version || ''; document.getElementById('changelog-title').value = item.title || '';
    document.getElementById('changelog-content').value = item.content || ''; document.getElementById('changelog-date').value = item.date || '';
    modal.dataset.editId = id; modal.style.display = 'flex';
}
function hideAddChangelogForm() { document.getElementById('changelog-form-modal').style.display = 'none'; }

async function saveChangelog() {
    const version = document.getElementById('changelog-version').value.trim(); const date = document.getElementById('changelog-date').value;
    const title = document.getElementById('changelog-title').value.trim(); const type = document.getElementById('changelog-type').value;
    const content = document.getElementById('changelog-content').value.trim(); if (!version || !title || !content) return alert('请填全信息');
    
    const pwd = prompt('请输入密码验证：'); if (!pwd) return;
    const editId = document.getElementById('changelog-form-modal').dataset.editId;
    const method = editId ? 'PUT' : 'POST';
    const payload = { password: pwd, version, date, title, type, content }; if (editId) payload.id = editId;
    
    fetch('../submit/changelog_api.php', { method, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) })
    .then(res => res.json()).then(data => { if(data.success) { hideAddChangelogForm(); loadChangelogList(); } else alert(data.message); });
}

async function deleteChangelog(id) {
    if (!confirm('确定删除？')) return;
    const pwd = prompt('验证密码：'); if (!pwd) return;
    fetch('../submit/changelog_api.php', { method: 'DELETE', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id, password: pwd }) }).then(res => res.json()).then(data => { if(data.success) loadChangelogList(); });
}

// ==================== 新增：交接面板前端数据绑定与防越权校验 ====================
function loadTransferModuleData() {
    fetch(AUTH_API + '?action=check_login')
        .then(res => res.json())
        .then(data => {
            if (data.logged_in) {
                document.getElementById('transfer-current-user').textContent = data.username;
                const remainDaysDisplay = document.getElementById('transfer-remain-days');
                
                // 前端联动校验：如果是 temp 临时部员账户，强制永久阻断并锁死转让面板，不留任何漏洞
                if (data.role === 'temp') {
                    remainDaysDisplay.textContent = '暂无任职期（部员账号）';
                    remainDaysDisplay.style.color = 'var(--accent)';
                    document.getElementById('transfer-form-area').style.display = 'none';
                    document.getElementById('transfer-lock-area').style.display = 'block';
                    document.getElementById('transfer-lock-reason').textContent = '越权警告：您的身份为临时培训部员，暂无独立账号的权属转让继承资质！';
                    return;
                }

                let remainDays = 999;
                let isInfinite = true;
                if (data.expires_at) {
                    const remainSec = data.expires_at - Math.floor(Date.now() / 1000);
                    remainDays = Math.ceil(remainSec / 86400);
                    isInfinite = false;
                }
                
                if (isInfinite) {
                    remainDaysDisplay.textContent = '永久有效';
                    remainDaysDisplay.style.color = 'var(--success)';
                    document.getElementById('transfer-form-area').style.display = 'none';
                    document.getElementById('transfer-lock-area').style.display = 'block';
                    document.getElementById('transfer-lock-reason').textContent = '当前主账户级别为系统永久有效，不满足小于 90 天的换届交接拦截标准。';
                } else {
                    remainDaysDisplay.textContent = remainDays + ' 天';
                    if (remainDays < 90) {
                        remainDaysDisplay.style.color = 'var(--accent)';
                        document.getElementById('transfer-form-area').style.display = 'block';
                        document.getElementById('transfer-lock-area').style.display = 'none';
                    } else {
                        remainDaysDisplay.style.color = 'var(--primary)';
                        document.getElementById('transfer-form-area').style.display = 'none';
                        document.getElementById('transfer-lock-area').style.display = 'block';
                        document.getElementById('transfer-lock-reason').textContent = `当前账号剩余任职期限为 ${remainDays} 天（大/等于 90 天），系统权限锁定中，禁止执行提前交接。`;
                    }
                }
            }
        });
}

function executeAccountTransfer() {
    const successor = document.getElementById('successor-username').value.trim();
    if (!successor) return alert('请输入下一届继任部长的账号名称！');
    
    if (!confirm(`【警告：管理权属交接确认】\n\n您正在移交核心管理权属给继任部长：[ ${successor} ]。\n\n一旦确认：\n1. 您的当前部长账号将立刻被系统永久销毁并除名。\n2. 您将立刻退出后台，失去系统的一切访问控制权。\n3. 系统将自动为新部长下发初始密码为 111111 的强制改密账户。\n\n是否立即执行？`)) {
        return;
    }
    
    fetch(AUTH_API + '?action=transfer_account', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ successor: successor })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(`🎉 权属交接大功告成！\n\n新一届管理员账号 [${successor}] 已成功接管系统。本任期服务结束，感谢您对数字平台的卓越贡献！`);
            location.reload();
        } else {
            alert('转让失败：' + data.message);
        }
    })
    .catch(() => alert('网络连接异常，交接失败'));
}

const originalLoadModuleData = loadModuleData;
loadModuleData = function(moduleId) {
    originalLoadModuleData(moduleId);
    if (moduleId === 'vote-reset') setTimeout(() => { loadAudioFilesList(); }, 100);
    if (moduleId === 'account-mgmt') setTimeout(() => { loadSubAccounts(); }, 100);
    if (moduleId === 'account-transfer') setTimeout(() => { loadTransferModuleData(); }, 100);
    if (moduleId === 'settings-announcement') { if (!richEditorInitialized) initRichEditor(); loadAnnouncement(); }
};

window.addEventListener('DOMContentLoaded', function() {
    checkLoginStatus();
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function(event) {
        const now = Date.now(); if (now - lastTouchEnd <= 350) event.preventDefault(); lastTouchEnd = now;
    }, { passive: false });
    
    document.addEventListener('contextmenu', function(e) { e.preventDefault(); });
    
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.preview-song-btn');
        if (btn) window.previewAdminSong(btn.getAttribute('data-song-id'), '', btn.getAttribute('data-container-id'));
    });
});