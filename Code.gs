// ─── CONFIG ───────────────────────────────────────────────────────────────────
const SHEET_ID   = 'YOUR_SPREADSHEET_ID';   // ← paste your Sheet ID here
const SHEET_NAME = 'Sheet1';                 // ← tab name in your Sheet

// ─── ROUTER ──────────────────────────────────────────────────────────────────
function doGet(e) {
  const action = e.parameter.action;
  const sheet  = SpreadsheetApp.openById(SHEET_ID).getSheetByName(SHEET_NAME);

  let result;
  try {
    switch (action) {
      case 'list':        result = getItems(sheet);                                         break;
      case 'add':         result = addItem(sheet, JSON.parse(e.parameter.data));            break;
      case 'update':      result = updateItem(sheet, e.parameter.id, JSON.parse(e.parameter.data)); break;
      case 'delete':      result = deleteItem(sheet, e.parameter.id);                       break;
      case 'renameList':  result = renameList(sheet, e.parameter.oldName, e.parameter.newName); break;
      case 'deleteList':  result = deleteList(sheet, e.parameter.listName);                 break;
      default:            result = { error: 'Unknown action: ' + action };
    }
  } catch (err) {
    result = { error: err.message };
  }

  return ContentService
    .createTextOutput(JSON.stringify(result))
    .setMimeType(ContentService.MimeType.JSON);
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function getSheet() {
  return SpreadsheetApp.openById(SHEET_ID).getSheetByName(SHEET_NAME);
}

function ensureHeaders(sheet) {
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(['ID', 'List Name', 'What', 'Where', 'When', 'Who', 'Complete']);
  }
}

function rowToItem(headers, row) {
  const item = {};
  headers.forEach((h, i) => { item[h] = row[i]; });
  return item;
}

function findRowById(sheet, id) {
  const data = sheet.getDataRange().getValues();
  for (let i = 1; i < data.length; i++) {
    if (String(data[i][0]) === String(id)) return i + 1; // 1-based sheet row
  }
  return -1;
}

// ─── CRUD ────────────────────────────────────────────────────────────────────
function getItems(sheet) {
  ensureHeaders(sheet);
  const data = sheet.getDataRange().getValues();
  if (data.length <= 1) return { items: [] };
  const headers = data[0];
  const items = [];
  for (let i = 1; i < data.length; i++) {
    if (!data[i][0]) continue;
    items.push(rowToItem(headers, data[i]));
  }
  return { items };
}

function addItem(sheet, data) {
  ensureHeaders(sheet);
  const id = String(Date.now());
  sheet.appendRow([
    id,
    data.listName  || '',
    data.what      || '',
    data.where     || '',
    data.when      || '',
    data.who       || '',
    false
  ]);
  return { success: true, id };
}

function updateItem(sheet, id, data) {
  const row = findRowById(sheet, id);
  if (row === -1) return { error: 'Item not found' };

  const COL = { listName: 2, what: 3, where: 4, when: 5, who: 6, complete: 7 };
  Object.entries(data).forEach(([key, val]) => {
    if (COL[key]) sheet.getRange(row, COL[key]).setValue(val);
  });
  return { success: true };
}

function deleteItem(sheet, id) {
  const row = findRowById(sheet, id);
  if (row === -1) return { error: 'Item not found' };
  sheet.deleteRow(row);
  return { success: true };
}

function renameList(sheet, oldName, newName) {
  const data = sheet.getDataRange().getValues();
  let count = 0;
  for (let i = 1; i < data.length; i++) {
    if (String(data[i][1]) === oldName) {
      sheet.getRange(i + 1, 2).setValue(newName);
      count++;
    }
  }
  return { success: true, updated: count };
}

function deleteList(sheet, listName) {
  const data = sheet.getDataRange().getValues();
  const rowsToDelete = [];
  for (let i = 1; i < data.length; i++) {
    if (String(data[i][1]) === listName) rowsToDelete.push(i + 1);
  }
  for (let i = rowsToDelete.length - 1; i >= 0; i--) {
    sheet.deleteRow(rowsToDelete[i]);
  }
  return { success: true, deleted: rowsToDelete.length };
}
