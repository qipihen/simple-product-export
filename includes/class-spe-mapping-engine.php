<?php

if (!class_exists('SPE_Mapping_Engine')) {
    class SPE_Mapping_Engine
    {
        /**
         * 自动映射 CSV 表头
         *
         * @param string[] $headers
         * @param array<string, array{aliases?: string[]}> $candidate_fields
         * @param array<string, string|int> $manual_overrides
         * @return array{map: array<string,int>, unmapped: string[], header_index: array<string,int>}
         */
        public function auto_map_headers($headers, $candidate_fields, $manual_overrides = [])
        {
            $headers = is_array($headers) ? $headers : [];
            $candidate_fields = is_array($candidate_fields) ? $candidate_fields : [];
            $manual_overrides = is_array($manual_overrides) ? $manual_overrides : [];

            $header_index = $this->build_header_index($headers);
            $map = [];
            $unmapped = [];
            $used_indexes = [];

            foreach ($candidate_fields as $field_key => $meta) {
                $idx = $this->resolve_manual_override($manual_overrides[$field_key] ?? null, $header_index, $used_indexes);

                if ($idx === null) {
                    $aliases = [];
                    if (is_array($meta) && isset($meta['aliases']) && is_array($meta['aliases'])) {
                        $aliases = $meta['aliases'];
                    }
                    $aliases = $this->normalize_aliases($field_key, $aliases);
                    $idx = $this->find_alias_index($aliases, $header_index, $used_indexes);
                }

                if ($idx !== null) {
                    $map[$field_key] = $idx;
                    $used_indexes[$idx] = true;
                } else {
                    $unmapped[] = (string) $field_key;
                }
            }

            return [
                'map' => $map,
                'unmapped' => $unmapped,
                'header_index' => $header_index,
            ];
        }

        /**
         * @param string[] $headers
         * @return array<string, int>
         */
        protected function build_header_index($headers)
        {
            $index = [];
            foreach ($headers as $idx => $header) {
                $key = strtolower(trim((string) $header));
                if ($key === '' || isset($index[$key])) {
                    continue;
                }
                $index[$key] = (int) $idx;
            }
            return $index;
        }

        /**
         * @param string[] $aliases
         * @param array<string, int> $header_index
         * @param array<int, bool> $used_indexes
         * @return int|null
         */
        protected function find_alias_index($aliases, $header_index, $used_indexes = [])
        {
            foreach ($aliases as $alias) {
                $key = strtolower(trim((string) $alias));
                if ($key !== '' && isset($header_index[$key])) {
                    $idx = (int) $header_index[$key];
                    if (!isset($used_indexes[$idx])) {
                        return $idx;
                    }
                }
            }
            return null;
        }

        /**
         * @param mixed $manual
         * @param array<string, int> $header_index
         * @param array<int, bool> $used_indexes
         * @return int|null
         */
        protected function resolve_manual_override($manual, $header_index, $used_indexes = [])
        {
            if (is_int($manual)) {
                if (!isset($used_indexes[$manual])) {
                    return $manual;
                }
                return null;
            }

            if (is_string($manual)) {
                $key = strtolower(trim($manual));
                if ($key !== '' && isset($header_index[$key])) {
                    $idx = (int) $header_index[$key];
                    if (!isset($used_indexes[$idx])) {
                        return $idx;
                    }
                }
            }

            return null;
        }

        /**
         * @param string $field_key
         * @param string[] $aliases
         * @return string[]
         */
        protected function normalize_aliases($field_key, $aliases)
        {
            $normalized = [];

            foreach ($aliases as $alias) {
                $alias = trim((string) $alias);
                if ($alias === '') {
                    continue;
                }
                $normalized[] = $alias;
            }

            $normalized[] = (string) $field_key;
            if (strpos((string) $field_key, 'field:') === 0) {
                $normalized[] = substr((string) $field_key, 6);
            }

            return array_values(array_unique($normalized));
        }
    }
}
