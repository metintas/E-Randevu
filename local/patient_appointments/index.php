<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php'); // email_to_user için Moodle kütüphaneleri

require_login();


$action = optional_param('action', '', PARAM_ALPHA);
$appointmentid = optional_param('appointmentid', 0, PARAM_INT);

if ($action === 'cancel' && $appointmentid) {
    $appointment = $DB->get_record('appointments', ['id' => $appointmentid]);

    if ($appointment && $appointment->patient_user_id == $USER->id) {
        // Hasta kendi randevusunu iptal ediyor (mail gönderilmez)
        $DB->delete_records('appointments', ['id' => $appointmentid]);
        echo json_encode(['success' => true, 'message' => 'Randevunuz iptal edildi.']);
        exit;
    } else if (is_siteadmin()) {
        // Admin iptal ediyorsa e-posta gönder, sonra sil
        $user = $DB->get_record('user', ['id' => $appointment->patient_user_id]);

        
if ($user && !empty($user->id)) {
    $subject = "Randevunuz İptal Edildi";
    $message = "Sayın {$user->firstname}, randevunuz iptal edilmiştir.";
    email_to_user($user, core_user::get_support_user(), $subject, $message);
}


        $DB->delete_records('appointments', ['id' => $appointmentid]);
        echo json_encode(['success' => true, 'message' => 'Randevu iptal edildi ve kullanıcıya bilgi verildi.']);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Bu işlemi yapma yetkiniz yok.']);
        exit;
    }
}



global $DB, $USER, $PAGE, $OUTPUT; // $PAGE ve $OUTPUT'u global olarak ekledik

// Hata raporlamayı en üst düzeye çıkar - HATA TESPİTİ İÇİN GEÇİCİ OLARAK AÇIK BIRAKIN
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kullanıcının doktor olup olmadığını kontrol et
if ($DB->record_exists('doctors', ['user_id' => $USER->id])) {
    // Eğer kullanıcı doktorsa, bu sayfayı görüntüleyemez.
    redirect(new moodle_url('/my'));
    exit;
}

// AJAX işlemleri
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? $_POST['action'];

    if ($action == 'get_districts' && !empty($_GET['city_id'])) {
        $districts = $DB->get_records('districts', ['city_id' => $_GET['city_id']], 'name ASC');
        $options = '<option value="">-- İlçe Seçiniz --</option>';
        foreach ($districts as $d) {
            $options .= '<option value="' . $d->id . '">' . format_string($d->name) . '</option>';
        }
        echo json_encode(['html' => $options]);
        exit;
    }

    if ($action == 'get_hospitals' && !empty($_GET['district_id'])) {
        $hospitals = $DB->get_records('hospitals', ['district_id' => $_GET['district_id']], 'name ASC');
        $options = '<option value="">-- Hastane Seçiniz --</option>';
        foreach ($hospitals as $h) {
            $options .= '<option value="' . $h->id . '">' . format_string($h->name) . '</option>';
        }
        echo json_encode(['html' => $options]);
        exit;
    }

    if ($action == 'get_polyclinics' && !empty($_GET['hospital_id'])) {
        $polyclinics = $DB->get_records('polyclinics', ['hospital_id' => $_GET['hospital_id']], 'name ASC');
        $options = '<option value="">-- Poliklinik Seçiniz --</option>';
        foreach ($polyclinics as $p) {
            $options .= '<option value="' . $p->id . '">' . format_string($p->name) . '</option>';
        }
        echo json_encode(['html' => $options]);
        exit;
    }

    if ($action == 'get_doctors' && !empty($_GET['polyclinic_id'])) {
        $doctors = $DB->get_records_sql("
            SELECT d.id, u.firstname, u.lastname
            FROM {doctors} d
            JOIN {user} u ON d.user_id = u.id
            WHERE d.polyclinic_id = ?
            ORDER BY u.firstname, u.lastname
        ", [$_GET['polyclinic_id']]);

        $options = '<option value="">-- Doktor Seçiniz --</option>';
        foreach ($doctors as $doc) {
            $options .= '<option value="' . $doc->id . '">' . format_string($doc->firstname . ' ' . $doc->lastname) . '</option>';
        }
        echo json_encode(['html' => $options]);
        exit;
    }

    if ($action == 'get_slots' && !empty($_GET['doctor_id'])) {
        $current_datetime = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM {appointment_slots}
                WHERE doctor_id = ? AND is_booked = 0 AND CONCAT(slot_date, ' ', slot_time) >= ?
                ORDER BY slot_date ASC, slot_time ASC";
        $slots = $DB->get_records_sql($sql, [$_GET['doctor_id'], $current_datetime]);

        $options = '<option value="">-- Randevu Saati Seçiniz --</option>';
        foreach ($slots as $slot) {
            $datetime_timestamp = strtotime($slot->slot_date . ' ' . $slot->slot_time);
            $formatted_datetime = date('Y-m-d H:i', $datetime_timestamp);

            $options .= '<option value="' . $slot->id . '">' . format_string($formatted_datetime) . '</option>';
        }
        echo json_encode(['html' => $options]);
        exit;
    }

    if ($action == 'save_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $city = required_param('city', PARAM_INT);
        $district = required_param('district', PARAM_INT);
        $hospital = required_param('hospital', PARAM_INT);
        $polyclinic = required_param('polyclinic', PARAM_INT);
        $doctorid = required_param('doctor', PARAM_INT);
        $slotid = required_param('appointment_time', PARAM_INT);

        // Seçilen doktorun gerçekten hangi hastaneye bağlı olduğunu bulalım
        $doctor_info = $DB->get_record_sql("
            SELECT d.id AS doctor_id, p.hospital_id
            FROM {doctors} d
            JOIN {polyclinics} p ON d.polyclinic_id = p.id
            WHERE d.id = ?
        ", [$doctorid]);

        if (!$doctor_info) {
            echo json_encode(['success' => false, 'message' => 'Seçilen doktor bilgisi bulunamadı.']);
            exit;
        }

        // Randevu kaydedilirken kullanılacak doğru hastane ID'si
        $correct_hospital_id = $doctor_info->hospital_id;

        // Seçilen randevu slotunu veritabanından al
        $slot = $DB->get_record('appointment_slots', ['id' => $slotid]);

        if (!$slot) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz randevu saati seçimi.']);
            exit;
        }

        // Eğer slot zaten rezerve edilmişse, hata ver
        if ($slot->is_booked == 1) {
            echo json_encode(['success' => false, 'message' => 'Seçtiğiniz randevu saati maalesef dolmuş. Lütfen başka bir saat seçin.']);
            exit;
        }

        // Hastanın YAKLAŞAN (gelecek tarihli) ve beklemede randevusu var mı kontrol et
        // Randevu zamanının şu anki zamandan büyük veya eşit olması koşulunu ekledik.
        $current_datetime = date('Y-m-d H:i:s');
        $existing_appointment = $DB->get_record_sql("
            SELECT a.id, a.appointment_time, a.hospital_id, a.polyclinic_id, a.doctor_id
            FROM {appointments} a
            WHERE a.patient_user_id = ?
            AND a.status = 'pending'
            AND CONCAT(a.appointment_time) >= ?
        ", [$USER->id, $current_datetime]);


        if ($existing_appointment) {
            // Mevcut randevu detaylarını da döndür
            $existing_hospital = $DB->get_field('hospitals', 'name', ['id' => $existing_appointment->hospital_id]);
            $existing_polyclinic = $DB->get_field('polyclinics', 'name', ['id' => $existing_appointment->polyclinic_id]);
            $existing_doctor_user = $DB->get_record_sql("SELECT u.firstname, u.lastname FROM {doctors} d JOIN {user} u ON d.user_id = u.id WHERE d.id = ?", [$existing_appointment->doctor_id]);
            $existing_doctor_name = $existing_doctor_user ? fullname($existing_doctor_user) : 'Bilinmiyor';

            echo json_encode([
                'success' => false,
                'code' => 'EXISTING_APPOINTMENT',
                'message' => 'Zaten beklemede olan bir randevunuz var. Yeni randevu almadan önce mevcut randevunuzu iptal etmelisiniz.',
                'existing_appointment_id' => $existing_appointment->id,
                'existing_appointment_details' => [
                    'time' => date('Y-m-d H:i', strtotime($existing_appointment->appointment_time)),
                    'hospital' => $existing_hospital,
                    'polyclinic' => $existing_polyclinic,
                    'doctor' => $existing_doctor_name
                ]
            ]);
            exit;
        }

        $record = new stdClass();
        $record->patient_user_id = $USER->id;
        $record->doctor_id = $doctorid;
        $record->hospital_id = $correct_hospital_id;
        $record->polyclinic_id = $polyclinic;
        $record->appointment_time = $slot->slot_date . ' ' . $slot->slot_time;
        $record->status = 'pending';
        $record->created_at = date('Y-m-d H:i:s');
        $record->updated_at = date('Y-m-d H:i:s');

        try {
            $DB->insert_record('appointments', $record);
            $slot->is_booked = 1;
            $DB->update_record('appointment_slots', $slot);
            echo json_encode(['success' => true, 'message' => 'Randevunuz başarıyla alındı.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Kayıt hatası: ' . $e->getMessage()]);
        }
        exit;
    }

    // Hastanın aldığı randevuları döndür (AJAX için)
    if ($action == 'list_appointments') {
        $appointments = $DB->get_records_sql("
            SELECT a.id, a.appointment_time, a.status, a.doctor_id, a.cancellation_reason,
                   h.name AS hospital_name,
                   p.name AS polyclinic_name,
                   u.firstname, u.lastname,
                   (SELECT r.id FROM {reports} r WHERE r.appointment_id = a.id LIMIT 1) AS report_id
            FROM {appointments} a
            JOIN {hospitals} h ON a.hospital_id = h.id
            JOIN {polyclinics} p ON a.polyclinic_id = p.id
            JOIN {doctors} d ON a.doctor_id = d.id
            JOIN {user} u ON d.user_id = u.id
            WHERE a.patient_user_id = ?
            ORDER BY a.appointment_time DESC
        ", [$USER->id]);

        $html = '<table class="table table-striped">';
        $html .= '<thead><tr><th>Tarih & Saat</th><th>Hastane</th><th>Poliklinik</th><th>Doktor</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>';

        if (!$appointments) {
            $html .= '<tr><td colspan="6" class="text-center">Henüz randevunuz yok.</td></tr>';
        } else {
            foreach ($appointments as $a) {
                $current_time = time();
                $appointment_timestamp = strtotime($a->appointment_time);
                
                $status_text = '';
                $status_class = '';
                $action_buttons = ''; // 'action_button' yerine 'action_buttons' kullanıldı

                if ($a->status == 'cancelled') {
                    $status_text = 'İptal Edildi';
                    $status_class = 'bg-secondary'; // Gri
                } elseif ($appointment_timestamp < $current_time) {
                    $status_text = 'Geçmiş Randevu';
                    $status_class = 'bg-danger'; // Kırmızı (geçmiş randevu)
                } else {
                    $status_text = 'Yaklaşan Randevu';
                    $status_class = 'bg-success'; // Yeşil (yaklaşan randevu)
                    $action_buttons .= '<button class="btn btn-danger btn-sm cancel-appointment-btn me-2" data-appointment-id="' . $a->id . '">İptal Et</button>';
                }
                
                // Rapor görüntüle butonu
                if (!empty($a->report_id)) {
                    // Rapor ID'si mevcutsa butonu göster
                    $action_buttons .= '<button class="btn btn-info btn-sm view-report-btn" data-report-id="' . $a->report_id . '">Raporu Görüntüle</button>';
                }


                // Tarih ve saat formatını YYYY-MM-DD HH:MM olarak ayarla
                $formatted_datetime = date('Y-m-d H:i', $appointment_timestamp);

                $html .= '<tr>';
                $html .= '<td>' . $formatted_datetime . '</td>';
                $html .= '<td>' . format_string($a->hospital_name) . '</td>';
                $html .= '<td>' . format_string($a->polyclinic_name) . '</td>';
                $html .= '<td>' . format_string($a->firstname . ' ' . $a->lastname) . '</td>';
                $html .= '<td><span class="badge ' . $status_class . '">' . $status_text . '</span></td>';
                $html .= '<td>' . $action_buttons . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        echo json_encode(['html' => $html]);
        exit;
    }

    // Randevu iptal etme işlemi
    if ($action == 'cancel_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['appointment_id'])) {
        $appointment_id = required_param('appointment_id', PARAM_INT);
        $cancellation_reason = optional_param('cancellation_reason', '', PARAM_TEXT); // İptal nedeni için

        $appointment = $DB->get_record('appointments', ['id' => $appointment_id, 'patient_user_id' => $USER->id]);

        if (!$appointment) {
            echo json_encode(['success' => false, 'message' => 'Randevu bulunamadı veya yetkiniz yok.']);
            exit;
        }

        // Randevu zamanı geçmişse iptale izin verme
        if (strtotime($appointment->appointment_time) < time()) {
            echo json_encode(['success' => false, 'message' => 'Geçmiş randevular iptal edilemez.']);
            exit;
        }

        try {
            // Randevuyu iptal olarak işaretle
            $appointment->status = 'cancelled';
            $appointment->updated_at = date('Y-m-d H:i:s'); // DATETIME formatında güncelle
            $appointment->cancellation_reason = $cancellation_reason; // İptal nedenini kaydet
            $DB->update_record('appointments', $appointment);

            // İlgili slotu boş olarak işaretle
            $slot_date = date('Y-m-d', strtotime($appointment->appointment_time));
            $slot_time = date('H:i:s', strtotime($appointment->appointment_time));

            $slot = $DB->get_record('appointment_slots', [
                'doctor_id' => $appointment->doctor_id,
                'slot_date' => $slot_date,
                'slot_time' => $slot_time
            ]);

            if ($slot) {
                $slot->is_booked = 0;
                $DB->update_record('appointment_slots', $slot);
            } else {
                error_log("Randevu ID: {$appointment_id} için slot bulunamadı. Slot tarihi: {$slot_date}, saati: {$slot_time}");
            }

     /*       // MAİL GÖNDERİMİ BAŞLANGICI
            $patient_user = $DB->get_record('user', ['id' => $appointment->patient_user_id]);
            if ($patient_user) {
                $doctor_user = $DB->get_record('user', ['id' => $appointment->doctor_id]);

                // *** DİKKAT: Daha önce eklediğimiz DEBUG satırlarını silebilir veya yorum satırı yapabilirsiniz. ***
                // error_log("DEBUG: patient_user objesi: " . var_export($patient_user, true));
                // error_log("DEBUG: doctor_user objesi: " . var_export($doctor_user, true));
                // error_log("DEBUG: fromuser (null) kullanılıyor, Moodle varsayılanı kullanacak.");

                // ÇÖZÜM: doktor_user'ın geçerli olup olmadığını kontrol edin
                $doctorname = $doctor_user ? fullname($doctor_user) : get_string('unknown_doctor', 'local_patient_appointments');

                $subject = get_string('appointmentcancelledsubject', 'local_patient_appointments');
                $fullmessagehtml = get_string('appointmentcancelledhtml', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => $doctorname, // BURAYI DEĞİŞTİRDİK
                    'appointmenttime' => date('Y-m-d H:i', strtotime($appointment->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $appointment->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $appointment->polyclinic_id]),
                    'reason' => empty($appointment->cancellation_reason) ? get_string('noreasonprovided', 'local_patient_appointments') : format_text($appointment->cancellation_reason, FORMAT_HTML)
                ]);
                $fullmessage = get_string('appointmentcancelledplain', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => $doctorname, // BURAYI DEĞİŞTİRDİK
                    'appointmenttime' => date('Y-m-d H:i', strtotime($appointment->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $appointment->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $appointment->polyclinic_id]),
                    'reason' => empty($appointment->cancellation_reason) ? get_string('noreasonprovided', 'local_patient_appointments') : $appointment->cancellation_reason
                ]);

                $fromuser = null; // Moodle'ın site e-posta adresini kullanır
                $emailresult = email_to_user($patient_user, $fromuser, $subject, $fullmessage, $fullmessagehtml);

                if (!$emailresult) {
                    error_log("Randevu iptal maili gönderilemedi. Randevu ID: {$appointment_id}, Hastaya: {$patient_user->email}");
                }
            }
            // MAİL GÖNDERİMİ SONU
...*/

            echo json_encode(['success' => true, 'message' => 'Randevunuz başarıyla iptal edildi.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Randevu iptal edilirken bir hata oluştu: ' . $e->getMessage()]);
        }
        exit;
    }

    // Mevcut randevu iptali ve yeni randevu onayı
    if ($action == 'cancel_and_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $old_appointment_id = required_param('old_appointment_id', PARAM_INT);
        $new_city = required_param('city', PARAM_INT);
        $new_district = required_param('district', PARAM_INT);
        $new_hospital = required_param('hospital', PARAM_INT);
        $new_polyclinic = required_param('polyclinic', PARAM_INT);
        $new_doctorid = required_param('doctor', PARAM_INT);
        $new_slotid = required_param('appointment_time', PARAM_INT);
        $cancellation_reason = optional_param('cancellation_reason', '', PARAM_TEXT); // İptal nedeni için

        // Eski randevuyu iptal et
        $old_appointment = $DB->get_record('appointments', ['id' => $old_appointment_id, 'patient_user_id' => $USER->id]);

        if (!$old_appointment) {
            echo json_encode(['success' => false, 'message' => 'İptal edilecek randevu bulunamadı veya yetkiniz yok.']);
            exit;
        }

        // Eski randevu zamanı geçmişse iptale izin verme
        if (strtotime($old_appointment->appointment_time) < time()) {
            echo json_encode(['success' => false, 'message' => 'Geçmiş randevular iptal edilemez.']);
            exit;
        }

        try {
            // Eski randevuyu iptal olarak işaretle
            $old_appointment->status = 'cancelled';
            $old_appointment->updated_at = date('Y-m-d H:i:s');
            $old_appointment->cancellation_reason = $cancellation_reason; // İptal nedenini kaydet
            $DB->update_record('appointments', $old_appointment);

            // Eski slotu boş olarak işaretle
            $old_slot_date = date('Y-m-d', strtotime($old_appointment->appointment_time));
            $old_slot_time = date('H:i:s', strtotime($old_appointment->appointment_time));

            $old_slot = $DB->get_record('appointment_slots', [
                'doctor_id' => $old_appointment->doctor_id,
                'slot_date' => $old_slot_date,
                'slot_time' => $old_slot_time
            ]);

            if ($old_slot) {
                $old_slot->is_booked = 0;
                $DB->update_record('appointment_slots', $old_slot);
            } else {
                error_log("Eski Randevu ID: {$old_appointment_id} için slot bulunamadı (cancel_and_book). Slot tarihi: {$old_slot_date}, saati: {$old_slot_time}");
            }

            // ESKİ RANDEVU İPTALİ İÇİN MAİL GÖNDERİMİ BAŞLANGICI
            $patient_user = $DB->get_record('user', ['id' => $old_appointment->patient_user_id]);
            if ($patient_user) {
                $doctor_user_old = $DB->get_record('user', ['id' => $old_appointment->doctor_id]);

                // *** DÜZELTME BAŞLANGICI ***
                $doctorname_old = $doctor_user_old ? fullname($doctor_user_old) : get_string('unknown_doctor', 'local_patient_appointments');
                // *** DÜZELTME SONU ***

                $subject_old = get_string('appointmentcancelledsubject', 'local_patient_appointments');
                $fullmessagehtml_old = get_string('appointmentcancelledhtml', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => $doctorname_old, // BURAYI DEĞİŞTİRDİK
                    'appointmenttime' => date('Y-m-d H:i', strtotime($old_appointment->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $old_appointment->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $old_appointment->polyclinic_id]),
                    'reason' => empty($old_appointment->cancellation_reason) ? get_string('noreasonprovided', 'local_patient_appointments') : format_text($old_appointment->cancellation_reason, FORMAT_HTML)
                ]);
                $fullmessage_old = get_string('appointmentcancelledplain', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => $doctorname_old, // BURAYI DEĞİŞTİRDİK
                    'appointmenttime' => date('Y-m-d H:i', strtotime($old_appointment->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $old_appointment->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $old_appointment->polyclinic_id]),
                    'reason' => empty($old_appointment->cancellation_reason) ? get_string('noreasonprovided', 'local_patient_appointments') : $old_appointment->cancellation_reason
                ]);

                $emailresult_old = email_to_user($patient_user, null, $subject_old, $fullmessage_old, $fullmessagehtml_old);
                if (!$emailresult_old) {
                    error_log("Eski randevu iptal maili gönderilemedi. Randevu ID: {$old_appointment_id}, Hastaya: {$patient_user->email}");
                }
            }
            // ESKİ RANDEVU İPTALİ İÇİN MAİL GÖNDERİMİ SONU

            // Yeni randevuyu al
            $new_slot = $DB->get_record('appointment_slots', [
                'id' => $new_slotid,
                'doctor_id' => $new_doctorid,
                'is_booked' => 0
            ]);

            if (!$new_slot) {
                echo json_encode(['success' => false, 'message' => 'Yeni randevu zamanı dolu veya geçersiz. Eski randevunuz iptal edildi.']);
                exit;
            }

            $new_record = new stdClass();
            $new_record->patient_user_id = $USER->id;
            $new_record->doctor_id = $new_doctorid;
            $new_record->hospital_id = $new_hospital;
            $new_record->polyclinic_id = $new_polyclinic;
            $new_record->appointment_time = $new_slot->slot_date . ' ' . $new_slot->slot_time;
            $new_record->status = 'pending';
            $new_record->created_at = date('Y-m-d H:i:s');
            $new_record->updated_at = date('Y-m-d H:i:s');

            $DB->insert_record('appointments', $new_record);
            $new_slot->is_booked = 1;
            $DB->update_record('appointment_slots', $new_slot);

            // YENİ RANDEVU KAYDI İÇİN MAİL GÖNDERİMİ BAŞLANGICI
            if ($patient_user) {
                $doctor_user_new = $DB->get_record('user', ['id' => $new_doctorid]);

                $subject_new = get_string('appointmentbookedsubject', 'local_patient_appointments');
                $fullmessagehtml_new = get_string('appointmentbookedhtml', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => fullname($doctor_user_new), // BURASI
                    'appointmenttime' => date('Y-m-d H:i', strtotime($new_record->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $new_record->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $new_record->polyclinic_id])
                ]);
                $fullmessage_new = get_string('appointmentbookedplain', 'local_patient_appointments', (object)[
                    'patientname' => fullname($patient_user),
                    'doctorname' => fullname($doctor_user_new), // BURASI
                    'appointmenttime' => date('Y-m-d H:i', strtotime($new_record->appointment_time)),
                    'hospitalname' => $DB->get_field('hospitals', 'name', ['id' => $new_record->hospital_id]),
                    'polyclinicname' => $DB->get_field('polyclinics', 'name', ['id' => $new_record->polyclinic_id])
                ]);

                $emailresult_new = email_to_user($patient_user, null, $subject_new, $fullmessage_new, $fullmessagehtml_new);
                if (!$emailresult_new) {
                    error_log("Yeni randevu maili gönderilemedi. Randevu ID: {$new_record->id}, Hastaya: {$patient_user->email}");
                }
            }
            // YENİ RANDEVU KAYDI İÇİN MAİL GÖNDERİMİ SONU

            echo json_encode(['success' => true, 'message' => 'Eski randevunuz iptal edildi ve yeni randevunuz başarıyla alındı.']);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Randevu iptal edilirken veya yeni randevu alınırken bir hata oluştu: ' . $e->getMessage()]);
        }
        exit;
    }

    // Rapor getirme işlemi
    if ($action == 'get_report' && !empty($_GET['report_id'])) {
        $report_id = required_param('report_id', PARAM_INT);
        $report = $DB->get_record('reports', ['id' => $report_id]);

        if (!$report) {
            echo json_encode(['success' => false, 'message' => 'Rapor bulunamadı.']);
            exit;
        }

        // Güvenlik ve yetkilendirme kontrolü
        $can_view = false;

        // 1. Hasta kendisi mi?
        if ($report->patient_user_id == $USER->id) {
            $can_view = true;
        } 
        // 2. Raporu yazan doktor mu?
        else if ($DB->record_exists('doctors', ['user_id' => $USER->id, 'id' => $report->doctor_id])) {
            $can_view = true;
        }
        // 3. Rapor açık mı ve aynı poliklinikteki bir doktor mu?
        else if ($report->is_shared_with_other_doctors_in_polyclinic == 1) {
            $current_doctor = $DB->get_record('doctors', ['user_id' => $USER->id]);
            if ($current_doctor && $current_doctor->polyclinic_id == $report->polyclinic_id) {
                $can_view = true;
            }
        }
        
        if (!$can_view) {
            echo json_encode(['success' => false, 'message' => 'Bu raporu görüntüleme yetkiniz yok.']);
            exit;
        }

        // Rapor içeriğini ve gizlilik durumunu döndür
        echo json_encode([
            'success' => true,
            'report_content' => format_text($report->report_content, FORMAT_HTML),
            'is_shared' => (bool)$report->is_shared_with_other_doctors_in_polyclinic
        ]);
        exit;
    }

    // Rapor görünürlüğünü güncelleme işlemi
    if ($action == 'update_report_visibility' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $report_id = required_param('report_id', PARAM_INT);
        $is_shared = required_param('is_shared', PARAM_INT); // 0 veya 1

        $report = $DB->get_record('reports', ['id' => $report_id, 'patient_user_id' => $USER->id]);

        if (!$report) {
            echo json_encode(['success' => false, 'message' => 'Rapor bulunamadı veya yetkiniz yok.']);
            exit;
        }

        try {
            $report->is_shared_with_other_doctors_in_polyclinic = (int)$is_shared;
            $report->updated_at = date('Y-m-d H:i:s');
            $DB->update_record('reports', $report);
            echo json_encode(['success' => true, 'message' => 'Rapor görünürlüğü başarıyla güncellendi.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Rapor görünürlüğü güncellenirken bir hata oluştu: ' . $e->getMessage()]);
        }
        exit;
    }


    echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
    exit;
}

// HTML SAYFASI BAŞLANGICI
$PAGE->set_url(new moodle_url('/local/patient_appointments/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Randevularım');
$PAGE->set_heading('Randevularım');

echo $OUTPUT->header();
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<div class="container my-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Randevularım</h3>
        <button class="btn btn-primary" id="openAppointmentModal">Randevu Al</button>
    </div>

    <div id="appointmentsList">
        <div class="text-center">Randevular yükleniyor...</div>
    </div>

    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="appointmentModalLabel">Randevu Alma</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
          </div>
          <div class="modal-body">
            <form id="appointmentForm">
                <div class="mb-3">
                    <label for="city" class="form-label">İl</label>
                    <select id="city" name="city" class="form-select" required>
                        <option value="">-- İl Seçiniz --</option>
                        <?php
                        $cities = $DB->get_records('cities', [], 'name ASC');
                        foreach ($cities as $city) {
                            echo '<option value="' . $city->id . '">' . format_string($city->name) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="district" class="form-label">İlçe</label>
                    <select id="district" name="district" class="form-select" required disabled>
                        <option value="">-- Önce İl Seçiniz --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="hospital" class="form-label">Hastane</label>
                    <select id="hospital" name="hospital" class="form-select" required disabled>
                        <option value="">-- Önce İlçe Seçiniz --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="polyclinic" class="form-label">Poliklinik</label>
                    <select id="polyclinic" name="polyclinic" class="form-select" required disabled>
                        <option value="">-- Önce Hastane Seçiniz --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="doctor" class="form-label">Doktor</label>
                    <select id="doctor" name="doctor" class="form-select" required disabled>
                        <option value="">-- Önce Poliklinik Seçiniz --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="appointment_time" class="form-label">Randevu Saati</label>
                    <select id="appointment_time" name="appointment_time" class="form-select" required disabled>
                        <option value="">-- Önce Doktor Seçiniz --</option>
                    </select>
                </div>

                <div id="formMessage" class="mb-3 text-danger"></div>
                <div id="existingAppointmentWarning" class="alert alert-warning d-none" role="alert">
                    <p>Zaten beklemede bir randevunuz bulunmaktadır:</p>
                    <ul class="list-unstyled">
                        <li id="existingAppTime"></li>
                        <li id="existingAppHospital"></li>
                        <li id="existingAppPolyclinic"></li>
                        <li id="existingAppDoctor"></li>
                    </ul>
                    <p>Yeni randevuyu almak için bu randevuyu iptal etmek ister misiniz?</p>
                    <button type="button" class="btn btn-warning btn-sm" id="cancelExistingAndBookNew">Evet, eskiyi iptal et ve yeni al</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hayır, yeni randevu alma</button>
                </div>


                <button type="submit" class="btn btn-success" id="submitAppointmentBtn">Randevu Al</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="reportModalLabel">Rapor Detayı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div id="reportContent"></div>
                    <div class="form-check form-switch mt-3" id="reportVisibilityToggleContainer">
                        <input class="form-check-input" type="checkbox" id="reportVisibilityToggle">
                        <label class="form-check-label" for="reportVisibilityToggle">Raporu tüm poliklinik doktorlarıyla paylaş (açık/gizli)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // Modal nesneleri
    var appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));
    var reportModal = new bootstrap.Modal(document.getElementById('reportModal'));

    var currentFormData = null; // Eski randevuyu iptal edip yenisini almak için form verilerini saklayacak

    // Randevuları yükle
    function loadAppointments() {
        $('#appointmentsList').html('<div class="text-center">Randevular yükleniyor...</div>'); // Yükleniyor mesajı göster
        $.getJSON('index.php', {action: 'list_appointments'}, function(data) {
            $('#appointmentsList').html(data.html);
        }).fail(function() {
            $('#appointmentsList').html('<div class="alert alert-danger" role="alert">Randevular yüklenirken bir hata oluştu. Lütfen sayfayı yenileyin.</div>');
        });
    }

    // Sayfa yüklendiğinde randevuları hemen yükle
    loadAppointments();

    // Modal aç
    $('#openAppointmentModal').click(function() {
        $('#appointmentForm')[0].reset();
        // Tüm select'leri başlangıç durumuna getir
        $('#city').val(''); // İl seçimini sıfırla
        $('#district').prop('disabled', true).html('<option value="">-- Önce İl Seçiniz --</option>');
        $('#hospital').prop('disabled', true).html('<option value="">-- Önce İlçe Seçiniz --</option>');
        $('#polyclinic').prop('disabled', true).html('<option value="">-- Önce Hastane Seçiniz --</option>');
        $('#doctor').prop('disabled', true).html('<option value="">-- Önce Poliklinik Seçiniz --</option>');
        $('#appointment_time').prop('disabled', true).html('<option value="">-- Önce Doktor Seçiniz --</option>');
        $('#formMessage').text('');
        $('#existingAppointmentWarning').addClass('d-none'); // Uyarıyı gizle
        $('#submitAppointmentBtn').show(); // Randevu Al butonunu göster
        appointmentModal.show();
    });

    // İl seçilince ilçeleri yükle
    $('#city').change(function() {
        var cityId = $(this).val();
        $('#district').prop('disabled', true).html('<option>Yükleniyor...</option>');
        $('#hospital, #polyclinic, #doctor, #appointment_time').prop('disabled', true).html('<option>...</option>');

        if (!cityId) {
            $('#district').prop('disabled', true).html('<option>-- Önce İl Seçiniz --</option>');
            return;
        }

        $.getJSON('index.php', {action: 'get_districts', city_id: cityId}, function(data) {
            $('#district').html(data.html).prop('disabled', false);
        });
    });

    // İlçe seçilince hastaneleri yükle
    $('#district').change(function() {
        var districtId = $(this).val();
        $('#hospital').prop('disabled', true).html('<option>Yükleniyor...</option>');
        $('#polyclinic, #doctor, #appointment_time').prop('disabled', true).html('<option>...</option>');

        if (!districtId) {
            $('#hospital').prop('disabled', true).html('<option>-- Önce İlçe Seçiniz --</option>');
            return;
        }

        $.getJSON('index.php', {action: 'get_hospitals', district_id: districtId}, function(data) {
            $('#hospital').html(data.html).prop('disabled', false);
        });
    });

    // Hastane seçilince poliklinikleri yükle
    $('#hospital').change(function() {
        var hospitalId = $(this).val();
        $('#polyclinic').prop('disabled', true).html('<option>Yükleniyor...</option>');
        $('#doctor, #appointment_time').prop('disabled', true).html('<option>...</option>');

        if (!hospitalId) {
            $('#polyclinic').prop('disabled', true).html('<option>-- Önce Hastane Seçiniz --</option>');
            return;
        }

        $.getJSON('index.php', {action: 'get_polyclinics', hospital_id: hospitalId}, function(data) {
            $('#polyclinic').html(data.html).prop('disabled', false);
        });
    });

    // Poliklinik seçilince doktorları yükle
    $('#polyclinic').change(function() {
        var polyclinicId = $(this).val();
        $('#doctor').prop('disabled', true).html('<option>Yükleniyor...</option>');
        $('#appointment_time').prop('disabled', true).html('<option>...</option>');

        if (!polyclinicId) {
            $('#doctor').prop('disabled', true).html('<option>-- Önce Poliklinik Seçiniz --</option>');
            return;
        }

        $.getJSON('index.php', {action: 'get_doctors', polyclinic_id: polyclinicId}, function(data) {
            $('#doctor').html(data.html).prop('disabled', false);
        });
    });

    // Doktor seçilince randevu saatlerini yükle
    $('#doctor').change(function() {
        var doctorId = $(this).val();
        $('#appointment_time').prop('disabled', true).html('<option>Yükleniyor...</option>');

        if (!doctorId) {
            $('#appointment_time').prop('disabled', true).html('<option>-- Önce Doktor Seçiniz --</option>');
            return;
        }

        $.getJSON('index.php', {action: 'get_slots', doctor_id: doctorId}, function(data) {
            $('#appointment_time').html(data.html).prop('disabled', false);
        });
    });

    // Form submit
    $('#appointmentForm').submit(function(e) {
        e.preventDefault();
        $('#formMessage').text('');
        $('#existingAppointmentWarning').addClass('d-none'); // Uyarıyı gizle
        $('#submitAppointmentBtn').show(); // Randevu Al butonunu tekrar göster

        currentFormData = $(this).serializeArray(); // Form verilerini sakla
        var formData = $(this).serialize() + '&action=save_appointment';

        $.post('index.php', formData, function(response) {
            if (response.success) {
                appointmentModal.hide();
                loadAppointments(); // Randevuları güncel listeyi göstermek için tekrar yükle
                alert(response.message);
            } else {
                if (response.code === 'EXISTING_APPOINTMENT') {
                    // Mevcut randevu uyarısını göster
                    $('#existingAppTime').text('Tarih & Saat: ' + response.existing_appointment_details.time);
                    $('#existingAppHospital').text('Hastane: ' + response.existing_appointment_details.hospital);
                    $('#existingAppPolyclinic').text('Poliklinik: ' + response.existing_appointment_details.polyclinic);
                    $('#existingAppDoctor').text('Doktor: ' + response.existing_appointment_details.doctor);
                    $('#existingAppointmentWarning').removeClass('d-none');
                    $('#submitAppointmentBtn').hide(); // Randevu Al butonunu gizle
                    // Eski randevu ID'sini 'Evet, eskiyi iptal et ve yeni al' butonuna ata
                    $('#cancelExistingAndBookNew').data('existing-appointment-id', response.existing_appointment_id);
                } else {
                    $('#formMessage').text(response.message);
                }
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            // Hata ayrıntılarını konsola yazdır
            console.error("AJAX İsteği Başarısız Oldu:", textStatus, errorThrown);
            console.error("Yanıt Metni:", jqXHR.responseText);
            $('#formMessage').text('Sunucu hatası, lütfen tekrar deneyiniz. Detaylar konsolda.');
        });
    });

    // Eski randevuyu iptal et ve yenisini al butonu
    $('#cancelExistingAndBookNew').click(function() {
        var oldAppointmentId = $(this).data('existing-appointment-id');
        var formDataToSend = currentFormData.slice(); // currentFormData'nın bir kopyasını al
        formDataToSend.push({name: 'action', value: 'cancel_and_book'});
        formDataToSend.push({name: 'old_appointment_id', value: oldAppointmentId});

        $.post('index.php', formDataToSend, function(response) {
            if (response.success) {
                appointmentModal.hide();
                loadAppointments();
                alert(response.message);
            } else {
                $('#formMessage').text(response.message);
                $('#existingAppointmentWarning').addClass('d-none'); // Uyarıyı gizle
                $('#submitAppointmentBtn').show(); // Randevu Al butonunu tekrar göster
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            // Hata ayrıntılarını konsola yazdır
            console.error("AJAX İsteği Başarısız Oldu (Cancel & Book):", textStatus, errorThrown);
            console.error("Yanıt Metni:", jqXHR.responseText);
            $('#formMessage').text('Sunucu hatası (iptal ve yeni al), lütfen tekrar deneyiniz. Detaylar konsolda.');
        });
    });

    // Randevu iptal etme butonu tıklama olayı (delegate ile)
    $(document).on('click', '.cancel-appointment-btn', function() {
        var appointmentId = $(this).data('appointment-id');
        if (confirm('Bu randevuyu iptal etmek istediğinizden emin misiniz?')) {
            $.post('index.php', {action: 'cancel_appointment', appointment_id: appointmentId}, function(response) {
                if (response.success) {
                    alert(response.message);
                    loadAppointments(); // Randevuları güncel listeyi göstermek için tekrar yükle
                } else {
                    alert('Randevu iptal edilirken bir hata oluştu: ' + response.message);
                }
            }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                // Hata ayrıntılarını konsola yazdır
                console.error("AJAX İsteği Başarısız Oldu (Cancel):", textStatus, errorThrown);
                console.error("Yanıt Metni:", jqXHR.responseText);
                alert('Sunucu hatası, lütfen tekrar deneyiniz. Detaylar konsolda.');
            });
        }
    });

    // Rapor görüntüle butonu tıklama olayı (delegate ile)
    $(document).on('click', '.view-report-btn', function() {
        var reportId = $(this).data('report-id');
        if (reportId) {
            $.getJSON('index.php', {action: 'get_report', report_id: reportId}, function(response) {
                if (response.success) {
                    $('#reportContent').html(response.report_content);
                    // Gizlilik anahtarının durumunu ayarla
                    $('#reportVisibilityToggle').prop('checked', response.is_shared);
                    // Rapor modalında görünürlük anahtarını göstermek için
                    $('#reportVisibilityToggleContainer').data('report-id', reportId).show(); 
                    reportModal.show(); // Rapor modalını göster
                } else {
                    alert('Rapor görüntülenirken bir hata oluştu: ' + response.message);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error("AJAX İsteği Başarısız Oldu (Get Report):", textStatus, errorThrown);
                console.error("Yanıt Metni:", jqXHR.responseText);
                alert('Rapor sunucudan çekilirken bir hata oluştu. Detaylar konsolda.');
            });
        } else {
            alert('Rapor ID bulunamadı.');
        }
    });

    // Rapor görünürlüğü değiştirme anahtarı
    $('#reportVisibilityToggle').change(function() {
        var reportId = $('#reportVisibilityToggleContainer').data('report-id');
        var isShared = $(this).is(':checked') ? 1 : 0;

        $.post('index.php', {
            action: 'update_report_visibility',
            report_id: reportId,
            is_shared: isShared
        }, function(response) {
            if (response.success) {
                // Başarılı olursa kullanıcıya bilgi verilebilir
                // console.log(response.message);
            } else {
                alert('Rapor görünürlüğü güncellenirken bir hata oluştu: ' + response.message);
                // Hata durumunda eski durumu geri yükle
                $('#reportVisibilityToggle').prop('checked', !isShared);
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX İsteği Başarısız Oldu (Update Report Visibility):", textStatus, errorThrown);
            console.error("Yanıt Metni:", jqXHR.responseText);
            alert('Rapor görünürlüğü güncellenirken sunucu hatası oluştu. Detaylar konsolda.');
            // Hata durumunda eski durumu geri yükle
            $('#reportVisibilityToggle').prop('checked', !isShared);
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>