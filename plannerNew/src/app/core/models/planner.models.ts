export type BlockType = 'theory' | 'workshop' | 'lab' | 'code_review' | 'project';
export type SessionMode = 'onsite' | 'remote' | 'hybrid';
export type StaffRole = 'main' | 'mentor' | 'assistant';
export type DraftStatus = 'draft' | 'review' | 'published' | 'archived';
export type TxDirection = 'credit' | 'debit' | 'hold' | 'unhold';

export interface Session {
  id: number;
  course_id: number;
  lesson_date: string;
  time_from: string;
  time_to: string;
  duration_min: number;
  status: string;
  topic: string;
  notes: string;
  room_id: number | null;
  mode: SessionMode;
  block_type: BlockType;
  draft_id: number | null;
  meeting_url: string;
}

export interface Room {
  id: number;
  name: string;
  capacity: number;
  workstations: number;
  has_projector: number;
  has_dual_mon: number;
  laptop_pool_cnt: number;
  mode_support: string;
  location: string;
  notes: string;
  is_active: number;
  // Extended fields used in UI
  type?: string;
  building?: string;
  floor?: string;
  amenities?: string[];
}

export interface TechPath {
  id: number;
  name: string;
  code: string;
  slug?: string;
  description: string;
  color_hex: string;
  color?: string;
  icon: string;
  prereq_path_id: number | null;
  is_active: number;
  default_level?: string;
  duration_hours?: number | null;
}

export interface Draft {
  id: number;
  title: string;
  description?: string;
  status: DraftStatus;
  base_draft_id: number | null;
  created_by: number;
  published_at: string | null;
  created_at: string;
  updated_at?: string;
  sessions?: Session[];
}

export interface DraftDiff {
  added: Session[];
  removed: Session[];
  changed: { old: Session; new: Session }[];
}

export interface SessionStaff {
  id: number;
  session_id: number;
  user_id: number;
  role: StaffRole;
  is_primary: number;
  user_name: string;
  user_email: string;
}

export interface Laptop {
  id: number;
  asset_tag: string;
  model: string;
  condition: string;
  room_id: number | null;
  is_active: number;
  is_loaned: number;
  loaned_to_session: number | null;
  loaned_to_client: number | null;
}

export interface OnlineMeeting {
  id: number;
  session_id: number;
  platform: 'zoom' | 'teams' | 'meet';
  meeting_url: string;
  meeting_id: string;
  password: string;
  host_email: string;
}

export interface CycleTemplate {
  id: number;
  name: string;
  repeat_type: string;
  days_of_week: number[];
  time_from: string;
  time_to: string;
  duration_weeks: number;
  skip_holidays: number;
  block_type: BlockType;
}

export interface TokenWallet {
  id: number;
  client_id: number;
  balance: number;
  balance_hold: number;
  total_granted: number;
  total_spent: number;
  updated_at: string;
  transactions?: TokenTransaction[];
}

export interface TokenTransaction {
  id: number;
  wallet_id: number;
  amount: number;
  direction: TxDirection;
  type?: string;
  reason: string;
  note?: string;
  ref_type: string;
  ref_id: number;
  created_at: string;
}

export interface TokenPrice {
  id: number;
  course_id: number | null;
  mentor_id: number | null;
  path_id: number | null;
  tech_path_id?: number | null;
  level: string | null;
  tokens_required: number;
  price?: number;
  valid_from: string | null;
  valid_to: string | null;
}

export interface Basket {
  id: number;
  client_id: number;
  status: string;
  expires_at: string;
  items: BasketItem[];
  total_tokens: number;
}

export interface BasketItem {
  id: number;
  session_id: number;
  mentor_id: number | null;
  tokens_reserved: number;
  status: string;
  lesson_date: string;
  time_from: string;
  time_to: string;
  course_id: number;
}

export interface Conflict {
  code: string;
  msg: string;
  user_id?: number;
  conflicts?: Session[];
}

export interface ConflictResult {
  hard: Conflict[];
  soft: Conflict[];
}

export interface AuditEntry {
  id: number;
  entity_type: string;
  entity_id: number;
  action: string;
  changed_by: number | null;
  before_json: string;
  after_json: string;
  ip_address: string;
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: { next_cursor: number | null };
}

export interface ApiResponse<T> {
  data: T;
}

export interface CheckoutResult {
  purchased: number;
  tokens_spent: number;
  wallet_balance: number;
  sessions: { session_id: number; mentor_id: number | null; tokens: number; lesson_date: string }[];
}
