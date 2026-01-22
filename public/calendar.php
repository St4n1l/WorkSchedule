<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

ensureSessionStarted();
$userId = currentUserId();
$username = currentUsername();
$isAdmin = currentIsAdmin();

if (!$userId) {
    header('Location: ./login.php', true, 302);
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function timeToMinutes(string $t): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) return null;
    $hh = (int)$m[1]; $mm = (int)$m[2];
    if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) return null;
    return $hh * 60 + $mm;
}
function minutesToTime(int $m): string {
    $m = max(0, min(24 * 60, $m));
    $hh = (int)floor($m / 60);
    $mm = $m % 60;
    return str_pad((string)$hh, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT);
}

function currentYearMonthFromQuery(): array {
    $now = new DateTimeImmutable('now');
    $y = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)$now->format('Y');
    $m = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)$now->format('n');
    if ($y < 1970) $y = 1970;
    if ($y > 2100) $y = 2100;
    if ($m < 1) $m = 1;
    if ($m > 12) $m = 12;
    return [$y, $m];
}

function redirectToMonth(int $year, int $month): void {
    header('Location: ./calendar.php?year=' . $year . '&month=' . $month, true, 302);
    exit;
}

function dateFromYmd(string $s): ?DateTimeImmutable {
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if (!$dt) return null;
    if ($dt->format('Y-m-d') !== $s) return null;
    return $dt;
}

[$year, $month] = currentYearMonthFromQuery();

$error = '';
$editing = null;

try {
    $pdo = db();

    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare('DELETE FROM calendar_events WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        redirectToMonth($year, $month);
    }

    $editing = null;
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editId = (int)$_GET['edit'];
        $stmt = $pdo->prepare(
            'SELECT id, title, description, event_date, category_id, start_min, end_min, color
             FROM calendar_events
             WHERE id = :id AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([':id' => $editId, ':uid' => $userId]);
        $editing = $stmt->fetch() ?: null;
        if (!$editing) {
            $error = 'Event not found (or not yours).';
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? 'save_event') === 'save_event') {
        $id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $eventDateStr = (string)($_POST['event_date'] ?? '');
        $eventDate = dateFromYmd($eventDateStr);
        $categoryId = isset($_POST['category_id']) && is_numeric($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $color = trim((string)($_POST['color'] ?? '#4f46e5'));

        $startMin = timeToMinutes($start);
        $endMin = timeToMinutes($end);

        if ($title === '') $error = 'Title is required.';
        elseif ($eventDate === null) $error = 'Date is invalid.';
        elseif ($startMin === null || $endMin === null) $error = 'Time must be HH:MM.';
        elseif ($endMin <= $startMin) $error = 'End time must be after start time.';
        elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $error = 'Color must be like #ff0000.';

        if ($error === '') {
            if ($id !== null && $id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE calendar_events
                     SET title = :title, description = :desc, event_date = :d, category_id = :cat, start_min = :s, end_min = :e, color = :c
                     WHERE id = :id AND user_id = :uid'
                );
                $stmt->execute([
                    ':uid' => $userId,
                    ':id' => $id,
                    ':title' => $title,
                    ':desc' => $description,
                    ':d' => $eventDate->format('Y-m-d'),
                    ':cat' => $categoryId,
                    ':s' => $startMin,
                    ':e' => $endMin,
                    ':c' => $color,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO calendar_events(user_id, title, description, event_date, category_id, start_min, end_min, color)
                     VALUES (:uid, :title, :desc, :d, :cat, :s, :e, :c)'
                );
                $stmt->execute([
                    ':uid' => $userId,
                    ':title' => $title,
                    ':desc' => $description,
                    ':d' => $eventDate->format('Y-m-d'),
                    ':cat' => $categoryId,
                    ':s' => $startMin,
                    ':e' => $endMin,
                    ':c' => $color,
                ]);
            }
            redirectToMonth($year, $month);
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
    $categoriesById = [];
    foreach ($categories as $c) {
        $categoriesById[(int)$c['id']] = $c;
    }

    $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $gridStart = $firstOfMonth->modify('monday this week');
    $gridEnd = $gridStart->modify('+41 days');

    $stmt = $pdo->prepare(
        'SELECT id, title, description, event_date, category_id, start_min, end_min, color
         FROM calendar_events
         WHERE user_id = :uid
           AND event_date BETWEEN :from AND :to
         ORDER BY event_date, start_min, id'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':from' => $gridStart->format('Y-m-d'),
        ':to' => $gridEnd->format('Y-m-d'),
    ]);
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $events = [];
    $editing = null;
    $categories = [];
    $categoriesById = [];
}

$eventsByDate = [];
foreach ($events as $ev) {
    $d = (string)($ev['event_date'] ?? '');
    if ($d === '') continue;
    if (!isset($eventsByDate[$d])) $eventsByDate[$d] = [];
    $eventsByDate[$d][] = $ev;
}

$days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$monthLabel = $firstOfMonth->format('F Y');
$prev = $firstOfMonth->modify('-1 month');
$next = $firstOfMonth->modify('+1 month');
$gridStart = $firstOfMonth->modify('monday this week');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Calendar</title>
  <style>
    *{ box-sizing: border-box; }
    body{
      margin: 0;
      font-family: Arial, sans-serif;
      min-height: 100vh;
      background:
        radial-gradient(1200px 600px at 10% 0%, rgba(79,70,229,.35), transparent 55%),
        radial-gradient(900px 500px at 100% 10%, rgba(16,185,129,.18), transparent 55%),
        #0b1220;
      color: rgba(255,255,255,.92);
    }
    .wrap{ max-width: 1200px; margin: 0 auto; padding: 20px 16px 28px; }
    .card{
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
    }
    .top{
      display:flex;
      justify-content: space-between;
      align-items:center;
      margin-bottom: 12px;
      padding: 12px 14px;
    }
    a{ color: rgba(255,255,255,.9); text-decoration: none; }
    a:hover{ text-decoration: underline; }
    .error{ color: #fecaca; margin: 10px 0; }

    form{
      margin: 12px 0 16px;
      padding: 12px 14px;
    }
    .row{ display:flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-top: 10px; }
    label{ font-size: 12px; color: rgba(255,255,255,.65); display:block; margin-bottom: 6px; }
    input, select{
      padding: 10px 10px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.92);
      outline: none;
      min-width: 150px;
    }
    input[type="color"]{ padding: 0; width: 52px; height: 42px; min-width: 52px; }
    select option{ color: #0b1220; background: #fff; } /* better contrast in dropdown list */
    input:focus, select:focus{ border-color: rgba(79,70,229,.65); box-shadow: 0 0 0 4px rgba(79,70,229,.20); }
    button{
      padding: 10px 12px;
      border-radius: 12px;
      border: 0;
      cursor: pointer;
      font-weight: 700;
      color: #fff;
      background: linear-gradient(135deg, rgba(79,70,229,.95), rgba(99,102,241,.85));
      box-shadow: 0 10px 22px rgba(0,0,0,.22);
    }

    .table-card{ padding: 12px 14px; }
    table{ width: 100%; border-collapse: separate; border-spacing: 0; overflow: hidden; border-radius: 14px; }
    th, td{
      border-right: 1px solid rgba(255,255,255,.12);
      border-bottom: 1px solid rgba(255,255,255,.12);
      padding: 8px;
      vertical-align: top;
      background: rgba(255,255,255,.03);
    }
    th{
      background: rgba(255,255,255,.06);
      font-weight: 700;
      font-size: 13px;
      color: rgba(255,255,255,.90);
    }
    tr:last-child td{ border-bottom: 0; }
    th:last-child, td:last-child{ border-right: 0; }
    .month-cell{
      height: 140px;
      vertical-align: top;
      padding: 10px;
    }
    .month-cell .date{
      display:flex;
      justify-content: space-between;
      align-items: baseline;
      margin-bottom: 6px;
      font-weight: 800;
      color: rgba(255,255,255,.92);
    }
    .month-cell.outside .date{ color: rgba(255,255,255,.45); }
    .month-cell.outside{ background: rgba(255,255,255,.02); }
    .month-cell.today{
      outline: 2px solid rgba(79,70,229,.55);
      outline-offset: -2px;
      box-shadow: inset 0 0 0 1px rgba(79,70,229,.25);
    }
    .event{
      display:block;
      margin: 4px 0;
      padding: 6px 8px;
      color: #0b1220;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.20);
      box-shadow: 0 8px 18px rgba(0,0,0,.18);
      text-decoration: none;
    }
    .event small{ color: rgba(11,18,32,.75); }
    .event .actions{ float:right; display:flex; gap:10px; }
    .event .actions a{ color: rgba(11,18,32,.85); text-decoration: none; font-weight: 900; }
    .event .desc{ display:block; margin-top: 2px; color: rgba(11,18,32,.78); }
    .muted{ color: rgba(255,255,255,.65); }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card top">
      <div>
        <strong>Calendar</strong>
        <span style="color: rgba(255,255,255,.65);">(Hello, <?= h($username ?: 'user') ?>)</span>
      </div>
      <div style="display:flex; gap: 12px; align-items:center;">
        <?php if ($isAdmin): ?>
          <a href="./admin.php">Admin panel</a>
        <?php endif; ?>
        <a href="./logout.php">Logout</a>
      </div>
    </div>

    <div class="card top" style="margin-bottom: 12px;">
      <div>
        <a href="./calendar.php?year=<?= (int)$prev->format('Y') ?>&month=<?= (int)$prev->format('n') ?>">← Prev</a>
        <span style="margin: 0 10px; font-weight: 800;"><?= h($monthLabel) ?></span>
        <a href="./calendar.php?year=<?= (int)$next->format('Y') ?>&month=<?= (int)$next->format('n') ?>">Next →</a>
      </div>
      <div class="muted">Month view</div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="save_event" />
        <?php if ($editing): ?>
          <strong>Edit event</strong>
          <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>" />
        <?php else: ?>
          <strong>Add event</strong>
        <?php endif; ?>
        <div class="row">
          <div>
            <label>Title</label>
            <input name="title" required value="<?= h((string)($editing['title'] ?? '')) ?>" />
          </div>
          <div style="flex: 1; min-width: 220px;">
            <label>Description</label>
            <input name="description" placeholder="Optional notes…" value="<?= h((string)($editing['description'] ?? '')) ?>" />
          </div>
          <div>
            <label>Date</label>
            <input type="date" name="event_date" required value="<?= h((string)($editing['event_date'] ?? $firstOfMonth->format('Y-m-d'))) ?>" />
          </div>
          <div>
            <label>Category</label>
            <select name="category_id">
              <option value="">(none)</option>
              <?php foreach ($categories as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?= $cid ?>" <?= ((int)($editing['category_id'] ?? 0) === $cid) ? 'selected' : '' ?>>
                  <?= h((string)$c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Start</label>
            <input type="time" name="start_time" value="<?= h($editing ? minutesToTime((int)$editing['start_min']) : '12:00') ?>" required />
          </div>
          <div>
            <label>End</label>
            <input type="time" name="end_time" value="<?= h($editing ? minutesToTime((int)$editing['end_min']) : '13:00') ?>" required />
          </div>
          <div>
            <label>Color</label>
            <input type="color" name="color" value="<?= h((string)($editing['color'] ?? '#4f46e5')) ?>" />
          </div>
          <div>
            <button type="submit"><?= $editing ? 'Save' : 'Add' ?></button>
            <?php if ($editing): ?>
              <a style="margin-left:10px;" href="./calendar.php?year=<?= $year ?>&month=<?= $month ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>

    <div class="card table-card">
      <table>
    <thead>
      <tr>
        <?php foreach ($days as $d): ?>
          <th><?= h($d) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        for ($week = 0; $week < 6; $week++):
      ?>
        <tr>
          <?php for ($dow = 0; $dow < 7; $dow++): ?>
            <?php
              $i = $week * 7 + $dow;
              $date = $gridStart->modify('+' . $i . ' days');
              $inMonth = (int)$date->format('n') === (int)$month;
              $isToday = $date->format('Y-m-d') === $today;
              $cellClasses = 'month-cell' . ($inMonth ? '' : ' outside') . ($isToday ? ' today' : '');
              $dateKey = $date->format('Y-m-d');
            ?>
            <td class="<?= h($cellClasses) ?>">
              <div class="date">
                <span><?= (int)$date->format('j') ?></span>
                <small class="muted"><?= h($date->format('D')) ?></small>
              </div>
              <?php foreach (($eventsByDate[$dateKey] ?? []) as $ev): ?>
                <?php
                  $s = (int)$ev['start_min'];
                  $e = (int)$ev['end_min'];
                  $t1 = minutesToTime($s);
                  $t2 = minutesToTime($e);
                  $bg = (string)$ev['color'];
                  $id = (int)$ev['id'];
                  $desc = trim((string)($ev['description'] ?? ''));
                  $catId = isset($ev['category_id']) ? (int)$ev['category_id'] : 0;
                  $catName = ($catId > 0 && isset($categoriesById[$catId])) ? (string)$categoriesById[$catId]['name'] : '';
                ?>
                <span class="event" style="background:<?= h($bg) ?>;" title="<?= h($desc) ?>">
                  <span class="actions">
                    <a href="./calendar.php?edit=<?= $id ?>&year=<?= $year ?>&month=<?= $month ?>" title="Edit">✎</a>
                    <a href="./calendar.php?delete=<?= $id ?>&year=<?= $year ?>&month=<?= $month ?>" title="Delete" onclick="return confirm('Delete this event?')">x</a>
                  </span>
                  <?= h((string)$ev['title']) ?>
                  <small>(<?= h($t1) ?>-<?= h($t2) ?>)</small>
                  <?php if ($catName !== ''): ?>
                    <small class="desc">#<?= h($catName) ?></small>
                  <?php endif; ?>
                  <?php if ($desc !== ''): ?>
                    <small class="desc"><?= h($desc) ?></small>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
      </table>
    </div>
  </div>
</body>
</html>

