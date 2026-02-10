<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class RollbackManager {

    const BACKUP_OPTION_PREFIX = 'fbv_woo_rename_backup_';

    public static function createBackup($files) {
        $backup_key = time();
        $backup_data = array('created_at' => current_time('mysql'), 'files' => $files);
        update_option(self::BACKUP_OPTION_PREFIX . $backup_key, $backup_data);
        return $backup_key;
    }

    public static function getImageBackup($attachment_id) {
        $original_path = get_post_meta($attachment_id, '_wp_attached_file', true);
        $original_title = get_post($attachment_id)->post_title;
        $original_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $original_guid = get_post($attachment_id)->guid;
        return array('attachment_id' => $attachment_id, 'original_path' => $original_path, 'original_title' => $original_title, 'original_alt' => $original_alt, 'original_guid' => $original_guid);
    }

    public static function restoreFromBackup($backup_key) {
        $backup = get_option(self::BACKUP_OPTION_PREFIX . $backup_key);
        if (!$backup) {
            return false;
        }
        foreach ($backup['files'] as $file) {
            self::restoreImage($file);
        }
        self::deleteBackup($backup_key);
        return true;
    }

    private static function restoreImage($backup_data) {
        $attachment_id = $backup_data['attachment_id'];
        if (!empty($backup_data['new_path']) && file_exists($backup_data['new_path'])) {
            $upload_dir = wp_upload_dir();
            $old_path = $upload_dir['basedir'] . '/' . $backup_data['original_path'];
            if (!file_exists(dirname($old_path))) {
                wp_mkdir_p(dirname($old_path));
            }
            rename($backup_data['new_path'], $old_path);
        }
        update_post_meta($attachment_id, '_wp_attached_file', $backup_data['original_path']);
        wp_update_post(array('ID' => $attachment_id, 'post_title' => $backup_data['original_title'], 'guid' => $backup_data['original_guid']));
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $backup_data['original_alt']);
        return true;
    }

    public static function deleteBackup($backup_key) {
        return delete_option(self::BACKUP_OPTION_PREFIX . $backup_key);
    }

    public static function cleanupOldBackups() {
        global $wpdb;
        $thirty_days_ago = time() - (30 * DAY_IN_SECONDS);
        $options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::BACKUP_OPTION_PREFIX) . '%'));
        $cleaned = 0;
        foreach ($options as $option_name) {
            $backup_key = str_replace(self::BACKUP_OPTION_PREFIX, '', $option_name);
            $backup = get_option($option_name);
            if ($backup && strtotime($backup['created_at']) < $thirty_days_ago) {
                self::deleteBackup($backup_key);
                $cleaned++;
            }
        }
        return $cleaned;
    }
}
