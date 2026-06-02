// Pokaż/ukryj pola zależne od "forma_podpisania"
document.addEventListener('DOMContentLoaded', () => {
  const toggle = (trigger, targets) => {
    const el = document.querySelector(trigger);
    if (!el) return;
    const update = () => {
      targets.forEach(([sel, val]) => {
        const t = document.querySelector(sel);
        if (t) t.closest('.mb-3, .row')?.style?.setProperty('display', el.value === val ? '' : 'none', '');
      });
    };
    el.addEventListener('change', update);
    update();
  };

  // Pola elektroniczne
  toggle('[name="forma_podpisania"]', [
    ['[name="platforma_el"]', 'elektroniczna'],
    ['[name="id_dokumentu_el"]', 'elektroniczna'],
    ['[name="plik_potwierdzenia"]', 'elektroniczna'],
  ]);

  // SQLite/MySQL toggle w install
  const dbType = document.querySelector('[name="db_type"]');
  if (dbType) {
    const mysqlFields = document.getElementById('mysql_fields');
    document.querySelectorAll('[name="db_type"]').forEach(r => {
      r.addEventListener('change', () => {
        if (mysqlFields) mysqlFields.style.display = r.value === 'mysql' ? '' : 'none';
      });
    });
  }

  // Potwierdzenie usunięcia
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  // Auto-expand sidebar on active link (mobile)
  const activeSbLink = document.querySelector('#sidebar .nav-active');
  if (activeSbLink) activeSbLink.scrollIntoView({ block: 'nearest' });
});
