export interface NavItem {
  label: string;
  icon: string;
  route?: string;
  children?: NavItem[];
}

export const NAV_TREE: NavItem[] = [
  { label: 'Start',        icon: 'dashboard',     route: '/start' },
  {
    label: 'Dydaktyka', icon: 'school',
    children: [
      { label: 'Lekcje',          icon: 'event_note',     route: '/dydaktyka/lekcje' },
      { label: 'Oceny',           icon: 'grade',          route: '/dydaktyka/oceny' },
      { label: 'Obecność',        icon: 'fact_check',     route: '/dydaktyka/obecnosc' },
      { label: 'Plan nauczania',  icon: 'list_alt',       route: '/dydaktyka/plan' },
      { label: 'Zadania',         icon: 'assignment',     route: '/dydaktyka/zadania' },
      { label: 'Testy',           icon: 'quiz',           route: '/dydaktyka/testy' },
      { label: 'Materiały',       icon: 'folder_open',    route: '/dydaktyka/materialy' },
      { label: 'Wiadomości',      icon: 'chat',           route: '/dydaktyka/wiadomosci' },
      { label: 'Wypłaty',         icon: 'payments',       route: '/dydaktyka/wyplaty' },
      { label: 'Urlopy',          icon: 'beach_access',   route: '/dydaktyka/urlopy' },
      { label: 'Okresy',          icon: 'date_range',     route: '/dydaktyka/okresy' },
    ]
  },
  {
    label: 'Umowy', icon: 'description',
    children: [
      { label: 'Zlecenie',    icon: 'work',           route: '/umowy/zlecenie' },
      { label: 'Dzieło',      icon: 'palette',        route: '/umowy/dzielo' },
      { label: 'Wolontariat', icon: 'volunteer_activism', route: '/umowy/wolontariat' },
    ]
  },
  {
    label: 'CRM', icon: 'people',
    children: [
      { label: 'Kontakty',    icon: 'contacts',       route: '/crm/kontakty' },
      { label: 'Sprawy',      icon: 'folder_shared',  route: '/crm/sprawy' },
    ]
  },
  { label: 'EZD',        icon: 'inbox',         route: '/ezd' },
  { label: 'Helpdesk',   icon: 'support_agent', route: '/helpdesk' },
  { label: 'Zadania',    icon: 'task_alt',      route: '/zadania' },
];
