/**
 * Live attachment editor behaviour (text/code & spreadsheet grid editor).
 */
(function () {
    'use strict';

    var FORM_ID = 'fic-edit-form';
    var activeCell = null;
    var activeSheetName = 'Sheet1';
    var workbookData = null;

    function byId(id) {
        return document.getElementById(id);
    }

    function getColLabel(index) {
        var label = '';
        var i = index;
        do {
            label = String.fromCharCode(65 + (i % 26)) + label;
            i = Math.floor(i / 26) - 1;
        } while (i >= 0);
        return label;
    }

    function getCellRef(row, col) {
        return getColLabel(col) + (row + 1);
    }

    /**
     * Recompute counters, the line gutter and the JSON syntax indicator for text mode.
     */
    function refreshTextEditor() {
        var textarea = byId('fic-edit-content');
        if (!textarea) {
            return;
        }

        var value = textarea.value;
        var lines = value === '' ? 0 : value.split('\n').length;

        var lineCount = byId('fic-line-count');
        var charCount = byId('fic-char-count');

        if (lineCount) {
            lineCount.textContent = String(lines);
        }
        if (charCount) {
            charCount.textContent = String(value.length);
        }

        var gutter = byId('fic-line-gutter');
        if (gutter) {
            var gutterText = '';
            for (var i = 1; i <= Math.max(lines, 1); i++) {
                gutterText += i + '\n';
            }
            gutter.textContent = gutterText;
        }

        var status = byId('fic-syntax-status');
        if (!status || status.getAttribute('data-format') !== 'json') {
            return;
        }

        var valid = true;
        if (value.trim() !== '') {
            try {
                JSON.parse(value);
            } catch (error) {
                valid = false;
            }
        }

        status.style.color = valid ? '#28a745' : '#d9534f';
        status.textContent = valid
            ? '✓ ' + (status.getAttribute('data-label-valid') || 'Valid JSON')
            : '✗ ' + (status.getAttribute('data-label-invalid') || 'Invalid JSON Syntax');
    }

    function showError(message) {
        var alertBox = byId('fic-edit-alert');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.style.display = 'block';
        }
    }

    // ==========================================
    // SPREADSHEET GRID EDITOR LOGIC
    // ==========================================

    function initSpreadsheetEditor() {
        var gridDataInput = byId('fic-grid-data');
        if (!gridDataInput) {
            return;
        }

        try {
            workbookData = JSON.parse(gridDataInput.value || '{}');
        } catch (e) {
            workbookData = {};
        }

        var activeTab = document.querySelector('.fic-edit-sheet-tab.is-active');
        if (activeTab) {
            activeSheetName = activeTab.getAttribute('data-sheet-name') || 'Sheet1';
        } else {
            var keys = Object.keys(workbookData);
            activeSheetName = keys.length > 0 ? keys[0] : 'Sheet1';
        }

        // Select first cell by default
        var firstCell = document.querySelector('.fic-grid-cell[data-row="0"][data-col="0"]');
        if (firstCell) {
            selectCell(firstCell);
        }
    }

    function highlightHeaders(rowIdx, colIdx) {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        // Reset previous highlighted headers
        var prevThs = table.querySelectorAll('thead th.fic-th-active');
        for (var i = 0; i < prevThs.length; i++) {
            prevThs[i].classList.remove('fic-th-active');
            prevThs[i].style.backgroundColor = '#f1f3f5';
            prevThs[i].style.color = '#495057';
        }

        var prevRowTds = table.querySelectorAll('tbody td.fic-row-active');
        for (var j = 0; j < prevRowTds.length; j++) {
            prevRowTds[j].classList.remove('fic-row-active');
            prevRowTds[j].style.backgroundColor = '#f8f9fa';
            prevRowTds[j].style.color = '#6c757d';
        }

        // Highlight active column header
        var activeTh = table.querySelector('thead th[data-col-index="' + colIdx + '"]');
        if (activeTh) {
            activeTh.classList.add('fic-th-active');
            activeTh.style.backgroundColor = '#d4edda';
            activeTh.style.color = '#155724';
        }

        // Highlight active row header
        var activeRowTd = table.querySelector('tbody td[data-row-index="' + rowIdx + '"]');
        if (activeRowTd) {
            activeRowTd.classList.add('fic-row-active');
            activeRowTd.style.backgroundColor = '#d4edda';
            activeRowTd.style.color = '#155724';
        }
    }

    function selectCell(cell) {
        if (!cell || !cell.classList.contains('fic-grid-cell')) {
            return;
        }

        if (activeCell && activeCell !== cell) {
            activeCell.style.outline = 'none';
            activeCell.style.backgroundColor = '';
            activeCell.classList.remove('is-selected');
        }

        activeCell = cell;
        cell.classList.add('is-selected');
        cell.style.outline = '2px solid #107c41';
        cell.style.backgroundColor = '#e8f0fe';

        var r = parseInt(cell.getAttribute('data-row') || '0', 10);
        var c = parseInt(cell.getAttribute('data-col') || '0', 10);

        highlightHeaders(r, c);

        var refBadge = document.querySelector('.fic-active-cell-ref');
        if (refBadge) {
            refBadge.textContent = getCellRef(r, c);
        }

        var formulaInput = byId('fic-formula-input');
        if (formulaInput && document.activeElement !== formulaInput) {
            formulaInput.value = cell.textContent || '';
        }
    }

    function syncGridToData() {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) {
            return;
        }

        var tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        var rows = [];
        var trs = tbody.querySelectorAll('tr');
        for (var i = 0; i < trs.length; i++) {
            var rowData = [];
            var tds = trs[i].querySelectorAll('.fic-grid-cell');
            for (var j = 0; j < tds.length; j++) {
                rowData.push(tds[j].textContent || '');
            }
            rows.push(rowData);
        }

        if (!workbookData) {
            workbookData = {};
        }

        workbookData[activeSheetName] = {
            rows: rows,
            rowCount: rows.length,
            columnCount: rows[0] ? rows[0].length : 0,
            truncated: false
        };

        var gridDataInput = byId('fic-grid-data');
        if (gridDataInput) {
            gridDataInput.value = JSON.stringify(workbookData);
        }

        // Build CSV text for fallback
        var csvLines = [];
        for (var r = 0; r < rows.length; r++) {
            var lineCells = [];
            for (var c = 0; c < rows[r].length; c++) {
                var val = String(rows[r][c]);
                if (val.indexOf(',') !== -1 || val.indexOf('"') !== -1 || val.indexOf('\n') !== -1) {
                    val = '"' + val.replace(/"/g, '""') + '"';
                }
                lineCells.push(val);
            }
            csvLines.push(lineCells.join(','));
        }

        var contentTextarea = byId('fic-edit-content');
        if (contentTextarea) {
            contentTextarea.value = csvLines.join('\n');
        }
    }

    function addRow() {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        var tbody = table.querySelector('tbody');
        var trs = tbody ? tbody.querySelectorAll('tr') : [];
        var colCount = table.querySelectorAll('thead th[data-col-index]').length || 3;
        var newRowIndex = trs.length;

        var tr = document.createElement('tr');

        var numTd = document.createElement('td');
        numTd.style.cssText = 'padding: 4px 6px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f8f9fa; font-weight: bold; user-select: none;';
        numTd.setAttribute('data-row-index', String(newRowIndex));
        numTd.textContent = String(newRowIndex + 1);
        tr.appendChild(numTd);

        for (var c = 0; c < colCount; c++) {
            var td = document.createElement('td');
            td.setAttribute('contenteditable', 'true');
            td.className = 'fic-grid-cell';
            td.setAttribute('data-row', String(newRowIndex));
            td.setAttribute('data-col', String(c));
            td.style.cssText = 'padding: 4px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; min-width: 90px; outline: none;';
            tr.appendChild(td);
        }

        if (tbody) {
            tbody.appendChild(tr);
        }
        syncGridToData();

        var newFirstCell = tr.querySelector('.fic-grid-cell[data-col="0"]');
        if (newFirstCell) {
            selectCell(newFirstCell);
            newFirstCell.focus();
        }
    }

    function addColumn() {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        var theadTr = table.querySelector('thead tr');
        var headerCols = table.querySelectorAll('thead th[data-col-index]');
        var newColIndex = headerCols.length;

        var th = document.createElement('th');
        th.style.cssText = 'padding: 4px 8px; border: 1px solid #dee2e6; font-weight: 600; color: #495057; font-family: monospace; text-align: center; background: #f1f3f5; min-width: 90px;';
        th.setAttribute('data-col-index', String(newColIndex));
        th.textContent = getColLabel(newColIndex);
        if (theadTr) {
            theadTr.appendChild(th);
        }

        var tbodyTrs = table.querySelectorAll('tbody tr');
        for (var r = 0; r < tbodyTrs.length; r++) {
            var td = document.createElement('td');
            td.setAttribute('contenteditable', 'true');
            td.className = 'fic-grid-cell';
            td.setAttribute('data-row', String(r));
            td.setAttribute('data-col', String(newColIndex));
            td.style.cssText = 'padding: 4px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; min-width: 90px; outline: none;';
            tbodyTrs[r].appendChild(td);
        }

        syncGridToData();
    }

    function deleteRow() {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        var tbody = table.querySelector('tbody');
        var trs = tbody ? tbody.querySelectorAll('tr') : [];
        if (trs.length <= 1) return;

        var rowToDelete = trs.length - 1;
        if (activeCell) {
            rowToDelete = parseInt(activeCell.getAttribute('data-row') || String(rowToDelete), 10);
        }

        if (trs[rowToDelete]) {
            trs[rowToDelete].parentNode.removeChild(trs[rowToDelete]);
        }

        // Re-index remaining rows
        var newTrs = tbody ? tbody.querySelectorAll('tr') : [];
        for (var r = 0; r < newTrs.length; r++) {
            var numTd = newTrs[r].querySelector('td:first-child');
            if (numTd) {
                numTd.textContent = String(r + 1);
                numTd.setAttribute('data-row-index', String(r));
            }
            var tds = newTrs[r].querySelectorAll('.fic-grid-cell');
            for (var c = 0; c < tds.length; c++) {
                tds[c].setAttribute('data-row', String(r));
            }
        }

        var targetCell = tbody ? tbody.querySelector('.fic-grid-cell[data-row="' + Math.min(rowToDelete, newTrs.length - 1) + '"][data-col="0"]') : null;
        if (targetCell) {
            selectCell(targetCell);
        }

        syncGridToData();
    }

    function deleteColumn() {
        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        var headerCols = table.querySelectorAll('thead th[data-col-index]');
        if (headerCols.length <= 1) return;

        var colToDelete = headerCols.length - 1;
        if (activeCell) {
            colToDelete = parseInt(activeCell.getAttribute('data-col') || String(colToDelete), 10);
        }

        var theadTr = table.querySelector('thead tr');
        var ths = theadTr ? theadTr.querySelectorAll('th[data-col-index]') : [];
        if (ths[colToDelete]) {
            ths[colToDelete].parentNode.removeChild(ths[colToDelete]);
        }

        // Re-index th headers
        var newThs = theadTr ? theadTr.querySelectorAll('th[data-col-index]') : [];
        for (var c = 0; c < newThs.length; c++) {
            newThs[c].setAttribute('data-col-index', String(c));
            newThs[c].textContent = getColLabel(c);
        }

        var tbodyTrs = table.querySelectorAll('tbody tr');
        for (var r = 0; r < tbodyTrs.length; r++) {
            var tds = tbodyTrs[r].querySelectorAll('.fic-grid-cell');
            if (tds[colToDelete]) {
                tds[colToDelete].parentNode.removeChild(tds[colToDelete]);
            }
            var newTds = tbodyTrs[r].querySelectorAll('.fic-grid-cell');
            for (var colIdx = 0; colIdx < newTds.length; colIdx++) {
                newTds[colIdx].setAttribute('data-col', String(colIdx));
            }
        }

        syncGridToData();
    }

    function clearActiveCell() {
        if (!activeCell) return;
        activeCell.textContent = '';
        var formulaInput = byId('fic-formula-input');
        if (formulaInput) {
            formulaInput.value = '';
        }
        syncGridToData();
    }

    function addSheet() {
        if (!workbookData) workbookData = {};
        var count = Object.keys(workbookData).length + 1;
        var defaultSheetName = 'Sheet' + count;
        while (workbookData[defaultSheetName]) {
            count++;
            defaultSheetName = 'Sheet' + count;
        }

        var inputName = window.prompt('Enter new sheet name:', defaultSheetName);
        if (inputName === null) return;
        var newSheetName = inputName.trim();
        if (!newSheetName) {
            newSheetName = defaultSheetName;
        }

        if (workbookData[newSheetName]) {
            window.alert('A sheet with this name already exists.');
            return;
        }

        workbookData[newSheetName] = {
            rows: [
                ['', '', '', '', ''],
                ['', '', '', '', ''],
                ['', '', '', '', ''],
                ['', '', '', '', ''],
                ['', '', '', '', '']
            ],
            rowCount: 5,
            columnCount: 5,
            truncated: false
        };

        renderSheetTabsBar();
        switchEditSheet(newSheetName);
    }

    function renameSheet(oldName) {
        if (!workbookData || !workbookData[oldName]) return;
        var inputName = window.prompt('Rename sheet to:', oldName);
        if (inputName === null) return;
        var newName = inputName.trim();
        if (!newName || newName === oldName) return;

        if (workbookData[newName]) {
            window.alert('A sheet with that name already exists.');
            return;
        }

        var updated = {};
        var keys = Object.keys(workbookData);
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            if (k === oldName) {
                updated[newName] = workbookData[oldName];
            } else {
                updated[k] = workbookData[k];
            }
        }
        workbookData = updated;

        if (activeSheetName === oldName) {
            activeSheetName = newName;
        }

        renderSheetTabsBar();
        syncGridToData();
    }

    function deleteSheet(sheetName) {
        if (!workbookData || !workbookData[sheetName]) return;
        var keys = Object.keys(workbookData);
        if (keys.length <= 1) {
            window.alert('Cannot delete the only sheet in the workbook.');
            return;
        }

        if (!window.confirm('Are you sure you want to delete sheet "' + sheetName + '"?')) {
            return;
        }

        delete workbookData[sheetName];
        var remainingKeys = Object.keys(workbookData);
        var nextSheet = remainingKeys[0];

        renderSheetTabsBar();
        switchEditSheet(nextSheet);
    }

    function renderSheetTabsBar() {
        var tabsContainer = document.querySelector('.fic-edit-sheet-tabs');
        if (!tabsContainer) {
            var wrapper = document.querySelector('.fic-spreadsheet-editor');
            if (wrapper) {
                tabsContainer = document.createElement('div');
                tabsContainer.className = 'fic-edit-sheet-tabs';
                tabsContainer.setAttribute('role', 'tablist');
                wrapper.insertBefore(tabsContainer, wrapper.querySelector('.fic-spreadsheet-grid-wrapper'));
            }
        }
        if (!tabsContainer || !workbookData) return;

        while (tabsContainer.firstChild) {
            tabsContainer.removeChild(tabsContainer.firstChild);
        }

        var keys = Object.keys(workbookData);
        for (var i = 0; i < keys.length; i++) {
            var sName = keys[i];
            var isCur = sName === activeSheetName;

            var tabWrap = document.createElement('div');
            tabWrap.className = 'fic-edit-sheet-tab-wrapper';
            tabWrap.style.cssText = 'display: inline-flex; align-items: center; background: ' + (isCur ? '#107c41' : '#f1f3f5') + '; border: 1px solid #d0d7de; border-bottom: none; border-radius: 4px 4px 0 0; padding: 2px 6px; margin-right: 4px; margin-bottom: 4px;';

            var tabBtn = document.createElement('button');
            tabBtn.type = 'button';
            tabBtn.className = 'fic-edit-sheet-tab' + (isCur ? ' is-active' : '');
            tabBtn.setAttribute('data-sheet-name', sName);
            tabBtn.style.cssText = 'background: none; border: none; cursor: pointer; font-size: 12px; color: ' + (isCur ? '#fff' : '#24292f') + '; font-weight: ' + (isCur ? '600' : 'normal') + '; padding: 3px 6px;';

            var icon = document.createElement('i');
            icon.className = 'fa fa-table';
            tabBtn.appendChild(icon);
            tabBtn.appendChild(document.createTextNode(' ' + sName));

            var renBtn = document.createElement('button');
            renBtn.type = 'button';
            renBtn.className = 'fic-btn-rename-sheet';
            renBtn.setAttribute('data-sheet-name', sName);
            renBtn.setAttribute('title', 'Rename Sheet');
            renBtn.style.cssText = 'background: none; border: none; cursor: pointer; padding: 1px 3px; color: ' + (isCur ? '#e8f5e9' : '#6c757d') + '; font-size: 10px;';
            var renIcon = document.createElement('i');
            renIcon.className = 'fa fa-pencil';
            renBtn.appendChild(renIcon);

            tabWrap.appendChild(tabBtn);
            tabWrap.appendChild(renBtn);

            if (keys.length > 1) {
                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'fic-btn-delete-sheet';
                delBtn.setAttribute('data-sheet-name', sName);
                delBtn.setAttribute('title', 'Delete Sheet');
                delBtn.style.cssText = 'background: none; border: none; cursor: pointer; padding: 1px 3px; color: ' + (isCur ? '#ffcdd2' : '#dc3545') + '; font-size: 12px; font-weight: bold;';
                delBtn.textContent = '×';
                tabWrap.appendChild(delBtn);
            }

            tabsContainer.appendChild(tabWrap);
        }
    }

    function switchEditSheet(sheetName) {
        if (!workbookData || !workbookData[sheetName]) return;
        syncGridToData();

        activeSheetName = sheetName;
        var sheetObj = workbookData[sheetName] || {rows: []};
        var rows = (sheetObj && Array.isArray(sheetObj.rows)) ? sheetObj.rows : (Array.isArray(sheetObj) ? sheetObj : []);

        renderSheetTabsBar();

        var table = document.querySelector('.fic-spreadsheet-table');
        if (!table) return;

        var colCount = 0;
        for (var r = 0; r < rows.length; r++) {
            colCount = Math.max(colCount, rows[r] ? rows[r].length : 0);
        }
        colCount = Math.max(colCount, 3);

        // Rebuild thead
        var theadTr = table.querySelector('thead tr');
        if (theadTr) {
            while (theadTr.firstChild) {
                theadTr.removeChild(theadTr.firstChild);
            }
            var numTh = document.createElement('th');
            numTh.style.cssText = 'padding: 4px 6px; border: 1px solid #dee2e6; width: 40px; background: #e9ecef; color: #495057; text-align: center; font-size: 11px;';
            numTh.textContent = '#';
            theadTr.appendChild(numTh);

            for (var c = 0; c < colCount; c++) {
                var th = document.createElement('th');
                th.style.cssText = 'padding: 4px 8px; border: 1px solid #dee2e6; font-weight: 600; color: #495057; font-family: monospace; text-align: center; background: #f1f3f5; min-width: 90px;';
                th.setAttribute('data-col-index', String(c));
                th.textContent = getColLabel(c);
                theadTr.appendChild(th);
            }
        }

        // Rebuild tbody
        var tbody = table.querySelector('tbody');
        if (tbody) {
            while (tbody.firstChild) {
                tbody.removeChild(tbody.firstChild);
            }
            var rowCount = Math.max(rows.length, 5);
            for (var rIdx = 0; rIdx < rowCount; rIdx++) {
                var rowCells = rows[rIdx] || [];
                var tr = document.createElement('tr');

                var numTd = document.createElement('td');
                numTd.style.cssText = 'padding: 4px 6px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f8f9fa; font-weight: bold; user-select: none;';
                numTd.setAttribute('data-row-index', String(rIdx));
                numTd.textContent = String(rIdx + 1);
                tr.appendChild(numTd);

                for (var colIdx = 0; colIdx < colCount; colIdx++) {
                    var td = document.createElement('td');
                    td.setAttribute('contenteditable', 'true');
                    td.className = 'fic-grid-cell';
                    td.setAttribute('data-row', String(rIdx));
                    td.setAttribute('data-col', String(colIdx));
                    td.style.cssText = 'padding: 4px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; min-width: 90px; outline: none;';
                    td.textContent = (rowCells[colIdx] !== undefined && rowCells[colIdx] !== null) ? String(rowCells[colIdx]) : '';
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
        }

        var firstCell = document.querySelector('.fic-grid-cell[data-row="0"][data-col="0"]');
        if (firstCell) {
            selectCell(firstCell);
        }
    }

    // ==========================================
    // EVENT LISTENERS & DELEGATION
    // ==========================================

    function onSubmit(event) {
        var form = event.target;
        if (!form || form.id !== FORM_ID) {
            return;
        }

        event.preventDefault();

        // If spreadsheet editor, ensure data is synced
        var isSpreadsheet = form.getAttribute('data-is-spreadsheet') === '1';
        if (isSpreadsheet) {
            syncGridToData();
        }

        var alertBox = byId('fic-edit-alert');
        var saveButton = byId('fic-edit-save');

        if (alertBox) {
            alertBox.style.display = 'none';
        }
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
        }

        var failureMessage = form.getAttribute('data-label-error') || 'Unable to save the attachment.';

        function fail(message) {
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = 'Save Changes';
            }
            showError(message || failureMessage);
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.json().catch(function () {
                return {success: response.ok};
            }).then(function (payload) {
                if (response.ok && payload && payload.success) {
                    if (window.KB && window.KB.modal && typeof window.KB.modal.close === 'function' && document.getElementById('modal-box')) {
                        window.KB.modal.close();
                    }
                    window.location.reload();
                    return;
                }

                fail(payload && payload.message ? payload.message : failureMessage);
            });
        }).catch(function () {
            fail(failureMessage);
        });
    }

    function onInput(event) {
        var target = event.target;
        if (!target) return;

        if (target.id === 'fic-edit-content') {
            refreshTextEditor();
        } else if (target.id === 'fic-formula-input') {
            if (activeCell) {
                activeCell.textContent = target.value;
                syncGridToData();
            }
        } else if (target.classList.contains('fic-grid-cell')) {
            var formulaInput = byId('fic-formula-input');
            if (formulaInput) {
                formulaInput.value = target.textContent || '';
            }
            syncGridToData();
        }
    }

    function onClick(event) {
        var target = event.target;
        if (!target) return;

        // Cancel button click
        if (target.classList.contains('fic-edit-cancel') || (target.closest && target.closest('.fic-edit-cancel'))) {
            event.preventDefault();
            if (window.KB && window.KB.modal && typeof window.KB.modal.close === 'function') {
                window.KB.modal.close();
            } else if (window.history && window.history.length > 1) {
                window.history.back();
            }
            return;
        }

        // Cell click
        var cell = target.closest ? target.closest('.fic-grid-cell') : null;
        if (cell) {
            selectCell(cell);
            return;
        }

        // Add Row
        if (target.classList.contains('fic-btn-add-row') || (target.closest && target.closest('.fic-btn-add-row'))) {
            event.preventDefault();
            addRow();
            return;
        }

        // Add Col
        if (target.classList.contains('fic-btn-add-col') || (target.closest && target.closest('.fic-btn-add-col'))) {
            event.preventDefault();
            addColumn();
            return;
        }

        // Delete Row
        if (target.classList.contains('fic-btn-del-row') || (target.closest && target.closest('.fic-btn-del-row'))) {
            event.preventDefault();
            deleteRow();
            return;
        }

        // Delete Col
        if (target.classList.contains('fic-btn-del-col') || (target.closest && target.closest('.fic-btn-del-col'))) {
            event.preventDefault();
            deleteColumn();
            return;
        }

        // Clear Cell
        if (target.classList.contains('fic-btn-clear-cell') || (target.closest && target.closest('.fic-btn-clear-cell'))) {
            event.preventDefault();
            clearActiveCell();
            return;
        }

        // Add Sheet
        if (target.classList.contains('fic-btn-add-sheet') || (target.closest && target.closest('.fic-btn-add-sheet'))) {
            event.preventDefault();
            addSheet();
            return;
        }

        // Rename Sheet
        var renameBtn = target.closest ? target.closest('.fic-btn-rename-sheet') : null;
        if (renameBtn) {
            event.preventDefault();
            var rName = renameBtn.getAttribute('data-sheet-name');
            if (rName) {
                renameSheet(rName);
            }
            return;
        }

        // Delete Sheet
        var deleteBtn = target.closest ? target.closest('.fic-btn-delete-sheet') : null;
        if (deleteBtn) {
            event.preventDefault();
            var dName = deleteBtn.getAttribute('data-sheet-name');
            if (dName) {
                deleteSheet(dName);
            }
            return;
        }

        // Sheet tab click
        var sheetTab = target.closest ? target.closest('.fic-edit-sheet-tab') : null;
        if (sheetTab) {
            event.preventDefault();
            var sName = sheetTab.getAttribute('data-sheet-name');
            if (sName) {
                switchEditSheet(sName);
            }
            return;
        }
    }

    function onKeyDown(event) {
        var target = event.target;
        if (!target || !target.classList.contains('fic-grid-cell')) {
            return;
        }

        var r = parseInt(target.getAttribute('data-row') || '0', 10);
        var c = parseInt(target.getAttribute('data-col') || '0', 10);

        if (event.key === 'Tab') {
            event.preventDefault();
            var nextC = event.shiftKey ? c - 1 : c + 1;
            if (nextC >= 0) {
                var nextCell = document.querySelector('.fic-grid-cell[data-row="' + r + '"][data-col="' + nextC + '"]');
                if (!nextCell && !event.shiftKey) {
                    addColumn();
                    nextCell = document.querySelector('.fic-grid-cell[data-row="' + r + '"][data-col="' + nextC + '"]');
                }
                if (nextCell) {
                    selectCell(nextCell);
                    nextCell.focus();
                }
            }
        } else if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            var nextR = r + 1;
            var nextRowCell = document.querySelector('.fic-grid-cell[data-row="' + nextR + '"][data-col="' + c + '"]');
            if (!nextRowCell) {
                addRow();
                nextRowCell = document.querySelector('.fic-grid-cell[data-row="' + nextR + '"][data-col="' + c + '"]');
            }
            if (nextRowCell) {
                selectCell(nextRowCell);
                nextRowCell.focus();
            }
        } else if (event.key === 'Enter' && event.shiftKey) {
            event.preventDefault();
            var prevR = r - 1;
            if (prevR >= 0) {
                var prevRowCell = document.querySelector('.fic-grid-cell[data-row="' + prevR + '"][data-col="' + c + '"]');
                if (prevRowCell) {
                    selectCell(prevRowCell);
                    prevRowCell.focus();
                }
            }
        }
    }

    function onScroll(event) {
        if (!event.target || event.target.id !== 'fic-edit-content') {
            return;
        }
        var gutter = byId('fic-line-gutter');
        if (gutter) {
            gutter.scrollTop = event.target.scrollTop;
        }
    }

    function init() {
        refreshTextEditor();
        initSpreadsheetEditor();
    }

    document.addEventListener('input', onInput, false);
    document.addEventListener('click', onClick, false);
    document.addEventListener('keydown', onKeyDown, false);
    document.addEventListener('submit', onSubmit, false);
    document.addEventListener('scroll', onScroll, true);

    if (window.KB && typeof window.KB.on === 'function') {
        window.KB.on('modal.afterRender', init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());