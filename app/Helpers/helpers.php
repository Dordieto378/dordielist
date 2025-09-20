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
        return url("/category/{$category}") . '?' . http_build_query([$filter => $value]);
    }
}
