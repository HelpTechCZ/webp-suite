<?php if (!defined('ABSPATH')) exit; ?>
<?php $webp_support = function_exists('imagewebp'); ?>

<div class="wrap webp-suite-wrap">
    <h1>WebP Suite</h1>
    <p class="description">Nahrajte obrázky v libovolném formátu — plugin je zmenší podle kratší strany se zachováním poměru stran, převede na WebP a originály smaže.</p>

    <?php if (!$webp_support): ?>
        <div class="notice notice-error">
            <p><strong>Chyba:</strong> PHP GD knihovna nemá podporu WebP. Plugin nebude fungovat správně.</p>
        </div>
    <?php endif; ?>

    <div class="webp-suite-card">
        <h2>Nastavení</h2>
        <table class="form-table">
            <tr>
                <th><label for="ws-short-side">Kratší strana (px)</label></th>
                <td>
                    <input type="number" id="ws-short-side" value="800" min="1" max="10000" class="small-text">
                    <p class="description">Obrázek se zmenší tak, aby kratší strana měla tento rozměr. Poměr stran zůstane zachován. Menší obrázky se nezvětšují.</p>
                </td>
            </tr>
            <tr>
                <th><label for="ws-quality">Kvalita WebP (%)</label></th>
                <td>
                    <input type="range" id="ws-quality" value="85" min="1" max="100" class="ws-range">
                    <span id="ws-quality-val">85</span>%
                </td>
            </tr>
            <tr>
                <th>Originály</th>
                <td>
                    <label>
                        <input type="checkbox" id="ws-delete-originals" checked>
                        Smazat původní soubory po konverzi
                    </label>
                </td>
            </tr>
        </table>
    </div>

    <div class="webp-suite-card">
        <h2>Upload obrázků</h2>
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
