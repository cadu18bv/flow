<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('flow_observatory');
    session_start();
}

function flow_runtime_dir() {
    $preferred = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
    if (is_dir($preferred)) {
        return $preferred;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats';
}

function flow_auth_db_path() {
    $candidates = array(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'flow_auth.db',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats' . DIRECTORY_SEPARATOR . 'flow_auth.db',
    );

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function flow_auth_ensure_schema($db) {
    if (!$db instanceof SQLite3) {
        return;
    }

    $db->exec('CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        role TEXT,
        action TEXT NOT NULL,
        target TEXT,
        details TEXT,
        ip_address TEXT,
        created_at TEXT NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at DESC)');
}

function flow_auth_connect() {
    static $db = null;

    if ($db instanceof SQLite3) {
        return $db;
    }

    $path = flow_auth_db_path();
    if (!file_exists($path)) {
        return null;
    }

    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(3000);
    flow_auth_ensure_schema($db);
    return $db;
}

function flow_auth_roles() {
    return array('master', 'admin', 'read');
}

function flow_auth_set_flash($message, $type = 'info') {
    $_SESSION['flow_flash'] = array(
        'message' => $message,
        'type' => $type,
    );
}

function flow_auth_take_flash() {
    if (!isset($_SESSION['flow_flash'])) {
        return null;
    }

    $flash = $_SESSION['flow_flash'];
    unset($_SESSION['flow_flash']);
    return $flash;
}

function flow_auth_find_user($username) {
    $db = flow_auth_connect();
    if (!$db) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, username, role, password_hash, is_active, last_login_at FROM users WHERE username = :username LIMIT 1');
    $stmt->bindValue(':username', trim((string)$username), SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

function flow_auth_current_user() {
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (empty($_SESSION['flow_user'])) {
        $user = null;
        return $user;
    }

    $dbUser = flow_auth_find_user($_SESSION['flow_user']);
    if (!$dbUser || (int)$dbUser['is_active'] !== 1) {
        unset($_SESSION['flow_user']);
        $user = null;
        return $user;
    }

    $user = $dbUser;
    return $user;
}

function flow_auth_is_logged_in() {
    return flow_auth_current_user() !== null;
}

function flow_auth_has_role($roles) {
    $user = flow_auth_current_user();
    if (!$user) {
        return false;
    }

    $roles = (array)$roles;
    return in_array($user['role'], $roles, true);
}

function flow_auth_require_login() {
    if (flow_auth_is_logged_in()) {
        return;
    }

    $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index.php';
    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

function flow_auth_require_role($roles) {
    flow_auth_require_login();
    if (flow_auth_has_role($roles)) {
        return;
    }

    http_response_code(403);
    die('Acesso negado.');
}

function flow_auth_login($username, $password) {
    $user = flow_auth_find_user($username);
    if (!$user || (int)$user['is_active'] !== 1) {
        return false;
    }

    if (!password_verify((string)$password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['flow_user'] = $user['username'];

    $db = flow_auth_connect();
    if ($db) {
        $stmt = $db->prepare('UPDATE users SET last_login_at = datetime(\'now\') WHERE id = :id');
        $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
        $stmt->execute();
    }

    return true;
}

function flow_auth_logout() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function flow_auth_client_ip() {
    $keys = array(
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    );

    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $raw = trim((string)$_SERVER[$key]);
        if ($raw === '') {
            continue;
        }

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $raw);
            $raw = trim((string)$parts[0]);
        }

        return $raw;
    }

    return 'desconhecido';
}

function flow_auth_audit($action, $details = '', $target = '', $actorUsername = null, $actorRole = null) {
    $db = flow_auth_connect();
    if (!$db) {
        return false;
    }

    $currentUser = flow_auth_current_user();
    if ($actorUsername === null && $currentUser) {
        $actorUsername = $currentUser['username'];
    }
    if ($actorRole === null && $currentUser) {
        $actorRole = $currentUser['role'];
    }

    $stmt = $db->prepare('INSERT INTO audit_logs (username, role, action, target, details, ip_address, created_at)
        VALUES (:username, :role, :action, :target, :details, :ip_address, datetime(\'now\'))');
    $stmt->bindValue(':username', $actorUsername !== null ? trim((string)$actorUsername) : null, SQLITE3_TEXT);
    $stmt->bindValue(':role', $actorRole !== null ? trim((string)$actorRole) : null, SQLITE3_TEXT);
    $stmt->bindValue(':action', trim((string)$action), SQLITE3_TEXT);
    $stmt->bindValue(':target', trim((string)$target), SQLITE3_TEXT);
    $stmt->bindValue(':details', trim((string)$details), SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', flow_auth_client_ip(), SQLITE3_TEXT);

    return (bool)$stmt->execute();
}

function flow_auth_recent_audit_logs($limit = 50) {
    $db = flow_auth_connect();
    if (!$db) {
        return array();
    }

    $limit = max(1, min(200, (int)$limit));
    $result = $db->query('SELECT id, username, role, action, target, details, ip_address, created_at
        FROM audit_logs
        ORDER BY id DESC
        LIMIT ' . $limit);

    $rows = array();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }

    return $rows;
}

function flow_auth_can_manage_target($targetRole) {
    $current = flow_auth_current_user();
    if (!$current) {
        return false;
    }

    if ($current['role'] === 'master') {
        return true;
    }

    if ($current['role'] === 'admin') {
        return $targetRole !== 'master';
    }

    return false;
}

function flow_auth_all_users() {
    $db = flow_auth_connect();
    if (!$db) {
        return array();
    }

    $result = $db->query('SELECT id, username, role, is_active, created_at, updated_at, last_login_at FROM users ORDER BY CASE role WHEN "master" THEN 1 WHEN "admin" THEN 2 ELSE 3 END, username');
    $rows = array();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }
    return $rows;
}
