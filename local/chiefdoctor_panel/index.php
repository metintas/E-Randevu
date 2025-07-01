<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/form/slot_form.php');

require_login();
$context = context_system::instance();
require_capability('local/chiefdoctor_panel:createslots', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/chiefdoctor_panel/index.php'));
$PAGE->set_title('Başhekim Paneli');
$PAGE->set_heading('Başhekim Paneli');
$PAGE->set_pagelayout('standard');

global $DB, $USER;

$form = new \local_chiefdoctor_panel\form\slot_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/my'));
} else if ($data = $form->get_data()) {
    $start = $data->startdate;
    $end = $data->enddate;
    $selected_doctor_id = $data->doctorid; // Seçilen doktorun ID'si

    if (($end - $start) > (60 * 60 * 24 * 15)) {
        throw new moodle_exception('Randevu slotları en fazla 15 günlük bir aralık için oluşturulabilir.', 'local_chiefdoctor_panel');
    }

    // Seçilen doktoru doğrudan çekiyoruz, tüm doktorları değil.
    // get_record ile çekmemiz yeterli çünkü tek bir doktor için işlem yapacağız.
    $selected_doctor = $DB->get_record('doctors', ['id' => $selected_doctor_id], '*', MUST_EXIST);

    for ($day = $start; $day <= $end; $day += 86400) {
        // HAFTA SONU KONTROLÜ: Cumartesi (6) veya Pazar (7) ise bu günü atla
        $day_of_week = date('N', $day); // 1 (Pazartesi) - 7 (Pazar)
        if ($day_of_week == 6 || $day_of_week == 7) {
            continue; // Bu günü atla ve bir sonraki güne geç
        }

        // Sadece seçilen doktor için slot oluştur
        for ($hour = 8; $hour <= 16; $hour++) {
            for ($min = 0; $min < 60; $min += 10) {
                $time = sprintf('%02d:%02d:00', $hour, $min);
                if ($time > '16:50:00') break;

                $exists = $DB->record_exists('appointment_slots', [
                    'doctor_id' => $selected_doctor->id, // selected_doctor_id kullanıyoruz
                    'slot_date' => date('Y-m-d', $day),
                    'slot_time' => $time
                ]);
                if (!$exists) {
                    $record = (object) [
                        'doctor_id' => $selected_doctor->id, // selected_doctor_id kullanıyoruz
                        'slot_date' => date('Y-m-d', $day),
                        'slot_time' => $time,
                        'is_booked' => 0
                    ];
                    $DB->insert_record('appointment_slots', $record);
                }
            }
        }
    }

    redirect($PAGE->url, get_string('slotscreated', 'local_chiefdoctor_panel'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-md-12">
            <ul class="nav nav-tabs mb-3" id="chiefDoctorTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="slot-creation-tab" data-bs-toggle="tab" data-bs-target="#slot-creation" type="button" role="tab" aria-controls="slot-creation" aria-selected="true">Randevu Slotu Oluştur</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="doctor-list-tab" data-bs-toggle="tab" data-bs-target="#doctor-list" type="button" role="tab" aria-controls="doctor-list" aria-selected="false">Doktor Listesi</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="slot-list-tab" data-bs-toggle="tab" data-bs-target="#slot-list" type="button" role="tab" aria-controls="slot-list" aria-selected="false">Randevu Slotları</button>
                </li>
            </ul>
            <div class="tab-content" id="chiefDoctorTabsContent">
                <div class="tab-pane fade show active" id="slot-creation" role="tabpanel" aria-labelledby="slot-creation-tab">
                    <div class="card shadow border-0">
                        <div class="card-header bg-primary text-white text-center">
                            <h4 class="mb-0">Randevu Slotu Oluştur</h4>
                        </div>
                        <div class="card-body">
                            <p class="mb-4 text-muted">
                                Lütfen slot oluşturulacak tarih aralığını ve atanacak doktoru seçin. Sistem seçilen doktor için 10 dakikalık slotlar oluşturacaktır.
                            </p>
                            <?php echo $form->render(); ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="doctor-list" role="tabpanel" aria-labelledby="doctor-list-tab">
                    <div class="card shadow border-0">
                        <div class="card-header bg-success text-white text-center">
                            <h4 class="mb-0">Kayıtlı Doktorlar</h4>
                        </div>
                        <div class="card-body">
                            <?php
                            $chief = $DB->get_record('chiefdoctors', ['user_id' => $USER->id], '*', MUST_EXIST);
                            $sql = "SELECT d.id AS doctorid, u.firstname, u.lastname, u.email, u.phone1, p.name AS polyclinicname
                                            FROM {doctors} d
                                            JOIN {user} u ON d.user_id = u.id
                                            LEFT JOIN {polyclinics} p ON d.polyclinic_id = p.id
                                            WHERE d.hospital_id = :hospitalid";
                            $doctorsdata = $DB->get_records_sql($sql, ['hospitalid' => $chief->hospital_id]);

                            if ($doctorsdata) {
                                echo '<div class="table-responsive">';
                                echo '<table class="table table-hover table-striped">';
                                echo '<thead class="table-light"><tr><th>Ad Soyad</th><th>E-posta</th><th>Telefon</th><th>Poliklinik</th><th>Randevu Slotları</th></tr></thead>';
                                echo '<tbody>';
                                foreach ($doctorsdata as $doctor) {
                                    echo '<tr>';
                                    echo '<td>' . fullname($doctor) . '</td>';
                                    echo '<td>' . $doctor->email . '</td>';
                                    echo '<td>' . ($doctor->phone1 ?: 'Yok') . '</td>';
                                    echo '<td>' . ($doctor->polyclinicname ?: 'Belirtilmemiş') . '</td>';
                                    echo '<td><button class="btn btn-sm btn-info view-slots-btn" data-doctorid="' . $doctor->doctorid . '" data-doctorname="' . fullname($doctor) . '">Slotları Görüntüle</button></td>';
                                    echo '</tr>';
                                }
                                echo '</tbody>';
                                echo '</table>';
                                echo '</div>';
                            } else {
                                echo '<div class="alert alert-warning" role="alert">Hastanenize kayıtlı doktor bulunmamaktadır.</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="slot-list" role="tabpanel" aria-labelledby="slot-list-tab">
                    <div class="card shadow border-0">
                        <div class="card-header bg-info text-white text-center">
                            <h4 class="mb-0" id="slotListHeader">Randevu Slotları</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="d-flex align-items-center">
                                    <label for="perPageSelect" class="form-label mb-0 me-2">Sayfa başına:</label>
                                    <select class="form-select form-select-sm d-inline-block w-auto" id="perPageSelect">
                                        <option value="10">10</option>
                                        <option value="20" selected>20</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                    <button class="btn btn-danger btn-sm ms-3" id="deleteSelectedSlotsBtn" disabled>Seçilenleri Sil (<span id="selectedSlotsCount">0</span>)</button>
                                </div>
                                <nav aria-label="Slot Sayfalama">
                                    <ul class="pagination pagination-sm mb-0" id="slotPagination">
                                    </ul>
                                </nav>
                            </div>
                            <div id="slots-content">
                                <div class="alert alert-info" role="alert">Yukarıdaki "Doktor Listesi" sekmesinden bir doktorun slotlarını görüntülemek için "Slotları Görüntüle" butonuna tıklayın.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-labelledby="customConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="customConfirmModalLabel">Onay</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body" id="customConfirmModalBody">
                </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="customConfirmModalConfirmBtn">Onayla</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="customAlertModal" tabindex="-1" aria-labelledby="customAlertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="customAlertModalLabel">Bilgi</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body" id="customAlertModalBody">
                </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Tamam</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var slotListTabButton = document.getElementById('slot-list-tab');
    var slotListHeader = document.getElementById('slotListHeader');
    var slotsContent = document.getElementById('slots-content');
    var slotPagination = document.getElementById('slotPagination');
    var perPageSelect = document.getElementById('perPageSelect');
    var deleteSelectedSlotsBtn = document.getElementById('deleteSelectedSlotsBtn');
    var selectedSlotsCountSpan = document.getElementById('selectedSlotsCount');

    let currentDoctorId = null;
    let currentDoctorName = null;
    let currentPage = 1;
    let currentPerPage = parseInt(perPageSelect.value); // Başlangıçta seçili değeri al
    let selectedSlotIds = new Set(); // Seçili slot ID'lerini tutmak için Set kullanıyoruz

    // Custom Modal Elementleri
    const customConfirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    const customConfirmModalBody = document.getElementById('customConfirmModalBody');
    const customConfirmModalConfirmBtn = document.getElementById('customConfirmModalConfirmBtn');

    const customAlertModal = new bootstrap.Modal(document.getElementById('customAlertModal'));
    const customAlertModalBody = document.getElementById('customAlertModalBody');

    // Özel onay fonksiyonu
    function showCustomConfirm(message, callback) {
        customConfirmModalBody.innerText = message;
        customConfirmModalConfirmBtn.onclick = function() {
            customConfirmModal.hide();
            callback(true);
        };
        customConfirmModal.show();
    }

    // Özel uyarı fonksiyonu
    function showCustomAlert(message) {
        customAlertModalBody.innerText = message;
        customAlertModal.show();
    }

    // Sayfa başına öğe sayısı değiştiğinde slotları yeniden yükle
    perPageSelect.addEventListener('change', function() {
        currentPerPage = parseInt(this.value);
        currentPage = 1; // Yeni perpage seçildiğinde ilk sayfaya dön
        if (currentDoctorId) {
            loadSlots(currentDoctorId, currentDoctorName, currentPage, currentPerPage);
        }
    });

    // Seçilen slot sayısını ve buton durumunu güncelleyen fonksiyon
    function updateSelectedCountAndButton() {
        selectedSlotsCountSpan.innerText = selectedSlotIds.size;
        deleteSelectedSlotsBtn.disabled = selectedSlotIds.size === 0;

        // "Tümünü Seç" checkbox'ının durumunu güncelle
        const selectAllCheckbox = document.getElementById('selectAllSlots');
        if (selectAllCheckbox) {
            const visibleCheckboxes = slotsContent.querySelectorAll('.slot-checkbox');
            // Eğer hiç görünür checkbox yoksa veya hepsi seçiliyse, selectAllChecked true olur
            const allVisibleSelected = visibleCheckboxes.length > 0 && Array.from(visibleCheckboxes).every(cb => selectedSlotIds.has(parseInt(cb.value)));
            // Eğer bazıları seçiliyse ama hepsi değilse, indeterminate true olur
            const someVisibleSelected = visibleCheckboxes.length > 0 && Array.from(visibleCheckboxes).some(cb => selectedSlotIds.has(parseInt(cb.value)));

            selectAllCheckbox.checked = allVisibleSelected;
            selectAllCheckbox.indeterminate = someVisibleSelected && !allVisibleSelected;
        }
    }

    // Slotları yükleme fonksiyonu
    function loadSlots(doctorId, doctorName, page, perPage) {
        currentDoctorId = doctorId;
        currentDoctorName = doctorName;
        currentPage = page;
        currentPerPage = perPage;
        selectedSlotIds.clear(); // Yeni doktor veya sayfa yüklendiğinde seçimi sıfırla
        updateSelectedCountAndButton(); // Seçim sayacını ve butonu güncelle

        var tab = new bootstrap.Tab(slotListTabButton);
        tab.show();

        slotListHeader.innerText = doctorName + ' Randevu Slotları';
        slotsContent.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"><span class="visually-hidden">Yükleniyor...</span></div><p class="mt-2">Slotlar yükleniyor...</p></div>';
        slotPagination.innerHTML = ''; // Sayfalama butonlarını temizle

        fetch(M.cfg.wwwroot + '/local/chiefdoctor_panel/ajax.php?action=list_slots&doctorid=' + doctorId + '&page=' + page + '&perpage=' + perPage)
            .then(response => {
                if (!response.ok) {
                    return response.json().then(json => { throw new Error('HTTP error! status: ' + response.status + ' - ' + (json.message || 'Bilinmeyen hata')); });
                }
                return response.json();
            })
            .then(data => {
                console.log("AJAX Response for slots:", data);

                if (data.success) {
                    renderSlots(data);
                } else {
                    slotsContent.innerHTML = '<div class="alert alert-danger" role="alert">Slotlar yüklenirken bir hata oluştu: ' + data.message + '</div>';
                }
            })
            .catch(error => {
                console.error('Error fetching slots:', error);
                slotsContent.innerHTML = '<div class="alert alert-danger" role="alert">Slotlar yüklenirken bir hata oluştu: ' + error.message + '</div>';
            });
    }

    // Slotları ve sayfalama butonlarını render eden fonksiyon
    function renderSlots(data) {
        let html = '';
        if (data.slots && data.slots.length > 0) {
            html += '<div class="table-responsive">';
            html += '<table class="table table-bordered table-sm mt-3">';
            html += '<thead class="table-primary"><tr>';
            html += '<th style="width: 30px;"><input type="checkbox" id="selectAllSlots"></th>'; // Tümünü Seç checkbox
            html += '<th>Tarih</th><th>Saat</th><th>Durum</th><th>İşlem</th></tr></thead>';
            html += '<tbody>';
            data.slots.forEach(slot => {
                const status = slot.is_booked == 1 ? 'Dolu' : 'Boş';
                const status_class = slot.is_booked == 1 ? 'table-danger' : 'table-success';
                const slotDate = new Date(slot.slot_date + 'T' + slot.slot_time);
                const formattedDate = new Intl.DateTimeFormat('tr-TR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(slotDate);
                const formattedTime = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' }).format(slotDate);

                // Checkbox'ın seçili olup olmadığını kontrol et
                const isChecked = selectedSlotIds.has(parseInt(slot.id)) ? 'checked' : '';

                html += '<tr class="' + status_class + '">';
                html += `<td><input type="checkbox" class="slot-checkbox" value="${slot.id}" ${isChecked}></td>`; // Her slot için checkbox
                html += '<td>' + formattedDate + '</td>';
                html += '<td>' + formattedTime + '</td>';
                html += '<td>' + status + '</td>';
                html += '<td>';
                html += `<button class="btn btn-sm ${slot.is_booked == 1 ? 'btn-warning' : 'btn-success'} me-2 toggle-slot-status-btn" data-slotid="${slot.id}" data-status="${slot.is_booked == 1 ? 0 : 1}" data-doctorid="${currentDoctorId}">${slot.is_booked == 1 ? 'Boşa Çıkar' : 'Dolu Yap'}</button>`;
                html += `<button class="btn btn-sm btn-danger delete-slot-btn" data-slotid="${slot.id}" data-doctorid="${currentDoctorId}">Sil</button>`;
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody>';
            html += '</table>';
            html += '</div>';
        } else {
            html += '<div class="alert alert-info" role="alert">Bu doktora ait henüz randevu slotu bulunmamaktadır.</div>';
        }
        slotsContent.innerHTML = html;
        renderPagination(data.currentPage, data.totalPages, data.doctorid, data.perPage);
        updateSelectedCountAndButton(); // Render sonrası seçim sayacını ve butonu güncelle
    }

    // Sayfalama butonlarını render eden fonksiyon
    function renderPagination(currentPage, totalPages, doctorId, perPage) {
        slotPagination.innerHTML = ''; // Önceki butonları temizle

        if (totalPages <= 1) {
            return; // Tek sayfa ise sayfalama gösterme
        }

        // Önceki sayfa butonu
        slotPagination.innerHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage - 1}">Önceki</a>
        </li>`;

        // Sayfa numaraları
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);

        if (startPage > 1) {
            slotPagination.innerHTML += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
            if (startPage > 2) {
                slotPagination.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            slotPagination.innerHTML += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a>
            </li>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                slotPagination.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            slotPagination.innerHTML += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
        }

        // Sonraki sayfa butonu
        slotPagination.innerHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage + 1}">Sonraki</a>
        </li>`;
    }

    // Olay yetkilendirme ile sayfalama butonlarına tıklama olayını dinle
    slotPagination.addEventListener('click', function(e) {
        e.preventDefault(); // Varsayılan bağlantı davranışını engelle
        const target = e.target;

        if (target.matches('.page-link') && !target.closest('.page-item').classList.contains('disabled')) {
            const newPage = parseInt(target.dataset.page);
            if (!isNaN(newPage) && newPage > 0) {
                loadSlots(currentDoctorId, currentDoctorName, newPage, currentPerPage);
            }
        }
    });

    // Olay yetkilendirme ile doktor listesi butonlarına tıklama olayını dinle
    document.querySelectorAll('.view-slots-btn').forEach(button => {
        button.addEventListener('click', function() {
            var doctorId = this.dataset.doctorid;
            var doctorName = this.dataset.doctorname;
            loadSlots(doctorId, doctorName, 1, currentPerPage); // İlk sayfadan başla
        });
    });

    // Olay yetkilendirme ile dinamik olarak oluşturulan toggle, delete ve checkbox butonlarını dinle
    slotsContent.addEventListener('click', function(e) {
        const target = e.target;

        if (target.matches('.toggle-slot-status-btn')) {
            const slotId = parseInt(target.dataset.slotid);
            const status = parseInt(target.dataset.status);
            const doctorId = parseInt(target.dataset.doctorid);
            if (!isNaN(slotId) && !isNaN(status) && !isNaN(doctorId)) {
                toggleSlotStatus(slotId, status, doctorId);
            }
        } else if (target.matches('.delete-slot-btn')) {
            const slotId = parseInt(target.dataset.slotid);
            const doctorId = parseInt(target.dataset.doctorid);
            if (!isNaN(slotId) && !isNaN(doctorId)) {
                deleteSlot(slotId, doctorId);
            }
        } else if (target.matches('#selectAllSlots')) { // Tümünü Seç checkbox
            const isChecked = target.checked;
            slotsContent.querySelectorAll('.slot-checkbox').forEach(checkbox => {
                checkbox.checked = isChecked;
                const slotId = parseInt(checkbox.value);
                if (isChecked) {
                    selectedSlotIds.add(slotId);
                } else {
                    selectedSlotIds.delete(slotId);
                }
            });
            updateSelectedCountAndButton();
        } else if (target.matches('.slot-checkbox')) { // Tekli slot checkbox
            const slotId = parseInt(target.value);
            if (target.checked) {
                selectedSlotIds.add(slotId);
            } else {
                selectedSlotIds.delete(slotId);
            }
            updateSelectedCountAndButton();
        }
    });

    // Seçilenleri Sil butonuna tıklama olayı
    deleteSelectedSlotsBtn.addEventListener('click', function() {
        if (selectedSlotIds.size > 0) {
            deleteMultipleSlots(Array.from(selectedSlotIds));
        } else {
            showCustomAlert('Lütfen silmek istediğiniz slotları seçin.');
        }
    });

    // toggleSlotStatus fonksiyonu
    window.toggleSlotStatus = function(slotId, status, doctorId) {
        const message = get_string('confirmsinglestatustoggle', 'local_chiefdoctor_panel'); // Dil dosyasından al
        showCustomConfirm(message, function(confirmed) {
            if (confirmed) {
                fetch(M.cfg.wwwroot + '/local/chiefdoctor_panel/ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=toggle_slot_status&slotid=' + slotId + '&status=' + status + '&sesskey=' + M.cfg.sesskey
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(json => { throw new Error('HTTP error! status: ' + response.status + ' - ' + (json.message || 'Bilinmeyen hata')); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showCustomAlert(data.message); // Veritabanı mesajı veya get_string('slotsuccessfullytoggled')
                        loadSlots(currentDoctorId, currentDoctorName, currentPage, currentPerPage);
                    } else {
                        showCustomAlert('Hata: ' + (data.message || get_string('toggle_slot_status_failed', 'local_chiefdoctor_panel')));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showCustomAlert('Sunucuya bağlanırken bir hata oluştu: ' + error.message);
                });
            }
        });
    }

    // deleteSlot fonksiyonu (tekli silme)
    window.deleteSlot = function(slotId, doctorId) {
        const message = get_string('confirmsingledeltion', 'local_chiefdoctor_panel'); // Dil dosyasından al
        showCustomConfirm(message, function(confirmed) {
            if (confirmed) {
                fetch(M.cfg.wwwroot + '/local/chiefdoctor_panel/ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=delete_slot&slotid=' + slotId + '&sesskey=' + M.cfg.sesskey
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(json => { throw new Error('HTTP error! status: ' + response.status + ' - ' + (json.message || 'Bilinmeyen hata')); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showCustomAlert(data.message); // Veritabanı mesajı veya get_string('slotsuccessfullydeleted')
                        loadSlots(currentDoctorId, currentDoctorName, currentPage, currentPerPage);
                    } else {
                        showCustomAlert('Hata: ' + (data.message || get_string('delete_slot_failed', 'local_chiefdoctor_panel')));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showCustomAlert('Sunucuya bağlanırken bir hata oluştu: ' + error.message);
                });
            }
        });
    }

    // Yeni: deleteMultipleSlots fonksiyonu (çoklu silme)
    window.deleteMultipleSlots = function(slotIds) {
        // Parametreli string için Moodle'ın JS string getirme fonksiyonunu kullanma (henüz tanımlı değil, mock olarak ekleyeceğiz)
        // Moodle'da JS'e string geçirmek için farklı bir yapı gerekir, şimdilik statik tutalım veya get_string fonksiyonunu simüle edelim.
        // Asıl Moodle JS'te 'M.str.local_chiefdoctor_panel.confirmmultipledeltion' gibi erişilir.
        // Şimdilik stringi direkt kullanıyoruz, Moodle'da get_string JS tarafında çalışmaz.
        const message = `Seçilen ${slotIds.length} adet randevu slotunu silmek istediğinize emin misiniz?`;
        // Moodle'da `M.str.get_string('stringname', 'component')` şeklinde kullanılır.
        // Eğer ajax.php'den bu stringleri çekmiyorsak, JS tarafında statik olarak veya Moodle'ın doğru JS string mekanizmasıyla çekmeliyiz.
        // Şimdilik düz string olarak bırakıyorum. Gerçek Moodle ortamında JS'e string çekmek için `amd/src/init.js` veya benzeri bir yolla Moodle'ın JS string API'si kullanılmalıdır.

        showCustomConfirm(message, function(confirmed) {
            if (confirmed) {
                fetch(M.cfg.wwwroot + '/local/chiefdoctor_panel/ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    // slotids bir dizi olduğu için join ile string'e çeviriyoruz
                    body: 'action=delete_multiple_slots&slotids=' + slotIds.join(',') + '&sesskey=' + M.cfg.sesskey
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(json => { throw new Error('HTTP error! status: ' + response.status + ' - ' + (json.message || 'Bilinmeyen hata')); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showCustomAlert(data.message); // Veritabanı mesajı veya get_string('slotsuccessfullydeletedmultiple')
                        loadSlots(currentDoctorId, currentDoctorName, currentPage, currentPerPage); // İşlem sonrası listeyi yenile
                    } else {
                        showCustomAlert('Hata: ' + (data.message || get_string('delete_slot_failed', 'local_chiefdoctor_panel'))); // Çoklu silme için farklı bir hata mesajı olabilir.
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showCustomAlert('Sunucuya bağlanırken bir hata oluştu: ' + error.message);
                });
            }
        });
    }

    // Moodle'ın get_string fonksiyonunu JavaScript tarafında simüle eden geçici bir fonksiyon.
    // Gerçek Moodle entegrasyonunda bu yerine M.str.component.stringname kullanılmalıdır.
    function get_string(stringName, componentName) {
        // Bu sadece bir simülasyondur. Gerçek Moodle'da bu stringler PHP'den JS'e aktarılır.
        const strings = {
            'local_chiefdoctor_panel': {
                'confirmsinglestatustoggle': 'Slotun durumunu değiştirmek istediğinize emin misiniz?',
                'confirmsingledeltion': 'Bu randevu slotunu silmek istediğinize emin misiniz?',
                'confirmmultipledeltion': 'Seçilen {a} adet randevu slotunu silmek istediğinize emin misiniz?', // {a} yerine placeholder
                'selectslotsfordeletion': 'Lütfen silmek istediğiniz slotları seçin.',
                'toggle_slot_status_failed': 'Slot durumu güncellenirken bir hata oluştu.',
                'delete_slot_failed': 'Randevu slotu silinirken bir hata oluştu.'
                // AJAX.php'den gelen mesajlar burada olmaz, onlar doğrudan PHP tarafından döner.
            }
        };

        if (strings[componentName] && strings[componentName][stringName]) {
            return strings[componentName][stringName];
        }
        // Eğer string bulunamazsa, placeholder'ı döndür.
        return `[[${stringName}]]`;
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>