<?php
// classes/form/slot_form.php

namespace local_chiefdoctor_panel\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php'); // moodleform.php yerine formslib.php kullanmak daha kapsamlıdır.

class slot_form extends \moodleform {
    protected function definition() {
        global $DB, $USER, $CFG; // $CFG'yi de ekleyelim, hata durumunda kullanabiliriz.

        $mform = $this->_form;

        // --- Tarih Aralığı Seçimi ---
        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_chiefdoctor_panel'));

        // Yarının başlangıç timestamp'ini al (bugün 2025-06-10 ise, yarın 2025-06-11'in başlangıcı)
        // Not: time() fonksiyonu anlık zamanı verir. Tomorrow, current time + 1 gün değil, yarının başlangıcıdır.
        $tomorrow = strtotime('tomorrow');
        // Başlangıç tarih seçicisinin varsayılan değerini yarına ayarla
        $mform->setDefault('startdate', $tomorrow);

        // HATA VEREN SATIR: MoodleQuickForm_date_selector sınıfında set_attributes() metodu yoktur.
        // Bu satırı kaldırıyoruz. HTML5 min özelliği yerine varsayılan değeri ve sunucu tarafı validasyonu kullanacağız.
        // $mform->getElement('startdate')->set_attributes(['min' => date('Y-m-d', $tomorrow)]);
        // Bu satırı zaten orijinal kodunuzdan kaldırmıştınız, bu haliyle devam ediyoruz.

        $mform->addRule('startdate', get_string('required', 'moodle'), 'required'); // 'required' stringi genellikle moodle'dan gelir.

        $mform->addElement('date_selector', 'enddate', get_string('enddate', 'local_chiefdoctor_panel'));
        $mform->setDefault('enddate', time() + (7 * 86400)); // Varsayılan olarak bir hafta sonrası
        $mform->addRule('enddate', get_string('required', 'moodle'), 'required'); // 'required' stringi genellikle moodle'dan gelir.

        // --- Doktor Seçim Alanı ---
        // Mevcut başhekime ait hastanenin doktorlarını çek
        try {
            $chief = $DB->get_record('chiefdoctors', ['user_id' => $USER->id], '*', MUST_EXIST);
            $doctors = $DB->get_records('doctors', ['hospital_id' => $chief->hospital_id], '', 'id, user_id');

            $doctor_options = [];
            $doctor_options[0] = get_string('selectdoctorplaceholder', 'local_chiefdoctor_panel'); // "Doktor Seçiniz" seçeneği

            if ($doctors) {
                foreach ($doctors as $doctor) {
                    $user_record = $DB->get_record('user', ['id' => $doctor->user_id]);
                    if ($user_record) {
                        $doctor_options[$doctor->id] = fullname($user_record);
                    }
                }
            } else {
                // Hiç doktor yoksa, uyarı verilebilir veya varsayılan bir seçenek sunulabilir.
                $doctor_options[0] = get_string('nodoctorsfound', 'local_chiefdoctor_panel');
            }

            $mform->addElement('select', 'doctorid', get_string('selectdoctor', 'local_chiefdoctor_panel'), $doctor_options);
            $mform->addRule('doctorid', get_string('required', 'moodle'), 'required');
            $mform->addRule('doctorid', get_string('selectadoctor', 'local_chiefdoctor_panel'), 'nonzero'); // 0 değerini seçilmez yap
        } catch (\moodle_exception $e) {
            // Başhekim kaydı bulunamazsa veya başka bir DB hatası olursa
            // Formu devre dışı bırakıp hata mesajı gösterebiliriz.
            $mform->addElement('html', \html_writer::tag('div', get_string('errordoctordata', 'local_chiefdoctor_panel') . ': ' . $e->getMessage(), ['class' => 'alert alert-danger']));
            // Tüm form elementlerini dondurarak gönderimi engelle
            $mform->hardFreeze();
            $mform->removeElement('submitbutton'); // Submit butonunu kaldır
        }


        // --- Eylem Butonları ---
        $this->add_action_buttons(true, get_string('createslots', 'local_chiefdoctor_panel'));
    }

    // --- Form Validasyonu ---
    function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Başlangıç tarihinin bitiş tarihinden önce olup olmadığını kontrol et
        if ($data['startdate'] > $data['enddate']) {
            $errors['startdate'] = get_string('startdatemustbebeforeenddate', 'local_chiefdoctor_panel');
        }

        // Başlangıç tarihinin yarından itibaren olup olmadığını sunucu tarafında kontrol et
        $tomorrow = strtotime('tomorrow'); // Yarının başlangıç timestamp'i
        if ($data['startdate'] < $tomorrow) {
            $errors['startdate'] = get_string('startdatemustbetomorroworlater', 'local_chiefdoctor_panel');
        }

        // doctorid alanı sadece 0 olduğunda hata ver, aksi takdirde kural zaten `nonzero` ile hallediliyor.
        // `nonzero` kuralı zaten 0 seçildiğinde hata verir, bu ek kontrol duplicate olabilir ama kalması sorun teşkil etmez.
        if (isset($data['doctorid']) && $data['doctorid'] == 0) {
            $errors['doctorid'] = get_string('selectadoctor', 'local_chiefdoctor_panel');
        }

        return $errors;
    }
}