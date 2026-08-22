/**
 * site.js — komponenty Alpine.js dla części publicznej.
 * Ładowany PRZED Alpine (oba z defer), żeby zdążyć z rejestracją.
 */
document.addEventListener('alpine:init', () => {
    /** Lightbox galerii: powiększanie, nawigacja lewo/prawo, klawiatura, swipe. */
    Alpine.data('lightbox', (images = []) => ({
        images,
        open: false,
        index: 0,
        touchX: null,

        show(i) {
            this.index = i;
            this.open = true;
            document.documentElement.style.overflow = 'hidden';
            this.$nextTick(() => this.$refs.dialog && this.$refs.dialog.focus());
        },
        close() {
            this.open = false;
            document.documentElement.style.overflow = '';
        },
        next() { if (this.images.length) this.index = (this.index + 1) % this.images.length; },
        prev() { if (this.images.length) this.index = (this.index - 1 + this.images.length) % this.images.length; },
        get current() { return this.images[this.index] || { src: '', alt: '', caption: '' }; },

        onTouchStart(e) { this.touchX = e.changedTouches[0].clientX; },
        onTouchEnd(e) {
            if (this.touchX === null) return;
            const dx = e.changedTouches[0].clientX - this.touchX;
            if (Math.abs(dx) > 50) { dx < 0 ? this.next() : this.prev(); }
            this.touchX = null;
        },
    }));
});
