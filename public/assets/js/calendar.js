 (function(){
  const target = document.getElementById('calendar');
  if (!target) return;
  const torneos = window.TORNEOS_CAL || [];

  // Formats YYYY-MM-DD -> DD/MM without creating Date objects (avoids TZ shifts)
  function fmtDM(dstr){
    if (!dstr) return '';
    const parts = String(dstr).split('-');
    if (parts.length < 3) return dstr;
    const [y,m,d] = parts;
    return `${String(d).padStart(2,'0')}/${String(m).padStart(2,'0')}`;
  }
  function formatRange(inicio, fin){
    if (!inicio) return '';
    if (!fin || fin === '') return fmtDM(inicio);
    if (inicio === fin) return fmtDM(inicio);
    return `${fmtDM(inicio)} – ${fmtDM(fin)}`;
  }

  let cur = new Date(); cur.setDate(1);

  function render(){
    target.innerHTML = '';
    const header = document.createElement('div');
    header.style.display = 'flex'; header.style.justifyContent = 'space-between'; header.style.alignItems = 'center'; header.style.marginBottom = '.6rem';

    const prev = document.createElement('button'); prev.className = 'btn'; prev.textContent = '<'; prev.onclick = () => { cur.setMonth(cur.getMonth()-1); render(); };
    const next = document.createElement('button'); next.className = 'btn'; next.textContent = '>'; next.onclick = () => { cur.setMonth(cur.getMonth()+1); render(); };
    const title = document.createElement('div'); title.innerHTML = '<strong>' + cur.toLocaleString(undefined, { month: 'long', year: 'numeric' }) + '</strong>';
    header.appendChild(prev); header.appendChild(title); header.appendChild(next);
    target.appendChild(header);

    const grid = document.createElement('div'); grid.style.display = 'grid'; grid.style.gridTemplateColumns = 'repeat(7,1fr)'; grid.style.gap = '4px';
    ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'].forEach(d=>{ const h = document.createElement('div'); h.style.fontSize = '.8rem'; h.style.textAlign = 'center'; h.style.color = 'var(--muted)'; h.textContent = d; grid.appendChild(h); });

    const firstWeekday = (cur.getDay()+6) % 7; // Monday = 0
    const daysInMonth = new Date(cur.getFullYear(), cur.getMonth()+1, 0).getDate();

    for (let i = 0; i < firstWeekday; i++) { grid.appendChild(document.createElement('div')); }

    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

    for (let d = 1; d <= daysInMonth; d++) {
      const cell = document.createElement('div');
      cell.style.minHeight = '80px';
      cell.style.padding = '8px';
      cell.style.borderRadius = '6px';
      cell.style.background = 'transparent';
      cell.style.border = '1px solid rgba(0,0,0,0.04)';
      cell.style.display = 'flex';
      cell.style.flexDirection = 'column';
      cell.style.gap = '6px';
      cell.style.boxSizing = 'border-box';

      const dateStr = `${cur.getFullYear()}-${String(cur.getMonth()+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const dayTitle = document.createElement('div'); dayTitle.style.fontSize = '.85rem'; dayTitle.style.marginBottom = '2px'; dayTitle.style.color = 'var(--text)'; dayTitle.textContent = d; cell.appendChild(dayTitle);

      if (dateStr === todayStr) {
        cell.style.background = 'linear-gradient(90deg, rgba(255,245,157,0.12), rgba(255,245,157,0.06))';
        cell.style.border = '1px solid rgba(255,213,79,0.25)';
      }

      // Show ONLY tournaments whose inicio exactly matches this date
      const matches = torneos.filter(t => t && typeof t.inicio === 'string' && t.inicio === dateStr);

      if (matches.length) {
        const list = document.createElement('div'); list.style.display = 'flex'; list.style.flexDirection = 'column'; list.style.gap = '6px'; list.style.width = '100%';

        matches.forEach(m => {
          const a = document.createElement('a');
          a.href = `/torneo/${m.id}`;
          a.style.display = 'block'; a.style.textDecoration = 'none'; a.style.color = 'inherit'; a.style.width = '100%'; a.style.boxSizing = 'border-box';

          const card = document.createElement('div');
          card.style.padding = '.4rem .5rem';
          card.style.borderRadius = '8px';
          card.style.background = 'rgba(255,255,255,0.03)';
          card.style.border = '1px solid rgba(255,255,255,0.03)';
          card.style.display = 'flex'; card.style.flexDirection = 'column'; card.style.gap = '4px'; card.style.overflow = 'hidden';

          const title = document.createElement('div');
          title.style.fontSize = '0.9rem'; title.style.fontWeight = '700'; title.style.color = 'var(--text)'; title.style.whiteSpace = 'nowrap'; title.style.overflow = 'hidden'; title.style.textOverflow = 'ellipsis';
          title.textContent = m.nombre || '';

          const range = document.createElement('div'); range.className = 'muted'; range.style.fontSize = '.8rem'; range.style.color = 'var(--muted)';
          range.textContent = formatRange(m.inicio, m.fin);

          card.appendChild(title); card.appendChild(range);
          a.appendChild(card);
          list.appendChild(a);
        });

        cell.appendChild(list);
      }

      grid.appendChild(cell);
    }

    target.appendChild(grid);
  }

  render();
})();
