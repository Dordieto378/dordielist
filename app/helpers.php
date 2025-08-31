<?php
use Illuminate\Support\Str;

if (! function_exists('category_filter_url')) {
    /**
     * Build a url like /category/animes?tags=Romance
     *
     * @param  string $category    
     * @param  string $filterKey   
     * @param  string $filterValue  
     * @return string
     */
    function category_filter_url(string $category, string $filterKey, string $filterValue): string
    {
        $slug   = Str::slug($category);             // “Visual-Novel” → “visual-novel”
        $base   = route('category', ['category' => $slug], false);
        return "{$base}?{$filterKey}=" . urlencode($filterValue);
    }
}
