(function ($) {
    'use strict';

    var queue = [];
    var processing = false;

    // Quality slider
    $('#ws-quality').on('input', function () {
        $('#ws-quality-val').text(this.value);
    });

    // Dropzone events
    var $dropzone = $('#ws-dropzone');
    var $fileInput = $('#ws-file-input');

    $dropzone.on('click', function () {
        $fileInput.trigger('click');
    });

    $dropzone.on('dragover dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.addClass('ws-dragover');
    });

    $dropzone.on('dragleave drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.removeClass('ws-dragover');
    });

    $dropzone.on('drop', function (e) {
        var files = e.originalEvent.dataTransfer.files;
        addFiles(files);
    });

    $fileInput.on('change', function () {
        addFiles(this.files);
        this.value = '';
    });

    function addFiles(files) {
        for (var i = 0; i < files.length; i++) {
            if (!files[i].type.startsWith('image/')) continue;
            var file = files[i];
            var id = 'q-' + Date.now() + '-' + i;
            queue.push({ id: id, file: file });
            addToQueueUI(id, file);
        }
        updateQueueCount();
    }

    function addToQueueUI(id, file) {
        var $card = $('#ws-queue-card');
        $card.show();

        var $item = $('<div class="ws-queue-item" data-id="' + id + '">');
        var $img = $('<img>');
        var reader = new FileReader();
        reader.onload = function (e) {
            $img.attr('src', e.target.result);
        };
        reader.readAsDataURL(file);

        var $remove = $('<button class="ws-remove" title="Odebrat">&times;</button>');
        $remove.on('click', function (e) {
            e.stopPropagation();
            queue = queue.filter(function (q) { return q.id !== id; });
            $item.remove();
            updateQueueCount();
        });

        var $name = $('<div class="ws-filename">' + escHtml(file.name) + '</div>');
        $item.append($img, $remove, $name);
        $('#ws-queue-list').append($item);
    }

    function updateQueueCount() {
        $('#ws-queue-count').text(queue.length);
        if (queue.length === 0) {
            $('#ws-queue-card').hide();
        }
    }

    // Clear queue
    $('#ws-clear').on('click', function () {
        queue = [];
        $('#ws-queue-list').empty();
        updateQueueCount();
    });

    // Process all
    $('#ws-start').on('click', function () {
        if (processing || queue.length === 0) return;
        processAll();
    });

    function processAll() {
        processing = true;
        var total = queue.length;
        var done = 0;
        var errors = 0;
        var totalOriginal = 0;
        var totalWebp = 0;

        $('#ws-start, #ws-clear').prop('disabled', true);
        $('#ws-progress-card').show();
        $('#ws-results-card').show();
        $('#ws-results-body').empty();
        $('#ws-summary').empty();
        updateProgress(0, total);

        var items = queue.slice();
        queue = [];
        $('#ws-queue-list').empty();
        updateQueueCount();

        function next(index) {
            if (index >= items.length) {
                finish();
                return;
            }

            var item = items[index];
            var formData = new FormData();
            formData.append('action', 'webp_suite_process');
            formData.append('nonce', webpSuite.nonce);
            formData.append('image', item.file);
            formData.append('short_side', $('#ws-short-side').val());
            formData.append('quality', $('#ws-quality').val());
            formData.append('delete_original', $('#ws-delete-originals').is(':checked') ? '1' : '0');

            $.ajax({
                url: webpSuite.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    done++;
                    if (response.success) {
                        var d = response.data;
                        addResult(d.original_name, d.original_size, d.webp_size, d.savings + '%', d.dimensions, true);
                        totalOriginal += parseSize(d.original_size);
                        totalWebp += parseSize(d.webp_size);
                    } else {
                        errors++;
                        addResult(item.file.name, '-', '-', '-', '-', false, response.data);
                    }
                    updateProgress(done, total);
                    next(index + 1);
                },
                error: function () {
                    done++;
                    errors++;
                    addResult(item.file.name, '-', '-', '-', '-', false, 'Chyba spojení');
                    updateProgress(done, total);
                    next(index + 1);
                }
            });
        }

        function finish() {
            processing = false;
            $('#ws-start, #ws-clear').prop('disabled', false);

            var successCount = total - errors;
            var avgSavings = totalOriginal > 0
                ? Math.round((1 - totalWebp / totalOriginal) * 100)
                : 0;

            $('#ws-summary').html(
                '<strong>Hotovo!</strong> ' +
                successCount + ' z ' + total + ' obrázků úspěšně zpracováno.' +
                (totalOriginal > 0 ? ' Průměrná úspora: <strong>' + avgSavings + '%</strong>' : '') +
                (errors > 0 ? ' <span style="color:#dc3232">' + errors + ' chyb.</span>' : '')
            );
        }

        next(0);
    }

    function updateProgress(done, total) {
        var pct = total > 0 ? Math.round(done / total * 100) : 0;
        $('#ws-progress-fill').css('width', pct + '%');
        $('#ws-progress-text').text(done + ' / ' + total);
    }

    function addResult(name, origSize, webpSize, savings, dims, ok, errMsg) {
        var status = ok
            ? '<span class="ws-status-ok">OK</span>'
            : '<span class="ws-status-err">' + escHtml(errMsg || 'Chyba') + '</span>';

        var row = '<tr>' +
            '<td>' + escHtml(name) + '</td>' +
            '<td>' + escHtml(origSize) + '</td>' +
            '<td>' + escHtml(webpSize) + '</td>' +
            '<td>' + escHtml(savings) + '</td>' +
            '<td>' + escHtml(dims) + '</td>' +
            '<td>' + status + '</td>' +
            '</tr>';
        $('#ws-results-body').append(row);
    }

    function parseSize(str) {
        // "150 KB" -> bytes (approx)
        var match = str.match(/([\d.]+)\s*(B|KB|MB|GB)/i);
        if (!match) return 0;
        var num = parseFloat(match[1]);
        var unit = match[2].toUpperCase();
        var multipliers = { B: 1, KB: 1024, MB: 1048576, GB: 1073741824 };
        return num * (multipliers[unit] || 1);
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
