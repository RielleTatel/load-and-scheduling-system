import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Institutional Atenista — see docs/design/JHS-design-system.md.
 * Cobalt chrome, canary signal, calm white workspace.
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                display: ['"Archivo Black"', '"Arial Black"', ...defaultTheme.fontFamily.sans],
                sans: ['Montserrat', ...defaultTheme.fontFamily.sans],
                data: ['Inter', ...defaultTheme.fontFamily.sans],
                script: ['"Great Vibes"', 'cursive'],
            },
            colors: {
                cobalt: '#1E22C4',
                electric: '#3B5BFF',
                navy: { DEFAULT: '#0B0B45', 800: '#12124F' },
                canary: { DEFAULT: '#FFD60A', ink: '#5A4B00' },
                parchment: '#F6EFD8',
                ink: '#14152E',
                slate: { brand: '#565A78' },
                mist: '#EEF1F8',
                line: '#DCE0EC',
                jade: '#1E9E6A',
                amber: { brand: '#E8A400' },
                rose: { brand: '#D64550' },
            },
            boxShadow: {
                card: '0 1px 2px rgba(11,11,69,.06), 0 10px 30px rgba(11,11,69,.05)',
            },
            borderRadius: {
                card: '16px',
                frame: '20px',
            },
        },
    },

    plugins: [forms],
};
