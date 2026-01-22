<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

ensureSessionStarted();
$userId = currentUserId();
$username = currentUsername();
$isAdmin = strtolower((string)$username) === 'admin';

if (!$userId) {
    header('Location: ./login.php', true, 302);
    exit;
}
if (!$isAdmin) {
    header('Location: ./calendar.php', true, 302);
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$error = '';

try {
    $pdo = db();

    if (isset($_GET['delete_category']) && is_numeric($_GET['delete_category'])) {
        $cid = (int)$_GET['delete_category'];
        $stmt = $pdo->prepare('DELETE FROM calendar_categories WHERE id = :id AND user_id IS NULL');
        $stmt->execute([':id' => $cid]);
        header('Location: ./admin.php', true, 302);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $catName = trim((string)($_POST['category_name'] ?? ''));
        $catColor = trim((string)($_POST['category_color'] ?? '#64748b'));

        if ($catName === '') {
            $error = 'Category name is required.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $catColor)) {
            $error = 'Category color must be like #ff0000.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO calendar_categories(user_id, name, color)
                 VALUES (NULL, :name, :color)
                 ON CONFLICT (name) WHERE user_id IS NULL
                 DO UPDATE SET color = EXCLUDED.color'
            );
            $stmt->execute([':name' => $catName, ':color' => $catColor]);
            header('Location: ./admin.php', true, 302);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, color
         FROM calendar_categories
         WHERE user_id IS NULL
         ORDER BY name, id'
    );
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $categories = [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin</title>
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
    a{ color: rgba(255,255,255,.9); text-decoration:none; }
    a:hover{ text-decoration: underline; }
    .box{
      width: min(520px, 100%);
      border-radius: 14px;
      padding: 16px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.12);
      box-shadow: 0 10px 30px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
    }
    .top{
      display:flex;
      justify-content: space-between;
      align-items:center;
      gap: 12px;
      margin-bottom: 10px;
    }
    h2{ margin: 0; }
    .muted{ color: rgba(255,255,255,.65); font-size: 13px; }
    .err{ color: #fecaca; margin-top: 10px; }
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
    input[type="color"]{ padding: 0; width: 64px; height: 42px; }
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
    .row{ display:flex; gap: 12px; align-items: end; }
    .row > div{ flex: 1; }
    .row .shrink{ flex: 0 0 auto; }
    .list{ margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,.12); }
    .pill{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      margin: 6px 10px 0 0;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.12);
      font-size: 13px;
    }
    .dot{ width: 10px; height: 10px; border-radius: 999px; }
  </style>
</head>
<body>
  <div class="box">
    <div class="top">
      <div>
        <h2>Admin</h2>
        <div class="muted">Manage global categories</div>
      </div>
      <div><a href="./calendar.php">Back to calendar</a></div>
    </div>

    <form method="post">
      <div class="row">
        <div>
          <label>Name
            <input name="category_name" placeholder="Hobby, Work, Free time…" />
          </label>
        </div>
        <div class="shrink">
          <label>Color
            <input type="color" name="category_color" value="#64748b" />
          </label>
        </div>
      </div>
      <button type="submit">Add / Update</button>
    </form>

    <?php if ($error !== ''): ?>
      <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="list">
      <strong>Existing categories</strong>
      <?php if (empty($categories)): ?>
        <div class="muted" style="margin-top:8px;">No categories yet.</div>
      <?php else: ?>
        <div>
          <?php foreach ($categories as $c): ?>
            <?php $cid = (int)$c['id']; ?>
            <span class="pill">
              <span class="dot" style="background:<?= h((string)$c['color']) ?>;"></span>
              <?= h((string)$c['name']) ?>
              <a class="muted" href="./admin.php?delete_category=<?= $cid ?>" onclick="return confirm('Delete this category?')">delete</a>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

