<?php
/**
 * Plugin Name: WebP Autogen
 * Description: Automatic WebP generation during upload + manual conversion of all uploads via an admin menu.
 * Version: 1.5
 * Author: jado GmbH
 */

defined('ABSPATH') or exit;



// === Admin-Menü ===
add_action('admin_menu', function () {
    add_options_page(
        'WebP Autogen',              // Page Title (oben im <h1>)
        'WebP Autogen',              // Menü-Label in der Sidebar
        'manage_options',            // Capability
        'webp-autogen',              // Menü-Slug
        'webp_autogen_admin_page'   // Callback-Funktion
    );
});

// === Admin Page ===
// === Admin Page ===
function webp_autogen_admin_page() {
    echo '<div class="wrap"><h1>WebP Autogen</h1>';
    echo '<p>WebP Autogen Status: <span style="background-color: green; color: white; padding: 0.5em; border-radius: 4px;">ON</span> - Newly uploaded images will be converted automatically.</p>';
    echo '<p>WebP files are created from all existing JPG/PNG files in the upload folder.</p>';
    echo '<div id="webp-status">Ready to Convert</div>';
    echo '<button id="webp-start" class="button button-primary" style="margin-top: 1em;">Start WebP Conversion of all images already uploaded</button>';
    echo '<div id="webp-progress-wrapper" style="margin-top:20px;display:none;">
            <div style="background:#f3f3f3;border:1px solid #ccc;width:100%;height:30px;">
              <div id="webp-progress-inner" style="background:#46b450;height:100%;width:0%;color:white;text-align:center;line-height:30px;">0%</div>
            </div>
          </div>';
    echo '<p style="color:#777;">Note: Works only on Apache servers. Check your server: <a target="_blank" href="https://httpstatus.io">httpstatus.io</a></p>';
    echo '</div>';
}

// === AJAX Handler ===
add_action('wp_ajax_webp_autogen_convert_batch', function () {
    error_log('AJAX Request received');  // Debugging-Ausgabe in Log
    $result = webp_autogen_generate_existing(true);
    wp_send_json($result);
});

// === WebP generation (batch) ===
function webp_autogen_generate_existing($return_stats = false, $limit = 200) {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir));
    $converted = 0;
    $skipped = 0;
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;
        $source = $file->getPathname();
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $source);
        if (file_exists($webp)) {
            $skipped++;
            continue;
        }
        $editor = wp_get_image_editor($source);
        if (!is_wp_error($editor)) {
            $editor->save($webp, 'image/webp');
            $converted++;
        }
        if ($converted >= $limit) break;
    }

    $status = webp_autogen_count_status();
    return [
        'converted_now' => $converted,
        'skipped_now'   => $skipped,
        'total'         => $status['total'],
        'converted'     => $status['converted'],
        'remaining'     => $status['remaining'],
    ];
}

// === Status Counter ===
function webp_autogen_count_status() {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir));
    $total = 0;
    $converted = 0;
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

        $total++;
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file->getPathname());
        if (file_exists($webp)) $converted++;
    }
    return [
        'total'     => $total,
        'converted' => $converted,
        'remaining' => $total - $converted,
    ];
}

// === WebP creation on upload ===
add_filter('wp_generate_attachment_metadata', 'webp_autogen_create_on_upload', 10, 2);
function webp_autogen_create_on_upload($metadata, $attachment_id) {
    $file = get_attached_file($attachment_id);
    $image = wp_get_image_editor($file);
    if (!is_wp_error($image)) {
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
        $image->save($webp, 'image/webp');
    }
    if (!empty($metadata['sizes'])) {
        $pathinfo = pathinfo($file);
        foreach ($metadata['sizes'] as $size) {
            $resized_file = $pathinfo['dirname'] . '/' . $size['file'];
            $resized_editor = wp_get_image_editor($resized_file);
            if (!is_wp_error($resized_editor)) {
                $resized_webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $resized_file);
                $resized_editor->save($resized_webp, 'image/webp');
            }
        }
    }
    return $metadata;
}

// === Replace image in front-end ===
add_filter('wp_get_attachment_image_src', 'webp_autogen_filter_image_src', 10, 4);
function webp_autogen_filter_image_src($image, $attachment_id, $size, $icon) {
    if (!is_array($image)) return $image;
    $original_url = $image[0];
    $webp_url = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp', $original_url);
    $webp_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $webp_url);
    if (file_exists($webp_path)) {
        $image[0] = $webp_url;
    }
    return $image;
}


// AJAX-Handler
add_action('wp_ajax_webp_autogen_get_converted_count', function () {
    $status = webp_autogen_count_status();
    wp_send_json_success([
        'converted' => $status['converted'], 
        'total' => $status['total'],         
    ]);
});

// === Replace images in post content ===
add_filter('the_content', 'webp_autogen_replace_content_images');
function webp_autogen_replace_content_images($content) {
    return preg_replace_callback('/<img\s+[^>]*src=["\']([^"\']+\.(jpg|jpeg|png))["\'][^>]*>/i', function ($matches) {
        $img_tag = $matches[0];
        $src = $matches[1];
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
        $full_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $webp);
        if (!file_exists($full_path)) return $img_tag;
        return '<picture><source srcset="' . esc_url($webp) . '" type="image/webp">' . $img_tag . '</picture>';
    }, $content);
}

// === .htaccess Regeln ===
register_activation_hook(__FILE__, 'webp_autogen_update_htaccess');
function webp_autogen_update_htaccess() {
    if (strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') === false) return;
    $htaccess_file = ABSPATH . '.htaccess';
    if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) return;
    $marker = "# BEGIN WebP Autogen";
    $rules = <<<HTACCESS
$marker
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png)$
  RewriteCond %{DOCUMENT_ROOT}/\$1.webp -f
  RewriteRule ^(.+)\.(jpe?g|png)$ \$1.webp [T=image/webp,E=accept:1]
</IfModule>
<IfModule mod_headers.c>
  Header append Vary Accept env=REDIRECT_accept
</IfModule>
# END WebP Autogen
HTACCESS;

    $contents = file_get_contents($htaccess_file);
    if (strpos($contents, $marker) === false) {
        file_put_contents($htaccess_file, $contents . "\n\n" . $rules);
    }
}

add_action('admin_footer', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'webp-autogen') return;

    global $pagenow;
    if ($pagenow !== 'upload.php' && (!isset($_GET['page']) || $_GET['page'] !== 'webp-autogen')) return;

    // Übergebe die AJAX-URL an das JavaScript
    ?>
    <script>
        // PHP-Variable in JavaScript setzen
        const myAjaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";

        document.addEventListener('DOMContentLoaded', function () {
            const convertBtn = document.getElementById('webp-start');
            const barInner = document.getElementById('webp-progress-inner');
            const progressWrapper = document.getElementById('webp-progress-wrapper');
            const status = document.getElementById('webp-status');

            // Hole die Anzahl der bereits konvertierten Bilder
            fetch(myAjaxUrl + '?action=webp_autogen_get_converted_count')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const converted = data.data.converted;
                        const total = data.data.total;
                        if (total > 0) {
                            status.innerHTML = `<p><strong>Converted:</strong> <span style="color: green;">${converted} / ${total}</span> | <strong>Remaining:</strong> ${total - converted}</p>`;
                        } else {
                            status.innerHTML = '<p>No images found to convert.</p>';
                        }
                    } else {
                        status.innerHTML = '<p>Unable to retrieve conversion status.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error fetching converted count:', error);
                    status.innerHTML = '<p>Error fetching conversion status.</p>';
                });

            let lastProgress = 0;  // Variable zum Speichern des letzten Fortschritts

            function runConversion() {
                console.log("Button clicked, starting conversion...");

                // Button deaktivieren
                convertBtn.disabled = true;
                convertBtn.innerHTML = 'Conversion in Progress...';  // Button-Text ändern

                progressWrapper.style.display = 'block'; // Fortschritt sichtbar machen

                // Initiale Anzeige
                barInner.style.width = '0%';
                barInner.textContent = '0%';

                // AJAX-Request für die WebP-Konvertierung
                fetch(myAjaxUrl + '?action=webp_autogen_convert_batch')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.total > 0) {
                            let percent = Math.round((data.converted / data.total) * 100);

                            if (percent >= lastProgress + 5) {
                                lastProgress = percent;
                                barInner.style.width = percent + '%';
                                barInner.textContent = percent + '%';
                            }

                            status.innerHTML = `<p><strong>Converted:</strong> <span style="color: green;">${data.converted} / ${data.total} </span>| Remaining: <strong>${data.remaining}</strong></p>`;

                            if (data.remaining > 0) {
                                setTimeout(runConversion, 1000);  // Ein Neustart nach 1 Sekunde
                            } else {
                                barInner.style.width = '100%';
                                barInner.textContent = '100%';
                                alert('✅ WebP conversion complete!');
                                convertBtn.disabled = false;  
                                convertBtn.innerHTML = 'Start WebP Conversion of all images already uploaded'; 
                            }
                        } else {
                            status.innerHTML = '<p>No images found to convert.</p>';
                            convertBtn.disabled = false;  
                            convertBtn.innerHTML = 'Start WebP Conversion of all images already uploaded';
                        }
                    })
                    .catch(error => {
                        console.error('Error during AJAX request:', error);
                        status.innerHTML = `<p>Error occurred during conversion: ${error.message}</p>`;
                        convertBtn.disabled = false;  
                        convertBtn.innerHTML = 'Start WebP Conversion of all images already uploaded'; 
                    });
            }

            convertBtn.addEventListener('click', runConversion);
        });
    </script>
    <style>
        #webp-progress-wrapper {
            display: block;
            margin-top: 20px;
        }
        #webp-progress-inner {
            background-color: #46b450;
            color: white;
            font-weight: bold;
            text-align: center;
            line-height: 30px;
            transition: width 0.5s ease-in-out;
        }
        #webp-progress-wrapper {
            width: 100%;
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 4px;
            height: 30px;
        }
    </style>
    <?php
});