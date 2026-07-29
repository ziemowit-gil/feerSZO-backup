# Publiczne API komunikatów

Endpoint do pobierania komunikatów (ogłoszeń organizacji) na zewnętrzne strony
WWW. **Bez autoryzacji**, CORS `*`. Zwraca wyłącznie komunikaty publiczne
(`audience = public`), aktywne i nieprzeterminowane.

## URL

```
GET /api/komunikaty/list.php
```

## Parametry (GET)

| Parametr    | Opis                                                                                   | Domyślnie |
|-------------|----------------------------------------------------------------------------------------|-----------|
| `limit`     | Liczba komunikatów, 1–50                                                                | 20        |
| `id`        | Pobierz jeden komunikat po ID (zwraca `item` zamiast `items`)                           | –         |
| `kategoria` | Filtr: `ogolne`, `pilne`, `wydarzenie`, `techniczne`, `rodo`, `administracyjne`         | wszystkie |
| `q`         | Filtr po tytule (fraza)                                                                 | –         |

## Odpowiedź — lista

```json
{
  "ok": true,
  "org": "Fundacja Edukacji Empatii Rozwoju FEER",
  "count": 2,
  "items": [
    {
      "id": 12,
      "title": "Przerwa techniczna w sobotę",
      "kategoria": "techniczne",
      "kategoria_label": "Techniczne / przerwa w działaniu",
      "kategoria_color": "#0EA5E9",
      "is_pinned": true,
      "excerpt": "W sobotę od 8:00 do 12:00 serwis będzie niedostępny…",
      "body_html": "<p>W sobotę od 8:00 do 12:00 …</p>",
      "published_at": "2026-07-20 09:14:00",
      "expires_at": "2026-08-01"
    }
  ]
}
```

## Odpowiedź — pojedynczy (`?id=12`)

```json
{ "ok": true, "org": "...", "item": { "id": 12, "...": "..." } }
```

Gdy komunikat nie istnieje / nie jest publiczny → HTTP 404 `{"ok": false, "error": "..."}`.

## Przykłady użycia

### curl
```bash
curl "https://TWOJA-DOMENA/api/komunikaty/list.php?limit=5&kategoria=pilne"
```

### JavaScript (fetch)
```html
<div id="komunikaty"></div>
<script>
fetch('https://TWOJA-DOMENA/api/komunikaty/list.php?limit=5')
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return;
    document.getElementById('komunikaty').innerHTML = d.items.map(k => `
      <article style="border-left:4px solid ${k.kategoria_color};padding-left:12px;margin:12px 0">
        <h3>${k.title} <small>${k.kategoria_label}</small></h3>
        <p>${k.excerpt}</p>
      </article>
    `).join('');
  });
</script>
```

## Uwagi

- Odpowiedź jest cache'owana 5 min (`Cache-Control: public, max-age=300`).
- `body_html` zawiera surowy HTML treści — przy osadzaniu na obcej stronie
  rozważ sanityzację lub użycie samego `excerpt`.
- Komunikat staje się publiczny, gdy w panelu ma odbiorcę
  „Strona logowania (publiczne)” i jest aktywny.
