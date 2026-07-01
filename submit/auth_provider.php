<?php
declare(strict_types=1);
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Strict');
date_default_timezone_set('Asia/Shanghai');
session_start();

define('ACCOUNTS_FILE', __DIR__ . '/accounts.json');
header('Content-Type: application/json; charset=utf-8');

function loadAccounts(): array {
    if (!file_exists(ACCOUNTS_FILE)) {
        $defaultHash = password_hash(hash('sha256', 'admin123456'), PASSWORD_BCRYPT);
        $initialData = [
            'superadmin' => [
                'username' => 'superadmin',
                'password' => $defaultHash,
                'role' => 'super',
                'is_first_login' => true,
                'expires_at' => null,
                'created_by' => 'system'
            ]
        ];
        file_put_contents(ACCOUNTS_FILE, json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $initialData;
    }
    $fp = fopen(ACCOUNTS_FILE, 'r');
    if ($fp) {
        flock($fp, LOCK_SH);
        $content = file_get_contents(ACCOUNTS_FILE);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($content ?: '[]', true) ?: [];
    }
    return [];
}

function saveAccounts(array $accounts): bool {
    $fp = fopen(ACCOUNTS_FILE, 'w+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        }
        fclose($fp);
    }
    return false;
}

function checkExpiration(array &$accounts): void {
    $changed = false;
    $now = time();
    foreach ($accounts as $username => $info) {
        if ($info['expires_at'] !== null && $now > $info['expires_at']) {
            unset($accounts[$username]);
            $changed = true;
        }
    }
    if ($changed) {
        saveAccounts($accounts);
    }
}

$accounts = loadAccounts();
checkExpiration($accounts);

if (isset($_SESSION['username'])) {
    $currentUser = $_SESSION['username'];
    if (!isset($accounts[$currentUser])) {
        unset($_SESSION['admin_logged_in']);
        session_destroy();
        echo json_encode(['status' => 'expired', 'message' => '账户已过期或被删除']);
        exit;
    }
    $_SESSION['admin_logged_in'] = true;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $input['action'] ?? '';

switch ($action) {
    case 'check_login':
        if (isset($_SESSION['username'])) {
            $user = $accounts[$_SESSION['username']];
            $_SESSION['admin_logged_in'] = true; 
            echo json_encode([
                'logged_in' => true,
                'username' => $_SESSION['username'],
                'role' => $user['role'],
                'is_first_login' => $user['is_first_login'],
                'expires_at' => $user['expires_at'] ?? null
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;

    case 'login':
        $username = trim($input['username'] ?? '');
        $passwordHash = $input['password'] ?? ''; 
        if (!isset($accounts[$username]) || !password_verify($passwordHash, $accounts[$username]['password'])) {
            echo json_encode(['status' => 'error', 'message' => '账号或密码错误']);
            exit;
        }
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $accounts[$username]['role'];
        $_SESSION['admin_logged_in'] = true; 
        echo json_encode([
            'status' => 'success',
            'role' => $accounts[$username]['role'],
            'is_first_login' => $accounts[$username]['is_first_login']
        ]);
        break;

    case 'logout':
        unset($_SESSION['admin_logged_in']);
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    case 'change_password':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['status' => 'error', 'message' => '未登录']);
            exit;
        }
        $newPasswordHash = $input['new_password'] ?? '';
        if (strlen($newPasswordHash) !== 64) {
            echo json_encode(['status' => 'error', 'message' => '非法的加密凭证']);
            exit;
        }
        $accounts[$_SESSION['username']]['password'] = password_hash($newPasswordHash, PASSWORD_BCRYPT);
        $accounts[$_SESSION['username']]['is_first_login'] = false;
        saveAccounts($accounts);
        echo json_encode(['status' => 'success']);
        break;

    case 'list_accounts':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['status' => 'error', 'message' => '未登录']);
            exit;
        }
        $role = $_SESSION['role'];
        $list = [];
        foreach ($accounts as $name => $info) {
            if ($role === 'super' || ($role === 'admin' && $info['created_by'] === $_SESSION['username'])) {
                $list[] = [
                    'username' => $info['username'],
                    'role' => $info['role'],
                    'is_first_login' => $info['is_first_login'],
                    'expires_at' => $info['expires_at'] ? date('Y-m-d H:i:s', $info['expires_at']) : '永久有效',
                    'created_by' => $info['created_by']
                ];
            }
        }
        echo json_encode(['status' => 'success', 'data' => $list]);
        break;

    case 'add_account':
        if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['super', 'admin'], true)) {
            echo json_encode(['status' => 'error', 'message' => '未登录或权限不足']);
            exit;
        }
        $newUsername = trim($input['username'] ?? '');
        $newRole = $input['role'] ?? 'temp';
        $days = (int)($input['days'] ?? 1);

        if (isset($accounts[$newUsername])) {
            echo json_encode(['status' => 'error', 'message' => '账号名已存在']);
            exit;
        }
        if ($_SESSION['role'] === 'admin') {
            $newRole = 'temp';
            if ($days > 3) $days = 3; 
        }
        
        $clientSimulatedHash = hash('sha256', '111111');
        $accounts[$newUsername] = [
            'username' => $newUsername,
            'password' => password_hash($clientSimulatedHash, PASSWORD_BCRYPT),
            'role' => $newRole,
            'is_first_login' => true,
            'expires_at' => $days > 0 ? time() + ($days * 86400) : null,
            'created_by' => $_SESSION['username']
        ];
        saveAccounts($accounts);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_account':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['status' => 'error', 'message' => '未登录']);
            exit;
        }
        $target = $input['username'] ?? '';
        if (!isset($accounts[$target]) || $target === 'superadmin') {
            echo json_encode(['status' => 'error', 'message' => '目标账户不存在或无法删除']);
            exit;
        }
        if ($_SESSION['role'] === 'admin' && $accounts[$target]['created_by'] !== $_SESSION['username']) {
            echo json_encode(['status' => 'error', 'message' => '权限不足']);
            exit;
        }
        unset($accounts[$target]);
        saveAccounts($accounts);
        echo json_encode(['status' => 'success']);
        break;

    case 'reset_password':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['status' => 'error', 'message' => '未登录']);
            exit;
        }
        $target = $input['username'] ?? '';
        if (!isset($accounts[$target])) {
            echo json_encode(['status' => 'error', 'message' => '目标账户不存在']);
            exit;
        }
        if ($_SESSION['role'] === 'admin' && $accounts[$target]['created_by'] !== $_SESSION['username']) {
            echo json_encode(['status' => 'error', 'message' => '权限不足']);
            exit;
        }
        $clientSimulatedHash = hash('sha256', '111111');
        $accounts[$target]['password'] = password_hash($clientSimulatedHash, PASSWORD_BCRYPT);
        $accounts[$target]['is_first_login'] = true;
        saveAccounts($accounts);
        echo json_encode(['status' => 'success']);
        break;

    case 'transfer_account':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['status' => 'error', 'message' => '未登录']);
            exit;
        }
        $currentUser = $_SESSION['username'];
        if (!isset($accounts[$currentUser])) {
            echo json_encode(['status' => 'error', 'message' => '当前账户不存在']);
            exit;
        }

        $creator = $accounts[$currentUser]['created_by'] ?? '';
        if (!in_array($creator, ['superadmin', 'system'], true)) {
            echo json_encode(['status' => 'error', 'message' => '越权拦截：当前账户属于下发的临时/培训账号，无权执行换届权属转让操作！']);
            exit;
        }

        if ($accounts[$currentUser]['expires_at'] === null) {
            echo json_encode(['status' => 'error', 'message' => '当前账户剩余任职期限大于 90 天（永久有效），系统权限锁定禁止转让。']);
            exit;
        }
        $remainTime = $accounts[$currentUser]['expires_at'] - time();
        if ($remainTime >= 90 * 86400) {
            echo json_encode(['status' => 'error', 'message' => '当前账户剩余任职期限大于 90 天，权限锁定禁止提前转让']);
            exit;
        }
        if ($remainTime <= 0) {
            echo json_encode(['status' => 'error', 'message' => '当前账户已过期']);
            exit;
        }
        $successor = trim($input['successor'] ?? '');
        if ($successor === '') {
            echo json_encode(['status' => 'error', 'message' => '继任者账号名称不可为空']);
            exit;
        }
        if (isset($accounts[$successor])) {
            echo json_encode(['status' => 'error', 'message' => '该账号名称已存在']);
            exit;
        }

        $clientSimulatedHash = hash('sha256', '111111');
        $accounts[$successor] = [
            'username' => $successor,
            'password' => password_hash($clientSimulatedHash, PASSWORD_BCRYPT),
            'role' => 'admin', 
            'is_first_login' => true,
            'expires_at' => time() + (365 * 86400), 
            'created_by' => 'superadmin' 
        ];
        unset($accounts[$currentUser]); 
        saveAccounts($accounts);
        unset($_SESSION['admin_logged_in']);
        session_destroy(); 
        echo json_encode(['status' => 'success']);
        break;
        
    default:
        echo json_encode(['status' => 'error', 'message' => '未知的请求动作']);
        exit;
}
?>