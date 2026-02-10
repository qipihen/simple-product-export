<?php
namespace FileBird\Classes\Modules\WooImageRename;

use FileBird\Model\Folder as FolderModel;

defined('ABSPATH') || exit;

class FolderSync {

    public static function getOrCreateCategoryFolder($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }
        $categories = get_the_terms($product_id, 'product_cat');
        if (empty($categories) || is_wp_error($categories)) {
            return 0;
        }
        $main_category = $categories[0];
        $folder_name = $main_category->name;
        $existing_folder = FolderModel::detail($folder_name, 0);
        if ($existing_folder) {
            return $existing_folder->id;
        }
        $result = FolderModel::newFolder($folder_name, 0);
        return $result ? $result['id'] : 0;
    }

    public static function syncImagesToFolder($folder_id, $attachment_ids) {
        if (empty($attachment_ids) || $folder_id <= 0) {
            return false;
        }
        FolderModel::setFoldersForPosts($attachment_ids, $folder_id);
        return true;
    }

    public static function syncProductImages($product_id, $attachment_ids) {
        $folder_id = self::getOrCreateCategoryFolder($product_id);
        if ($folder_id <= 0) {
            return array('success' => false, 'error' => 'Failed to create or get category folder');
        }
        $result = self::syncImagesToFolder($folder_id, $attachment_ids);
        return array('success' => $result, 'folder_id' => $folder_id, 'folder_name' => $folder_id > 0 ? FolderModel::findById($folder_id, 'name')->name : '');
    }
}
