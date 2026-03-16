<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/database.php';

if (!in_array($_SESSION['role'], ['employee','manager','department_head','admin'], true)) {
    die("Access denied");
}

$db = (new Database())->connect();
$emp_id = (int)($_SESSION['emp_id'] ?? 0);

if ($emp_id <= 0) {
    die("Employee record not found.");
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeFloat($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
}

function leaveRulePreset(string $typeName): array {
    $key = strtolower(trim($typeName));

    $base = [
        'bucket' => 'annual',
        'bucket_label' => 'Vacational Balance',
        'show_rules' => true,
        'min_days_notice' => 0,
        'max_days' => null,
        'allow_emergency' => false,
        'subtype_label' => '',
        'subtypes' => [],
        'show_location_text' => false,
        'location_label' => 'Specify',
        'show_illness_text' => false,
        'show_other_purpose' => false,
        'show_expected_delivery' => false,
        'show_calamity_location' => false,
        'show_surgery_details' => false,
        'show_monetization_reason' => false,
        'show_terminal_reason' => false,
        'documents' => [],
        'rules_text' => [],
    ];

    switch ($key) {
        case 'vacation leave':
        case 'vacation':
        case 'vacational':
        case 'annual':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'min_days_notice' => 5,
                'subtype_label' => 'Vacation Details',
                'subtypes' => [
                    'within_ph' => 'Within the Philippines',
                    'abroad' => 'Abroad',
                ],
                'show_location_text' => true,
                'location_label' => 'Location / Destination',
                'documents' => [
                    'travel_authority' => 'Travel authority / clearance if applicable',
                ],
                'rules_text' => [
                    'It shall be filed five (5) days in advance, whenever possible, of the effective date of such leave.',
                    'Vacation leave within the Philippines or abroad shall be indicated for travel authority and clearance purposes.',
                ],
            ]);

                case 'mandatory / forced leave':
        case 'mandatory/forced leave':
        case 'mandatory / force leave':
        case 'mandatory/force leave':
        case 'mandatory':
        case 'forced':
        case 'forced leave':
        case 'force':
        case 'force leave':
            return array_merge($base, [
                'bucket' => 'force',
                'bucket_label' => 'Force Balance',
                'min_days_notice' => 0,
                'rules_text' => [
                    'Annual five-day vacation leave shall be forfeited if not taken during the year.',
                    'If the scheduled leave is cancelled due to exigency of service, it shall no longer be deducted.',
                    'Availment of one (1) day or more vacation leave may be considered in complying with mandatory/forced leave, subject to rules.',
                ],
            ]);

        case 'sick leave':
        case 'sick':
            return array_merge($base, [
                'bucket' => 'sick',
                'bucket_label' => 'Sick Balance',
                'allow_emergency' => true,
                'subtype_label' => 'Sick Leave Details',
                'subtypes' => [
                    'in_hospital' => 'In Hospital',
                    'out_patient' => 'Out Patient',
                ],
                'show_illness_text' => true,
                'documents' => [
                    'medical_certificate' => 'Medical Certificate',
                    'affidavit' => 'Affidavit if medical consultation was not availed of',
                ],
                'rules_text' => [
                    'It shall be filed immediately upon employee’s return from such leave.',
                    'If filed in advance or exceeding five (5) days, application shall be accompanied by a medical certificate.',
                    'If medical consultation was not availed of, an affidavit should be executed by the applicant.',
                ],
            ]);

        case 'maternity leave':
        case 'maternity':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 105,
                'show_expected_delivery' => true,
                'documents' => [
                    'proof_of_pregnancy' => 'Proof of pregnancy (ultrasound / doctor’s certificate)',
                    'allocation_form' => 'Notice of Allocation of Maternity Leave Credits (if needed)',
                ],
                'rules_text' => [
                    'Maternity leave is for 105 days.',
                    'Proof of pregnancy such as ultrasound or doctor’s certificate on expected date of delivery is required.',
                    'Accomplished Notice of Allocation of Maternity Leave Credits may be needed.',
                ],
            ]);

        case 'paternity leave':
        case 'paternity':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 7,
                'documents' => [
                    'child_delivery_proof' => 'Proof of child’s delivery (birth certificate / medical certificate)',
                    'marriage_contract' => 'Marriage contract',
                ],
                'rules_text' => [
                    'Paternity leave is for 7 days.',
                    'Proof of child’s delivery and marriage contract are required.',
                ],
            ]);

        case 'special privilege leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 3,
                'min_days_notice' => 7,
                'allow_emergency' => true,
                'subtype_label' => 'Special Privilege Leave Details',
                'subtypes' => [
                    'within_ph' => 'Within the Philippines',
                    'abroad' => 'Abroad',
                ],
                'show_location_text' => true,
                'location_label' => 'Location / Destination',
                'documents' => [
                    'travel_authority' => 'Travel authority / clearance if applicable',
                ],
                'rules_text' => [
                    'It shall be filed/approved for at least one (1) week prior to availment, except in emergency cases.',
                    'Travel details shall be indicated for travel authority and clearance purposes.',
                ],
            ]);

        case 'solo parent leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 7,
                'min_days_notice' => 5,
                'documents' => [
                    'solo_parent_id' => 'Updated Solo Parent Identification Card',
                ],
                'rules_text' => [
                    'It shall be filed in advance or whenever possible five (5) days before going on such leave.',
                    'Updated Solo Parent Identification Card is required.',
                ],
            ]);

        case 'study leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 180,
                'subtype_label' => 'Study Leave Details',
                'subtypes' => [
                    'masters' => 'Completion of Master’s Degree',
                    'bar_review' => 'BAR / Board Examination Review',
                ],
                'show_other_purpose' => true,
                'documents' => [
                    'agency_requirements' => 'Agency internal requirements, if any',
                    'study_contract' => 'Contract between agency head and employee',
                ],
                'rules_text' => [
                    'Study leave is up to 6 months.',
                    'Agency internal requirements, if any, must be met.',
                    'A contract between the agency head or authorized representative and the employee is required.',
                ],
            ]);

        case 'vawc leave':
        case '10-day vawc leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 10,
                'allow_emergency' => true,
                'documents' => [
                    'barangay_protection_order' => 'Barangay Protection Order / certification',
                    'court_protection_order' => 'Temporary/Permanent Protection Order / certification',
                    'police_report' => 'Police report',
                    'medical_certificate' => 'Medical certificate, if applicable',
                ],
                'rules_text' => [
                    'It shall be filed in advance or immediately upon the woman employee’s return from such leave.',
                    'Supporting documents such as BPO, TPO/PPO, certifications, police report, or medical certificate may be required.',
                ],
            ]);

        case 'rehabilitation leave':
        case 'rehabilitation privilege':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 180,
                'documents' => [
                    'letter_request' => 'Letter request',
                    'police_report' => 'Relevant report such as police report, if any',
                    'medical_certificate' => 'Medical certificate on injuries and treatment',
                    'government_physician_concurrence' => 'Written concurrence of a government physician if attending physician is private',
                ],
                'rules_text' => [
                    'Application shall be made within one (1) week from the time of the accident except when a longer period is warranted.',
                    'Relevant reports and medical certificate are required.',
                ],
            ]);

        case 'special leave benefits for women':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 60,
                'min_days_notice' => 5,
                'allow_emergency' => true,
                'show_surgery_details' => true,
                'documents' => [
                    'medical_certificate' => 'Medical certificate from proper medical authorities',
                    'clinical_summary' => 'Clinical summary / histopathological report / operative technique',
                ],
                'rules_text' => [
                    'Application may be filed at least five (5) days prior to scheduled gynecological surgery.',
                    'In emergency cases, application shall be filed immediately upon employee’s return.',
                    'Medical certificate and supporting clinical records are required.',
                ],
            ]);

        case 'special emergency (calamity) leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'max_days' => 5,
                'show_calamity_location' => true,
                'documents' => [
                    'calamity_proof' => 'Proof that residence is in declared calamity area',
                ],
                'rules_text' => [
                    'Can be applied for a maximum of five (5) working days, straight or staggered, within thirty (30) days from actual occurrence.',
                    'This privilege shall be enjoyed once a year only.',
                ],
            ]);

        case 'monetization of leave credits':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'show_monetization_reason' => true,
                'documents' => [
                    'letter_request' => 'Letter request stating valid and justifiable reasons',
                ],
                'rules_text' => [
                    'Application for monetization of fifty percent (50%) or more of accumulated leave credits shall be accompanied by a letter request.',
                ],
            ]);

        case 'terminal leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'show_terminal_reason' => true,
                'documents' => [
                    'separation_proof' => 'Proof of resignation, retirement, or separation from service',
                ],
                'rules_text' => [
                    'Proof of resignation, retirement, or separation from service is required.',
                ],
            ]);

        case 'adoption leave':
            return array_merge($base, [
                'bucket' => 'annual',
                'bucket_label' => 'Vacational Balance',
                'documents' => [
                    'dswd_proof' => 'Authenticated copy of Pre-Adoptive Placement Authority from DSWD',
                ],
                'rules_text' => [
                    'Application shall be filed with an authenticated copy of the Pre-Adoptive Placement Authority issued by DSWD.',
                ],
            ]);

        default:
            return $base;
    }
}

$empStmt = $db->prepare("
    SELECT e.id, e.first_name, e.middle_name, e.last_name, e.department, e.position, e.salary,
           e.annual_balance, e.sick_balance, e.force_balance, u.email
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.id = ?
    LIMIT 1
");
$empStmt->execute([$emp_id]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die("Employee not found.");
}

$typesStmt = $db->query("SELECT * FROM leave_types ORDER BY id ASC");
$leaveTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

$balanceMap = [
    'annual' => safeFloat($employee['annual_balance'] ?? 0),
    'sick'   => safeFloat($employee['sick_balance'] ?? 0),
    'force'  => safeFloat($employee['force_balance'] ?? 0),
    'none'   => 0,
];

$leaveTypeRulesById = [];
foreach ($leaveTypes as $lt) {
    $preset = leaveRulePreset((string)$lt['name']);
    $preset['id'] = (int)$lt['id'];
    $preset['name'] = (string)$lt['name'];
    $preset['current_balance'] = $balanceMap[$preset['bucket']] ?? 0;
    $leaveTypeRulesById[(int)$lt['id']] = $preset;
}

$fullName = trim(
    (string)($employee['first_name'] ?? '') . ' ' .
    (string)($employee['middle_name'] ?? '') . ' ' .
    (string)($employee['last_name'] ?? '')
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Apply Leave</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <script src="../assets/js/script.js"></script>
    <style>
        .leave-application-card {
            max-width: 980px;
            margin: 0 auto;
        }
        .leave-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .readonly-box {
            width: 100%;
            padding: 10px 12px;
            min-height: 44px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #f8fafc;
            color: #111827;
            display: flex;
            align-items: center;
        }
        .section-block {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            background: #fff;
            margin-bottom: 18px;
        }
        .section-block h3 {
            margin-bottom: 14px;
            font-size: 17px;
        }
        .muted-note {
            font-size: 13px;
            color: #6b7280;
        }
        .rule-box {
            display: none;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            border-radius: 14px;
            padding: 16px;
            margin-top: 12px;
        }
        .rule-box.active {
            display: block;
        }
        .rule-box h4 {
            margin-bottom: 8px;
            font-size: 15px;
            color: #1d4ed8;
        }
        .rule-list {
            margin: 0;
            padding-left: 18px;
        }
        .rule-list li {
            margin-bottom: 6px;
            color: #1e3a8a;
            font-size: 14px;
        }
        .dynamic-area {
            display: none;
        }
        .dynamic-area.active {
            display: block;
        }
        .chip-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            font-size: 13px;
            color: #374151;
        }
        .radio-card-group,
        .check-card-group {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 8px;
        }
        .choice-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            cursor: pointer;
        }
        .choice-card input {
            width: auto;
            margin: 2px 0 0 0;
        }
        .choice-card span {
            color: #111827;
            font-size: 14px;
        }
        .doc-checklist {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .doc-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .doc-item input {
            width: auto;
            margin: 2px 0 0 0;
        }
        .doc-item span {
            color: #111827;
            font-size: 14px;
        }
        .warning-box {
            margin-top: 12px;
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #92400e;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 13px;
            display: none;
        }
        .warning-box.active {
            display: block;
        }
        .balance-banner {
            margin-top: 10px;
            display: none;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid var(--border);
            color: #111827;
            font-size: 14px;
        }
        .balance-banner.active {
            display: block;
        }
        .compact-textarea {
            min-height: 110px;
        }
        @media (max-width: 900px) {
            .leave-grid-2,
            .radio-card-group,
            .check-card-group,
            .doc-checklist {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<div class="app-main">
    <?php
    $title = 'Apply Leave';
    include __DIR__ . '/partials/ui/page-header.php';
    ?>
    <div class="ui-card leave-application-card">
        <h2 class="page-subtitle" style="text-align:center;margin-bottom:8px;">Application for Leave</h2>
        <p style="text-align:center;font-size:13px;color:#6b7280;margin-bottom:24px;">
            Fill out the request based on the official leave form. Extra instructions and requirements will appear only for the selected leave type.
        </p>

        <form method="POST" action="../controllers/LeaveController.php" id="applyLeaveForm">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">

            <div class="section-block">
                <h3>1. Employee Information</h3>
                <div class="leave-grid-2">
                    <div>
                        <label>Full Name</label>
                        <div class="readonly-box"><?= e($fullName); ?></div>
                    </div>
                    <div>
                        <label>Date of Filing</label>
                        <input type="date" name="filing_date" value="<?= e(date('Y-m-d')); ?>" required>
                    </div>
                    <div>
                        <label>Office / Department</label>
                        <div class="readonly-box"><?= e($employee['department'] ?? ''); ?></div>
                    </div>
                    <div>
                        <label>Position</label>
                        <div class="readonly-box"><?= e($employee['position'] ?? ''); ?></div>
                    </div>
                    <div>
                        <label>Salary</label>
                        <div class="readonly-box"><?= safeFloat($employee['salary'] ?? 0) > 0 ? e(number_format((float)$employee['salary'], 2)) : '—'; ?></div>
                    </div>
                    <div>
                        <label>Email</label>
                        <div class="readonly-box"><?= e($employee['email'] ?? ''); ?></div>
                    </div>
                </div>
            </div>

            <div class="section-block">
                <h3>2. Leave Request</h3>

                <div class="leave-grid-2">
                    <div>
                        <label for="leave_type">Leave Type</label>
                        <select name="leave_type_id" id="leave_type" required>
                            <option value="">-- Select Leave Type --</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?= (int)$lt['id']; ?>"><?= e($lt['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="commutation">Commutation</label>
                        <select name="commutation" id="commutation">
                            <option value="Not Requested">Not Requested</option>
                            <option value="Requested">Requested</option>
                        </select>
                    </div>

                    <div>
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" required>
                    </div>

                    <div>
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" required>
                    </div>

                    <div>
                        <label for="total_days">Total Days</label>
                        <input type="text" id="total_days" readonly>
                    </div>

                    <div>
                        <label>
                            <input type="checkbox" name="emergency_case" id="emergency_case" value="1" style="width:auto;margin-right:8px;">
                            Emergency case
                        </label>
                        <div class="muted-note">Use only when the selected leave type allows emergency filing.</div>
                    </div>
                </div>

                <div id="balance-banner" class="balance-banner"></div>
                <div id="rule-box" class="rule-box">
                    <h4>Selected Leave Type Rules</h4>
                    <ul id="rule-list" class="rule-list"></ul>
                </div>
                <div id="warning-box" class="warning-box"></div>
            </div>

            <div class="section-block dynamic-area" id="details-section">
                <h3>3. Leave Details</h3>

                <div id="subtype-wrapper" style="display:none;">
                    <label id="subtype-label">Leave Details</label>
                    <div id="subtype-options" class="radio-card-group"></div>
                </div>

                <div id="location-wrapper" style="display:none;">
                    <label id="location-label" for="detail_location">Specify Location / Destination</label>
                    <input type="text" name="details[location]" id="detail_location" placeholder="Enter location / destination">
                </div>

                <div id="illness-wrapper" style="display:none;">
                    <label for="detail_illness">Specify Illness / Condition</label>
                    <textarea name="details[illness]" id="detail_illness" class="compact-textarea" placeholder="Enter illness / medical condition"></textarea>
                </div>

                <div id="other-purpose-wrapper" style="display:none;">
                    <label for="detail_other_purpose">Other Purpose / Remarks</label>
                    <textarea name="details[other_purpose]" id="detail_other_purpose" class="compact-textarea" placeholder="Enter other purpose if needed"></textarea>
                </div>

                <div id="expected-delivery-wrapper" style="display:none;">
                    <label for="detail_expected_delivery">Expected Date of Delivery</label>
                    <input type="date" name="details[expected_delivery]" id="detail_expected_delivery">
                </div>

                <div id="calamity-location-wrapper" style="display:none;">
                    <label for="detail_calamity_location">Calamity / Disaster Location</label>
                    <input type="text" name="details[calamity_location]" id="detail_calamity_location" placeholder="Enter affected location">
                </div>

                <div id="surgery-wrapper" style="display:none;">
                    <label for="detail_surgery">Gynecological Surgery / Procedure Details</label>
                    <textarea name="details[surgery_details]" id="detail_surgery" class="compact-textarea" placeholder="Enter surgery details / clinical summary"></textarea>
                </div>

                <div id="monetization-wrapper" style="display:none;">
                    <label for="detail_monetization_reason">Reason for Monetization</label>
                    <textarea name="details[monetization_reason]" id="detail_monetization_reason" class="compact-textarea" placeholder="State valid and justifiable reasons"></textarea>
                </div>

                <div id="terminal-wrapper" style="display:none;">
                    <label for="detail_terminal_reason">Terminal Leave Basis</label>
                    <textarea name="details[terminal_reason]" id="detail_terminal_reason" class="compact-textarea" placeholder="Indicate resignation / retirement / separation details"></textarea>
                </div>

                <div style="margin-top:16px;">
                    <label for="reason">General Reason / Remarks</label>
                    <textarea name="reason" id="reason" rows="5" required class="compact-textarea" placeholder="Enter the reason for your leave request"></textarea>
                </div>
            </div>

            <div class="section-block dynamic-area" id="documents-section">
                <h3>4. Supporting Documents</h3>

                <div id="documents-list" class="doc-checklist"></div>

                <div class="chip-row">
                    <label class="chip">
                        <input type="checkbox" name="medical_certificate_attached" value="1" style="width:auto;">
                        Medical certificate attached
                    </label>
                    <label class="chip">
                        <input type="checkbox" name="affidavit_attached" value="1" style="width:auto;">
                        Affidavit attached
                    </label>
                </div>

                <div class="muted-note" style="margin-top:12px;">
                    These fields can later be upgraded into actual file uploads. For now, they act as structured indicators for the printed form and approval flow.
                </div>
            </div>

            <div style="text-align:center;margin-top:20px;">
                <button type="submit" style="padding:12px 32px;font-size:16px;">Submit Leave Request</button>
            </div>
        </form>
    </div>
</div>

<script>
const leaveTypeRules = <?= json_encode($leaveTypeRulesById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function calculateDaysAndRefresh() {
    if (typeof calculateDays === 'function') {
        calculateDays();
    }
    setTimeout(updateLeaveTypeUI, 150);
}

function renderSubtypeOptions(rule) {
    const wrapper = document.getElementById('subtype-wrapper');
    const label = document.getElementById('subtype-label');
    const options = document.getElementById('subtype-options');

    options.innerHTML = '';

    if (!rule || !rule.subtypes || Object.keys(rule.subtypes).length === 0) {
        wrapper.style.display = 'none';
        return;
    }

    label.textContent = rule.subtype_label || 'Leave Details';
    Object.entries(rule.subtypes).forEach(([value, text]) => {
        const item = document.createElement('label');
        item.className = 'choice-card';
        item.innerHTML = `
            <input type="radio" name="leave_subtype" value="${value}">
            <span>${text}</span>
        `;
        options.appendChild(item);
    });

    wrapper.style.display = 'block';
}

function renderDocuments(rule) {
    const section = document.getElementById('documents-section');
    const list = document.getElementById('documents-list');
    list.innerHTML = '';

    if (!rule || !rule.documents || Object.keys(rule.documents).length === 0) {
        section.classList.remove('active');
        return;
    }

    Object.entries(rule.documents).forEach(([key, text]) => {
        const item = document.createElement('label');
        item.className = 'doc-item';
        item.innerHTML = `
            <input type="checkbox" name="supporting_documents[]" value="${key}">
            <span>${text}</span>
        `;
        list.appendChild(item);
    });

    section.classList.add('active');
}

function renderRules(rule) {
    const ruleBox = document.getElementById('rule-box');
    const ruleList = document.getElementById('rule-list');

    ruleList.innerHTML = '';

    if (!rule || !rule.show_rules || !rule.rules_text || rule.rules_text.length === 0) {
        ruleBox.classList.remove('active');
        return;
    }

    rule.rules_text.forEach(text => {
        const li = document.createElement('li');
        li.textContent = text;
        ruleList.appendChild(li);
    });

    ruleBox.classList.add('active');
}

function updateWarning(rule) {
    const warningBox = document.getElementById('warning-box');
    const start = document.getElementById('start_date').value;
    const filing = document.querySelector('input[name="filing_date"]').value;
    const totalDays = parseFloat(document.getElementById('total_days').value || '0');

    let warnings = [];

    if (rule) {
        if (rule.min_days_notice && start && filing) {
            const startDate = new Date(start + 'T00:00:00');
            const filingDate = new Date(filing + 'T00:00:00');
            const diffMs = startDate - filingDate;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays < rule.min_days_notice) {
                warnings.push(`This leave is normally filed at least ${rule.min_days_notice} day(s) in advance.`);
            }
        }

        if (rule.max_days && totalDays > rule.max_days) {
            warnings.push(`This leave type normally allows up to ${rule.max_days} day(s).`);
        }

        const emergencyChecked = document.getElementById('emergency_case').checked;
        if (emergencyChecked && !rule.allow_emergency) {
            warnings.push('Emergency filing is not normally allowed for this leave type.');
        }

        if ((rule.name || '').toLowerCase().includes('sick') && totalDays > 5) {
            warnings.push('Sick leave exceeding five (5) days should be accompanied by a medical certificate.');
        }
    }

    if (warnings.length === 0) {
        warningBox.classList.remove('active');
        warningBox.innerHTML = '';
        return;
    }

    warningBox.innerHTML = warnings.join('<br>');
    warningBox.classList.add('active');
}

function updateLeaveTypeUI() {
    const typeElem = document.getElementById('leave_type');
    const selectedId = typeElem.value;
    const rule = leaveTypeRules[selectedId] || null;

    const detailsSection = document.getElementById('details-section');
    const balanceBanner = document.getElementById('balance-banner');

    detailsSection.classList.toggle('active', !!rule);

    if (rule) {
        balanceBanner.innerHTML = `<strong>${rule.name}</strong> deducts from <strong>${rule.bucket_label}</strong> · Current balance: <strong>${Number(rule.current_balance).toFixed(3)}</strong> day(s)`;
        balanceBanner.classList.add('active');
    } else {
        balanceBanner.classList.remove('active');
        balanceBanner.innerHTML = '';
    }

    renderRules(rule);
    renderSubtypeOptions(rule);
    renderDocuments(rule);

    document.getElementById('location-wrapper').style.display = rule && rule.show_location_text ? 'block' : 'none';
    document.getElementById('location-label').textContent = rule && rule.location_label ? rule.location_label : 'Specify';

    document.getElementById('illness-wrapper').style.display = rule && rule.show_illness_text ? 'block' : 'none';
    document.getElementById('other-purpose-wrapper').style.display = rule && rule.show_other_purpose ? 'block' : 'none';
    document.getElementById('expected-delivery-wrapper').style.display = rule && rule.show_expected_delivery ? 'block' : 'none';
    document.getElementById('calamity-location-wrapper').style.display = rule && rule.show_calamity_location ? 'block' : 'none';
    document.getElementById('surgery-wrapper').style.display = rule && rule.show_surgery_details ? 'block' : 'none';
    document.getElementById('monetization-wrapper').style.display = rule && rule.show_monetization_reason ? 'block' : 'none';
    document.getElementById('terminal-wrapper').style.display = rule && rule.show_terminal_reason ? 'block' : 'none';

    updateWarning(rule);
}

window.addEventListener('load', function () {
    const leaveType = document.getElementById('leave_type');
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    const filingDate = document.querySelector('input[name="filing_date"]');
    const emergencyCase = document.getElementById('emergency_case');

    if (leaveType) {
        leaveType.addEventListener('change', function () {
            updateLeaveTypeUI();
            calculateDaysAndRefresh();
        });
    }

    if (startDate) {
        startDate.addEventListener('change', calculateDaysAndRefresh);
    }

    if (endDate) {
        endDate.addEventListener('change', calculateDaysAndRefresh);
    }

    if (filingDate) {
        filingDate.addEventListener('change', updateLeaveTypeUI);
    }

    if (emergencyCase) {
        emergencyCase.addEventListener('change', updateLeaveTypeUI);
    }

    updateLeaveTypeUI();
});
</script>

</body>
</html>
