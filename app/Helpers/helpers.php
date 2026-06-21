<?php

if (! function_exists('category_filter_url')) {
    /**
     *
     * @param string $category
     * @param string $filter
     * @param string $value
     * @return string
     */
    function category_filter_url(string $category, string $filter, string $value): string
    {
        if (in_array(strtolower($filter), ['studio', 'author', 'developers', 'developer'], true)) {
            return url('/metadata/'.rawurlencode($category).'/'.rawurlencode($filter))
                . '?' . http_build_query(['name' => $value]);
        }

        return url("/category/{$category}") . '?' . http_build_query([$filter => $value]);
    }
}
