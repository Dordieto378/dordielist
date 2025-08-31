import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Roboto', 'Arial', 'sans-serif'], 
            },
            colors: {
                flatRed: '#ab2328',
            },
        },
    },
    plugins: [
        require('tailwind-scrollbar-hide'),
    ],
    
};
