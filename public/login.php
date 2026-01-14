<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

ensureSessionStarted();

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $pdo = db();
            $user = findUserByUsername($pdo, $username);
            if (!$user || !password_verify($password, (string)$user['password_hash'])) {
                $error = 'Invalid username or password.';
            } else {
                loginUser((int)$user['id']);
                $_SESSION['username'] = (string)$user['username'];
                header('Location: ./calendar.php', true, 302);
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login</title>
  <style>
    *{ box-sizing: border-box; }
    body{
      margin: 0;
      min-height: 100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 20px;
      font-family: Arial, sans-serif;
      background:
        radial-gradient(1200px 600px at 10% 0%, rgba(79,70,229,.35), transparent 55%),
        radial-gradient(900px 500px at 100% 10%, rgba(16,185,129,.18), transparent 55%),
        #0b1220;
      color: rgba(255,255,255,.92);
    }
    a{ color: rgba(255,255,255,.9); }
    .box{
      width: 380px;
      border-radius: 14px;
      padding: 16px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.12);
      box-shadow: 0 10px 30px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
    }
    h2{ margin: 0 0 10px; }
    label{ display:block; margin-top: 10px; font-size: 12px; color: rgba(255,255,255,.70); }
    input{
      width: 100%;
      padding: 10px 10px;
      margin-top: 6px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.92);
      outline: none;
    }
    input:focus{ border-color: rgba(79,70,229,.65); box-shadow: 0 0 0 4px rgba(79,70,229,.20); }
    button{
      margin-top: 14px;
      padding: 10px 12px;
      border-radius: 12px;
      border: 0;
      cursor: pointer;
      font-weight: 700;
      color: #fff;
      width: 100%;
      background: linear-gradient(135deg, rgba(79,70,229,.95), rgba(99,102,241,.85));
      box-shadow: 0 10px 22px rgba(0,0,0,.22);
    }
    .err{ color: #fecaca; margin-top: 10px; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Login</h2>
    <form method="post">
      <label>Username
        <input name="username" autocomplete="username" required />
      </label>
      <label>Password
        <input type="password" name="password" autocomplete="current-password" required />
      </label>
      <button type="submit">Login</button>
    </form>

    <?php if ($error !== ''): ?>
      <div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <p style="margin-top:12px;">No account? <a href="./register.php">Register</a></p>
  </div>
</body>
</html>

