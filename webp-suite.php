<?php
/**
 * Plugin Name: WebP Suite
 * Description: Automatická konverze obrázků na WebP při uploadu + hromadný převod. Resize podle kratší strany, zachování poměru stran.
 * Version: 1.2.0
 * Author: HelpTech.cz
 * Text Domain: webp-suite
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEBP_SUITE_VERSION', '1.2.0');
define('WEBP_SUITE_DIR', plugin_dir_path(__FILE__));
define('WEBP_SUITE_URL', plugin_dir_url(__FILE__));

class WebP_Suite {

    /** Max velikost uploadu: 20 MB */
    const MAX_UPLOAD_SIZE = 20 * 1024 * 1024;

    /** Max pixelový budget (šířka × výška) */
    const MAX_PIXEL_BUDGET = 25000000;

    /** Max rozměr zdrojového obrázku (px) */
    const MAX_SOURCE_DIMENSION = 16000;

    /** Povolené MIME typy pro konverzi */
    const CONVERTIBLE_MIMES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'image/bmp', 'image/x-ms-bmp', 'image/avif',
    ];

    /** Výchozí nastavení */
    const DEFAULTS = [
        'short_side'    => 800,
        'quality'       => 85,
        'auto_convert'  => false,
        'delete_original' => true,
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'save_settings']);
        add_action('wp_ajax_webp_suite_process', [$this, 'ajax_process_image']);

        // Povolit upload WebP
        add_filter('mime_types', [$this, 'allow_webp_upload']);
        add_filter('upload_mimes', [$this, 'allow_webp_upload']);

        // Auto-konverze při uploadu
        add_filter('wp_handle_upload', [$this, 'auto_convert_on_upload']);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('webp_suite_settings', []), self::DEFAULTS);
    }

    public function allow_webp_upload($mimes) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    // =========================================================================
    // Admin stránka
    // =========================================================================

    public function add_admin_page() {
        add_media_page('WebP Suite', 'WebP Suite', 'upload_files', 'webp-suite', [$this, 'render_page']);
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

    public function save_settings() {
        if (!isset($_POST['webp_suite_save_settings'])) {
            return;
        }
        check_admin_referer('webp_suite_settings');
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = [
            'short_side'      => max(1, min(10000, absint($_POST['short_side'] ?? 800))),
            'quality'         => max(1, min(100, absint($_POST['quality'] ?? 85))),
            'auto_convert'    => isset($_POST['auto_convert']),
            'delete_original' => isset($_POST['delete_original']),
        ];

        update_option('webp_suite_settings', $settings);

        add_settings_error('webp_suite', 'settings_saved', 'Nastavení uloženo.', 'success');
    }

    // =========================================================================
    // Auto-konverze při uploadu (editor, média)
    // =========================================================================

    public function auto_convert_on_upload(array $upload): array {
        $settings = $this->get_settings();

        if (!$settings['auto_convert']) {
            return $upload;
        }

        // Nepřevádět pokud už je WebP nebo není obrázek ke konverzi
        $mime = $upload['type'] ?? '';
        if (!in_array($mime, self::CONVERTIBLE_MIMES, true)) {
            return $upload;
        }

        $original_path = $upload['file'];

        // Bezpečnostní kontroly
        if (!file_exists($original_path)) {
            return $upload;
        }

        $result = $this->process_image($original_path, $settings['short_side'], $settings['quality']);
        if (!$result) {
            return $upload;
        }

        // Smazat originál
        if ($settings['delete_original'] && $result['webp_path'] !== $original_path) {
            @unlink($original_path);
        }

        // Vrátit WebP místo originálu — WordPress zaregistruje WebP jako attachment
        $upload['file'] = $result['webp_path'];
        $upload['type'] = 'image/webp';
        $upload['url']  = str_replace(
            wp_normalize_path(wp_upload_dir()['basedir']),
            wp_upload_dir()['baseurl'],
            wp_normalize_path($result['webp_path'])
        );

        return $upload;
    }

    // =========================================================================
    // AJAX handler pro hromadný upload
    // =========================================================================

    public function ajax_process_image() {
        check_ajax_referer('webp_suite', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Nemáte oprávnění.');
        }

        if (empty($_FILES['image'])) {
            wp_send_json_error('Žádný soubor.');
        }

        $file = $_FILES['image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Upload selhal s chybou: ' . $file['error']);
        }

        if ($file['size'] > self::MAX_UPLOAD_SIZE) {
            wp_send_json_error('Soubor je příliš velký (max 20 MB).');
        }

        $real_mime = wp_get_image_mime($file['tmp_name']);
        if (!$real_mime || !in_array($real_mime, array_merge(self::CONVERTIBLE_MIMES, ['image/webp']), true)) {
            wp_send_json_error('Nepovolený typ souboru: ' . ($real_mime ?: 'neznámý'));
        }

        $short_side      = max(1, min(10000, absint($_POST['short_side'] ?? 800)));
        $quality         = max(1, min(100, absint($_POST['quality'] ?? 85)));
        $delete_original = ($_POST['delete_original'] ?? '1') === '1';

        $original_name = sanitize_file_name($file['name']);

        // Dočasně vypnout auto-konverzi aby se nezpracoval dvakrát
        remove_filter('wp_handle_upload', [$this, 'auto_convert_on_upload']);

        $upload = wp_handle_upload($file, ['test_form' => false]);

        // Vrátit filtr
        add_filter('wp_handle_upload', [$this, 'auto_convert_on_upload']);

        if (isset($upload['error'])) {
            wp_send_json_error('Upload souboru selhal.');
        }

        $original_path = $upload['file'];
        $original_size = filesize($original_path);

        // Path traversal ochrana
        $upload_dir = wp_normalize_path(wp_upload_dir()['basedir']);
        $real_original = realpath($original_path);
        if (!$real_original || strpos(wp_normalize_path($real_original), $upload_dir . '/') !== 0) {
            @unlink($original_path);
            wp_send_json_error('Neplatná cesta souboru.');
        }

        $result = $this->process_image($original_path, $short_side, $quality);
        if (!$result) {
            @unlink($original_path);
            wp_send_json_error('Konverze selhala pro: ' . $original_name);
        }

        // Registrace v knihovně médií
        $pathinfo = pathinfo($result['webp_path']);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/webp',
            'post_title'     => sanitize_text_field($pathinfo['filename']),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $result['webp_path']);

        if (!is_wp_error($attachment_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $result['webp_path']);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        if ($delete_original && $result['webp_path'] !== $original_path) {
            @unlink($original_path);
        }

        $webp_size = filesize($result['webp_path']);

        wp_send_json_success([
            'original_name' => $original_name,
            'original_size' => size_format($original_size),
            'webp_size'     => size_format($webp_size),
            'savings'       => round((1 - $webp_size / max(1, $original_size)) * 100, 1),
            'dimensions'    => $result['width'] . ' x ' . $result['height'],
            'attachment_id' => $attachment_id,
        ]);
    }

    // =========================================================================
    // Sdílená logika zpracování obrázku
    // =========================================================================

    /**
     * Zpracuje obrázek: resize podle kratší strany + konverze na WebP.
     *
     * @return array{webp_path: string, width: int, height: int}|false
     */
    private function process_image(string $file_path, int $short_side, int $quality) {
        // Bezpečnostní kontroly rozměrů
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return false;
        }

        $src_w = $image_info[0];
        $src_h = $image_info[1];

        if ($src_w > self::MAX_SOURCE_DIMENSION || $src_h > self::MAX_SOURCE_DIMENSION) {
            return false;
        }
        if ($src_w * $src_h > self::MAX_PIXEL_BUDGET) {
            return false;
        }

        // Načtení obrázku
        $src = $this->load_image($file_path);
        if (!$src) {
            return false;
        }

        // Korekce EXIF orientace
        $src = $this->fix_orientation($src, $file_path);
        $src_w = imagesx($src);
        $src_h = imagesy($src);

        // Proporcionální resize podle kratší strany
        $scale = $short_side / min($src_w, $src_h);

        if ($scale >= 1.0) {
            $dst_w = $src_w;
            $dst_h = $src_h;
        } else {
            $dst_w = (int)round($src_w * $scale);
            $dst_h = (int)round($src_h * $scale);
        }

        if ($dst_w * $dst_h > self::MAX_PIXEL_BUDGET) {
            imagedestroy($src);
            return false;
        }

        $dst = imagecreatetruecolor($dst_w, $dst_h);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
        imagedestroy($src);

        // Uložení WebP
        $pathinfo = pathinfo($file_path);
        $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pathinfo['filename']);
        $webp_path = $pathinfo['dirname'] . '/' . $safe_filename . '.webp';

        if (file_exists($webp_path)) {
            $webp_path = $pathinfo['dirname'] . '/' . $safe_filename . '-' . wp_generate_password(6, false) . '.webp';
        }

        $saved = imagewebp($dst, $webp_path, $quality);
        imagedestroy($dst);

        if (!$saved || !file_exists($webp_path)) {
            @unlink($webp_path);
            return false;
        }

        return [
            'webp_path' => $webp_path,
            'width'     => $dst_w,
            'height'    => $dst_h,
        ];
    }

    // =========================================================================
    // Pomocné metody
    // =========================================================================

    private function fix_orientation($img, string $path) {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        if (!$exif || empty($exif['Orientation'])) {
            return $img;
        }

        switch ((int)$exif['Orientation']) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = imagerotate($img, 180, 0);
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 270, 0);
                break;
            case 6:
                $img = imagerotate($img, 270, 0);
                break;
            case 7:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 90, 0);
                break;
            case 8:
                $img = imagerotate($img, 90, 0);
                break;
        }

        return $img;
    }

    private function load_image(string $path) {
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
