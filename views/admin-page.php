<?php if (!defined('ABSPATH')) exit; ?>
<?php
$webp_support = function_exists('imagewebp');
$settings = $this->get_settings();
settings_errors('webp_suite');
?>

<div class="wrap webp-suite-wrap">
    <h1>WebP Suite</h1>
    <p class="description">Automatická konverze obrázků na WebP při uploadu + hromadný převod.</p>

    <?php if (!$webp_support): ?>
        <div class="notice notice-error">
            <p><strong>Chyba:</strong> PHP GD knihovna nemá podporu WebP. Plugin nebude fungovat správně.</p>
        </div>
    <?php endif; ?>

    <!-- Nastavení -->
    <div class="webp-suite-card">
        <h2>Nastavení</h2>
        <form method="post">
            <?php wp_nonce_field('webp_suite_settings'); ?>
            <input type="hidden" name="webp_suite_save_settings" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="ws-auto-convert">Automatická konverze</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_convert" id="ws-auto-convert" <?php checked($settings['auto_convert']); ?>>
                            Automaticky převádět obrázky na WebP při každém uploadu (editor, média)
                        </label>
                        <p class="description">Když je zapnuto, každý JPG/PNG/GIF nahraný kdekoliv ve WordPressu se automaticky zmenší a převede na WebP.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ws-short-side">Kratší strana (px)</label></th>
                    <td>
                        <input type="number" name="short_side" id="ws-short-side" value="<?php echo esc_attr($settings['short_side']); ?>" min="1" max="10000" class="small-text">
                        <p class="description">Obrázek se zmenší tak, aby kratší strana měla tento rozměr. Poměr stran zůstane zachován. Menší obrázky se nezvětšují.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ws-quality">Kvalita WebP (%)</label></th>
                    <td>
                        <input type="range" name="quality" id="ws-quality" value="<?php echo esc_attr($settings['quality']); ?>" min="1" max="100" class="ws-range">
                        <span id="ws-quality-val"><?php echo esc_html($settings['quality']); ?></span>%
                    </td>
                </tr>
                <tr>
                    <th>Originály</th>
                    <td>
                        <label>
                            <input type="checkbox" name="delete_original" <?php checked($settings['delete_original']); ?>>
                            Smazat původní soubory po konverzi
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Uložit nastavení'); ?>
        </form>
    </div>

    <?php if ($settings['auto_convert']): ?>
        <div class="notice notice-info inline" style="margin: 0 0 16px;">
            <p><strong>Auto-konverze je zapnutá.</strong> Všechny obrázky nahrané přes editor nebo média se automaticky převedou na WebP (<?php echo esc_html($settings['short_side']); ?>px, <?php echo esc_html($settings['quality']); ?>%).</p>
        </div>
    <?php endif; ?>

    <!-- Hromadný upload -->
    <div class="webp-suite-card">
        <h2>Hromadný upload</h2>
        <div class="ws-dropzone" id="ws-dropzone">
            <div class="ws-dropzone-inner">
                <span class="dashicons dashicons-upload"></span>
                <p>Přetáhněte obrázky sem<br>nebo <strong>klikněte pro výběr</strong></p>
                <p class="description">JPG, PNG, GIF, BMP, WebP, AVIF — libovolný počet</p>
            </div>
            <input type="file" id="ws-file-input" multiple accept="image/*" style="display:none">
        </div>
    </div>

    <div class="webp-suite-card" id="ws-queue-card" style="display:none">
        <h2>Fronta <span class="ws-badge" id="ws-queue-count">0</span></h2>
        <div id="ws-queue-list" class="ws-queue-list"></div>
        <div class="ws-actions">
            <button class="button button-primary button-hero" id="ws-start">
                <span class="dashicons dashicons-admin-generic"></span> Zpracovat všechny
            </button>
            <button class="button" id="ws-clear">Vymazat frontu</button>
        </div>
    </div>

    <div class="webp-suite-card" id="ws-progress-card" style="display:none">
        <h2>Zpracovávám...</h2>
        <div class="ws-progress-bar">
            <div class="ws-progress-fill" id="ws-progress-fill"></div>
        </div>
        <p id="ws-progress-text">0 / 0</p>
    </div>

    <div class="webp-suite-card" id="ws-results-card" style="display:none">
        <h2>Výsledky</h2>
        <table class="widefat striped" id="ws-results-table">
            <thead>
                <tr>
                    <th>Soubor</th>
                    <th>Originál</th>
                    <th>WebP</th>
                    <th>Úspora</th>
                    <th>Rozměr</th>
                    <th>Stav</th>
                </tr>
            </thead>
            <tbody id="ws-results-body"></tbody>
        </table>
        <div class="ws-summary" id="ws-summary"></div>
    </div>
</div>
