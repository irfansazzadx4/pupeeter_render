<?php 
// Process form submission before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    include_once("includes/configuration.php");
    include_once("includes/balance_handler.php");
    
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
        exit();
    }

    $token_user = $_SESSION['user_token'];
    $sql = "SELECT email FROM users WHERE token = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token_user);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_email = $row['email'];
        $_SESSION['user_email'] = $user_email; 
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit();
    }
    
    $registNo = $_POST['registNo'] ?? '';
    $salanNo = $_POST['salanNo'] ?? '';
    $unionName = $_POST['unionName'] ?? '';
    $mouzaName = $_POST['mouzaName'] ?? '';
    $thanaName = $_POST['thanaName'] ?? '';
    $districtName = $_POST['districtName'] ?? '';
    $holdingNo = $_POST['holdingNo'] ?? '';
    $kotiyanNo = $_POST['kotiyanNo'] ?? '';
    $porisodDate = $_POST['porisodDate'] ?? '';
    $dateEn = $_POST['dateEn'] ?? '';
    $dateBn = $_POST['dateBn'] ?? '';
    $monthBn = $_POST['monthBn'] ?? '';
    $yearBn = $_POST['yearBn'] ?? '';

    $owners = json_encode($_POST['owners'] ?? []);
    $dags = json_encode($_POST['dags'] ?? []);

    $uddeBokeya = $_POST['uddeBokeya'] ?? '';
    $lastBokeya = $_POST['lastBokeya'] ?? '';
    $sudBokeya = $_POST['sudBokeya'] ?? '';
    $halDabi = $_POST['halDabi'] ?? '';
    $Motdabi = $_POST['Motdabi'] ?? '';
    $MotAdai = $_POST['MotAdai'] ?? '';
    $MotBokeya = $_POST['MotBokeya'] ?? '';
    $Montobbo = $_POST['Montobbo'] ?? '';
    $total_in_words = $_POST['total_in_words'] ?? '';

    $form_id = $_POST['id'] ?? null;

    if ($form_id) {
        $sql = "UPDATE ldtax_data SET
                    registNo=?, salanNo=?, unionName=?, mouzaName=?, thanaName=?, districtName=?,
                    holdingNo=?, kotiyanNo=?, porisodDate=?, dateEn=?, dateBn=?, monthBn=?, yearBn=?,
                    owners=?, dags=?,
                    uddeBokeya=?, lastBokeya=?, sudBokeya=?, halDabi=?, Motdabi=?, MotAdai=?, MotBokeya=?, Montobbo=?, total_in_words=?
                WHERE id=?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ssssssssssssssssssssssssi", 
                $registNo, $salanNo, $unionName, $mouzaName, $thanaName, $districtName,
                $holdingNo, $kotiyanNo, $porisodDate, $dateEn, $dateBn, $monthBn, $yearBn,
                $owners, $dags,
                $uddeBokeya, $lastBokeya, $sudBokeya, $halDabi, $Motdabi, $MotAdai, $MotBokeya, $Montobbo, $total_in_words,
                $form_id
            );
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'ভূমি তথ্য ফর্মটি সফলভাবে আপডেট হয়েছে']);
            } else {
                echo json_encode(['status' => 'error', 'message' => "ফর্ম ডেটা আপডেট করা যায়নি: " . $stmt->error]);
            }
            $stmt->close();
        }
    } else {
        $deduction_result = checkAndDeductBalance($conn, $user_email, 'ldtax_clone');

        if ($deduction_result['success']) {
            $conn->begin_transaction(); 

            try {
                $token = bin2hex(random_bytes(16)); 

                $sql = "INSERT INTO ldtax_data (token, registNo, salanNo, unionName, mouzaName, thanaName, districtName, holdingNo, kotiyanNo, porisodDate, dateEn, dateBn, monthBn, yearBn, owners, dags, uddeBokeya, lastBokeya, sudBokeya, halDabi, Motdabi, MotAdai, MotBokeya, Montobbo, total_in_words) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("sssssssssssssssssssssssss", 
                        $token, $registNo, $salanNo, $unionName, $mouzaName, $thanaName, $districtName, $holdingNo, $kotiyanNo, $porisodDate, $dateEn, $dateBn, $monthBn, $yearBn, $owners, $dags, $uddeBokeya, $lastBokeya, $sudBokeya, $halDabi, $Motdabi, $MotAdai, $MotBokeya, $Montobbo, $total_in_words
                    );
                    
                    if ($stmt->execute()) {
                        $conn->commit();
                        echo json_encode(['status' => 'success', 'message' => 'ভূমি তথ্য ফর্মটি সফলভাবে জমা হয়েছে। ' . $deduction_result['message']]);
                    } else {
                        throw new Exception("ফর্ম ডেটা সংরক্ষণ করা যায়নি: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    throw new Exception("Error preparing insert statement: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => "Balance deduction failed: " . $deduction_result['message']]);
        }
    }
    $conn->close();
    exit(); 
}

// Display logic starts here
include('header.php'); 
include_once("includes/balance_handler.php");

if (!isset($_SESSION['user_token'])) {
    header("location: index.php");
    exit();
}

$ldtax_data = null;
$is_edit_mode = false;
$ldtax_id = null;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $ldtax_id = $_GET['id'];
    $is_edit_mode = true;

    $sql_fetch = "SELECT * FROM ldtax_data WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("i", $ldtax_id);
        $stmt_fetch->execute();
        $result_fetch = $stmt_fetch->get_result();
        if ($result_fetch->num_rows == 1) {
            $ldtax_data = $result_fetch->fetch_assoc();
            $ldtax_data['owners'] = json_decode($ldtax_data['owners'], true);
            $ldtax_data['dags'] = json_decode($ldtax_data['dags'], true);
        }
        $stmt_fetch->close();
    }
}

$service_charge = 0;
$service_sql = "SELECT charge FROM service_charges WHERE service_name = 'ldtax_clone' AND status = 'active'";
$service_result = $conn->query($service_sql);
if ($service_result && $service_result->num_rows > 0) {
    $service_row = $service_result->fetch_assoc();
    $service_charge = $service_row['charge'];
} else {
    $service_charge = 50; 
}

$current_registNo = $ldtax_data['registNo'] ?? '';
$current_salanNo = $ldtax_data['salanNo'] ?? '';
$current_unionName = $ldtax_data['unionName'] ?? '';
$current_mouzaName = $ldtax_data['mouzaName'] ?? '';
$current_thanaName = $ldtax_data['thanaName'] ?? '';
$current_districtName = $ldtax_data['districtName'] ?? '';
$current_holdingNo = $ldtax_data['holdingNo'] ?? '';
$current_kotiyanNo = $ldtax_data['kotiyanNo'] ?? '';
$current_porisodDate = $ldtax_data['porisodDate'] ?? '';
$current_dateEn = $ldtax_data['dateEn'] ?? '';
$current_dateBn = $ldtax_data['dateBn'] ?? '';
$current_monthBn = $ldtax_data['monthBn'] ?? '';
$current_yearBn = $ldtax_data['yearBn'] ?? '';
$current_uddeBokeya = $ldtax_data['uddeBokeya'] ?? '';
$current_lastBokeya = $ldtax_data['lastBokeya'] ?? '';
$current_sudBokeya = $ldtax_data['sudBokeya'] ?? '';
$current_halDabi = $ldtax_data['halDabi'] ?? '';
$current_Motdabi = $ldtax_data['Motdabi'] ?? '';
$current_MotAdai = $ldtax_data['MotAdai'] ?? '';
$current_MotBokeya = $ldtax_data['MotBokeya'] ?? '';
$current_Montobbo = $ldtax_data['Montobbo'] ?? '';
$current_total_in_words = $ldtax_data['total_in_words'] ?? '';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ভূমি উন্নয়ন কর ফর্ম</title>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@300;400;500;600;700&family=Noto+Sans+Bengali:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <style>
    :root {
      --brand: rgb(5, 155, 177);
      --brand-dark: rgb(4, 128, 147);
      --brand-light: rgba(5, 155, 177, 0.10);
      --brand-border: rgba(5, 155, 177, 0.25);
      --brand-focus: rgba(5, 155, 177, 0.55);
      --bg: #ffffff;
      --bg-page: #f4f8fa;
      --bg-section: #ffffff;
      --text-dark: #1a2332;
      --text-mid: #3d5166;
      --text-muted: #7a93a8;
      --border: #d6e4ea;
      --shadow-sm: 0 2px 8px rgba(5,155,177,0.07);
      --shadow-md: 0 4px 24px rgba(5,155,177,0.12);
      --shadow-lg: 0 8px 40px rgba(5,155,177,0.16);
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
      font-family: 'Hind Siliguri', 'Noto Sans Bengali', sans-serif;
      background: var(--bg-page);
      min-height: 100vh;
      color: var(--text-dark);
      padding: 28px 12px 48px;
    }

    .form-container {
      max-width: 990px;
      margin: 0 auto;
      animation: fadeInUp 0.5s ease both;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideIn {
      from { opacity: 0; transform: translateX(-12px); }
      to { opacity: 1; transform: translateX(0); }
    }

    /* === TOP HEADER === */
    .form-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .form-header .govt-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--brand);
      border-radius: 50px;
      padding: 7px 22px;
      font-size: 11.5px;
      color: #fff;
      letter-spacing: 1.4px;
      text-transform: uppercase;
      margin-bottom: 16px;
      box-shadow: var(--shadow-sm);
      font-weight: 600;
    }
    .form-header h2 {
      font-size: 26px;
      font-weight: 700;
      color: var(--brand);
      margin: 0 0 6px;
    }
    .form-header p {
      color: var(--text-muted);
      font-size: 13.5px;
    }

    /* === INFO CARDS === */
    .info-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .info-card {
      background: var(--brand);
      border-radius: 14px;
      padding: 16px 18px;
      display: flex;
      align-items: center;
      gap: 14px;
      transition: transform 0.2s, box-shadow 0.2s;
      animation: fadeInUp 0.5s ease both;
      box-shadow: var(--shadow-md);
    }
    .info-card:nth-child(1) { animation-delay: 0.08s; }
    .info-card:nth-child(2) { animation-delay: 0.16s; }
    .info-card:nth-child(3) { animation-delay: 0.24s; }
    .info-card:nth-child(4) { animation-delay: 0.32s; }
    .info-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
    .info-card .card-icon {
      width: 44px; height: 44px;
      border-radius: 10px;
      background: rgba(255,255,255,0.2);
      display: flex; align-items: center; justify-content: center;
      font-size: 19px;
      color: #fff;
      flex-shrink: 0;
    }
    .info-card .card-label {
      font-size: 10.5px;
      color: rgba(255,255,255,0.75);
      margin-bottom: 3px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 500;
    }
    .info-card .card-value {
      font-size: 16px;
      font-weight: 700;
      color: #ffffff;
    }

    /* === MAIN FORM CARD === */
    .form-card {
      background: var(--bg-section);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 32px;
      box-shadow: var(--shadow-md);
    }

    /* === SECTION HEADERS === */
    .section-block { margin-bottom: 26px; animation: fadeInUp 0.5s ease both; }
    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--brand);
      border-radius: 10px 10px 0 0;
      padding: 11px 18px;
    }
    .section-header .section-title {
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: 13.5px;
      font-weight: 600;
      color: #ffffff;
      letter-spacing: 0.2px;
    }
    .section-header .section-title i {
      color: rgba(255,255,255,0.85);
      font-size: 14px;
    }
    .section-body {
      border: 1px solid var(--brand-border);
      border-top: none;
      border-radius: 0 0 10px 10px;
      padding: 20px;
      background: #ffffff;
    }

    /* === FORM CONTROLS === */
    .form-label {
      font-size: 12.5px;
      font-weight: 500;
      color: var(--text-mid);
      margin-bottom: 5px;
      display: block;
    }
    .form-control, .form-select {
      background: #f8fbfc !important;
      border: 1px solid var(--border) !important;
      border-radius: 9px !important;
      color: #000000 !important;
      padding: 10px 13px !important;
      font-size: 14px !important;
      font-family: 'Hind Siliguri', sans-serif !important;
      transition: all 0.22s ease !important;
      width: 100%;
    }
    .form-control::placeholder { color: #b0c4ce !important; font-size: 13px; }
    .form-control:focus, .form-select:focus {
      outline: none !important;
      border-color: var(--brand-focus) !important;
      box-shadow: 0 0 0 3px rgba(5,155,177,0.12) !important;
      background: #ffffff !important;
    }
    .form-control:hover, .form-select:hover {
      border-color: var(--brand-border) !important;
    }
    .form-select option { background: #ffffff; color: #000000; }

    .helper-text {
      font-size: 11px;
      color: var(--text-muted);
      margin-top: 4px;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .helper-text i { font-size: 10px; color: var(--brand); }

    /* === DATE PREVIEW === */
    .date-preview-box {
      margin-top: 8px;
      padding: 8px 14px;
      background: rgba(5,155,177,0.07);
      border: 1px solid rgba(5,155,177,0.22);
      border-radius: 8px;
      font-size: 14.5px;
      color: var(--brand-dark);
      font-weight: 600;
      display: none;
      animation: fadeInUp 0.3s ease;
      letter-spacing: 0.4px;
    }
    .date-preview-box.visible { display: flex; align-items: center; gap: 8px; }

    /* === ADD ROW BUTTON === */
    .btn-add-row {
      background: rgba(255,255,255,0.18);
      border: 1px solid rgba(255,255,255,0.5);
      color: #ffffff;
      border-radius: 7px;
      padding: 5px 13px;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      display: flex;
      align-items: center;
      gap: 5px;
      font-family: 'Hind Siliguri', sans-serif;
    }
    .btn-add-row:hover {
      background: rgba(255,255,255,0.30);
      transform: scale(1.04);
    }

    /* === DYNAMIC ROWS === */
    .dynamic-row {
      display: flex;
      align-items: flex-end;
      gap: 10px;
      margin-bottom: 12px;
      animation: slideIn 0.3s ease both;
    }
    .dynamic-row .field-group { flex: 1; }
    .btn-remove {
      background: #fff0f0;
      border: 1px solid #fca5a5;
      color: #dc2626;
      border-radius: 8px;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    .btn-remove:hover {
      background: #fee2e2;
      transform: scale(1.08);
    }

    /* === TABLE === */
    .premium-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--brand-border);
    }
    .premium-table thead tr th {
      background: var(--brand);
      color: #ffffff;
      font-size: 11.5px;
      font-weight: 600;
      padding: 11px 10px;
      border-bottom: none;
      letter-spacing: 0.3px;
      text-align: center;
      white-space: nowrap;
    }
    .premium-table tbody tr td {
      padding: 8px 6px;
      border-bottom: 1px solid #e8f4f7;
      vertical-align: middle;
      background: #ffffff;
    }
    .premium-table tbody tr:last-child td { border-bottom: none; }
    .premium-table .form-control {
      font-size: 13px !important;
      padding: 7px 9px !important;
      text-align: center;
    }

    /* === SUBMIT SECTION === */
    .submit-section {
      margin-top: 28px;
      padding-top: 22px;
      border-top: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }
    .submit-buttons {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: center;
    }
    .btn-submit {
      background: var(--brand);
      border: none;
      border-radius: 10px;
      color: #fff;
      padding: 13px 36px;
      font-size: 15.5px;
      font-family: 'Hind Siliguri', sans-serif;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.22s;
      box-shadow: 0 4px 18px rgba(5,155,177,0.32);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-submit:hover {
      background: var(--brand-dark);
      transform: translateY(-2px);
      box-shadow: 0 8px 28px rgba(5,155,177,0.42);
    }
    .btn-list {
      background: #ffffff;
      border: 2px solid var(--brand);
      border-radius: 10px;
      color: var(--brand);
      padding: 11px 28px;
      font-size: 15px;
      font-family: 'Hind Siliguri', sans-serif;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.22s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .btn-list:hover {
      background: var(--brand);
      color: #fff;
      transform: translateY(-2px);
    }
    .charge-notice {
      font-size: 12.5px;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .charge-notice i { color: var(--brand); }

    /* Responsive */
    @media (max-width: 640px) {
      .form-card { padding: 16px; }
      .info-cards { grid-template-columns: 1fr 1fr; }
      .premium-table thead { display: none; }
      .premium-table tbody tr { display: flex; flex-wrap: wrap; padding: 8px; border-bottom: 1px solid var(--border); }
      .premium-table tbody tr td { border: none; padding: 4px; flex: 1 1 50%; background: transparent; }
    }
  </style>
</head>
<body>
<div class="form-container">

  <!-- Header -->
  <div class="form-header">
    <div class="govt-badge">
      <i class="fa-solid fa-landmark"></i>
      গণপ্রজাতন্ত্রী বাংলাদেশ সরকার
    </div>
    <h2>ভূমি উন্নয়ন কর ফর্ম</h2>
    <p>Land Development Tax — ডিজিটাল সেবা পোর্টাল</p>
  </div>

  <!-- Info Cards -->
  <div class="info-cards">
    <div class="info-card">
      <div class="card-icon"><i class="fa-solid fa-wallet"></i></div>
      <div>
        <div class="card-label">বর্তমান ব্যালেন্স</div>
        <div class="card-value">৳<?php echo number_format($balance, 2); ?></div>
      </div>
    </div>
    <div class="info-card">
      <div class="card-icon"><i class="fa-solid fa-receipt"></i></div>
      <div>
        <div class="card-label">সেবা চার্জ</div>
        <div class="card-value">৳<?php echo number_format($service_charge, 0); ?></div>
      </div>
    </div>
    <div class="info-card">
      <div class="card-icon"><i class="fa-solid fa-file-alt"></i></div>
      <div>
        <div class="card-label">ফর্ম অবস্থা</div>
        <div class="card-value"><?php echo $is_edit_mode ? 'সম্পাদনা' : 'নতুন'; ?></div>
      </div>
    </div>
    <div class="info-card">
      <div class="card-icon"><i class="fa-solid fa-shield-halved"></i></div>
      <div>
        <div class="card-label">নিরাপত্তা</div>
        <div class="card-value">SSL সুরক্ষিত</div>
      </div>
    </div>
  </div>

  <!-- Form -->
  <div class="form-card">
    <form id="myForm" method="POST">

      <?php if ($is_edit_mode): ?>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($ldtax_id); ?>">
      <?php endif; ?>

      <!-- Section 1: Basic Info -->
      <div class="section-block" style="animation-delay: 0.1s">
        <div class="section-header">
          <div class="section-title"><i class="fa-solid fa-circle-info"></i> মৌলিক তথ্য</div>
        </div>
        <div class="section-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">রেজিস্ট্রি নম্বর</label>
              <input type="text" class="form-control" name="registNo" id="registNoInput" placeholder="যেমন: 134546215730" value="<?php echo htmlspecialchars($current_registNo); ?>" readonly/>
            </div>
            <div class="col-md-4">
              <label class="form-label">চালান নম্বর <span style="color:var(--text-muted); font-size:11px;">(ঐচ্ছিক)</span></label>
              <input type="text" class="form-control" name="salanNo" placeholder="যেমন: 987654321" value="<?php echo htmlspecialchars($current_salanNo); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">সিটি কর্পোরেশন / পৌরসভা / ইউনিয়ন ভূমি অফিস</label>
              <input type="text" class="form-control" name="unionName" placeholder="যেমন: ঢাকা উত্তর সিটি কর্পোরেশন" value="<?php echo htmlspecialchars($current_unionName); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">মৌজা ও জে. এল. নম্বর</label>
              <input type="text" class="form-control" name="mouzaName" placeholder="যেমন: কল্যাণপুর, জে.এল-১২৩" value="<?php echo htmlspecialchars($current_mouzaName); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">উপজেলা / থানা</label>
              <input type="text" class="form-control" name="thanaName" placeholder="যেমন: সাভার" value="<?php echo htmlspecialchars($current_thanaName); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">জেলা</label>
              <input type="text" class="form-control" name="districtName" placeholder="যেমন: ঢাকা" value="<?php echo htmlspecialchars($current_districtName); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">হোল্ডিং নম্বর <span style="color:var(--text-muted); font-size:11px;">(২ নং রেজিস্টার অনুযায়ী)</span></label>
              <input type="text" class="form-control" name="holdingNo" placeholder="যেমন: HLD-00542" value="<?php echo htmlspecialchars($current_holdingNo); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">খতিয়ান নম্বর</label>
              <input type="text" class="form-control" name="kotiyanNo" placeholder="যেমন: ১২৫৮" value="<?php echo htmlspecialchars($current_kotiyanNo); ?>"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">পরিশোধের সাল</label>
              <input type="text" class="form-control" name="porisodDate" placeholder="যেমন: ১৪৩০-১৪৩১" value="<?php echo htmlspecialchars($current_porisodDate); ?>"/>
            </div>
          </div>
        </div>
      </div>

      <!-- Section 2: Date -->
      <div class="section-block" style="animation-delay: 0.2s">
        <div class="section-header">
          <div class="section-title"><i class="fa-solid fa-calendar-days"></i> তারিখ তথ্য</div>
        </div>
        <div class="section-body">
          <div class="row g-3 align-items-start">
            <div class="col-md-4">
              <label class="form-label">তারিখ (ইংরেজি)</label>
              <input type="text" class="form-control" name="dateEn" id="dateEnInput" placeholder="dd-mm-yyyy" value="<?php echo htmlspecialchars($current_dateEn); ?>"/>
              <div class="helper-text"><i class="fa-solid fa-circle-info"></i> dd-mm-yyyy ফরম্যাটে লিখুন</div>
              <div class="date-preview-box" id="datePreview">
                <i class="fa-solid fa-star-and-crescent" style="font-size:13px;"></i>
                <span id="datePreviewText"></span>
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label">দিন (বাংলা)</label>
              <select class="form-select" name="dateBn">
                <option value="">--নির্বাচন--</option>
                <?php for ($i = 1; $i <= 31; $i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($current_dateBn == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">মাস (বাংলা)</label>
              <select class="form-select" name="monthBn">
                <option value="">--নির্বাচন--</option>
                <?php
                $bn_months = ['বৈশাখ','জ্যৈষ্ঠ','আষাঢ়','শ্রাবণ','ভাদ্র','আশ্বিন','কার্তিক','অগ্রহায়ণ','পৌষ','মাঘ','ফাল্গুন','চৈত্র'];
                foreach ($bn_months as $month): ?>
                  <option value="<?php echo $month; ?>" <?php echo ($current_monthBn == $month) ? 'selected' : ''; ?>><?php echo $month; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">বছর (বাংলা)</label>
              <select class="form-select" name="yearBn">
                <option value="">--নির্বাচন--</option>
                <?php for ($i = 1400; $i <= 1490; $i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($current_yearBn == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Section 3: Owner -->
      <div class="section-block" style="animation-delay: 0.3s">
        <div class="section-header">
          <div class="section-title"><i class="fa-solid fa-users"></i> মালিকের নাম ও সম্পত্তির পরিমাণ</div>
          <button type="button" class="btn-add-row" id="addOwner"><i class="fa-solid fa-plus"></i> যোগ করুন</button>
        </div>
        <div class="section-body" id="ownerSection">
          <div class="row g-2 mb-1" style="padding-bottom: 6px; border-bottom: 1px solid rgba(255,255,255,0.05);">
            <div class="col-md-6"><span class="form-label" style="margin:0;">মালিকের নাম</span></div>
            <div class="col-md-5"><span class="form-label" style="margin:0;">সম্পত্তির পরিমাণ</span></div>
          </div>
          <div class="dynamic-row owner-row">
            <div class="field-group col-md-6">
              <input type="text" class="form-control" name="owners[0][name]" placeholder="মালিকের পূর্ণ নাম লিখুন"/>
            </div>
            <div class="field-group col-md-5">
              <input type="number" step="any" class="form-control" name="owners[0][share]" placeholder="পরিমাণ (একর/শতক)"/>
            </div>
            <div style="width:36px; flex-shrink:0;"></div>
          </div>
        </div>
      </div>

      <!-- Section 4: Dag -->
      <div class="section-block" style="animation-delay: 0.4s">
        <div class="section-header">
          <div class="section-title"><i class="fa-solid fa-map-pin"></i> দাগ নম্বর</div>
          <button type="button" class="btn-add-row" id="addDag"><i class="fa-solid fa-plus"></i> যোগ করুন</button>
        </div>
        <div class="section-body" id="dagSection">
          <div class="row g-2 mb-1" style="padding-bottom: 6px; border-bottom: 1px solid rgba(255,255,255,0.05);">
            <div class="col-md-3"><span class="form-label" style="margin:0;">দাগ নম্বর</span></div>
            <div class="col-md-3"><span class="form-label" style="margin:0;">খতিয়ান শ্রেণি</span></div>
            <div class="col-md-5"><span class="form-label" style="margin:0;">খতিয়ান পরিমাণ</span></div>
          </div>
          <div class="dynamic-row dag-row">
            <div class="field-group" style="flex:1.2">
              <input type="text" class="form-control" name="dags[0][dag]" placeholder="যেমন: ১২৩৪"/>
            </div>
            <div class="field-group" style="flex:1.2">
              <input type="text" class="form-control" name="dags[0][type]" placeholder="যেমন: বি.এস"/>
            </div>
            <div class="field-group" style="flex:2">
              <input type="text" class="form-control" name="dags[0][amount]" placeholder="যেমন: ০.৫০ একর"/>
            </div>
            <div style="width:36px; flex-shrink:0;"></div>
          </div>
        </div>
      </div>

      <!-- Section 5: Financial Table -->
      <div class="section-block" style="animation-delay: 0.5s">
        <div class="section-header">
          <div class="section-title"><i class="fa-solid fa-table"></i> অতিরিক্ত তথ্য টেবিল</div>
        </div>
        <div class="section-body" style="padding: 14px; overflow-x: auto;">
          <table class="premium-table">
            <thead>
              <tr>
                <th>তিন বৎসরের ঊর্ধ্বের বকেয়া</th>
                <th>গত তিন বৎসরের বকেয়া</th>
                <th>বকেয়ার সুদ ও ক্ষতিপূরণ</th>
                <th>হাল দাবি</th>
                <th>মোট দাবি</th>
                <th>মোট আদায়</th>
                <th>মোট বকেয়া</th>
                <th>মন্তব্য</th>
                <th>সর্বমোট (কথায়)</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><input type="text" class="form-control" name="uddeBokeya" placeholder="0.00" value="<?php echo htmlspecialchars($current_uddeBokeya); ?>"/></td>
                <td><input type="text" class="form-control" name="lastBokeya" placeholder="0.00" value="<?php echo htmlspecialchars($current_lastBokeya); ?>"/></td>
                <td><input type="text" class="form-control" name="sudBokeya" placeholder="0.00" value="<?php echo htmlspecialchars($current_sudBokeya); ?>"/></td>
                <td><input type="text" class="form-control" name="halDabi" placeholder="0.00" value="<?php echo htmlspecialchars($current_halDabi); ?>"/></td>
                <td><input type="text" class="form-control" name="Motdabi" placeholder="0.00" value="<?php echo htmlspecialchars($current_Motdabi); ?>"/></td>
                <td><input type="text" class="form-control" name="MotAdai" placeholder="0.00" value="<?php echo htmlspecialchars($current_MotAdai); ?>"/></td>
                <td><input type="text" class="form-control" name="MotBokeya" placeholder="0.00" value="<?php echo htmlspecialchars($current_MotBokeya); ?>"/></td>
                <td><input type="text" class="form-control" name="Montobbo" placeholder="মন্তব্য লিখুন" value="<?php echo htmlspecialchars($current_Montobbo); ?>"/></td>
                <td><input type="text" class="form-control" name="total_in_words" placeholder="কথায় লিখুন" value="<?php echo htmlspecialchars($current_total_in_words); ?>"/></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Submit -->
      <div class="submit-section">
        <div class="submit-buttons">
          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-paper-plane"></i>
            <?php echo $is_edit_mode ? 'আপডেট করুন' : 'সাবমিট করুন'; ?>
          </button>
          <a href="ldtax_list.php" class="btn-list">
            <i class="fa-solid fa-list"></i>
            ভূমি কর তালিকা
          </a>
        </div>
        <div class="charge-notice">
          <i class="fa-solid fa-circle-exclamation"></i>
          <?php echo $is_edit_mode 
            ? 'আপডেট করতে কোন ফি কাটা হবে না।' 
            : 'সাবমিট করলে আপনার একাউন্ট থেকে ৳' . number_format($service_charge, 0) . ' টাকা কেটে নেওয়া হবে।'; ?>
        </div>
      </div>

    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  // ============================
  // PHP DATA
  // ============================
  var isEditMode = <?php echo json_encode($is_edit_mode); ?>;
  var ldtaxData = <?php echo json_encode($ldtax_data); ?>;

  // ============================
  // BANGLA UTILITIES
  // ============================
  function toBanglaNum(n) {
    var d = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return String(n).replace(/[0-9]/g, function(c){ return d[parseInt(c)]; });
  }

  var enMonthToBn = {
    '01':'জানুয়ারি','02':'ফেব্রুয়ারি','03':'মার্চ','04':'এপ্রিল',
    '05':'মে','06':'জুন','07':'জুলাই','08':'আগস্ট',
    '09':'সেপ্টেম্বর','10':'অক্টোবর','11':'নভেম্বর','12':'ডিসেম্বর'
  };

  function convertDateToBangla(val) {
    var parts = val.split('-');
    if (parts.length !== 3) return null;
    var dd = parts[0].trim(), mm = parts[1].trim(), yyyy = parts[2].trim();
    if (dd.length < 1 || mm.length < 1 || yyyy.length < 4) return null;
    var mnBn = enMonthToBn[mm.padStart(2,'0')];
    if (!mnBn) return null;
    return toBanglaNum(parseInt(dd)) + ' ' + mnBn + ' ' + toBanglaNum(yyyy);
  }

  // ============================
  // SERIAL NUMBER
  // ============================
  var serialCount = 1;
  function updateSerial() {
    var s = String(serialCount).padStart(4, '0');
    $('#serialDisplay').text(s);
    $('#serialNo').val(s);
  }

  // Load serial from localStorage
  $(document).ready(function() {
    var stored = localStorage.getItem('ldtax_serial');
    if (stored) { serialCount = parseInt(stored) || 1; }
    updateSerial();
  });

  // ============================
  // AUTO 12-DIGIT REGISTRY NO
  // ============================
  $(document).ready(function() {
    var regInput = $('#registNoInput');
    // Only auto-fill if empty (new mode) — edit mode already has value from PHP
    if (!regInput.val() || regInput.val().trim() === '') {
      var rand12 = '';
      // First digit never 0 to ensure proper 12-digit number
      rand12 += Math.floor(Math.random() * 9) + 1;
      for (var i = 0; i < 11; i++) {
        rand12 += Math.floor(Math.random() * 10);
      }
      regInput.val(rand12);
    }
  });

  // ============================
  // TABLE NUMERIC FIELDS — English digits only
  // ============================
  var numericTableFields = [
    'uddeBokeya', 'lastBokeya', 'sudBokeya',
    'halDabi', 'Motdabi', 'MotAdai', 'MotBokeya'
  ];

  numericTableFields.forEach(function(fieldName) {
    var selector = 'input[name="' + fieldName + '"]';
    // On keypress: block non-numeric / non-decimal characters
    $(document).on('keypress', selector, function(e) {
      var ch = String.fromCharCode(e.which);
      if (!/[0-9.]/.test(ch) && e.which !== 8 && e.which !== 0) {
        e.preventDefault();
      }
      // Prevent more than one decimal point
      if (ch === '.' && $(this).val().indexOf('.') !== -1) {
        e.preventDefault();
      }
    });
    // On paste or input: strip any non-numeric characters
    $(document).on('input', selector, function() {
      var val = $(this).val();
      val = val.replace(/[^0-9.]/g, '');
      var parts = val.split('.');
      if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
      }
      $(this).val(val);
    });
  });

  // ============================
  // DATE LIVE PREVIEW
  // ============================
  $('#dateEnInput').on('input', function() {
    var val = $(this).val().trim();
    var result = convertDateToBangla(val);
    if (result) {
      $('#datePreviewText').text(result);
      $('#datePreview').addClass('visible');
    } else {
      $('#datePreview').removeClass('visible');
    }
  });

  // Trigger on load if value already exists
  if ($('#dateEnInput').val()) {
    $('#dateEnInput').trigger('input');
  }

  // ============================
  // DYNAMIC ROW TRACKING
  // ============================
  var ownerIndex = 1;
  var dagIndex = 1;

  function addOwnerRow(name, share) {
    name = name || ''; share = share || '';
    var row = $(`
      <div class="dynamic-row owner-row" style="opacity:0">
        <div class="field-group" style="flex:2">
          <input type="text" class="form-control" name="owners[${ownerIndex}][name]" placeholder="মালিকের পূর্ণ নাম লিখুন" value="${name}">
        </div>
        <div class="field-group" style="flex:1.5">
          <input type="number" step="any" class="form-control" name="owners[${ownerIndex}][share]" placeholder="পরিমাণ (একর/শতক)" value="${share}">
        </div>
        <button type="button" class="btn-remove remove-btn"><i class="fa-solid fa-xmark"></i></button>
      </div>
    `);
    $('#ownerSection').append(row);
    setTimeout(function(){ row.css('opacity', '1').css('transition', 'opacity 0.3s'); }, 10);
    ownerIndex++;
  }

  function addDagRow(dag, type, amount) {
    dag = dag || ''; type = type || ''; amount = amount || '';
    var row = $(`
      <div class="dynamic-row dag-row" style="opacity:0">
        <div class="field-group" style="flex:1.2">
          <input type="text" class="form-control" name="dags[${dagIndex}][dag]" placeholder="যেমন: ১২৩৪" value="${dag}">
        </div>
        <div class="field-group" style="flex:1.2">
          <input type="text" class="form-control" name="dags[${dagIndex}][type]" placeholder="যেমন: বি.এস" value="${type}">
        </div>
        <div class="field-group" style="flex:2">
          <input type="text" class="form-control" name="dags[${dagIndex}][amount]" placeholder="যেমন: ০.৫০ একর" value="${amount}">
        </div>
        <button type="button" class="btn-remove remove-btn"><i class="fa-solid fa-xmark"></i></button>
      </div>
    `);
    $('#dagSection').append(row);
    setTimeout(function(){ row.css('opacity', '1').css('transition', 'opacity 0.3s'); }, 10);
    dagIndex++;
  }

  // ============================
  // EDIT MODE PRE-FILL
  // ============================
  $(document).ready(function() {
    if (isEditMode && ldtaxData) {
      $('#ownerSection .owner-row').remove();
      $('#dagSection .dag-row').remove();

      if (ldtaxData.owners && ldtaxData.owners.length > 0) {
        ldtaxData.owners.forEach(function(o) { addOwnerRow(o.name, o.share); });
      } else { addOwnerRow(); }

      if (ldtaxData.dags && ldtaxData.dags.length > 0) {
        ldtaxData.dags.forEach(function(d) { addDagRow(d.dag, d.type, d.amount); });
      } else { addDagRow(); }
    } else {
      if ($('#ownerSection .owner-row').length === 0) addOwnerRow();
      if ($('#dagSection .dag-row').length === 0) addDagRow();
    }
  });

  $('#addOwner').click(function() { addOwnerRow(); });
  $('#addDag').click(function() { addDagRow(); });

  $(document).on('click', '.remove-btn', function() {
    var row = $(this).closest('.dynamic-row');
    row.css({ opacity: '0', transform: 'translateX(-10px)', transition: 'all 0.25s' });
    setTimeout(function(){ row.remove(); }, 260);
  });

  // ============================
  // AJAX SUBMIT (UNCHANGED)
  // ============================
  $('#myForm').on('submit', function(e) {
    e.preventDefault();

    Swal.fire({
      title: 'নিশ্চিত করুন',
      text: 'আপনি কি ফর্মটি সাবমিট করতে চান?',
      icon: 'question',
      background: '#0f1628',
      color: '#e2e8f0',
      showCancelButton: true,
      confirmButtonText: 'হ্যাঁ, সাবমিট করুন',
      cancelButtonText: 'বাতিল',
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#475569'
    }).then((result) => {
      if (result.isConfirmed) {
        Swal.fire({
          title: 'প্রক্রিয়াকরণ হচ্ছে...',
          text: 'একটু অপেক্ষা করুন',
          allowOutsideClick: false,
          background: '#0f1628',
          color: '#e2e8f0',
          didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
          url: window.location.href,
          type: 'POST',
          data: $('#myForm').serialize(),
          dataType: 'json',
          success: function(response) {
            if (response.status === 'success') {
              // Increment serial on success
              serialCount++;
              localStorage.setItem('ldtax_serial', serialCount);

              Swal.fire({
                icon: 'success',
                title: 'সফল হয়েছে!',
                text: response.message,
                confirmButtonText: 'ঠিক আছে',
                background: '#0f1628',
                color: '#e2e8f0',
                confirmButtonColor: '#10b981',
                timer: 2000,
                showConfirmButton: false
              }).then(() => {
                window.location.href = 'ldtax_list.php?msg=success&text=' + encodeURIComponent(response.message);
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'ত্রুটি!',
                text: response.message,
                background: '#0f1628',
                color: '#e2e8f0',
                confirmButtonText: 'ঠিক আছে',
                confirmButtonColor: '#3b82f6'
              });
            }
          },
          error: function(xhr, status, error) {
            Swal.close();
            var errorMessage = '';
            try {
              var r = JSON.parse(xhr.responseText);
              errorMessage = r.message || 'একটি সমস্যা হয়েছে';
            } catch(e) {
              errorMessage = xhr.responseText || 'নেটওয়ার্ক সমস্যা';
            }
            if (errorMessage.includes('Insufficient balance')) {
              Swal.fire({
                icon: 'warning',
                title: 'অপর্যাপ্ত ব্যালেন্স',
                text: 'অনুগ্রহ করে আপনার একাউন্ট রিচার্জ করুন।',
                background: '#0f1628',
                color: '#e2e8f0',
                showCancelButton: true,
                confirmButtonText: 'রিচার্জ করুন',
                cancelButtonText: 'পরে',
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#475569'
              }).then((r) => {
                if (r.isConfirmed) window.location.href = 'recharge.php';
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'সমস্যা হয়েছে!',
                text: errorMessage,
                background: '#0f1628',
                color: '#e2e8f0',
                confirmButtonText: 'ঠিক আছে',
                confirmButtonColor: '#3b82f6'
              });
            }
          }
        });
      }
    });
  });
</script>
</body>
</html>