/**
 * Passkey 面板 Tailwind CSS v3 配置
 *
 * 产物：Passkey/assist/css/panel.css
 *
 * content 使用相对「配置文件所在目录」的路径，不含任何机器绝对路径。
 * 颜色统一转发 --booadmin-* 语义变量，明暗由后台主题变量驱动。
 *
 * 构建（在 config/Passkey 目录执行）：
 *   ../../tailwindcss-3.4.17.exe -c config/tailwind.config.js \
 *       -i config/input.css -o Passkey/assist/css/panel.css --minify
 *
 * @type {import('tailwindcss').Config}
 */

const path = require('path');

/** content 基于 __dirname 解析，与构建时的 cwd 无关 */
const src = (glob) => path.resolve(__dirname, '../Passkey', glob);

const v = (name, fallback) =>
    `color-mix(in srgb, var(--booadmin-${name}, ${fallback}) calc(<alpha-value> * 100%), transparent)`;

const backgroundColor = {
    white: v('surface', '#ffffff'),
    gray: {
        50:  v('surface-2', '#f9fafb'),
        100: v('surface-muted', '#f3f4f6'),
        200: v('active', '#e5e7eb'),
    },
};

const textColor = {
    white: v('on-accent', '#ffffff'),
    gray: {
        500: v('muted', '#6b7280'),
        600: v('text', '#4b5563'),
        700: v('text', '#374151'),
        900: v('text', '#111827'),
    },
};

const borderColor = {
    DEFAULT: v('border', '#e5e7eb'),
    gray: {
        100: v('border', '#e5e7eb'),
        200: v('border', '#e5e7eb'),
        300: v('border-strong', '#d1d5db'),
    },
};

module.exports = {
    content: [
        src('**/*.php'),
        src('**/*.js'),
    ],
    theme: {
        extend: {
            backgroundColor,
            textColor,
            borderColor,
            divideColor: borderColor,
            ringColor: Object.assign({}, borderColor, { DEFAULT: v('accent', '#5865f2') }),
            placeholderColor: textColor,
        },
    },
    plugins: [],
};
