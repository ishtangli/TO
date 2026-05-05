<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

$locationRaw = filter_input(INPUT_GET, 'LOCATION', FILTER_DEFAULT, FILTER_NULL_ON_FAILURE);
$LOCATION = filter_var($locationRaw, FILTER_VALIDATE_INT) ?: 0;

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$reloadUrl = htmlspecialchars($_SERVER['PHP_SELF'] . ($queryString ? '?' . $queryString : ''), ENT_QUOTES);

$summary = fetch_summary_data($LOCATION);
$rows = fetch_open_to_rows($LOCATION);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
$rowsJson = json_encode($rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Open TO Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="120;url=<?php echo $reloadUrl; ?>">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<header class="site-header">
  <div class="brand">
    <h1>Open TO Dashboard</h1>
    <p class="muted">Auto-refresh every 120 seconds</p>
  </div>
  <div class="controls">
    <!-- Location dropdown -->
    <form id="locationForm" method="get">
      <label>Location
        <select name="LOCATION" onchange="document.getElementById('locationForm').submit()">
          <option value="0"<?php if ($LOCATION===0) echo ' selected'; ?>>All</option>
          <option value="1"<?php if ($LOCATION===1) echo ' selected'; ?>>COMPOWH</option>
          <option value="2"<?php if ($LOCATION===2) echo ' selected'; ?>>AUTOMATED</option>
          <option value="3"<?php if ($LOCATION===3) echo ' selected'; ?>>MSU</option>
          <option value="4"<?php if ($LOCATION===4) echo ' selected'; ?>>INFLAM</option>
          <option value="5"<?php if ($LOCATION===5) echo ' selected'; ?>>RAWMAT</option>
          <option value="10"<?php if ($LOCATION===10) echo ' selected'; ?>>AUTOMATED/MSU/INFLAM/RAWMAT</option>
        </select>
      </label>
    </form>

    <!-- Search box -->
    <label>Search <input id="searchInput" type="search" placeholder="Search TO#, priority, location..."></label>

    <!-- Results limit dropdown -->
    <label>Results
      <select id="limitSelect">
        <option value="50">50</option>
        <option value="100" selected>100</option>
        <option value="250">250</option>
        <option value="all">All</option>
      </select>
    </label>

    <!-- Download button -->
    <button id="downloadBtn" class="btn" type="button">Download CSV</button>

    <div class="countdown">Refresh in <span id="countdown">120</span>s</div>
  </div>
</header>

<main class="container">
  <section class="summary-cards">
    <article class="card card-aog"><div class="card-title">AOG</div><div class="card-value"><?php echo h($summary['AOG'] ?? 0); ?></div></article>
    <article class="card card-wsp"><div class="card-title">WSP</div><div class="card-value"><?php echo h($summary['WSP'] ?? 0); ?></div></article>
    <article class="card card-others"><div class="card-title">OTHERS</div><div class="card-value"><?php echo h($summary['OTHERS'] ?? 0); ?></div></article>
  </section>

  <section class="table-panel">
    <h2>Open Transfer Orders</h2>
    <div class="table-wrapper">
      <table class="open-to-table">
        <thead>
          <tr>
            <th>Date</th><th>TO#</th><th>Running Hrs</th><th>Target Hrs</th>
            <th>Remaining Hrs</th><th>Target Date</th><th>Priority</th><th>From</th><th>To</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
  </section>
</main>

<footer class="site-footer">
  <small class="muted">Last updated: <?php echo date('Y-m-d H:i:s'); ?></small>
</footer>

<script>
(function(){
  const REFRESH_SECONDS = 120;
  const countdownEl = document.getElementById('countdown');
  const reloadUrl = "<?php echo $reloadUrl; ?>";
  const rows = <?php echo $rowsJson ?? '[]'; ?>;
  const tableBody = document.getElementById('tableBody');
  const searchInput = document.getElementById('searchInput');
  const limitSelect = document.getElementById('limitSelect');
  const downloadBtn = document.getElementById('downloadBtn');

  // Countdown (deadline-based)
  let deadline = Date.now() + REFRESH_SECONDS * 1000;
  let timerId;
  function updateCountdown(){
    const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    countdownEl.textContent = remaining;
    if (remaining <= 0) {
      location.replace(reloadUrl);
    } else {
      timerId = setTimeout(updateCountdown, 1000);
    }
  }
  updateCountdown();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      clearTimeout(timerId);
      updateCountdown();
    }
  });

  // Helpers
  function escapeHtml(s){ return s === null || s === undefined ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function rowBgClass(rem, i){ if (rem === 0) return 'bg-due'; if (rem < 0) return 'bg-expired'; return (i % 2 === 0) ? 'bg-odd' : 'bg-even'; }

  // Render table
  function renderTable(data){
    const limit = limitSelect.value === 'all' ? data.length : parseInt(limitSelect.value, 10);
    tableBody.innerHTML = '';
    data.slice(0, limit).forEach((r, i) => {
      const tr = document.createElement('tr');
      tr.className = rowBgClass(Number(r.REMAINING_HOURS), i);
      tr.innerHTML = `<td>${escapeHtml(r.TO_DATE)}</td><td>${escapeHtml(r.ORDER_NUMBER)}</td>
        <td>${escapeHtml(r.RUNNING_HOURS)}</td><td>${escapeHtml(r.TARGET_HOURS)}</td>
        <td>${escapeHtml(r.REMAINING_HOURS)}</td><td>${escapeHtml(r.TARGET_DATE)}</td>
        <td>${escapeHtml(r.PRIORITY)}</td><td>${escapeHtml(r.SHIPPED_FROM_LOCATION)}</td><td>${escapeHtml(r.REQUESTER_LOCATION)}</td>`;
      tableBody.appendChild(tr);
    });
  }

  // Filter and sort
  function applyFilters(){
    const q = searchInput.value.trim().toLowerCase();
    const filtered = rows.filter(r => {
      if (!q) return true;
      return String(r.ORDER_NUMBER).toLowerCase().includes(q)
        || String(r.PRIORITY).toLowerCase().includes(q)
        || String(r.SHIPPED_FROM_LOCATION).toLowerCase().includes(q)
        || String(r.REQUESTER_LOCATION).toLowerCase().includes(q);
    });
    filtered.sort((a,b) => Number(a.REMAINING_HOURS) - Number(b.REMAINING_HOURS));
    renderTable(filtered);
    return filtered; // return filtered set for download
  }

  searchInput.addEventListener('input', applyFilters);
  limitSelect.addEventListener('change', applyFilters);

  // CSV download
  function toCSV(rowsArray){
    const headers = ['Date','TO#','Running Hrs','Target Hrs','Remaining Hrs','Target Date','Priority','From','To'];
    const lines = [headers.join(',')];
    rowsArray.forEach(r => {
      const cols = [
        r.TO_DATE ?? '',
        r.ORDER_NUMBER ?? '',
        r.RUNNING_HOURS ?? '',
        r.TARGET_HOURS ?? '',
        r.REMAINING_HOURS ?? '',
        r.TARGET_DATE ?? '',
        r.PRIORITY ?? '',
        r.SHIPPED_FROM_LOCATION ?? '',
        r.REQUESTER_LOCATION ?? ''
      ].map(cell => {
        // Escape double quotes and wrap in quotes if needed
        const s = String(cell).replace(/"/g, '""');
        return /[",\n]/.test(s) ? `"${s}"` : s;
      });
      lines.push(cols.join(','));
    });
    return lines.join('\n');
  }

  downloadBtn.addEventListener('click', () => {
    // Use the currently filtered dataset (before applying limit) so user can choose limited or full via Results dropdown
    const filtered = applyFilters();
    const limit = limitSelect.value === 'all' ? filtered.length : parseInt(limitSelect.value, 10);
    const toExport = filtered.slice(0, limit);
    if (!toExport.length) {
      // simple user feedback
      downloadBtn.textContent = 'No rows to download';
      setTimeout(() => downloadBtn.textContent = 'Download CSV', 1500);
      return;
    }
    const csv = toCSV(toExport);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const ts = new Date().toISOString().replace(/[:.]/g,'-');
    a.href = url;
    a.download = `open_to_export_${ts}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });

  // Initial render
  applyFilters();
})();
</script>
</body>
</html>
