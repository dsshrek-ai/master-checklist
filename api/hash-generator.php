<?php
// One-time-use tool: generates a password hash + the INSERT statement to run in phpMyAdmin.
// DELETE THIS FILE from the server as soon as you're done using it.
//
// You probably don't need this: MyDataWorld's `users` table is shared across
// every app built on it, so an account already created for T-Minus (or any
// other MyDataWorld app) logs into Master Checklist too. Only use this to
// create a brand-new account.

$hash = null;
$insertSql = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $displayName = trim($_POST['display_name'] ?? '');

  if ($username !== '' && $password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $escapedUsername = addslashes($username);
    $escapedDisplayName = addslashes($displayName);
    $insertSql = "INSERT INTO users (username, password_hash, display_name) VALUES "
      . "('{$escapedUsername}', '{$hash}', '{$escapedDisplayName}');";
  }
}
?>
<!DOCTYPE html>
<html>
<head><title>Password Hash Generator</title></head>
<body style="font-family: sans-serif; max-width: 600px; margin: 40px auto;">
  <h1>One-time password hash generator</h1>
  <p><strong>Delete this file after use.</strong></p>
  <form method="post">
    <p><label>Username: <input type="text" name="username" required></label></p>
    <p><label>Display name: <input type="text" name="display_name"></label></p>
    <p><label>Password: <input type="text" name="password" required></label></p>
    <p><button type="submit">Generate</button></p>
  </form>
  <?php if ($insertSql): ?>
    <h2>Run this in phpMyAdmin's SQL tab:</h2>
    <textarea style="width:100%; height:100px;"><?= htmlspecialchars($insertSql) ?></textarea>
  <?php endif; ?>
</body>
</html>
