<?php

if (!class_exists('SPE_Entity_Registry')) {
    class SPE_Entity_Registry
    {
        /**
         * 获取排除配置
         *
         * @return array{post_types: string[], taxonomies: string[]}
         */
        protected function get_exclude_config()
        {
            $exclude = [
                'post_types' => [],
                'taxonomies' => [],
            ];

            if (function_exists('apply_filters')) {
                $filtered = apply_filters('spe_entity_registry_exclude', $exclude);
                if (is_array($filtered)) {
                    $exclude = array_merge($exclude, $filtered);
                }
            }

            foreach (['post_types', 'taxonomies'] as $key) {
                if (!isset($exclude[$key]) || !is_array($exclude[$key])) {
                    $exclude[$key] = [];
                    continue;
                }

                $exclude[$key] = array_values(array_unique(array_filter(array_map(function ($item) {
                    return trim((string) $item);
                }, $exclude[$key]))));
            }

            return $exclude;
        }

        /**
         * 获取公开 post type 对象
         *
         * @return array<string, object>
         */
        public function get_post_types()
        {
            if (!function_exists('get_post_types')) {
                return [];
            }

            $items = get_post_types(['public' => true], 'objects');
            if (!is_array($items)) {
                return [];
            }

            $exclude = $this->get_exclude_config();
            foreach ($exclude['post_types'] as $post_type) {
                unset($items[$post_type]);
            }

            return $items;
        }

        /**
         * 获取公开 taxonomy 对象
         *
         * @return array<string, object>
         */
        public function get_taxonomies()
        {
            if (!function_exists('get_taxonomies')) {
                return [];
            }

            $items = get_taxonomies(['public' => true], 'objects');
            if (!is_array($items)) {
                return [];
            }

            $exclude = $this->get_exclude_config();
            foreach ($exclude['taxonomies'] as $taxonomy) {
                unset($items[$taxonomy]);
            }

            return $items;
        }

        /**
         * 获取用于 UI 的实体列表
         *
         * @return array<int, array{id:string,type:string,name:string,label:string}>
         */
        public function get_entities()
        {
            $entities = [];

            foreach ($this->get_post_types() as $name => $object) {
                $entities[] = [
                    'id' => 'post:' . (string) $name,
                    'type' => 'post_type',
                    'name' => (string) $name,
                    'label' => isset($object->label) ? (string) $object->label : (string) $name,
                ];
            }

            foreach ($this->get_taxonomies() as $name => $object) {
                $entities[] = [
                    'id' => 'tax:' . (string) $name,
                    'type' => 'taxonomy',
                    'name' => (string) $name,
                    'label' => isset($object->label) ? (string) $object->label : (string) $name,
                ];
            }

            return $entities;
        }

        /**
         * 获取默认 taxonomy
         */
        public function get_default_taxonomy($preferred = 'product_cat')
        {
            $taxonomies = $this->get_taxonomies();
            if (isset($taxonomies[$preferred])) {
                return $preferred;
            }

            if (!empty($taxonomies)) {
                return (string) array_key_first($taxonomies);
            }

            return (string) $preferred;
        }
    }
}
