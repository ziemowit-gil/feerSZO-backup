<?php /* Baner informacyjny — trwające prace nad nową wersją systemu */ ?>
<div id="banner-rewrite" class="alert alert-info alert-dismissible d-flex align-items-center gap-2 mb-0 rounded-0 border-0 border-bottom py-2 px-3" role="alert"
     style="font-size:.875rem;display:none!important">
  <i class="bi bi-tools flex-shrink-0"></i>
  <span>
    <strong>Trwają prace nad nową wersją systemu.</strong>
    Obecna wersja działa w pełni normalnie. Jeśli zauważysz jakiś błąd — prosimy zgłosić go administratorowi.
  </span>
  <button type="button" class="btn-close ms-auto" aria-label="Zamknij" onclick="
    localStorage.setItem('banner_rewrite_dismissed','1');
    document.getElementById('banner-rewrite').style.display='none';
  "></button>
</div>
<script>
(function(){
  if (localStorage.getItem('banner_rewrite_dismissed') !== '1') {
    var el = document.getElementById('banner-rewrite');
    if (el) el.style.removeProperty('display');
  }
})();
</script>
