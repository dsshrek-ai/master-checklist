<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['success' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// binds a variable-length parameter list (mysqli_stmt::bind_param needs refs)
function bindParams(mysqli_stmt $stmt, string $types, array $values): void {
  $refs = [];
  foreach ($values as $k => $v) {
    $refs[$k] = &$values[$k];
  }
  array_unshift($refs, $types);
  call_user_func_array([$stmt, 'bind_param'], $refs);
}

function nullableStr(?string $v): ?string {
  $v = trim((string)$v);
  return $v === '' ? null : $v;
}

// ---- Auth helpers ----

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$result) {
    fail('Session expired or invalid — please log in again', 401);
  }
  return $result;
}

// Using the app also needs an app_access grant for 'master-checklist' in My
// Apps Hub -- a MyDataWorld login on its own isn't enough.
const APP_KEY = 'master-checklist';
const NO_ACCESS_MESSAGE = 'Your login isn\'t set up for Master Checklist yet. Ask to be granted it in My Apps Hub.';

function hasAppAccess(int $userId): bool {
  $stmt = db()->prepare('SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id WHERE aa.user_id = ? AND a.app_key = ?');
  $appKey = APP_KEY;
  $stmt->bind_param('is', $userId, $appKey);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

function logAppUsage(int $userId): void {
  try {
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $appKey = APP_KEY;
    $stmt->bind_param('is', $userId, $appKey);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess((int)$user['id'])) {
    respond(['error' => NO_ACCESS_MESSAGE, 'noAccess' => true], 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

function requireListOwner(array $user, int $listId): array {
  $stmt = db()->prepare('SELECT * FROM checklist_lists WHERE id = ?');
  $stmt->bind_param('i', $listId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    fail('List not found', 404);
  }
  if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('You are not authorized to access this list', 403);
  }
  return $row;
}

function requireItemOwner(array $user, int $itemId): array {
  $stmt = db()->prepare('SELECT * FROM checklist_items WHERE id = ?');
  $stmt->bind_param('i', $itemId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    fail('Item not found', 404);
  }
  if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('You are not authorized to access this item', 403);
  }
  return $row;
}

// ---- Shaping ----

function shapeList(array $row): array {
  return [
    'id'   => (int)$row['id'],
    'name' => $row['name'],
  ];
}

function shapeItem(array $row): array {
  return [
    'id'        => (int)$row['id'],
    'listId'    => (int)$row['list_id'],
    'parentId'  => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
    'depth'     => (int)$row['depth'],
    'what'      => $row['what'],
    'where'     => $row['where_text'],
    'whenDate'  => $row['when_date'],
    'whenTime'  => $row['when_time'] !== null ? substr($row['when_time'], 0, 5) : null,
    'who'       => $row['who'],
    'complete'  => (bool)$row['complete'],
    'clearedAt' => $row['cleared_at'],
  ];
}

// ---- Tree helpers (indent/outdent) ----
// Fetches every item in a list (cleared or not — clearing only hides items
// from the UI, it never changes the underlying tree) keyed by id.
function fetchTreeRows(int $listId): array {
  $stmt = db()->prepare('SELECT id, parent_id, depth FROM checklist_items WHERE list_id = ? ORDER BY id');
  $stmt->bind_param('i', $listId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  $byId = [];
  foreach ($rows as $r) {
    $byId[(int)$r['id']] = ['parentId' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null, 'depth' => (int)$r['depth']];
  }
  return $byId;
}

function buildChildrenMap(array $byId): array {
  $children = [];
  foreach ($byId as $id => $row) {
    $key = $row['parentId'] ?? 0;
    $children[$key][] = $id;
  }
  return $children; // already in id-ascending order since fetchTreeRows orders by id
}

function collectDescendants(array $childrenMap, int $rootId): array {
  $result = [];
  $stack = $childrenMap[$rootId] ?? [];
  while ($stack) {
    $id = array_pop($stack);
    $result[] = $id;
    foreach ($childrenMap[$id] ?? [] as $childId) {
      $stack[] = $childId;
    }
  }
  return $result;
}

function subtreeMaxRelDepth(array $childrenMap, int $rootId): int {
  $max = 0;
  foreach ($childrenMap[$rootId] ?? [] as $childId) {
    $max = max($max, 1 + subtreeMaxRelDepth($childrenMap, $childId));
  }
  return $max;
}

// ---- Router ----

$action = $_GET['action'] ?? '';

switch ($action) {

  case 'login': {
    $body = jsonBody();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }

    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    if (!hasAppAccess((int)$user['id'])) {
      respond(['error' => NO_ACCESS_MESSAGE, 'noAccess' => true], 403);
    }

    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare(
      'INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();

    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $body = jsonBody();
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['success' => true]);
  }

  case 'listLists': {
    $user = requireMember();
    $stmt = db()->prepare('SELECT * FROM checklist_lists WHERE user_id = ? ORDER BY name');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    respond(['lists' => array_map('shapeList', $rows)]);
  }

  case 'addList': {
    $user = requireMember();
    $body = jsonBody();
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
      fail('List name is required');
    }

    $stmt = db()->prepare('INSERT INTO checklist_lists (user_id, name) VALUES (?, ?)');
    $stmt->bind_param('is', $user['id'], $name);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    respond(['success' => true, 'id' => $newId, 'name' => $name]);
  }

  case 'renameList': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    if ($id <= 0 || $name === '') {
      fail('List id and new name are required');
    }
    requireListOwner($user, $id);

    $stmt = db()->prepare('UPDATE checklist_lists SET name = ? WHERE id = ?');
    $stmt->bind_param('si', $name, $id);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
  }

  case 'deleteList': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('List id is required');
    }
    requireListOwner($user, $id);

    $stmt = db()->prepare('DELETE FROM checklist_lists WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
  }

  case 'listItems': {
    $user = requireMember();
    $listId = (int)($_GET['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListOwner($user, $listId);

    $stmt = db()->prepare('SELECT * FROM checklist_items WHERE list_id = ? ORDER BY id');
    $stmt->bind_param('i', $listId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $clearedCount = 0;
    $items = [];
    foreach ($rows as $row) {
      if ($row['cleared_at'] !== null) {
        $clearedCount++;
        continue;
      }
      $items[] = shapeItem($row);
    }

    respond(['items' => $items, 'clearedCount' => $clearedCount]);
  }

  case 'addItem': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    $what = trim((string)($body['what'] ?? ''));
    if ($listId <= 0 || $what === '') {
      fail('listId and what are required');
    }
    requireListOwner($user, $listId);

    $where = nullableStr($body['where'] ?? null);
    $whenDate = nullableStr($body['whenDate'] ?? null);
    $whenTime = nullableStr($body['whenTime'] ?? null);
    $who = nullableStr($body['who'] ?? null);

    // Optional parentId adds a sub-item directly under an existing item:
    // child (depth 1) or grandchild (depth 2), never deeper.
    $parentId = (int)($body['parentId'] ?? 0);
    $depth = 0;
    if ($parentId > 0) {
      $parent = requireItemOwner($user, $parentId);
      if ((int)$parent['list_id'] !== $listId) {
        fail('That parent item is in a different list');
      }
      $depth = (int)$parent['depth'] + 1;
      if ($depth > 2) {
        fail('Can\'t add a sub-item there — grandchild is the deepest level allowed');
      }
    }
    $parentParam = $parentId > 0 ? $parentId : null;

    $stmt = db()->prepare(
      'INSERT INTO checklist_items (list_id, user_id, parent_id, depth, what, where_text, when_date, when_time, who)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iiiisssss', $listId, $user['id'], $parentParam, $depth, $what, $where, $whenDate, $whenTime, $who);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    respond(['success' => true, 'id' => $newId]);
  }

  case 'updateItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    requireItemOwner($user, $id);

    $columnMap = [
      'what'     => 'what',
      'where'    => 'where_text',
      'whenDate' => 'when_date',
      'whenTime' => 'when_time',
      'who'      => 'who',
      'complete' => 'complete',
    ];

    $sets = [];
    $types = '';
    $values = [];
    foreach ($columnMap as $key => $column) {
      if (!array_key_exists($key, $body)) {
        continue;
      }
      if ($key === 'complete') {
        $sets[] = "$column = ?";
        $types .= 'i';
        $values[] = $body[$key] ? 1 : 0;
      } elseif ($key === 'what') {
        $what = trim((string)$body[$key]);
        if ($what === '') {
          fail('"What" cannot be empty');
        }
        $sets[] = "$column = ?";
        $types .= 's';
        $values[] = $what;
      } else {
        $sets[] = "$column = ?";
        $types .= 's';
        $values[] = nullableStr($body[$key]);
      }
    }

    if (!$sets) {
      fail('No fields to update');
    }

    $sql = 'UPDATE checklist_items SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $types .= 'i';
    $values[] = $id;

    $stmt = db()->prepare($sql);
    bindParams($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
  }

  case 'deleteItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    requireItemOwner($user, $id);

    // FK ON DELETE CASCADE removes any children/grandchildren with it.
    $stmt = db()->prepare('DELETE FROM checklist_items WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
  }

  case 'indentItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    $item = requireItemOwner($user, $id);
    $listId = (int)$item['list_id'];
    $depth = (int)$item['depth'];
    $parentKey = $item['parent_id'] !== null ? (int)$item['parent_id'] : 0;

    $byId = fetchTreeRows($listId);
    $childrenMap = buildChildrenMap($byId);
    $siblings = $childrenMap[$parentKey] ?? [];
    $idx = array_search($id, $siblings, true);

    if ($idx === false || $idx === 0) {
      fail('No preceding item at this level to nest under');
    }

    $candidateId = $siblings[$idx - 1];
    $newDepth = $depth + 1;
    $relMax = subtreeMaxRelDepth($childrenMap, $id);

    if ($newDepth + $relMax > 2) {
      fail('Can\'t indent further — grandchild is the deepest level allowed');
    }

    $stmt = db()->prepare('UPDATE checklist_items SET parent_id = ?, depth = ? WHERE id = ?');
    $stmt->bind_param('iii', $candidateId, $newDepth, $id);
    $stmt->execute();
    $stmt->close();

    $descendants = collectDescendants($childrenMap, $id);
    if ($descendants) {
      $placeholders = implode(',', array_fill(0, count($descendants), '?'));
      $stmt = db()->prepare("UPDATE checklist_items SET depth = depth + 1 WHERE id IN ($placeholders)");
      bindParams($stmt, str_repeat('i', count($descendants)), $descendants);
      $stmt->execute();
      $stmt->close();
    }

    respond(['success' => true]);
  }

  case 'outdentItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    $item = requireItemOwner($user, $id);
    $listId = (int)$item['list_id'];

    if ($item['parent_id'] === null) {
      fail('Already at the top level');
    }

    $byId = fetchTreeRows($listId);
    $childrenMap = buildChildrenMap($byId);
    $parentId = (int)$item['parent_id'];
    $newParentId = $byId[$parentId]['parentId']; // may be null
    $newDepth = (int)$item['depth'] - 1;

    $stmt = db()->prepare('UPDATE checklist_items SET parent_id = ?, depth = ? WHERE id = ?');
    $stmt->bind_param('iii', $newParentId, $newDepth, $id);
    $stmt->execute();
    $stmt->close();

    $descendants = collectDescendants($childrenMap, $id);
    if ($descendants) {
      $placeholders = implode(',', array_fill(0, count($descendants), '?'));
      $stmt = db()->prepare("UPDATE checklist_items SET depth = depth - 1 WHERE id IN ($placeholders)");
      bindParams($stmt, str_repeat('i', count($descendants)), $descendants);
      $stmt->execute();
      $stmt->close();
    }

    respond(['success' => true]);
  }

  case 'clearChecked': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListOwner($user, $listId);

    $stmt = db()->prepare(
      'UPDATE checklist_items SET cleared_at = NOW() WHERE list_id = ? AND complete = 1 AND cleared_at IS NULL'
    );
    $stmt->bind_param('i', $listId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    respond(['success' => true, 'cleared' => $count]);
  }

  case 'deleteChecked': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListOwner($user, $listId);

    // Permanent, unlike clearChecked — also takes any cleared-but-checked
    // items with it, and cascades to children/grandchildren via the FK.
    $stmt = db()->prepare('DELETE FROM checklist_items WHERE list_id = ? AND complete = 1');
    $stmt->bind_param('i', $listId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    respond(['success' => true, 'deleted' => $count]);
  }

  case 'restoreCleared': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListOwner($user, $listId);

    $stmt = db()->prepare(
      'UPDATE checklist_items SET cleared_at = NULL WHERE list_id = ? AND cleared_at IS NOT NULL'
    );
    $stmt->bind_param('i', $listId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    respond(['success' => true, 'restored' => $count]);
  }

  default:
    fail('Unknown action', 404);
}
