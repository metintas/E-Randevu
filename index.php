<?php
// Bu dosya Moodle'ın bir parçasıdır - http://moodle.org/

if (!file_exists('./config.php')) {
    header('Location: install.php');
    die;
}

require_once('config.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/filelib.php');


redirect_if_major_upgrade_required();

$redirect = optional_param('redirect', 1, PARAM_BOOL);

$urlparams = array();
if (!empty($CFG->defaulthomepage) &&
        ($CFG->defaulthomepage == HOMEPAGE_MY || $CFG->defaulthomepage == HOMEPAGE_MYCOURSES) &&
        $redirect === 0
) {
    $urlparams['redirect'] = 0;
}
$PAGE->set_url('/', $urlparams);
$PAGE->set_pagelayout('frontpage');
$PAGE->add_body_class('hospital-frontpage');
$PAGE->set_other_editing_capability('moodle/course:update');
$PAGE->set_other_editing_capability('moodle/course:manageactivities');
$PAGE->set_other_editing_capability('moodle/course:activityvisibility');

$PAGE->set_cacheable(false);

require_course_login($SITE);

$hasmaintenanceaccess = has_capability('moodle/site:maintenanceaccess', context_system::instance());

if (!empty($CFG->maintenance_enabled) and !$hasmaintenanceaccess) {
    print_maintenance_message();
}

$hassiteconfig = has_capability('moodle/site:config', context_system::instance());

if ($hassiteconfig && moodle_needs_upgrading()) {
    redirect($CFG->wwwroot .'/'. $CFG->admin .'/index.php');
}

\core\hub\registration::registration_reminder('/index.php');

$homepage = get_home_page();
if ($homepage != HOMEPAGE_SITE) {
    if (optional_param('setdefaulthome', false, PARAM_BOOL)) {
        set_user_preference('user_home_page_preference', HOMEPAGE_SITE);
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MY) && $redirect === 1) {
        redirect($CFG->wwwroot .'/my/');
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MYCOURSES) && $redirect === 1) {
        redirect($CFG->wwwroot .'/my/courses.php');
    } else if ($homepage == HOMEPAGE_URL) {
        redirect(get_default_home_page_url());
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_USER)) {
        $frontpagenode = $PAGE->settingsnav->find('frontpage', null);
        if ($frontpagenode) {
            $frontpagenode->add(
                get_string('makethismyhome'),
                new moodle_url('/', array('setdefaulthome' => true)),
                navigation_node::TYPE_SETTING);
        } else {
            $frontpagenode = $PAGE->settingsnav->add(get_string('frontpagesettings'), null, navigation_node::TYPE_SETTING, null);
            $frontpagenode->force_open();
            $frontpagenode->add(get_string('makethismyhome'),
                new moodle_url('/', array('setdefaulthome' => true)),
                navigation_node::TYPE_SETTING);
        }
    }
}

course_view(context_course::instance(SITEID));

$PAGE->set_pagetype('site-index');
$PAGE->set_docs_path('');
$PAGE->set_title('E Randevu');
$PAGE->set_heading($SITE->fullname);

$siteformatoptions = course_get_format($SITE)->get_format_options();
$modinfo = get_fast_modinfo($SITE);
$modnamesused = $modinfo->get_used_module_names();

include_course_ajax($SITE, $modnamesused);

$courserenderer = $PAGE->get_renderer('core', 'course');

if ($hassiteconfig) {
    $editurl = new moodle_url('/course/view.php', ['id' => SITEID, 'sesskey' => sesskey()]);
    $editbutton = $OUTPUT->edit_button($editurl);
}

echo $OUTPUT->header();

// --- KENDİ TASARIM KODUNUZ BURADA BAŞLIYOR ---
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');

    body.hospital-frontpage {
        background-color: #eef2f6; /* Açık gri arka plan */
        font-family: 'Poppins', sans-serif;
        color: #333;
    }
    .main-container {
        max-width: 1280px;
        margin: 30px auto;
        background-color: #ffffff;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }

    /* Slider Stilleri */
    .slider-container {
        position: relative;
        width: 100%;
        height: 450px; /* Slider yüksekliği */
        overflow: hidden;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }
    .slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 1.5s ease-in-out;
        background-size: cover;
        background-position: center;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    }
    .slide.active {
        opacity: 1;
    }
    .slide-content {
        text-align: center;
        padding: 20px;
        background: rgba(0, 0, 0, 0.4);
        border-radius: 8px;
        max-width: 800px;
    }
    .slide-content h2 {
        font-size: 3.5em;
        margin-bottom: 10px;
        font-weight: 700;
        letter-spacing: 1px;
    }
    .slide-content p {
        font-size: 1.5em;
        font-weight: 300;
        margin-bottom: 20px;
    }
    .slider-nav {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 10px;
        z-index: 10;
    }
    .nav-dot {
        width: 12px;
        height: 12px;
        background-color: rgba(255, 255, 255, 0.6);
        border-radius: 50%;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    .nav-dot.active {
        background-color: #007bff;
        border: 2px solid white;
    }

    /* Hero Section (Slider altı) */
    .hero-section {
        text-align: center;
        padding: 50px 20px;
        background-color: #f8f9fa;
        border-bottom: 1px solid #eee;
    }
    .hero-section h1 {
        font-size: 2.8em;
        color: #0056b3;
        margin-bottom: 15px;
        font-weight: 700;
    }
    .hero-section p {
        font-size: 1.2em;
        color: #555;
        max-width: 800px;
        margin: 0 auto 30px auto;
        line-height: 1.6;
    }
    .cta-button {
        display: inline-block;
        background: linear-gradient(45deg, #007bff, #0056b3);
        color: white;
        padding: 15px 40px;
        border-radius: 50px;
        text-decoration: none;
        font-size: 1.3em;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
    }
    .cta-button:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0, 123, 255, 0.4);
        background: linear-gradient(45deg, #0056b3, #007bff);
    }

    /* Hizmetler Bölümü */
    .services-section {
        padding: 60px 20px;
        text-align: center;
    }
    .services-section h2 {
        font-size: 2.5em;
        color: #0056b3;
        margin-bottom: 40px;
        font-weight: 700;
        position: relative;
    }
    .services-section h2::after {
        content: '';
        display: block;
        width: 80px;
        height: 4px;
        background-color: #007bff;
        margin: 15px auto 0;
        border-radius: 2px;
    }
    .feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 30px;
        margin-bottom: 40px;
    }
    .feature-card {
        background-color: #fdfdff;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07);
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: 1px solid #e0e6ed;
    }
    .feature-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.12);
    }
    .feature-card i {
        font-size: 3em;
        color: #007bff;
        margin-bottom: 15px;
    }
    .feature-card h3 {
        color: #333;
        font-size: 1.5em;
        margin-bottom: 10px;
        font-weight: 600;
    }
    .feature-card p {
        font-size: 1em;
        color: #666;
        line-height: 1.6;
    }


    /* Duyarlı Tasarım */
    @media (max-width: 768px) {
        .main-container {
            margin: 15px auto;
            border-radius: 0;
        }
        .slider-container {
            height: 300px;
            border-radius: 0;
        }
        .slide-content h2 {
            font-size: 2.2em;
        }
        .slide-content p {
            font-size: 1.1em;
        }
        .hero-section {
            padding: 30px 15px;
        }
        .hero-section h1 {
            font-size: 2em;
        }
        .hero-section p {
            font-size: 1em;
        }
        .cta-button {
            padding: 12px 25px;
            font-size: 1.1em;
        }
        .services-section {
            padding: 40px 15px;
        }
        .services-section h2 {
            font-size: 2em;
        }
        .feature-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .feature-card {
            padding: 20px;
        }
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<div class="main-container">
    <div class="slider-container">
        <div class="slide active" style="background-image: url('https://www.florence.com.tr/getmedia/f1324047-6762-43ba-8e3e-70235ead8bf9/hastane-enfeksiyonlari-ne-demektir_1.webp?ext=.webp');">
            <div class="slide-content">
                <h2>Sağlığınız İçin Modern Yaklaşım</h2>
                <p>Güvenilir, çağdaş ve ulaşılabilir sağlık hizmetleri sunuyoruz.</p>
            </div>
        </div>
        <div class="slide" style="background-image: url('https://st2.depositphotos.com/1000393/10757/i/950/depositphotos_107570232-stock-photo-blurry-hospital-background.jpg');">
            <div class="slide-content">
                <h2>Deneyimli Uzman Kadro</h2>
                <p>Alanında lider hekimlerimizle sağlığınız emin ellerde.</p>
            </div>
        </div>
        <div class="slide" style="background-image: url('https://st2.depositphotos.com/2065849/8219/i/450/depositphotos_82195114-stock-photo-iv-drip-on-the-background.jpg');">
            <div class="slide-content">
                <h2>Hasta Odaklı Hizmet Anlayışı</h2>
                <p>Her hastamız için kişiselleştirilmiş tedavi planları.</p>
            </div>
        </div>
        <div class="slider-nav">
            <div class="nav-dot active" data-slide="0"></div>
            <div class="nav-dot" data-slide="1"></div>
            <div class="nav-dot" data-slide="2"></div>
        </div>
    </div>

    <div class="hero-section">
        <h1><?php echo htmlspecialchars($SITE->fullname); ?>'ya Hoş Geldiniz</h1>
        <p>Gelişmiş teknolojilerle donatılmış, hasta odaklı hizmet anlayışıyla yola çıktık. Sağlığınız için en iyi hizmeti sunmak amacıyla sürekli kendimizi geliştiriyoruz.</p>
        <?php if (!isloggedin() || isguestuser()) { ?>
            <a href="<?php echo new moodle_url('/login/index.php'); ?>" class="cta-button">Giriş Yap / Kaydol</a>
        <?php } else { ?>
             <a href="<?php echo new moodle_url('/my/'); ?>" class="cta-button">Panelime Git</a>
        <?php } ?>
    </div>

    <div class="services-section">
        <h2>Hizmetlerimiz</h2>
        <div class="feature-grid">
            <div class="feature-card">
                <i class="fas fa-calendar-check"></i>
                <h3>Online Randevu</h3>
                <p>Hızlı ve kolay bir şekilde online randevu alın, doktorunuzla görüşün.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-user-md"></i>
                <h3>Uzman Doktorlar</h3>
                <p>Alanında uzman, deneyimli hekim kadromuzla sağlığınız emin ellerde.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-clipboard-list"></i>
                <h3>Sonuç Takibi</h3>
                <p>Test sonuçlarınızı ve raporlarınızı çevrimiçi takip edin.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-ambulance"></i>
                <h3>Acil Sağlık Hizmeti</h3>
                <p>Acil durumlar için 7/24 kesintisiz ve hızlı müdahale.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-balance-scale"></i>
                <h3>Hasta Hakları</h3>
                <p>Tüm hastalarımızın haklarını güvence altına alıyor, şeffaf ve adil bir hizmet sunuyoruz.</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-heartbeat"></i>
                <h3>Fiziksel Terapi & Rehabilitasyon</h3>
                <p>Kişiselleştirilmiş fizik tedavi programları ile sağlığınıza kavuşun.</p>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    // DOM içeriği tamamen yüklendiğinde çalışacak kod.
    // Moodle'ın requireJS'sine gerek kalmaz, doğrudan çalışır.
    document.addEventListener('DOMContentLoaded', function() {
        const slides = document.querySelectorAll('.slide');
        const navDots = document.querySelectorAll('.nav-dot');
        let currentSlide = 0;
        let slideInterval;

        function showSlide(index) {
            // Tüm slaytlardan 'active' sınıfını kaldır
            slides.forEach(slide => slide.classList.remove('active'));
            // Tüm navigasyon noktalarından 'active' sınıfını kaldır
            navDots.forEach(dot => dot.classList.remove('active'));

            // Belirtilen slayta ve navigasyon noktasına 'active' sınıfını ekle
            slides[index].classList.add('active');
            navDots[index].classList.add('active');
            currentSlide = index;
        }

        function nextSlide() {
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
        }

        function startSlider() {
            clearInterval(slideInterval); // Önceki interval'ı temizle
            slideInterval = setInterval(nextSlide, 5000); // Her 5 saniyede bir geçiş
        }

        // Sayfa yüklendiğinde slider'ı başlat
        showSlide(currentSlide);
        startSlider();

        // Navigasyon noktalarına tıklama olayını dinle
        navDots.forEach(dot => {
            dot.addEventListener('click', function() {
                const slideIndex = parseInt(this.dataset.slide); // data-slide özniteliğini al
                showSlide(slideIndex);
                startSlider(); // Dot tıklanınca da slider'ı yeniden başlat
            });
        });
    });
</script>

<?php
echo $OUTPUT->footer(); // Moodle'ın varsayılan footer'ını basar