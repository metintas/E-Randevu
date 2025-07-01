<?php
// Geçici hata ayıklama ayarları. Canlı sunucuda KESİNLİKLE kaldırılmalıdır!
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Moodle'ın temel yapısını yükle
require_once(__DIR__ . '/../../config.php');

// Moodle sistem içi çalışma kontrolü
defined('MOODLE_INTERNAL') || die();

global $DB, $USER;

// Sadece POST istekleri ve geçerli sesskey için JSON yanıtı veren helper
function json_response_secure($data, $action_name) {
    global $USER;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action_name !== 'list_slots') {
        // error_log("AJAX DEBUG: '{$action_name}' için geçersiz istek metodu: " . $_SERVER['REQUEST_METHOD']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Geçersiz istek metodu.']);
        exit;
    }
    // list_slots dışındaki POST istekleri için sesskey kontrolü
    // Çoklu silme de POST olduğu için sesskey kontrolüne tabi olmalı
    if ($action_name !== 'list_slots' && !confirm_sesskey()) {
        // error_log("AJAX DEBUG: '{$action_name}' için geçersiz güvenlik anahtarı. User: " . $USER->id);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik anahtarı.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Ortak hata yakalama bloğu
try {
    // Kullanıcı girişi ve yetki kontrolü
    require_login();
    $context = context_system::instance();
    require_capability('local/chiefdoctor_panel:createslots', $context);

    // Başhekim bilgisini al
    $chief = $DB->get_record('chiefdoctors', ['user_id' => $USER->id], '*', MUST_EXIST);
    if (!$chief) {
        json_response_secure(['success' => false, 'message' => 'Başhekim bilgisi bulunamadı veya yetkiniz yok.'], 'auth_check');
    }
    $hospitalid = $chief->hospital_id;

    $action = required_param('action', PARAM_ALPHANUMEXT);
    // error_log("AJAX DEBUG: Alınan aksiyon: '{$action}'");

    switch ($action) {
        case 'list_slots':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                json_response_secure(['success' => false, 'message' => 'Geçersiz istek metodu. GET bekleniyor.'], $action);
            }

            $doctorid = required_param('doctorid', PARAM_INT);
            $page = optional_param('page', 1, PARAM_INT);
            $perpage = optional_param('perpage', 20, PARAM_INT);

            if ($page < 1) $page = 1;
            if ($perpage < 1) $perpage = 20;
            if ($perpage > 100) $perpage = 100;

            $offset = ($page - 1) * $perpage;

            // error_log("AJAX DEBUG: 'list_slots' için gelen doktor ID: " . $doctorid . ", Sayfa: " . $page . ", Sayfa Başına: " . $perpage);

            $doctor_record = $DB->get_record('doctors', ['id' => $doctorid, 'hospital_id' => $hospitalid]);
            if (!$doctor_record) {
                // error_log("AJAX ERROR: Doktor bulunamadı veya hastaneye ait değil. ID: {$doctorid}, Hospital: {$hospitalid}");
                json_response_secure(['success' => false, 'message' => 'Doktor bulunamadı veya hastanenize ait değil.'], $action);
            }
            // error_log("AJAX DEBUG: Doktor kaydı bulundu: " . json_encode($doctor_record));

            $doctor_user = $DB->get_record('user', ['id' => $doctor_record->user_id]);
            if (!$doctor_user) {
                // error_log("AJAX ERROR: Doktorun kullanıcı bilgileri bulunamadı. User ID: {$doctor_record->user_id}");
                json_response_secure(['success' => false, 'message' => 'Doktorun kullanıcı bilgileri bulunamadı.'], $action);
            }
            // error_log("AJAX DEBUG: Doktor kullanıcı bilgileri: " . fullname($doctor_user));

            $total_slots = $DB->count_records('appointment_slots', ['doctor_id' => $doctorid]);
            // error_log("AJAX DEBUG: Toplam slot sayısı: " . $total_slots);

            $slots = $DB->get_records('appointment_slots', ['doctor_id' => $doctorid], 'slot_date ASC, slot_time ASC', '*', $offset, $perpage);
            // error_log("AJAX DEBUG: Çekilen slot sayısı (bu sayfa için): " . count($slots));
            // if (empty($slots)) {
            //     error_log("AJAX DEBUG: Doktor ID {$doctorid} için bu sayfada hiç slot bulunamadı.");
            // } else {
            //     error_log("AJAX DEBUG: İlk 3 slot örneği (bu sayfa için): " . json_encode(array_slice($slots, 0, 3)));
            // }

            $polyclinic_name = $DB->get_field('polyclinics', 'name', ['id' => $doctor_record->polyclinic_id]) ?: 'Belirtilmemiş Poliklinik';

            $response_data = [
                'success' => true,
                'doctorName' => fullname($doctor_user),
                'polyclinicName' => $polyclinic_name,
                'slots' => array_values($slots),
                'totalSlots' => $total_slots,
                'currentPage' => $page,
                'perPage' => $perpage,
                'totalPages' => ceil($total_slots / $perpage)
            ];

            json_response_secure($response_data, $action);
            break;

        case 'toggle_slot_status':
            $slotid = required_param('slotid', PARAM_INT);
            $status = required_param('status', PARAM_INT);
            // error_log("AJAX DEBUG: 'toggle_slot_status' için slot ID: {$slotid}, durum: {$status}");

            // Güvenlik kontrolü: Slotun başhekimin hastanesine ait olup olmadığını kontrol et
            $sql = "SELECT aslots.id, aslots.doctor_id FROM {appointment_slots} aslots
                     JOIN {doctors} d ON aslots.doctor_id = d.id
                     WHERE aslots.id = :slotid AND d.hospital_id = :hospitalid";
            $slot_check = $DB->get_record_sql($sql, ['slotid' => $slotid, 'hospitalid' => $hospitalid]);

            if (!$slot_check) {
                // error_log("AJAX ERROR: Slot durumu değiştirilemedi, yetkisiz erişim veya slot bulunamadı. Slot ID: {$slotid}");
                json_response_secure(['success' => false, 'message' => 'Randevu slotu bulunamadı veya yetkiniz yok.'], $action);
            }

            // Slotu güncelle
            $updateslot = new stdClass();
            $updateslot->id = $slotid;
            $updateslot->is_booked = (int)$status;

            if ($DB->update_record('appointment_slots', $updateslot)) {
                // error_log("AJAX DEBUG: Slot durumu başarıyla güncellendi. Slot ID: {$slotid}, Yeni durum: {$status}");
                json_response_secure(['success' => true, 'message' => 'Randevu slotu durumu başarıyla güncellendi.'], $action);
            } else {
                // error_log("AJAX ERROR: Slot durumu güncellenemedi. Slot ID: {$slotid}");
                json_response_secure(['success' => false, 'message' => 'Randevu slotu durumu güncellenemedi.'], $action);
            }
            break;

        case 'delete_slot':
            $slotid = required_param('slotid', PARAM_INT);
            // error_log("AJAX DEBUG: 'delete_slot' için slot ID: {$slotid}");

            // Güvenlik kontrolü: Slotun başhekimin hastanesine ait olup olmadığını kontrol et
            $sql = "SELECT aslots.id FROM {appointment_slots} aslots
                     JOIN {doctors} d ON aslots.doctor_id = d.id
                     WHERE aslots.id = :slotid AND d.hospital_id = :hospitalid";
            $slot_check = $DB->get_record_sql($sql, ['slotid' => $slotid, 'hospitalid' => $hospitalid]);

            if (!$slot_check) {
                // error_log("AJAX ERROR: Slot silinemedi, yetkisiz erişim veya slot bulunamadı. Slot ID: {$slotid}");
                json_response_secure(['success' => false, 'message' => 'Randevu slotu bulunamadı veya yetkiniz yok.'], $action);
            }

            if ($DB->delete_records('appointment_slots', ['id' => $slotid])) {
                // error_log("AJAX DEBUG: Randevu slotu başarıyla silindi. Slot ID: {$slotid}");
                json_response_secure(['success' => true, 'message' => 'Randevu slotu başarıyla silindi.'], $action);
            } else {
                // error_log("AJAX ERROR: Randevu slotu silinemedi. Slot ID: {$slotid}");
                json_response_secure(['success' => false, 'message' => 'Randevu slotu silinemedi.'], $action);
            }
            break;

        case 'delete_multiple_slots':
            // slotids parametresini bir dizi olarak alıyoruz
            $slotids_str = required_param('slotids', PARAM_RAW); // Virgülle ayrılmış string olarak gelir
            $slotids = array_map('intval', explode(',', $slotids_str)); // Integer dizisine çevir

            if (empty($slotids)) {
                json_response_secure(['success' => false, 'message' => 'Silinecek slot ID\'si bulunamadı.'], $action);
            }

            $deleted_count = 0;
            $failed_count = 0;
            $failed_ids = [];

            foreach ($slotids as $slotid) {
                // Güvenlik kontrolü: Her bir slotun başhekimin hastanesine ait olup olmadığını kontrol et
                $sql = "SELECT aslots.id FROM {appointment_slots} aslots
                         JOIN {doctors} d ON aslots.doctor_id = d.id
                         WHERE aslots.id = :slotid AND d.hospital_id = :hospitalid";
                $slot_check = $DB->get_record_sql($sql, ['slotid' => $slotid, 'hospitalid' => $hospitalid]);

                if ($slot_check) {
                    if ($DB->delete_records('appointment_slots', ['id' => $slotid])) {
                        $deleted_count++;
                    } else {
                        $failed_count++;
                        $failed_ids[] = $slotid;
                    }
                } else {
                    $failed_count++;
                    $failed_ids[] = $slotid; // Yetkisiz erişim veya bulunamama durumunda da başarısız say
                }
            }

            if ($deleted_count > 0) {
                $message = "Başarıyla silinen slot sayısı: {$deleted_count}.";
                if ($failed_count > 0) {
                    $message .= " Silinemeyen slot sayısı: {$failed_count}. (ID'ler: " . implode(', ', $failed_ids) . ")";
                }
                json_response_secure(['success' => true, 'message' => $message], $action);
            } else {
                $message = "Hiçbir slot silinemedi.";
                if ($failed_count > 0) {
                    $message .= " (Tüm slotlar yetkisiz erişim veya bulunamadığı için silinemedi: " . implode(', ', $failed_ids) . ")";
                }
                json_response_secure(['success' => false, 'message' => $message], $action);
            }
            break;

        default:
            // error_log("AJAX ERROR: Geçersiz işlem tipi: {$action}");
            json_response_secure(['success' => false, 'message' => 'Geçersiz işlem tipi.'], $action);
            break;
    }

} catch (moodle_exception $e) {
    // error_log("AJAX FATAL: Moodle Exception in ajax.php: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
    json_response_secure(['success' => false, 'message' => 'Sistem Hatası: ' . $e->getMessage()], isset($action) ? $action : 'unknown');
} catch (Exception $e) {
    // error_log("AJAX FATAL: General Exception in ajax.php: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
    json_response_secure(['success' => false, 'message' => 'Beklenmeyen bir hata oluştu: ' . $e->getMessage()], isset($action) ? $action : 'unknown');
}
?>
