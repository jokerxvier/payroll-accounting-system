export type EmploymentClassification =
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type PayFrequency = 'monthly' | 'semi_monthly';

export type EmployeeRow = {
    lms_staff_id: number;
    staff_no: string | null;
    full_name: string | null;
    email: string | null;
    role_id: number | null;
    department_id: number | null;
    designation_id: number | null;
    has_profile: boolean;
    basic_salary_centavos: number;
    employment_classification: EmploymentClassification | null;
    pay_frequency: PayFrequency | null;
    is_active: boolean | null;
};

export type EmployeeProfile = {
    id: number;
    lms_staff_id: number;
    employment_classification: EmploymentClassification;
    pay_frequency: PayFrequency;
    basic_salary_centavos: number;
    tax_status: string | null;
    tin: string | null;
    sss_number: string | null;
    philhealth_number: string | null;
    pagibig_number: string | null;
    bank_name: string | null;
    bank_account_number: string | null;
    bank_account_name: string | null;
    date_hired: string | null;
    date_terminated: string | null;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type EmployeeDetail = {
    lms_staff_id: number;
    staff_no: string | null;
    full_name: string | null;
    email: string | null;
    department: { id: number; name: string } | null;
    designation: { id: number; title: string } | null;
    role: { id: number; name: string } | null;
    profile: EmployeeProfile | null;
};

export type EmployeeListFilters = {
    search?: string;
    status?: 'active' | 'inactive';
    department_id?: number;
    employment_classification?: EmploymentClassification;
    sort_by?: 'staff_no' | 'full_name';
    sort_dir?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
};

export type DepartmentOption = {
    id: number;
    name: string;
};

export type EmploymentTypeOption = {
    value: EmploymentClassification;
    label: string;
};
