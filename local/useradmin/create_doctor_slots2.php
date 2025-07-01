<?php
require_once(__DIR__ . '/../../config.php'); // Moodle yapılandırmasını yükle
require_login(); // Giriş yapılmamışsa yönlendir

// Moodle ortamı dışında çağrılmayı engelle
defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);

// Moodle form kütüphanesini yükle
require_once($CFG->libdir . '/formslib.php');


// Moodle birim sabitlerini tanımla (Moodle ortamı dışında test için)
// Gerçek Moodle ortamında bu sabitler Moodle çekirdeği tarafından sağlanır.
if (!defined('UNIT_MINUTE')) {
    define('UNIT_MINUTE', 60); // 60 saniye = 1 dakika
}
if (!defined('UNIT_HOUR')) {
    define('UNIT_HOUR', 3600); // 3600 saniye = 1 saat
}
if (!defined('UNIT_DAY')) {
    define('UNIT_DAY', 86400); // 86400 saniye = 1 gün
}

/**
 * Doktor randevu aralıkları oluşturmak için kullanılan form sınıfı.
 * moodleform sınıfından miras alır.
 */
class local_doctorslots_create_slots_form extends moodleform {

    /**
     * Form elemanlarının tanımını yapar.
     */
    protected function definition() {
        global $CFG, $DB; // Moodle global değişkenleri

        $mform = $this->_form; // Form nesnesine erişim

        // Doktor ID'si için basit bir seçim kutusu (demo amaçlı)
        // Gerçek uygulamada bu, veritabanından alınan doktor listesi olabilir.
        $doctors = array(
            1 => 'Dr. Ayşe Yılmaz',
            2 => 'Dr. Can Demir',
            3 => 'Dr. Elif Kaya',
        );
        $mform->addElement('select', 'doctorid', get_string('doctor', 'local_doctorslots'), $doctors);
        $mform->addRule('doctorid', get_string('required'), 'required'); // Zorunlu alan kuralı

        // Başlangıç Tarihi seçici
        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_doctorslots'));
        $mform->addRule('startdate', get_string('required'), 'required');

        // Bitiş Tarihi seçici
        $mform->addElement('date_selector', 'enddate', get_string('enddate', 'local_doctorslots'));
        $mform->addRule('enddate', get_string('required'), 'required');

        // Başlangıç Saati seçici (saniye cinsinden)
        // UNIT_MINUTE sabitini kullanarak Moodle'ın tanıdığı birimi belirtiyoruz.
        // Moodle'ın 'minutes' string anahtarını kullanarak doğru birim çevirisi sağlıyoruz.
        $mform->addElement('duration', 'starttime', get_string('starttime', 'local_doctorslots'), array('defaultunit' => UNIT_MINUTE, 'units' => array(UNIT_MINUTE => get_string('minutes', 'core'))));
        $mform->setDefault('starttime', 9 * UNIT_HOUR); // Varsayılan: 09:00 (saniye cinsinden)
        $mform->addRule('starttime', get_string('required'), 'required');

        // Bitiş Saati seçici (saniye cinsinden)
        // UNIT_MINUTE sabitini kullanarak Moodle'ın tanıdığı birimi belirtiyoruz.
        // Moodle'ın 'minutes' string anahtarını kullanarak doğru birim çevirisi sağlıyoruz.
        $mform->addElement('duration', 'endtime', get_string('endtime', 'local_doctorslots'), array('defaultunit' => UNIT_MINUTE, 'units' => array(UNIT_MINUTE => get_string('minutes', 'core'))));
        $mform->setDefault('endtime', 17 * UNIT_HOUR); // Varsayılan: 17:00 (saniye cinsinden)
        $mform->addRule('endtime', get_string('required'), 'required');

        // Randevu Aralığı (dakika cinsinden)
        $mform->addElement('text', 'slotinterval', get_string('slotinterval', 'local_doctorslots'), array('size' => '5'));
        $mform->setType('slotinterval', PARAM_INT); // Integer tipi
        $mform->addRule('slotinterval', get_string('required'), 'required');
        $mform->addRule('slotinterval', get_string('numericerror'), 'numeric');
        $mform->addRule('slotinterval', get_string('lessthanmin', 'local_doctorslots', 1), 'min', 1); // Minimum 1 dakika

        // Form gönderim butonlarını ekle
        $this->add_action_buttons();
    }

    /**
     * Form doğrulamasını yapar.
     *
     * @param array $data Formdan gelen veriler.
     * @param array $files Yüklenen dosyalar (bu formda kullanılmaz).
     * @return array Hata mesajları dizisi.
     */
    function validation($data, $files) {
        $errors = parent::validation($data, $files); // Üst sınıfın doğrulamasını çağır

        // Moodle'ın date_selector nesnelerini Unix zaman damgasına dönüştürerek karşılaştır
        $startdate_ts = $data['startdate']->getTimestamp();
        $enddate_ts = $data['enddate']->getTimestamp();

        // Bitiş tarihinin başlangıç tarihinden sonra olup olmadığını kontrol et
        if ($enddate_ts < $startdate_ts) {
            $errors['enddate'] = get_string('enddatebeforestartdate', 'local_doctorslots');
        }

        // Bitiş saatinin başlangıç saatinden sonra olup olmadığını kontrol et
        if ($data['endtime'] <= $data['starttime']) {
            $errors['endtime'] = get_string('endtimebeforestarttime', 'local_doctorslots');
        }

        return $errors;
    }
}

/**
 * Doktor randevu aralıklarını oluşturan fonksiyon.
 * Bu fonksiyon, verilen tarihler ve saatler arasında randevu aralıklarını simüle eder.
 * Gerçek bir Moodle eklentisinde bu veriler veritabanına kaydedilmelidir.
 *
 * @param int $doctorid Doktorun ID'si.
 * @param object $startdate Moodle date_selector nesnesi (başlangıç tarihi).
 * @param object $enddate Moodle date_selector nesnesi (bitiş tarihi).
 * @param int $starttime Günün başlangıcından itibaren saniye cinsinden başlangıç saati.
 * @param int $endtime Günün başlangıcından itibaren saniye cinsinden bitiş saati.
 * @param int $slotinterval Randevu aralığı (dakika cinsinden).
 * @throws Exception Randevu aralığı oluşturulamazsa hata fırlatır.
 */
function local_doctorslots_create_slots($doctorid, $startdate, $enddate, $starttime, $endtime, $slotinterval) {
    global $DB; // Gerçek bir Moodle eklentisinde veritabanı işlemleri için $DB kullanılır.

    // Moodle'ın date_selector nesnelerini DateTime nesnelerine dönüştür
    $start_dt = new DateTime();
    $start_dt->setTimestamp($startdate->getTimestamp());
    $start_dt->setTime(0, 0, 0); // Saati günün başına sıfırla

    $end_dt = new DateTime();
    $end_dt->setTimestamp($enddate->getTimestamp());
    $end_dt->setTime(0, 0, 0); // Saati günün başına sıfırla

    $slots_generated = 0;
    $current_date = clone $start_dt; // Başlangıç tarihinden kopyala

    echo "<h3>" . get_string('generatedslots', 'local_doctorslots') . "</h3>";
    echo "<p>" . get_string('doctorid', 'local_doctorslots') . ": " . $doctorid . "</p>";
    echo "<p>" . get_string('interval', 'local_doctorslots') . ": " . $slotinterval . " " . get_string('minutes', 'local_doctorslots') . "</p>";
    echo "<ul>";

    // Her gün için randevu aralıklarını oluştur
    while ($current_date <= $end_dt) {
        // Günün başlangıç ve bitiş zaman damgalarını hesapla
        $day_start_timestamp = $current_date->getTimestamp() + $starttime;
        $day_end_timestamp = $current_date->getTimestamp() + $endtime;

        $current_slot_timestamp = $day_start_timestamp;

        // Belirtilen saat aralığında randevu aralıklarını oluştur
        while ($current_slot_timestamp < $day_end_timestamp) {
            $slot_end_timestamp = $current_slot_timestamp + ($slotinterval * UNIT_MINUTE); // Randevu bitiş zamanı

            // Randevunun günün bitiş saatini aşmadığından emin ol
            if (floor($slot_end_timestamp / UNIT_DAY) > floor($day_end_timestamp / UNIT_DAY)) {
                // Eğer randevu bitişi sonraki güne sarkıyorsa, bu randevuyu oluşturma
                break;
            }

            // Randevu başlangıç ve bitiş DateTime nesneleri
            $slot_start_datetime = new DateTime();
            $slot_start_datetime->setTimestamp($current_slot_timestamp);

            $slot_end_datetime = new DateTime();
            $slot_end_datetime->setTimestamp($slot_end_timestamp);

            // Gerçek bir senaryoda, bu randevu bilgilerini bir veritabanı tablosuna eklersin:
            /*
            $record = new stdClass();
            $record->doctorid = $doctorid;
            $record->timestart = $current_slot_timestamp;
            $record->timeend = $slot_end_timestamp;
            $record->timecreated = time(); // Kayıt oluşturulma zamanı
            $DB->insert_record('local_doctorslots_slots', $record); // 'local_doctorslots_slots' senin tablonun adı olmalı
            */

            // Oluşturulan randevuyu ekrana yazdır
            echo "<li>" . $slot_start_datetime->format('Y-m-d H:i') . " - " . $slot_end_datetime->format('H:i') . "</li>";
            $slots_generated++;
            $current_slot_timestamp = $slot_end_timestamp; // Bir sonraki randevunun başlangıcı
        }
        $current_date->modify('+1 day'); // Bir sonraki güne geç
    }
    echo "</ul>";

    // Hiç randevu oluşturulamazsa hata fırlat
    if ($slots_generated === 0) {
        throw new Exception(get_string('noslotsgenerated', 'local_doctorslots'));
    }
}

// Moodle ortamı dışında test etmek için basit bir get_string fonksiyonu
// Gerçek Moodle ortamında bu fonksiyon Moodle çekirdeği tarafından sağlanır.
if (!function_exists('get_string')) {
    function get_string($identifier, $component = 'moodle', $a = null) {
        $strings = [
            'doctor' => 'Doktor',
            'startdate' => 'Başlangıç Tarihi',
            'enddate' => 'Bitiş Tarihi',
            'starttime' => 'Başlangıç Saati',
            'endtime' => 'Bitiş Saati',
            'slotinterval' => 'Randevu Aralığı (Dakika)',
            'required' => 'Bu alan zorunludur.',
            'numericerror' => 'Bu alan sayısal olmalıdır.',
            'lessthanmin' => 'Değer %a değerinden küçük olamaz.',
            'slotscreatedsuccessfully' => 'Randevu aralıkları başarıyla oluşturuldu.',
            'notification' => 'Bildirim',
            'notifysuccess' => 'Başarılı',
            'notifyproblem' => 'Problem',
            'enddatebeforestartdate' => 'Bitiş tarihi başlangıç tarihinden önce olamaz.',
            'endtimebeforestarttime' => 'Bitiş saati başlangıç saatinden önce olamaz.',
            'generatedslots' => 'Oluşturulan Randevu Aralıkları',
            'interval' => 'Aralık',
            'minutes' => 'dakika', // Bu satır, test get_string fonksiyonu için hala 'dakika' olabilir
            'noslotsgenerated' => 'Hiç randevu aralığı oluşturulamadı. Lütfen tarih ve saat aralıklarını kontrol edin.',
        ];

        // Moodle'ın beklediği genel birim stringleri
        $moodle_core_strings = [
            'minutes' => 'dakika',
            'hours' => 'saat',
            'days' => 'gün',
            'weeks' => 'hafta',
            'months' => 'ay',
            'years' => 'yıl',
        ];

        if ($component === 'core' && isset($moodle_core_strings[$identifier])) { // 'moodle' yerine 'core' kontrolü eklendi
            $str = $moodle_core_strings[$identifier];
        } else if (isset($strings[$identifier])) {
            $str = $strings[$identifier];
        } else {
            return "[$identifier]"; // Eksik stringler için yedek
        }
        
        if (is_array($a)) {
            foreach ($a as $key => $value) {
                $str = str_replace('%' . $key, $value, $str);
            }
        } else if ($a !== null) {
            $str = str_replace('%a', $a, $str);
        }
        return $str;
    }
}

// Moodle ortamı dışında test etmek için basit bir $OUTPUT nesnesi
// Gerçek Moodle ortamında bu nesne Moodle çekirdeği tarafından sağlanır.
if (!isset($OUTPUT)) {
    $OUTPUT = new stdClass();
    $OUTPUT->notification = function($message, $type = '') {
        $class = ($type == 'notifysuccess') ? 'success' : 'error';
        return "<div class='moodle-notification $class'>$message</div>";
    };
}

// Moodle ortamı dışında test etmek için basit bir $CFG nesnesi
// Gerçek Moodle ortamında bu nesne Moodle çekirdeği tarafından sağlanır.
if (!isset($CFG)) {
    $CFG = new stdClass();
    // Bu dosyanın Moodle kök dizinine göre /local/useradmin/create_doctor_slots.php konumunda olduğunu varsayarak
    $CFG->dirroot = dirname(dirname(dirname(__FILE__))); // Moodle'ın kök dizinini bul
    $CFG->libdir = $CFG->dirroot . '/lib'; // lib klasörünün doğru yolunu ayarla
}

// Moodle ortamı dışında test etmek için basit bir $DB nesnesi
// Gerçek Moodle ortamında bu nesne Moodle çekirdeği tarafından sağlanır.
if (!isset($DB)) {
    $DB = new stdClass();
    $DB->insert_record = function($table, $data) {
        // Bu sadece bir simülasyon. Gerçekte veritabanına kayıt eklenir.
        // echo "Veritabanına eklendi: Tablo: $table, Veri: " . print_r($data, true) . "<br>";
        return true;
    };
}


// *** BURADA FORM OLUŞTURULUYOR VE İŞLENİYOR ***
// Bu kısım, 'index.php' tarafından include edildiğinde çalışacaktır.
$mform = new local_doctorslots_create_slots_form();

// Form gönderilmiş ve geçerli veriler varsa
if ($mform->is_cancelled()) {
    // İptal butonuna basıldıysa bir şey yap (örn: ana sayfaya yönlendir)
    // Eğer bir yönlendirme yapmayacaksanız bu bloğu boş bırakabilirsiniz.
} else if ($data = $mform->get_data()) {
    // Form verileri başarıyla alınmışsa
    try {
        local_doctorslots_create_slots(
            $data->doctorid,
            $data->startdate,
            $data->enddate,
            $data->starttime,
            $data->endtime,
            $data->slotinterval
        );
        echo $OUTPUT->notification(get_string('slotscreatedsuccessfully', 'local_doctorslots'), 'notifysuccess');
    } catch (Exception $e) {
        echo $OUTPUT->notification(get_string('notifyproblem', 'local_doctorslots') . ': ' . $e->getMessage(), 'notifyproblem');
    }
} else {
    // Form henüz gönderilmemişse veya geçersizse formu görüntüle
    $mform->display();
}