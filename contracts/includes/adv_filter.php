<?php
/**
 * contracts/includes/adv_filter.php
 * Shared CSS + JS for advanced filter panels in contract list pages.
 */
?>
<style>
.adv-toggle {
  display: inline-flex; align-items: center; gap: .35rem;
  font-size: .8rem; color: #6B7280; cursor: pointer;
  padding: .28rem .65rem; border-radius: 6px;
  background: transparent; border: 1px solid #E5E7EB;
  transition: background .1s, color .1s;
  text-decoration: none; white-space: nowrap;
}
.adv-toggle:hover { background: #F3F4F6; color: #111827; border-color: #D1D5DB; }
.adv-toggle.is-active { color: #0176D3; border-color: #93C5FD; background: #EFF6FF; }

.adv-panel {
  background: #F9FAFB;
  border: 1px solid #E5E7EB;
  border-radius: 8px;
  padding: .85rem 1rem .65rem;
  margin-top: .4rem;
}
.adv-panel .adv-label {
  font-size: .7rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; color: #9CA3AF; margin-bottom: .2rem;
  display: block;
}
.adv-panel .form-control,
.adv-panel .form-select {
  font-size: .82rem; height: calc(1.5em + .6rem + 2px); padding: .3rem .55rem;
}
.adv-panel .form-check-label { font-size: .82rem; }
.adv-sep { width: 1px; background: #E5E7EB; align-self: stretch; margin: 0 .25rem; }

.active-chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .5rem; }
.active-chip {
  display: inline-flex; align-items: center; gap: .25rem;
  font-size: .72rem; font-weight: 600; padding: .15rem .5rem;
  border-radius: 2rem; background: #EFF6FF; color: #1D4ED8;
  border: 1px solid #BFDBFE; text-decoration: none;
}
.active-chip .chip-x { font-size: .85em; opacity: .7; }
.active-chip:hover { background: #DBEAFE; text-decoration: none; color: #1E40AF; }

.sort-btn { font-size: .78rem; padding: .25rem .55rem; }
.contracts-table th[data-sort] { cursor: pointer; user-select: none; white-space: nowrap; }
.contracts-table th[data-sort]:hover { background: #EFF6FF; }
.sort-arrow { font-size: .7em; opacity: .5; margin-left: .15rem; }
.sort-arrow.active { opacity: 1; color: #0176D3; }
</style>
