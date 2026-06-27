<?php
/**
 * includes/karty30_api.php — Wspólna konfiguracja zasobów REST API Karty 30.
 * Jedno źródło prawdy dla: api/v1/karty30.php (CRUD) oraz api/v1/openapi.php (spec).
 */

/** Definicje zasobów: tabela, whitelista pól, wymagane, sort, filtry, flagi. */
function k30_api_resources(): array {
    return [
        'clients' => [
            'table'    => 'k30_clients',
            'fields'   => ['name','email','phone','status','problem','equipment','date_of_birth',
                           'gender','address','notes','preferred_contact_method','consent','available_hours'],
            'required' => ['name'],
            'order'    => 'name ASC',
            'filters'  => ['status'],
            'search'   => ['name','email','phone'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['status' => 'enrolled', 'preferred_contact_method' => 'email'],
        ],
        'schedules' => [
            'table'    => 'k30_schedules',
            'fields'   => ['client_id','assigned_to','start_time','duration_minutes','status','description',
                           'is_remote','billing_type','pfron_contract_id','resource_id','cancel_reason'],
            'required' => ['client_id','start_time'],
            'order'    => 'start_time DESC',
            'filters'  => ['client_id','status','assigned_to'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['status' => 'preliminary', 'duration_minutes' => 60, 'billing_type' => 'free'],
        ],
        'consultations' => [
            'table'    => 'k30_consultations',
            'fields'   => ['schedule_id','client_id','consultant_id','consultation_datetime','duration_minutes',
                           'description','next_action','status'],
            'required' => ['client_id','consultation_datetime'],
            'order'    => 'consultation_datetime DESC',
            'filters'  => ['client_id','status','consultant_id'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['status' => 'draft'],
        ],
        'waiting' => [
            'table'    => 'k30_waiting_list',
            'fields'   => ['client_id','priority','reason','notes','status'],
            'required' => ['client_id'],
            'order'    => 'created_at DESC',
            'filters'  => ['client_id','status','priority'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['priority' => 'zwykly', 'status' => 'waiting'],
        ],
        'courses' => [
            'table'    => 'k30_ti_courses',
            'fields'   => ['name','description','instructor_id','location','is_active','status',
                           'grades_enabled','billing_model','billing_amount','default_meeting_url'],
            'required' => ['name'],
            'order'    => 'name ASC',
            'filters'  => ['status','instructor_id'],
            'search'   => ['name'],
            'created_at' => true, 'updated_at' => false, 'created_by' => true,
            'defaults' => ['is_active' => 1, 'status' => 'active', 'grades_enabled' => 1],
            'soft_delete' => ['column' => 'status', 'value' => 'cancelled'],
        ],
        'enrollments' => [
            'table'    => 'k30_ti_enrollments',
            'fields'   => ['course_id','client_id','hourly_rate','start_date','end_date','status','notes',
                           'billing_model','billing_amount'],
            'required' => ['course_id','client_id'],
            'order'    => 'id DESC',
            'filters'  => ['course_id','client_id','status'],
            'created_at' => true, 'updated_at' => false, 'created_by' => false,
            'defaults' => ['status' => 'active'],
        ],
        'lessons' => [
            'table'    => 'k30_ti_sessions',
            'fields'   => ['course_id','lesson_date','time_from','time_to','duration_min','status','topic',
                           'notes','instructor_notes','has_homework','self_prep_remote','meeting_url'],
            'required' => ['course_id','lesson_date'],
            'order'    => 'lesson_date DESC, id DESC',
            'filters'  => ['course_id','status'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['status' => 'planned', 'duration_min' => 60],
        ],
        'homework' => [
            'table'    => 'k30_ti_homework',
            'fields'   => ['course_id','session_id','title','description','due_at','open_at','close_at','hint','is_active'],
            'required' => ['course_id','title'],
            'order'    => 'created_at DESC',
            'filters'  => ['course_id','session_id','is_active'],
            'search'   => ['title'],
            'created_at' => true, 'updated_at' => false, 'created_by' => true,
            'defaults' => ['is_active' => 1],
        ],
        'materials' => [
            'table'    => 'k30_ti_materials',
            'fields'   => ['course_id','session_id','type','title','description','url','open_at','close_at','is_active'],
            'required' => ['course_id','title'],
            'order'    => 'created_at DESC',
            'filters'  => ['course_id','session_id','type','is_active'],
            'search'   => ['title'],
            'created_at' => true, 'updated_at' => false, 'created_by' => true,
            'defaults' => ['type' => 'inne', 'is_active' => 1],
        ],
        'grades' => [
            'table'    => 'k30_ti_grades',
            'fields'   => ['course_id','client_id','session_id','category','value_text','value_num','weight','description'],
            'required' => ['course_id','client_id','value_text'],
            'order'    => 'graded_at DESC, id DESC',
            'filters'  => ['course_id','client_id','category'],
            'created_at' => true, 'updated_at' => false, 'created_by' => false,
            'defaults' => ['category' => 'inne', 'weight' => 1],
        ],
        'tests' => [
            'table'    => 'k30_ti_tests',
            'fields'   => ['course_id','title','description','time_limit_min','pass_pct','shuffle','is_active','sync_grade'],
            'required' => ['course_id','title'],
            'order'    => 'created_at DESC',
            'filters'  => ['course_id','is_active'],
            'search'   => ['title'],
            'created_at' => true, 'updated_at' => true, 'created_by' => true,
            'defaults' => ['is_active' => 0],
        ],
    ];
}

/** Mapowanie kluczy obcych → tabela rodzica (walidacja istnienia przy create). */
function k30_api_fk_parents(): array {
    return [
        'client_id'  => 'k30_clients',
        'course_id'  => 'k30_ti_courses',
        'session_id' => 'k30_ti_sessions',
        'schedule_id'=> 'k30_schedules',
        'test_id'    => 'k30_ti_tests',
        'homework_id'=> 'k30_ti_homework',
    ];
}

/** Pola traktowane jako liczby całkowite (normalizacja wejścia). */
function k30_api_int_fields(): array {
    return ['is_active','consent','has_homework','self_prep_remote','grades_enabled','billing_model',
            'duration_minutes','duration_min','time_limit_min','pass_pct','shuffle','sync_grade'];
}

/** Pola data-czas (normalizacja: T→spacja, dopis sekund). */
function k30_api_dt_fields(): array {
    return ['start_time','consultation_datetime','due_at','open_at','close_at'];
}
