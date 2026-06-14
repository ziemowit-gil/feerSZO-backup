<?php /** Wspólna stopka panelu kursanta/rodzica. */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Przełącznik motywu jasny/ciemny (zapamiętywany w localStorage).
document.addEventListener('click', function(e){
  var btn = e.target.closest('#kp-theme-toggle');
  if (!btn) return;
  var next = document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-bs-theme', next);
  try { localStorage.setItem('kp-theme', next); } catch (e) {}
});
</script>
</body>
</html>
