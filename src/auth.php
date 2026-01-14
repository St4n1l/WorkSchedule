<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function currentUserId(): ?int
{
    ensureSessionStarted();
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) ? $id : (is_numeric($id) ? (int)$id : null);
}

function currentUsername(): ?string
{
    ensureSessionStarted();
    $u = $_SESSION['username'] ?? null;
    $u = is_string($u) ? trim($u) : '';
    return $u !== '' ? $u : null;
}

function requireLoginJson(): int
{
    $uid = currentUserId();
    if ($uid === null || $uid <= 0) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
        exit;
    }
    return $uid;
}

function loginUser(int $userId): void
{
    ensureSessionStarted();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logoutUser(): void
{
    ensureSessionStarted();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function findUserByUsername(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM calendar_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

