<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_login();

$context = context_system::instance();
// require_capability('moodle/site:manageusers', $context);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = required_param('username', PARAM_ALPHANUM);
    $password = required_param('password', PARAM_RAW);
    $firstname = required_param('firstname', PARAM_NOTAGS);
    $lastname = required_param('lastname', PARAM_NOTAGS);
    $email = required_param('email', PARAM_EMAIL);
    $hospital_id = required_param('hospital_id', PARAM_INT);

    // Hastanede zaten bir başhekim olup olmadığını kontrol et
    if ($DB->record_exists('chiefdoctors', ['hospital_id' => $hospital_id])) {
        // Hata mesajını düz string olarak veya Moodle'ın daha eski sürümlerinde kullanılan bir yöntemi kullanın
        // Örneğin:
        // redirect(new moodle_url('/local/useradmin/index.php'), 'Bu hastanede zaten bir başhekim bulunmaktadır. Yeni bir başhekim eklenemez.', \moodle_url::PARAM_ERROR); // Moodle 3.x ve bazı 4.x versiyonları için
        // Veya sadece string mesaj
        redirect(new moodle_url('/local/useradmin/index.php'), 'Bu hastanede zaten bir başhekim bulunmaktadır. Yeni bir başhekim eklenemez.', 'error');
        exit;
    }

    // Yeni kullanıcı oluşturma
    $user = new stdClass();
    $user->username = $username;
    $user->password = hash_internal_user_password($password);
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->auth = 'manual';

    // Kullanıcı ekle
    $user->id = user_create_user($user, false);

    if ($user->id) {
        // Başhekim tablosuna ekle
        $record = new stdClass();
        $record->user_id = $user->id;
        $record->hospital_id = $hospital_id;
        $record->created_at = date('Y-m-d H:i:s');
        $record->updated_at = date('Y-m-d H:i:s');

        $DB->insert_record('chiefdoctors', $record);

        // Rol ataması (chiefdoctor rolü)
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'chiefdoctor']);
        role_assign($roleid, $user->id, $context->id);

        // Başarı mesajını düz string olarak kullanın
        redirect(new moodle_url('/local/useradmin/index.php'), 'Başhekim başarıyla eklendi.', 'success');
    } else {
        // Hata mesajını düz string olarak kullanın
        redirect(new moodle_url('/local/useradmin/index.php'), 'Kullanıcı oluşturulamadı.', 'error');
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Başhekim Ekle</title>
</head>
<body>
<h2>Başhekim Ekle</h2>
<form method="post" action="">
    Kullanıcı Adı: <input type="text" name="username" required><br>
    Şifre: <input type="password" name="password" required><br>
    Ad: <input type="text" name="firstname" required><br>
    Soyad: <input type="text" name="lastname" required><br>
    Email: <input type="email" name="email" required><br>
    Hastane:
    <select name="hospital_id" required>
        <?php
        $hospitals = $DB->get_records('hospitals');
        $chief_hospitals = $DB->get_fieldset('chiefdoctors', 'hospital_id');

        foreach ($hospitals as $hospital) {
            $disabled = in_array($hospital->id, $chief_hospitals) ? 'disabled' : '';
            echo "<option value='{$hospital->id}' {$disabled}>" . htmlspecialchars($hospital->name) . "</option>";
        }
        ?>
    </select><br>
    <button type="submit">Ekle</button>
</form>
</body>
</html>