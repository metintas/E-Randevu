<?php

// Hata gösterimi (geliştirme ortamı için)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Moodle yapılandırmalarını al
require_once(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/moodlelib.php');

// JSON çıktısı gönderilecek
header('Content-Type: application/json');

global $DB, $USER;

// Kullanıcı giriş yapmış mı kontrol et
require_login();

// Parametreleri al
$patient_id = required_param('patientid', PARAM_INT);
$polyclinic_id = required_param('polyclinicid', PARAM_INT); // Bu parametre hala bekleniyor
$sesskey = required_param('sesskey', PARAM_ALPHANUMEXT);

// Sesskey doğrulaması
if (!confirm_sesskey($sesskey)) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz oturum anahtarı.']);
    exit;
}

// Giriş yapan kullanıcının doktor kaydını al
$current_doctor_record = $DB->get_record('doctors', ['user_id' => $USER->id]);
if (!$current_doctor_record) {
    echo json_encode(['status' => 'error', 'message' => 'Sadece doktorlar bu işlevi kullanabilir.']);
    exit;
}

// Doktorun bu hastayla randevusu var mı? (Bu kontrol önceki isteğiniz doğrultusunda yerinde bırakılmıştır.)
$has_appointment_with_patient = $DB->record_exists('appointments', [
    'doctor_id' => $current_doctor_record->id,
    'patient_user_id' => $patient_id
]);

if (!$has_appointment_with_patient) {
    echo json_encode(['status' => 'error', 'message' => 'Bu hastanın raporlarını görüntüleme yetkiniz yok.']);
    exit;
}

// Raporları çek:
// 1. is_shared_with_other_doctors_in_polyclinic = 1 olan tüm raporlar VEYA
// 2. is_shared_with_other_doctors_in_polyclinic = 0 olsa bile, raporu yazan doktor (r.doctor_id) şu anki doktor ise.
$sql = "
    SELECT r.id, r.report_type, r.report_content, r.created_at, r.is_patient_viewable, r.is_shared_with_other_doctors_in_polyclinic,
            du.firstname AS doctor_firstname, du.lastname AS doctor_lastname,
            p.name AS polyclinic_name
    FROM {reports} r
    JOIN {doctors} d ON r.doctor_id = d.id
    JOIN {user} du ON d.user_id = du.id
    JOIN {polyclinics} p ON r.polyclinic_id = p.id
    WHERE r.patient_user_id = ?
      AND (
            r.is_shared_with_other_doctors_in_polyclinic = 1
            OR (r.is_shared_with_other_doctors_in_polyclinic = 0 AND r.doctor_id = ?)
          )
    ORDER BY r.created_at DESC
";

$params = [$patient_id, $current_doctor_record->id]; // patient_id ve current_doctor_id kullanılıyor

$patient_reports = $DB->get_records_sql($sql, $params);

// Raporları formatla
$formatted_reports = [];
foreach ($patient_reports as $report) {
    $report->created_at = strtotime($report->created_at); // datetime → timestamp
    $report->report_content = format_text($report->report_content, FORMAT_HTML);
    $formatted_reports[] = $report;
}

// JSON yanıtı döndür
echo json_encode(['status' => 'success', 'reports' => array_values($formatted_reports)]);

?>