# WooCommerce

This guide is for site admins who run a WooCommerce shop. It explains, in plain terms, what the
**On Page®** plugin does with your shop data.

WooCommerce is optional. You only need it if your integration syncs products.

## What gets synced

The plugin can create and update these WooCommerce items:

- **products**, both simple and variable
- **variations** of variable products
- **product categories**
- **product tags**
- **product brands**
- **global attributes** and their values

The plugin uses WooCommerce's own tools to save the data. Your products look and behave like
products created by hand in the WooCommerce admin.

## What a product can include

Each synced product can carry:

- name, long description and short description
- prices, stock, SKU, weight and dimensions
- a main image and a gallery
- categories, tags and a brand
- attributes
- custom fields (ACF)
- downloadable files, such as data sheets or manuals

Images and files are copied into your **Media Library**. If a file was already imported, it is
reused, not copied again.

## Documents on the product page

The plugin adds a **Documents** section to the product page. It lists the product's downloadable
files, such as a data sheet or a safety sheet.

Only files sent by the integration are shown there. Files you add by hand in WooCommerce are
not listed in that section.

For variable products, the files are also copied to each variation. This keeps the WooCommerce
admin consistent.

## Multilingual shops

If WPML is active, each product can have its own name, description and files per language. The
plugin creates or updates the translations for you. Translations are not generated automatically:
the plugin uses the text sent by the integration.

## Good to know

- Syncs are driven by the integration. You don't need to press anything in the admin.
- Products are matched by a stable On Page® identifier. Running a sync again updates the same
  product instead of creating a copy.
- If you see two categories with the same name, the identifiers are out of sync. Ask the team
  that maintains your integration to run a clean re-import.

For the technical details, see [../dev/woocommerce.md](../dev/woocommerce.md).
