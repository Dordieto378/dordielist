<?php

if (! function_exists('category_filter_url')) {
    /**
     * Build a filter URL for category browsing.
     *
     * @param string $category  e.g. 'animes', 'mangas'
     * @param string $filter    e.g. 'genre', 'studio', 'author', 'tags'
     * @param string $value
     * @return string
     */
    function category_filter_url(string $category, string $filter, string $value): string
    {
        // This assumes you already have routes like: /browse/{category}?{filter}={value}
        return url("/browse/{$category}") . '?' . http_build_query([$filter => $value]);
    }
}
