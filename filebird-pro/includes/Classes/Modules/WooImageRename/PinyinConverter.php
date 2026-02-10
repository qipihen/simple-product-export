<?php
namespace FileBird\Classes\Modules\WooImageRename;

defined('ABSPATH') || exit;

class PinyinConverter {

    /**
     * 固定字段翻译映射
     * 常见产品分类和属性的英文翻译
     *
     * @var array
     */
    private static $translationMap = array(
        // 产品分类
        '便携式充电桩' => 'portable-ev-charger',
        '直流充电桩' => 'dc-ev-charger',
        '交流充电桩' => 'ac-ev-charger',
        '家用充电桩' => 'home-ev-charger',
        '公共充电桩' => 'public-ev-charger',
        '充电枪' => 'ev-charging-gun',
        '充电器' => 'ev-charger',

        // 规格参数
        '美标' => 'type1',
        '欧标' => 'type2',
        '国标' => 'gbt',
        '特斯拉' => 'tesla',
        '便携' => 'portable',
        '壁挂' => 'wall-mounted',

        // 图片类型
        '主图' => 'main',
        '枪头' => 'connector',
        '场景' => 'scene',
        '细节' => 'detail',
        '包装' => 'package',
        '安装' => 'installation',

        // 产品系列
        '宝石系列' => 'gem-series',
        '钻石系列' => 'diamond-series',
        '黄金系列' => 'gold-series',
    );

    /**
     * 中文转拼音（完整版）
     *
     * @param string $text 输入文本
     * @return string 转换后的拼音
     */
    public static function convert($text) {
        // 先尝试翻译固定字段
        $text = self::translateFixedFields($text);

        // 移除特殊字符，保留中文、字母、数字、空格
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // 转换剩余中文字符为拼音
        $pinyin = self::chineseToPinyin($text);

        return $pinyin;
    }

    /**
     * 翻译固定字段
     *
     * @param string $text 输入文本
     * @return string 翻译后的文本
     */
    private static function translateFixedFields($text) {
        foreach (self::$translationMap as $chinese => $english) {
            $text = str_replace($chinese, $english, $text);
        }
        return $text;
    }

    /**
     * 生成 URL 安全的文件名
     *
     * @param string $text 输入文本
     * @return string URL 安全的文件名
     */
    public static function toSlug($text) {
        $text = self::convert($text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text;
    }

    /**
     * 完整的中文转拼音实现
     * 常用汉字拼音映射
     *
     * @param string $text 中文文本
     * @return string 拼音文本
     */
    private static function chineseToPinyin($text) {
        // 完整的常用字拼音映射
        $map = array(
            // 数字
            '一' => 'yi', '二' => 'er', '三' => 'san', '四' => 'si', '五' => 'wu',
            '六' => 'liu', '七' => 'qi', '八' => 'ba', '九' => 'jiu', '十' => 'shi',

            // 常用字
            '的' => '', '了' => '', '是' => '', '在' => '', '有' => '', '和' => '',
            '就' => '', '不' => '', '人' => 'ren', '都' => '', '一' => 'yi',
            '一个' => '', '上' => 'shang', '也' => '', '很' => '', '到' => 'dao',
            '说' => 'shuo', '要' => 'yao', '去' => 'qu', '你' => 'ni', '会' => 'hui',
            '着' => '', '没有' => '', '看' => 'kan', '好' => 'hao', '自己' => 'ziji',
            '这' => 'zhe', '那' => 'na', '里' => 'li', '用' => 'yong', '我' => 'wo',

            // 产品相关
            '产' => 'chan', '品' => 'pin', '电' => 'dian', '子' => 'zi', '机' => 'ji',
            '充' => 'chong', '桩' => 'zhuang', '备' => 'bei', '式' => 'shi',
            '宝' => 'bao', '石' => 'shi', '系' => 'xi', '列' => 'lie',
            '瓦' => 'wa', '特' => 'te', '标' => 'biao', '枪' => 'qiang',
            '头' => 'tou', '图' => 'tu', '景' => 'jing', '装' => 'zhuang',

            // 规格相关
            '大' => 'da', '小' => 'xiao', '中' => 'zhong', '长' => 'chang',
            '短' => 'duan', '高' => 'gao', '低' => 'di', '新' => 'xin', '旧' => 'jiu',
            '型' => 'xing', '号' => 'hao', '款' => 'kuan', '版' => 'ban',

            // 颜色
            '红' => 'red', '蓝' => 'blue', '绿' => 'green', '黄' => 'yellow',
            '黑' => 'black', '白' => 'white', '灰' => 'grey', '金' => 'gold',

            // 材料
            '钢' => 'steel', '铁' => 'iron', '铝' => 'aluminum', '铜' => 'copper',
            '塑' => 'plastic', '料' => '', '胶' => 'rubber',

            // 其他常用
            '国' => 'guo', '际' => 'ji', '通' => 'tong', '用' => 'yong',
            '专' => 'zhuan', '利' => 'li', '美' => 'mei', '欧' => 'ou',
            '网' => 'wang', '络' => 'luo', '连' => 'lian', '接' => 'jie',
            '线' => 'xian', '缆' => 'lan', '控' => 'kong', '制' => 'zhi',
            '系' => 'xi', '统' => 'tong', '智' => 'zhi', '能' => 'neng',
        );

        foreach ($map as $chinese => $pinyin) {
            $text = str_replace($chinese, $pinyin === '' ? '' : $pinyin . '-', $text);
        }

        // 清理多余的分隔符
        $text = str_replace(array('---', '--'), '-', $text);
        $text = trim($text, '-');

        return $text;
    }

    /**
     * 从产品获取 URL 安全的文件名
     * 优先使用 SKU 或英文 slug
     *
     * @param int $product_id 产品 ID
     * @return string URL 安全的文件名
     */
    public static function getSlugFromProduct($product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return '';
        }

        // 优先使用 SKU
        $sku = $product->get_sku();
        if (!empty($sku)) {
            return sanitize_title($sku);
        }

        // 其次使用产品 slug
        $slug = $product->get_slug();
        if (!empty($slug)) {
            return $slug;
        }

        // 最后使用标题转换
        return self::toSlug($product->get_title());
    }
}
