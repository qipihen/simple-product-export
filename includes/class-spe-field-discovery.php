<?php

if (!class_exists('SPE_Field_Discovery')) {
    class SPE_Field_Discovery
    {
        /**
         * 文章实体基础字段
         *
         * @return array<string, array{label:string,aliases:string[]}>
         */
        public function get_post_base_fields($post_type = 'post')
        {
            $excerpt_label = $post_type === 'product' ? '短描述' : '摘要';
            $content_label = $post_type === 'product' ? '长描述' : '内容';

            return [
                'id' => [
                    'label' => 'ID',
                    'aliases' => ['ID', 'id'],
                ],
                'title' => [
                    'label' => '标题',
                    'aliases' => ['标题', 'Title', 'title', '名称', 'name'],
                ],
                'slug' => [
                    'label' => 'Slug',
                    'aliases' => ['Slug', 'slug'],
                ],
                'excerpt' => [
                    'label' => $excerpt_label,
                    'aliases' => [$excerpt_label, 'Excerpt', 'excerpt', 'Short Description', 'short_description', '短描述', '摘要'],
                ],
                'content' => [
                    'label' => $content_label,
                    'aliases' => [$content_label, 'Content', 'content', 'Long Description', 'long_description', '长描述', '内容'],
                ],
                'meta_title' => [
                    'label' => 'Meta Title',
                    'aliases' => ['Meta Title', 'meta_title', 'title'],
                ],
                'meta_description' => [
                    'label' => 'Meta Description',
                    'aliases' => ['Meta Description', 'meta_description', 'description'],
                ],
            ];
        }

        /**
         * 分类实体基础字段
         *
         * @return array<string, array{label:string,aliases:string[]}>
         */
        public function get_taxonomy_base_fields()
        {
            return [
                'id' => [
                    'label' => 'ID',
                    'aliases' => ['ID', 'id', 'term_id'],
                ],
                'name' => [
                    'label' => '标题',
                    'aliases' => ['标题', '名称', 'name', 'Name', 'Title'],
                ],
                'slug' => [
                    'label' => 'Slug',
                    'aliases' => ['Slug', 'slug'],
                ],
                'description' => [
                    'label' => '描述',
                    'aliases' => ['描述', 'Description', 'description'],
                ],
                'parent' => [
                    'label' => '父分类 ID',
                    'aliases' => ['父分类 ID', 'parent', 'Parent'],
                ],
                'meta_title' => [
                    'label' => 'Meta Title',
                    'aliases' => ['Meta Title', 'meta_title', 'title'],
                ],
                'meta_description' => [
                    'label' => 'Meta Description',
                    'aliases' => ['Meta Description', 'meta_description', 'description'],
                ],
            ];
        }

        /**
         * 解析 post type 自定义字段
         *
         * @param string[] $meta_keys
         * @param string[] $exclude_keys
         * @return string[]
         */
        public function resolve_post_custom_fields($post_type, $meta_keys = [], $exclude_keys = [])
        {
            $meta_keys = is_array($meta_keys) ? $meta_keys : [];
            $exclude_keys = is_array($exclude_keys) ? $exclude_keys : [];

            $acf_defined = function_exists('spe_get_post_type_acf_field_names')
                ? spe_get_post_type_acf_field_names($post_type)
                : [];

            if (function_exists('spe_merge_export_custom_fields')) {
                return spe_merge_export_custom_fields($meta_keys, $exclude_keys, $acf_defined);
            }

            $custom = array_values(array_diff($meta_keys, $exclude_keys));
            $custom = array_values(array_unique(array_merge($custom, $acf_defined)));
            sort($custom);
            return $custom;
        }

        /**
         * 解析 taxonomy 自定义字段
         *
         * @param string[] $meta_keys
         * @return string[]
         */
        public function resolve_taxonomy_custom_fields($taxonomy, $meta_keys = [])
        {
            $meta_keys = is_array($meta_keys) ? $meta_keys : [];
            $meta_keys = array_values(array_filter(array_map(function ($item) {
                $item = trim((string) $item);
                if ($item === '' || strpos($item, '_') === 0) {
                    return '';
                }
                return $item;
            }, $meta_keys)));

            $exclude = ['_product_count', '_thumbnail_id'];
            $custom = array_values(array_diff($meta_keys, $exclude));

            $acf_defined = function_exists('spe_get_taxonomy_acf_field_names')
                ? spe_get_taxonomy_acf_field_names($taxonomy)
                : [];

            $required = function_exists('spe_get_taxonomy_required_custom_fields')
                ? spe_get_taxonomy_required_custom_fields()
                : [];

            $custom = array_values(array_unique(array_merge($custom, $acf_defined, $required)));
            sort($custom);
            return $custom;
        }

        /**
         * 构建 taxonomy 候选字段（用于映射）
         *
         * @param string[] $meta_keys
         * @return array<string, array{label:string,aliases:string[]}>
         */
        public function build_taxonomy_candidate_fields($taxonomy, $meta_keys = [])
        {
            $fields = $this->get_taxonomy_base_fields();
            foreach ($this->resolve_taxonomy_custom_fields($taxonomy, $meta_keys) as $field_name) {
                $key = 'field:' . $field_name;
                $fields[$key] = [
                    'label' => $field_name,
                    'aliases' => [$field_name],
                ];
            }
            return $fields;
        }

        /**
         * 构建 post 候选字段（用于映射）
         *
         * @param string[] $meta_keys
         * @param string[] $exclude_keys
         * @return array<string, array{label:string,aliases:string[]}>
         */
        public function build_post_candidate_fields($post_type, $meta_keys = [], $exclude_keys = [])
        {
            $fields = $this->get_post_base_fields($post_type);
            foreach ($this->resolve_post_custom_fields($post_type, $meta_keys, $exclude_keys) as $field_name) {
                $key = 'field:' . $field_name;
                $fields[$key] = [
                    'label' => $field_name,
                    'aliases' => [$field_name],
                ];
            }
            return $fields;
        }
    }
}
