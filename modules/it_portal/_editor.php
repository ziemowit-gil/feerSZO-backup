<?php
/**
 * modules/it_portal/_editor.php — TinyMCE dla pól .itp-rich w panelu Portalu Usług IT.
 * Textarea zostaje widoczna do czasu startu edytora (nigdy display:none — TinyMCE sam ją ukrywa).
 */
?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
  selector: 'textarea.itp-rich',
  license_key: 'gpl',
  promotion: false,
  branding: false,
  menubar: false,
  plugins: 'lists link table code autoresize',
  toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat code',
  block_formats: 'Akapit=p; Nagłówek=h2; Podtytuł=h3',
  min_height: 180,
  autoresize_bottom_margin: 20,
  entity_encoding: 'raw',
  link_default_protocol: 'https',
  setup: function (editor) { editor.on('change', function () { editor.save(); }); }
});
document.addEventListener('submit', function () { if (window.tinymce) tinymce.triggerSave(); }, true);
</script>
