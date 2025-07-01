<?php
require_once('../../config.php');
require_login(); // Moodle oturumunu başlat ve giriş yapılmasını sağla

// Sayfa bağlamını sistem olarak ayarla
$context = context_system::instance();
$PAGE->set_context($context);

// Geçici olarak bu URL'yi ayarlayın, sonra dinamik olarak güncellenecek.
$PAGE->set_url(new moodle_url('/local/useradmin/edit_chief.php'));

// Yalnızca site yöneticilerinin erişimine izin ver
if (!is_siteadmin()) {
    echo $OUTPUT->notification('Bu sayfaya erişim yetkiniz yok.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// URL'den başhekim ID'sini al
// Bu ID, 'chiefdoctors' tablosundaki 'id' alanına karşılık gelmeli.
$chiefid = optional_param('id', 0, PARAM_INT);

// Eğer başhekim ID'si gelmediyse hata ver ve yönlendir.
if (!$chiefid) {
    redirect(new moodle_url('/local/useradmin/index.php?tab=chiefs'), get_string('invalidparam', 'error'));
}

// Başhekim kaydını 'chiefdoctors' tablosundan kendi ID'si ile al
$chief = $DB->get_record('chiefdoctors', ['id' => $chiefid]);
if (!$chief) {
    redirect(new moodle_url('/local/useradmin/index.php?tab=chiefs'), get_string('invaliduser', 'local_useradmin'));
}

// Başhekim kaydından Moodle user_id'sini al
$userid = $chief->user_id;

// Moodle kullanıcı bilgilerini al (user_id ile)
$user = $DB->get_record('user', ['id' => $userid]);
if (!$user) {
    redirect(new moodle_url('/local/useradmin/index.php?tab=chiefs'), get_string('invaliduser', 'local_useradmin'));
}

// Hastane kaydını al
$hospital = $DB->get_record('hospitals', ['id' => $chief->hospital_id]);
if (!$hospital) {
    redirect(new moodle_url('/local/useradmin/index.php?tab=chiefs'), get_string('invalidhospital', 'local_useradmin'));
}

// Tüm hastaneler formda seçim için
$hospitals = $DB->get_records('hospitals');

// POST isteği işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = required_param('firstname', PARAM_TEXT);
    $lastname = required_param('lastname', PARAM_TEXT);
    $email = required_param('email', PARAM_EMAIL);
    $hospital_id = required_param('hospital_id', PARAM_INT);

    // Kullanıcı bilgilerini güncelle
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;

    // Kullanıcı güncelleme işlemi
    $DB->update_record('user', $user);

    // Başhekim kaydını güncelle
    $chief->hospital_id = $hospital_id;
    $DB->update_record('chiefdoctors', $chief);

    // Başarılı mesajı ile yönlendirme
    redirect(new moodle_url('/local/useradmin/index.php?tab=chiefs'), get_string('chiefsuccessfullyupdated', 'local_useradmin'));
}

// Sayfa başlığını ve başlığı ayarla
$PAGE->set_title(get_string('editchief', 'local_useradmin', $user->firstname . ' ' . $user->lastname));
$PAGE->set_heading(get_string('editchief', 'local_useradmin', $user->firstname . ' ' . $user->lastname));

echo $OUTPUT->header();
?>

<form method="post" action="">
    <div class="mb-3">
        <label for="firstname">Ad</label>
        <input type="text" name="firstname" id="firstname" value="<?php echo htmlspecialchars($user->firstname) ?>" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="lastname">Soyad</label>
        <input type="text" name="lastname" id="lastname" value="<?php echo htmlspecialchars($user->lastname) ?>" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="email">E-posta</label>
        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($user->email) ?>" class="form-control" required>
    </div>
    <div class="mb-3">
        <label for="hospital_id">Hastane</label>
        <select name="hospital_id" id="hospital_id" class="form-select" required>
            <?php foreach ($hospitals as $h): ?>
                <option value="<?php echo $h->id ?>" <?php if ($h->id == $chief->hospital_id) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($h->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Güncelle</button>
</form>

<?php
echo $OUTPUT->footer();
