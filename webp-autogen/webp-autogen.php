<?php
/**
 * Plugin Name: WebP Autogen
 * Description: Automatic WebP generation during upload + manual conversion of all uploads via an admin menu.
 * Version: 1.4
 * Author: jado GmbH
 */

defined('ABSPATH') or exit;

// === Admin-Menü hinzufügen ===
add_action('admin_menu', function () {
    add_menu_page(
        'WebP Autogen',
        'WebP Autogen',
        'manage_options',
        'webp-autogen',
        'webp_autogen_admin_page',
        'dashicons-format-image'
    );
});

// === Admin Page ===
function webp_autogen_admin_page() {
    ?>
    <div class="wrap">
        <h1>WebP Autogen</h1>
        <p>WebP versions are created from all existing JPG/PNG files in the upload folder. And if the plugin remains active, newly uploaded images will also be recalculated.</p>
        <h3>Please start conversion several times if there are more than 200 images!</h3>
        <form method="post">
            <?php submit_button('Start Convert Images (200)'); ?>
        </form>
        <p style="color: #777;">Note: Only works on Apache servers! Check your server here <a target="_blank" href="https://httpstatus.io">httpstatus.io</a></p>
        <div style="margin-top: 20px;">
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && current_user_can('manage_options')) {
                $result = webp_autogen_generate_existing(true);
                echo "<strong>Done:</strong> {$result['converted']} WebP files created / {$result['skipped']} skipped.";
            }
            ?>
        </div>
    </div>
    <?php
}

// === WebP generation for existing images ===
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
        if ($converted >= $limit) break; // Abbruch nach Limit
    }
    if ($return_stats) {
        return ['converted' => $converted, 'skipped' => $skipped];
    }
}

// === WebP at Upload ===
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

// === Automatic exchange in themes ===
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

// === Replace images in content with <picture> using WebP ===
add_filter('the_content', 'webp_autogen_replace_content_images');
function webp_autogen_replace_content_images($content) {
    return preg_replace_callback('/<img\s+[^>]*src=["\']([^"\']+\.(jpg|jpeg|png))["\'][^>]*>/i', function ($matches) {
        $img_tag = $matches[0];
        $src = $matches[1];
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
        $full_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $webp);
        if (!file_exists($full_path)) return $img_tag;
        return '<picture>
                    <source srcset="' . esc_url($webp) . '" type="image/webp">
                    ' . $img_tag . '
                </picture>';
    }, $content);
}

// === Add .htaccess ===
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