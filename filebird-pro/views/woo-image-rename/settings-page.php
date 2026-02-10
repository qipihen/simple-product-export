<?php
/**
 * FileBird Pro - WooCommerce 图片重命名设置页面
 *
 * @package FileBird
 */

defined('ABSPATH') || exit;
?>

<div class="fbv-woo-rename-settings" id="fbv-woo-rename-app">
    <h2><?php esc_html_e('WooCommerce 图片重命名设置', 'filebird'); ?></h2>

    <div class="fbv-woo-rename-section">
        <h3><?php esc_html_e('批量处理', 'filebird'); ?></h3>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e('选择要处理的产品', 'filebird'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="fbv_woo_product_selection" value="all" checked>
                        <?php esc_html_e('全部产品', 'filebird'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="fbv_woo_product_selection" value="category">
                        <?php esc_html_e('指定分类', 'filebird'); ?>
                        <select name="fbv_woo_category" id="fbv-woo-category-select" disabled>
                            <option value=""><?php esc_html_e('选择分类', 'filebird'); ?></option>
                            <?php
                            $categories = get_terms(array(
                                'taxonomy' => 'product_cat',
                                'hide_empty' => false,
                            ));
                            foreach ($categories as $category) {
                                echo '<option value="' . esc_attr($category->slug) . '">' . esc_html($category->name) . '</option>';
                            }
                            ?>
                        </select>
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="fbv_woo_product_selection" value="manual">
                        <?php esc_html_e('手动输入产品 IDs', 'filebird'); ?>
                        <input type="text" name="fbv_woo_product_ids" id="fbv-woo-product-ids" placeholder="123, 456, 789" disabled>
                    </label>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e('处理选项', 'filebird'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="fbv_woo_options[rename_files]" value="1" checked>
                        <?php esc_html_e('重命名文件', 'filebird'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="fbv_woo_options[move_files]" value="1" checked>
                        <?php esc_html_e('创建物理文件夹', 'filebird'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="fbv_woo_options[update_alt]" value="1" checked>
                        <?php esc_html_e('更新 Alt 标签', 'filebird'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="fbv_woo_options[sync_folders]" value="1" checked>
                        <?php esc_html_e('同步到 FileBird 虚拟文件夹', 'filebird'); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e('预览模式', 'filebird'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="fbv_woo_preview" value="1" id="fbv-woo-preview-mode">
                        <?php esc_html_e('仅预览，不实际修改', 'filebird'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="button" class="button button-secondary" id="fbv-woo-preview-btn">
                <?php esc_html_e('预览更改', 'filebird'); ?>
            </button>
            <button type="button" class="button button-primary" id="fbv-woo-process-btn">
                <?php esc_html_e('开始处理', 'filebird'); ?>
            </button>
            <span class="fbv-woo-loading spinner" style="display: none;"></span>
        </p>
    </div>

    <div class="fbv-woo-rename-section" id="fbv-woo-results" style="display: none;">
        <h3><?php esc_html_e('处理结果', 'filebird'); ?></h3>
        <div id="fbv-woo-results-content"></div>
    </div>

    <div class="fbv-woo-rename-section">
        <h3><?php esc_html_e('处理历史', 'filebird'); ?></h3>
        <p>
            <button type="button" class="button button-secondary" id="fbv-woo-load-history-btn">
                <?php esc_html_e('加载历史记录', 'filebird'); ?>
            </button>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('日期', 'filebird'); ?></th>
                    <th><?php esc_html_e('状态', 'filebird'); ?></th>
                    <th><?php esc_html_e('处理数量', 'filebird'); ?></th>
                    <th><?php esc_html_e('操作', 'filebird'); ?></th>
                </tr>
            </thead>
            <tbody id="fbv-woo-history-body">
                <tr>
                    <td colspan="4"><?php esc_html_e('点击上方按钮加载历史记录', 'filebird'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // 处理产品选择方式变化
    $('input[name="fbv_woo_product_selection"]').on('change', function() {
        var value = $(this).val();
        $('#fbv-woo-category-select, #fbv-woo-product-ids').prop('disabled', true);

        if (value === 'category') {
            $('#fbv-woo-category-select').prop('disabled', false);
        } else if (value === 'manual') {
            $('#fbv-woo-product-ids').prop('disabled', false);
        }
    });

    // 获取产品 IDs
    function getProductIds() {
        var selection = $('input[name="fbv_woo_product_selection"]:checked').val();

        if (selection === 'all') {
            // 全部产品 - 需要通过 API 获取
            return null; // 将在 API 中处理
        } else if (selection === 'category') {
            // 按分类 - 需要通过 API 获取
            return $('#fbv-woo-category-select').val();
        } else {
            // 手动输入
            var ids = $('#fbv-woo-product-ids').val().split(',').map(function(id) {
                return parseInt(id.trim());
            }).filter(function(id) {
                return !isNaN(id);
            });
            return ids;
        }
    }

    // 获取处理选项
    function getOptions() {
        return {
            rename_files: $('input[name="fbv_woo_options[rename_files]"]').is(':checked'),
            move_files: $('input[name="fbv_woo_options[move_files]"]').is(':checked'),
            update_alt: $('input[name="fbv_woo_options[update_alt]"]').is(':checked'),
            sync_folders: $('input[name="fbv_woo_options[sync_folders]"]').is(':checked'),
        };
    }

    // 预览按钮
    $('#fbv-woo-preview-btn').on('click', function() {
        $('.fbv-woo-loading').show();
        $('#fbv-woo-results').hide();

        var selection = $('input[name="fbv_woo_product_selection"]:checked').val();
        var requestData = {
            product_selection: selection,
            category: $('#fbv-woo-category-select').val(),
            product_ids: getProductIds(),
            options: getOptions()
        };

        // 如果是全部产品或分类产品，先获取产品列表
        if (selection === 'all' || selection === 'category') {
            // 调用产品 API 获取 IDs
            getProductsAndPreview(requestData);
        } else {
            // 直接预览
            callPreviewApi(requestData);
        }
    });

    // 处理按钮
    $('#fbv-woo-process-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('确定要开始处理吗？此操作将修改文件。', 'filebird'); ?>')) {
            return;
        }

        $('.fbv-woo-loading').show();
        $('#fbv-woo-results').hide();

        var selection = $('input[name="fbv_woo_product_selection"]:checked').val();
        var requestData = {
            product_selection: selection,
            category: $('#fbv-woo-category-select').val(),
            product_ids: getProductIds(),
            options: getOptions()
        };

        // 如果是全部产品或分类产品，先获取产品列表
        if (selection === 'all' || selection === 'category') {
            getProductsAndProcess(requestData);
        } else {
            callProcessApi(requestData);
        }
    });

    // 获取产品并预览
    function getProductsAndPreview(requestData) {
        var productRequest = {
            page: 1,
            per_page: 100, // 可以根据需要调整
            category: requestData.category
        };

        $.ajax({
            url: fbvWooRename.apiUrl + 'products',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            data: productRequest,
            success: function(response) {
                var productIds = response.products.map(function(p) { return p.id; });
                requestData.product_ids = productIds;
                callPreviewApi(requestData);
            },
            error: function(xhr) {
                alert('Error loading products: ' + (xhr.responseJSON.message || 'Unknown error'));
                $('.fbv-woo-loading').hide();
            }
        });
    }

    // 获取产品并处理
    function getProductsAndProcess(requestData) {
        var productRequest = {
            page: 1,
            per_page: 100,
            category: requestData.category
        };

        $.ajax({
            url: fbvWooRename.apiUrl + 'products',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            data: productRequest,
            success: function(response) {
                var productIds = response.products.map(function(p) { return p.id; });
                requestData.product_ids = productIds;
                callProcessApi(requestData);
            },
            error: function(xhr) {
                alert('Error loading products: ' + (xhr.responseJSON.message || 'Unknown error'));
                $('.fbv-woo-loading').hide();
            }
        });
    }

    // 调用预览 API
    function callPreviewApi(requestData) {
        $.ajax({
            url: fbvWooRename.apiUrl + 'preview',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            data: JSON.stringify({
                product_ids: requestData.product_ids,
                options: requestData.options
            }),
            contentType: 'application/json',
            success: function(response) {
                displayPreviewResults(response);
            },
            error: function(xhr) {
                alert('Error: ' + (xhr.responseJSON.message || xhr.statusText));
            },
            complete: function() {
                $('.fbv-woo-loading').hide();
            }
        });
    }

    // 调用处理 API
    function callProcessApi(requestData) {
        $.ajax({
            url: fbvWooRename.apiUrl + 'process',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            data: JSON.stringify({
                product_ids: requestData.product_ids,
                options: requestData.options
            }),
            contentType: 'application/json',
            success: function(response) {
                displayProcessResults(response);
                // 自动加载历史记录
                loadHistory();
            },
            error: function(xhr) {
                alert('Error: ' + (xhr.responseJSON.message || xhr.statusText));
            },
            complete: function() {
                $('.fbv-woo-loading').hide();
            }
        });
    }

    // 显示预览结果
    function displayPreviewResults(response) {
        var html = '<p><strong><?php esc_html_e('预览结果', 'filebird'); ?></strong></p>';

        if (response.previews && response.previews.length > 0) {
            response.previews.forEach(function(preview) {
                if (preview.success) {
                    html += '<h4>' + preview.product_title + ' (' + preview.total_images + ' <?php esc_html_e('张图片', 'filebird'); ?>)</h4>';
                    html += '<p><?php esc_html_e('目标分类:', 'filebird'); ?> ' + preview.category + '</p>';
                    html += '<table class="wp-list-table widefat fixed striped">';
                    html += '<thead><tr><th><?php esc_html_e('当前路径', 'filebird'); ?></th><th><?php esc_html_e('新路径', 'filebird'); ?></th><th><?php esc_html_e('新 Alt', 'filebird'); ?></th></tr></thead>';
                    html += '<tbody>';
                    preview.previews.forEach(function(item) {
                        html += '<tr>';
                        html += '<td>' + item.current_path + '</td>';
                        html += '<td>' + item.new_path + '</td>';
                        html += '<td>' + item.new_alt + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                } else {
                    html += '<p class="error">' + preview.error + '</p>';
                }
            });
        } else {
            html += '<p><?php esc_html_e('没有找到预览数据', 'filebird'); ?></p>';
        }

        $('#fbv-woo-results-content').html(html);
        $('#fbv-woo-results').show();
    }

    // 显示处理结果
    function displayProcessResults(response) {
        var html = '<div class="notice notice-success"><p><strong><?php esc_html_e('处理完成', 'filebird'); ?></strong></p>';
        html += '<p><?php esc_html_e('成功:', 'filebird'); ?> ' + response.total_success + '</p>';
        html += '<p><?php esc_html_e('失败:', 'filebird'); ?> ' + response.total_failed + '</p>';

        if (response.backup_keys && Object.keys(response.backup_keys).length > 0) {
            html += '<p><?php esc_html_e('备份已创建，可以使用以下备份键进行回滚:', 'filebird'); ?></p>';
            html += '<ul>';
            for (var productId in response.backup_keys) {
                html += '<li><?php esc_html_e('产品', 'filebird'); ?> ' + productId + ': ' + response.backup_keys[productId] + '</li>';
            }
            html += '</ul>';
        }

        html += '</div>';

        $('#fbv-woo-results-content').html(html);
        $('#fbv-woo-results').show();
    }

    // 加载历史记录
    function loadHistory() {
        $.ajax({
            url: fbvWooRename.apiUrl + 'history',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            success: function(response) {
                displayHistory(response);
            },
            error: function(xhr) {
                alert('Error loading history: ' + (xhr.responseJSON.message || 'Unknown error'));
            }
        });
    }

    $('#fbv-woo-load-history-btn').on('click', loadHistory);

    // 显示历史记录
    function displayHistory(response) {
        var html = '';

        if (response.history && response.history.length > 0) {
            response.history.forEach(function(item) {
                html += '<tr>';
                html += '<td>' + item.created_at + '</td>';
                html += '<td><span class="status-active"><?php esc_html_e('已完成', 'filebird'); ?></span></td>';
                html += '<td>' + item.file_count + ' <?php esc_html_e('个文件', 'filebird'); ?></td>';
                html += '<td><button type="button" class="button button-small" data-backup-key="' + item.backup_key + '"><?php esc_html_e('回滚', 'filebird'); ?></button></td>';
                html += '</tr>';
            });
        } else {
            html = '<tr><td colspan="4"><?php esc_html_e('暂无历史记录', 'filebird'); ?></td></tr>';
        }

        $('#fbv-woo-history-body').html(html);

        // 绑定回滚按钮
        $('#fbv-woo-history-body button[data-backup-key]').on('click', function() {
            var backupKey = $(this).data('backup-key');
            if (confirm('<?php esc_html_e('确定要回滚此操作吗？', 'filebird'); ?>')) {
                rollbackBackup(backupKey);
            }
        });
    }

    // 回滚备份
    function rollbackBackup(backupKey) {
        $('.fbv-woo-loading').show();

        $.ajax({
            url: fbvWooRename.apiUrl + 'rollback',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fbvWooRename.nonce);
            },
            data: JSON.stringify({
                backup_key: backupKey
            }),
            contentType: 'application/json',
            success: function(response) {
                alert('<?php esc_html_e('回滚成功！', 'filebird'); ?>');
                loadHistory(); // 刷新历史记录
            },
            error: function(xhr) {
                alert('Error: ' + (xhr.responseJSON.message || xhr.statusText));
            },
            complete: function() {
                $('.fbv-woo-loading').hide();
            }
        });
    }
});
</script>
