<?php
/**
 * Plugin Name: WebP Suite
 * Description: Hromadný upload obrázků s resize podle kratší strany, konverzí na WebP a smazáním originálů.
 * Version: 1.1.0
 * Author: HelpTech.cz
 * Text Domain: webp-suite
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEBP_SUITE_VERSION', '1.1.0');
define('WEBP_SUITE_DIR', plugin_dir_path(__FILE__));
define('WEBP_SUITE_URL', plugin_dir_url(__FILE__));

class WebP_Suite {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_webp_suite_process', [$this, 'ajax_process_image']);
        // Povolit upload WebP ve WordPress
        add_filter('mime_types', [$this, 'allow_webp_upload']);
        add_filter('upload_mimes', [$this, 'allow_webp_upload']);
    }

    public function allow_webp_upload($mimes) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    public function add_admin_page() {
        add_media_page(
            'WebP Suite',
            'WebP Suite',
            'upload_files',
            'webp-suite',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'media_page_webp-suite') {
            return;
        }
        wp_enqueue_style('webp-suite', WEBP_SUITE_URL . 'assets/style.css', [], WEBP_SUITE_VERSION);
        wp_enqueue_script('webp-suite', WEBP_SUITE_URL . 'assets/script.js', ['jquery'], WEBP_SUITE_VERSION, true);
        wp_localize_script('webp-suite', 'webpSuite', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('webp_suite'),
        ]);
    }

    public function render_page() {
        if (!current_user_can('upload_files')) {
            wp_die('Nemáte oprávnění.');
        }
        include WEBP_SUITE_DIR . 'views/admin-page.php';
    }

    /** Max velikost uploadu: 20 MB */
    const MAX_UPLOAD_SIZE = 20 * 1024 * 1024;

    /** Max pixelový budget (šířka × výška) — ochrana proti memory exhaustion */
    const MAX_PIXEL_BUDGET = 25000000; // 25 Mpx = ~100 MB v GD

    /** Max rozměr zdrojového obrázku (px) — ochrana proti decompression bomb */
    const MAX_SOURCE_DIMENSION = 16000;

    /** Povolené MIME typy obrázků */
    const ALLOWED_MIMES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'image/bmp', 'image/x-ms-bmp', 'image/webp', 'image/avif',
    ];

    public function ajax_process_image() {
        check_ajax_referer('webp_suite', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Nemáte oprávnění.');
        }

        if (empty($_FILES['image'])) {
            wp_send_json_error('Žádný soubor.');
        }

        $file = $_FILES['image'];

        // Kontrola chyby uploadu
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Upload selhal s chybou: ' . $file['error']);
        }

        // Omezení velikosti souboru
        if ($file['size'] > self::MAX_UPLOAD_SIZE) {
            wp_send_json_error('Soubor je příliš velký (max 20 MB).');
        }

        // Ověření skutečného MIME typu (podle obsahu, ne přípony)
        $real_mime = wp_get_image_mime($file['tmp_name']);
        if (!$real_mime || !in_array($real_mime, self::ALLOWED_MIMES, true)) {
            wp_send_json_error('Nepovolený typ souboru: ' . ($real_mime ?: 'neznámý'));
        }

        $short_side      = max(1, min(10000, absint($_POST['short_side'] ?? 800)));
        $quality         = max(1, min(100, absint($_POST['quality'] ?? 85)));
        $delete_original = ($_POST['delete_original'] ?? '1') === '1';

        $original_name = sanitize_file_name($file['name']);

        // Upload originálu přes WP (další vrstva validace)
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (isset($upload['error'])) {
            // Nezobrazovat plnou cestu — jen obecná hláška
            wp_send_json_error('Upload souboru selhal.');
        }

        $original_path = $upload['file'];

        // Ověření že soubor je v upload adresáři (path traversal ochrana)
        $upload_dir = wp_normalize_path(wp_upload_dir()['basedir']);
        $real_original = realpath($original_path);
        if (!$real_original || strpos(wp_normalize_path($real_original), $upload_dir . '/') !== 0) {
            @unlink($original_path);
            wp_send_json_error('Neplatná cesta souboru.');
        }

        $original_size = filesize($original_path);

        // Ochrana proti decompression bomb — kontrola zdrojových rozměrů PŘED načtením do GD
        $image_info = @getimagesize($original_path);
        if (!$image_info) {
            @unlink($original_path);
            wp_send_json_error('Nelze přečíst rozměry obrázku: ' . $original_name);
        }

        $src_w_check = $image_info[0];
        $src_h_check = $image_info[1];

        if ($src_w_check > self::MAX_SOURCE_DIMENSION || $src_h_check > self::MAX_SOURCE_DIMENSION) {
            @unlink($original_path);
            wp_send_json_error('Zdrojový obrázek je příliš velký (' . $src_w_check . 'x' . $src_h_check . ', max ' . self::MAX_SOURCE_DIMENSION . 'px).');
        }

        if ($src_w_check * $src_h_check > self::MAX_PIXEL_BUDGET) {
            @unlink($original_path);
            wp_send_json_error('Zdrojový obrázek má příliš mnoho pixelů (' . number_format($src_w_check * $src_h_check) . ', max ' . number_format(self::MAX_PIXEL_BUDGET) . ').');
        }

        // Načtení obrázku
        $src = $this->load_image($original_path);
        if (!$src) {
            @unlink($original_path);
            wp_send_json_error('Nepodporovaný formát obrázku: ' . $original_name);
        }

        // Korekce EXIF orientace (fotky z telefonu/fotoaparátu)
        $src = $this->fix_orientation($src, $original_path);

        $src_w = imagesx($src);
        $src_h = imagesy($src);

        // Proporcionální resize podle kratší strany — zachová poměr stran
        $scale = $short_side / min($src_w, $src_h);

        // Pokud je obrázek menší než cílová velikost, nezvětšovat
        if ($scale >= 1.0) {
            $dst_w = $src_w;
            $dst_h = $src_h;
        } else {
            $dst_w = (int)round($src_w * $scale);
            $dst_h = (int)round($src_h * $scale);
        }

        // Ochrana proti memory exhaustion
        if ($dst_w * $dst_h > self::MAX_PIXEL_BUDGET) {
            imagedestroy($src);
            @unlink($original_path);
            wp_send_json_error('Výsledné rozměry jsou příliš velké.');
        }

        $dst = imagecreatetruecolor($dst_w, $dst_h);
        if (!$dst) {
            imagedestroy($src);
            @unlink($original_path);
            wp_send_json_error('Nelze vytvořit cílový obrázek (nedostatek paměti).');
        }

        // Zachovat průhlednost
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

        imagedestroy($src);

        // Uložení WebP — sanitizace názvu souboru + zajištění unikátnosti
        $pathinfo = pathinfo($original_path);
        $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pathinfo['filename']);
        $webp_path = $pathinfo['dirname'] . '/' . $safe_filename . '.webp';

        // Ochrana proti přepsání existujícího souboru (race condition)
        if (file_exists($webp_path)) {
            $webp_path = $pathinfo['dirname'] . '/' . $safe_filename . '-' . wp_generate_password(6, false) . '.webp';
        }

        $webp_saved = imagewebp($dst, $webp_path, $quality);
        imagedestroy($dst);

        if (!$webp_saved || !file_exists($webp_path)) {
            @unlink($webp_path);
            @unlink($original_path);
            wp_send_json_error('Konverze na WebP selhala pro: ' . $original_name);
        }

        $webp_size = filesize($webp_path);

        // Registrace v knihovně médií
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/webp',
            'post_title'     => sanitize_text_field($pathinfo['filename']),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $webp_path);

        if (!is_wp_error($attachment_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        // Smazání originálu
        if ($delete_original) {
            @unlink($original_path);
        }

        wp_send_json_success([
            'original_name' => $original_name,
            'original_size' => size_format($original_size),
            'webp_size'     => size_format($webp_size),
            'savings'       => round((1 - $webp_size / max(1, $original_size)) * 100, 1),
            'dimensions'    => $dst_w . ' x ' . $dst_h,
            'attachment_id' => $attachment_id,
        ]);
    }

    /**
     * Opraví orientaci obrázku podle EXIF dat.
     * JPEG fotky z telefonu/fotoaparátu mají pixely uložené v landscape,
     * ale EXIF tag říká jak je otočit. GD tento tag ignoruje.
     */
    private function fix_orientation($img, string $path) {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        if (!$exif || empty($exif['Orientation'])) {
            return $img;
        }

        switch ((int)$exif['Orientation']) {
            case 2: // Zrcadlově horizontálně
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3: // Otočeno 180°
                $img = imagerotate($img, 180, 0);
                break;
            case 4: // Zrcadlově vertikálně
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5: // Zrcadlově horizontálně + otočeno 270° CW
                imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 270, 0);
                break;
            case 6: // Otočeno 90° CW (nejčastější — portrait fotka)
                $img = imagerotate($img, 270, 0);
                break;
            case 7: // Zrcadlově horizontálně + otočeno 90° CW
                imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 90, 0);
                break;
            case 8: // Otočeno 270° CW
                $img = imagerotate($img, 90, 0);
                break;
        }

        return $img;
    }

    private function load_image(string $path) {
        // Detekce MIME z obsahu souboru (ne z přípony) — konzistentní s validací v uploadu
        $mime = wp_get_image_mime($path);
        if (!$mime) {
            return false;
        }

        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                return @imagecreatefromjpeg($path);
            case 'image/png':
                $img = @imagecreatefrompng($path);
                if ($img) {
                    imagesavealpha($img, true);
                }
                return $img;
            case 'image/gif':
                return @imagecreatefromgif($path);
            case 'image/bmp':
            case 'image/x-ms-bmp':
                return @imagecreatefrombmp($path);
            case 'image/webp':
                return @imagecreatefromwebp($path);
            case 'image/avif':
                if (function_exists('imagecreatefromavif')) {
                    return @imagecreatefromavif($path);
                }
                return false;
            default:
                return false;
        }
    }
}

new WebP_Suite();

// GitHub auto-updater
require_once WEBP_SUITE_DIR . 'includes/class-github-updater.php';
new WebP_Suite_GitHub_Updater(__FILE__, 'HelpTechCZ/webp-suite');
