# Master Checklist — Setup Guide

A multi-list checklist app — items have What/Where/When/Who, can be checked
off, indented into children/grandchildren, and checked items can be cleared
(hidden) or restored. Backed by **MyDataWorld**, the same shared database
[T-Minus](https://github.com/dsshrek-ai/T-Minus) uses — one login works
across every app on it, and lists/items are private per account.

## 1. Add the tables to MyDataWorld

MyDataWorld (and its `users`/`sessions` tables) already exists from setting
up T-Minus — don't recreate it. Open **phpMyAdmin**, select the MyDataWorld
database, go to the **SQL** tab, and run everything in
[`api/schema.sql`](api/schema.sql). It only adds two new tables:
`checklist_lists` and `checklist_items`.

## 2. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the same
   `DB_NAME`, `DB_USER`, and `DB_PASS` you used for T-Minus's `config.php` —
   it's the same database.
2. Upload the whole `api/` folder via FTP/File Manager to a folder on
   seniorfamily.org — e.g. `seniorfamily.org/checklist-api/`.
3. You probably don't need to create a new account: any username/password
   that already works for T-Minus (or another MyDataWorld app) works here
   too. If you do want a brand-new account, visit
   `https://seniorfamily.org/checklist-api/hash-generator.php`, fill in a
   username/password/display name, and run the generated `INSERT` statement
   in phpMyAdmin's SQL tab. **Delete `hash-generator.php` from the server**
   afterward — it has no login of its own.

## 3. Point the app at your API

In `index.html`, update this line to match wherever you uploaded the `api/`
folder in step 2:

```js
const API_URL = 'https://seniorfamily.org/checklist-api/api.php';
```

## 4. Publish to GitHub Pages

1. Push this folder's contents to the `main` branch of
   `dsshrek-ai/master-checklist` (already set up as the repo's remote).
2. In the repo on GitHub, go to **Settings → Pages**.
3. Under "Source", select **Deploy from a branch → main → / (root)**.
4. Your app will be live at `https://dsshrek-ai.github.io/master-checklist/`.

## 5. Install as PWA (optional)

- **iPhone/iPad:** Open the URL in Safari → Share → Add to Home Screen
- **Android:** Open in Chrome → three-dot menu → Add to Home Screen
- **Desktop Chrome:** Click the install icon in the address bar

## Notes

- Lists and items are keyed by user id — everyone with a MyDataWorld login
  sees only their own lists.
- Indenting is capped at grandchild (top level → child → grandchild). The
  indent button nests an item under the item directly above it at the same
  level; outdent promotes it back up one level.
- Deleting an item deletes its children/grandchildren with it (cascades in
  the database), along with any photos/files on them.
- Photos/files are stored in `api/uploads/` on the server (created on first
  upload, locked by its own `.htaccess`) and only served through `api.php` to
  people who can see the list. Up to 10 MB each — but the host's PHP
  `upload_max_filesize` / `post_max_size` must also allow that.
- Sharing: the list's owner shares it by MyDataWorld username (the other
  person needs a Master Checklist grant in My Apps Hub). Everyone on a list
  can add, edit, check off, move and attach files; only the owner can rename,
  delete or re-share it. A member can leave a list from the Lists screen.
- "Add to calendar" uses the shared Apple/Google choice from My Apps Hub
  (`mdw-prefs.js`, a copy of `my-apps-hub/shared/mdw-prefs.js`).
- "Clear checked" hides checked items without deleting them; "Restore"
  brings back everything currently hidden for that list — use this pair for
  repeating lists you want to reuse. "Delete checked" is the permanent
  option: it removes checked items (and any sub-items under them) for good,
  for one-off lists you don't need history on.
