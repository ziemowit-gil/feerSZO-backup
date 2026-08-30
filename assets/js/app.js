// Zakładkowy formularz umowy (Tailwind + Alpine) — jak contractFormGuard (spinner
// przy zapisie + ostrzeżenie o niezapisanych zmianach), plus obsługa zakładek.
// Formularz ma `novalidate`, żeby przejąć natywną walidację HTML5: bez tego, gdy
// pole "required" leży na ukrytej (x-show=false → display:none) zakładce, przeglądarka
// blokuje wysyłkę bez pokazania dymka błędu (nie da się go wyrenderować na ukrytym
// elemencie) — formularz "zawiesza się" bez żadnej informacji dla użytkownika.
// Zamiast tego walidujemy sami: przy submit sprawdzamy checkValidity(), przełączamy
// na zakładkę z pierwszym błędnym polem, i dopiero wtedy wołamy reportValidity().
document.addEventListener('alpine:init', () => {
  Alpine.data('tabbedContractForm', (numTabs = 1) => ({
    tab: 1,
    numTabs,
    submitting: false,
    dirty: false,
    init() {
      this.$el.addEventListener('input', () => { this.dirty = true; });
      this.$el.addEventListener('change', () => { this.dirty = true; });
      window.addEventListener('beforeunload', (e) => {
        if (this.dirty && !this.submitting) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    },
    goTab(n) {
      this.tab = n;
    },
    onSubmit(e) {
      // Drugorzędne przyciski submit (np. "Zapisz roboczo") mogą mieć `formnovalidate`,
      // żeby ominąć pola "required" przy zapisie roboczym. checkValidity() o tym nie wie
      // (nie jest świadome KTÓRY przycisk wywołał submit) — trzeba sprawdzić to ręcznie
      // przez e.submitter, inaczej złamalibyśmy istniejące przyciski "zapisz roboczo".
      if (e.submitter && e.submitter.formNoValidate) {
        this.submitting = true;
        this.dirty = false;
        return;
      }
      if (!this.$el.checkValidity()) {
        e.preventDefault();
        const invalid = this.$el.querySelector(':invalid');
        if (invalid) {
          const pane = invalid.closest('[data-tab-pane]');
          if (pane) this.tab = parseInt(pane.dataset.tabPane, 10);
          this.$nextTick(() => invalid.reportValidity());
        }
        return;
      }
      this.submitting = true;
      this.dirty = false;
    },
  }));
});

// Modal "Złóż wniosek o edycję" (Alpine.js) — używany przez wszystkie typy umów,
// patrz includes/contract_correction.php: edit_request_trigger_html().
document.addEventListener('alpine:init', () => {
  Alpine.data('editRequestModal', (cfg) => ({
    open: false,
    submitting: false,
    errors: [],
    opis: '',
    labelId: 'erm-label-' + cfg.type + '-' + cfg.id,
    textareaId: 'erm-opis-' + cfg.type + '-' + cfg.id,

    openModal() {
      this.open = true;
      this.errors = [];
      this.$nextTick(() => this.$refs.textarea && this.$refs.textarea.focus());
    },

    close() {
      if (this.submitting) return;
      this.open = false;
    },

    submitForm() {
      if (this.submitting) return;
      if (!this.opis.trim()) {
        this.errors = ['Opis wymaganych zmian jest obowiązkowy.'];
        this.$nextTick(() => this.$refs.textarea && this.$refs.textarea.focus());
        return;
      }
      this.submitting = true;
      this.errors = [];
      const body = new URLSearchParams({ type: cfg.type, id: cfg.id, opis_zmian: this.opis, _csrf: cfg.csrf });
      fetch(cfg.endpoint, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
      })
        .then(r => r.json().catch(() => null))
        .then(data => {
          if (data && data.ok) {
            window.location.reload();
            return;
          }
          this.errors = (data && data.errors) || ['Nie udało się złożyć wniosku. Spróbuj ponownie.'];
          this.submitting = false;
          this.$nextTick(() => this.$refs.textarea && this.$refs.textarea.focus());
        })
        .catch(() => {
          this.errors = ['Błąd połączenia. Spróbuj ponownie.'];
          this.submitting = false;
        });
    },
  }));
});

// Formularze dodawania/edycji umów (add.php, edit.php, wizard.php wszystkich typów):
// blokada podwójnej wysyłki (spinner na przycisku) + ostrzeżenie przed wyjściem
// z niezapisanymi zmianami. Czysto kosmetyczne — nie ingeruje w walidację ani
// zapis po stronie serwera, formularz nadal wysyła się natywnie (POST).
document.addEventListener('alpine:init', () => {
  Alpine.data('contractFormGuard', () => ({
    submitting: false,
    dirty: false,
    init() {
      this.$el.addEventListener('input', () => { this.dirty = true; });
      this.$el.addEventListener('change', () => { this.dirty = true; });
      window.addEventListener('beforeunload', (e) => {
        if (this.dirty && !this.submitting) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    },
    onSubmit() {
      this.submitting = true;
      this.dirty = false;
    },
  }));
});

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
