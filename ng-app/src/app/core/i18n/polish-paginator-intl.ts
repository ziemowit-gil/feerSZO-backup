import { MatPaginatorIntl } from '@angular/material/paginator';

export function polishPaginatorIntl(): MatPaginatorIntl {
  const intl = new MatPaginatorIntl();
  intl.itemsPerPageLabel = 'Wierszy na stronę:';
  intl.nextPageLabel     = 'Następna strona';
  intl.previousPageLabel = 'Poprzednia strona';
  intl.firstPageLabel    = 'Pierwsza strona';
  intl.lastPageLabel     = 'Ostatnia strona';
  intl.getRangeLabel = (page: number, pageSize: number, length: number) => {
    if (length === 0) return '0 z 0';
    const start = page * pageSize + 1;
    const end   = Math.min((page + 1) * pageSize, length);
    return `${start}–${end} z ${length}`;
  };
  return intl;
}
