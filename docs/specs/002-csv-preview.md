# Functional Specification: 002 - Safe CSV Read-Only Table Preview

**Feature Target**: Milestone 2 — CSV Read-Only Table Preview Engine  
**Document Status**: Proposal / Specification  
**Author**: Youssef BOUTALEB  
**Date**: 2026-08-07  

---

## 1. User Story & Summary

**User Story**:  
As a Kanboard project manager, team member, or auditor, I want to safely preview CSV file attachments (`.csv`, `.tsv`) attached to tasks or projects directly as formatted, read-only HTML data tables in a modal, so that I can inspect structured tabular data quickly without downloading raw files or risking client-side script execution.

---

## 2. Scope

- **Supported Extensions**: `.csv`, `.tsv`.
- **Automatic Delimiter Detection**: Dynamically detects common field delimiters including comma (`,`), semicolon (`;`), tab (`\t`), and pipe (`|`).
- **Safe HTML Table Rendering**: Formats CSV data into a styled, responsive HTML table (`<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`).
- **Cell Content Neutralization**: HTML entity escaping (`htmlspecialchars()`) applied to every individual cell to prevent XSS.
- **Row & Column Pagination / Bounds**: Preview limited to first 100 rows and 50 columns with explicit truncation notice for large datasets.
- **Streaming Parser**: Memory-bounded line-by-line parsing using native PHP `fgetcsv()` or `str_getcsv()` streaming to guarantee low memory usage.

---

## 3. Non-Goals

- **NO In-Place Editing**: Users cannot edit cell values or headers.
- **NO Saving / Exporting**: Modifying CSV data back to object storage is strictly out of scope for Milestone 2.
- **NO Task Import Integration**: CSV data will not be converted into Kanboard tasks or subtasks.
- **NO Formula / Code Execution**: Excel-style formulas starting with `=`, `+`, `-`, or `@` will NOT be evaluated or executed.
- **NO External Heavy Dependencies**: No unverified 3rd-party rendering engines or frontend JavaScript libraries.

---

## 4. Acceptance Criteria

1. **AC-1 (Format Resolution)**:
   - Uploading `.csv` or `.tsv` files routes preview requests to `CsvPreviewHandler`.
2. **AC-2 (Delimiter Detection)**:
   - Standard comma-separated files render with distinct column headers.
   - Semicolon-separated files (common in European Excel exports) automatically detect `;` as delimiter without layout collapse.
3. **AC-3 (XSS Neutralization)**:
   - Malicious cell values (e.g. `<script>alert('XSS')</script>`, `<img src=x onerror=...>`) render as harmless plain text.
4. **AC-4 (Memory & Performance Boundary)**:
   - Processing a 500 MB CSV file memory usage stays strictly under 8 MB by parsing only the preview head (max 100 rows).
5. **AC-5 (Truncation Notice)**:
   - If CSV exceeds 100 rows or 50 columns, an alert notice informs the user that rows/columns have been truncated for preview performance.

---

## 5. Security & Performance Requirements

### Security Constraints
- **XSS Prevention**: Every table cell value MUST be processed with `htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **Formula Injection Mitigation**: Raw cell text starting with `=`, `+`, `-`, `@`, `0x09`, `0x0D` is entity-escaped to prevent CSV injection attacks during clipboard copy-pastes.
- **Strict Read-Only Modal**: Rendered in a secure modal container without form elements or mutation scripts.

### Performance Constraints
- **Memory Safety**: Max memory allocation per preview request MUST NOT exceed 4 MB regardless of underlying file size on disk.
- **Line Limit**: Maximum 100 rows parsed and rendered per preview request.
- **Column Limit**: Maximum 50 columns parsed and rendered per row.

---

## 6. Test Cases

| ID | Test Scenario | Input / Payload | Expected Result |
| :--- | :--- | :--- | :--- |
| **TC-CSV-01** | Standard Comma CSV | `id,name,role\n1,Alice,Admin\n2,Bob,User` | Renders 3x3 table with headers `id`, `name`, `role`. |
| **TC-CSV-02** | Semicolon CSV | `id;name;city\n100;Paris;FR\n200;Berlin;DE` | Auto-detects `;` delimiter and renders 3 columns cleanly. |
| **TC-CSV-03** | Malicious XSS Cell | `name,code\nEvil,"<script>alert(1)</script>"` | Cell output is entity-escaped (`&lt;script&gt;`). No script executes. |
| **TC-CSV-04** | Empty CSV File | `` (0 bytes) | Displays empty table notice cleanly without throwing exceptions. |
| **TC-CSV-05** | Oversized CSV (> 100 rows) | 50,000 rows CSV | Reads first 100 rows, truncates remaining rows, displays row truncation alert banner. |

---

## 7. Definition of Done

- [ ] `docs/specs/002-csv-preview.md` approved.
- [ ] Implementation plan created and approved.
- [ ] `CsvPreviewHandler` implemented and registered in `FileInteractionManager`.
- [ ] Unit tests for delimiter detection, cell escaping, and row truncation passing.
- [ ] `bash scripts/agent-verify.sh` passes 100% (PHPStan Level 8 clean).
- [ ] `CLAUDE.md` and `walkthrough.md` updated.
