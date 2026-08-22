<?php
/**
 * ui.php — słownik klas Tailwind dla panelu.
 * Trzyma wygląd w jednym miejscu: ui('input'), ui('card'), ui('btn') ...
 */
declare(strict_types=1);

function ui(string $key): string
{
    static $c = [
        'card'      => 'rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5 sm:p-6',
        'card_flat' => 'rounded-xl border border-zinc-800 bg-zinc-900/40 p-4',
        'label'     => 'mb-1.5 block text-sm font-medium text-zinc-300',
        'hint'      => 'mt-1 text-xs text-zinc-500',
        'input'     => 'w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition focus:border-indigo-500',
        'textarea'  => 'w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-xs leading-relaxed text-zinc-100 outline-none transition focus:border-indigo-500',
        'select'    => 'w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 outline-none transition focus:border-indigo-500',
        'checkbox'  => 'h-4 w-4 rounded border-zinc-600 bg-zinc-950 text-indigo-500 focus:ring-indigo-500',
        'color'     => 'h-9 w-12 cursor-pointer rounded border border-zinc-700 bg-zinc-950 p-1',
        'btn'       => 'inline-flex items-center gap-2 rounded-lg border border-zinc-700 bg-zinc-800 px-3.5 py-2 text-sm font-medium text-zinc-100 transition hover:bg-zinc-700',
        'btn_primary' => 'inline-flex items-center gap-2 rounded-lg bg-indigo-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-400',
        'btn_danger'  => 'inline-flex items-center gap-2 rounded-lg border border-rose-900 bg-rose-950/60 px-3 py-2 text-sm font-medium text-rose-300 transition hover:bg-rose-900/60',
        'btn_ghost'   => 'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-100',
        'badge'     => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
        'th'        => 'px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-zinc-500',
        'td'        => 'px-4 py-3 text-sm text-zinc-300 align-middle',
        'section_h' => 'text-sm font-semibold uppercase tracking-wider text-zinc-400',
    ];
    return $c[$key] ?? '';
}
