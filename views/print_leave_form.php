<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['personnel', 'hr', 'admin'], true)) {
    die("Access denied");
}

$db = (new Database())->connect();
$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    die("Invalid request ID");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function arr(array $row, string $key, $default = null)
{
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function safeFloat($value): float
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function fmtDisplayDate(?string $date): string
{
    if (!$date) return '';
    $date = trim((string)$date);
    if ($date === '') return '';

    $ts = strtotime($date);
    if ($ts === false) return e($date);

    return date('F j, Y', $ts);
}

function checkbox(bool $checked): string
{
    return $checked ? '☑' : '☐';
}

function firstExistingPath(array $paths): ?string
{
    foreach ($paths as $p) {
        $abs = realpath(__DIR__ . '/' . $p);
        if ($abs && file_exists($abs)) {
            return $p;
        }
    }
    return null;
}

try {
    $stmt = $db->prepare("
        SELECT 
            lr.*,
            e.*,
            u.email,
            COALESCE(lt.name, lr.leave_type) AS leave_type_name,
            lt.law_title,
            lt.law_text,
            lf.office_department,
            lf.employee_last_name,
            lf.employee_first_name,
            lf.employee_middle_name,
            lf.date_of_filing,
            lf.position_title,
            lf.salary AS form_salary,
            lf.details_of_leave_json,
            lf.commutation_requested,
            lf.certification_as_of,
            lf.cert_vacation_total_earned,
            lf.cert_vacation_less_this_application,
            lf.cert_vacation_balance,
            lf.cert_sick_total_earned,
            lf.cert_sick_less_this_application,
            lf.cert_sick_balance,
            lf.recommendation_status,
            lf.recommendation_reason,
            lf.approved_for_days_with_pay,
            lf.approved_for_days_without_pay,
            lf.approved_for_others,
            lf.personnel_signatory_name_a,
            lf.personnel_signatory_position_a,
            lf.personnel_signatory_name_c,
            lf.personnel_signatory_position_c
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        LEFT JOIN leave_request_forms lf ON lf.leave_request_id = lr.id
        WHERE lr.id = ?
          AND (lr.workflow_status = 'finalized' OR lr.status = 'approved')
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $t) {
    $stmt = $db->prepare("
        SELECT 
            lr.*,
            e.*,
            u.email,
            COALESCE(lt.name, lr.leave_type) AS leave_type_name,
            lt.law_title,
            lt.law_text
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.id = ?
          AND (lr.workflow_status = 'finalized' OR lr.status = 'approved')
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$request) {
    die("Request not found or not finalized");
}

$selectedLeaveType = strtolower(trim((string)arr($request, 'leave_type_name', '')));
$deduct = safeFloat(arr($request, 'total_days', 0));

$vacTotalEarned = (arr($request, 'cert_vacation_total_earned') !== null && arr($request, 'cert_vacation_total_earned') !== '')
    ? safeFloat(arr($request, 'cert_vacation_total_earned'))
    : safeFloat(arr($request, 'snapshot_annual_balance', 0));

$sickTotalEarned = (arr($request, 'cert_sick_total_earned') !== null && arr($request, 'cert_sick_total_earned') !== '')
    ? safeFloat(arr($request, 'cert_sick_total_earned'))
    : safeFloat(arr($request, 'snapshot_sick_balance', 0));

$isVacationBucket = in_array($selectedLeaveType, [
    'annual', 'vacation', 'vacational', 'vacation leave',
    'force', 'mandatory/forced leave', 'mandatory', 'forced'
], true);

$isSickBucket = in_array($selectedLeaveType, ['sick', 'sick leave'], true);

$vacLess = (arr($request, 'cert_vacation_less_this_application') !== null && arr($request, 'cert_vacation_less_this_application') !== '')
    ? safeFloat(arr($request, 'cert_vacation_less_this_application'))
    : ($isVacationBucket ? $deduct : 0.0);

$sickLess = (arr($request, 'cert_sick_less_this_application') !== null && arr($request, 'cert_sick_less_this_application') !== '')
    ? safeFloat(arr($request, 'cert_sick_less_this_application'))
    : ($isSickBucket ? $deduct : 0.0);

$vacBalance = (arr($request, 'cert_vacation_balance') !== null && arr($request, 'cert_vacation_balance') !== '')
    ? safeFloat(arr($request, 'cert_vacation_balance'))
    : max(0, $vacTotalEarned - $vacLess);

$sickBalance = (arr($request, 'cert_sick_balance') !== null && arr($request, 'cert_sick_balance') !== '')
    ? safeFloat(arr($request, 'cert_sick_balance'))
    : max(0, $sickTotalEarned - $sickLess);

$availableForPay = $isSickBucket ? $sickTotalEarned : $vacTotalEarned;

$daysWithPay = (arr($request, 'approved_for_days_with_pay') !== null && arr($request, 'approved_for_days_with_pay') !== '')
    ? safeFloat(arr($request, 'approved_for_days_with_pay'))
    : min($deduct, $availableForPay);

$daysWithoutPay = (arr($request, 'approved_for_days_without_pay') !== null && arr($request, 'approved_for_days_without_pay') !== '')
    ? safeFloat(arr($request, 'approved_for_days_without_pay'))
    : max(0, $deduct - $daysWithPay);

$approvedOthers = trim((string)arr($request, 'approved_for_others', ''));

$department = trim((string)(arr($request, 'office_department') ?: arr($request, 'department', '')));
$lastName = trim((string)(arr($request, 'employee_last_name') ?: arr($request, 'last_name', '')));
$firstName = trim((string)(arr($request, 'employee_first_name') ?: arr($request, 'first_name', '')));
$middleName = trim((string)(arr($request, 'employee_middle_name') ?: arr($request, 'middle_name', '')));
$dateOfFiling = arr($request, 'date_of_filing') ?: arr($request, 'created_at', date('Y-m-d'));
$position = trim((string)(arr($request, 'position_title') ?: arr($request, 'position', '')));
$salary = (arr($request, 'form_salary') !== null && arr($request, 'form_salary') !== '')
    ? safeFloat(arr($request, 'form_salary'))
    : safeFloat(arr($request, 'salary', 0));

$recommendationStatus = strtolower(trim((string)arr($request, 'recommendation_status', '')));
$recommendationReason = trim((string)(
    arr($request, 'recommendation_reason')
    ?: arr($request, 'personnel_comments')
    ?: arr($request, 'department_head_comments')
    ?: arr($request, 'manager_comments', '')
));

$isDisapproved = strtolower((string)arr($request, 'status', '')) === 'rejected' || $recommendationStatus === 'for_disapproval';

$commutationRequested = strtolower(trim((string)(
    arr($request, 'commutation_requested')
    ?: arr($request, 'commutation', '')
)));
$commNotRequested = in_array($commutationRequested, ['', 'not_requested', 'not requested', 'no'], true);
$commRequested = in_array($commutationRequested, ['requested', 'yes'], true);

$signatoryAName = trim((string)arr($request, 'personnel_signatory_name_a', ''));
$signatoryAPosition = trim((string)arr($request, 'personnel_signatory_position_a', ''));
$signatoryCName = trim((string)arr($request, 'personnel_signatory_name_c', ''));
$signatoryCPosition = trim((string)arr($request, 'personnel_signatory_position_c', ''));

if ($signatoryAName === '') $signatoryAName = 'ANN GERALYN T. PELIAS';
if ($signatoryAPosition === '') $signatoryAPosition = 'Chief Administrative Officer';
if ($signatoryCName === '') $signatoryCName = 'ATTY. ALBERTO T. ESCOBARTE';
if ($signatoryCPosition === '') $signatoryCPosition = 'Assistant Regional Director';

$lawTitle = trim((string)arr($request, 'law_title', ''));
$lawText = trim((string)arr($request, 'law_text', ''));

$leaveTypeChecks = [
    'vacation leave' => false,
    'mandatory/forced leave' => false,
    'sick leave' => false,
    'maternity leave' => false,
    'paternity leave' => false,
    'special privilege leave' => false,
    'solo parent leave' => false,
    'study leave' => false,
    '10-day vawc leave' => false,
    'rehabilitation privilege' => false,
    'special leave benefits for women' => false,
    'special emergency (calamity) leave' => false,
    'adoption leave' => false,
    'others' => false,
];

switch ($selectedLeaveType) {
    case 'vacation':
    case 'vacational':
    case 'annual':
    case 'vacation leave':
        $leaveTypeChecks['vacation leave'] = true;
        break;
    case 'force':
    case 'mandatory':
    case 'forced':
    case 'mandatory/forced leave':
        $leaveTypeChecks['mandatory/forced leave'] = true;
        break;
    case 'sick':
    case 'sick leave':
        $leaveTypeChecks['sick leave'] = true;
        break;
    case 'maternity':
    case 'maternity leave':
        $leaveTypeChecks['maternity leave'] = true;
        break;
    case 'paternity':
    case 'paternity leave':
        $leaveTypeChecks['paternity leave'] = true;
        break;
    case 'special privilege leave':
        $leaveTypeChecks['special privilege leave'] = true;
        break;
    case 'solo parent leave':
        $leaveTypeChecks['solo parent leave'] = true;
        break;
    case 'study leave':
        $leaveTypeChecks['study leave'] = true;
        break;
    case '10-day vawc leave':
        $leaveTypeChecks['10-day vawc leave'] = true;
        break;
    case 'rehabilitation privilege':
        $leaveTypeChecks['rehabilitation privilege'] = true;
        break;
    case 'special leave benefits for women':
        $leaveTypeChecks['special leave benefits for women'] = true;
        break;
    case 'special emergency (calamity) leave':
        $leaveTypeChecks['special emergency (calamity) leave'] = true;
        break;
    case 'adoption leave':
        $leaveTypeChecks['adoption leave'] = true;
        break;
    default:
        $leaveTypeChecks['others'] = true;
        break;
}

$detailChecks = [
    'within_ph' => false,
    'abroad' => false,
    'in_hospital' => false,
    'out_patient' => false,
    'women_special' => false,
    'masters' => false,
    'bar_review' => false,
    'monetization' => false,
    'terminal' => false,
];

if ($leaveTypeChecks['vacation leave'] || $leaveTypeChecks['special privilege leave']) {
    $detailChecks['within_ph'] = true;
}
if ($leaveTypeChecks['sick leave']) {
    $detailChecks['in_hospital'] = true;
}
if ($leaveTypeChecks['special leave benefits for women']) {
    $detailChecks['women_special'] = true;
}
if ($leaveTypeChecks['study leave']) {
    $detailChecks['masters'] = true;
}

$otherLeaveLabel = (!$leaveTypeChecks['others']) ? '' : (arr($request, 'leave_type_name', ''));
$otherDetailText = trim((string)arr($request, 'reason', ''));

// optional logos
$depedLogo = firstExistingPath([
    '/../pictures/DEPED.jpg',
    '/../pictures/deped.jpg',
    '/../assets/img/deped.png',
    '/../assets/img/deped.jpg'
]);

$regionLogo = firstExistingPath([
    '/../pictures/region4a.png',
    '/../pictures/region.png',
    '/../assets/img/region4a.png',
    '/../assets/img/region.png'
]);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Application for Leave - <?= e(trim($firstName . ' ' . $lastName)); ?></title>
    <link rel="stylesheet" href="../assets/css/print_leave_form.css">
</head>
<body>
<div class="page">

    <table class="top-meta">
        <tr>
            <td class="meta-left">
                <div><strong><em>Civil Service Form No. 6</em></strong></div>
                <div><strong><em>Revised 2020</em></strong></div>
            </td>
            <td class="meta-right"><strong>ANNEX A</strong></td>
        </tr>
    </table>

    <table class="header-block">
        <tr>
            <td class="logo-cell">
                <?php if ($depedLogo): ?>
                    <img src="<?= e($depedLogo); ?>" alt="DepEd Seal" class="seal-img">
                <?php else: ?>
                    <div class="seal"></div>
                <?php endif; ?>
            </td>
            <td class="logo-cell">
                <?php if ($regionLogo): ?>
                    <img src="<?= e($regionLogo); ?>" alt="Region Seal" class="seal-img">
                <?php else: ?>
                    <div class="seal"></div>
                <?php endif; ?>
            </td>
            <td class="header-center">
                <div class="gov-line">Republic of the Philippines</div>
                <div class="gov-line">Department of Education</div>
                <div class="gov-region">Region IV-A CALABARZON</div>
                <div class="gov-sub">Gate 2 Karangalan Village, Cainta, Rizal</div>
            </td>
        </tr>
    </table>

    <div class="main-title">APPLICATION FOR LEAVE</div>

    <table class="leave-form">
        <colgroup>
            <col style="width:12%">
            <col style="width:13%">
            <col style="width:13%">
            <col style="width:15%">
            <col style="width:10%">
            <col style="width:14%">
            <col style="width:11%">
            <col style="width:12%">
        </colgroup>

        <tr>
            <td colspan="3" class="cell-label head-cell">1.&nbsp; OFFICE/DEPARTMENT</td>
            <td colspan="5" class="cell-label head-cell">
                2.&nbsp; NAME:
                <span class="name-guide guide-last">(Last)</span>
                <span class="name-guide guide-first">(First)</span>
                <span class="name-guide guide-middle">(Middle)</span>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="cell-value value-row office-value"><?= e($department); ?></td>
            <td colspan="2" class="cell-value value-row"><?= e($lastName); ?></td>
            <td colspan="2" class="cell-value value-row"><?= e($firstName); ?></td>
            <td colspan="1" class="cell-value value-row"><?= e($middleName); ?></td>
        </tr>
        <tr>
            <td colspan="2" class="cell-label head-cell">3.&nbsp; DATE OF FILING</td>
            <td colspan="2" class="cell-line centered strong"><?= e(fmtDisplayDate($dateOfFiling)); ?></td>
            <td colspan="2" class="cell-label head-cell">4.&nbsp; POSITION</td>
            <td colspan="2" class="cell-line centered"><?= e($position); ?></td>
        </tr>
        <tr>
            <td colspan="6" class="blank-top"></td>
            <td class="cell-label head-cell">5.&nbsp; SALARY</td>
            <td class="cell-line centered"><?= $salary > 0 ? number_format($salary, 2) : ''; ?></td>
        </tr>

        <tr>
            <td colspan="8" class="section-title section-row">6.&nbsp; DETAILS OF APPLICATION</td>
        </tr>

        <tr>
            <td colspan="4" class="subsection-header head-cell">6.A TYPE OF LEAVE TO BE AVAILED OF</td>
            <td colspan="4" class="subsection-header head-cell">6.B DETAILS OF LEAVE</td>
        </tr>

        <tr>
            <td colspan="4" class="top-align list-cell">
                <table class="inner-list">
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['vacation leave']); ?></td><td><strong>Vacation Leave</strong> <span class="small-note">(Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['mandatory/forced leave']); ?></td><td><strong>Mandatory/Forced Leave</strong> <span class="small-note">(Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['sick leave']); ?></td><td><strong>Sick Leave</strong> <span class="small-note">(Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['maternity leave']); ?></td><td><strong>Maternity Leave</strong> <span class="small-note">(R.A. No. 11210 / IRR issued by CSC, DOLE and SSS)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['paternity leave']); ?></td><td><strong>Paternity Leave</strong> <span class="small-note">(R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['special privilege leave']); ?></td><td><strong>Special Privilege Leave</strong> <span class="small-note">(Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['solo parent leave']); ?></td><td><strong>Solo Parent Leave</strong> <span class="small-note">(RA No. 8972 / CSC MC No. 8, s. 2004)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['study leave']); ?></td><td><strong>Study Leave</strong> <span class="small-note">(Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['10-day vawc leave']); ?></td><td><strong>10-Day VAWC Leave</strong> <span class="small-note">(RA No. 9262 / CSC MC No. 15, s. 2005)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['rehabilitation privilege']); ?></td><td><strong>Rehabilitation Privilege</strong> <span class="small-note">(Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['special leave benefits for women']); ?></td><td><strong>Special Leave Benefits for Women</strong> <span class="small-note">(RA No. 9710 / CSC MC No. 25, s. 2010)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['special emergency (calamity) leave']); ?></td><td><strong>Special Emergency (Calamity) Leave</strong> <span class="small-note">(CSC MC No. 2, s. 2012, as amended)</span></td></tr>
                    <tr><td class="box"><?= checkbox($leaveTypeChecks['adoption leave']); ?></td><td><strong>Adoption Leave</strong> <span class="small-note">(R.A. No. 8552)</span></td></tr>
                    <tr>
                        <td class="box"><?= checkbox($leaveTypeChecks['others']); ?></td>
                        <td><em>Others:</em> <span class="line-fill"><?= e($otherLeaveLabel); ?></span></td>
                    </tr>
                </table>
            </td>

            <td colspan="4" class="top-align list-cell">
                <table class="inner-list details-list">
                    <tr><td colspan="2" class="italic-head">In case of Vacation/Special Privilege Leave:</td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['within_ph']); ?></td><td>Within the Philippines <span class="inline-line"></span></td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['abroad']); ?></td><td>Abroad (Specify) <span class="inline-line"></span></td></tr>

                    <tr><td colspan="2" class="italic-head">In case of Sick Leave:</td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['in_hospital']); ?></td><td>In Hospital (Specify Illness) <span class="inline-line"></span></td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['out_patient']); ?></td><td>Out Patient (Specify Illness) <span class="inline-line"></span></td></tr>

                    <tr><td colspan="2" class="italic-head">In case of Special Leave Benefits for Women:</td></tr>
                    <tr><td class="box"></td><td>(Specify Illness) <span class="inline-line"><?= e($detailChecks['women_special'] ? $otherDetailText : ''); ?></span></td></tr>

                    <tr><td colspan="2" class="italic-head">In case of Study Leave:</td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['masters']); ?></td><td>Completion of Master's Degree</td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['bar_review']); ?></td><td>BAR/Board Examination Review <em>Other purpose:</em> <span class="inline-line"><?= e($otherDetailText); ?></span></td></tr>

                    <tr><td class="box"><?= checkbox($detailChecks['monetization']); ?></td><td>Monetization of Leave Credits</td></tr>
                    <tr><td class="box"><?= checkbox($detailChecks['terminal']); ?></td><td>Terminal Leave</td></tr>
                </table>
            </td>
        </tr>

        <tr>
            <td colspan="4" class="subsection-header head-cell">6.C NUMBER OF WORKING DAYS APPLIED FOR</td>
            <td colspan="4" class="subsection-header head-cell">6.D COMMUTATION</td>
        </tr>

        <tr>
            <td colspan="4" class="days-block top-align">
                <div class="days-value"><?= number_format($deduct, 3); ?></div>
                <div class="line-wide"></div>
                <div class="inclusive-label">INCLUSIVE DATES</div>
                <div class="line-wide centered-date">
                    <?= e(fmtDisplayDate(arr($request, 'start_date', ''))); ?>
                    <?= (!empty(arr($request, 'start_date')) || !empty(arr($request, 'end_date'))) ? ' to ' : '' ?>
                    <?= e(fmtDisplayDate(arr($request, 'end_date', ''))); ?>
                </div>
            </td>
            <td colspan="4" class="commutation-block top-align">
                <div class="comm-row"><?= checkbox($commNotRequested); ?> <span>Not Requested</span></div>
                <div class="comm-row"><?= checkbox($commRequested); ?> <span>Requested</span></div>
                <div class="applicant-signature">(Signature of Applicant)</div>
            </td>
        </tr>

        <tr>
            <td colspan="8" class="section-title section-row">7.&nbsp; DETAILS OF ACTION ON APPLICATION</td>
        </tr>

        <tr>
            <td colspan="4" class="subsection-header head-cell">7.A CERTIFICATION OF LEAVE CREDITS</td>
            <td colspan="4" class="subsection-header head-cell">7.B RECOMMENDATION</td>
        </tr>

        <tr>
            <td colspan="4" class="top-align cert-cell">
                <div class="as-of-wrap">As of <span class="as-of-line"><?= e(fmtDisplayDate(arr($request, 'certification_as_of', date('Y-m-d')))); ?></span></div>

                <table class="credits-table">
                    <tr>
                        <th></th>
                        <th>Vacation Leave</th>
                        <th>Sick Leave</th>
                    </tr>
                    <tr>
                        <td><em>Total Earned</em></td>
                        <td><?= number_format($vacTotalEarned, 3); ?></td>
                        <td><?= number_format($sickTotalEarned, 3); ?></td>
                    </tr>
                    <tr>
                        <td><em>Less this application</em></td>
                        <td><?= number_format($vacLess, 3); ?></td>
                        <td><?= number_format($sickLess, 3); ?></td>
                    </tr>
                    <tr>
                        <td><em>Balance</em></td>
                        <td><?= number_format($vacBalance, 3); ?></td>
                        <td><?= number_format($sickBalance, 3); ?></td>
                    </tr>
                </table>

                <div class="sig-area cert-sign">
                    <div class="sig-name"><?= e($signatoryAName); ?></div>
                    <div class="sig-line"></div>
                    <div class="sig-pos"><?= e($signatoryAPosition); ?></div>
                </div>
            </td>

            <td colspan="4" class="top-align recommendation-cell">
                <div class="rec-row"><?= checkbox(!$isDisapproved); ?> <span>For approval</span></div>
                <div class="rec-row"><?= checkbox($isDisapproved); ?> <span>For disapproval due to</span> <span class="reason-line short-reason"><?= e($isDisapproved ? $recommendationReason : ''); ?></span></div>
                <div class="reason-line big"></div>
                <div class="reason-line big"></div>
                <div class="reason-line big"></div>

                <div class="sig-area lower">
                    <div class="sig-line"></div>
                    <div class="sig-pos">Chief of the Division/Section or Unit Head</div>
                </div>
            </td>
        </tr>

        <tr>
            <td colspan="4" class="top-align approval-cell no-right-border">
                <div class="approve-title">7.C APPROVED FOR:</div>
                <div class="approve-row"><span class="short-line"><?= number_format($daysWithPay, 3); ?></span> day with pay</div>
                <div class="approve-row"><span class="short-line"><?= number_format($daysWithoutPay, 3); ?></span> days without pay</div>
                <div class="approve-row"><span class="short-line"><?= e($approvedOthers); ?></span> others (Specify)</div>
            </td>

            <td colspan="4" class="top-align disapprove-cell no-left-border">
                <div class="approve-title">7.&nbsp;D&nbsp; DISAPPROVED DUE TO:</div>
                <div class="reason-line big"></div>
                <div class="reason-line big"></div>
                <div class="reason-line big"></div>
            </td>
        </tr>

        <tr>
            <td colspan="8" class="final-signatory-row">
                <div class="sig-area centered-final">
                    <div class="sig-name final"><?= e($signatoryCName); ?></div>
                    <div class="sig-line"></div>
                    <div class="sig-pos"><?= e($signatoryCPosition); ?></div>
                </div>
            </td>
        </tr>
    </table>

    <?php if ($lawTitle !== '' || $lawText !== ''): ?>
        <div class="law-note">
            <strong>Related Law:</strong> <?= e($lawTitle); ?>
            <?php if ($lawText !== ''): ?>
                <div class="law-text"><?= nl2br(e($lawText)); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
window.print();
</script>
</body>
</html>