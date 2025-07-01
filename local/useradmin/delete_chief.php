<?php
require_once('../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$chief_id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if (empty($chief_id)) {
    redirect(new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs']),
        get_string('invalidparam', 'error'), null, 'error');
}

// chiefdoctors tablosundan user_id'yi al
$chiefrecord = $DB->get_record('chiefdoctors', ['id' => $chief_id]);

if (!$chiefrecord) {
    redirect(new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs']),
        get_string('invalidchiefrecord', 'local_useradmin'), null, 'error');
}

$user_id = $chiefrecord->user_id;

if (!$user = $DB->get_record('user', ['id' => $user_id])) {
    redirect(new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs']),
        get_string('invaliduser', 'local_useradmin'), null, 'error');
}

$PAGE->set_url(new moodle_url('/local/useradmin/delete_chief.php', ['id' => $chief_id]));

if (!$confirm) {
    $fullname = fullname($user);
    $PAGE->set_title(get_string('confirmdeleteuser', 'local_useradmin', $fullname));
    $PAGE->set_heading(get_string('confirmdeleteuser', 'local_useradmin', $fullname));
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletechief', 'local_useradmin', $fullname),
        new moodle_url('/local/useradmin/delete_chief.php', ['id' => $chief_id, 'confirm' => 1]),
        new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs'])
    );
    echo $OUTPUT->footer();
    exit;
}

try {
    // chiefdoctors tablosundaki kaydı sil
    $DB->delete_records('chiefdoctors', ['id' => $chief_id]);

    // Moodle kullanıcı kaydını sil
    if (!delete_user($user)) {
        throw new moodle_exception('errordeletinguser', 'local_useradmin', '', fullname($user));
    }

    redirect(new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs']),
        get_string('chiefsuccessfullydeleted', 'local_useradmin'), null, 'success');

} catch (Exception $e) {
    redirect(new moodle_url('/local/useradmin/index.php', ['tab' => 'chiefs']),
        get_string('errordeletingchief', 'local_useradmin', fullname($user)) . ': ' . $e->getMessage(),
        null,
        'error');
}
