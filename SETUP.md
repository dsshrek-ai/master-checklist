# Master Checklist — Setup Guide

## Step 1: Create the Google Sheet

1. Go to [sheets.google.com](https://sheets.google.com) and create a new spreadsheet
2. Name it **Master Checklist**
3. In row 1, add these exact headers (one per column, A through G):

   | A  | B         | C    | D     | E    | F   | G        |
   |----|-----------|------|-------|------|-----|----------|
   | ID | List Name | What | Where | When | Who | Complete |

4. Copy the **Spreadsheet ID** from the URL:
   `https://docs.google.com/spreadsheets/d/`**`THIS_PART`**`/edit`

---

## Step 2: Deploy the Apps Script

1. In your Sheet, go to **Extensions → Apps Script**
2. Delete the default `myFunction` code
3. Paste in the contents of `Code.gs` from this folder
4. Replace `YOUR_SPREADSHEET_ID` on line 2 with the ID you copied above
5. Click **Save** (floppy disk icon)
6. Click **Deploy → New deployment**
7. Click the gear icon next to "Type" and choose **Web app**
8. Set:
   - **Execute as:** Me
   - **Who has access:** Anyone
9. Click **Deploy**
10. Click **Authorize access** and follow the Google prompts
11. Copy the **Web app URL** — it looks like:
    `https://script.google.com/macros/s/ABC123.../exec`

---

## Step 3: Configure the App

Open `index.html` in a text editor and find this line near the bottom of the `<script>` block:

```js
const SCRIPT_URL = 'YOUR_APPS_SCRIPT_URL';
```

Replace `YOUR_APPS_SCRIPT_URL` with the URL you copied in Step 2. Save the file.

---

## Step 4: Publish to GitHub Pages

1. Create a new **public** repository on GitHub (e.g. `master-checklist`)
2. Push this folder's contents to the `main` branch:
   ```
   git init
   git add .
   git commit -m "Initial commit"
   git remote add origin https://github.com/YOUR_USERNAME/master-checklist.git
   git push -u origin main
   ```
3. In the repo on GitHub, go to **Settings → Pages**
4. Under "Source", select **Deploy from a branch → main → / (root)**
5. Click **Save** — your app will be live at:
   `https://YOUR_USERNAME.github.io/master-checklist/`

---

## Step 5: Install as PWA (optional)

- **iPhone/iPad:** Open the URL in Safari → Share → Add to Home Screen
- **Android:** Open in Chrome → three-dot menu → Add to Home Screen
- **Desktop Chrome:** Click the install icon in the address bar

---

## Notes

- The Google Sheet is the source of truth — you can view/edit data there anytime
- The Apps Script runs as your Google account, so the Sheet stays private even though the app URL is public
- If you redeploy the Apps Script after changes, the URL stays the same — no need to update the app
