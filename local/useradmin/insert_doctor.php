<?php
// Moodle'ın ana yapılandırma dosyasını dahil et.
require_once('../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_login();

// Sayfa başlığı ve yapılandırmaları **her zaman en başta** yapılmalı
$PAGE->set_url(new moodle_url('/local/useradmin/insert_doctor.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Doktor Ekle");
$PAGE->set_heading("Doktor Ekle");

// Sadece admin erişebilsin
if (!is_siteadmin()) {
    die('Bu işlemi yapmaya yetkiniz yok.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $DB;

    $username = required_param('username', PARAM_USERNAME);
    $firstname = required_param('firstname', PARAM_NOTAGS);
    $lastname = required_param('lastname', PARAM_NOTAGS);
    $password = required_param('password', PARAM_RAW);
    $email = required_param('email', PARAM_EMAIL);
    $hospital_id = required_param('hospital_id', PARAM_INT);

    // polyclinic_name her zaman gönderilir, polyclinic_id ise mevcut seçildiyse
    $polyclinic_name_from_form = required_param('polyclinic_name', PARAM_TEXT);
    // polyclinic_id'yi optional yapıyoruz çünkü 'yeni' seçeneğinde boş gelebilir
    $polyclinic_id_from_form = optional_param('polyclinic_id', 0, PARAM_INT);


    // Kullanıcı nesnesi oluştur
    $user = new stdClass();
    $user->username     = $username;
    $user->firstname    = $firstname;
    $user->lastname     = $lastname;
    $user->email        = $email;
    $user->confirmed    = 1;
    $user->auth         = 'manual';
    $user->mnethostid   = $CFG->mnet_localhost_id;
    $user->lang         = current_language();
    $user->timezone     = $CFG->timezone;
    $user->country      = 'TR';
    $user->city         = '';
    $user->password     = $password;

    // Zorunlu ama atanmayan alanlara varsayılan değerler atayın
    $user->idnumber = '';
    $user->phone1 = '';
    $user->phone2 = '';
    $user->institution = '';
    $user->department = '';
    $user->address = '';
    $user->theme = '';
    $user->secret = '';
    $user->description = '';
    $user->mailformat = 1;
    $user->maildigest = 0;
    $user->maildisplay = 2;
    $user->autosubscribe = 1;
    $user->trackforums = 0;
    $user->timecreated = time();
    $user->timemodified = time();
    $user->trustbitmask = 0;
    $user->picture = 0;

    try {
        // Kullanıcı oluştur
        $newuserid = user_create_user($user, true, false);

        // Rol atama (doktor rolü id = 5)
        $roleid = 5;
        $context = context_system::instance();
        role_assign($roleid, $newuserid, $context);

        // Polikliniği kontrol et ve/veya oluştur
        // Eğer mevcut poliklinik ID'si gönderildiyse ve geçerliyse, onu kullan.
        // Aksi takdirde, poliklinik adını kullanarak arama yap veya yeni oluştur.
        $polyclinic_id_to_use = null;

        if ($polyclinic_id_from_form > 0) {
            // Mevcut bir poliklinik ID'si gönderilmişse, geçerliliğini kontrol et
            $existing_polyclinic_by_id = $DB->get_record('polyclinics', ['id' => $polyclinic_id_from_form, 'hospital_id' => $hospital_id]);
            if ($existing_polyclinic_by_id) {
                $polyclinic_id_to_use = $existing_polyclinic_by_id->id;
            }
        }

        if ($polyclinic_id_to_use === null) {
            // ID ile bulunamadı veya ID gönderilmedi (yeni poliklinik durumu)
            // Poliklinik adıyla arama yap
            $polyclinic_record = $DB->get_record(
                'polyclinics',
                ['hospital_id' => $hospital_id, 'name' => $polyclinic_name_from_form]
            );

            if ($polyclinic_record) {
                $polyclinic_id_to_use = $polyclinic_record->id;
            } else {
                // Poliklinik yoksa, yeni bir kayıt oluştur
                $new_polyclinic = new stdClass();
                $new_polyclinic->hospital_id = $hospital_id;
                $new_polyclinic->name = $polyclinic_name_from_form;
                $polyclinic_id_to_use = $DB->insert_record('polyclinics', $new_polyclinic);
            }
        }

        if ($polyclinic_id_to_use === null) {
             throw new Exception("Poliklinik ID belirlenemedi.");
        }


        // Ek tabloya doktor bilgisi kaydet (tablonuz 'doctors' veya 'doctor_polyclinic_hospital' olabilir)
        $record = new stdClass();
        $record->user_id = $newuserid;
        $record->hospital_id = $hospital_id;
        $record->polyclinic_id = $polyclinic_id_to_use;

        // Burada 'doctors' tablosu yerine doğru tablo adını kullanın.
        // Eğer tablonuz 'doctor_polyclinic_hospital' ise, aşağıdaki satırı ona göre düzeltin:
        // $DB->insert_record('doctor_polyclinic_hospital', $record);
        $DB->insert_record('doctors', $record);

        // Başarıyla yönlendir
        redirect(
            new moodle_url('/local/useradmin/index.php'),
            'Doktor başarıyla eklendi.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    } catch (Exception $e) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification('Kullanıcı oluşturulurken hata: ' . $e->getMessage(), 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }
}

// Sayfa çıktısı sadece GET isteğinde gösterilir
echo $OUTPUT->header();
echo $OUTPUT->heading('Doktor Ekleme Sayfası');
?>

<p>Lütfen doktor eklemek için <a href="index.php">ana kullanıcı yönetim sayfasına</a> gidin.</p>

<?php
echo $OUTPUT->footer();
?>