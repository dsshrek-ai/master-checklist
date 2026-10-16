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

// prepared SELECT -> all rows
function q(string $sql, string $types = '', array $values = []): array {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    bindParams($stmt, $types, $values);
  }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
}

// prepared INSERT/UPDATE/DELETE -> insert id (or affected rows when no insert)
function exec1(string $sql, string $types = '', array $values = []): int {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    bindParams($stmt, $types, $values);
  }
  $stmt->execute();
  $result = $stmt->insert_id ?: $stmt->affected_rows;
  $stmt->close();
  return (int)$result;
}

function nullableStr($v, int $maxLen = 0): ?string {
  $v = trim((string)$v);
  if ($v === '') {
    return null;
  }
  return $maxLen > 0 ? mb_substr($v, 0, $maxLen) : $v;
}

function inPlaceholders(array $ids): string {
  return implode(',', array_fill(0, count($ids), '?'));
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

// A list is reachable by its owner and by anyone it's shared with
// (checklist_list_shares). Shared members can do everything to the items;
// only the owner can rename/delete the list or change who it's shared with.
function requireListAccess(array $user, int $listId): array {
  $rows = q('SELECT * FROM checklist_lists WHERE id = ?', 'i', [$listId]);
  if (!$rows) {
    fail('List not found', 404);
  }
  $row = $rows[0];
  $row['is_owner'] = (int)$row['user_id'] === (int)$user['id'];
  if (!$row['is_owner']
      && !q('SELECT 1 FROM checklist_list_shares WHERE list_id = ? AND user_id = ?', 'ii', [$listId, (int)$user['id']])) {
    fail('You are not authorized to access this list', 403);
  }
  return $row;
}

function requireListOwner(array $user, int $listId): array {
  $row = requireListAccess($user, $listId);
  if (!$row['is_owner']) {
    fail('Only the list\'s owner can do that', 403);
  }
  return $row;
}

function requireItemAccess(array $user, int $itemId): array {
  $rows = q('SELECT * FROM checklist_items WHERE id = ?', 'i', [$itemId]);
  if (!$rows) {
    fail('Item not found', 404);
  }
  requireListAccess($user, (int)$rows[0]['list_id']);
  return $rows[0];
}

// ---- Shaping ----

function shapeList(array $row, int $userId): array {
  return [
    'id'         => (int)$row['id'],
    'name'       => $row['name'],
    'isOwner'    => (int)$row['user_id'] === $userId,
    'ownerName'  => $row['owner_name'] ?: $row['owner_username'],
    'shareCount' => (int)$row['share_count'],
    'total'      => (int)$row['total'],
    'done'       => (int)$row['done'],
  ];
}

function shapeItem(array $row): array {
  return [
    'id'          => (int)$row['id'],
    'listId'      => (int)$row['list_id'],
    'parentId'    => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
    'depth'       => (int)$row['depth'],
    'sortOrder'   => (int)$row['sort_order'],
    'what'        => $row['what'],
    'where'       => $row['where_text'],
    'whenDate'    => $row['when_date'],
    'whenTime'    => $row['when_time'] !== null ? substr($row['when_time'], 0, 5) : null,
    'who'         => $row['who'],
    'contactName' => $row['contact_name'],
    'phoneNumber' => $row['phone_number'],
    'notes'       => $row['notes'],
    'complete'    => (bool)$row['complete'],
    'clearedAt'   => $row['cleared_at'],
    'attachments' => [],
  ];
}

function shapeAttachment(array $row): array {
  return [
    'id'       => (int)$row['id'],
    'itemId'   => (int)$row['item_id'],
    'fileName' => $row['file_name'],
    'fileType' => $row['file_type'],
    'fileSize' => (int)$row['file_size'],
  ];
}

function shapeMember(array $row): array {
  return [
    'userId'      => (int)$row['id'],
    'username'    => $row['username'],
    'displayName' => $row['display_name'] ?: $row['username'],
  ];
}

// ---- Tree helpers (indent/outdent/move) ----
// Fetches every item in a list (cleared or not — clearing only hides items
// from the UI, it never changes the underlying tree) keyed by id, in display
// order (sort_order, then id).
function fetchTreeRows(int $listId): array {
  $rows = q('SELECT id, parent_id, depth, cleared_at FROM checklist_items WHERE list_id = ? ORDER BY sort_order, id', 'i', [$listId]);
  $byId = [];
  foreach ($rows as $r) {
    $byId[(int)$r['id']] = [
      'parentId' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
      'depth'    => (int)$r['depth'],
      'cleared'  => $r['cleared_at'] !== null,
    ];
  }
  return $byId;
}

function buildChildrenMap(array $byId): array {
  $children = [];
  foreach ($byId as $id => $row) {
    $key = $row['parentId'] ?? 0;
    $children[$key][] = $id;
  }
  return $children; // in display order since fetchTreeRows orders by sort_order
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

// Rewrites sort_order as 10, 20, 30… in the given order (one sibling group).
function renumber(array $orderedIds): void {
  $stmt = db()->prepare('UPDATE checklist_items SET sort_order = ? WHERE id = ?');
  $order = 0;
  foreach ($orderedIds as $id) {
    $order += 10;
    $stmt->bind_param('ii', $order, $id);
    $stmt->execute();
  }
  $stmt->close();
}

function nextSortOrder(int $listId): int {
  $rows = q('SELECT COALESCE(MAX(sort_order), 0) + 10 AS next FROM checklist_items WHERE list_id = ?', 'i', [$listId]);
  return (int)$rows[0]['next'];
}

// ---- Attachments ----

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

// The served Content-Type comes from this map, never from what the browser
// claimed on upload -- so a shared list can't be used to serve HTML/script.
const ALLOWED_TYPES = [
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'png'  => 'image/png',
  'gif'  => 'image/gif',
  'webp' => 'image/webp',
  'heic' => 'image/heic',
  'heif' => 'image/heif',
  'pdf'  => 'application/pdf',
  'txt'  => 'text/plain',
  'rtf'  => 'application/rtf',
  'csv'  => 'text/csv',
  'doc'  => 'application/msword',
  'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'xls'  => 'application/vnd.ms-excel',
  'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'ppt'  => 'application/vnd.ms-powerpoint',
  'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

function uploadDir(): string {
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
  }
  // Belt and braces: never serve these directly, only through the API.
  if (!file_exists($dir . '/.htaccess')) {
    file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
  }
  return $dir;
}

function newStoredName(): string {
  return bin2hex(random_bytes(16));
}

function deleteStoredFiles(array $storedNames): void {
  $dir = uploadDir();
  foreach ($storedNames as $name) {
    $path = $dir . '/' . basename($name);
    if (is_file($path)) {
      unlink($path);
    }
  }
}

function storedNamesForItems(array $itemIds): array {
  if (!$itemIds) {
    return [];
  }
  $rows = q('SELECT stored_name FROM checklist_attachments WHERE item_id IN (' . inPlaceholders($itemIds) . ')',
            str_repeat('i', count($itemIds)), $itemIds);
  return array_column($rows, 'stored_name');
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
    $userId = (int)$user['id'];
    // Own lists plus lists shared with me, with done/total for the picker.
    $rows = q(
      'SELECT l.id, l.name, l.user_id, u.display_name AS owner_name, u.username AS owner_username,
         (SELECT COUNT(*) FROM checklist_list_shares s WHERE s.list_id = l.id) AS share_count,
         (SELECT COUNT(*) FROM checklist_items i WHERE i.list_id = l.id AND i.cleared_at IS NULL) AS total,
         (SELECT COUNT(*) FROM checklist_items i WHERE i.list_id = l.id AND i.cleared_at IS NULL AND i.complete = 1) AS done
       FROM checklist_lists l JOIN users u ON u.id = l.user_id
       WHERE l.user_id = ?
          OR EXISTS (SELECT 1 FROM checklist_list_shares s WHERE s.list_id = l.id AND s.user_id = ?)
       ORDER BY l.name',
      'ii', [$userId, $userId]
    );
    respond(['lists' => array_map(fn($r) => shapeList($r, $userId), $rows)]);
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

    $files = array_column(q(
      'SELECT a.stored_name FROM checklist_attachments a JOIN checklist_items i ON i.id = a.item_id WHERE i.list_id = ?',
      'i', [$id]
    ), 'stored_name');

    // FKs cascade to items, their attachment rows and the share rows.
    exec1('DELETE FROM checklist_lists WHERE id = ?', 'i', [$id]);
    deleteStoredFiles($files);

    respond(['success' => true]);
  }

  // Copies a list (owned or shared with me) into a new list I own: every
  // visible item, unchecked, same nesting and order, files copied too.
  case 'duplicateList': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    if ($id <= 0 || $name === '') {
      fail('List id and a name for the copy are required');
    }
    requireListAccess($user, $id);
    $userId = (int)$user['id'];

    // Parents before children, so each child's new parent id is known.
    $items = q('SELECT * FROM checklist_items WHERE list_id = ? AND cleared_at IS NULL ORDER BY depth, sort_order, id', 'i', [$id]);
    $attachments = q(
      'SELECT a.* FROM checklist_attachments a JOIN checklist_items i ON i.id = a.item_id
       WHERE i.list_id = ? AND i.cleared_at IS NULL ORDER BY a.id',
      'i', [$id]
    );

    $copiedFiles = [];
    db()->begin_transaction();
    try {
      $newListId = exec1('INSERT INTO checklist_lists (user_id, name) VALUES (?, ?)', 'is', [$userId, $name]);

      $newIds = [];    // old item id => new item id
      $newDepths = []; // new item id => depth
      foreach ($items as $it) {
        $oldParent = $it['parent_id'] !== null ? (int)$it['parent_id'] : null;
        // A child whose parent was cleared (so not copied) becomes top level.
        $newParent = ($oldParent !== null && isset($newIds[$oldParent])) ? $newIds[$oldParent] : null;
        $depth = $newParent !== null ? $newDepths[$newParent] + 1 : 0;
        $sort = (int)$it['sort_order'];
        $newId = exec1(
          'INSERT INTO checklist_items (list_id, user_id, parent_id, depth, sort_order, what, where_text, when_date, when_time, who, contact_name, phone_number, notes)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          'iiiiissssssss',
          [$newListId, $userId, $newParent, $depth, $sort, $it['what'], $it['where_text'], $it['when_date'], $it['when_time'],
           $it['who'], $it['contact_name'], $it['phone_number'], $it['notes']]
        );
        $newIds[(int)$it['id']] = $newId;
        $newDepths[$newId] = $depth;
      }

      $dir = uploadDir();
      foreach ($attachments as $a) {
        $src = $dir . '/' . basename($a['stored_name']);
        $stored = newStoredName();
        if (!isset($newIds[(int)$a['item_id']]) || !is_file($src) || !copy($src, $dir . '/' . $stored)) {
          continue;
        }
        $copiedFiles[] = $stored;
        exec1(
          'INSERT INTO checklist_attachments (item_id, user_id, file_name, file_type, file_size, stored_name) VALUES (?, ?, ?, ?, ?, ?)',
          'iissis',
          [$newIds[(int)$a['item_id']], $userId, $a['file_name'], $a['file_type'], (int)$a['file_size'], $stored]
        );
      }
      db()->commit();
    } catch (Throwable $e) {
      db()->rollback();
      deleteStoredFiles($copiedFiles);
      fail('Couldn\'t copy the list', 500);
    }

    respond(['success' => true, 'id' => $newListId, 'name' => $name]);
  }

  // ---- Sharing ----

  case 'listShares': {
    $user = requireMember();
    $listId = (int)($_GET['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    $list = requireListAccess($user, $listId);

    $owner = q('SELECT id, username, display_name FROM users WHERE id = ?', 'i', [(int)$list['user_id']]);
    $members = q(
      'SELECT u.id, u.username, u.display_name FROM checklist_list_shares s JOIN users u ON u.id = s.user_id
       WHERE s.list_id = ? ORDER BY s.created_at',
      'i', [$listId]
    );
    respond([
      'me'      => (int)$user['id'],
      'isOwner' => $list['is_owner'],
      'owner'   => $owner ? shapeMember($owner[0]) : null,
      'members' => array_map('shapeMember', $members),
    ]);
  }

  case 'shareList': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    $username = trim((string)($body['username'] ?? ''));
    if ($listId <= 0 || $username === '') {
      fail('listId and username are required');
    }
    requireListOwner($user, $listId);

    $found = q('SELECT id, username, display_name FROM users WHERE username = ?', 's', [$username]);
    if (!$found) {
      fail('No MyDataWorld login has that username', 404);
    }
    $target = $found[0];
    if ((int)$target['id'] === (int)$user['id']) {
      fail('That\'s you — you already own this list');
    }
    if (!hasAppAccess((int)$target['id'])) {
      fail(($target['display_name'] ?: $target['username']) . ' isn\'t set up for Master Checklist yet. Grant them access in My Apps Hub first.');
    }

    exec1('INSERT IGNORE INTO checklist_list_shares (list_id, user_id) VALUES (?, ?)', 'ii', [$listId, (int)$target['id']]);
    respond(['success' => true, 'member' => shapeMember($target)]);
  }

  // The owner can remove anyone; a member can remove themselves ("leave",
  // which is just userId omitted).
  case 'unshareList': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    $userId = (int)($body['userId'] ?? 0) ?: (int)$user['id'];
    if ($listId <= 0) {
      fail('listId is required');
    }
    $list = requireListAccess($user, $listId);
    if (!$list['is_owner'] && $userId !== (int)$user['id']) {
      fail('Only the list\'s owner can do that', 403);
    }

    exec1('DELETE FROM checklist_list_shares WHERE list_id = ? AND user_id = ?', 'ii', [$listId, $userId]);
    respond(['success' => true]);
  }

  // ---- Items ----

  case 'listItems': {
    $user = requireMember();
    $listId = (int)($_GET['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListAccess($user, $listId);

    $rows = q('SELECT * FROM checklist_items WHERE list_id = ? ORDER BY sort_order, id', 'i', [$listId]);

    $clearedCount = 0;
    $items = [];
    foreach ($rows as $row) {
      if ($row['cleared_at'] !== null) {
        $clearedCount++;
        continue;
      }
      $items[(int)$row['id']] = shapeItem($row);
    }

    $attachments = q(
      'SELECT a.* FROM checklist_attachments a JOIN checklist_items i ON i.id = a.item_id WHERE i.list_id = ? ORDER BY a.id',
      'i', [$listId]
    );
    foreach ($attachments as $a) {
      if (isset($items[(int)$a['item_id']])) {
        $items[(int)$a['item_id']]['attachments'][] = shapeAttachment($a);
      }
    }

    respond(['items' => array_values($items), 'clearedCount' => $clearedCount]);
  }

  case 'addItem': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    $what = trim((string)($body['what'] ?? ''));
    if ($listId <= 0 || $what === '') {
      fail('listId and what are required');
    }
    requireListAccess($user, $listId);

    $where = nullableStr($body['where'] ?? null);
    $whenDate = nullableStr($body['whenDate'] ?? null);
    $whenTime = nullableStr($body['whenTime'] ?? null);
    $who = nullableStr($body['who'] ?? null);
    $contactName = nullableStr($body['contactName'] ?? null, 150);
    $phoneNumber = nullableStr($body['phoneNumber'] ?? null, 40);
    $notes = nullableStr($body['notes'] ?? null);

    // Optional parentId adds a sub-item directly under an existing item:
    // child (depth 1) or grandchild (depth 2), never deeper.
    $parentId = (int)($body['parentId'] ?? 0);
    $depth = 0;
    if ($parentId > 0) {
      $parent = requireItemAccess($user, $parentId);
      if ((int)$parent['list_id'] !== $listId) {
        fail('That parent item is in a different list');
      }
      $depth = (int)$parent['depth'] + 1;
      if ($depth > 2) {
        fail('Can\'t add a sub-item there — grandchild is the deepest level allowed');
      }
    }
    $parentParam = $parentId > 0 ? $parentId : null;
    $sortOrder = nextSortOrder($listId); // new items go last among their siblings

    $newId = exec1(
      'INSERT INTO checklist_items (list_id, user_id, parent_id, depth, sort_order, what, where_text, when_date, when_time, who, contact_name, phone_number, notes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      'iiiiissssssss',
      [$listId, (int)$user['id'], $parentParam, $depth, $sortOrder, $what, $where, $whenDate, $whenTime, $who, $contactName, $phoneNumber, $notes]
    );

    respond(['success' => true, 'id' => $newId]);
  }

  case 'updateItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    requireItemAccess($user, $id);

    // body key => [column, max length (0 = none)]
    $columnMap = [
      'what'        => ['what', 0],
      'where'       => ['where_text', 0],
      'whenDate'    => ['when_date', 0],
      'whenTime'    => ['when_time', 0],
      'who'         => ['who', 0],
      'contactName' => ['contact_name', 150],
      'phoneNumber' => ['phone_number', 40],
      'notes'       => ['notes', 0],
      'complete'    => ['complete', 0],
    ];

    $sets = [];
    $types = '';
    $values = [];
    foreach ($columnMap as $key => [$column, $maxLen]) {
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
        $values[] = nullableStr($body[$key], $maxLen);
      }
    }

    if (!$sets) {
      fail('No fields to update');
    }

    $types .= 'i';
    $values[] = $id;
    exec1('UPDATE checklist_items SET ' . implode(', ', $sets) . ' WHERE id = ?', $types, $values);

    respond(['success' => true]);
  }

  case 'deleteItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    $item = requireItemAccess($user, $id);

    $childrenMap = buildChildrenMap(fetchTreeRows((int)$item['list_id']));
    $files = storedNamesForItems(array_merge([$id], collectDescendants($childrenMap, $id)));

    // FK ON DELETE CASCADE removes any children/grandchildren (and their
    // attachment rows) with it; the files on disk are removed here.
    exec1('DELETE FROM checklist_items WHERE id = ?', 'i', [$id]);
    deleteStoredFiles($files);

    respond(['success' => true]);
  }

  case 'indentItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
      fail('Item id is required');
    }
    $item = requireItemAccess($user, $id);
    $listId = (int)$item['list_id'];
    $depth = (int)$item['depth'];
    $parentKey = $item['parent_id'] !== null ? (int)$item['parent_id'] : 0;

    $byId = fetchTreeRows($listId);
    $childrenMap = buildChildrenMap($byId);

    // Nest under the nearest *visible* sibling above -- a cleared one is
    // hidden, so nesting under it would make the item vanish.
    $candidateId = null;
    foreach ($childrenMap[$parentKey] ?? [] as $siblingId) {
      if ($siblingId === $id) {
        break;
      }
      if (!$byId[$siblingId]['cleared']) {
        $candidateId = $siblingId;
      }
    }
    if ($candidateId === null) {
      fail('No preceding item at this level to nest under');
    }

    $newDepth = $depth + 1;
    $relMax = subtreeMaxRelDepth($childrenMap, $id);

    if ($newDepth + $relMax > 2) {
      fail('Can\'t indent further — grandchild is the deepest level allowed');
    }

    // Lands as the last child of its new parent.
    exec1('UPDATE checklist_items SET parent_id = ?, depth = ?, sort_order = ? WHERE id = ?',
          'iiii', [$candidateId, $newDepth, nextSortOrder($listId), $id]);

    $descendants = collectDescendants($childrenMap, $id);
    if ($descendants) {
      exec1('UPDATE checklist_items SET depth = depth + 1 WHERE id IN (' . inPlaceholders($descendants) . ')',
            str_repeat('i', count($descendants)), $descendants);
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
    $item = requireItemAccess($user, $id);
    $listId = (int)$item['list_id'];

    if ($item['parent_id'] === null) {
      fail('Already at the top level');
    }

    $byId = fetchTreeRows($listId);
    $childrenMap = buildChildrenMap($byId);
    $parentId = (int)$item['parent_id'];
    $newParentId = $byId[$parentId]['parentId']; // may be null
    $newDepth = (int)$item['depth'] - 1;

    exec1('UPDATE checklist_items SET parent_id = ?, depth = ? WHERE id = ?', 'iii', [$newParentId, $newDepth, $id]);

    $descendants = collectDescendants($childrenMap, $id);
    if ($descendants) {
      exec1('UPDATE checklist_items SET depth = depth - 1 WHERE id IN (' . inPlaceholders($descendants) . ')',
            str_repeat('i', count($descendants)), $descendants);
    }

    // Place it right after its old parent among its new siblings.
    $siblings = [];
    foreach ($childrenMap[$newParentId ?? 0] ?? [] as $siblingId) {
      $siblings[] = $siblingId;
      if ($siblingId === $parentId) {
        $siblings[] = $id;
      }
    }
    renumber($siblings);

    respond(['success' => true]);
  }

  // Swaps an item with the visible sibling above/below it; its sub-items
  // come along since they hang off it.
  case 'moveItem': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    $direction = (string)($body['direction'] ?? '');
    if ($id <= 0 || ($direction !== 'up' && $direction !== 'down')) {
      fail('Item id and direction (up/down) are required');
    }
    $item = requireItemAccess($user, $id);

    $byId = fetchTreeRows((int)$item['list_id']);
    $siblings = buildChildrenMap($byId)[$item['parent_id'] !== null ? (int)$item['parent_id'] : 0] ?? [];
    $visible = array_values(array_filter($siblings, fn($sid) => !$byId[$sid]['cleared']));

    $vi = array_search($id, $visible, true);
    $ni = $direction === 'up' ? $vi - 1 : $vi + 1;
    if ($vi === false || $ni < 0 || $ni >= count($visible)) {
      fail($direction === 'up' ? 'Already at the top' : 'Already at the bottom');
    }
    $neighborId = $visible[$ni];

    $a = array_search($id, $siblings, true);
    $b = array_search($neighborId, $siblings, true);
    [$siblings[$a], $siblings[$b]] = [$siblings[$b], $siblings[$a]];
    renumber($siblings);

    respond(['success' => true]);
  }

  case 'clearChecked': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListAccess($user, $listId);

    $count = exec1(
      'UPDATE checklist_items SET cleared_at = NOW() WHERE list_id = ? AND complete = 1 AND cleared_at IS NULL',
      'i', [$listId]
    );

    respond(['success' => true, 'cleared' => $count]);
  }

  case 'deleteChecked': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListAccess($user, $listId);

    // Permanent, unlike clearChecked — also takes any cleared-but-checked
    // items with it, and cascades to children/grandchildren via the FK.
    $childrenMap = buildChildrenMap(fetchTreeRows($listId));
    $doomed = [];
    foreach (q('SELECT id FROM checklist_items WHERE list_id = ? AND complete = 1', 'i', [$listId]) as $r) {
      $doomed[] = (int)$r['id'];
      $doomed = array_merge($doomed, collectDescendants($childrenMap, (int)$r['id']));
    }
    $files = storedNamesForItems(array_values(array_unique($doomed)));

    $count = exec1('DELETE FROM checklist_items WHERE list_id = ? AND complete = 1', 'i', [$listId]);
    deleteStoredFiles($files);

    respond(['success' => true, 'deleted' => $count]);
  }

  case 'restoreCleared': {
    $user = requireMember();
    $body = jsonBody();
    $listId = (int)($body['listId'] ?? 0);
    if ($listId <= 0) {
      fail('listId is required');
    }
    requireListAccess($user, $listId);

    $count = exec1(
      'UPDATE checklist_items SET cleared_at = NULL WHERE list_id = ? AND cleared_at IS NOT NULL',
      'i', [$listId]
    );

    respond(['success' => true, 'restored' => $count]);
  }

  // ---- Attachments ----

  // multipart POST ?action=uploadAttachment with fields itemId + file
  case 'uploadAttachment': {
    $user = requireMember();
    $itemId = (int)($_POST['itemId'] ?? 0);
    if ($itemId <= 0) {
      fail('itemId is required');
    }
    requireItemAccess($user, $itemId);

    $f = $_FILES['file'] ?? null;
    if (!$f || !isset($f['error'])) {
      fail('No file was sent');
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
      fail('That file is larger than the server allows');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
      fail('The upload didn\'t finish — please try again');
    }
    if ((int)$f['size'] > MAX_UPLOAD_BYTES) {
      fail('Files can be up to 10 MB');
    }

    $name = mb_substr(basename((string)$f['name']), 0, 255);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(ALLOWED_TYPES[$ext])) {
      fail('That kind of file isn\'t supported. Use a photo, PDF, text or Office file.');
    }

    $stored = newStoredName();
    if (!move_uploaded_file($f['tmp_name'], uploadDir() . '/' . $stored)) {
      fail('Couldn\'t save the file', 500);
    }
    $type = ALLOWED_TYPES[$ext];
    $size = (int)$f['size'];
    $newId = exec1(
      'INSERT INTO checklist_attachments (item_id, user_id, file_name, file_type, file_size, stored_name) VALUES (?, ?, ?, ?, ?, ?)',
      'iissis', [$itemId, (int)$user['id'], $name, $type, $size, $stored]
    );

    respond(['success' => true, 'attachment' => [
      'id' => $newId, 'itemId' => $itemId, 'fileName' => $name, 'fileType' => $type, 'fileSize' => $size,
    ]]);
  }

  // GET ?action=attachment&id= -> the file itself (fetched with the bearer
  // token, so the page turns it into a blob URL)
  case 'attachment': {
    $user = requireMember();
    $id = (int)($_GET['id'] ?? 0);
    $rows = q('SELECT a.*, i.list_id FROM checklist_attachments a JOIN checklist_items i ON i.id = a.item_id WHERE a.id = ?', 'i', [$id]);
    if (!$rows) {
      fail('File not found', 404);
    }
    $a = $rows[0];
    requireListAccess($user, (int)$a['list_id']);

    $path = uploadDir() . '/' . basename($a['stored_name']);
    if (!is_file($path)) {
      fail('File not found', 404);
    }
    header('Content-Type: ' . $a['file_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $a['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
  }

  case 'deleteAttachment': {
    $user = requireMember();
    $body = jsonBody();
    $id = (int)($body['id'] ?? 0);
    $rows = q('SELECT a.*, i.list_id FROM checklist_attachments a JOIN checklist_items i ON i.id = a.item_id WHERE a.id = ?', 'i', [$id]);
    if (!$rows) {
      fail('File not found', 404);
    }
    requireListAccess($user, (int)$rows[0]['list_id']);

    exec1('DELETE FROM checklist_attachments WHERE id = ?', 'i', [$id]);
    deleteStoredFiles([$rows[0]['stored_name']]);

    respond(['success' => true]);
  }

  default:
    fail('Unknown action', 404);
}
