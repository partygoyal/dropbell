(() => {
  let activeMenu = null;
  function closeMenu() {
    if (!activeMenu) return;
    const { menu, actions, summary } = activeMenu;
    activeMenu = null;
    menu.open = false;
    summary.setAttribute('aria-expanded', 'false');
    menu.append(actions);
  }
  document.addEventListener('pointerdown', event => {
    if (activeMenu && !activeMenu.menu.contains(event.target) && !activeMenu.actions.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && activeMenu) {
      const summary = activeMenu.summary;
      closeMenu(); summary.focus();
    }
  });
  window.addEventListener('resize', closeMenu);
  document.addEventListener('scroll', event => {
    if (activeMenu && !activeMenu.actions.contains(event.target)) closeMenu();
  }, true);
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
  const csvCell = value => {
    const safe = /^\s*[=+@-]/u.test(value) ? `'${value}` : value;
    return `"${safe.replaceAll('"', '""')}"`;
  };
  function download(headers, rows, name) {
    const csv = [headers, ...rows].map(row => row.map(csvCell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${name}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  for (const [tableIndex, table] of [...document.querySelectorAll('.dashboard table')].entries()) {
    if (table.dataset.enhanced || !table.tHead || !table.tBodies[0]) continue;
    table.dataset.enhanced = 'true';
    table.classList.add('advanced-table');
    const body = table.tBodies[0];
    const headers = [...table.tHead.rows[0].cells];
    const rows = [...body.rows].filter(row => row.cells.length === headers.length && !row.querySelector('td[colspan]'));
    let actionIndex = headers.findIndex(cell => !cell.textContent.trim() || cell.textContent.trim().toLowerCase() === 'actions');
    if (actionIndex < 0) {
      actionIndex = headers.length;
      const th = document.createElement('th');
      th.textContent = 'Actions';
      table.tHead.rows[0].append(th);
    } else headers[actionIndex].textContent = 'Actions';
    const titles = headers.filter((_, index) => index !== actionIndex).map(cell => cell.textContent.trim());
    const records = rows.map((row, originalIndex) => ({ row, originalIndex, values: [...row.cells].filter((_, index) => index !== actionIndex).map(cell => cell.innerText?.trim() || cell.textContent.trim()) }));
    const serverKey = table.dataset.serverTable;
    const serverPaging = !!serverKey;
    if (serverPaging) {
      const panel = table.closest('.panel');
      for (const pager of panel?.querySelectorAll('.admin-table-footer, .pagination, nav[role="navigation"]') || []) pager.hidden = true;
      const next = panel?.nextElementSibling;
      if (next?.matches('.pagination, nav[role="navigation"]')) next.hidden = true;
    }
    const title = table.closest('.panel')?.querySelector('h2')?.textContent.trim() || document.querySelector('h1')?.textContent.trim() || 'Table';
    const toolbar = document.createElement('div');
    toolbar.className = 'table-toolbar';
    const searchLabel = document.createElement('label');
    searchLabel.textContent = 'Search loaded rows';
    const search = document.createElement('input');
    search.type = 'search'; search.placeholder = `Search ${title.toLowerCase()}`;
    search.setAttribute('aria-label', `Search loaded rows in ${title}`);
    searchLabel.append(search);
    const sizeLabel = document.createElement('label');
    sizeLabel.textContent = 'Rows to show';
    const size = document.createElement('select');
    for (const [value, label] of [['10', '10'], ['20', '20'], ['50', '50'], ['100', '100'], ['200', '200']]) {
      const option = document.createElement('option'); option.value = value; option.textContent = label; size.append(option);
    }
    size.value = new URL(location.href).searchParams.get('per_page') || '20';
    sizeLabel.append(size);
    const exportButton = document.createElement('button'); exportButton.type = 'button'; exportButton.textContent = 'Export visible rows ↓';
    const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'table-secondary'; reset.textContent = 'Reset table';
    toolbar.append(searchLabel, sizeLabel, exportButton);
    const allExport = document.createElement('button'); allExport.type = 'button'; allExport.className = 'table-secondary';
    allExport.textContent = serverPaging ? 'Export all matching records ↓' : 'Export entire table ↓';
    const entireExport = document.createElement('button'); entireExport.type = 'button'; entireExport.className = 'table-secondary'; entireExport.textContent = 'Export entire dataset ↓';
    function serverExport(entire) {
      const url = new URL(table.dataset.fullExportUrl || location.href);
      if (entire) for (const key of [...url.searchParams.keys()]) if (!(serverKey?.startsWith('report-') && key === 'type')) url.searchParams.delete(key);
      url.searchParams.delete('page'); url.searchParams.delete('per_page');
      url.searchParams.set('export', 'all'); url.searchParams.set('export_table', table.dataset.fullExportKey || serverKey);
      window.location.assign(url.href);
    }
    allExport.addEventListener('click', () => {
      if (serverPaging) serverExport(false);
      else download(titles, records.map(record => record.values), `dropbell-table-${tableIndex + 1}`);
    });
    entireExport.addEventListener('click', () => serverExport(true));
    toolbar.append(allExport);
    if (serverPaging || table.dataset.fullExportUrl) toolbar.append(entireExport);
    toolbar.append(reset);
    const note = document.createElement('p'); note.className = 'table-scope-note';
    note.textContent = serverPaging ? 'Visible export uses the rows shown below. All matching records uses the page filters; entire dataset removes those filters. Quick search and sorting apply to the current page.' : 'Search and visible export apply to this table. Entire table includes all its loaded rows.';
    const footer = document.createElement('div'); footer.className = 'table-tool-footer';
    const state = document.createElement('span'); state.setAttribute('role', 'status'); state.setAttribute('aria-live', 'polite');
    const previous = document.createElement('button'); previous.type = 'button'; previous.textContent = serverPaging ? '← Previous page' : '← Previous rows';
    const next = document.createElement('button'); next.type = 'button'; next.textContent = serverPaging ? 'Next page →' : 'Next rows →';
    footer.append(state, previous, next);
    let wrap = table.closest('.table-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'table-wrap'; table.before(wrap); wrap.append(table); }
    wrap.before(toolbar, note); wrap.after(footer);
    let sortIndex = -1, ascending = true, page = 0;
    let filtered = [...records];
    const noResults = document.createElement('tr');
    const emptyCell = document.createElement('td'); emptyCell.colSpan = table.tHead.rows[0].cells.length; emptyCell.className = 'empty'; emptyCell.textContent = 'No rows match your search.'; noResults.append(emptyCell);
    function sortValue(value) {
      const numeric = value.replaceAll(',', '').replace(/^₹\s*/, '');
      if (/^[+-]?\d+(\.\d+)?$/.test(numeric)) return Number(numeric);
      if (/^\d{1,2} [A-Za-z]{3} \d{4}/.test(value) || /^\d{4}-\d{2}-\d{2}/.test(value)) {
        const date = Date.parse(value.replace(/ IST$/, '+05:30'));
        if (Number.isFinite(date)) return date;
      }
      return value;
    }
    function render() {
      closeMenu();
      const term = search.value.trim().toLocaleLowerCase();
      filtered = records.filter(record => record.values.join(' ').toLocaleLowerCase().includes(term));
      if (sortIndex >= 0) filtered.sort((a, b) => {
        const left = sortValue(a.values[sortIndex]), right = sortValue(b.values[sortIndex]);
        const result = typeof left === 'number' && typeof right === 'number' ? left - right : collator.compare(String(left), String(right));
        return (ascending ? result : -result) || a.originalIndex - b.originalIndex;
      });
      const limit = serverPaging ? Math.max(1, filtered.length) : Number(size.value);
      page = Math.min(page, Math.max(0, Math.ceil(filtered.length / limit) - 1));
      if (records.length) {
        for (const row of [...body.rows]) row.remove();
        for (const record of filtered) { record.row.hidden = true; body.append(record.row); }
        for (const record of filtered.slice(page * limit, (page + 1) * limit)) record.row.hidden = false;
        if (!filtered.length) body.append(noResults);
      } else for (const cell of body.querySelectorAll('td[colspan]')) cell.colSpan = table.tHead.rows[0].cells.length;
      state.textContent = `${filtered.length ? page * limit + 1 : 0}–${Math.min((page + 1) * limit, filtered.length)} of ${filtered.length} matching loaded rows`;
      if (serverPaging) {
        previous.disabled = !table.dataset.previousPage; next.disabled = !table.dataset.nextPage;
        previous.hidden = next.hidden = Number(table.dataset.pageCount) <= 1;
        state.textContent = `Page ${table.dataset.currentPage} of ${table.dataset.pageCount} · ${table.dataset.total} records · ${filtered.length} shown on this page`;
      } else {
        previous.disabled = page === 0; next.disabled = (page + 1) * limit >= filtered.length;
        previous.hidden = next.hidden = filtered.length <= limit;
      }
      exportButton.disabled = !filtered.length;
    }
    let valueIndex = 0;
    for (const [index, header] of headers.entries()) {
      header.scope = 'col';
      if (index === actionIndex) continue;
      const columnIndex = valueIndex++;
      const button = document.createElement('button'); button.type = 'button'; button.className = 'table-sort'; button.textContent = `${header.textContent.trim()} ↕`;
      button.setAttribute('aria-label', `Sort by ${header.textContent.trim()}`);
      header.replaceChildren(button);
      button.addEventListener('click', () => {
        ascending = sortIndex === columnIndex ? !ascending : true; sortIndex = columnIndex; page = 0;
        for (const cell of headers) cell.removeAttribute('aria-sort');
        header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending'); render();
      });
    }
    for (const record of records) {
      let cell = record.row.cells[actionIndex];
      if (!cell) cell = record.row.insertCell();
      cell.classList.add('advanced-row-actions');
      const menu = document.createElement('details'); menu.className = 'table-actions-menu';
      const summary = document.createElement('summary'); summary.textContent = 'Actions ▾';
      const actions = document.createElement('div');
      if (cell.childNodes.length) {
        while (cell.firstChild) actions.append(cell.firstChild);
      } else {
        const link = record.row.querySelector('a[href]');
        if (link) { const view = link.cloneNode(true); view.textContent = 'View related record'; actions.append(view); }
      }
      for (const nested of [...actions.querySelectorAll('details.table-actions-menu')]) {
        const content = nested.querySelector('div');
        if (content) nested.replaceWith(...content.childNodes);
      }
      const copy = document.createElement('button'); copy.type = 'button'; copy.textContent = 'Copy row details';
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(record.values.map((value, index) => `${titles[index]}: ${value}`).join('\n')); state.textContent = 'Row details copied.'; }
        catch { state.textContent = 'Clipboard access unavailable. Select the row text and copy it manually.'; }
        menu.open = false;
      });
      actions.append(copy); menu.append(summary, actions); cell.append(menu);
      actions.classList.add('floating-table-actions');
      summary.setAttribute('aria-expanded', 'false');
      summary.setAttribute('aria-controls', `table-actions-${tableIndex}-${record.originalIndex}`);
      actions.id = `table-actions-${tableIndex}-${record.originalIndex}`;
      menu.addEventListener('toggle', () => {
        if (!menu.open) {
          if (activeMenu?.menu === menu) closeMenu();
          return;
        }
        if (!menu.isConnected) { menu.open = false; return; }
        if (activeMenu?.menu === menu) return;
        closeMenu();
        activeMenu = { menu, actions, summary };
        summary.setAttribute('aria-expanded', 'true');
        document.body.append(actions);
        actions.style.visibility = 'hidden';
        actions.style.maxHeight = `${Math.max(80, window.innerHeight - 24)}px`;
        const anchor = summary.getBoundingClientRect();
        const popup = actions.getBoundingClientRect();
        actions.style.left = `${Math.max(12, Math.min(anchor.right - popup.width, window.innerWidth - popup.width - 12))}px`;
        const below = anchor.bottom + 6;
        actions.style.top = `${Math.max(12, below + popup.height <= window.innerHeight - 12 ? below : anchor.top - popup.height - 6)}px`;
        actions.style.visibility = 'visible';
      });
      summary.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') {
          event.preventDefault(); menu.open = true;
          requestAnimationFrame(() => actions.querySelector('a,button')?.focus());
        }
      });
      actions.addEventListener('keydown', event => {
        const controls = [...actions.querySelectorAll('a[href],button:not(:disabled)')];
        const index = controls.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          controls[(index + (event.key === 'ArrowDown' ? 1 : controls.length - 1)) % controls.length]?.focus();
        }
      });
      actions.addEventListener('click', event => {
        if (event.target.closest('a,button')) closeMenu();
      });
      actions.addEventListener('focusout', () => {
        setTimeout(() => { if (activeMenu?.menu === menu && !actions.contains(document.activeElement) && !menu.contains(document.activeElement)) closeMenu(); }, 0);
      });
    }
    search.addEventListener('input', () => { page = 0; render(); });
    size.addEventListener('change', () => {
      if (serverPaging) {
        const url = new URL(location.href); url.searchParams.set('per_page', size.value); url.searchParams.delete('page'); window.location.assign(url.href);
      } else { page = 0; render(); }
    });
    previous.addEventListener('click', () => { if (serverPaging) window.location.assign(table.dataset.previousPage); else { page--; render(); } }); next.addEventListener('click', () => { if (serverPaging) window.location.assign(table.dataset.nextPage); else { page++; render(); } });
    reset.addEventListener('click', () => { search.value = ''; size.value = new URL(location.href).searchParams.get('per_page') || '20'; page = 0; sortIndex = -1; ascending = true; for (const header of headers) header.removeAttribute('aria-sort'); render(); });
    exportButton.addEventListener('click', () => download(titles, filtered.filter(record => !record.row.hidden).map(record => record.values), `dropbell-table-${tableIndex + 1}`));
    render();
  }
})();
