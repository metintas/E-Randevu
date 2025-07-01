<?php
require_once('../../config.php');
require_login();

$PAGE->set_url(new moodle_url('/local/useradmin/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Kullanıcı Yönetimi");
$PAGE->set_heading("Kullanıcı Yönetimi");

// Moodle'ın jQuery'sini yükle
$PAGE->requires->jquery();

echo $OUTPUT->header();

if (is_siteadmin()) {

    $hospitals = $DB->get_records('hospitals');
    ?>

    <div class="container mt-4" style="max-width: 900px;">
        <ul class="nav nav-tabs" id="userAdminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="doktor-ekle-tab" data-bs-toggle="tab" data-bs-target="#doktor-ekle" type="button" role="tab" aria-controls="doktor-ekle" aria-selected="true">
                    Doktor Ekle
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="bashekim-ekle-tab" data-bs-toggle="tab" data-bs-target="#bashekim-ekle" type="button" role="tab" aria-controls="bashekim-ekle" aria-selected="false">
                    Başhekim Ekle
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="doktorlari-listele-tab" data-bs-toggle="tab" data-bs-target="#doktorlari-listele" type="button" role="tab" aria-controls="doktorlari-listele" aria-selected="false">
                    Doktorları Listele
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="bashekimleri-listele-tab" data-bs-toggle="tab" data-bs-target="#bashekimleri-listele" type="button" role="tab" aria-controls="bashekimleri-listele" aria-selected="false">
                    Başhekimleri Listele
                </button>
            </li>
        </ul>

        <div class="tab-content border border-top-0 p-4 bg-white shadow-sm">
            <div class="tab-pane fade show active" id="doktor-ekle" role="tabpanel" aria-labelledby="doktor-ekle-tab">
                <h3>Yeni Doktor Ekle</h3>
                <form method="POST" action="insert_doctor.php" novalidate>
                    <div class="mb-3">
                        <input type="text" name="username" class="form-control" placeholder="Kullanıcı Adı" required>
                    </div>
                    <div class="mb-3 row">
                        <div class="col">
                            <input type="text" name="firstname" class="form-control" placeholder="Ad" required>
                        </div>
                        <div class="col">
                            <input type="text" name="lastname" class="form-control" placeholder="Soyad" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="Şifre" required>
                    </div>
                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="E-posta" required>
                    </div>
                    <div class="mb-3">
                        <select name="hospital_id" id="hospital_id_select" class="form-select" required>
                            <option value="" selected>Hastane Seç</option>
                            <?php foreach ($hospitals as $h): ?>
                                <option value="<?php echo $h->id ?>"><?php echo htmlspecialchars($h->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <select name="polyclinic_selection_type" id="polyclinic_selection_type" class="form-select" required>
                             <option value="">Poliklinik Seçim Yöntemi</option>
                             <option value="existing">Mevcut Poliklinik Seç</option>
                             <option value="new">Yeni Poliklinik Ekle</option>
                        </select>
                    </div>

                    <div class="mb-3" id="existing_polyclinic_container" style="display: none;">
                        <select name="polyclinic_id" id="polyclinic_id_select" class="form-select">
                            <option value="">Poliklinik Yükleniyor...</option>
                        </select>
                    </div>

                    <div class="mb-3" id="new_polyclinic_container" style="display: none;">
                        <input type="text" name="polyclinic_name_new" id="polyclinic_name_new_input" class="form-control" placeholder="Yeni Poliklinik Adı">
                    </div>

                    <button type="submit" class="btn btn-primary">Doktor Ekle</button>
                </form>
            </div>

            <div class="tab-pane fade" id="bashekim-ekle" role="tabpanel" aria-labelledby="bashekim-ekle-tab">
                <h3>Yeni Başhekim Ekle</h3>
                <form method="POST" action="insert_chief.php" novalidate>
                    <div class="mb-3">
                        <input type="text" name="username" class="form-control" placeholder="Kullanıcı Adı" required>
                    </div>
                    <div class="mb-3 row">
                        <div class="col">
                            <input type="text" name="firstname" class="form-control" placeholder="Ad" required>
                        </div>
                        <div class="col">
                            <input type="text" name="lastname" class="form-control" placeholder="Soyad" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="Şifre" required>
                    </div>
                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="E-posta" required>
                    </div>
                    <div class="mb-3">
                        <select name="hospital_id" class="form-select" required>
                            <option value="" selected>Hastane Seç</option>
                            <?php foreach ($hospitals as $h): ?>
                                <option value="<?php echo $h->id ?>"><?php echo htmlspecialchars($h->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Başhekim Ekle</button>
                </form>
            </div>

            <div class="tab-pane fade" id="doktorlari-listele" role="tabpanel" aria-labelledby="doktorlari-listele-tab">
                <h3>Kayıtlı Doktorlar</h3>
                <?php include 'list_doctors.php'; // Doktorları listeleyen dosyayı dahil et ?>
            </div>

            <div class="tab-pane fade" id="bashekimleri-listele" role="tabpanel" aria-labelledby="bashekimleri-listele-tab">
                <h3>Kayıtlı Başhekimler</h3>
                <?php include 'list_chiefs.php'; // Başhekimleri listeleyen dosyayı dahil et ?>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    (function($) {
        $(document).ready(function() {
            var hospitalSelect = $('#hospital_id_select');
            var polyclinicSelectionType = $('#polyclinic_selection_type');
            var existingPolyclinicContainer = $('#existing_polyclinic_container');
            var polyclinicIdSelect = $('#polyclinic_id_select');
            var newPolyclinicContainer = $('#new_polyclinic_container');
            var newPolyclinicInput = $('#polyclinic_name_new_input');

            // Başlangıçta poliklinik seçim tiplerini gizle
            polyclinicSelectionType.parent().hide();

            // Hastane seçimi değiştiğinde
            hospitalSelect.on('change', function() {
                var hospitalId = $(this).val();
                polyclinicSelectionType.val(''); // Poliklinik seçim yöntemini sıfırla
                existingPolyclinicContainer.hide();
                newPolyclinicContainer.hide();
                polyclinicIdSelect.empty(); // Mevcut poliklinikleri temizle
                newPolyclinicInput.val(''); // Yeni poliklinik adını temizle

                if (hospitalId) {
                    polyclinicSelectionType.parent().show(); // Poliklinik seçim yöntemini göster
                    // Poliklinik seçimi için 'Mevcut' seçeneğini tıklanabilir yap
                    polyclinicSelectionType.find('option[value="existing"]').prop('disabled', false);
                    polyclinicSelectionType.prop('required', true); // Poliklinik seçim yöntemi zorunlu

                } else {
                    polyclinicSelectionType.parent().hide(); // Yoksa gizle
                    polyclinicSelectionType.prop('required', false); // Zorunluluğu kaldır
                }
            });

            // Poliklinik seçim yöntemi değiştiğinde
            polyclinicSelectionType.on('change', function() {
                var selectionType = $(this).val();
                var hospitalId = hospitalSelect.val();

                existingPolyclinicContainer.hide();
                newPolyclinicContainer.hide();
                polyclinicIdSelect.prop('required', false);
                newPolyclinicInput.prop('required', false);

                if (selectionType === 'existing') {
                    if (hospitalId) {
                        existingPolyclinicContainer.show();
                        polyclinicIdSelect.empty().append('<option value="">Yükleniyor...</option>').prop('disabled', true);

                        $.ajax({
                            url: 'get_polyclinics.php', // AJAX dosyasının yolu
                            type: 'GET',
                            data: { hospital_id: hospitalId },
                            dataType: 'json',
                            success: function(data) {
                                polyclinicIdSelect.empty().append('<option value="">Poliklinik Seç</option>');
                                if (data.length > 0) {
                                    $.each(data, function(key, polyclinic) {
                                        polyclinicIdSelect.append('<option value="' + polyclinic.id + '">' + polyclinic.name + '</option>');
                                    });
                                } else {
                                    polyclinicIdSelect.append('<option value="">Bu hastaneye ait poliklinik bulunmamaktadır.</option>');
                                }
                                polyclinicIdSelect.prop('disabled', false).prop('required', true);
                            },
                            error: function() {
                                polyclinicIdSelect.empty().append('<option value="">Poliklinikler yüklenemedi.</option>').prop('disabled', false);
                            }
                        });
                    } else {
                        polyclinicIdSelect.empty().append('<option value="">Lütfen önce hastane seçin.</option>');
                    }
                } else if (selectionType === 'new') {
                    newPolyclinicContainer.show();
                    newPolyclinicInput.prop('required', true);
                }
            });

            // Form gönderildiğinde, poliklinik verilerini duruma göre ayarla
            $('form[action="insert_doctor.php"]').on('submit', function() {
                var selectionType = polyclinicSelectionType.val();
                var form = $(this);

                // Gizli inputları oluşturmak için
                var hiddenPolyclinicName = $('<input type="hidden" name="polyclinic_name">');
                var hiddenPolyclinicId = $('<input type="hidden" name="polyclinic_id">');

                if (selectionType === 'existing') {
                    // Mevcut poliklinik seçildiyse, hem ID'sini hem de adını gönder
                    var selectedPolyclinicId = polyclinicIdSelect.val();
                    var selectedPolyclinicName = polyclinicIdSelect.find('option:selected').text();
                    hiddenPolyclinicId.val(selectedPolyclinicId);
                    hiddenPolyclinicName.val(selectedPolyclinicName);
                } else if (selectionType === 'new') {
                    // Yeni poliklinik girildiyse, sadece adını gönder
                    var newName = newPolyclinicInput.val();
                    hiddenPolyclinicName.val(newName);
                    // Yeni eklenenler için ID başlangıçta null veya 0 olarak kabul edilebilir.
                    // PHP tarafı zaten ad ile kontrol edip oluşturacak.
                    hiddenPolyclinicId.val(''); // ID yoksa boş gönder
                }

                form.append(hiddenPolyclinicName).append(hiddenPolyclinicId);

                // Orjinal select ve input alanlarını devre dışı bırak ki tekrar gönderilmesinler
                // veya isimleri çakışmasın
                polyclinicIdSelect.prop('disabled', true);
                newPolyclinicInput.prop('disabled', true);
                polyclinicSelectionType.prop('disabled', true);

                return true; // Formu gönder
            });

        });
    })(jQuery);
    </script>

<?php
} else {
    // Admin değilse erişim engelle
    echo $OUTPUT->notification('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'error');
}

echo $OUTPUT->footer();
?>