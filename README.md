## Novac for WooCommerce

## Requirements

1. Node v20+
2. pnpm
3. WPCLI
4. Webpack

## Account Setup

1. Setup for Account by entering your API key and Checkout preferences.

## Build Process for Developers

Our plugin uses modern build tools, including Webpack and UglifyJS, to generate production-ready JavaScript and CSS. The following scripts define our build process:

```json
{
  "prebuild": "pnpm install && composer install",
  "build": "pnpm run preuglify && pnpm run uglify && composer run makepot && pnpm run build:webpack && pnpm run plugin-zip",
  "build:webpack": "wp-scripts build",
  "start": "pnpm run start:webpack",
  "start:webpack": "rimraf build/* && wp-scripts start",
  "preuglify": "rm -f $pnpm_package_config_assets_js_min",
  "uglify": "for f in $pnpm_package_config_assets_js_js; do file=${f%.js}; node_modules/.bin/uglifyjs $f -c -m > $file.min.js; done"
}
```

Developers can reproduce the build by following these steps:

### Steps to Reproducing the build process Plugin zip.

1. git clone https://github.com/Novac-Finance/novac-woocommerce-plugin
2. /bin/setup.sh
3. pnpm install
4. pnpm build
5. Download the plugin zip from here: https://github.com/Novac-Finance/novac-woocommerce-plugin/releases

### Links to unminified

- assets/js/checkout.js
- assets/blocks/index.js
- assets/admin/settings/index.js
- assets/editor/index.js
