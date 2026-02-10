<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class ModuleWooImageRename {

    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // 检查 WooCommerce 是否启用
        if (!$this->isWooCommerceActive()) {
            return;
        }

        $this->initHooks();
    }

    /**
     * 初始化 Hooks
     */
    private function initHooks() {
        // 添加设置页面标签
        add_filter('fbv_settings_tabs', array($this, 'addSettingsTab'));

        // 添加设置页面内容
        add_action('fbv_settings_page_content', array($this, 'renderSettingsPage'));

        // 注册 API 路由
        add_action('rest_api_init', array($this, 'registerApiRoutes'));

        // 产品编辑页面添加按钮
        add_action('woocommerce_product_meta_end', array($this, 'addProductPageButton'));

        // 加载前端资源
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
    }

    /**
     * 检查 WooCommerce 是否启用
     */
    private function isWooCommerceActive() {
        return class_exists('WooCommerce');
    }

    /**
     * 添加设置页面标签
     */
    public function addSettingsTab($tabs) {
        $tabs['woo_rename'] = __('Woo 图片重命名', 'filebird');
        return $tabs;
    }

    /**
     * 渲染设置页面
     */
    public function renderSettingsPage($current_tab) {
        if ($current_tab !== 'woo_rename') {
            return;
        }

        include NJFB_PLUGIN_PATH . 'views/woo-image-rename/settings-page.php';
    }

    /**
     * 注册 API 路由
     */
    public function registerApiRoutes() {
        // 预览 API
        register_rest_route('filebird-woo/v1', '/preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'apiPreview'),
            'permission_callback' => array($this, 'checkPermission'),
        ));

        // 处理 API
        register_rest_route('filebird-woo/v1', '/process', array(
            'methods' => 'POST',
            'callback' => array($this, 'apiProcess'),
            'permission_callback' => array($this, 'checkPermission'),
        ));

        // 回滚 API
        register_rest_route('filebird-woo/v1', '/rollback', array(
            'methods' => 'POST',
            'callback' => array($this, 'apiRollback'),
            'permission_callback' => array($this, 'checkPermission'),
        ));

        // 历史记录 API
        register_rest_route('filebird-woo/v1', '/history', array(
            'methods' => 'GET',
            'callback' => array($this, 'apiGetHistory'),
            'permission_callback' => array($this, 'checkPermission'),
        ));

        // 获取产品列表 API
        register_rest_route('filebird-woo/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'apiGetProducts'),
            'permission_callback' => array($this, 'checkPermission'),
        ));
    }

    /**
     * 权限检查
     */
    public function checkPermission() {
        return current_user_can('manage_options');
    }

    /**
     * API: 预览更改
     */
    public function apiPreview($request) {
        $product_ids = $request->get_param('product_ids');
        $options = $request->get_param('options');

        if (empty($product_ids)) {
            return new \WP_Error('no_products', '请选择要处理的产品', array('status' => 400));
        }

        $processor = new ImageProcessor();
        $previews = array();

        foreach ($product_ids as $product_id) {
            $previews[] = $processor->previewChanges($product_id, $options);
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'previews' => $previews,
        ), 200);
    }

    /**
     * API: 处理图片
     */
    public function apiProcess($request) {
        $product_ids = $request->get_param('product_ids');
        $options = $request->get_param('options');

        if (empty($product_ids)) {
            return new \WP_Error('no_products', '请选择要处理的产品', array('status' => 400));
        }

        $processor = new ImageProcessor();
        $results = array();
        $total_success = 0;
        $total_failed = 0;
        $backup_keys = array();

        foreach ($product_ids as $product_id) {
            $result = $processor->processProduct($product_id, $options);
            $results[] = $result;
            $total_success += $result['success_count'] ?? 0;
            $total_failed += $result['failed_count'] ?? 0;

            if (!empty($result['backup_key'])) {
                $backup_keys[$product_id] = $result['backup_key'];
            }
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'backup_keys' => $backup_keys,
            'results' => $results,
        ), 200);
    }

    /**
     * API: 回滚
     */
    public function apiRollback($request) {
        $backup_key = $request->get_param('backup_key');

        if (empty($backup_key)) {
            return new \WP_Error('no_backup_key', '请提供备份键', array('status' => 400));
        }

        $result = RollbackManager::restoreFromBackup($backup_key);

        if ($result) {
            return new \WP_REST_Response(array(
                'success' => true,
                'message' => '回滚成功',
            ), 200);
        } else {
            return new \WP_Error('rollback_failed', '回滚失败，备份不存在或已过期', array('status' => 400));
        }
    }

    /**
     * API: 获取历史记录
     */
    public function apiGetHistory($request) {
        global $wpdb;

        $prefix = RollbackManager::BACKUP_OPTION_PREFIX;

        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC LIMIT 50",
                $wpdb->esc_like($prefix) . '%'
            ),
            ARRAY_A
        );

        $history = array();

        foreach ($options as $option) {
            $backup_key = str_replace($prefix, '', $option['option_name']);
            $backup_data = maybe_unserialize($option['option_value']);

            $history[] = array(
                'backup_key' => $backup_key,
                'created_at' => $backup_data['created_at'] ?? '',
                'file_count' => count($backup_data['files'] ?? array()),
            );
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'history' => $history,
        ), 200);
    }

    /**
     * API: 获取产品列表
     */
    public function apiGetProducts($request) {
        $page = $request->get_param('page') ?? 1;
        $per_page = $request->get_param('per_page') ?? 20;
        $search = $request->get_param('search') ?? '';
        $category = $request->get_param('category') ?? '';

        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'publish',
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        if (!empty($category)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $category,
                ),
            );
        }

        $products = wc_get_products($args);
        $total = wc_get_products(array_merge($args, array('posts_per_page' => -1)));

        $formatted_products = array();

        foreach ($products as $product) {
            $image_ids = array();
            $thumbnail_id = $product->get_image_id();
            if ($thumbnail_id) {
                $image_ids[] = $thumbnail_id;
            }

            $gallery_ids = $product->get_gallery_image_ids();
            if (!empty($gallery_ids)) {
                $image_ids = array_merge($image_ids, $gallery_ids);
            }

            $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));

            $formatted_products[] = array(
                'id' => $product->get_id(),
                'title' => $product->get_title(),
                'sku' => $product->get_sku(),
                'image_count' => count($image_ids),
                'categories' => $categories,
            );
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'products' => $formatted_products,
            'total' => count($total),
            'page' => $page,
            'per_page' => $per_page,
        ), 200);
    }

    /**
     * 在产品编辑页面添加按钮
     */
    public function addProductPageButton() {
        global $post;

        if (!$post || $post->post_type !== 'product') {
            return;
        }

        ?>
        <div class="fbv-woo-rename-section" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <h4 style="margin: 0 0 10px 0;"><?php esc_html_e('图片重命名', 'filebird'); ?></h4>
            <button type="button" class="button button-primary fbv-woo-rename-single-product" data-product-id="<?php echo esc_attr($post->ID); ?>">
                <?php esc_html_e('重命名此产品图片', 'filebird'); ?>
            </button>
            <span class="spinner" style="float: none; margin: 0 10px; vertical-align: middle;"></span>
        </div>
        <?php
    }

    /**
     * 加载后台资源
     */
    public function enqueueAdminAssets($hook) {
        // 只在相关页面加载
        if (strpos($hook, 'filebird') === false && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        // 加载样式
        wp_enqueue_style(
            'fbv-woo-rename-style',
            NJFB_PLUGIN_URL . 'assets/css/woo-image-rename.css',
            array(),
            NJFB_VERSION
        );

        // 加载脚本
        wp_enqueue_script(
            'fbv-woo-rename-script',
            NJFB_PLUGIN_URL . 'assets/js/woo-image-rename.js',
            array('jquery'),
            NJFB_VERSION,
            true
        );

        // 传递数据到 JS
        wp_localize_script('fbv-woo-rename-script', 'fbvWooRename', array(
            'apiUrl' => rest_url('filebird-woo/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
}
