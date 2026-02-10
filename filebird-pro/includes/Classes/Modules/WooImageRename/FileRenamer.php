<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class FileRenamer {

    private static $imageTypePatterns = array(
        'main' => array('主图', 'main', 'cover', '首页'),
        'connector' => array('枪头', '枪', 'connector', '插头'),
        'scene' => array('场景', 'scene', '环境', '应用'),
        'detail' => array('细节', 'detail', '详情', '特写'),
        'package' => array('包装', 'package', '包装盒'),
        'installation' => array('安装', 'installation', '安装图'),
    );

    public static function generateNewFileName($product_id, $attachment_id, $sequence, $extension) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }
        $product_slug = PinyinConverter::getSlugFromProduct($product_id);
        $image_type = self::detectImageType($attachment_id);
        if ($sequence === 1 && $image_type === 'photo') {
            $image_type = 'main';
        }
        $new_filename = $product_slug . '-' . $image_type . '-' . $sequence . '.' . $extension;
        return $new_filename;
    }

    private static function detectImageType($attachment_id) {
        $original_filename = get_post_meta($attachment_id, '_wp_attached_file', true);
        $original_filename = basename($original_filename);
        $original_title = get_post($attachment_id)->post_title;
        $search_text = strtolower($original_filename . ' ' . $original_title);
        foreach (self::$imageTypePatterns as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($search_text, strtolower($pattern)) !== false) {
                    return $type;
                }
            }
        }
        return 'photo';
    }

    public static function renameAttachment($attachment_id, $new_filename) {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return false;
        }
        $filename_without_ext = pathinfo($new_filename, PATHINFO_FILENAME);
        wp_update_post(array('ID' => $attachment_id, 'post_title' => $filename_without_ext, 'post_name' => $filename_without_ext));
        return true;
    }

    public static function getUniqueFileName($filename, $directory = '') {
        $upload_dir = wp_upload_dir();
        if (empty($directory)) {
            $directory = $upload_dir['path'];
        } else {
            $directory = $upload_dir['basedir'] . '/' . $directory;
        }
        $filepath = $directory . '/' . $filename;
        if (!file_exists($filepath)) {
            return $filename;
        }
        $filename_parts = pathinfo($filename);
        $extension = $filename_parts['extension'];
        $name = $filename_parts['filename'];
        $suffix = 2;
        do {
            $new_filename = $name . '-' . $suffix . '.' . $extension;
            $filepath = $directory . '/' . $new_filename;
            $suffix++;
        } while (file_exists($filepath));
        return $new_filename;
    }
}
