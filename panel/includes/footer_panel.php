<?php
$_pv_org_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>
</main>
<footer class="pv-footer" role="contentinfo">
  <span><i class="bi bi-person-circle me-1" style="color:var(--vol-color)" aria-hidden="true"></i>Panel wolontariusza<?= $_pv_org_f ? ' · '.h($_pv_org_f) : '' ?></span>
  <span>&copy; <?= date('Y') ?></span>
</footer>
</div><!-- /pv-shell -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var btn=document.createElement('button');
  btn.type='button';btn.className='btn d-md-none';
  btn.setAttribute('aria-label','Otwórz nawigację');btn.setAttribute('aria-expanded','false');
  btn.innerHTML='<i class="bi bi-list" style="font-size:1.3rem;color:var(--vol-on)" aria-hidden="true"></i>';
  btn.style.cssText='margin-right:.25rem;margin-left:.5rem;background:none;border:none;padding:.25rem .5rem;cursor:pointer';
  var tb=document.querySelector('.pv-topbar');
  var sb=document.getElementById('pv-sidebar');
  if(tb&&sb){
    var brand=tb.querySelector('.pv-brand');
    if(brand)brand.after(btn);
    btn.addEventListener('click',function(){
      var open=sb.classList.toggle('open');
      btn.setAttribute('aria-expanded',open?'true':'false');
    });
    document.addEventListener('keydown',function(e){
      if(e.key==='Escape'&&sb.classList.contains('open')){sb.classList.remove('open');btn.focus();}
    });
  }
})();
</script>
</body>
</html>
