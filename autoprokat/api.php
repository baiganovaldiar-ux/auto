<?php
declare(strict_types=1);
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/src/db.php';

function out($d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function body(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }
function clean($s): string { return trim(strip_tags((string)$s)); }
function logm(string $m) {
    $dir = __DIR__ . '/logs'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/app.log', date('c') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . " $m\n", FILE_APPEND);
}
function need(?string $role = null): array {
    $u = $_SESSION['user'] ?? null;
    if (!$u) out(['error' => 'Требуется вход в аккаунт'], 401);
    if ($role && $u['role'] !== $role) out(['error' => 'Доступ запрещён'], 403);
    return $u;
}
set_exception_handler(function ($e) { logm('ERROR ' . $e->getMessage()); out(['error' => 'Внутренняя ошибка сервера'], 500); });

const CAR_SELECT = 'SELECT c.*, (SELECT ROUND(AVG(rating),1) FROM reviews WHERE car_id=c.id) AS avg_rating, (SELECT COUNT(*) FROM reviews WHERE car_id=c.id) AS reviews_count';
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$m = $_SERVER['REQUEST_METHOD'];
if ($m !== 'GET' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) out(['error' => 'Неверный CSRF-токен, обновите страницу'], 419);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$seg = explode('/', trim(substr($uri, strpos($uri, 'api.php') + 7), '/'));
$r = $seg[0]; $id = isset($seg[1]) ? (int)$seg[1] : null;
$b = $m === 'GET' ? [] : body();
logm("$m /$r" . ($id ? "/$id" : ''));

// ---------- AUTH ----------
if ($r === 'me' && $m === 'GET') {
    $fav = [];
    if (!empty($_SESSION['user'])) {
        $q = $pdo->prepare('SELECT car_id FROM favorites WHERE user_id=?'); $q->execute([$_SESSION['user']['id']]);
        $fav = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    out(['user' => $_SESSION['user'] ?? null, 'csrf' => $_SESSION['csrf'], 'favorites' => $fav]);
}

if ($r === 'register' && $m === 'POST') {
    $name = clean($b['name'] ?? ''); $email = strtolower(clean($b['email'] ?? '')); $pw = (string)($b['password'] ?? '');
    $err = [];
    if (mb_strlen($name) < 2) $err['name'] = 'Минимум 2 символа';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err['email'] = 'Неверный email';
    if (strlen($pw) < 8) $err['password'] = 'Минимум 8 символов';
    if ($err) out(['error' => 'Проверьте поля формы', 'fields' => $err], 422);
    $q = $pdo->prepare('SELECT 1 FROM users WHERE email=?'); $q->execute([$email]);
    if ($q->fetch()) out(['error' => 'Email уже занят', 'fields' => ['email' => 'Email уже занят']], 409);
    $pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => $uid, 'name' => $name, 'email' => $email, 'role' => 'user'];
    out(['user' => $_SESSION['user']], 201);
}
if ($r === 'login' && $m === 'POST') {
    $q = $pdo->prepare('SELECT * FROM users WHERE email=?'); $q->execute([strtolower(clean($b['email'] ?? ''))]);
    $u = $q->fetch();
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) { logm('LOGIN FAIL'); out(['error' => 'Неверный email или пароль'], 401); }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
    out(['user' => $_SESSION['user']]);
}
if ($r === 'logout' && $m === 'POST') { unset($_SESSION['user']); session_regenerate_id(true); out(['ok' => true]); }

// ---------- CARS ----------
if ($r === 'cars') {
    if ($m === 'GET' && !$id) {
        $w = ['1=1']; $p = [];
        if (($q = clean($_GET['q'] ?? '')) !== '') { $w[] = '(brand LIKE ? OR model LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if (!empty($_GET['category'])) { $w[] = 'category=?'; $p[] = clean($_GET['category']); }
        if (!empty($_GET['max_price'])) { $w[] = 'price_per_day<=?'; $p[] = (float)$_GET['max_price']; }
        if (!empty($_GET['from']) && !empty($_GET['to'])) {
            $w[] = "id NOT IN (SELECT car_id FROM bookings WHERE status='active' AND start_date<? AND end_date>?)";
            $p[] = (string)$_GET['to']; $p[] = (string)$_GET['from'];
        }
        $sort = in_array($_GET['sort'] ?? '', ['price_per_day', 'year', 'brand', 'avg_rating'], true) ? $_GET['sort'] : 'id';
        $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int)($_GET['page'] ?? 1)); $pp = min(50, max(1, (int)($_GET['per_page'] ?? 6)));
        $off = ($page - 1) * $pp;
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM cars WHERE ' . implode(' AND ', $w)); $cnt->execute($p);
        $total = (int)$cnt->fetchColumn();
        $s = $pdo->prepare(CAR_SELECT . ' FROM cars c WHERE ' . implode(' AND ', $w) . " ORDER BY $sort $dir LIMIT $pp OFFSET $off");
        $s->execute($p);
        out(['data' => $s->fetchAll(), 'meta' => ['page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => (int)ceil($total / $pp)]]);
    }
    if ($m === 'GET') {
        $s = $pdo->prepare('SELECT * FROM cars WHERE id=?'); $s->execute([$id]);
        ($c = $s->fetch()) ? out($c) : out(['error' => 'Авто не найдено'], 404);
    }
    need('admin');
    if ($m === 'POST' || $m === 'PUT') {
        $v = [clean($b['brand'] ?? ''), clean($b['model'] ?? ''), (int)($b['year'] ?? 0), clean($b['category'] ?? ''),
              (float)($b['price_per_day'] ?? 0), (int)($b['seats'] ?? 5),
              in_array($b['transmission'] ?? '', ['AT', 'MT'], true) ? $b['transmission'] : 'AT', clean($b['description'] ?? '')];
        $err = [];
        if (!$v[0]) $err['brand'] = 'Обязательное поле';
        if (!$v[1]) $err['model'] = 'Обязательное поле';
        if ($v[2] < 1990 || $v[2] > (int)date('Y') + 1) $err['year'] = 'Неверный год';
        if (!in_array($v[3], ['economy', 'business', 'premium', 'suv', 'minivan', 'electric'], true)) $err['category'] = 'economy, business, premium, suv, minivan или electric';
        if ($v[4] <= 0) $err['price_per_day'] = 'Цена должна быть больше 0';
        if ($err) out(['error' => 'Проверьте поля формы', 'fields' => $err], 422);
        if ($m === 'POST') {
            $pdo->prepare('INSERT INTO cars(brand,model,year,category,price_per_day,seats,transmission,description) VALUES(?,?,?,?,?,?,?,?)')->execute($v);
            out(['id' => (int)$pdo->lastInsertId()], 201);
        }
        $v[] = $id;
        $pdo->prepare('UPDATE cars SET brand=?,model=?,year=?,category=?,price_per_day=?,seats=?,transmission=?,description=? WHERE id=?')->execute($v);
        out(['ok' => true]);
    }
    if ($m === 'DELETE' && $id) { $pdo->prepare('DELETE FROM cars WHERE id=?')->execute([$id]); out(['ok' => true]); }
}

// ---------- BOOKINGS ----------
if ($r === 'bookings') {
    $u = need();
    if ($m === 'GET') {
        $sql = 'SELECT b.*, c.brand, c.model, u.email FROM bookings b JOIN cars c ON c.id=b.car_id JOIN users u ON u.id=b.user_id'
             . ($u['role'] === 'admin' ? '' : ' WHERE b.user_id=?') . ' ORDER BY b.id DESC';
        $s = $pdo->prepare($sql); $s->execute($u['role'] === 'admin' ? [] : [$u['id']]);
        out(['data' => $s->fetchAll()]);
    }
    if ($m === 'POST') {
        $cs = $pdo->prepare('SELECT * FROM cars WHERE id=?'); $cs->execute([(int)($b['car_id'] ?? 0)]);
        $car = $cs->fetch();
        if (!$car) out(['error' => 'Авто не найдено'], 404);
        $s = DateTime::createFromFormat('Y-m-d', (string)($b['start'] ?? '')); $e = DateTime::createFromFormat('Y-m-d', (string)($b['end'] ?? ''));
        if (!$s || !$e) out(['error' => 'Укажите даты в формате ГГГГ-ММ-ДД'], 422);
        $s->setTime(0, 0); $e->setTime(0, 0);
        if ($s < new DateTime('today')) out(['error' => 'Дата начала не может быть в прошлом'], 422);
        if ($e <= $s) out(['error' => 'Дата окончания должна быть позже даты начала'], 422);
        $o = $pdo->prepare("SELECT 1 FROM bookings WHERE car_id=? AND status='active' AND start_date<? AND end_date>?");
        $o->execute([$car['id'], $e->format('Y-m-d'), $s->format('Y-m-d')]);
        if ($o->fetch()) out(['error' => 'Авто уже занято на выбранные даты'], 409);
        $days = (int)$s->diff($e)->days; $total = $days * (float)$car['price_per_day'];
        $pdo->prepare('INSERT INTO bookings(user_id,car_id,start_date,end_date,total) VALUES(?,?,?,?,?)')
            ->execute([$u['id'], $car['id'], $s->format('Y-m-d'), $e->format('Y-m-d'), $total]);
        out(['id' => (int)$pdo->lastInsertId(), 'days' => $days, 'total' => $total], 201);
    }
    if ($m === 'DELETE' && $id) {
        $s = $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?" . ($u['role'] === 'admin' ? '' : ' AND user_id=?'));
        $s->execute($u['role'] === 'admin' ? [$id] : [$id, $u['id']]);
        $s->rowCount() ? out(['ok' => true]) : out(['error' => 'Бронь не найдена'], 404);
    }
}

// ---------- PROFILE ----------
if ($r === 'profile') {
    $u = need();
    if ($m === 'GET') {
        $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS spent FROM bookings WHERE user_id=? AND status='active'");
        $s->execute([$u['id']]); $st = $s->fetch();
        $d = $pdo->prepare('SELECT created_at FROM users WHERE id=?'); $d->execute([$u['id']]);
        out(['user' => $u, 'since' => $d->fetchColumn(), 'bookings' => (int)$st['n'], 'spent' => (float)$st['spent']]);
    }
    if ($m === 'PUT') {
        $name = clean($b['name'] ?? '');
        if (mb_strlen($name) < 2) out(['error' => 'Проверьте поля формы', 'fields' => ['name' => 'Минимум 2 символа']], 422);
        $pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $u['id']]);
        $_SESSION['user']['name'] = $name;
        out(['user' => $_SESSION['user']]);
    }
}
if ($r === 'password' && $m === 'POST') {
    $u = need();
    $s = $pdo->prepare('SELECT password_hash FROM users WHERE id=?'); $s->execute([$u['id']]);
    if (!password_verify((string)($b['old'] ?? ''), (string)$s->fetchColumn())) out(['error' => 'Текущий пароль неверный', 'fields' => ['old' => 'Неверный пароль']], 422);
    $new = (string)($b['new'] ?? '');
    if (strlen($new) < 8) out(['error' => 'Проверьте поля формы', 'fields' => ['new' => 'Минимум 8 символов']], 422);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    out(['ok' => true]);
}

// ---------- FAVORITES ----------
if ($r === 'favorites') {
    $u = need();
    if ($m === 'GET') {
        $s = $pdo->prepare(CAR_SELECT . ' FROM favorites f JOIN cars c ON c.id=f.car_id WHERE f.user_id=? ORDER BY f.created_at DESC');
        $s->execute([$u['id']]);
        out(['data' => $s->fetchAll()]);
    }
    if ($id && ($m === 'POST' || $m === 'DELETE')) {
        if ($m === 'POST') {
            $c = $pdo->prepare('SELECT 1 FROM cars WHERE id=?'); $c->execute([$id]);
            if (!$c->fetch()) out(['error' => 'Авто не найдено'], 404);
            $pdo->prepare('INSERT IGNORE INTO favorites(user_id,car_id) VALUES(?,?)')->execute([$u['id'], $id]);
        } else {
            $pdo->prepare('DELETE FROM favorites WHERE user_id=? AND car_id=?')->execute([$u['id'], $id]);
        }
        out(['ok' => true]);
    }
}

// ---------- REVIEWS ----------
if ($r === 'reviews') {
    if ($m === 'GET') {
        $s = $pdo->prepare('SELECT r.*, u.name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.car_id=? ORDER BY r.id DESC LIMIT 50');
        $s->execute([(int)($_GET['car_id'] ?? 0)]);
        out(['data' => $s->fetchAll()]);
    }
    $u = need();
    if ($m === 'POST') {
        $car = (int)($b['car_id'] ?? 0); $rating = (int)($b['rating'] ?? 0); $text = mb_substr(clean($b['comment'] ?? ''), 0, 500);
        if ($rating < 1 || $rating > 5) out(['error' => 'Оценка должна быть от 1 до 5'], 422);
        $h = $pdo->prepare("SELECT 1 FROM bookings WHERE user_id=? AND car_id=? AND status<>'cancelled' LIMIT 1");
        $h->execute([$u['id'], $car]);
        if (!$h->fetch()) out(['error' => 'Отзыв можно оставить после бронирования этого автомобиля'], 403);
        $pdo->prepare('INSERT INTO reviews(user_id,car_id,rating,comment) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)')
            ->execute([$u['id'], $car, $rating, $text]);
        out(['ok' => true], 201);
    }
    if ($m === 'DELETE' && $id) {
        $s = $pdo->prepare('DELETE FROM reviews WHERE id=?' . ($u['role'] === 'admin' ? '' : ' AND user_id=?'));
        $s->execute($u['role'] === 'admin' ? [$id] : [$id, $u['id']]);
        $s->rowCount() ? out(['ok' => true]) : out(['error' => 'Отзыв не найден'], 404);
    }
}

// ---------- ADMIN: STATS & USERS ----------
if ($r === 'stats' && $m === 'GET') {
    need('admin');
    $one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
    out([
        'users' => (int)$one('SELECT COUNT(*) FROM users'),
        'cars' => (int)$one('SELECT COUNT(*) FROM cars'),
        'active' => (int)$one("SELECT COUNT(*) FROM bookings WHERE status='active'"),
        'cancelled' => (int)$one("SELECT COUNT(*) FROM bookings WHERE status='cancelled'"),
        'revenue' => (float)$one("SELECT COALESCE(SUM(total),0) FROM bookings WHERE status='active'"),
        'top' => $pdo->query("SELECT CONCAT(c.brand,' ',c.model) AS name, COUNT(*) AS cnt, SUM(b.total) AS rev FROM bookings b JOIN cars c ON c.id=b.car_id WHERE b.status='active' GROUP BY c.id, c.brand, c.model ORDER BY cnt DESC LIMIT 5")->fetchAll(),
        'months' => $pdo->query("SELECT DATE_FORMAT(start_date,'%Y-%m') AS m, COUNT(*) AS cnt, SUM(total) AS rev FROM bookings WHERE status='active' GROUP BY DATE_FORMAT(start_date,'%Y-%m') ORDER BY m DESC LIMIT 6")->fetchAll(),
    ]);
}
if ($r === 'users') {
    $u = need('admin');
    if ($m === 'GET') out(['data' => $pdo->query('SELECT id,name,email,role,created_at FROM users ORDER BY id')->fetchAll()]);
    if ($m === 'PUT' && $id) {
        if ($id === (int)$u['id']) out(['error' => 'Нельзя менять собственную роль'], 422);
        if (!in_array($b['role'] ?? '', ['user', 'admin'], true)) out(['error' => 'Неверная роль'], 422);
        $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$b['role'], $id]);
        out(['ok' => true]);
    }
}

out(['error' => 'Маршрут не найден'], 404);
