<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY and FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * My Courses.
 *
 * @package    core
 * @subpackage my
 * @copyright  2021 Mathew May <mathew.solutions>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/my/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/moodlelib.php'); // For messaging functions like message_send

redirect_if_major_upgrade_required();

require_login();

global $DB, $USER, $OUTPUT, $PAGE;

$hassiteconfig = has_capability('moodle/site:config', context_system::instance());
if ($hassiteconfig && moodle_needs_upgrading()) {
    redirect(new moodle_url('/admin/index.php'));
}

$context = context_system::instance();

// --- START: Doctor-specific functionalities ---
$is_doctor = $DB->record_exists('doctors', ['user_id' => $USER->id]);
$doctor_appointments_html = '';
$doctor_reports_html = '';
$all_reports_for_modals = []; // Tüm raporları modal oluşturmak için burada tutacağız
$patient_reports_for_modals = []; // Hastanın görebileceği raporları modal oluşturmak için burada tutacağız

if ($is_doctor) {
    $doctor_record = $DB->get_record('doctors', ['user_id' => $USER->id]);

    if ($doctor_record) {
        $doctor_id = $doctor_record->id;

        // --- Handle Appointment Cancellation ---
        if (isset($_POST['cancel_appointment']) && confirm_sesskey()) {
            $appointmentid = required_param('appointmentid', PARAM_INT);
            $cancellationreason = required_param('cancellationreason', PARAM_TEXT);

            $appointment_to_cancel = $DB->get_record('appointments', ['id' => $appointmentid, 'doctor_id' => $doctor_id]);

            if ($appointment_to_cancel) {
                $appointment_to_cancel->status = 'cancelled';
                $appointment_to_cancel->cancellation_reason = clean_param($cancellationreason, PARAM_TEXT);
                $DB->update_record('appointments', $appointment_to_cancel);

                $appointment_date = date('Y-m-d', strtotime($appointment_to_cancel->appointment_time));
                $appointment_time = date('H:i:s', strtotime($appointment_to_cancel->appointment_time));

                $slot_to_free = $DB->get_record('appointment_slots', [
                    'doctor_id' => $doctor_id,
                    'slot_date' => $appointment_date,
                    'slot_time' => $appointment_time,
                    'is_booked' => 1
                ]);

                if ($slot_to_free) {
                    $slot_to_free->is_booked = 0;
                    $DB->update_record('appointment_slots', $slot_to_free);
                    $OUTPUT->notification('Randevu slotu başarıyla boşa çıkarıldı.', 'notifysuccess');
                } else {
                    $OUTPUT->notification('Randevuya ait slot bulunamadı veya zaten boştaydı. ', 'notifywarning');
                }

                $report = new stdClass();
                $report->appointment_id = $appointmentid;
                $report->doctor_id = $doctor_id;
                $report->patient_user_id = $appointment_to_cancel->patient_user_id;
                $report->report_type = 'iptal_bildirimi';
                $report->report_content = 'Randevu iptal edildi. Neden: ' . clean_param($cancellationreason, PARAM_TEXT);
                $report->polyclinic_id = $appointment_to_cancel->polyclinic_id;
                $report->is_shared_with_other_doctors_in_polyclinic = 0;
                $report->is_patient_viewable = 0; // İptal bildirimleri varsayılan olarak hastaya görünmez
                $DB->insert_record('reports', $report);

                $patient = $DB->get_record('user', ['id' => $appointment_to_cancel->patient_user_id]);
                if ($patient) {
                    $messageto = new \core\message\message();
                    $messageto->component = 'moodle_appointment_system';
                    $messageto->name = 'appointment_cancellation';
                    $messageto->userfrom = $USER;
                    $messageto->userto = $patient;
                    $messageto->subject = 'Randevu İptal Bildirimi';
                    $messageto->fullmessage = 'Sayın ' . fullname($patient) . ",\n\n" .
                                                'Doktorunuz ' . fullname($USER) . ' tarafından ' .
                                                userdate(strtotime($appointment_to_cancel->appointment_time), get_string('strftimedatetime', 'langconfig')) .
                                                ' tarihli randevunuz iptal edilmiştir.' . "\n\n" .
                                                'İptal nedeni: ' . clean_param($cancellationreason, PARAM_TEXT) . "\n\n" .
                                                'Anlayışınız için teşekkür ederiz.';
                    $messageto->fullmessageformat = FORMAT_PLAIN;
                    $messageto->notification = 1;
                    $messageto->contexturl = new moodle_url('/my/courses.php');
                    $messageto->contexturlname = get_string('mycourses', 'admin');
                    message_send($messageto);

                    $OUTPUT->notification('Randevu başarıyla iptal edildi ve hastaya bildirim gönderildi.', 'notifysuccess');
                } else {
                    $OUTPUT->notification('Randevu iptal edildi ancak hasta bulunamadığı için bildirim gönderilemedi.', 'notifywarning');
                }
            } else {
                $OUTPUT->notification('Randevu bulunamadı veya iptal etme yetkiniz yok.', 'notifydanger');
            }
            redirect(new moodle_url('/my/courses.php'));
        }

        // --- Handle Report/Prescription Submission ---
        if (isset($_POST['submit_report']) && confirm_sesskey()) {
            $appointmentid = required_param('report_appointmentid', PARAM_INT);
            $reporttype = required_param('reporttype', PARAM_ALPHANUMEXT);
            $reportcontent = required_param('reportcontent', PARAM_TEXT);
            $ispatientviewable = optional_param('is_patient_viewable', 0, PARAM_INT); // is_public yerine is_patient_viewable

            $appointment_for_report = $DB->get_record('appointments', ['id' => $appointmentid, 'doctor_id' => $doctor_id]);

            if ($appointment_for_report) {
                $report = new stdClass();
                $report->appointment_id = $appointmentid;
                $report->doctor_id = $doctor_id;
                $report->patient_user_id = $appointment_for_report->patient_user_id;
                $report->report_type = clean_param($reporttype, PARAM_ALPHANUMEXT);
                $report->report_content = clean_param($reportcontent, PARAM_TEXT);
                $report->polyclinic_id = $appointment_for_report->polyclinic_id;
                $report->is_shared_with_other_doctors_in_polyclinic = optional_param('is_shared_with_other_doctors_in_polyclinic', 0, PARAM_INT); // Yeni checkbox için
                $report->is_patient_viewable = $ispatientviewable; // is_public yerine is_patient_viewable kullanıldı
                $DB->insert_record('reports', $report);

                if ($appointment_for_report->status !== 'cancelled') {
                    $appointment_for_report->status = 'completed';
                    $DB->update_record('appointments', $appointment_for_report);
                }

                $OUTPUT->notification('Rapor/Reçete başarıyla kaydedildi.', 'notifysuccess');
            } else {
                $OUTPUT->notification('Randevu bulunamadı veya rapor yazma yetkiniz yok.', 'notifydanger');
            }
            redirect(new moodle_url('/my/courses.php'));
        }

        $current_datetime = date('Y-m-d H:i:s');

        // Fetch all appointments for this doctor
        $appointments = $DB->get_records_sql("
            SELECT a.id, a.appointment_time, a.status, a.patient_user_id, a.polyclinic_id,
                   u.firstname AS patient_firstname, u.lastname AS patient_lastname,
                   h.name AS hospital_name, p.name AS polyclinic_name
            FROM {appointments} a
            JOIN {user} u ON a.patient_user_id = u.id
            JOIN {hospitals} h ON a.hospital_id = h.id
            JOIN {polyclinics} p ON a.polyclinic_id = p.id
            WHERE a.doctor_id = ?
            ORDER BY a.appointment_time ASC
        ", [$doctor_id]);

        // --- START: Doctor Appointments Display HTML ---
        $doctor_appointments_html .= '<div class="card shadow-sm mb-4">';
        $doctor_appointments_html .= '<div class="card-header bg-primary text-white">';
        $doctor_appointments_html .= '<h3 class="mb-0">Randevularınız</h3>';
        $doctor_appointments_html .= '</div>';
        $doctor_appointments_html .= '<div class="card-body">';
        $doctor_appointments_html .= '<p class="text-muted mb-4">Yaklaşan ve tamamlanmış randevularınızın detayları aşağıdadır.</p>';
        $doctor_appointments_html .= '<div class="table-responsive">';
        $doctor_appointments_html .= '<table class="table table-hover table-bordered caption-top">';
        $doctor_appointments_html .= '<caption>Tüm Randevularınız</caption>';
        $doctor_appointments_html .= '<thead class="table-light">';
        $doctor_appointments_html .= '<tr>';
        $doctor_appointments_html .= '<th scope="col">Tarih & Saat</th>';
        $doctor_appointments_html .= '<th scope="col">Hasta Adı</th>';
        $doctor_appointments_html .= '<th scope="col">Hastane</th>';
        $doctor_appointments_html .= '<th scope="col">Poliklinik</th>';
        $doctor_appointments_html .= '<th scope="col">Durum</th>';
        $doctor_appointments_html .= '<th scope="col">İşlemler</th>';
        $doctor_appointments_html .= '</tr></thead><tbody>';

        if (empty($appointments)) {
            $doctor_appointments_html .= '<tr><td colspan="6" class="text-center py-4 text-muted">';
            $doctor_appointments_html .= '<i class="fa fa-calendar-times-o me-2" aria-hidden="true"></i> Henüz bir randevunuz bulunmamaktadır.';
            $doctor_appointments_html .= '</td></tr>';
        } else {
            foreach ($appointments as $appointment) {
                $appointment_timestamp = strtotime($appointment->appointment_time);
                $formatted_datetime = userdate($appointment_timestamp, get_string('strftimedatetime', 'langconfig'));

                $status_text = '';
                $status_class = '';
                $actions_html = '';

                switch ($appointment->status) {
                    case 'pending':
                        $status_text = 'Beklemede';
                        $status_class = 'bg-warning text-dark';
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-danger me-1" data-bs-toggle="modal" data-bs-target="#cancelModal-' . $appointment->id . '">';
                        $actions_html .= '<i class="fa fa-times-circle" aria-hidden="true"></i> İptal Et';
                        $actions_html .= '</button>';
                        if ($appointment_timestamp <= time() + (60*60)) {
                            $actions_html .= '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reportModal-' . $appointment->id . '">';
                            $actions_html .= '<i class="fa fa-file-text-o" aria-hidden="true"></i> Rapor Yaz';
                            $actions_html .= '</button>';
                        }
                        // Yeni Raporları Görüntüle butonu
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="showPatientReports(' . $appointment->patient_user_id . ', ' . $doctor_record->polyclinic_id . ', \'' . sesskey() . '\');">';
                        $actions_html .= '<i class="fa fa-folder-open" aria-hidden="true"></i> Raporları Görüntüle';
                        $actions_html .= '</button>';
                        break;
                    case 'confirmed':
                        $status_text = 'Onaylandı';
                        $status_class = 'bg-success';
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-danger me-1" data-bs-toggle="modal" data-bs-target="#cancelModal-' . $appointment->id . '">';
                        $actions_html .= '<i class="fa fa-times-circle" aria-hidden="true"></i> İptal Et';
                        $actions_html .= '</button>';
                        if ($appointment_timestamp <= time() + (60*60)) {
                            $actions_html .= '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reportModal-' . $appointment->id . '">';
                            $actions_html .= '<i class="fa fa-file-text-o" aria-hidden="true"></i> Rapor Yaz';
                            $actions_html .= '</button>';
                        }
                        // Yeni Raporları Görüntüle butonu
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="showPatientReports(' . $appointment->patient_user_id . ', ' . $doctor_record->polyclinic_id . ', \'' . sesskey() . '\');">';
                        $actions_html .= '<i class="fa fa-folder-open" aria-hidden="true"></i> Raporları Görüntüle';
                        $actions_html .= '</button>';
                        break;
                    case 'cancelled':
                        $status_text = 'İptal Edildi';
                        $status_class = 'bg-danger';
                        $actions_html .= '<span class="text-muted"><i class="fa fa-ban" aria-hidden="true"></i> İşlem Yok</span>';
                        // İptal edilen randevular için bile rapor görüntüleme yeteneği verilebilir
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="showPatientReports(' . $appointment->patient_user_id . ', ' . $doctor_record->polyclinic_id . ', \'' . sesskey() . '\');">';
                        $actions_html .= '<i class="fa fa-folder-open" aria-hidden="true"></i> Raporları Görüntüle';
                        $actions_html .= '</button>';
                        break;
                    case 'completed':
                        $status_text = 'Tamamlandı';
                        $status_class = 'bg-primary';
                        $existing_report = $DB->get_record('reports', ['appointment_id' => $appointment->id, 'doctor_id' => $doctor_id]);
                        if (!$existing_report) {
                            $actions_html .= '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reportModal-' . $appointment->id . '">';
                            $actions_html .= '<i class="fa fa-file-text-o" aria-hidden="true"></i> Rapor Yaz';
                            $actions_html .= '</button>';
                        } else {
                            $actions_html .= '<span class="text-muted"><i class="fa fa-check" aria-hidden="true"></i> İşlem Yapıldı</span>';
                        }
                        // Yeni Raporları Görüntüle butonu
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="showPatientReports(' . $appointment->patient_user_id . ', ' . $doctor_record->polyclinic_id . ', \'' . sesskey() . '\');">';
                        $actions_html .= '<i class="fa fa-folder-open" aria-hidden="true"></i> Raporları Görüntüle';
                        $actions_html .= '</button>';
                        break;
                    default:
                        $status_text = 'Bilinmiyor';
                        $status_class = 'bg-secondary';
                        $actions_html .= '<span class="text-muted"><i class="fa fa-info-circle" aria-hidden="true"></i> İşlem Yok</span>';
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="showPatientReports(' . $appointment->patient_user_id . ', ' . $doctor_record->polyclinic_id . ', \'' . sesskey() . '\');">';
                        $actions_html .= '<i class="fa fa-folder-open" aria-hidden="true"></i> Raporları Görüntüle';
                        $actions_html .= '</button>';
                        break;
                }

                $doctor_appointments_html .= '<tr>';
                $doctor_appointments_html .= '<td>' . $formatted_datetime . '</td>';
                $doctor_appointments_html .= '<td>' . format_string($appointment->patient_firstname . ' ' . $appointment->patient_lastname) . '</td>';
                $doctor_appointments_html .= '<td>' . format_string($appointment->hospital_name) . '</td>';
                $doctor_appointments_html .= '<td>' . format_string($appointment->polyclinic_name) . '</td>';
                $doctor_appointments_html .= '<td><span class="badge rounded-pill ' . $status_class . '">' . $status_text . '</span></td>';
                $doctor_appointments_html .= '<td>' . $actions_html . '</td>';
                $doctor_appointments_html .= '</tr>';
            }
        }
        $doctor_appointments_html .= '</tbody></table>';
        $doctor_appointments_html .= '</div>';
        $doctor_appointments_html .= '</div>';
        $doctor_appointments_html .= '</div>';


        // --- START: Doctor Reports Display HTML ---
        // Fetch only reports written by the current doctor
        $doctor_reports = $DB->get_records_sql("
            SELECT r.id, r.report_type, r.report_content, r.created_at, r.is_patient_viewable, r.doctor_id, r.polyclinic_id, r.is_shared_with_other_doctors_in_polyclinic,
                   u.firstname AS patient_firstname, u.lastname AS patient_lastname,
                   d.user_id AS writer_doctor_user_id, du.firstname AS writer_doctor_firstname, du.lastname AS writer_doctor_lastname,
                   p.name AS polyclinic_name
            FROM {reports} r
            JOIN {user} u ON r.patient_user_id = u.id
            JOIN {doctors} d ON r.doctor_id = d.id
            JOIN {user} du ON d.user_id = du.id
            JOIN {polyclinics} p ON r.polyclinic_id = p.id
            WHERE r.doctor_id = ?
            ORDER BY r.created_at DESC
        ", [$doctor_id]);


        foreach ($doctor_reports as $report) {
            $all_reports_for_modals[$report->id] = $report;
        }

        $doctor_reports_html .= '<div class="card shadow-sm mb-4">';
        $doctor_reports_html .= '<div class="card-header bg-success text-white">';
        $doctor_reports_html .= '<h3 class="mb-0">Yazdığınız Raporlar ve Reçeteler</h3>';
        $doctor_reports_html .= '</div>';
        $doctor_reports_html .= '<div class="card-body">';
        $doctor_reports_html .= '<p class="text-muted mb-4">Daha önce yazdığınız raporlar ve reçeteler aşağıdadır.</p>';
        // Search bar added here
        $doctor_reports_html .= '<div class="mb-3">';
        $doctor_reports_html .= '<input type="text" id="reportSearch" class="form-control" placeholder="Rapor türü veya hasta adına göre ara...">';
        $doctor_reports_html .= '</div>';
        $doctor_reports_html .= '<div class="table-responsive">';
        $doctor_reports_html .= '<table class="table table-hover table-bordered caption-top" id="doctorReportsTable">';
        $doctor_reports_html .= '<caption>Geçmiş Raporlarınız</caption>';
        $doctor_reports_html .= '<thead class="table-light">';
        $doctor_reports_html .= '<tr>';
        $doctor_reports_html .= '<th scope="col">Tarih</th>';
                $doctor_reports_html .= '<th scope="col">Hasta Adı</th>';
                $doctor_reports_html .= '<th scope="col">Rapor Türü</th>';
                $doctor_reports_html .= '<th scope="col">İçerik Önizlemesi</th>';
                $doctor_reports_html .= '<th scope="col">Erişim</th>';
                $doctor_reports_html .= '<th scope="col">İşlemler</th>';
                $doctor_reports_html .= '</tr></thead><tbody>';

                if (empty($doctor_reports)) {
                    $doctor_reports_html .= '<tr><td colspan="6" class="text-center py-4 text-muted">';
                    $doctor_reports_html .= '<i class="fa fa-file-o me-2" aria-hidden="true"></i> Henüz bir rapor veya reçete yazmamışsınız.';
                    $doctor_reports_html .= '</td></tr>';
                } else {
                    foreach ($doctor_reports as $report) {
                        // Düzeltme: Hasta adı doğrudan sorgudan alındığı için yeniden çekmeye gerek yok.
                        $patient_name = format_string($report->patient_firstname . ' ' . $report->patient_lastname);
                        $formatted_report_date = userdate(strtotime($report->created_at), get_string('strftimedate', 'langconfig'));

                        $report_type_display = '';
                        $report_type_raw = ''; // For search filtering
                        switch ($report->report_type) {
                            case 'reçete': $report_type_display = '<span class="badge bg-info">Reçete</span>'; $report_type_raw = 'Reçete'; break;
                            case 'rapor': $report_type_display = '<span class="badge bg-secondary">Rapor</span>'; $report_type_raw = 'Rapor'; break;
                            case 'tetkik_sonucu': $report_type_display = '<span class="badge bg-dark">Tetkik Sonucu</span>'; $report_type_raw = 'Tetkik Sonucu'; break;
                            case 'sevk': $report_type_display = '<span class="badge bg-warning text-dark">Sevk Belgesi</span>'; $report_type_raw = 'Sevk Belgesi'; break;
                            case 'iptal_bildirimi': $report_type_display = '<span class="badge bg-danger">İptal Bildirimi</span>'; $report_type_raw = 'İptal Bildirimi'; break;
                            default: $report_type_display = '<span class="badge bg-light text-dark">' . $report->report_type . '</span>'; $report_type_raw = $report->report_type; break;
                        }

                        // ERIŞIM DURUMU BURADA DÜZELTİLDİ: is_patient_viewable ve is_shared_with_other_doctors_in_polyclinic baz alınarak
                        $access_status_text = '';
                        $access_status_class = '';
                        if ($report->is_patient_viewable) {
                            $access_status_text = 'Hastaya Açık (Tüm Doktorlar Görüyor)'; // Clarification
                            $access_status_class = 'bg-success';
                        } else if ($report->is_shared_with_other_doctors_in_polyclinic) {
                            $access_status_text = 'Paylaşıldı (Tüm Doktorlar Görüyor)'; // Clarification
                            $access_status_class = 'bg-warning text-dark';
                        } else {
                            $access_status_text = 'Gizli (Sadece Yazan Doktor)'; // Clarification
                            $access_status_class = 'bg-dark';
                        }
                        $access_status = '<span class="badge ' . $access_status_class . '">' . $access_status_text . '</span>';


                        $doctor_reports_html .= '<tr data-patient-name="' . htmlspecialchars(format_string($patient_name)) . '" data-report-type="' . htmlspecialchars($report_type_raw) . '">';
                        $doctor_reports_html .= '<td>' . $formatted_report_date . '</td>';
                        $doctor_reports_html .= '<td>' . format_string($patient_name) . '</td>';
                        $doctor_reports_html .= '<td>' . $report_type_display . '</td>';
                        $doctor_reports_html .= '<td><div class="report-content-preview">' . strip_tags(text_to_html(strip_tags($report->report_content), true, false)) . '</div></td>';
                        $doctor_reports_html .= '<td>' . $access_status . '</td>';
                        $doctor_reports_html .= '<td>';
                        $doctor_reports_html .= '<button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewReportModal-' . $report->id . '">';
                        $doctor_reports_html .= '<i class="fa fa-eye" aria-hidden="true"></i> Görüntüle';
                        $doctor_reports_html .= '</button>';
                        $doctor_reports_html .= '</td>';
                        $doctor_reports_html .= '</tr>';
                    }
                }
                $doctor_reports_html .= '</tbody></table>';
                $doctor_reports_html .= '</div>';
                $doctor_reports_html .= '</div>';
                $doctor_reports_html .= '</div>';

            } else {
                $doctor_appointments_html .= '<p class="alert alert-warning text-center mt-4">Doktor bilgileriniz bulunamadı. Lütfen sistem yöneticinizle iletişime geçin.</p>';
            }
        }
        // --- END: Doctor-specific functionalities ---


        // Get the My Moodle page info.
        if (!$currentpage = my_get_page(null, MY_PAGE_PUBLIC, MY_PAGE_COURSES)) {
            throw new Exception('mymoodlesetup');
        }

        // Start setting up the page.
        $PAGE->set_context($context);
        $PAGE->set_url('/my/courses.php');
        $PAGE->add_body_classes(['limitedwidth', 'page-mycourses']);
        $PAGE->set_pagelayout('mycourses');
        $PAGE->set_docs_path('mycourses');

        $PAGE->set_pagetype('my-index');
        $PAGE->set_subpage($currentpage->id);
        $PAGE->set_title(get_string('mycourses'));
        $PAGE->set_heading(get_string('mycourses'));

        $PAGE->force_lock_all_blocks();
        $PAGE->theme->addblockposition = BLOCK_ADDBLOCK_POSITION_CUSTOM;

        // Add course management if the user has the capabilities for it.
        $coursecat = core_course_category::user_top();
        $coursemanagemenu = [];
        if (count(enrol_get_all_users_courses($USER->id, true)) > 0) {
            if ($coursecat && ($category = core_course_category::get_nearest_editable_subcategory($coursecat, ['create']))) {
                $coursemanagemenu['newcourseurl'] = new moodle_url('/course/edit.php', ['category' => $category->id]);
            }
            if ($coursecat && ($category = core_course_category::get_nearest_editable_subcategory($coursecat, ['manage']))) {
                $coursemanagemenu['manageurl'] = new moodle_url('/course/management.php', ['categoryid' => $category->id]);
            }
            if ($coursecat) {
                $category = core_course_category::get_nearest_editable_subcategory($coursecat, ['moodle/course:request']);
                if ($category && $category->can_request_course()) {
                    $coursemanagemenu['courserequesturl'] = new moodle_url('/course/request.php', ['categoryid' => $category->id]);
                }
            }
        }
        if (!empty($coursemanagemenu)) {
            $PAGE->add_header_action($OUTPUT->render_from_template('my/dropdown', $coursemanagemenu));
        }

        echo $OUTPUT->header();

        // Include Bootstrap 5 CSS and Font Awesome
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">';
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
        echo '<style>
            .report-content-preview {
                max-height: 100px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: normal;
            }
        </style>';


        if (core_userfeedback::should_display_reminder()) {
            core_userfeedback::print_reminder_block();
        }

        // Display doctor's content or regular Moodle content
        if ($is_doctor) {
            echo '<div class="container-fluid my-4">';
            echo $doctor_appointments_html;
            echo $doctor_reports_html;

            // --- Modals for Cancellation and Report ---
            if (!empty($appointments)) {
                foreach ($appointments as $appointment) {
                    // Cancellation Modal
                    echo '
                    <div class="modal fade" id="cancelModal-' . $appointment->id . '" tabindex="-1" aria-labelledby="cancelModalLabel-' . $appointment->id . '" aria-hidden="true">
                      <div class="modal-dialog">
                        <div class="modal-content">
                          <form action="" method="POST">
                            <div class="modal-header bg-danger text-white">
                              <h5 class="modal-title" id="cancelModalLabel-' . $appointment->id . '">Randevuyu İptal Et</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                              <p><strong>' . format_string($appointment->patient_firstname . ' ' . $appointment->patient_lastname) . '</strong> adlı hastanın <strong>' . userdate(strtotime($appointment->appointment_time), get_string('strftimedatetime', 'langconfig')) . '</strong> tarihli randevusunu iptal etmek istediğinizden emin misiniz?</p>
                              <div class="mb-3">
                                <label for="cancellationreason-' . $appointment->id . '" class="form-label">İptal Nedeni:</label>
                                <textarea class="form-control" id="cancellationreason-' . $appointment->id . '" name="cancellationreason" rows="3" required></textarea>
                              </div>
                              <input type="hidden" name="appointmentid" value="' . $appointment->id . '">
                              <input type="hidden" name="sesskey" value="' . sesskey() . '">
                            </div>
                            <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                              <button type="submit" name="cancel_appointment" class="btn btn-danger">Randevuyu İptal Et</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>';

                    // Report/Prescription Modal
                    echo '
                    <div class="modal fade" id="reportModal-' . $appointment->id . '" tabindex="-1" aria-labelledby="reportModalLabel-' . $appointment->id . '" aria-hidden="true">
                      <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                          <form action="" method="POST">
                            <div class="modal-header bg-primary text-white">
                              <h5 class="modal-title" id="reportModalLabel-' . $appointment->id . '">Rapor / Reçete Yaz</h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                              <p><strong>' . format_string($appointment->patient_firstname . ' ' . $appointment->patient_lastname) . '</strong> adlı hasta için <strong>' . userdate(strtotime($appointment->appointment_time), get_string('strftimedatetime', 'langconfig')) . '</strong> tarihli randevu.</p>
                              <div class="mb-3">
                                <label for="reporttype-' . $appointment->id . '" class="form-label">Belge Türü:</label>
                                <select class="form-select" id="reporttype-' . $appointment->id . '" name="reporttype" required>
                                  <option value="">Seçiniz...</option>
                                  <option value="reçete">Reçete</option>
                                  <option value="rapor">Rapor</option>
                                  <option value="tetkik_sonucu">Tetkik Sonucu</option>
                                  <option value="sevk">Sevk Belgesi</option>
                                </select>
                              </div>
                              <div class="mb-3">
                                <label for="reportcontent-' . $appointment->id . '" class="form-label">İçerik:</label>
                                <textarea class="form-control" id="reportcontent-' . $appointment->id . '" name="reportcontent" rows="10" required></textarea>
                              </div>
                              <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" value="1" id="is_patient_viewable-' . $appointment->id . '" name="is_patient_viewable">
                                <label class="form-check-label" for="is_patient_viewable-' . $appointment->id . '">
                                  Bu raporu hastanın kendi ekranında görünür yap.
                                </label>
                              </div>
                              <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" value="1" id="is_shared_with_other_doctors_in_polyclinic-' . $appointment->id . '" name="is_shared_with_other_doctors_in_polyclinic">
                                <label class="form-check-label" for="is_shared_with_other_doctors_in_polyclinic-' . $appointment->id . '">
                                  Bu raporu kendi polikliniğimdeki diğer doktorlarla paylaş.
                                </label>
                              </div>
                              <input type="hidden" name="report_appointmentid" value="' . $appointment->id . '">
                              <input type="hidden" name="sesskey" value="' . sesskey() . '">
                            </div>
                            <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                              <button type="submit" name="submit_report" class="btn btn-primary">Kaydet</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>';
                }
            }

            // Doctor's Report View Modals (for reports they wrote)
            if (!empty($all_reports_for_modals)) {
                foreach ($all_reports_for_modals as $report) {
                    // Adjusted to get the doctor who wrote the report, not necessarily the current user.
                    $writer_doctor_record = $DB->get_record('doctors', ['id' => $report->doctor_id]);
                    $writer_doctor_user = $DB->get_record('user', ['id' => $writer_doctor_record->user_id]);
                    $patient_of_report = $DB->get_record('user', ['id' => $report->patient_user_id]);
                    $polyclinic_of_report = $DB->get_record('polyclinics', ['id' => $report->polyclinic_id]);

                    $modal_access_status_text = '';
                    if ($report->is_patient_viewable) {
                        $modal_access_status_text = 'Hastaya Açık (Tüm Doktorlar Görüyor)';
                    } else if ($report->is_shared_with_other_doctors_in_polyclinic) {
                        $modal_access_status_text = 'Paylaşıldı (Tüm Doktorlar Görüyor)';
                    } else {
                        $modal_access_status_text = 'Gizli (Sadece Yazan Doktor)';
                    }


                    echo '
                    <div class="modal fade" id="viewReportModal-' . $report->id . '" tabindex="-1" aria-labelledby="viewReportModalLabel-' . $report->id . '" aria-hidden="true">
                      <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                          <div class="modal-header bg-info text-white">
                            <h5 class="modal-title" id="viewReportModalLabel-' . $report->id . '">Rapor Detayı: ' . format_string($report->report_type) . '</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <p><strong>Yazan Doktor:</strong> ' . format_string(fullname($writer_doctor_user)) . '</p>
                            <p><strong>Hasta Adı:</strong> ' . format_string(fullname($patient_of_report)) . '</p>
                            <p><strong>Poliklinik:</strong> ' . format_string($polyclinic_of_report->name) . '</p>
                            <p><strong>Tarih:</strong> ' . userdate(strtotime($report->created_at), get_string('strftimedatetime', 'langconfig')) . '</p>
                            <hr>
                            <h4>İçerik:</h4>
                            <div class="report-full-content">' . format_text($report->report_content, FORMAT_HTML) . '</div>
                          </div>
                          <div class="modal-footer">
                            <span class="text-muted">Rapor erişim durumu: ' . $modal_access_status_text . '</span>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                          </div>
                        </div>
                      </div>
                    </div>';
                }
            }

            // Modal for Patient Reports (fetched via AJAX)
            echo '
            <div class="modal fade" id="patientReportsModal" tabindex="-1" aria-labelledby="patientReportsModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-xl">
                <div class="modal-content">
                  <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="patientReportsModalLabel">Hastanın Raporları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body" id="patientReportsModalBody">
                    <p class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Raporlar yükleniyor...</p>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                  </div>
                </div>
              </div>
            </div>';

            // Bootstrap JavaScript for modals and custom JS for AJAX
            echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>';
            echo '<script>
                // Function for filtering doctor\'s own reports
                document.getElementById("reportSearch").addEventListener("keyup", function() {
                    let searchValue = this.value.toLowerCase();
                    let table = document.getElementById("doctorReportsTable");
                    let rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");

                    for (let i = 0; i < rows.length; i++) {
                        let row = rows[i];
                        let patientName = row.getAttribute("data-patient-name").toLowerCase();
                        let reportType = row.getAttribute("data-report-type").toLowerCase();

                        if (patientName.includes(searchValue) || reportType.includes(searchValue)) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    }
                });

                function showPatientReports(patientId, polyclinicId, sesskey) {
                    const modalBody = document.getElementById("patientReportsModalBody");
                    modalBody.innerHTML = \'<p class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Raporlar yükleniyor...</p>\';

                    var patientReportsModal = new bootstrap.Modal(document.getElementById("patientReportsModal"), {});
                    patientReportsModal.show();

                    fetch(\'' . $CFG->wwwroot . '/my/ajax_get_patient_reports.php?patientid=\' + patientId + \'&polyclinicid=\' + polyclinicId + \'&sesskey=\' + sesskey)
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === "success") {
                                let reportsHtml = "";
                                if (data.reports.length > 0) {
                                    reportsHtml += \'<div class="table-responsive">\';
                                    reportsHtml += \'<table class="table table-hover table-bordered">\';
                                    reportsHtml += \'<thead class="table-light">\';
                                    reportsHtml += \'<tr><th scope="col">Tarih</th><th scope="col">Doktor</th><th scope="col">Poliklinik</th><th scope="col">Rapor Türü</th><th scope="col">Erişim Durumu</th><th scope="col">İçerik</th></tr>\';
                                    reportsHtml += \'</thead><tbody>\';
                                    data.reports.forEach(report => {
                                        let reportTypeDisplay = "";
                                        let accessStatusText = "";
                                        let accessStatusClass = "";

                                        switch (report.report_type) {
                                            case "reçete": reportTypeDisplay = \'<span class="badge bg-info">Reçete</span>\'; break;
                                            case "rapor": reportTypeDisplay = \'<span class="badge bg-secondary">Rapor</span>\'; break;
                                            case "tetkik_sonucu": reportTypeDisplay = \'<span class="badge bg-dark">Tetkik Sonucu</span>\'; break;
                                            case "sevk": reportTypeDisplay = \'<span class="badge bg-warning text-dark">Sevk Belgesi</span>\'; break;
                                            case "iptal_bildirimi": reportTypeDisplay = \'<span class="badge bg-danger">İptal Bildirimi</span>\'; break;
                                            default: reportTypeDisplay = \'<span class="badge bg-light text-dark">\' + report.report_type + \'</span>\'; break;
                                        }

                                        if (report.is_patient_viewable == 1) {
                                            accessStatusText = "Hastaya Açık (Tüm Doktorlar Görüyor)";
                                            accessStatusClass = "bg-success";
                                        } else if (report.is_shared_with_other_doctors_in_polyclinic == 1) {
                                            accessStatusText = "Paylaşıldı (Tüm Doktorlar Görüyor)";
                                            accessStatusClass = "bg-warning text-dark";
                                        } else {
                                            accessStatusText = "Gizli (Sadece Yazan Doktor)";
                                            accessStatusClass = "bg-dark";
                                        }
                                        let accessStatus = \'<span class="badge \' + accessStatusClass + \'">\' + accessStatusText + \'</span>\';

                                        reportsHtml += \'<tr>\';
                                        reportsHtml += \'<td>\' + new Date(report.created_at * 1000).toLocaleDateString() + \'</td>\'; // Unix timestamp to readable date
                                        reportsHtml += \'<td>\' + report.doctor_firstname + \' \' + report.doctor_lastname + \'</td>\';
                                        reportsHtml += \'<td>\' + report.polyclinic_name + \'</td>\';
                                        reportsHtml += \'<td>\' + reportTypeDisplay + \'</td>\';
                                        reportsHtml += \'<td>\' + accessStatus + \'</td>\';
                                        reportsHtml += \'<td>\' + report.report_content + \'</td>\';
                                        reportsHtml += \'</tr>\';
                                    });
                                    reportsHtml += \'</tbody></table>\';
                                    reportsHtml += \'</div>\';
                                } else {
                                    reportsHtml = \'<p class="alert alert-info text-center">Bu hasta için görüntülenebilir rapor bulunmamaktadır.</p>\';
                                }
                                modalBody.innerHTML = reportsHtml;
                            } else {
                                modalBody.innerHTML = \'<p class="alert alert-danger text-center">Raporlar yüklenirken bir hata oluştu: \' + data.message + \'</p>\';
                            }
                        })
                        .catch(error => {
                            console.error(\'Error fetching patient reports:\', error);
                            modalBody.innerHTML = \'<p class="alert alert-danger text-center">Raporlar yüklenirken bir ağ hatası oluştu.</p>\';
                        });
                }
            </script>';

            echo $OUTPUT->footer();
            exit;
        }

        // If the user is NOT a doctor, then display the regular Moodle content.
        $patient_reports_html = '';
        $is_patient = ! $is_doctor; // Zaten doktor değilse hastadır.

        if ($is_patient) {
            $patient_id = $USER->id;

            // fetch all reports for this patient that are either public or written by the current user (if they are also a doctor)
            // or shared with other doctors in the same polyclinic (if current user is a doctor in that polyclinic)
            $params = [];
            $sql_where_clauses = [];

            // Eğer mevcut kullanıcı aynı zamanda doktorsa, kendi yazdığı veya aynı polikliniğinde paylaşılan raporları da görebilmeli.
            $current_doctor_record = null;
            $current_doctor_id = 0;
            if (has_capability('moodle/site:viewmypages', $context) && $DB->record_exists('doctors', ['user_id' => $USER->id])) {
                $current_doctor_record = $DB->get_record('doctors', ['user_id' => $USER->id]);
                if ($current_doctor_record) {
                    $current_doctor_id = $current_doctor_record->id;
                }
            }

            if ($current_doctor_id > 0) {
                // Eğer kullanıcı hem hasta hem doktorsa:
                // 1. Kendisine yazılmış ve hastaya açık olanlar (r.patient_user_id = ? AND r.is_patient_viewable = 1)
                // 2. Kendi yazdığı raporlar (r.doctor_id = ?)
                // 3. Kendi polikliniğindeki paylaşılan raporlar (r.is_shared_with_other_doctors_in_polyclinic = 1 AND r.polyclinic_id = ?)
                $sql_where_clauses[] = "(r.patient_user_id = ? AND r.is_patient_viewable = 1)";
                $params[] = $patient_id; // r.patient_user_id = ? için

                $sql_where_clauses[] = "(r.doctor_id = ?)"; // Mevcut doktor kendi yazdığı raporları görmeli
                $params[] = $current_doctor_id; // r.doctor_id = ? için

                $sql_where_clauses[] = "(r.is_shared_with_other_doctors_in_polyclinic = 1 AND r.polyclinic_id = ?)"; // Kendi polikliniğindeki paylaşılan raporlar
                $params[] = $current_doctor_record->polyclinic_id; // r.polyclinic_id = ? için

                $final_where_clause = "(" . implode(" OR ", $sql_where_clauses) . ")";

            } else {
                // Eğer kullanıcı sadece hastaysa:
                // Sadece kendisine yazılmış ve hastaya açık raporları görebilir.
                $final_where_clause = "(r.patient_user_id = ? AND r.is_patient_viewable = 1)";
                $params[] = $patient_id; // r.patient_user_id = ? için
            }


            $patient_reports = $DB->get_records_sql("
                SELECT r.id, r.report_type, r.report_content, r.created_at, r.is_patient_viewable, r.doctor_id, r.polyclinic_id, r.is_shared_with_other_doctors_in_polyclinic,
                       du.firstname AS doctor_firstname, du.lastname AS doctor_lastname,
                       p.name AS polyclinic_name
                FROM {reports} r
                JOIN {doctors} d ON r.doctor_id = d.id
                JOIN {user} du ON d.user_id = du.id
                JOIN {polyclinics} p ON r.polyclinic_id = p.id
                WHERE $final_where_clause
                ORDER BY r.created_at DESC
            ", $params);


            $patient_reports_html .= '<div class="card shadow-sm mb-4">';
            $patient_reports_html .= '<div class="card-header bg-info text-white">';
            $patient_reports_html .= '<h3 class="mb-0">Size Yazılan Raporlar ve Reçeteler</h3>';
            $patient_reports_html .= '</div>';
            $patient_reports_html .= '<div class="card-body">';
            $patient_reports_html .= '<p class="text-muted mb-4">Size yazılmış raporlarınızı ve reçetelerinizi buradan görüntüleyebilirsiniz.</p>';
            $patient_reports_html .= '<div class="table-responsive">';
            $patient_reports_html .= '<table class="table table-hover table-bordered caption-top">';
            $patient_reports_html .= '<caption>Geçmiş Raporlarınız</caption>';
            $patient_reports_html .= '<thead class="table-light">';
            $patient_reports_html .= '<tr>';
            $patient_reports_html .= '<th scope="col">Tarih</th>';
            $patient_reports_html .= '<th scope="col">Doktor</th>';
            $patient_reports_html .= '<th scope="col">Poliklinik</th>';
            $patient_reports_html .= '<th scope="col">Rapor Türü</th>';
            $patient_reports_html .= '<th scope="col">Erişim Durumu</th>';
            $patient_reports_html .= '<th scope="col">İşlemler</th>';
            $patient_reports_html .= '</tr></thead><tbody>';

            if (empty($patient_reports)) {
                $patient_reports_html .= '<tr><td colspan="6" class="text-center py-4 text-muted">';
                $patient_reports_html .= '<i class="fa fa-file-o me-2" aria-hidden="true"></i> Henüz size yazılmış bir rapor veya reçete bulunmamaktadır.';
                $patient_reports_html .= '</td></tr>';
            } else {
                foreach ($patient_reports as $report) {
                    $formatted_report_date = userdate(strtotime($report->created_at), get_string('strftimedate', 'langconfig'));
                    $doctor_full_name = format_string($report->doctor_firstname . ' ' . $report->doctor_lastname);
                    $report_type_display = '';
                    switch ($report->report_type) {
                        case 'reçete': $report_type_display = '<span class="badge bg-info">Reçete</span>'; break;
                        case 'rapor': $report_type_display = '<span class="badge bg-secondary">Rapor</span>'; break;
                        case 'tetkik_sonucu': $report_type_display = '<span class="badge bg-dark">Tetkik Sonucu</span>'; break;
                        case 'sevk': $report_type_display = '<span class="badge bg-warning text-dark">Sevk Belgesi</span>'; break;
                        case 'iptal_bildirimi': $report_type_display = '<span class="badge bg-danger">İptal Bildirimi</span>'; break;
                        default: $report_type_display = '<span class="badge bg-light text-dark">' . $report->report_type . '</span>'; break;
                    }

                    // ERIŞIM DURUMU BURADA DÜZELTİLDİ: is_patient_viewable ve is_shared_with_other_doctors_in_polyclinic baz alınarak
                    $access_status_text = '';
                    $access_status_class = '';

                    if ($report->is_patient_viewable) {
                        $access_status_text = 'Hastaya Açık (Tüm Doktorlar Görüyor)';
                        $access_status_class = 'bg-success';
                    } else if ($report->is_shared_with_other_doctors_in_polyclinic) {
                        $access_status_text = 'Paylaşıldı (Tüm Doktorlar Görüyor)';
                        $access_status_class = 'bg-warning text-dark';
                    } else {
                        $access_status_text = 'Gizli (Sadece Yazan Doktor)';
                        $access_status_class = 'bg-dark';
                    }
                    $access_status = '<span class="badge ' . $access_status_class . '">' . $access_status_text . '</span>';


                    $actions_html = '';
                    $can_view_report_as_patient_or_doctor = false;

                    // Kriter 1: Rapor hastaya görünür ise (is_patient_viewable = 1) hasta ve doktor görebilir.
                    if ($report->is_patient_viewable) {
                        $can_view_report_as_patient_or_doctor = true;
                    }
                    // Kriter 2: Raporu yazan doktor ise o doktor görebilir.
                    if ($is_doctor && $report->doctor_id == $current_doctor_id) {
                        $can_view_report_as_patient_or_doctor = true;
                    }
                    // Kriter 3: Raporun yazıldığı hasta ise hasta görebilir. (Zaten is_patient_viewable ile kontrol ediliyor)
                    if ($report->patient_user_id == $USER->id && $report->is_patient_viewable) {
                         $can_view_report_as_patient_or_doctor = true;
                    }
                    // Kriter 4: Rapor gizli (is_patient_viewable = 0) ancak aynı poliklinikteki diğer doktorlarla paylaşılmışsa
                    // (is_shared_with_other_doctors_in_polyclinic = 1) ve mevcut kullanıcı aynı poliklinikte bir doktorsa görebilir.
                    // This logic was slightly off in previous version, now updated based on 'all doctors' view
                    // If a report is shared with other doctors in polyclinic (is_shared_with_other_doctors_in_polyclinic = 1),
                    // and the current user is a doctor (regardless of polyclinic for viewing 'shared' reports), they should see it.
                    if ($report->is_shared_with_other_doctors_in_polyclinic && $is_doctor) { // Removed polyclinic_id check
                        $can_view_report_as_patient_or_doctor = true;
                    }


                    if ($can_view_report_as_patient_or_doctor) {
                        $actions_html .= '<button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewPatientReportModal-' . $report->id . '">';
                        $actions_html .= '<i class="fa fa-eye" aria-hidden="true"></i> Görüntüle';
                        $actions_html .= '</button>';
                        // Bu raporu modal oluşturmak için ekle
                        $patient_reports_for_modals[$report->id] = $report;
                    } else {
                        $actions_html .= '<span class="text-muted"><i class="fa fa-lock" aria-hidden="true"></i> Erişim Yok</span>';
                    }


                    $patient_reports_html .= '<tr>';
                    $patient_reports_html .= '<td>' . $formatted_report_date . '</td>';
                    $patient_reports_html .= '<td>' . $doctor_full_name . '</td>';
                    $patient_reports_html .= '<td>' . format_string($report->polyclinic_name) . '</td>';
                    $patient_reports_html .= '<td>' . $report_type_display . '</td>';
                    $patient_reports_html .= '<td>' . $access_status . '</td>';
                    $patient_reports_html .= '<td>' . $actions_html . '</td>';
                    $patient_reports_html .= '</tr>';
                }
            }
            $patient_reports_html .= '</tbody></table>';
            $patient_reports_html .= '</div>';
            $patient_reports_html .= '</div>';
            $patient_reports_html .= '</div>';

            echo '<div class="container-fluid my-4">';
            echo $patient_reports_html;
            echo '</div>';

            // Patient's Report View Modals (for reports they can view)
            if (!empty($patient_reports_for_modals)) {
                foreach ($patient_reports_for_modals as $report) {
                    $doctor_of_report = $DB->get_record('doctors', ['id' => $report->doctor_id]);
                    $doctor_user_of_report = $DB->get_record('user', ['id' => $doctor_of_report->user_id]);
                    $patient_of_report_modal = $DB->get_record('user', ['id' => $report->patient_user_id]);
                    $polyclinic_of_report = $DB->get_record('polyclinics', ['id' => $report->polyclinic_id]);

                    $modal_access_status_text = '';
                    if ($report->is_patient_viewable) {
                        $modal_access_status_text = 'Hastaya Açık (Tüm Doktorlar Görüyor)';
                    } else if ($report->is_shared_with_other_doctors_in_polyclinic) {
                        $modal_access_status_text = 'Paylaşıldı (Tüm Doktorlar Görüyor)';
                    } else {
                        $modal_access_status_text = 'Gizli (Sadece Yazan Doktor)';
                    }

                    echo '
                    <div class="modal fade" id="viewPatientReportModal-' . $report->id . '" tabindex="-1" aria-labelledby="viewPatientReportModalLabel-' . $report->id . '" aria-hidden="true">
                      <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                          <div class="modal-header bg-info text-white">
                            <h5 class="modal-title" id="viewPatientReportModalLabel-' . $report->id . '">Rapor Detayı: ' . format_string($report->report_type) . '</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <p><strong>Yazan Doktor:</strong> ' . format_string(fullname($doctor_user_of_report)) . '</p>
                            <p><strong>Hasta Adı:</strong> ' . format_string(fullname($patient_of_report_modal)) . '</p>
                            <p><strong>Poliklinik:</strong> ' . format_string($polyclinic_of_report->name) . '</p>
                            <p><strong>Tarih:</strong> ' . userdate(strtotime($report->created_at), get_string('strftimedatetime', 'langconfig')) . '</p>
                            <hr>
                            <h4>İçerik:</h4>
                            <div class="report-full-content">' . format_text($report->report_content, FORMAT_HTML) . '</div>
                          </div>
                          <div class="modal-footer">
                            <span class="text-muted">Rapor erişim durumu: ' . $modal_access_status_text . '</span>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                          </div>
                        </div>
                      </div>
                    </div>';
                }
            }

            // Bootstrap JavaScript for modals
            echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>';
        }


        echo $OUTPUT->custom_block_region('content');

        echo $OUTPUT->footer();

        // Trigger dashboard has been viewed event.
        $eventparams = array('context' => $context);
        $event = \core\event\mycourses_viewed::create($eventparams);
        $event->trigger();