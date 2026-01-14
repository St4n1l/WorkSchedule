<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

ensureSessionStarted();
$userId = currentUserId();
$username = currentUsername();

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

$error = '';

try {
    $pdo = db();

    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare('DELETE FROM calendar_events WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        header('Location: ./calendar.php', true, 302);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $title = trim((string)($_POST['title'] ?? ''));
        $day = (int)($_POST['day'] ?? -1);
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $color = trim((string)($_POST['color'] ?? '#4f46e5'));

        $startMin = timeToMinutes($start);
        $endMin = timeToMinutes($end);

        if ($title === '') $error = 'Title is required.';
        elseif ($day < 0 || $day > 6) $error = 'Day is invalid.';
        elseif ($startMin === null || $endMin === null) $error = 'Time must be HH:MM.';
        elseif ($endMin <= $startMin) $error = 'End time must be after start time.';
        elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $error = 'Color must be like #ff0000.';

        if ($error === '') {
            $stmt = $pdo->prepare(
                'INSERT INTO calendar_events(user_id, title, day, start_min, end_min, color)
                 VALUES (:uid, :title, :day, :s, :e, :c)'
            );
            $stmt->execute([
                ':uid' => $userId,
                ':title' => $title,
                ':day' => $day,
                ':s' => $startMin,
                ':e' => $endMin,
                ':c' => $color,
            ]);
            header('Location: ./calendar.php', true, 302);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, day, start_min, end_min, color
         FROM calendar_events
         WHERE user_id = :uid
         ORDER BY day, start_min, id'
    );
    $stmt->execute([':uid' => $userId]);
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $events = [];
}

$eventsByDay = array_fill(0, 7, []);
foreach ($events as $ev) {
    $eventsByDay[(int)$ev['day']][] = $ev;
}

$days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$startHour = 6;
$endHour = 22;
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
    .timecol{ width: 78px; background: rgba(255,255,255,.06); font-weight: 700; }
    .event{
      display:block;
      margin: 4px 0;
      padding: 6px 8px;
      color: #0b1220;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.20);
      box-shadow: 0 8px 18px rgba(0,0,0,.18);
    }
    .event small{ color: rgba(11,18,32,.75); }
    .delete{
      float:right;
      color: rgba(11,18,32,.85);
      text-decoration: none;
      font-weight: 700;
      margin-left: 10px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card top">
      <div>
        <strong>Calendar</strong>
        <span style="color: rgba(255,255,255,.65);">(Hello, <?= h($username ?: 'user') ?>)</span>
      </div>
      <div><a href="./logout.php">Logout</a></div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="post">
        <strong>Add event</strong>
        <div class="row">
          <div>
            <label>Title</label>
            <input name="title" required />
          </div>
          <div>
            <label>Day</label>
            <select name="day" required>
              <?php foreach ($days as $i => $d): ?>
                <option value="<?= $i ?>"><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Start</label>
            <input type="time" name="start_time" value="12:00" required />
          </div>
          <div>
            <label>End</label>
            <input type="time" name="end_time" value="13:00" required />
          </div>
          <div>
            <label>Color</label>
            <input type="color" name="color" value="#4f46e5" />
          </div>
          <div>
            <button type="submit">Add</button>
          </div>
        </div>
      </form>
    </div>

    <div class="card table-card">
      <table>
    <thead>
      <tr>
        <th class="timecol">Time</th>
        <?php foreach ($days as $d): ?>
          <th><?= h($d) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php for ($h = $startHour; $h < $endHour; $h++): ?>
        <?php
          $slotStart = $h * 60;
          $slotEnd = ($h + 1) * 60;
          $label = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
        ?>
        <tr>
          <td class="timecol"><strong><?= h($label) ?></strong></td>
          <?php for ($day = 0; $day < 7; $day++): ?>
            <td>
              <?php
                foreach ($eventsByDay[$day] as $ev) {
                    $s = (int)$ev['start_min'];
                    $e = (int)$ev['end_min'];
                    if ($s < $slotEnd && $e > $slotStart) {
                        $t1 = str_pad((string)floor($s/60),2,'0',STR_PAD_LEFT) . ':' . str_pad((string)($s%60),2,'0',STR_PAD_LEFT);
                        $t2 = str_pad((string)floor($e/60),2,'0',STR_PAD_LEFT) . ':' . str_pad((string)($e%60),2,'0',STR_PAD_LEFT);
                        $bg = (string)$ev['color'];
                        $id = (int)$ev['id'];
                        echo '<span class="event" style="background:' . h($bg) . ';">'
                           . h((string)$ev['title'])
                           . ' <small>(' . h($t1) . '-' . h($t2) . ')</small>'
                           . ' <a class="delete" href="./calendar.php?delete=' . $id . '" title="Delete">x</a>'
                           . '</span>';
                    }
                }
              ?>
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

