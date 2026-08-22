<?php
/**
 * lightbox.php — nakładka Lightboxa (Alpine).
 * Wymaga rodzica z x-data="lightbox([...])".
 */
?>
<div class="lb" x-show="open" x-cloak x-transition.opacity
     @keydown.escape.window="close()" @keydown.arrow-right.window="next()" @keydown.arrow-left.window="prev()"
     @click.self="close()" tabindex="-1" x-ref="dialog" role="dialog" aria-modal="true">

  <button class="lb__close" type="button" @click="close()" aria-label="Zamknij">
    <i class="bi bi-x-lg" aria-hidden="true"></i>
  </button>

  <button class="lb__nav lb__nav--prev" type="button" @click.stop="prev()" x-show="images.length > 1" aria-label="Poprzednie zdjęcie">
    <i class="bi bi-chevron-left" aria-hidden="true"></i>
  </button>

  <figure class="lb__figure" @touchstart="onTouchStart($event)" @touchend="onTouchEnd($event)" @click.stop>
    <img class="lb__img" :src="current.src" :alt="current.alt">
    <figcaption class="lb__caption" x-show="current.caption" x-text="current.caption"></figcaption>
    <p class="lb__counter" x-show="images.length > 1">
      <span x-text="index + 1"></span> / <span x-text="images.length"></span>
    </p>
  </figure>

  <button class="lb__nav lb__nav--next" type="button" @click.stop="next()" x-show="images.length > 1" aria-label="Następne zdjęcie">
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
  </button>
</div>
