# WooCommerce

This guide is for site admins who run a WooCommerce shop. It explains, in plain terms, what the
**On Page®** plugin does with your shop data.

WooCommerce is optional. You only need it if your client syncs products.

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

A new synced product is **published** unless the client sends another status. Later syncs keep
the product's current status unless the client sends one: if you set a synced product to draft
by hand, it stays a draft.

Images and files are copied into your **Media Library**. If a file was already imported, it is
reused, not copied again. The plugin imports images, video, audio, PDF files, office documents,
text and CSV files, archives and SVG images. SVG images are cleaned first: anything that could run
code on your site is removed.

## Attributes

The client manages the product's own attributes. It can also set **global attributes** on a
product: the ones listed under **Products > Attributes**. Their values fill the choice menus that
customers use to pick a variation on the product page. The values must already exist under that
attribute in WooCommerce.

When the product is synced again:

- A global attribute that the client sends replaces the one on the product, even if you added it
  by hand.
- A global attribute that the client sends empty is removed from the product.
- If the client removes all the attributes of a product, the global ones go too, even those you
  added by hand.
- A global attribute that the client does not mention is kept as it is, together with the
  variations built on it. So global attributes you add by hand are safe, unless the client sends
  the same one.

## Documents on the product page

The plugin adds a section to the product page with the heading **Documents**. The heading is
always in English. It lists the product's downloadable files, such as
a data sheet or a safety sheet.

Only files sent by the client are shown there. Files you add by hand in WooCommerce are
not listed in that section.

The client decides, file by file, whether it is public. A file marked as not public, such as the
file of a digital product you sell, is not listed. WooCommerce gives it only to customers who
bought the product.

For variable products, the files are also copied to each variation. This keeps the WooCommerce
admin consistent. If you set different files on a variation by hand, the plugin leaves that
variation's files alone.

Each file keeps the same identifier from one sync to the next. Customers who bought a
downloadable product keep access to its files after a sync.

## Variations

- Two variations of the same product cannot have the same options. The client receives an error
  if it tries to create a second one.
- When a product no longer offers an option, for example a colour removed from the product, the
  variations that use that option are set to **private**. Customers can no longer buy them through
  old links or carts. Nothing is deleted. When the client sends the variation again with an option
  the product offers, it gets its previous status back.
- Variations you create by hand in WooCommerce are never set to private by the plugin.

## Multilingual shops

If WPML is active, each product can have its own name, description and files per language. The
plugin creates or updates the translations for you. Translations are not generated automatically:
the plugin uses the text sent by the client.

Before the first sync, set **Products** to **Translatable** under **WPML > Settings > Post Types
Translation**. WPML does not do it by itself. See
[guide.md](guide.md#make-the-post-types-translatable-first).

## Good to know

- Syncs are driven by the client. You don't need to press anything in the admin.
- Sync goes one way only. Changes you make to a synced product in WooCommerce are not sent back to
  On Page®, and the next sync can overwrite them.
- A product's web address (slug) is set when the product is created. Renaming the product later
  does not change it, unless the client explicitly asks for a new slug.
- Products are matched by a stable On Page® identifier. Running a sync again updates the same
  product instead of creating a copy.
- If you see two categories with the same name, the identifiers are out of sync. Ask whoever
  maintains your client to run a clean re-import.

For the technical details, see [../dev/woocommerce.md](../dev/woocommerce.md).
