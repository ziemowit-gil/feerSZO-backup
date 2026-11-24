// Kształty odpowiedzi API CRM (modules/crmApi/logic/CrmApiPresenter.php).
export interface Option { value: string, label: string, color?: string | null }
export interface Paged<T> { data: T[], meta: { total: number, page: number, per_page: number, pages: number } }

export interface Me {
  user: { id: number, name: string, email: string, role: string, is_admin: boolean, restricted: boolean }
  perms: Record<string, { read: boolean, write: boolean, delete: boolean }>
  fields: Record<string, { view: boolean, edit: boolean }>
  can_mailing: boolean
  badges: { inbox_unread: number, queue_due: number, cases_stale: number }
  token_expires_at: string | null
  legacy_url: string
  szo_url: string
  org_name: string
}

export interface Dictionaries {
  statuses: Option[]
  types: (Option & { short: string, org_like: boolean })[]
  categories: Option[]
  groups: { id: number, name: string, color: string | null, member_count: number }[]
  owners: { id: number, name: string }[]
  tags: { tag: string, count: number }[]
  wojewodztwa: string[]
  case_statuses: (Option & { closed: boolean, active: boolean })[]
  case_types: { id: number, name: string, color: string | null }[]
  case_priorities: Option[]
  case_close_reasons: Option[]
  delete_reasons: Option[]
}

export interface ContactRow {
  id: number
  type: string
  status: string | null
  imie_nazwisko: string | null
  email: string | null
  telefon: string | null
  organizacja: string | null
  stanowisko: string | null
  addr_city: string | null
  wojewodztwo: string | null
  owner_id: number | null
  avatar_initials: string | null
  is_critical: boolean
  tags: string[]
  last_comm_at: string | null
  updated_at: string | null
  primary_category: { slug: string, label: string, color: string | null } | null
}

export interface Contact {
  id: number
  [field: string]: unknown
  type: string
  status: string | null
  imie_nazwisko: string | null
  owner_id: number | null
  owner_name: string | null
  is_critical: boolean
  email_opt_out: boolean
  tags: string[]
  groups: { id: number, name: string, color: string | null }[]
  persons: { id: number, name: string, role: string | null, email: string | null, telefon: string | null }[]
}

export interface ContactDetail {
  data: Contact
  facts: { open_cases: number, offers: { open: number, won: number, won_value: number } | null, last_contact_at: string | null, last_contact_in: boolean, notes: number }
  perms: { edit: boolean, delete: boolean, field_view: Record<string, boolean>, field_edit: Record<string, boolean> }
  legacy_url: string
}

export interface Note { id: number, body: string, is_pinned: boolean, author: string | null, created_by: number | null, created_at: string | null }
export interface Communication { id: number, channel: string | null, direction: 'in' | 'out', subject: string | null, excerpt: string, status: string | null, sent_at: string | null, sender_name: string | null, from_name: string | null, from_email: string | null, contact_id?: number, contact_name?: string }
export interface Activity { id: number, type: string, title: string, description: string | null, scheduled_at: string | null, status: string, outcome: string | null, assigned_name: string | null, contact_id: number | null, contact_name: string | null }
export interface Task { id: number, title: string, due_date: string | null, priority: string | null, status: string, owner_name: string | null, case_id: number | null, case_title: string | null }

export interface CaseRow {
  id: number
  case_number: string | null
  title: string
  status: string
  status_label: string
  status_color: string | null
  is_closed: boolean
  priority: string
  contact_id: number | null
  contact_name: string | null
  owner_id: number | null
  owner_name: string | null
  type_id: number | null
  due_date: string | null
  is_overdue: boolean
  notes_count: number | null
  created_at: string | null
  updated_at: string | null
  closed_at: string | null
}

export interface SlaPart { deadline: string | null, state: 'ok' | 'soon' | 'breach' | 'met' | null, left_h: number | null }
export interface CaseDetail {
  data: CaseRow & {
    description: string | null
    closed_reason: string | null
    closed_reason_label: string | null
    type_name: string | null
    creator_name: string | null
    sla: { response: SlaPart | null, close: SlaPart | null } | null
    notes: { id: number, body: string, created_at: string | null, author: string | null }[]
    history: { from: string | null, to: string | null, from_label: string | null, to_label: string, reason: string | null, user: string | null, at: string | null }[]
    parties: { contact_id: number, name: string | null, role: string | null, is_main: boolean }[]
  }
  perms: { edit: boolean }
  legacy_url: string
}

export interface Dashboard {
  tiles: { acts_late: number, acts_today: number, inbox_unread: number | null, inbox_mine: number | null, cases_open: number | null, cases_high: number | null, cases_stale: number | null }
  stats: { total: number, persons: number, orgs: number, new_this_month: number, comms_today: number, comms_week: number }
  offers: { pipeline: number, won_value: number, win_rate: number | null } | null
  my_activities: Activity[]
  open_cases: CaseRow[]
  recent_contacts: ContactRow[]
  recent_communications: Communication[]
  status_bars: (Option & { count: number })[]
}

// ── Skrzynka (modules/crmApi/logic/CrmApiInboxController.php) ─────────────────
export interface InboxMeta {
  folders: { id: number, name: string, color: string | null, shared: boolean, count: number }[]
  is_admin: boolean
  autocreate: boolean
  away: { label: string } | null
  ezd_available: boolean
  nozbe_available: boolean
  assist_available: boolean
  views: { value: string, label: string, count: number }[]
  mailboxes: { id: number, name: string, color: string, count: number }[]
  queues: { id: number, name: string, color: string | null, count: number }[]
  tags: string[]
  accounts: { key: string, label: string, ready: boolean }[]
  send_as_default: string
  templates: { id: number, name: string, subject: string, body: string }[]
  can_write: boolean
  threads_default: boolean
  me: number
}

export interface InboxRow {
  id: number
  msg_no: string | null
  direction: 'in' | 'out'
  subject: string | null
  snippet: string
  from_name: string | null
  from_email: string | null
  sent_at: string | null
  is_read: boolean
  status: string | null
  hidden: boolean
  has_attachments: boolean
  contact_id: number | null
  contact_name: string | null
  assigned_to: number | null
  assigned_name: string | null
  mailbox_id: number | null
  mailbox_name: string | null
  queue_name: string | null
  queue_color: string | null
  thread_count: number
  tags: string[]
  followed?: boolean
  trust?: string | null
}

export interface InboxMessage extends Omit<InboxRow, 'trust'> {
  followers: { id: number, name: string }[]
  following: boolean
  conversation: { id: number, direction: 'in' | 'out', from: string | null, sent_at: string | null, subject: string | null, frame: string }[] | null
  ezd_sprawa_id: number | null
  trust: { label: string, title: string } | null
  to: string | null
  frame: string
  has_remote_images: boolean
  is_preview: boolean
  case_id: number | null
  queue: { id: number, name: string } | null
  attachments: { id: number, name: string, mime: string, size: number }[]
  contact: { id: number, name: string | null, email: string | null, messages: number, cases: number, owner_name: string | null, last_at: string | null } | null
  candidates: { id: number, name: string | null, email: string | null, organizacja: string | null }[]
  contact_cases: { id: number, title: string, case_number: string | null, is_closed: boolean }[]
  thread: { id: number, direction: 'in' | 'out', subject: string | null, sent_at: string | null, is_read: boolean }[]
}

export interface InboxAssist { intent: string, intent_label: string, summary: string, escalate: boolean, escalate_reason: string, missing: string[], sensitive: boolean, reply: string }
