<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class FileMover {

    public static function moveFile($attachment_id, $new_relative_path) {
        $upload_dir = wp_upload_dir();
        $original_path = get_post_meta($attachment_id, '_wp_attached_file', true);
        $original_file = $upload_dir['basedir'] . '/' . $original_path;
        if (!file_exists($original_file)) {
            return array('success' => false, 'error' => 'Original file not found');
        }
        $new_file = $upload_dir['basedir'] . '/' . $new_relative_path;
        $new_dir = dirname($new_file);
        if (!file_exists($new_dir)) {
            wp_mkdir_p($new_dir);
        }
        if (!rename($original_file, $new_file)) {
            return array('success' => false, 'error' => 'Failed to move file');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $new_relative_path);
        $new_url = $upload_dir['baseurl'] . '/' . $new_relative_path;
        wp_update_post(array('ID' => $attachment_id, 'guid' => $new_url));
        self::moveImageSizes($attachment_id, $original_path, $new_relative_path);
        return array('success' => true, 'old_path' => $original_path, 'new_path' => $new_relative_path);
    }

    private static function moveImageSizes($attachment_id, $original_path, $new_path) {
        $upload_dir = wp_upload_dir();
        $meta_data = wp_get_attachment_metadata($attachment_id);
        if (empty($meta_data['sizes'])) {
            return;
        }
        $original_dir = dirname($original_path);
        $new_dir = dirname($new_path);
        $filename = basename($new_path);
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        foreach ($meta_data['sizes'] as $size => $size_data) {
            $old_size_file = $upload_dir['basedir'] . '/' . $original_dir . '/' . $size_data['file'];
            if (file_exists($old_size_file)) {
                $new_size_filename = $filename_without_ext . '-' . $size_data['width'] . 'x' . $size_data['height'] . '.' . $extension;
                $new_size_file = $upload_dir['basedir'] . '/' . $new_dir . '/' . $new_size_filename;
                if (rename($old_size_file, $new_size_file)) {
                    $meta_data['sizes'][$size]['file'] = $new_size_filename;
                }
            }
        }
        wp_update_attachment_metadata($attachment_id, $meta_data);
    }

    public static function getCategoryDirectoryName($product_id) {
        $categories = get_the_terms($product_id, 'product_cat');
        if (empty($categories) || is_wp_error($categories)) {
            return 'uncategorized';
        }
        $category_name = $categories[0]->name;
        return PinyinConverter::toSlug($category_name);
    }
}
