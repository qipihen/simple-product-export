<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class AltGenerator {

    /**
     * 为产品图片生成 Alt 标签
     *
     * @param int $product_id 产品 ID
     * @param int $sequence 序号
     * @return string Alt 标签
     */
    public static function generate($product_id, $sequence = 1) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return '';
        }

        $title = $product->get_title();

        // 保留中文用于 Alt (SEO 友好)
        $alt_text = $title . ' 图片' . $sequence;

        return apply_filters('fbv_woo_image_alt', $alt_text, $product_id, $sequence);
    }

    /**
     * 更新附件的 Alt 标签
     *
     * @param int $attachment_id 附件 ID
     * @param string $alt_text Alt 标签
     * @return bool 更新是否成功
     */
    public static function updateAttachmentAlt($attachment_id, $alt_text) {
        return update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
    }

    /**
     * 批量更新产品图片的 Alt 标签
     *
     * @param int $product_id 产品 ID
     * @param array $attachment_ids 附件 ID 数组
     * @return array 更新结果
     */
    public static function batchUpdateForProduct($product_id, $attachment_ids) {
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array(),
        );

        foreach ($attachment_ids as $index => $attachment_id) {
            $sequence = $index + 1;
            $alt_text = self::generate($product_id, $sequence);

            if (self::updateAttachmentAlt($attachment_id, $alt_text)) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = array(
                    'attachment_id' => $attachment_id,
                    'error' => 'Failed to update alt tag',
                );
            }
        }

        return $results;
    }
}
