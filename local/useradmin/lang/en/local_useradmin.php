<?php
// local/useradmin/lang/tr/local_useradmin.php

$string['pluginname'] = 'Kullanıcı Yönetimi';
$string['doctors'] = 'Doktorlar';
$string['chiefs'] = 'Başhekimler';
$string['addnewdoctor'] = 'Yeni Doktor Ekle';
$string['addnewchief'] = 'Yeni Başhekim Ekle';

// Bu stringler kesinlikle olmalı ve doğru yazılmalı:
$string['invalidparam'] = 'Geçersiz parametre sağlandı.'; // 'error' bileşeninden gelmiyor, 'local_useradmin' bileşeninden gelebilir.
$string['invaliduser'] = 'Kullanıcı bulunamadı veya geçersiz.'; // Bu, sizin 'Geçersiz kullanıcı ID\'si.' olarak kullandığınız
$string['invalidhospital'] = 'Hastane bulunamadı veya geçersiz.';
$string['invalidpolyclinic'] = 'Poliklinik bulunamadı veya geçersiz.'; // Bu doktorlar için gerekli, başhekim için değil ama tutarlılık için ekleyebilirsiniz.

$string['editchief'] = 'Başhekimi Düzenle: {$a}'; // Bu stringin doğru olduğundan emin olun
$string['chiefnotfound'] = 'Başhekim kaydı bulunamadı.'; // Daha spesifik bir hata mesajı için

$string['chiefsuccessfullyupdated'] = 'Başhekim bilgileri başarıyla güncellendi.';

// Silme ile ilgili stringler (zaten paylaşmıştınız, yine de kontrol edin):
$string['confirmdeletedoctor'] = 'Doktoru silmek istediğinize emin misiniz? \'{$a}\'';
$string['doctorsuccessfullydeleted'] = 'Doktor başarıyla silindi.';
$string['errordeletingdoctor'] = 'Doktor silme hatası: {$a}';
$string['confirmdeleteuser'] = 'Kullanıcı silme işlemini onaylıyor musunuz? \'{$a}\'';
$string['errordeletinguser'] = 'Moodle kullanıcısı silinirken hata oluştu: {$a}';
$string['confirmdeletechief'] = 'Başhekimi ve tüm ilişkili verileri silmek istediğinize emin misiniz? \'{$a}\'';
$string['chiefsuccessfullydeleted'] = 'Başhekim başarıyla silindi.';
$string['errordeletingchief'] = 'Başhekim silme hatası: {$a}'; // Hata mesajlarında {$a} kullanmak Moodle'da yaygındır.
$string['slotinterval'] = 'Randevu Aralığı (dakika)';
$string['startdate'] = 'Başlangıç Tarihi';
$string['enddate'] = 'Bitiş Tarihi';
$string['starttime'] = 'Günlük Başlangıç Saati';
$string['endtime'] = 'Günlük Bitiş Saati';
$string['generatedslots'] = 'Toplam Oluşturulan Slot: {$a}';
$string['endtimebeforestarttime'] = 'Bitiş saati başlangıç saatinden önce olamaz.';
