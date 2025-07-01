<?php
require_once('../../config.php');
require_login(); // Moodle oturumunu başlat ve giriş yapılmasını sağla

// Sayfa bağlamını sistem olarak ayarla
$context = context_system::instance();
$PAGE->set_context($context);

// Geçici olarak bu URL'yi ayarlayın, onay ekranı için dinamik olarak güncellenecek.
$PAGE->set_url(new moodle_url('/local/useradmin/edit_doctor.php'));

// Yalnızca site yöneticilerinin erişimine izin ver
if (!is_siteadmin()) {
    echo $OUTPUT->notification('Bu sayfaya erişim yetkiniz yok.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// URL'den doktor ID'sini al
$doctorid = optional_param('id', 0, PARAM_INT);

if (!$doctorid) {
    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Geçersiz parametre sağlandı.', null, \core\output\notification::NOTIFY_ERROR);
}

$doctor = $DB->get_record('doctors', ['id' => $doctorid]);
if (!$doctor) {
    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Doktor kaydı bulunamadı.', null, \core\output\notification::NOTIFY_ERROR);
}

$userid = $doctor->user_id;

$user = $DB->get_record('user', ['id' => $userid]);
if (!$user) {
    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Kullanıcı bulunamadı veya geçersiz.', null, \core\output\notification::NOTIFY_ERROR);
}

$hospital = $DB->get_record('hospitals', ['id' => $doctor->hospital_id]);
if (!$hospital) {
    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Hastane bulunamadı veya geçersiz.', null, \core\output\notification::NOTIFY_ERROR);
}

$polyclinic = $DB->get_record('polyclinics', ['id' => $doctor->polyclinic_id]);
if (!$polyclinic) {
    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Poliklinik bulunamadı veya geçersiz.', null, \core\output\notification::NOTIFY_ERROR);
}

// Tüm hastaneler ve poliklinikler formda seçim için
$hospitals = $DB->get_records('hospitals');
$all_polyclinics = $DB->get_records('polyclinics'); // JavaScript için tüm poliklinikleri çekiyoruz

// POST isteği işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = required_param('firstname', PARAM_TEXT);
    $lastname = required_param('lastname', PARAM_TEXT);
    $email = required_param('email', PARAM_EMAIL);
    $hospital_id = required_param('hospital_id', PARAM_INT);
    $polyclinic_id = required_param('polyclinic_id', PARAM_INT);

    // Kullanıcı bilgilerini güncelle
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;

    // Kullanıcı güncelleme işlemi
    $DB->update_record('user', $user);

    // Doktor kaydını güncelle
    $doctor->hospital_id = $hospital_id;
    $doctor->polyclinic_id = $polyclinic_id;
    $DB->update_record('doctors', $doctor);

    // Düzeltildi: \core\output\notification sınıfı kullanıldı.
    redirect(new moodle_url('/local/useradmin/index.php?tab=doctors'), 'Doktor bilgileri başarıyla güncellendi.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Sayfa başlığını ve başlığını doğrudan metin olarak ayarla
$PAGE->set_title("Doktor Düzenle: ");
$PAGE->set_heading("Doktor Düzenle: " . htmlspecialchars($user->firstname . ' ' . $user->lastname));

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
                <option value="<?php echo $h->id ?>" <?php if ($h->id == $doctor->hospital_id) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($h->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="polyclinic_id">Poliklinik</label>
        <select name="polyclinic_id" id="polyclinic_id" class="form-select" required>
            <option value="">Lütfen hastane seçin</option>
            <?php
            foreach ($all_polyclinics as $poly) {
                $selected = ($poly->id == $doctor->polyclinic_id) ? 'selected' : '';
                $hidden = ($poly->hospital_id != $doctor->hospital_id) ? 'hidden' : '';
                echo '<option value="' . $poly->id . '" data-hospital-id="' . $poly->hospital_id . '" ' . $selected . ' ' . $hidden . '>' . htmlspecialchars($poly->name) . '</option>';
            }
            ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Güncelle</button>
</form>

<script type="text/javascript">
    require(['jquery'], function($) {
        $(document).ready(function() {
            var $hospitalSelect = $('#hospital_id');
            var $polyclinicSelect = $('#polyclinic_id');
            var initialPolyclinicId = <?php echo $doctor->polyclinic_id; ?>;

            function filterPolyclinics(selectedHospitalId) {
                var foundSelected = false;
                $polyclinicSelect.find('option').each(function() {
                    var polyHospitalId = $(this).data('hospital-id');
                    if (polyHospitalId == selectedHospitalId) {
                        $(this).show();
                        if (!foundSelected && $(this).val() == initialPolyclinicId) {
                            $(this).prop('selected', true);
                            foundSelected = true;
                        }
                    } else {
                        $(this).hide();
                    }
                });

                if (!foundSelected || $polyclinicSelect.find('option:selected:visible').length === 0) {
                    var firstVisibleOption = $polyclinicSelect.find('option:visible').not(':first').first();
                    if (firstVisibleOption.length > 0) {
                        firstVisibleOption.prop('selected', true);
                    } else {
                        $polyclinicSelect.val('');
                    }
                }
            }

            filterPolyclinics($hospitalSelect.val());

            $hospitalSelect.on('change', function() {
                var selectedHospitalId = $(this).val();
                initialPolyclinicId = null;
                filterPolyclinics(selectedHospitalId);
            });
        });
    });
</script>

<?php
echo $OUTPUT->footer();
?>