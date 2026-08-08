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
  the database).
- "Clear checked" hides checked items without deleting them; "Restore"
  brings back everything currently hidden for that list. Nothing is
  permanently lost until you explicitly delete an item.
