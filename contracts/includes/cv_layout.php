<?php
/**
 * contracts/includes/cv_layout.php
 *
 * Współdzielony layout widoku umowy — poziomy pasek zakładek NAD treścią
 * (nie z boku — świadomie zrezygnowano z sidebara: zajmował kolumnę na
 * szerokich ekranach bez realnej korzyści i nie mieścił się dobrze na
 * węższych) + sekcje ".cv-section" zamiast klasycznych kart Bootstrap
 * z zakładkami w nagłówku.
 *
 * Użycie:
 *   require_once dirname(__DIR__) . '/includes/cv_layout.php';
 *   ... <div class="cv-tabs-layout">
 *         <ul class="nav nav-pills cv-side-tabs" ...>...</ul>
 *         <div class="tab-content cv-side-tabs-content" ...>...</div>
 *       </div>
 */
?>
<style>
/* Zakładki poziome nad treścią (nazwa klasy `cv-side-tabs` zostaje z
   powodów historycznych/kompatybilności z markupem widoków umów). */
.cv-tabs-layout { display: block; }
.cv-side-tabs {
  display: flex; flex-direction: row; flex-wrap: wrap; gap: .2rem;
  width: 100%; margin: 0 0 1rem; border: none;
  /* Nieprzezroczysta karta — pod spodem jest geometryczne tło aplikacji
     (includes/app_bg.php), które prześwitywało pod tekstem. */
  background: #fff;
  border: 1px solid #E2E8F0;
  border-radius: 12px;
  padding: .35rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.cv-side-tabs .nav-link {
  border: none !important; border-radius: 8px !important;
  padding: .5rem .9rem; font-size: .83rem; font-weight: 500;
  color: #64748B; text-align: center; white-space: nowrap;
  background: transparent !important; width: auto;
  display: inline-flex; align-items: center; gap: .45rem;
  transition: color .15s ease, background .15s ease;
}
.cv-side-tabs .nav-link:hover { color: #1E3A5F; background: #F1F5F9 !important; }
.cv-side-tabs .nav-link.active,
.cv-side-tabs .nav-link[aria-selected="true"] {
  color: #1E6DFF !important; background: #EFF5FF !important; font-weight: 700 !important;
}
.cv-side-tabs .nav-link .bi { font-size: .95rem; flex-shrink: 0; }

/* Zawartość zakładek */
.cv-side-tabs-content {
  width: 100%;
  background: #fff;                     /* treść zawsze na białym, nie na wzorze tła */
  border: 1px solid #E2E8F0 !important;
  border-radius: 12px !important;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  padding: 1.25rem;
}

/* Animacja przełączania — NIE nadpisujemy Bootstrap fade/show (to psuje przełączanie) */
.cv-side-tabs-content .tab-pane.fade.show.active { opacity: 1; }

/* Na wąskich ekranach: pasek zakładek przewija się w poziomie zamiast zawijać */
@media (max-width: 860px) {
  .cv-side-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .cv-side-tabs .nav-link { flex-shrink: 0; }
}

/* Sekcja (zastępuje .card/.card-header/.card-body dla prostych bloków danych) */
.cv-section { padding: 1.2rem 0; border-bottom: 1px solid #F1F5F9; }
.cv-section:last-child { border-bottom: none; padding-bottom: 0; }
.cv-section-head {
  display: flex; align-items: center; gap: .5rem;
  margin-bottom: .9rem;
}
.cv-section-icon {
  width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem;
}
.cv-section-title {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #94A3B8; flex: 1;
}
.cv-section-action { margin-left: auto; }

/* Siatka pól */
.cv-fields { display: flex; flex-wrap: wrap; gap: .8rem 2rem; }
.cv-field { min-width: 130px; flex: 0 1 auto; }
.cv-field-wide { flex: 1 1 260px; }
.cv-field-full { flex: 1 1 100%; }
.cv-label {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #94A3B8; margin-bottom: .2rem;
}
.cv-value { font-size: .9rem; color: #1E293B; line-height: 1.4; }

/* Tabele bez karty */
.cv-table { width: 100%; font-size: .85rem; }
.cv-table th {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: #94A3B8; padding: .5rem 0;
  border-bottom: 1px solid #E2E8F0;
}
.cv-table td { padding: .55rem 0; border-bottom: 1px solid #F8FAFC; color: #374151; vertical-align: middle; }
.cv-table tr:last-child td { border-bottom: none; }

/* Stary .detail-label/.detail-value — zachowaj kompatybilność z istniejącymi widokami */
.detail-label { font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94A3B8;margin-bottom:.2rem; }
.detail-value { font-size:.9rem;color:#1E293B;line-height:1.4; }
</style>
