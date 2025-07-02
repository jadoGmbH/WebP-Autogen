<?php
/**
 * Plugin Name: WebP Autogen
 * Description: Automatic WebP generation during upload + manual conversion of all uploads via an admin menu.
 * Version: 1.6
 * Author: jado GmbH
 */

defined('ABSPATH') or exit;

add_action('plugins_loaded', function () {
    load_plugin_textdomain('webp-autogen', false, dirname(plugin_basename(__FILE__)) . '/languages');
});


// === Admin-Menü ===
add_action('admin_menu', function () {
    add_options_page(
        'WebP Autogen',
        'WebP Autogen',
        'manage_options',
        'webp-autogen',
        'webp_autogen_admin_page'
    );
});

// === Admin Page ===
function webp_autogen_admin_page()
{
    echo '<div class="wrap"><h1>' . esc_html__('WebP Autogen', 'webp-autogen') . '</h1>';
    echo '<p>WebP Autogen Status: <span style="background-color: #77940f; color: white; padding: 0.5em; border-radius: 3px;">ON</span> - Newly uploaded images will be converted automatically.</p>';
    echo '<p>' . esc_html__('WebP files are created from all existing JPG/PNG files in the upload folder.', 'webp-autogen') . '</p>';
    echo '<div id="webp-status">' . esc_html__('Ready to Convert', 'webp-autogen') . '</div>';
    echo '<button id="webp-start" class="button button-primary" style="margin-top: 1em;"><p>' . esc_html__('Start WebP Conversion of all images already uploaded', 'webp-autogen') . '</p></button>';
    echo '<div id="webp-progress-wrapper" style="border:1px solid #ccc;margin-top:20px;border-radius: 3px;display:none;">
            <div style="background:#f3f3f3;border-radius: 2px;width:100%;height:30px;">
              <div id="webp-progress-inner" style="background:#77940f;height:100%;width:0;border-radius: 2px;color:white;text-align:center;line-height:30px;">0%</div>
            </div>
          </div>';
    echo '<p style="color:#777;">' . esc_html__('Note: Works only on Apache servers. Check your server:', 'webp-autogen') . '</p>';
    echo '<a style="display: inline-block;" target="_blank" href="https://httpstatus.io">httpstatus.io</a>';
    echo '</div>';
}

// === AJAX Handler ===
add_action('wp_ajax_webp_autogen_convert_batch', function () {
    //error_log('AJAX Request received');
    $result = webp_autogen_generate_existing(true);
    wp_send_json($result);
});

// === WebP generation (batch) ===
function webp_autogen_generate_existing($return_stats = false, $limit = 200)
{
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
        'skipped_now' => $skipped,
        'total' => $status['total'],
        'converted' => $status['converted'],
        'remaining' => $status['remaining'],
    ];
}

// === Status Counter ===
function webp_autogen_count_status()
{
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
        'total' => $total,
        'converted' => $converted,
        'remaining' => $total - $converted,
    ];
}

// === WebP creation on upload ===
add_filter('wp_generate_attachment_metadata', 'webp_autogen_create_on_upload', 10, 2);
function webp_autogen_create_on_upload($metadata, $attachment_id)
{
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
function webp_autogen_filter_image_src($image, $attachment_id, $size, $icon)
{
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

add_action('admin_enqueue_scripts', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'webp-autogen') return;
    wp_enqueue_script(
        'webp-autogen-js',
        plugin_dir_url(__FILE__) . 'js/webp-autogen.js',
        ['wp-i18n'], // wichtig für wp.i18n!
        '1.0',
        true
    );

    wp_localize_script('webp-autogen-js', 'webpAutogen', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);

    wp_set_script_translations('webp-autogen-js', 'webp-autogen');
});

// === Replace images in post content ===
add_filter('the_content', 'webp_autogen_replace_content_images');
function webp_autogen_replace_content_images($content)
{
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
function webp_autogen_update_htaccess()
{
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
    //global $pagenow;
    //if ($pagenow !== 'upload.php' && (!isset($_GET['page']) || $_GET['page'] !== 'webp-autogen')) return;
    ?>
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
            border-radius: 3px;
            height: 30px;
        }

        button p{
         margin: 0;
        }

        .spinnera {
            display: inline-block;
            position: relative;
            left: 5px;
            top: 1px;
            height: 12px;
            width: 60px;
            background-image: linear-gradient(#3c434a 12px, transparent 0),
            linear-gradient(#3c434a 12px, transparent 0),
            linear-gradient(#3c434a 12px, transparent 0),
            linear-gradient(#3c434a 12px, transparent 0);
            background-repeat: no-repeat;
            background-size: 12px auto;
            background-position: 0 0, 12px 0, 24px 0, 36px 0;
            animation: pgfill 1s linear infinite;
        }

        @keyframes pgfill {
            0% {
                background-image: linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0);
            }
            25% {
                background-image: linear-gradient(#77940f 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0);
            }
            50% {
                background-image: linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#77940f 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0);
            }
            75% {
                background-image: linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#77940f 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0);
            }
            100% {
                background-image: linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#C5CFC4FF 12px, transparent 0), linear-gradient(#77940f 12px, transparent 0);
            }
        }
    </style>
    <?php
});