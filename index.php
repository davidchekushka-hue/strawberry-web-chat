<?php
declare(strict_types=1);

/* ============================================================
 *  Raspberry Web Chat — single file
 *  By Larp Dev · build 2000.77
 *  PHP 8+, SQLite. Запуск: php -S 0.0.0.0:8080
 * ============================================================ */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    if (!is_dir($dir) || !is_writable($dir)) {
        if (isset($_GET['action'])) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'data/ недоступна для записи']);
            exit;
        }
        http_response_code(500);
        echo 'Создайте папку data/ рядом с index.php и дайте права 775.';
        exit;
    }

    $pdo = new PDO('sqlite:' . $dir . '/chat.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');

    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY,
            nickname    TEXT NOT NULL UNIQUE,
            tag         TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL,
            last_seen   INTEGER NOT NULL,
            is_admin    INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token       TEXT PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            created_at  INTEGER NOT NULL,
            last_seen   INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS messages (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            body        TEXT NOT NULL,
            created_at  INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_messages_id   ON messages(id);
        CREATE INDEX IF NOT EXISTS idx_messages_user ON messages(user_id);
        CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
    SQL);

    $pdo->exec('DELETE FROM users WHERE id = 7777777');
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ok(array $data = []): void  { json_out(['ok' => true]  + $data); }
function fail(string $m, int $c = 400): void { json_out(['ok' => false, 'error' => $m], $c); }

function current_user(): ?array {
    $token = $_COOKIE['rwc_session'] ?? '';
    if (!is_string($token) || $token === '') return null;

    $db = db();
    $stmt = $db->prepare(
        'SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?'
    );
    $stmt->execute([$token]);
    $u = $stmt->fetch();
    if (!$u) return null;

    $now = time();
    $db->prepare('UPDATE sessions SET last_seen = ? WHERE token = ?')->execute([$now, $token]);
    $db->prepare('UPDATE users    SET last_seen = ? WHERE id = ?')->execute([$now, $u['id']]);
    return $u;
}

function user_public(array $u): array {
    return [
        'id'       => (int)$u['id'],
        'nickname' => (string)$u['nickname'],
        'tag'      => (string)$u['tag'],
    ];
}
function message_public(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'user_id'    => (int)$r['user_id'],
        'nickname'   => (string)$r['nickname'],
        'tag'        => (string)$r['tag'],
        'body'       => (string)$r['body'],
        'created_at' => (int)$r['created_at'],
    ];
}
function next_uid(PDO $db): int {
    for ($i = 0; $i < 200; $i++) {
        $id = random_int(1000000, 9999999);
        $stmt = $db->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) return $id;
    }
    $max = (int)$db->query('SELECT COALESCE(MAX(id), 1000000) FROM users')->fetchColumn();
    return max($max + 1, 1000001);
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
    try {
        switch ($action) {
            case 'login':    api_login();    break;
            case 'me':       api_me();       break;
            case 'logout':   api_logout();   break;
            case 'send':     api_send();     break;
            case 'messages': api_messages(); break;
            case 'history':  api_history();  break;
            case 'online':   api_online();   break;
            case 'ping':     api_ping();     break;
            default:         fail('unknown action', 404);
        }
    } catch (Throwable $e) {
        fail($e->getMessage(), 500);
    }
    exit;
}

function api_ping(): void { ok(['time' => time(), 'php' => PHP_VERSION, 'author' => 'Larp Dev']); }

function api_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);

    $nickname = trim((string)($_POST['nickname'] ?? ''));
    if ($nickname === '') fail('Введите ник');
    if (mb_strlen($nickname) > 24) fail('Ник слишком длинный (макс 24)');
    if (!preg_match('/^[\p{L}\p{N}_\-\. ]+$/u', $nickname)) {
        fail('Недопустимые символы. Разрешены буквы, цифры, пробел, . _ -');
    }
    $nickname = preg_replace('/\s+/u', ' ', $nickname);

    $db = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE nickname = ?');
    $stmt->execute([$nickname]);
    $user = $stmt->fetch();

    $isNew = false;
    if (!$user) {
        $id  = next_uid($db);
        $tag = '#U' . $id;
        $now = time();
        $db->prepare(
            'INSERT INTO users (id, nickname, tag, created_at, last_seen, is_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([$id, $nickname, $tag, $now, $now]);

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        $isNew = true;
    }

    $token = bin2hex(random_bytes(24));
    $now = time();
    $db->prepare('INSERT INTO sessions (token, user_id, created_at, last_seen) VALUES (?, ?, ?, ?)')
       ->execute([$token, $user['id'], $now, $now]);

    setcookie('rwc_session', $token, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ok(['user' => user_public($user), 'is_new' => $isNew]);
}

function api_me(): void {
    $u = current_user();
    if (!$u) fail('Нет сессии', 401);
    ok(['user' => user_public($u)]);
}

function api_logout(): void {
    $token = $_COOKIE['rwc_session'] ?? '';
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }
    setcookie('rwc_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ok();
}

function api_send(): void {
    $u = current_user();
    if (!$u) fail('Не авторизован', 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);

    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') fail('Пустое сообщение');
    if (mb_strlen($body) > 2000) fail('Слишком длинное (макс 2000)');

    $now = time();
    $db  = db();
    $db->prepare('INSERT INTO messages (user_id, body, created_at) VALUES (?, ?, ?)')
       ->execute([$u['id'], $body, $now]);
    $id = (int)$db->lastInsertId();

    ok(['message' => [
        'id'         => $id,
        'user_id'    => (int)$u['id'],
        'nickname'   => (string)$u['nickname'],
        'tag'        => (string)$u['tag'],
        'body'       => $body,
        'created_at' => $now,
    ]]);
}

function api_messages(): void {
    $u = current_user();
    if (!$u) fail('Не авторизован', 401);

    $since = (int)($_GET['since'] ?? 0);

    $stmt = db()->prepare(
        'SELECT m.id, m.user_id, m.body, m.created_at, u.nickname, u.tag
         FROM messages m JOIN users u ON u.id = m.user_id
         WHERE m.id > ? ORDER BY m.id ASC LIMIT 200'
    );
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();

    ok(['messages' => array_map('message_public', $rows), 'now' => time()]);
}

function api_history(): void {
    $u = current_user();
    if (!$u) fail('Не авторизован', 401);

    $before = (int)($_GET['before'] ?? 0);
    if ($before <= 0) $before = PHP_INT_MAX;
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));

    $stmt = db()->prepare(
        'SELECT m.id, m.user_id, m.body, m.created_at, u.nickname, u.tag
         FROM messages m JOIN users u ON u.id = m.user_id
         WHERE m.id < ? ORDER BY m.id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $before, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll());
    ok(['messages' => array_map('message_public', $rows)]);
}

function api_online(): void {
    $threshold = time() - 30;
    $stmt = db()->prepare(
        'SELECT id, nickname, tag FROM users
         WHERE last_seen > ? ORDER BY nickname COLLATE NOCASE'
    );
    $stmt->execute([$threshold]);
    ok(['users' => array_map('user_public', $stmt->fetchAll())]);
}

$me = current_user();
$isChat = $me !== null;

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Raspberry Web Chat<?= $isChat ? '' : ' — Вход' ?> · By Larp Dev</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; font-family: Tahoma, "Segoe UI", Verdana, sans-serif; font-size: 11px; color: #000; }

.xp-titlebar {
  display: flex; align-items: center; height: 28px; padding: 0 3px 0 6px;
  background: linear-gradient(180deg,#0058ee 0%,#3a93ff 8%,#0058ee 40%,#0058ee 88%,#0046c8 100%);
  color: #fff; font-weight: bold; font-size: 12px; text-shadow: 1px 1px 1px rgba(0,0,0,.55);
  border-radius: 7px 7px 0 0; user-select: none;
}
.xp-titlebar-icon { font-size: 14px; margin-right: 6px; }
.xp-titlebar-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.xp-titlebar-buttons { display: flex; gap: 2px; }
.xp-titlebar-buttons span {
  width: 21px; height: 21px; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(180deg,#4aa0ff,#0058ee); border: 1px solid #003ec8; border-radius: 3px;
  color: #fff; font-weight: bold; font-size: 12px; cursor: default; text-shadow: none;
}
.xp-titlebar-buttons .xp-btn-close { background: linear-gradient(180deg,#ff7043,#c02b00); border-color: #8b1e00; cursor: pointer; }

.xp-login-body { min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(180deg,#1c4e8c 0%,#4a8fd6 50%,#1c4e8c 100%); padding: 20px; }
.xp-login-window { width: 520px; max-width: 100%; background: #ece9d8; border: 1px solid #0831d9;
  border-radius: 8px 8px 0 0; box-shadow: 0 10px 40px rgba(0,0,0,.5); overflow: hidden; }
.xp-login-content { display: flex; padding: 16px; gap: 16px; }
.xp-login-left { width: 140px; display: flex; flex-direction: column; align-items: center;
  justify-content: center; background: linear-gradient(180deg,#3a93ff,#0058ee);
  border-radius: 4px; color: #fff; padding: 14px 10px; text-align: center; }
.xp-logo { font-size: 48px; line-height: 1; margin-bottom: 8px; }
.xp-brand { font-weight: bold; font-size: 13px; line-height: 1.3; }
.xp-brand-sub { font-size: 9px; opacity: .85; margin-top: 8px; }
.xp-brand-author { font-size: 10px; opacity: .95; margin-top: 6px; font-style: italic; font-weight: bold; letter-spacing: .5px; }
.xp-login-right { flex: 1; min-width: 0; }
.xp-login-hint { font-weight: bold; margin-bottom: 8px; }
.xp-label { display: block; margin-bottom: 3px; }
.xp-input { width: 100%; padding: 3px 5px; font: inherit; border: 2px inset #7f9db9; background: #fff; outline: none; }
.xp-input:focus { border-color: #3a93ff; }
.xp-login-note { color: #555; font-size: 10px; margin-top: 6px; line-height: 1.4; }
.xp-error { color: #c00; font-weight: bold; min-height: 14px; margin-top: 6px; }
.xp-login-buttons { margin-top: 10px; text-align: right; }

.xp-button { font: inherit; padding: 4px 14px; min-width: 80px;
  background: linear-gradient(180deg,#fdfdfd 0%,#ecebe6 45%,#d6d2c2 100%);
  border: 1px solid #003c74; border-radius: 3px; cursor: pointer; color: #000; }
.xp-button:hover  { background: linear-gradient(180deg,#fff 0%,#f3f2ee 45%,#dfdbc9 100%); }
.xp-button:active { background: linear-gradient(180deg,#d6d2c2 0%,#ecebe6 45%,#fdfdfd 100%); }
.xp-button-primary { font-weight: bold; box-shadow: 0 0 0 1px #fff inset; }

.xp-statusbar { display: flex; background: #ece9d8; border-top: 1px solid #a5a5a5; font-size: 11px; }
.xp-statusbar-cell { padding: 3px 8px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.xp-statusbar-cell + .xp-statusbar-cell { border-left: 1px solid #a5a5a5; }
.xp-statusbar-right { text-align: right; flex: 0 0 auto; }
.xp-statusbar-brand { color: #0058ee; font-weight: bold; font-style: italic; letter-spacing: .5px; }

.xp-desktop { min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(180deg,#1c4e8c 0%,#4a8fd6 50%,#1c4e8c 100%);
  padding: 20px 20px 46px 20px; overflow: hidden; }
.xp-chat-window { width: 900px; max-width: 100%; height: calc(100vh - 86px); max-height: 700px;
  display: flex; flex-direction: column; background: #ece9d8; border: 1px solid #0831d9;
  border-radius: 8px 8px 0 0; box-shadow: 0 10px 40px rgba(0,0,0,.5); overflow: hidden; }
.xp-menubar { display: flex; background: #ece9d8; padding: 2px 4px; border-bottom: 1px solid #d5d2c2; }
.xp-menu-item { padding: 2px 8px; cursor: default; user-select: none; }
.xp-menu-item:hover { background: #316ac5; color: #fff; }
.xp-chat-body { flex: 1; display: flex; min-height: 0; background: #fff; border: 2px inset #b8b4a4; margin: 4px; }

.xp-sidebar { width: 190px; flex: 0 0 190px; background: #f4f1e6; border-right: 1px solid #b8b4a4;
  padding: 4px; overflow-y: auto; }
.xp-panel { border: 1px solid #b8b4a4; background: #fff; }
.xp-panel-title { background: linear-gradient(180deg,#3a93ff,#0058ee); color: #fff;
  font-weight: bold; padding: 2px 6px; font-size: 11px; }
.xp-online-list { padding: 4px; }
.xp-online-user { display: flex; align-items: center; gap: 5px; padding: 2px 3px; border-radius: 2px; }
.xp-online-user:hover { background: #e6efff; }
.xp-dot { width: 8px; height: 8px; border-radius: 50%;
  background: radial-gradient(circle at 30% 30%,#b6ff8b,#2e9e00);
  box-shadow: 0 0 3px #2e9e00; flex: 0 0 auto; }
.xp-online-nick { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.xp-online-id { color: #888; font-size: 10px; }
.xp-empty { color: #888; padding: 4px; }

.xp-chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; background: #fff; position: relative; }
.xp-messages { flex: 1; overflow-y: auto; padding: 6px; background: #fff; font-size: 11px; }
.xp-msg { padding: 3px 5px; margin-bottom: 2px; border-bottom: 1px dotted #e0e0e0; }
.xp-msg-me { background: #f0f6ff; }
.xp-msg-head { display: flex; gap: 6px; align-items: baseline; margin-bottom: 1px; }
.xp-msg-nick { font-weight: bold; color: #0058ee; }
.xp-msg-me .xp-msg-nick { color: #b10000; }
.xp-msg-id { color: #888; font-size: 10px; }
.xp-msg-tag { color: #008844; font-size: 10px; }
.xp-msg-time { margin-left: auto; color: #999; font-size: 10px; }
.xp-msg-body { white-space: pre-wrap; word-wrap: break-word; padding-left: 2px; line-height: 1.45; font-size: 13px; }

.xp-composer { display: flex; gap: 4px; padding: 4px; border-top: 1px solid #b8b4a4; background: #ece9d8; }
.xp-textarea { flex: 1; font: inherit; padding: 4px; border: 2px inset #7f9db9; background: #fff;
  outline: none; resize: none; min-height: 40px; max-height: 120px; }
.xp-textarea:focus { border-color: #3a93ff; }
.xp-composer .xp-button { align-self: stretch; }
.xp-composer #send-btn { min-width: 100px; }
.xp-composer #emoji-btn { min-width: 40px; padding: 4px 8px; font-size: 16px; }

.xp-taskbar { position: fixed; left: 0; right: 0; bottom: 0; height: 30px;
  display: flex; align-items: center;
  background: linear-gradient(180deg,#245edb 0%,#3f8cf3 8%,#245edb 40%,#1941a5 100%);
  color: #fff; font-size: 11px; z-index: 4000; }
.xp-start-btn { font: inherit; font-weight: bold; height: 24px; margin-left: 2px; padding: 0 10px 0 6px;
  background: linear-gradient(180deg,#3a9e3a,#216f21); border: 1px solid #1a5c1a; border-radius: 3px;
  color: #fff; cursor: pointer; text-shadow: 1px 1px 1px rgba(0,0,0,.5); }
.xp-start-btn.active { background: linear-gradient(180deg,#216f21,#3a9e3a); }
.xp-taskbar-items { flex: 1; display: flex; gap: 3px; padding: 0 4px; min-width: 0; }
.xp-taskbar-item { padding: 3px 10px; background: linear-gradient(180deg,#3f8cf3,#1c4e8c);
  border: 1px solid #1941a5; border-radius: 2px; max-width: 220px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.xp-taskbar-tray { padding: 0 8px; border-left: 1px solid #1941a5; height: 100%;
  display: flex; align-items: center; font-weight: bold; user-select: none; }

.xp-disconnected #status { color: #c00; font-weight: bold; }

.xp-startmenu {
  position: fixed; left: 0; bottom: 30px; width: 260px;
  background: linear-gradient(180deg, #fff 0%, #d3e5fa 60%, #b0d0f5 100%);
  border: 1px solid #0831d9; border-radius: 8px 8px 0 0;
  box-shadow: 4px -4px 20px rgba(0,0,0,.55);
  z-index: 4500; overflow: hidden; display: none;
}
.xp-startmenu.open { display: block; }
.xp-startmenu-header {
  background: linear-gradient(180deg, #1c4e8c, #4a8fd6);
  color: #fff; padding: 8px 10px;
  display: flex; align-items: center; gap: 10px;
  font-weight: bold; font-size: 13px;
  text-shadow: 1px 1px 1px rgba(0,0,0,.55);
}
.xp-startmenu-avatar {
  width: 42px; height: 42px; background: #fff; border: 2px solid #fff;
  border-radius: 4px; display: flex; align-items: center; justify-content: center;
  font-size: 26px;
}
.xp-startmenu-user { display: flex; flex-direction: column; gap: 2px; }
.xp-startmenu-nick { font-size: 13px; }
.xp-startmenu-id { font-size: 10px; opacity: .85; font-weight: normal; }
.xp-startmenu-author { font-size: 9px; opacity: .8; font-style: italic; font-weight: normal; letter-spacing: .5px; }
.xp-startmenu-items { padding: 4px; }
.xp-startmenu-item {
  display: flex; align-items: center; gap: 10px; padding: 6px 8px;
  cursor: pointer; border-radius: 2px; font-size: 12px; color: #000;
}
.xp-startmenu-item:hover { background: #316ac5; color: #fff; }
.xp-startmenu-icon { width: 24px; text-align: center; font-size: 18px; }
.xp-startmenu-sep { height: 1px; background: #a5c4e8; margin: 4px 6px; }
.xp-startmenu-sep-strong { height: 2px; background: #fff; border-bottom: 1px solid #a5c4e8; margin: 4px 0; }

.xp-game-window {
  position: fixed; display: flex; flex-direction: column;
  background: #ece9d8; border: 1px solid #0831d9;
  border-radius: 8px 8px 0 0; box-shadow: 6px 6px 24px rgba(0,0,0,.6);
  overflow: hidden;
}
.xp-game-body { padding: 8px; overflow: auto; flex: 1; min-height: 0; }

.game-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 11px; }
.game-bar > button { margin-left: auto; }

.mines-grid { display: grid; gap: 1px; background: #808080; border: 2px inset #b8b4a4;
  padding: 2px; margin: 0 auto; width: max-content; }
.mines-cell {
  width: 26px; height: 26px;
  background: linear-gradient(180deg,#dfdfdf,#b8b8b8);
  border: 2px outset #f0f0f0;
  display: flex; align-items: center; justify-content: center;
  font-weight: bold; font-size: 13px; cursor: pointer; user-select: none;
}
.mines-cell.opened { border: 1px solid #b8b4a4; background: #d4d0c0; cursor: default; }
.mines-cell.opened[data-n="1"] { color: #0000ff; }
.mines-cell.opened[data-n="2"] { color: #008000; }
.mines-cell.opened[data-n="3"] { color: #ff0000; }
.mines-cell.opened[data-n="4"] { color: #000080; }
.mines-cell.opened[data-n="5"] { color: #800000; }
.mines-cell.opened[data-n="6"] { color: #008080; }
.mines-cell.opened[data-n="7"] { color: #000; }
.mines-cell.opened[data-n="8"] { color: #808080; }
.mines-cell.boom { background: #ff4040; }

.puzzle-grid { display: grid; grid-template-columns: repeat(4, 60px); gap: 3px;
  background: #808080; padding: 3px; border: 2px inset #b8b4a4;
  width: max-content; margin: 0 auto; }
.puzzle-cell {
  width: 60px; height: 60px;
  background: linear-gradient(180deg,#5a9cff,#1c4e8c); color: #fff;
  font-weight: bold; font-size: 22px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; border: 2px outset #7f9db9; user-select: none;
}
.puzzle-cell.empty { background: #d4d0c0; border: 2px inset #b8b4a4; cursor: default; }

.game-snake { display: flex; flex-direction: column; }
.snake-canvas {
  display: block; outline: none; border: 2px inset #b8b4a4;
  background: #000; align-self: center;
}

.fast-browser { display: flex; flex-direction: column; height: 100%; min-height: 0; }
.fb-toolbar {
  display: flex; gap: 3px; padding: 4px; background: #ece9d8;
  border-bottom: 1px solid #b8b4a4; align-items: center;
}
.fb-btn { min-width: 30px; padding: 3px 6px; font-size: 12px; }
.fb-url {
  flex: 1; font: inherit; padding: 3px 6px;
  border: 2px inset #7f9db9; background: #fff; outline: none; min-width: 100px;
}
.fb-url:focus { border-color: #3a93ff; }
.fb-go { min-width: 70px; }
.fb-frame-wrap {
  flex: 1; position: relative; background: #fff;
  border: 2px inset #b8b4a4; margin: 0 4px; min-height: 200px;
}
.fb-frame { width: 100%; height: 100%; border: 0; display: block; background: #fff; }
.fb-error {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 10px;
  background: #fff; color: #333; padding: 20px; text-align: center;
}
.fb-error[hidden] { display: none; }
.fb-error-icon { font-size: 42px; }
.fb-status {
  padding: 3px 8px; background: #ece9d8; border-top: 1px solid #a5a5a5;
  font-size: 10px; color: #555; overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; margin: 4px 4px 0;
}
.fb-status b { color: #0058ee; }
.fb-status a { color: #0058ee; }

.xp-dropdown {
  position: fixed;
  background: #ece9d8;
  border: 1px solid #003c74;
  box-shadow: 3px 3px 10px rgba(0,0,0,.35);
  min-width: 220px;
  padding: 3px;
  display: none;
  z-index: 5000;
  font-size: 11px;
}
.xp-dropdown.open { display: block; }
.xp-dropdown-title {
  padding: 4px 10px; color: #555; font-size: 10px; font-weight: bold;
  border-bottom: 1px solid #c0bcac; margin-bottom: 3px;
}
.xp-dropdown-item {
  display: flex; align-items: center; gap: 8px;
  padding: 5px 10px; cursor: pointer; border-radius: 2px;
}
.xp-dropdown-item:hover { background: #316ac5; color: #fff; }
.xp-dropdown-item.active::after { content: "✔"; margin-left: auto; font-weight: bold; }
.xp-dropdown-sep { height: 1px; background: #c0bcac; margin: 4px 6px; }
.xp-dropdown-swatch {
  width: 16px; height: 16px; border: 1px solid #555;
  border-radius: 2px; flex: 0 0 16px;
}

.help-content { font-size: 11px; line-height: 1.55; }
.help-content h3 {
  margin: 0 0 8px; font-size: 13px; color: #0058ee;
  border-bottom: 1px solid #c0bcac; padding-bottom: 4px;
}
.help-content h4 { margin: 12px 0 4px; font-size: 12px; color: #000080; }
.help-content p { margin: 4px 0; }
.help-content ul { margin: 4px 0; padding-left: 20px; }
.help-content li { margin: 2px 0; }
.help-content kbd {
  background: #fff; border: 1px solid #808080; border-bottom-width: 2px;
  border-radius: 3px; padding: 0 5px;
  font-family: Consolas, "Courier New", monospace; font-size: 10px; font-weight: bold;
}
.help-content code {
  background: #fff; border: 1px solid #b8b4a4; padding: 1px 4px;
  font-family: Consolas, monospace; font-size: 10px;
}
.help-tabs { display: flex; gap: 2px; border-bottom: 1px solid #a5a5a5; margin-bottom: 8px; }
.help-tab {
  padding: 4px 10px; background: #d4d0c0;
  border: 1px solid #a5a5a5; border-bottom: none;
  border-radius: 3px 3px 0 0; cursor: pointer; font-size: 11px;
}
.help-tab.active {
  background: #ece9d8; font-weight: bold;
  position: relative; top: 1px; border-bottom: 1px solid #ece9d8;
}
.help-panel { display: none; }
.help-panel.active { display: block; }

.emoji-panel {
  position: absolute;
  bottom: 54px;
  right: 8px;
  background: #ece9d8;
  border: 1px solid #003c74;
  box-shadow: 3px 3px 12px rgba(0,0,0,.4);
  padding: 6px;
  display: none;
  z-index: 3000;
  border-radius: 3px;
}
.emoji-panel.open { display: block; }
.emoji-title {
  font-size: 10px; color: #555; font-weight: bold;
  padding: 2px 4px 6px; border-bottom: 1px solid #c0bcac; margin-bottom: 4px;
}
.emoji-grid {
  display: grid;
  grid-template-columns: repeat(8, 26px);
  gap: 2px;
}
.emoji-cell {
  font-size: 18px; cursor: pointer; padding: 2px 0;
  border-radius: 3px; text-align: center; user-select: none;
  line-height: 1;
}
.emoji-cell:hover { background: #316ac5; }

.shutdown-overlay {
  position: fixed; inset: 0; z-index: 99999;
  display: none; align-items: center; justify-content: center; flex-direction: column;
  background: radial-gradient(circle at 50% 40%, #1e5fb8 0%, #0a2a5c 80%);
  color: #fff; font-family: Tahoma, "Segoe UI", Verdana, sans-serif;
  user-select: none;
}
.shutdown-overlay.active { display: flex; }
.shutdown-content { text-align: center; padding: 20px; }
.shutdown-logo { font-size: 84px; line-height: 1; margin-bottom: 26px;
  filter: drop-shadow(0 4px 12px rgba(0,0,0,.5)); }
.shutdown-title {
  font-size: 26px; font-weight: bold; letter-spacing: .5px;
  text-shadow: 1px 1px 3px rgba(0,0,0,.6);
}
.shutdown-sub {
  font-size: 12px; opacity: .75; margin-top: 12px; letter-spacing: 1px;
}
.shutdown-dots { margin-top: 22px; }
.shutdown-dots span {
  display: inline-block; width: 9px; height: 9px; border-radius: 50%;
  background: #fff; margin: 0 4px;
  animation: shutdown-dot 1.3s infinite ease-in-out;
}
.shutdown-dots span:nth-child(2) { animation-delay: .18s; }
.shutdown-dots span:nth-child(3) { animation-delay: .36s; }
@keyframes shutdown-dot {
  0%, 80%, 100% { opacity: .25; transform: scale(.8); }
  40%           { opacity: 1;   transform: scale(1); }
}
body.theme-raspberry .shutdown-overlay {
  background: radial-gradient(circle at 50% 40%, #4e8b2f 0%, #1a0f0f 85%);
}
body.theme-raspberry .shutdown-overlay .shutdown-title { color: #ffe0e0; }
body.theme-skype .shutdown-overlay {
  background: radial-gradient(circle at 50% 40%, #21a0f0 0%, #052e55 85%);
}
body.theme-95 .shutdown-overlay {
  background: #000; font-family: "MS Sans Serif", Tahoma, sans-serif;
}
body.theme-95 .shutdown-overlay .shutdown-logo { display: none; }
body.theme-95 .shutdown-overlay .shutdown-title {
  font-size: 22px; color: #ffa000; text-shadow: none; font-weight: bold;
}
body.theme-95 .shutdown-overlay .shutdown-sub { color: #ffcc00; opacity: 1; font-size: 12px; }
body.theme-95 .shutdown-dots span { background: #ffa000; }

/* THEME: RASPBERRY */
body.theme-raspberry .xp-titlebar {
  background: linear-gradient(180deg,#6a0f0f 0%,#d62828 8%,#8b1a1a 40%,#7a0d0d 88%,#4a0808 100%);
}
body.theme-raspberry .xp-titlebar-buttons span {
  background: linear-gradient(180deg,#d62828,#7a0d0d); border-color: #4a0808;
}
body.theme-raspberry .xp-titlebar-buttons .xp-btn-close {
  background: linear-gradient(180deg,#ff8a3d,#b83a00); border-color: #6a1a00;
}
body.theme-raspberry .xp-login-body,
body.theme-raspberry .xp-desktop {
  background: linear-gradient(180deg,#2d5a1e 0%,#4e8b2f 50%,#2d5a1e 100%);
}
body.theme-raspberry .xp-login-left { background: linear-gradient(180deg,#4e8b2f,#2d5a1e); }
body.theme-raspberry .xp-panel-title { background: linear-gradient(180deg,#4e8b2f,#2d5a1e); }
body.theme-raspberry .xp-taskbar {
  background: linear-gradient(180deg,#4a0808 0%,#a01818 8%,#6a0f0f 40%,#3d0606 100%);
}
body.theme-raspberry .xp-start-btn {
  background: linear-gradient(180deg,#4e8b2f,#2d5a1e); border-color: #1a3d10;
}
body.theme-raspberry .xp-start-btn.active { background: linear-gradient(180deg,#2d5a1e,#4e8b2f); }
body.theme-raspberry .xp-taskbar-item {
  background: linear-gradient(180deg,#a01818,#4a0808); border-color: #3d0606;
}
body.theme-raspberry .xp-startmenu {
  background: linear-gradient(180deg,#fff 0%,#f5e3e3 60%,#ebd0d0 100%);
}
body.theme-raspberry .xp-startmenu-header { background: linear-gradient(180deg,#6a0f0f,#a01818); }
body.theme-raspberry .xp-startmenu-avatar { background: #fff0f0; }
body.theme-raspberry .xp-startmenu-item:hover { background: #8b1a1a; }
body.theme-raspberry .xp-startmenu-sep { background: #e0b8b8; }
body.theme-raspberry .xp-startmenu-sep-strong { background: #fff; border-bottom-color: #e0b8b8; }
body.theme-raspberry .xp-startmenu-author { color: #8b1a1a; }
body.theme-raspberry .xp-msg-nick { color: #8b1a1a; }
body.theme-raspberry .xp-msg-me .xp-msg-nick { color: #2d5a1e; }
body.theme-raspberry .xp-msg-me { background: #eaf6e2; }
body.theme-raspberry .xp-online-user:hover { background: #f5e3e3; }
body.theme-raspberry .xp-online-id { color: #a05050; }
body.theme-raspberry .xp-button-primary {
  color: #fff; background: linear-gradient(180deg,#7ac143,#3d7a1a); border-color: #2d5a1e;
}
body.theme-raspberry .xp-statusbar-brand { color: #8b1a1a; }
body.theme-raspberry .xp-login-left { color: #fff; }

/* THEME: SKYPE */
body.theme-skype .xp-titlebar {
  background: linear-gradient(180deg,#063a6b 0%,#21a0f0 8%,#0e5ea8 40%,#0a4a85 88%,#052e55 100%);
}
body.theme-skype .xp-titlebar-buttons span {
  background: linear-gradient(180deg,#21a0f0,#0e5ea8); border-color: #063a6b;
}
body.theme-skype .xp-login-body,
body.theme-skype .xp-desktop {
  background: linear-gradient(180deg,#e8f4fd 0%,#bfe1f7 50%,#8bc9ef 100%);
}
body.theme-skype .xp-login-left { background: linear-gradient(180deg,#21a0f0,#0e5ea8); }
body.theme-skype .xp-panel-title { background: linear-gradient(180deg,#21a0f0,#0e5ea8); }
body.theme-skype .xp-taskbar {
  background: linear-gradient(180deg,#052e55 0%,#0e5ea8 8%,#0a4a85 40%,#031d3d 100%);
}
body.theme-skype .xp-start-btn {
  background: linear-gradient(180deg,#2ecc71,#1a9e50); border-color: #0f7a3d;
}
body.theme-skype .xp-start-btn.active { background: linear-gradient(180deg,#1a9e50,#2ecc71); }
body.theme-skype .xp-taskbar-item {
  background: linear-gradient(180deg,#0e5ea8,#052e55); border-color: #031d3d;
}
body.theme-skype .xp-sidebar { background: #eef7ff; border-right-color: #a0c8e8; }
body.theme-skype .xp-panel { border-color: #a0c8e8; }
body.theme-skype .xp-chat-body { border-color: #a0c8e8; }
body.theme-skype .xp-msg { border-bottom-color: #d8e8f5; }
body.theme-skype .xp-msg-me { background: #e0f0ff; }
body.theme-skype .xp-msg-nick { color: #0e5ea8; }
body.theme-skype .xp-msg-me .xp-msg-nick { color: #1a9e50; }
body.theme-skype .xp-online-user:hover { background: #e0f0ff; }
body.theme-skype .xp-startmenu {
  background: linear-gradient(180deg,#fff 0%,#e8f4fd 60%,#cbe4f5 100%);
}
body.theme-skype .xp-startmenu-header { background: linear-gradient(180deg,#21a0f0,#0e5ea8); }
body.theme-skype .xp-startmenu-avatar { background: #e8f4fd; }
body.theme-skype .xp-startmenu-item:hover { background: #21a0f0; }
body.theme-skype .xp-startmenu-sep { background: #b8d8ee; }
body.theme-skype .xp-startmenu-sep-strong { background: #fff; border-bottom-color: #b8d8ee; }
body.theme-skype .xp-startmenu-author { color: #0e5ea8; }
body.theme-skype .xp-composer { background: #e8f4fd; }
body.theme-skype .xp-menubar { background: #e8f4fd; border-bottom-color: #b8d8ee; }
body.theme-skype .xp-button-primary {
  color: #fff; background: linear-gradient(180deg,#21a0f0,#0e5ea8); border-color: #063a6b;
}
body.theme-skype .xp-statusbar-brand { color: #0e5ea8; }
body.theme-skype .xp-login-left { color: #fff; }

/* THEME: WINDOWS 95 */
body.theme-95 {
  font-family: "MS Sans Serif", Tahoma, Verdana, sans-serif;
  font-size: 11px;
}
body.theme-95 .xp-titlebar {
  height: 20px; padding: 1px 2px; background: #000080;
  border-radius: 0; font-size: 11px; font-weight: bold; text-shadow: none;
}
body.theme-95 .xp-titlebar-icon { font-size: 12px; margin-right: 3px; }
body.theme-95 .xp-titlebar-buttons { gap: 1px; }
body.theme-95 .xp-titlebar-buttons span {
  width: 16px; height: 14px; background: #c0c0c0; color: #000;
  border: 2px outset #dfdfdf; border-radius: 0;
  font-size: 9px; font-weight: bold; text-shadow: none; box-shadow: none;
}
body.theme-95 .xp-titlebar-buttons .xp-btn-close {
  background: #c0c0c0; color: #000; border: 2px outset #dfdfdf;
}
body.theme-95 .xp-login-body,
body.theme-95 .xp-desktop { background: #008080; }
body.theme-95 .xp-chat-window,
body.theme-95 .xp-login-window,
body.theme-95 .xp-game-window {
  background: #c0c0c0; border: 2px outset #dfdfdf; border-radius: 0;
  box-shadow: 2px 2px 0 rgba(0,0,0,.7);
}
body.theme-95 .xp-menubar {
  background: #c0c0c0; padding: 1px 2px; border-bottom: 1px solid #808080;
}
body.theme-95 .xp-menu-item { padding: 2px 6px; }
body.theme-95 .xp-menu-item:hover { background: #000080; color: #fff; }
body.theme-95 .xp-chat-body {
  background: #c0c0c0; border: 2px inset #808080; margin: 2px;
}
body.theme-95 .xp-sidebar {
  background: #c0c0c0; border-right: 2px groove #dfdfdf; padding: 2px;
}
body.theme-95 .xp-panel { background: #c0c0c0; border: 2px inset #808080; }
body.theme-95 .xp-panel-title { background: #000080; color: #fff; padding: 1px 4px; }
body.theme-95 .xp-online-list { padding: 2px; }
body.theme-95 .xp-online-user:hover { background: #000080; color: #fff; }
body.theme-95 .xp-online-user:hover .xp-online-id { color: #dfdfdf; }
body.theme-95 .xp-msg { padding: 2px 4px; border-bottom: 1px dashed #a0a0a0; }
body.theme-95 .xp-msg-nick { color: #000080; }
body.theme-95 .xp-msg-me .xp-msg-nick { color: #800000; }
body.theme-95 .xp-msg-me { background: #dcdcf5; }
body.theme-95 .xp-composer { background: #c0c0c0; border-top: 2px groove #dfdfdf; }
body.theme-95 .xp-textarea {
  border: 2px inset #808080; background: #fff;
  font-family: "MS Sans Serif", Tahoma, sans-serif;
}
body.theme-95 .xp-button {
  background: #c0c0c0; color: #000; border: 2px outset #dfdfdf;
  border-radius: 0; font-family: "MS Sans Serif", Tahoma, sans-serif;
  padding: 3px 12px; min-width: 70px;
}
body.theme-95 .xp-button:active { background: #c0c0c0; border-style: inset; }
body.theme-95 .xp-button-primary {
  color: #000; background: #c0c0c0; box-shadow: none; font-weight: bold;
}
body.theme-95 .xp-statusbar { background: #c0c0c0; border-top: 2px groove #dfdfdf; }
body.theme-95 .xp-statusbar-cell { padding: 2px 6px; border-left: 1px solid #808080; }
body.theme-95 .xp-statusbar-cell:first-child { border-left: none; }
body.theme-95 .xp-statusbar-brand { color: #000080; }
body.theme-95 .xp-taskbar {
  height: 28px; background: #c0c0c0; color: #000;
  border-top: 2px groove #dfdfdf;
}
body.theme-95 .xp-start-btn {
  height: 22px; background: #c0c0c0; color: #000;
  border: 2px outset #dfdfdf; border-radius: 0;
  font-weight: bold; text-shadow: none; padding: 1px 8px; margin-left: 1px;
}
body.theme-95 .xp-start-btn:active,
body.theme-95 .xp-start-btn.active {
  background: #c0c0c0; border-style: inset;
}
body.theme-95 .xp-taskbar-item {
  background: #c0c0c0; color: #000;
  border: 2px outset #dfdfdf; border-radius: 0; padding: 1px 8px;
}
body.theme-95 .xp-taskbar-tray { border-left: 2px groove #dfdfdf; color: #000; }
body.theme-95 .xp-startmenu {
  background: #c0c0c0; border: 2px outset #dfdfdf; border-radius: 0;
  box-shadow: 2px 2px 0 rgba(0,0,0,.7);
}
body.theme-95 .xp-startmenu-header {
  background: #000080; border-radius: 0; padding: 6px 8px; text-shadow: none;
}
body.theme-95 .xp-startmenu-avatar {
  background: #c0c0c0; border: 2px inset #808080; border-radius: 0;
}
body.theme-95 .xp-startmenu-item:hover { background: #000080; color: #fff; }
body.theme-95 .xp-startmenu-sep { background: #808080; margin: 3px 4px; }
body.theme-95 .xp-startmenu-sep-strong {
  height: 2px; background: #fff; border-bottom-color: #808080; margin: 3px 0;
}
body.theme-95 .xp-startmenu-author { color: #000080; }
body.theme-95 .xp-dropdown {
  background: #c0c0c0; border: 2px outset #dfdfdf; border-radius: 0;
}
body.theme-95 .xp-dropdown-title { border-bottom-color: #808080; }
body.theme-95 .xp-dropdown-item:hover { background: #000080; color: #fff; }
body.theme-95 .xp-dropdown-swatch { border-color: #000; border-radius: 0; }
body.theme-95 .xp-login-left { background: #000080; border-radius: 0; }
body.theme-95 .help-tab {
  background: #c0c0c0; border: 2px outset #dfdfdf; border-bottom: none; border-radius: 0;
}
body.theme-95 .help-tab.active { background: #c0c0c0; border-style: inset; top: 0; }
body.theme-95 .help-content h3 { color: #000080; }
body.theme-95 .help-content h4 { color: #800000; }
body.theme-95 .emoji-panel {
  background: #c0c0c0; border: 2px outset #dfdfdf; border-radius: 0;
}
body.theme-95 .emoji-cell:hover { background: #000080; }

@media (max-width: 720px) {
  .xp-chat-window { height: calc(100vh - 60px); border-radius: 0; }
  .xp-desktop { padding: 0 0 30px 0; align-items: stretch; }
  .xp-sidebar { display: none; }
  .xp-login-window { width: 100%; }
  .xp-login-left { display: none; }
}
</style>
</head>
<body class="<?= $isChat ? 'xp-desktop' : 'xp-login-body' ?>">

<?php if (!$isChat): ?>

<div class="xp-login-window">
  <div class="xp-titlebar">
    <div class="xp-titlebar-icon">🍓</div>
    <div class="xp-titlebar-text">Raspberry Web Chat</div>
    <div class="xp-titlebar-buttons">
      <span class="xp-btn-min">_</span><span class="xp-btn-max">□</span><span class="xp-btn-close">×</span>
    </div>
  </div>
  <div class="xp-login-content">
    <div class="xp-login-left">
      <div class="xp-logo">🍓</div>
      <div class="xp-brand">Raspberry<br>Web Chat</div>
      <div class="xp-brand-sub">build 2000.77</div>
      <div class="xp-brand-author">By Larp Dev</div>
    </div>
    <div class="xp-login-right">
      <div class="xp-login-hint">Введите имя, чтобы войти</div>
      <form id="login-form" autocomplete="off">
        <label class="xp-label" for="nickname">Ник:</label>
        <input id="nickname" class="xp-input" maxlength="24" placeholder="Придумайте ник" required>
        <div class="xp-login-note">
          Регистрация не требуется. Если ник свободен — аккаунт будет создан,
          а вам автоматически выдан уникальный ID.
        </div>
        <div id="login-error" class="xp-error"></div>
        <div class="xp-login-buttons">
          <button type="submit" class="xp-button xp-button-primary">Войти →</button>
        </div>
      </form>
    </div>
  </div>
  <div class="xp-statusbar">
    <div class="xp-statusbar-cell">Готово</div>
    <div class="xp-statusbar-cell xp-statusbar-right xp-statusbar-brand">By Larp Dev</div>
  </div>
</div>

<script>
(function restoreThemeLogin() {
  var saved = 'xp';
  try { saved = localStorage.getItem('rwc_theme') || 'xp'; } catch (_) {}
  if (['xp','raspberry','skype','95'].indexOf(saved) === -1) saved = 'xp';
  if (saved !== 'xp') document.body.classList.add('theme-' + saved);
})();
</script>

<script>
(() => {
  const form  = document.getElementById('login-form');
  const input = document.getElementById('nickname');
  const err   = document.getElementById('login-error');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    const nick = input.value.trim();
    if (!nick) { err.textContent = 'Введите ник'; return; }

    const fd = new FormData();
    fd.append('nickname', nick);
    try {
      const r = await fetch('?action=login', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) { err.textContent = j.error || 'Ошибка входа'; return; }
      location.href = location.pathname;
    } catch (_) {
      err.textContent = 'Ошибка сети';
    }
  });

  input.focus();
})();
</script>

<?php else: ?>

<div class="xp-chat-window" id="chat-window">
  <div class="xp-titlebar">
    <div class="xp-titlebar-icon">🍓</div>
    <div class="xp-titlebar-text">Raspberry Web Chat — Общий чат · By Larp Dev</div>
    <div class="xp-titlebar-buttons">
      <span class="xp-btn-min">_</span><span class="xp-btn-max">□</span>
      <span class="xp-btn-close" id="btn-close" title="Завершение работы">×</span>
    </div>
  </div>
  <div class="xp-menubar">
    <span class="xp-menu-item">Файл</span>
    <span class="xp-menu-item">Правка</span>
    <span class="xp-menu-item" id="menu-view">Вид ▾</span>
    <span class="xp-menu-item">Игры</span>
    <span class="xp-menu-item" id="menu-help">Справка</span>
  </div>
  <div class="xp-chat-body">
    <div class="xp-sidebar">
      <div class="xp-panel">
        <div class="xp-panel-title">Пользователи онлайн</div>
        <div id="online-list" class="xp-online-list"></div>
      </div>
    </div>
    <div class="xp-chat-main">
      <div id="messages" class="xp-messages"></div>
      <div class="xp-composer">
        <textarea id="composer" class="xp-textarea" rows="2" placeholder="Введите сообщение и нажмите Enter…"></textarea>
        <button id="emoji-btn" class="xp-button" title="Эмодзи">😀</button>
        <button id="send-btn" class="xp-button xp-button-primary">Отправить</button>
      </div>
      <div class="emoji-panel" id="emoji-panel">
        <div class="emoji-title">Эмодзи</div>
        <div class="emoji-grid" id="emoji-grid"></div>
      </div>
    </div>
  </div>
  <div class="xp-statusbar">
    <div class="xp-statusbar-cell" id="status">Подключение…</div>
    <div class="xp-statusbar-cell xp-statusbar-right xp-statusbar-brand">By Larp Dev</div>
    <div class="xp-statusbar-cell xp-statusbar-right">
      <span id="me-label">—</span>
    </div>
  </div>
</div>

<div class="xp-dropdown" id="view-dropdown">
  <div class="xp-dropdown-title">Тема оформления</div>
  <div class="xp-dropdown-item" data-theme="xp">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(180deg,#3a93ff,#0058ee)"></span>
    <span>Windows XP</span>
  </div>
  <div class="xp-dropdown-item" data-theme="raspberry">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(135deg,#d62828,#4e8b2f)"></span>
    <span>🍓 Raspberry</span>
  </div>
  <div class="xp-dropdown-item" data-theme="skype">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(135deg,#21a0f0,#2ecc71)"></span>
    <span>💬 Skype</span>
  </div>
  <div class="xp-dropdown-item" data-theme="95">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(135deg,#008080,#c0c0c0)"></span>
    <span>🪟 Windows 95</span>
  </div>
  <div class="xp-dropdown-sep"></div>
  <div class="xp-dropdown-item" id="sound-toggle">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(135deg,#ffd700,#ff8800)"></span>
    <span id="sound-toggle-label">🔊 Звуки: вкл</span>
  </div>
  <div class="xp-dropdown-item" id="sound-test">
    <span class="xp-dropdown-swatch" style="background:linear-gradient(135deg,#7ac0ff,#3a93ff)"></span>
    <span>🔔 Проверить звук</span>
  </div>
</div>

<div class="xp-startmenu" id="startmenu">
  <div class="xp-startmenu-header">
    <div class="xp-startmenu-avatar">🍓</div>
    <div class="xp-startmenu-user">
      <div class="xp-startmenu-nick" id="sm-nick">—</div>
      <div class="xp-startmenu-id" id="sm-id">—</div>
      <div class="xp-startmenu-author">By Larp Dev</div>
    </div>
  </div>
  <div class="xp-startmenu-items">
    <div class="xp-startmenu-item" data-game="browser">
      <span class="xp-startmenu-icon">🔍</span><span>Fast Search</span>
    </div>
    <div class="xp-startmenu-item" data-game="minesweeper">
      <span class="xp-startmenu-icon">💣</span><span>Сапёр</span>
    </div>
    <div class="xp-startmenu-item" data-game="puzzle">
      <span class="xp-startmenu-icon">🧩</span><span>Пятнашки</span>
    </div>
    <div class="xp-startmenu-item" data-game="snake">
      <span class="xp-startmenu-icon">🐍</span><span>Змейка</span>
    </div>
    <div class="xp-startmenu-sep"></div>
    <div class="xp-startmenu-item" data-game="about">
      <span class="xp-startmenu-icon">ℹ️</span><span>О программе</span>
    </div>
    <div class="xp-startmenu-item" data-game="help">
      <span class="xp-startmenu-icon">❓</span><span>Справка</span>
    </div>
    <div class="xp-startmenu-sep-strong"></div>
    <div class="xp-startmenu-item" id="sm-logout">
      <span class="xp-startmenu-icon">🚪</span><span>Завершение работы</span>
    </div>
  </div>
</div>

<div class="xp-taskbar">
  <button class="xp-start-btn" id="start-btn">🍓 Пуск</button>
  <div class="xp-taskbar-items"><div class="xp-taskbar-item">Raspberry Web Chat</div></div>
  <div class="xp-taskbar-tray"><span id="clock">--:--</span></div>
</div>

<div class="shutdown-overlay" id="shutdown-overlay">
  <div class="shutdown-content">
    <div class="shutdown-logo">🍓</div>
    <div class="shutdown-title">Завершение работы RasberryOS...</div>
    <div class="shutdown-sub">Пожалуйста, подождите</div>
    <div class="shutdown-dots"><span></span><span></span><span></span></div>
  </div>
</div>

<script>
window.__ME__ = <?= json_encode([
    'id'       => (int)$me['id'],
    'nickname' => (string)$me['nickname'],
    'tag'      => (string)$me['tag'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(() => {
  'use strict';

  /* ============ SOUND ENGINE (Web Audio, no files) ============ */
  const Sound = (() => {
    let ctx = null;
    let enabled = true;
    try { enabled = localStorage.getItem('rwc_sound') !== 'off'; } catch (_) {}

    function getCtx() {
      if (ctx) return ctx;
      const C = window.AudioContext || window.webkitAudioContext;
      if (!C) return null;
      try { ctx = new C(); } catch (_) { ctx = null; }
      return ctx;
    }

    // Разбудить контекст на первом user gesture.
    // capture: true → срабатывает раньше кликов, чтобы ctx был running
    // к моменту, когда click-обработчик вызовет tone().
    function unlock() {
      const c = getCtx();
      if (!c) return;
      if (c.state === 'suspended') {
        c.resume().catch(() => {});
      }
    }
    ['pointerdown','mousedown','keydown','touchstart'].forEach(ev => {
      window.addEventListener(ev, unlock, { capture: true, passive: true });
    });

    function playTone(c, freq, dur, o) {
      const type    = o.type || 'sine';
      const vol     = o.vol !== undefined ? o.vol : 0.09;
      const delay   = o.delay || 0;
      const sweepTo = o.sweepTo || null;

      // t0 — гарантированно в будущем, чтобы не "провалиться"
      const t0 = c.currentTime + delay + 0.01;

      const osc = c.createOscillator();
      const g   = c.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, t0);
      if (sweepTo && sweepTo > 0) {
        osc.frequency.exponentialRampToValueAtTime(sweepTo, t0 + dur);
      }
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.linearRampToValueAtTime(vol, t0 + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(g).connect(c.destination);
      osc.start(t0);
      osc.stop(t0 + dur + 0.05);
    }

    function tone(freq, dur, opts) {
      if (!enabled) return;
      const c = getCtx(); if (!c) return;
      const o = opts || {};

      // Ключевой фикс: если контекст suspended — ждём resume, потом играем.
      if (c.state === 'running') {
        playTone(c, freq, dur, o);
        return;
      }
      c.resume().then(() => {
        if (c.state === 'running') playTone(c, freq, dur, o);
      }).catch(() => {});
    }

    return {
      click:     () => tone(1800, 0.035, { type: 'square', vol: 0.05 }),
      startOpen: () => {
        tone(660,  0.07, { type: 'triangle', vol: 0.09 });
        tone(990,  0.09, { type: 'triangle', vol: 0.09, delay: 0.06 });
      },
      startClose:() => tone(880, 0.06, { type: 'triangle', vol: 0.07, sweepTo: 500 }),
      send:      () => {
        tone(700,  0.09, { type: 'triangle', vol: 0.10 });
        tone(1200, 0.10, { type: 'triangle', vol: 0.09, delay: 0.07, sweepTo: 1500 });
      },
      receive:   () => {
        tone(1350, 0.09, { type: 'triangle', vol: 0.10, sweepTo: 950 });
        tone(880,  0.11, { type: 'triangle', vol: 0.09, delay: 0.09 });
      },
      close:     () => tone(900, 0.09, { type: 'triangle', vol: 0.08, sweepTo: 400 }),
      shutdown:  () => {
        tone(880, 0.14, { type: 'triangle', vol: 0.11 });
        tone(660, 0.14, { type: 'triangle', vol: 0.11, delay: 0.16 });
        tone(440, 0.42, { type: 'triangle', vol: 0.11, delay: 0.32, sweepTo: 220 });
      },
      test:      () => {
        tone(880,  0.10, { type: 'triangle', vol: 0.11 });
        tone(1320, 0.12, { type: 'triangle', vol: 0.11, delay: 0.10 });
      },
      isEnabled:  () => enabled,
      setEnabled: (v) => {
        enabled = !!v;
        try { localStorage.setItem('rwc_sound', enabled ? 'on' : 'off'); } catch (_) {}
      }
    };
  })();

  /* ============ EMOJI MAP ============ */
  const EMOJI_MAP = {
    ':)': '😊', ':-)': '😊',
    ':(': '☹️', ':-(': '☹️',
    ':D': '😄', ':-D': '😄',
    ';)': '😉', ';-)': '😉',
    ':P': '😛', ':-P': '😛',
    ':O': '😮', ':-O': '😮',
    ':|': '😐', ':-|': '😐',
    '<3': '❤️',
    ':fire:': '🔥',
    ':raspberry:': '🍓',
    ':star:': '⭐',
    ':rocket:': '🚀',
    ':skull:': '💀',
    ':crown:': '👑',
    ':cat:': '🐱',
    ':dog:': '🐶',
    ':100:': '💯',
    ':ok:': '👌',
    ':cool:': '😎',
    ':wave:': '👋',
    ':heart:': '❤️',
    ':rainbow:': '🌈',
    ':sun:': '☀️',
    ':moon:': '🌙',
    ':pizza:': '🍕',
    ':coffee:': '☕',
    ':ghost:': '👻',
    ':alien:': '👽',
    ':robot:': '🤖',
    ':dice:': '🎲',
    ':zap:': '⚡',
    ':boom:': '💥'
  };

  function esc(s) {
    return String(s)
      .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
      .replaceAll('"','&quot;').replaceAll("'",'&#39;');
  }

  const EMOJI_KEYS = Object.keys(EMOJI_MAP).map(k => [esc(k), EMOJI_MAP[k]]);

  function formatBody(text) {
    let html = esc(text);
    for (const [key, emoji] of EMOJI_KEYS) {
      html = html.split(key).join(emoji);
    }
    return html;
  }

  /* ============ WINDOW MANAGER ============ */
  let winZ = 1000;
  const openWindows = new Set();

  function openWindow(opts) {
    const { title, width = 420, height = 340, content, icon = '🎮' } = opts;
    const w = document.createElement('div');
    w.className = 'xp-game-window';
    w.style.width  = width + 'px';
    w.style.height = height + 'px';
    const cx = Math.max(20, (window.innerWidth  - width)  / 2 + (Math.random() * 60 - 30));
    const cy = Math.max(20, (window.innerHeight - height) / 2 + (Math.random() * 60 - 30) - 20);
    w.style.left = cx + 'px';
    w.style.top  = cy + 'px';
    w.style.zIndex = ++winZ;

    const tb = document.createElement('div');
    tb.className = 'xp-titlebar';
    tb.innerHTML = '<div class="xp-titlebar-icon"></div>' +
                   '<div class="xp-titlebar-text"></div>' +
                   '<div class="xp-titlebar-buttons">' +
                     '<span class="xp-btn-close" title="Закрыть">×</span>' +
                   '</div>';
    tb.querySelector('.xp-titlebar-icon').textContent = icon;
    tb.querySelector('.xp-titlebar-text').textContent = title;

    const body = document.createElement('div');
    body.className = 'xp-game-body';
    if (content instanceof Node) body.appendChild(content);

    w.appendChild(tb);
    w.appendChild(body);
    document.body.appendChild(w);

    w.addEventListener('mousedown', () => { w.style.zIndex = ++winZ; });

    tb.querySelector('.xp-btn-close').addEventListener('click', () => {
      Sound.close();
      openWindows.delete(w);
      w.remove();
    });

    tb.addEventListener('mousedown', (e) => {
      if (e.target.classList.contains('xp-btn-close')) return;
      e.preventDefault();
      const sx = e.clientX - w.offsetLeft;
      const sy = e.clientY - w.offsetTop;
      const mv = (ev) => {
        const nx = ev.clientX - sx;
        const ny = ev.clientY - sy;
        w.style.left = Math.max(-w.offsetWidth + 100, Math.min(window.innerWidth - 100, nx)) + 'px';
        w.style.top  = Math.max(0, Math.min(window.innerHeight - 40, ny)) + 'px';
      };
      const up = () => {
        document.removeEventListener('mousemove', mv);
        document.removeEventListener('mouseup', up);
      };
      document.addEventListener('mousemove', mv);
      document.addEventListener('mouseup', up);
    });

    openWindows.add(w);
    return w;
  }

  /* ============ GAME: MINESWEEPER ============ */
  function gameMinesweeper() {
    const div = document.createElement('div');
    const W = 9, H = 9, MINES = 10;
    let grid, revealed, flagged, dead, won;

    function init() {
      grid     = Array.from({ length: H }, () => Array(W).fill(0));
      revealed = Array.from({ length: H }, () => Array(W).fill(false));
      flagged  = Array.from({ length: H }, () => Array(W).fill(false));
      dead = false; won = false;

      let placed = 0;
      while (placed < MINES) {
        const r = Math.floor(Math.random() * H);
        const c = Math.floor(Math.random() * W);
        if (grid[r][c] === -1) continue;
        grid[r][c] = -1; placed++;
      }
      for (let r = 0; r < H; r++) for (let c = 0; c < W; c++) {
        if (grid[r][c] === -1) continue;
        let n = 0;
        for (let dr = -1; dr <= 1; dr++) for (let dc = -1; dc <= 1; dc++) {
          const nr = r + dr, nc = c + dc;
          if (nr >= 0 && nr < H && nc >= 0 && nc < W && grid[nr][nc] === -1) n++;
        }
        grid[r][c] = n;
      }
    }

    function reveal(r, c) {
      if (dead || won) return;
      if (r < 0 || r >= H || c < 0 || c >= W) return;
      if (revealed[r][c] || flagged[r][c]) return;
      revealed[r][c] = true;
      if (grid[r][c] === -1) { dead = true; return; }
      if (grid[r][c] === 0) {
        for (let dr = -1; dr <= 1; dr++) for (let dc = -1; dc <= 1; dc++) {
          if (dr || dc) reveal(r + dr, c + dc);
        }
      }
    }

    function checkWin() {
      let open = 0;
      for (let r = 0; r < H; r++) for (let c = 0; c < W; c++) if (revealed[r][c]) open++;
      if (open === H * W - MINES) won = true;
    }

    function draw() {
      checkWin();
      div.innerHTML = '';

      const bar = document.createElement('div');
      bar.className = 'game-bar';
      const mines = document.createElement('span');
      mines.textContent = '💣 ' + MINES;
      const status = document.createElement('span');
      status.style.fontWeight = 'bold';
      if (dead) status.textContent = '💀 Проигрыш';
      else if (won) status.textContent = '🏆 Победа!';
      else status.textContent = 'ЛКМ — открыть, ПКМ — флаг';
      const btn = document.createElement('button');
      btn.className = 'xp-button'; btn.textContent = 'Заново';
      btn.onclick = () => { init(); draw(); };
      bar.appendChild(mines); bar.appendChild(status); bar.appendChild(btn);
      div.appendChild(bar);

      const table = document.createElement('div');
      table.className = 'mines-grid';
      table.style.gridTemplateColumns = 'repeat(' + W + ', 26px)';

      for (let r = 0; r < H; r++) for (let c = 0; c < W; c++) {
        const cell = document.createElement('div');
        cell.className = 'mines-cell';

        if (revealed[r][c]) {
          cell.classList.add('opened');
          if (grid[r][c] === -1) { cell.textContent = '💣'; cell.classList.add('boom'); }
          else if (grid[r][c] > 0) { cell.textContent = grid[r][c]; cell.dataset.n = grid[r][c]; }
        } else if (dead && grid[r][c] === -1) {
          cell.classList.add('opened');
          cell.textContent = '💣';
        } else if (flagged[r][c]) {
          cell.textContent = '🚩';
        }

        cell.addEventListener('contextmenu', (e) => {
          e.preventDefault();
          if (revealed[r][c] || dead || won) return;
          flagged[r][c] = !flagged[r][c];
          Sound.click();
          draw();
        });
        cell.addEventListener('click', () => {
          reveal(r, c);
          draw();
        });
        table.appendChild(cell);
      }
      div.appendChild(table);
    }

    init(); draw();
    return div;
  }

  /* ============ GAME: 15-PUZZLE ============ */
  function gamePuzzle() {
    const div = document.createElement('div');
    let tiles = [];

    function solvable() {
      let inv = 0;
      const arr = tiles.filter(x => x);
      for (let i = 0; i < arr.length; i++)
        for (let j = i + 1; j < arr.length; j++)
          if (arr[i] > arr[j]) inv++;
      const rowTop = Math.floor(tiles.indexOf(0) / 4);
      return (inv + rowTop) % 2 === 0;
    }

    function init() {
      do {
        tiles = Array.from({ length: 15 }, (_, i) => i + 1).concat([0]);
        for (let i = tiles.length - 1; i > 0; i--) {
          const j = Math.floor(Math.random() * (i + 1));
          [tiles[i], tiles[j]] = [tiles[j], tiles[i]];
        }
      } while (!solvable());
    }

    function draw() {
      div.innerHTML = '';
      const solved = tiles.every((v, i) => i === tiles.length - 1 ? v === 0 : v === i + 1);

      const bar = document.createElement('div');
      bar.className = 'game-bar';
      const status = document.createElement('span');
      status.style.fontWeight = 'bold';
      status.textContent = solved ? '🏆 Собрано!' : 'Клик по плитке рядом с пустой';
      const btn = document.createElement('button');
      btn.className = 'xp-button'; btn.textContent = 'Заново';
      btn.onclick = () => { init(); draw(); };
      bar.appendChild(status); bar.appendChild(btn);
      div.appendChild(bar);

      const grid = document.createElement('div');
      grid.className = 'puzzle-grid';

      tiles.forEach((v, i) => {
        const cell = document.createElement('div');
        cell.className = 'puzzle-cell';
        if (v === 0) {
          cell.classList.add('empty');
        } else {
          cell.textContent = v;
          cell.addEventListener('click', () => {
            if (solved) return;
            const blankIdx = tiles.indexOf(0);
            const r = Math.floor(i / 4), c = i % 4;
            const br = Math.floor(blankIdx / 4), bc = blankIdx % 4;
            if (Math.abs(r - br) + Math.abs(c - bc) === 1) {
              [tiles[i], tiles[blankIdx]] = [tiles[blankIdx], tiles[i]];
              Sound.click();
              draw();
            }
          });
        }
        grid.appendChild(cell);
      });
      div.appendChild(grid);
    }

    init(); draw();
    return div;
  }

  /* ============ GAME: SNAKE ============ */
  function gameSnake() {
    const div = document.createElement('div');
    div.className = 'game-snake';
    const SIZE = 20, CELL = 14;
    let snake, dir, food, timer, alive, score;

    const canvas = document.createElement('canvas');
    canvas.width = SIZE * CELL;
    canvas.height = SIZE * CELL;
    canvas.className = 'snake-canvas';
    canvas.tabIndex = 0;
    const ctx = canvas.getContext('2d');

    function placeFood() {
      do {
        food = { x: Math.floor(Math.random() * SIZE), y: Math.floor(Math.random() * SIZE) };
      } while (snake.some(s => s.x === food.x && s.y === food.y));
    }

    function init() {
      snake = [{ x: 10, y: 10 }];
      dir   = { x: 1, y: 0 };
      score = 0;
      alive = true;
      placeFood();
      if (timer) clearInterval(timer);
      timer = setInterval(step, 130);
    }

    function step() {
      if (!canvas.isConnected) { clearInterval(timer); timer = null; return; }
      if (!alive) return;

      const head = { x: snake[0].x + dir.x, y: snake[0].y + dir.y };
      if (head.x < 0 || head.x >= SIZE || head.y < 0 || head.y >= SIZE ||
          snake.some(s => s.x === head.x && s.y === head.y)) {
        alive = false;
        draw();
        return;
      }
      snake.unshift(head);
      if (head.x === food.x && head.y === food.y) {
        score++;
        Sound.click();
        placeFood();
      }
      else snake.pop();
      draw();
    }

    function draw() {
      ctx.fillStyle = '#000';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      ctx.strokeStyle = '#0a1a0a';
      for (let i = 1; i < SIZE; i++) {
        ctx.beginPath(); ctx.moveTo(i * CELL, 0); ctx.lineTo(i * CELL, canvas.height); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(0, i * CELL); ctx.lineTo(canvas.width, i * CELL); ctx.stroke();
      }

      ctx.fillStyle = '#ff3030';
      ctx.fillRect(food.x * CELL + 1, food.y * CELL + 1, CELL - 2, CELL - 2);

      snake.forEach((s, i) => {
        ctx.fillStyle = i === 0 ? '#b6ff8b' : '#2e9e00';
        ctx.fillRect(s.x * CELL + 1, s.y * CELL + 1, CELL - 2, CELL - 2);
      });

      ctx.fillStyle = '#0f0';
      ctx.font = '12px monospace';
      ctx.fillText('Счёт: ' + score, 8, 16);

      if (!alive) {
        ctx.fillStyle = 'rgba(0,0,0,0.72)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ff3030';
        ctx.font = 'bold 24px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('GAME OVER', canvas.width / 2, canvas.height / 2);
        ctx.fillStyle = '#0f0';
        ctx.font = '12px monospace';
        ctx.fillText('Enter — рестарт', canvas.width / 2, canvas.height / 2 + 24);
        ctx.textAlign = 'start';
      }
    }

    function onKey(e) {
      const k = e.key;
      if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(k)) e.preventDefault();
      if (!alive && k === 'Enter') { init(); draw(); return; }
      if (k === 'ArrowUp'    && dir.y === 0) dir = { x: 0, y: -1 };
      else if (k === 'ArrowDown'  && dir.y === 0) dir = { x: 0, y: 1 };
      else if (k === 'ArrowLeft'  && dir.x === 0) dir = { x: -1, y: 0 };
      else if (k === 'ArrowRight' && dir.x === 0) dir = { x: 1, y: 0 };
    }

    canvas.addEventListener('keydown', onKey);
    canvas.addEventListener('click', () => canvas.focus());

    const bar = document.createElement('div');
    bar.className = 'game-bar';
    const hint = document.createElement('span');
    hint.textContent = 'Стрелки — управление';
    const btn = document.createElement('button');
    btn.className = 'xp-button'; btn.textContent = 'Заново';
    btn.onclick = () => { init(); draw(); canvas.focus(); };
    bar.appendChild(hint); bar.appendChild(btn);
    div.appendChild(bar);
    div.appendChild(canvas);

    init(); draw();
    setTimeout(() => canvas.focus(), 80);
    return div;
  }

  /* ============ FAST SEARCH BROWSER ============ */
  function fastBrowser() {
    const HOME = 'https://wiby.me/';

    const div = document.createElement('div');
    div.className = 'fast-browser';

    const bar = document.createElement('div');
    bar.className = 'fb-toolbar';
    bar.innerHTML =
      '<button class="xp-button fb-btn" data-act="back"  title="Назад">◀</button>' +
      '<button class="xp-button fb-btn" data-act="fwd"   title="Вперёд">▶</button>' +
      '<button class="xp-button fb-btn" data-act="home"  title="Домой">🏠</button>' +
      '<button class="xp-button fb-btn" data-act="reload" title="Обновить">⟳</button>' +
      '<input class="fb-url" type="text" placeholder="Введите запрос или URL…" autocomplete="off" spellcheck="false">' +
      '<button class="xp-button fb-go">Искать</button>';

    const wrap = document.createElement('div');
    wrap.className = 'fb-frame-wrap';

    const frame = document.createElement('iframe');
    frame.className = 'fb-frame';
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox');

    const errBox = document.createElement('div');
    errBox.className = 'fb-error';
    errBox.hidden = true;
    errBox.innerHTML =
      '<div class="fb-error-icon">🛡️</div>' +
      '<div><b>Сайт запретил встраивание в iframe</b></div>' +
      '<div style="color:#666;max-width:340px">' +
        'Многие сайты блокируют показ внутри рамки (X-Frame-Options / CSP). ' +
        'Открой страницу в новой вкладке — там всё откроется.' +
      '</div>' +
      '<button class="xp-button fb-open">Открыть в новой вкладке</button>';

    wrap.appendChild(frame);
    wrap.appendChild(errBox);

    const status = document.createElement('div');
    status.className = 'fb-status';
    status.innerHTML = '🔍 <b>Fast Search</b> · движок wiby.me · поисковик старого интернета';

    div.appendChild(bar);
    div.appendChild(wrap);
    div.appendChild(status);

    const urlInput = bar.querySelector('.fb-url');
    const history = [];
    let histIdx = -1;
    let currentUrl = HOME;

    function isUrl(s) {
      s = s.trim();
      if (!s) return false;
      if (/^(https?:)?\/\//i.test(s)) return true;
      if (/^[\w-]+(\.[\w-]+)+(\/\S*)?$/i.test(s) && !/\s/.test(s)) return true;
      return false;
    }
    function normalize(s) {
      s = s.trim();
      if (!s) return HOME;
      if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
      return s;
    }
    function toUrl(query) {
      query = query.trim();
      if (!query) return HOME;
      if (isUrl(query)) return normalize(query);
      return 'https://wiby.me/?q=' + encodeURIComponent(query);
    }
    function load(url, pushHistory) {
      currentUrl = url;
      errBox.hidden = true;
      urlInput.value = url;
      status.innerHTML = '🔍 <b>Загрузка…</b> · ' + url;
      try { frame.src = url; } catch (e) {}
      if (pushHistory) {
        history.splice(histIdx + 1);
        history.push(url);
        histIdx = history.length - 1;
      }
    }

    frame.addEventListener('load', () => {
      status.innerHTML = '🔍 <b>Fast Search</b> · ' + currentUrl +
        ' · <a href="' + currentUrl + '" target="_blank" rel="noopener">открыть в новой вкладке</a>';
    });

    bar.querySelector('[data-act="back"]').addEventListener('click', () => {
      if (histIdx > 0) { histIdx--; load(history[histIdx], false); }
    });
    bar.querySelector('[data-act="fwd"]').addEventListener('click', () => {
      if (histIdx < history.length - 1) { histIdx++; load(history[histIdx], false); }
    });
    bar.querySelector('[data-act="home"]').addEventListener('click', () => load(HOME, true));
    bar.querySelector('[data-act="reload"]').addEventListener('click', () => load(currentUrl, false));
    bar.querySelector('.fb-go').addEventListener('click', () => load(toUrl(urlInput.value), true));
    urlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); load(toUrl(urlInput.value), true); }
    });
    errBox.querySelector('.fb-open').addEventListener('click', () => {
      window.open(currentUrl, '_blank', 'noopener');
    });

    load(HOME, true);
    return div;
  }

  /* ============ ABOUT ============ */
  function aboutWindow() {
    const div = document.createElement('div');
    div.innerHTML =
      '<div style="display:flex;gap:12px;align-items:flex-start;">' +
        '<div style="font-size:48px;line-height:1;">🍓</div>' +
        '<div>' +
          '<div style="font-weight:bold;font-size:13px;">Raspberry Web Chat</div>' +
          '<div style="color:#555;margin-top:4px;">build 2000.77 · <b>By Larp Dev</b></div>' +
          '<div style="margin-top:8px;">PHP ' + <?= json_encode(PHP_VERSION) ?> + ' · SQLite · WinXP UI</div>' +
          '<div style="margin-top:8px;color:#555;">Мессенджер без регистрации.<br>Придумайте ник — получите ID.</div>' +
          '<div style="margin-top:10px;">' +
            'Движок поиска: <a href="https://wiby.me" target="_blank" style="color:#0058ee;">wiby.me</a>' +
          '</div>' +
        '</div>' +
      '</div>';
    return div;
  }

  /* ============ HELP ============ */
  function helpWindow() {
    const div = document.createElement('div');
    div.className = 'help-content';

    const tabsBar = document.createElement('div');
    tabsBar.className = 'help-tabs';

    const tabs = [
      { id: 'basic',    label: 'Основы' },
      { id: 'chat',     label: 'Чат' },
      { id: 'emoji',    label: 'Эмодзи' },
      { id: 'games',    label: 'Игры' },
      { id: 'themes',   label: 'Темы' },
      { id: 'about',    label: 'О сборке' }
    ];

    tabs.forEach((t, i) => {
      const tab = document.createElement('div');
      tab.className = 'help-tab' + (i === 0 ? ' active' : '');
      tab.textContent = t.label;
      tab.dataset.tab = t.id;
      tabsBar.appendChild(tab);
    });
    div.appendChild(tabsBar);

    const panels = document.createElement('div');
    function addPanel(id, active, html) {
      const p = document.createElement('div');
      p.className = 'help-panel' + (active ? ' active' : '');
      p.dataset.tab = id;
      p.innerHTML = html;
      panels.appendChild(p);
    }

    addPanel('basic', true, `
      <h3>🍓 Raspberry Web Chat</h3>
      <p>Ретро-мессенджер в стиле Windows XP/2000.</p>
      <h4>Как войти</h4>
      <ul>
        <li>Открой страницу — появится окно входа.</li>
        <li>Введи любой ник (регистрации нет).</li>
        <li>Ник свободен — аккаунт создастся, ты получишь ID.</li>
        <li>Ник занят — войдёшь под ним же.</li>
      </ul>
      <h4>Горячие клавиши</h4>
      <ul>
        <li><kbd>Enter</kbd> — отправить.</li>
        <li><kbd>Shift</kbd>+<kbd>Enter</kbd> — перенос строки.</li>
      </ul>
      <h4>Завершение работы</h4>
      <ul>
        <li>Кнопка <b>×</b> в шапке окна.</li>
        <li>Или <b>Пуск → 🚪 Завершение работы</b>.</li>
      </ul>
      <h4>Звуки</h4>
      <ul>
        <li>Включаются в <b>Вид ▾ → 🔊 Звуки: вкл</b>.</li>
        <li>Проверить можно через <b>🔔 Проверить звук</b>.</li>
        <li><b>Важно:</b> браузеры блокируют звук до первого клика по странице.
        Один раз кликни в любом месте — дальше всё будет играть.</li>
      </ul>
    `);

    addPanel('chat', false, `
      <h3>💬 Чат</h3>
      <h4>Общий чат</h4>
      <p>Все онлайн видят сообщения в реальном времени (~1 сек).</p>
      <h4>Пользователи онлайн</h4>
      <p>В левой панели — кто активен последние 30 сек.</p>
      <h4>Твой профиль</h4>
      <p>Ник, ID (#xxxxxxx) и тег (#Uxxxxxxx) — в правом нижнем углу
      и в шапке меню Пуск.</p>
    `);

    addPanel('emoji', false, `
      <h3>😀 Эмодзи и смайлы</h3>
      <p>В поле ввода кнопка <b>😀</b> — открывает панель.</p>
      <h4>Текстовые коды</h4>
      <ul>
        <li><code>:)</code> <code>:D</code> <code>:(</code> <code>;)</code> <code>:P</code> <code>:O</code> — смайлы</li>
        <li><code>&lt;3</code> <code>:heart:</code> — ❤️</li>
        <li><code>:fire:</code> <code>:raspberry:</code> <code>:star:</code> <code>:crown:</code></li>
        <li><code>:skull:</code> <code>:ghost:</code> <code>:alien:</code> <code>:robot:</code></li>
        <li><code>:ok:</code> <code>:cool:</code> <code>:100:</code> <code>:wave:</code></li>
        <li><code>:pizza:</code> <code>:coffee:</code> <code>:rainbow:</code> <code>:zap:</code></li>
      </ul>
    `);

    addPanel('games', false, `
      <h3>🎮 Мини-игры</h3>
      <p>Через <b>🍓 Пуск</b>.</p>
      <h4>💣 Сапёр</h4><ul><li>9×9, 10 мин. ЛКМ / ПКМ.</li></ul>
      <h4>🧩 Пятнашки</h4><ul><li>4×4, всегда решаемая.</li></ul>
      <h4>🐍 Змейка</h4><ul><li>Стрелки, Enter — рестарт.</li></ul>
    `);

    addPanel('themes', false, `
      <h3>🎨 Темы оформления</h3>
      <p>Меню <b>Вид ▾</b> в шапке окна чата.</p>
      <ul>
        <li>🪟 Windows XP — синяя Luna.</li>
        <li>🍓 Raspberry — красно-зелёная.</li>
        <li>💬 Skype — голубая.</li>
        <li>🖥 Windows 95 — плоский серый.</li>
      </ul>
    `);

    addPanel('about', false, `
      <h3>ℹ️ О сборке</h3>
      <p><b>Версия:</b> build 2000.77</p>
      <p><b>Автор:</b> By Larp Dev</p>
      <p><b>Стек:</b> PHP + SQLite + vanilla JS + Web Audio</p>
      <h4>Что внутри</h4>
      <ul>
        <li>Чат (short-polling).</li>
        <li>Меню Пуск + оконный менеджер.</li>
        <li>3 мини-игры.</li>
        <li>Мини-браузер Fast Search.</li>
        <li>4 темы оформления.</li>
        <li>Звуки на Web Audio API.</li>
        <li>Система эмодзи.</li>
        <li>Экран завершения работы.</li>
      </ul>
      <p style="color:#666;margin-top:10px;">© Raspberry Systems</p>
    `);

    div.appendChild(panels);

    tabsBar.addEventListener('click', (e) => {
      const tab = e.target.closest('.help-tab');
      if (!tab) return;
      tabsBar.querySelectorAll('.help-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      panels.querySelectorAll('.help-panel').forEach(p => {
        p.classList.toggle('active', p.dataset.tab === tab.dataset.tab);
      });
    });

    return div;
  }

  /* ============ CHAT ============ */
  const ME = window.__ME__;
  const messagesEl = document.getElementById('messages');
  const composerEl = document.getElementById('composer');
  const sendBtn    = document.getElementById('send-btn');
  const emojiBtn   = document.getElementById('emoji-btn');
  const emojiPanel = document.getElementById('emoji-panel');
  const emojiGrid  = document.getElementById('emoji-grid');
  const onlineEl   = document.getElementById('online-list');
  const statusEl   = document.getElementById('status');
  const meLabelEl  = document.getElementById('me-label');
  const clockEl    = document.getElementById('clock');
  const closeBtn   = document.getElementById('btn-close');

  meLabelEl.textContent = ME.nickname + ' #' + ME.id + ' ' + ME.tag;
  document.getElementById('sm-nick').textContent = ME.nickname;
  document.getElementById('sm-id').textContent   = '#' + ME.id + ' ' + ME.tag;

  /* ---- emoji picker ---- */
  const PICKER_EMOJIS = [
    '😀','😄','😁','😊','😉','😎','😍','🥰',
    '😂','🤣','😅','🙃','😐','😴','😢','😭',
    '😡','🤔','🤯','🥳','🤩','😱','😇','🤖',
    '👍','👎','👌','✌️','🤞','👋','🙏','💪',
    '❤️','💔','🔥','⭐','✨','⚡','💥','🎉',
    '🍓','🍕','☕','🌈','☀️','🌙','💯','🚀',
    '💀','👻','👽','🐱','🐶','🦄','🎲','🎮'
  ];
  PICKER_EMOJIS.forEach(ch => {
    const c = document.createElement('div');
    c.className = 'emoji-cell';
    c.textContent = ch;
    c.addEventListener('click', () => {
      const pos = composerEl.selectionStart || composerEl.value.length;
      const val = composerEl.value;
      composerEl.value = val.slice(0, pos) + ch + val.slice(composerEl.selectionEnd || pos);
      composerEl.selectionStart = composerEl.selectionEnd = pos + ch.length;
      composerEl.focus();
    });
    emojiGrid.appendChild(c);
  });

  emojiBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    emojiPanel.classList.toggle('open');
  });
  emojiPanel.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => emojiPanel.classList.remove('open'));

  /* ---- chat ---- */
  let lastId = 0;
  let pollTimer = null;
  let pollDelay = 1000;
  const seen = new Set();

  function fmtTime(ts) {
    const d = new Date(ts * 1000), p = n => String(n).padStart(2, '0');
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function render(msg, opts) {
    if (seen.has(msg.id)) return;
    seen.add(msg.id);

    const silent = opts && opts.silent;
    const isMine = msg.user_id === ME.id;
    if (!silent && !isMine) Sound.receive();

    const el = document.createElement('div');
    el.className = 'xp-msg' + (isMine ? ' xp-msg-me' : '');

    const head = document.createElement('div');
    head.className = 'xp-msg-head';
    head.innerHTML =
      '<span class="xp-msg-nick">' + esc(msg.nickname) + '</span>' +
      '<span class="xp-msg-id">#' + msg.user_id + '</span>' +
      (msg.tag ? '<span class="xp-msg-tag">' + esc(msg.tag) + '</span>' : '') +
      '<span class="xp-msg-time">' + fmtTime(msg.created_at) + '</span>';

    const body = document.createElement('div');
    body.className = 'xp-msg-body';
    body.innerHTML = formatBody(msg.body);

    el.appendChild(head);
    el.appendChild(body);
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function loadHistory() {
    try {
      const r = await fetch('?action=history&limit=50');
      const j = await r.json();
      if (!j.ok) return;
      j.messages.forEach(m => {
        render(m, { silent: true });
        if (m.id > lastId) lastId = m.id;
      });
    } catch (_) {}
  }

  async function loadOnline() {
    try {
      const r = await fetch('?action=online');
      const j = await r.json();
      if (!j.ok) return;
      if (!j.users.length) {
        onlineEl.innerHTML = '<div class="xp-empty">Никого нет</div>';
        return;
      }
      onlineEl.innerHTML = j.users.map(u =>
        '<div class="xp-online-user">' +
          '<span class="xp-dot"></span>' +
          '<span class="xp-online-nick">' + esc(u.nickname) + '</span>' +
          '<span class="xp-online-id">#' + u.id + '</span>' +
        '</div>'
      ).join('');
    } catch (_) {}
  }

  async function pollMessages() {
    try {
      const r = await fetch('?action=messages&since=' + lastId);
      const j = await r.json();
      if (j.ok && j.messages.length) {
        j.messages.forEach(m => {
          render(m);
          if (m.id > lastId) lastId = m.id;
        });
      }
      statusEl.textContent = 'Онлайн';
      document.body.classList.remove('xp-disconnected');
      pollDelay = 1000;
    } catch (e) {
      statusEl.textContent = 'Нет связи — переподключение…';
      document.body.classList.add('xp-disconnected');
      pollDelay = Math.min(pollDelay * 2, 5000);
    }
    clearTimeout(pollTimer);
    pollTimer = setTimeout(pollMessages, pollDelay);
  }

  async function sendMessage() {
    const body = composerEl.value.trim();
    if (!body) return;
    composerEl.value = '';
    emojiPanel.classList.remove('open');

    Sound.send();

    const fd = new FormData();
    fd.append('body', body);

    try {
      const r = await fetch('?action=send', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        render(j.message, { silent: true });
        if (j.message.id > lastId) lastId = j.message.id;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(pollMessages, 50);
      } else {
        statusEl.textContent = j.error || 'Ошибка отправки';
      }
    } catch (_) {
      statusEl.textContent = 'Ошибка сети';
    }
    composerEl.focus();
  }

  let shuttingDown = false;
  async function doLogout() {
    if (shuttingDown) return;
    shuttingDown = true;

    Sound.shutdown();

    const overlay = document.getElementById('shutdown-overlay');
    overlay.classList.add('active');

    await new Promise(r => setTimeout(r, 2400));

    try { await fetch('?action=logout', { method: 'POST' }); } catch (_) {}
    location.href = location.pathname;
  }

  function tickClock() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    clockEl.textContent = p(d.getHours()) + ':' + p(d.getMinutes());
  }

  sendBtn.addEventListener('click', sendMessage);
  composerEl.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });
  closeBtn.addEventListener('click', doLogout);

  /* ============ GLOBAL CLICK SOUND ============ */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('.xp-button, .xp-menu-item, .xp-startmenu-item, .xp-dropdown-item');
    if (!el) return;
    if (el.id === 'send-btn') return;
    if (el.id === 'emoji-btn') return;
    if (el.closest('#emoji-panel')) return;
    Sound.click();
  }, true);

  /* ============ START MENU ============ */
  const startBtn  = document.getElementById('start-btn');
  const startMenu = document.getElementById('startmenu');

  function toggleStart(force) {
    const wasOpen = startMenu.classList.contains('open');
    const open = force !== undefined ? force : !wasOpen;
    startMenu.classList.toggle('open', open);
    startBtn.classList.toggle('active', open);
    if (open && !wasOpen) Sound.startOpen();
    else if (!open && wasOpen) Sound.startClose();
  }

  startBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleStart();
  });

  document.addEventListener('click', (e) => {
    if (startMenu.classList.contains('open') &&
        !startMenu.contains(e.target) && e.target !== startBtn) {
      toggleStart(false);
    }
  });

  startMenu.addEventListener('click', (e) => e.stopPropagation());

  document.getElementById('sm-logout').addEventListener('click', () => {
    toggleStart(false);
    doLogout();
  });

  startMenu.querySelectorAll('.xp-startmenu-item[data-game]').forEach(item => {
    item.addEventListener('click', () => {
      const g = item.dataset.game;
      toggleStart(false);
      if (g === 'minesweeper') {
        openWindow({ title: 'Сапёр',   icon: '💣', width: 320, height: 400, content: gameMinesweeper() });
      } else if (g === 'puzzle') {
        openWindow({ title: 'Пятнашки', icon: '🧩', width: 300, height: 360, content: gamePuzzle() });
      } else if (g === 'snake') {
        openWindow({ title: 'Змейка',   icon: '🐍', width: 320, height: 360, content: gameSnake() });
      } else if (g === 'browser') {
        openWindow({ title: 'Fast Search — мини-браузер', icon: '🔍', width: 720, height: 520, content: fastBrowser() });
      } else if (g === 'about') {
        openWindow({ title: 'О программе', icon: 'ℹ️', width: 380, height: 220, content: aboutWindow() });
      } else if (g === 'help') {
        openWindow({ title: 'Справка — Raspberry Web Chat', icon: '❓', width: 560, height: 500, content: helpWindow() });
      }
    });
  });

  document.getElementById('menu-help').addEventListener('click', () => {
    openWindow({ title: 'Справка — Raspberry Web Chat', icon: '❓', width: 560, height: 500, content: helpWindow() });
  });

  /* ============ THEME + SOUND SWITCHER ============ */
  const themeMenu     = document.getElementById('menu-view');
  const themeDropdown = document.getElementById('view-dropdown');
  const soundToggle   = document.getElementById('sound-toggle');
  const soundTest     = document.getElementById('sound-test');
  const soundLabel    = document.getElementById('sound-toggle-label');

  function applyTheme(name) {
    document.body.classList.remove('theme-xp','theme-raspberry','theme-skype','theme-95');
    if (name !== 'xp') document.body.classList.add('theme-' + name);
    try { localStorage.setItem('rwc_theme', name); } catch (_) {}
    themeDropdown.querySelectorAll('.xp-dropdown-item[data-theme]').forEach(el => {
      el.classList.toggle('active', el.dataset.theme === name);
    });
  }

  function updateSoundLabel() {
    soundLabel.textContent = Sound.isEnabled() ? '🔊 Звуки: вкл' : '🔇 Звуки: выкл';
  }

  (function restoreTheme() {
    let saved = 'xp';
    try { saved = localStorage.getItem('rwc_theme') || 'xp'; } catch (_) {}
    if (!['xp','raspberry','skype','95'].includes(saved)) saved = 'xp';
    applyTheme(saved);
  })();
  updateSoundLabel();

  themeMenu.addEventListener('click', (e) => {
    e.stopPropagation();
    const r = themeMenu.getBoundingClientRect();
    themeDropdown.style.left = r.left + 'px';
    themeDropdown.style.top  = (r.bottom + 2) + 'px';
    themeDropdown.classList.toggle('open');
    toggleStart(false);
  });

  themeDropdown.addEventListener('click', (e) => e.stopPropagation());

  document.addEventListener('click', () => {
    themeDropdown.classList.remove('open');
  });

  themeDropdown.querySelectorAll('.xp-dropdown-item[data-theme]').forEach(item => {
    item.addEventListener('click', () => {
      applyTheme(item.dataset.theme);
      themeDropdown.classList.remove('open');
    });
  });

  soundToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const turningOn = !Sound.isEnabled();
    Sound.setEnabled(turningOn);
    updateSoundLabel();
    // при включении — короткий тест
    if (turningOn) Sound.test();
  });

  soundTest.addEventListener('click', (e) => {
    e.stopPropagation();
    if (!Sound.isEnabled()) {
      Sound.setEnabled(true);
      updateSoundLabel();
    }
    Sound.test();
  });

  /* ============ BOOT ============ */
  tickClock();
  setInterval(tickClock, 30000);
  setInterval(loadOnline, 10000);

  loadHistory().then(() => {
    statusEl.textContent = 'Онлайн';
    loadOnline();
    pollMessages();
  });

  composerEl.focus();
})();
</script>

<?php endif; ?>
</body>
</html>
