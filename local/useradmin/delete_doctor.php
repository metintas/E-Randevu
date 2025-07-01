<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

// Sayfa bilgileri
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// Geçersiz ID kontrolü
if (empty($id)) {
    redirect(
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors']),
        get_string('invalidparam', 'error'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Doktor kaydını getir
if (!$doctor = $DB->get_record('doctors', ['id' => $id])) {
    redirect(
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors']),
        get_string('invaliduser', 'local_useradmin'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// İlişkili kullanıcıyı getir
$userid = $doctor->user_id;

if (!$user = $DB->get_record('user', ['id' => $userid])) {
    redirect(
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors']),
        get_string('invaliduser', 'local_useradmin'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Sayfa başlık ayarları
$PAGE->set_url(new moodle_url('/local/useradmin/delete_doctor.php', ['id' => $id]));
$PAGE->set_title("Delete Doctor");

// Onay ekranı
if (!$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletedoctor', 'local_useradmin', fullname($user)),
        new moodle_url('/local/useradmin/delete_doctor.php', ['id' => $id, 'confirm' => 1]),
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors'])
    );
    echo $OUTPUT->footer();
    exit;
}

// Silme işlemi
try {
    // İlişkili randevuları sil
    $DB->delete_records('appointments', ['doctor_id' => $id]);

    // İlişkili slotları sil
    $DB->delete_records('appointment_slots', ['doctor_id' => $id]);

    // Doktor kaydını sil
    $DB->delete_records('doctors', ['id' => $id]);

    // Kullanıcıyı sil
    if (!user_delete_user($user)) {
        throw new moodle_exception('errordeletinguser', 'local_useradmin', '', fullname($user));
    }

    redirect(
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors']),
        get_string('doctorsuccessfullydeleted', 'local_useradmin'),
        \core\output\notification::NOTIFY_SUCCESS
    );

} catch (Exception $e) {
    redirect(
        new moodle_url('/local/useradmin/index.php', ['tab' => 'doctors']),
        get_string('errordeletingdoctor', 'local_useradmin', fullname($user)) . ': ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
