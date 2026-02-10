<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class ImageProcessor {

    private $errors = array();
    private $success_count = 0;
    private $backup_data = array();

    /**
     * 处理单个产品的所有图片
     *
     * @param int $product_id 产品 ID
     * @param array $options 处理选项
     * @return array 处理结果
     */
    public function processProduct($product_id, $options = array()) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return array(
                'success' => false,
                'error' => 'Product not found',
            );
        }

        // 获取产品图片
        $image_ids = $this->getProductImages($product_id);

        if (empty($image_ids)) {
            return array(
                'success' => false,
                'error' => 'No images found for this product',
            );
        }

        // 创建备份
        $this->backup_data = array();
        foreach ($image_ids as $image_id) {
            $this->backup_data[] = RollbackManager::getImageBackup($image_id);
        }
        $backup_key = RollbackManager::createBackup($this->backup_data);

        // 处理每张图片
        $results = array();

        foreach ($image_ids as $index => $image_id) {
            $sequence = $index + 1;
            $result = $this->processImage($product_id, $image_id, $sequence, $options);
            $results[] = $result;

            if ($result['success']) {
                $this->success_count++;
            } else {
                $this->errors[] = array(
                    'image_id' => $image_id,
                    'error' => $result['error'],
                );
            }
        }

        return array(
            'success' => true,
            'backup_key' => $backup_key,
            'processed' => count($results),
            'success_count' => $this->success_count,
            'failed_count' => count($this->errors),
            'errors' => $this->errors,
            'results' => $results,
        );
    }

    /**
     * 处理单张图片
     *
     * @param int $product_id 产品 ID
     * @param int $attachment_id 附件 ID
     * @param int $sequence 序号
     * @param array $options 处理选项
     * @return array 处理结果
     */
    private function processImage($product_id, $attachment_id, $sequence, $options) {
        $result = array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'steps' => array(),
        );

        try {
            // 1. 生成新文件名
            if (!empty($options['rename_files'])) {
                $original_path = get_post_meta($attachment_id, '_wp_attached_file', true);
                $extension = pathinfo($original_path, PATHINFO_EXTENSION);
                $new_filename = FileRenamer::generateNewFileName($product_id, $attachment_id, $sequence, $extension);

                $result['steps'][] = 'Generated new filename: ' . $new_filename;
            }

            // 2. 移动文件到新目录
            if (!empty($options['move_files'])) {
                $category_dir = FileMover::getCategoryDirectoryName($product_id);
                $new_relative_path = $category_dir . '/' . ($new_filename ?? basename($original_path));

                $move_result = FileMover::moveFile($attachment_id, $new_relative_path);

                if (!$move_result['success']) {
                    throw new \Exception($move_result['error']);
                }

                $result['steps'][] = 'Moved to: ' . $new_relative_path;
            }

            // 3. 重命名附件
            if (!empty($options['rename_files']) && isset($new_filename)) {
                FileRenamer::renameAttachment($attachment_id, $new_filename);
                $result['steps'][] = 'Renamed attachment';
            }

            // 4. 更新 Alt 标签
            if (!empty($options['update_alt'])) {
                $alt_text = AltGenerator::generate($product_id, $sequence);
                AltGenerator::updateAttachmentAlt($attachment_id, $alt_text);
                $result['steps'][] = 'Updated alt: ' . $alt_text;
            }

            // 5. 同步到 FileBird 虚拟文件夹
            if (!empty($options['sync_folders'])) {
                $sync_result = FolderSync::syncProductImages($product_id, array($attachment_id));
                $result['folder_id'] = $sync_result['folder_id'] ?? 0;
                $result['steps'][] = 'Synced to folder ID: ' . ($sync_result['folder_id'] ?? 0);
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 获取产品的所有图片 ID
     *
     * @param int $product_id 产品 ID
     * @return array 图片 ID 数组
     */
    private function getProductImages($product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return array();
        }

        $image_ids = array();

        // 主图
        $thumbnail_id = $product->get_image_id();
        if ($thumbnail_id) {
            $image_ids[] = $thumbnail_id;
        }

        // 图库图片
        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            $image_ids = array_merge($image_ids, $gallery_ids);
        }

        return array_unique($image_ids);
    }

    /**
     * 预览将要进行的更改
     *
     * @param int $product_id 产品 ID
     * @param array $options 处理选项
     * @return array 预览结果
     */
    public function previewChanges($product_id, $options = array()) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return array(
                'success' => false,
                'error' => 'Product not found',
            );
        }

        $image_ids = $this->getProductImages($product_id);
        $category_dir = FileMover::getCategoryDirectoryName($product_id);

        $previews = array();

        foreach ($image_ids as $index => $image_id) {
            $sequence = $index + 1;
            $current_path = get_post_meta($image_id, '_wp_attached_file', true);
            $extension = pathinfo($current_path, PATHINFO_EXTENSION);
            $new_filename = FileRenamer::generateNewFileName($product_id, $image_id, $sequence, $extension);
            $new_path = $category_dir . '/' . $new_filename;
            $alt_text = AltGenerator::generate($product_id, $sequence);

            $previews[] = array(
                'attachment_id' => $image_id,
                'current_path' => $current_path,
                'new_path' => $new_path,
                'new_alt' => $alt_text,
            );
        }

        return array(
            'success' => true,
            'product_id' => $product_id,
            'product_title' => $product->get_title(),
            'category' => $category_dir,
            'total_images' => count($image_ids),
            'previews' => $previews,
        );
    }

    /**
     * 获取错误信息
     *
     * @return array 错误信息数组
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * 获取成功处理数量
     *
     * @return int 成功数量
     */
    public function getSuccessCount() {
        return $this->success_count;
    }

    /**
     * 重置处理器状态
     *
     * @return void
     */
    public function reset() {
        $this->errors = array();
        $this->success_count = 0;
        $this->backup_data = array();
    }

    /**
     * 批量处理多个产品
     *
     * @param array $product_ids 产品 ID 数组
     * @param array $options 处理选项
     * @return array 批量处理结果
     */
    public function processBatch($product_ids, $options = array()) {
        $results = array();
        $total_processed = 0;
        $total_success = 0;
        $total_failed = 0;

        foreach ($product_ids as $product_id) {
            $this->reset();
            $result = $this->processProduct($product_id, $options);

            $results[$product_id] = $result;
            $total_processed++;

            if ($result['success']) {
                $total_success += $result['success_count'];
                $total_failed += $result['failed_count'];
            } else {
                $total_failed++;
            }
        }

        return array(
            'success' => true,
            'total_products' => count($product_ids),
            'total_processed' => $total_processed,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'results' => $results,
        );
    }
}
