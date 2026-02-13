<?php

if (!class_exists('SPE_Match_Engine')) {
    class SPE_Match_Engine
    {
        /**
         * 解析单行数据匹配结果
         *
         * @param array<string, mixed> $row
         * @param array<string, callable> $callbacks
         * @param array<string, mixed> $config
         * @return array{matched_id:int|null,action:string,error:string,strategy:string}
         */
        public function resolve($row, $callbacks, $config = [])
        {
            $row = is_array($row) ? $row : [];
            $callbacks = is_array($callbacks) ? $callbacks : [];
            $config = is_array($config) ? $config : [];

            $strategies = isset($config['strategies']) && is_array($config['strategies'])
                ? $config['strategies']
                : ['id', 'slug', 'unique_meta'];

            $id_field = isset($config['id_field']) ? (string) $config['id_field'] : 'id';
            $slug_field = isset($config['slug_field']) ? (string) $config['slug_field'] : 'slug';
            $unique_meta_field = isset($config['unique_meta_field']) ? (string) $config['unique_meta_field'] : '';
            $unique_meta_key = isset($config['unique_meta_key']) ? (string) $config['unique_meta_key'] : '';
            $allow_insert = !empty($config['allow_insert']);

            foreach ($strategies as $strategy) {
                if ($strategy === 'id') {
                    $id_value = $this->normalize_numeric_id($this->read_row_value($row, $id_field));
                    if ($id_value <= 0 || !isset($callbacks['find_by_id']) || !is_callable($callbacks['find_by_id'])) {
                        continue;
                    }

                    $matched = call_user_func($callbacks['find_by_id'], $id_value);
                    $matched_id = $this->normalize_numeric_id($matched);
                    if ($matched_id > 0) {
                        return $this->result($matched_id, 'update', '', 'id');
                    }
                }

                if ($strategy === 'slug') {
                    $slug = trim((string) $this->read_row_value($row, $slug_field));
                    if ($slug === '' || !isset($callbacks['find_by_slug']) || !is_callable($callbacks['find_by_slug'])) {
                        continue;
                    }

                    $matched = call_user_func($callbacks['find_by_slug'], $slug);
                    $matched_id = $this->normalize_numeric_id($matched);
                    if ($matched_id > 0) {
                        return $this->result($matched_id, 'update', '', 'slug');
                    }
                }

                if ($strategy === 'unique_meta') {
                    if ($unique_meta_key === '' || $unique_meta_field === '') {
                        continue;
                    }

                    $meta_value = trim((string) $this->read_row_value($row, $unique_meta_field));
                    if ($meta_value === '' || !isset($callbacks['find_by_meta']) || !is_callable($callbacks['find_by_meta'])) {
                        continue;
                    }

                    $matched = call_user_func($callbacks['find_by_meta'], $unique_meta_key, $meta_value);

                    if (is_array($matched)) {
                        $ids = array_values(array_filter(array_map([$this, 'normalize_numeric_id'], $matched)));
                        if (count($ids) === 1) {
                            return $this->result((int) $ids[0], 'update', '', 'unique_meta');
                        }
                        if (count($ids) > 1) {
                            return $this->result(null, 'error', 'unique meta key matched multiple records', 'unique_meta');
                        }
                        continue;
                    }

                    $matched_id = $this->normalize_numeric_id($matched);
                    if ($matched_id > 0) {
                        return $this->result($matched_id, 'update', '', 'unique_meta');
                    }
                }
            }

            if ($allow_insert) {
                return $this->result(null, 'insert', '', 'none');
            }

            return $this->result(null, 'skip', 'record not found by configured strategies', 'none');
        }

        /**
         * @param mixed $value
         * @return int
         */
        protected function normalize_numeric_id($value)
        {
            if (is_int($value)) {
                return $value > 0 ? $value : 0;
            }

            if (is_numeric($value)) {
                $id = intval($value);
                return $id > 0 ? $id : 0;
            }

            if (is_string($value)) {
                $value = preg_replace('/[^0-9]/', '', $value);
                if ($value === '') {
                    return 0;
                }
                $id = intval($value);
                return $id > 0 ? $id : 0;
            }

            return 0;
        }

        /**
         * @param array<string, mixed> $row
         * @param string $key
         * @return mixed
         */
        protected function read_row_value($row, $key)
        {
            if ($key === '') {
                return '';
            }

            if (array_key_exists($key, $row)) {
                return $row[$key];
            }

            $target = strtolower($key);
            foreach ($row as $row_key => $row_value) {
                if (strtolower((string) $row_key) === $target) {
                    return $row_value;
                }
            }

            return '';
        }

        /**
         * @param int|null $matched_id
         * @return array{matched_id:int|null,action:string,error:string,strategy:string}
         */
        protected function result($matched_id, $action, $error, $strategy)
        {
            return [
                'matched_id' => $matched_id === null ? null : intval($matched_id),
                'action' => (string) $action,
                'error' => (string) $error,
                'strategy' => (string) $strategy,
            ];
        }
    }
}
