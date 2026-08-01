export type CourseStatus = 'active' | 'cancelled' | 'archived';
export type SessionStatus = 'scheduled' | 'completed' | 'cancelled' | 'remote_material';
export type EnrollmentStatus = 'active' | 'ended' | 'resigned';
export type BillingModel = 'hourly' | 'flat' | 'none';

export interface Course {
  id: number;
  name: string;
  description?: string;
  instructor_id?: number;
  instructor_name?: string;
  location?: string;
  status: CourseStatus;
  grades_enabled: boolean;
  track_attendance: boolean;
  is_online: boolean;
  billing_model: BillingModel;
  billing_amount?: number;
  lesson_payout_bb?: number;
  created_at: string;
}

export interface Session {
  id: number;
  course_id: number;
  course_name?: string;
  lesson_date: string;
  time_from: string;
  time_to: string;
  duration_min: number;
  status: SessionStatus;
  topic?: string;
  notes?: string;
  instructor_notes?: string;
  has_homework: boolean;
  meeting_url?: string;
  cancelled_at?: string;
  cancel_reason?: string;
  created_at: string;
}

export interface Enrollment {
  id: number;
  course_id: number;
  course_name?: string;
  client_id: number;
  client_name?: string;
  hourly_rate?: number;
  start_date: string;
  end_date?: string;
  status: EnrollmentStatus;
  notes?: string;
  billing_model?: BillingModel;
  billing_amount?: number;
}

export interface Attendance {
  id: number;
  session_id: number;
  client_id: number;
  client_name?: string;
  attended: boolean;
  notes?: string;
  ind_notes?: string;
  no_show: boolean;
  cancel_pending: boolean;
}

export type GradeValue = '1' | '2' | '3' | '4' | '5' | '6' | '1+' | '2+' | '3+' | '4+' | '5+' | '1-' | '2-' | '3-' | '4-' | '5-' | '6-' | 'zal' | 'nzal' | 'nb';

export interface Grade {
  id: number;
  course_id: number;
  client_id: number;
  client_name?: string;
  session_id?: number;
  category: string;
  value_text: GradeValue;
  value_num?: number;
  weight: number;
  description?: string;
  graded_by?: number;
  graded_at: string;
  attempt_id?: number;
}

export interface SessionDialogData {
  session?: Partial<Session>;
  courseId?: number;
  courses?: Course[];
}
