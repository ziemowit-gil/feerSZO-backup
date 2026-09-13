// Domain models for the kursant (student) panel

/**
 * Rola konta wpiętego w token API: kursant, rodzic/opiekun, osoba upoważniona
 * (wgląd tylko do odczytu) lub impersonacja admina (pełne uprawnienia kursanta).
 * Odpowiednik trzech odrębnych stron logowania klasycznego panelu
 * (login.php / parent_login.php / authp_login.php) + admin/test_login.php.
 */
export type KursantRole = 'student' | 'parent' | 'authp' | 'impersonation';

export interface LoginResponse {
  success: boolean;
  token: string;
  role: KursantRole;
  actor_name: string;
  student: StudentAccount;
  client: ClientInfo;
  must_change_password: boolean;
  error?: string;
}

export interface StudentAccount {
  id: number;
  client_id: number;
  login: string;
  login_alias: string | null;
  is_minor: boolean;
  must_change_password: boolean;
  push_enabled: number;
  notify_email_messages: number;
  notify_sms_messages: number;
  notify_email_dyd: number;
  notify_sms_dyd: number;
  notify_sms_lessons: number;
  ms_upn: string | null;
  ms_user_id: string | null;
  owncloud_login: string | null;
  name: string;
}

export interface ClientInfo {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
}

export interface Course {
  id: number;
  name: string;
  status: 'active' | 'inactive' | 'completed';
  instructor_name: string;
  start_date: string;
  end_date: string | null;
  lesson_count: number;
  completed_count: number;
  progress_pct: number;
  next_lesson_date: string | null;
}

export interface DashboardData {
  student: StudentAccount;
  client: ClientInfo;
  active_courses: Course[];
  inactive_courses: Course[];
  next_lesson: Lesson | null;
  upcoming_lessons: Lesson[];
  streak_days: number;
  notices_unread: number;
  msg_unread: number;
  terms_pending: number;
  hw_pending: number;
  cal_ical: string;
  cal_gcal: string;
  role: KursantRole;
  actor_name: string;
}

export type LessonStatus =
  | 'planned' | 'held' | 'cancelled' | 'excused' | 'absence' | 'remote_material'
  | 'individual_change' | 'reserved' | 'draft';

export interface Lesson {
  id: number;
  course_id: number;
  course_name: string;
  date: string;
  time_from: string;
  time_to: string;
  status: LessonStatus;
  instructor_name: string;
  room_name: string | null;
  notes: string | null;
  rating: number | null;
  cancel_requested: boolean;
  reschedule_proposed: boolean;
  meeting_url: string | null;
}

export interface Homework {
  id: number;
  session_id: number;
  course_id: number;
  course_name: string;
  session_date: string;
  title: string;
  description: string;
  due_date: string | null;
  status: 'pending' | 'submitted' | 'graded';
  submitted_at: string | null;
  submission_body: string | null;
  submission_file_url: string | null;
  grade: string | null;
  feedback: string | null;
}

export interface Material {
  id: number;
  course_id: number;
  course_name: string;
  title: string;
  type: string;
  file_url: string;
  added_at: string;
}

export interface DydGroup {
  session_id: number;
  session_date: string;
  course_id: number;
  course_name: string;
  materials: Material[];
  homeworks: Homework[];
}

export interface Grade {
  id: number;
  course_id: number;
  course_name: string;
  date: string;
  type: string;
  value: string;
  weight: number;
  comment: string | null;
}

export interface GradesByCourse {
  course_id: number;
  course_name: string;
  grades: Grade[];
  average: number | null;
}

export interface CurriculumItem {
  id: number;
  course_id: number;
  course_name: string;
  order_no: number;
  title: string;
  description: string | null;
  is_completed: boolean;
  completed_at: string | null;
}

export interface TestItem {
  id: number;
  course_id: number;
  course_name: string;
  title: string;
  description: string | null;
  available_from: string | null;
  available_to: string | null;
  time_limit_min: number | null;
  max_attempts: number;
  attempts_used: number;
  last_score: number | null;
  max_score: number;
  last_attempt_at: string | null;
  status: 'available' | 'completed' | 'expired' | 'locked';
}

export interface Notice {
  id: number;
  title: string;
  body: string;
  created_at: string;
  is_read: boolean;
  category: string | null;
}

export interface Message {
  id: number;
  sender: 'staff' | 'student';
  sender_name: string;
  subject: string;
  body: string;
  created_at: string;
  is_read: boolean;
}

export interface BillingBalance {
  charges: number;
  payments: number;
  credit: number;
  debt: number;
}

export interface BillingGroupBalance {
  course_id: number;
  course_name: string;
  charges: number;
  paid: number;
  debt: number;
  credit: number;
}

export interface BillingMonthRow {
  course_name: string;
  hours_billed: number;
  adjustment: number;
  adjustment_note: string | null;
  total: number;
  invoice_url: string | null;
  hours_url: string;
}

export interface BillingMonth {
  year: number;
  month: number;
  label: string;
  sum_hours: number;
  sum_due: number;
  status: 'draft' | 'issued' | 'paid';
  due_date: string | null;
  overdue: boolean;
  /** Gdy true — miesiąc ma rozbicie na kilka grup (rows); gdy false — jeden wpis łączny. */
  multi: boolean;
  hours_url: string;
  invoice_url: string | null;
  rows: BillingMonthRow[];
}

export interface BillingData {
  balance: BillingBalance;
  /** Saldo per grupa — tylko gdy kursant ma >1 aktywną grupę. */
  groups: BillingGroupBalance[];
  general_credit: number;
  months: BillingMonth[];
  /** Numer konta do wpłat (NRB/IBAN) — puste, gdy nie ustawiono (indywidualne, kursu, ani organizacji). */
  pay_account: string;
  /** Tytuł przelewu, np. "TI/105/74226 Jan Kowalski". */
  pay_title: string;
  /** Kody modeli rozliczeń aktywnych zapisów (9999 = indywidualny). */
  pay_codes: number[];
  /** 12-cyfrowy numer referencyjny per aktywna grupa: 6 cyfr nr kursanta + 6 cyfr nr kursu — indywidualny numer konta kursanta w tej grupie (część numeru rachunku bankowego); przy wpłacie na ten numer tytuł przelewu nie ma znaczenia. */
  pay_refs: PaymentRef[];
}

export interface PendingWalletPayment {
  amount: number;
  url: string;
  label: string;
  created_at: string;
}

export interface WalletOp {
  date: string;
  kind: 'in' | 'out' | 'declared';
  amount: number;
  label: string;
  note: string;
  status: string;
  covered: number;
}

export interface WalletData {
  balance: { credit: number; debt: number; payments: number };
  gateways: { stripe: boolean; payu: boolean; p24: boolean };
  pending: PendingWalletPayment[];
  ops: WalletOp[];
  pay_account: string;
  pay_title: string;
}

export interface PaymentRef {
  course_id: number;
  course_name: string;
  ref: string;
}

export interface YearEndOverpayCourse {
  course_id: number;
  course_name: string;
  model_label: string;
  amount: number;
}

export interface YearEndOverpayInfo {
  allowed: boolean;
  year?: number;
  deadline?: string;
  courses?: YearEndOverpayCourse[];
  projected_total?: number;
  current_credit?: number;
  suggested_amount?: number;
  gateways?: ('stripe' | 'payu' | 'p24')[];
  transfer_allowed?: boolean;
  transfer_account?: string | null;
  transfer_title?: string | null;
}

export interface License {
  id: number;
  software_name: string;
  license_key: string | null;
  assigned_at: string;
  expires_at: string | null;
  download_url: string | null;
  notes: string | null;
}

export interface ActivityLogEntry {
  id: number;
  action: string;
  description: string;
  ip: string | null;
  created_at: string;
}

export interface Term {
  id: number;
  title: string;
  type: string;
  version: string;
  file_url: string | null;
  body_html: string;
  is_accepted: boolean;
  accepted_at: string | null;
  required: boolean;
}

export interface AuthorizedPerson {
  id: number;
  name: string;
  relation: string;
  phone: string | null;
  email: string | null;
  created_at: string;
  is_active: boolean;
}

export interface VlabServer {
  id: number;
  hostname: string;
  port: number;
  username: string;
  status: 'running' | 'stopped' | 'provisioning';
  expires_at: string | null;
  type: 'shared' | 'dedicated';
  web_terminal_url: string | null;
}

export interface OnlineState {
  ms_provisioned: boolean;
  ms_upn: string | null;
  ms_temp_password: string | null;
  ms_tenant_name: string | null;
  zoom_link: string | null;
  teams_link: string | null;
  active_lesson_url: string | null;
}

export interface OwnCloudState {
  provisioned: boolean;
  login: string | null;
  webdav_url: string | null;
  files_app_url: string | null;
  quota_bytes: number | null;
  used_bytes: number | null;
}

export interface ApiResponse<T = void> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
}

export interface GuardianNotifyPrefs {
  guardian_email: string;
  guardian_phone: string;
  parent_notify_absence: boolean;
  parent_notify_grade: boolean;
  parent_notify_messages: boolean;
  parent_notify_lessons: boolean;
  child_access_blocked: boolean;
}

export interface ParentOtpChild {
  id: number;
  name: string;
}

// ── Panel prowadzącego (dydaktyka TI) ───────────────────────────────────────
// Osobna tożsamość (konto SZO `users`, nie k30_ti_student_accounts) i osobne
// API (api/v1/dydaktyk_instructor.php) — patrz InstructorAuthService /
// InstructorApiService. Zakres zawsze ograniczony do WŁASNYCH kursów
// prowadzącego; funkcje kierownika nie mają tu odpowiednika (zostają w
// klasycznym panelu karty30/ti/dydaktyk/).

export interface Instructor {
  id: number;
  name: string;
  email: string;
}

export interface InstructorTotpRequiredResponse {
  success: boolean;
  data?: { totp_required: true; pending_token: string };
  error?: string;
}

export interface InstructorLoginResponse {
  success: boolean;
  data?: { token: string; instructor: Instructor };
  error?: string;
}

export interface InstructorLesson {
  id: number;
  course_id: number;
  course_name: string;
  lesson_date: string;
  time_from: string;
  time_to: string;
  status: LessonStatus;
  topic: string | null;
  meeting_url: string | null;
  default_meeting_url: string | null;
  enrolled?: number;
}

export interface InstructorAttendanceMonthRow {
  course_id: number;
  course_name: string;
  lessons: number;
  present: number;
  absent: number;
}

export interface InstructorDashboard {
  instructor: Instructor;
  courses_count: number;
  today: InstructorLesson[];
  upcoming: InstructorLesson[];
  pending_cancel: number;
  notices_unread: number;
  msg_unread_total: number;
  attendance_month: InstructorAttendanceMonthRow[];
}

export interface InstructorLessonRow {
  id: number;
  course_id: number;
  course_name: string;
  lesson_date: string;
  time_from: string;
  time_to: string;
  status: LessonStatus;
  topic: string | null;
  meeting_url: string | null;
  default_meeting_url: string | null;
  docs_complete: number;
  rescheduled_from_date: string | null;
  attended_count: number;
  total_count: number;
  is_substitution: boolean;
}

export interface InstructorAttendanceEntry {
  client_id: number;
  client_name: string;
  attended: number;
  cancelled: number;
  no_show: number;
}

export interface InstructorHomework {
  id: number;
  course_id: number;
  course_name: string;
  title: string;
  description: string | null;
  hint: string | null;
  due_at: string | null;
  is_active: number;
  sub_count: number;
  graded_count: number;
  availability: { state: 'open' | 'upcoming' | 'closed'; label: string };
}

export interface InstructorHomeworkSubmission {
  id: number;
  client_id: number;
  client_name: string;
  body: string | null;
  file_name: string | null;
  file_path: string | null;
  status: 'submitted' | 'graded';
  grade: string | null;
  feedback: string | null;
  submitted_at: string | null;
}

export interface InstructorHomeworkDetail {
  homework: InstructorHomework;
  submissions: InstructorHomeworkSubmission[];
}
