# User guide

This guide is for WordPress site administrators. It explains what the **On Page®** plugin does, what it needs, and how to set it up.

## What is On Page®?

The **On Page®** plugin syncs data from the On Page® PIM into WordPress.

Here is how the pieces fit together:

- **On Page®** holds the source data.
- A **client** sends that data to WordPress. A client is any program that calls the plugin's API, whoever wrote it.
- The **On Page®** plugin receives the data through its **REST API**.
- WordPress stores it as structured content.

This way WordPress is your publishing site, while the data is still managed in one central place: On Page®.

## What is it for?

Use the plugin when you want to import structured collections into WordPress and keep them up to date. For example:

- cars
- real estate
- products
- store locations
- catalogs

A practical example:

- On Page® has a collection called **Car**.
- In WordPress it becomes a **Post Type**.
- Each item in the collection becomes a piece of content on the site.

## Requirements

| Requirement | Status | Why |
|---|---|---|
| **WordPress 7.1+** | Required | |
| **PHP 8.2+** | Required | |
| **Advanced Custom Fields (ACF) 6.1+** | **Required** | Creates and manages the structured custom fields attached to the imported content, so the data can be shown and organized in the WordPress admin and in your site templates. Version 6.1 is the first one that can create post types and taxonomies, which the plugin relies on. With an older version, a red notice in the admin asks you to update ACF, and the plugin's API answers every call with an error. |
| **WooCommerce** | Optional | Only needed if the client syncs products, categories, brands or other e-commerce data. |
| **WPML** | Optional | Only needed if the site manages synced multilingual content. It links content, terms, labels and translated data to the languages configured in WordPress. |

Important notes:

- **ACF is always required**, even if you don't need any custom fields. Without ACF, the plugin turns its API off and shows a red notice in the WordPress admin. This applies to WooCommerce-only syncs too, and even if products need no custom fields.
- **ACF PRO** is only needed for advanced field types (repeater, flexible content, gallery).
- **WPML** is only needed for multilingual syncs. It is a **paid plugin**.
- Without WPML, the multilingual features are not available.
- **Hosting limits.** The plugin downloads images and files during each request, one after the
  other. A large product can take tens of seconds. Agree on `max_execution_time` and
  `memory_limit` with your hosting provider and with whoever runs the client, so a request is not
  cut off halfway.

## How syncing works

1. The data is prepared in **On Page®**.
2. The **client** calls the plugin's REST API on your WordPress site.
3. The plugin creates or updates:
   - **Post Types**
   - **ACF fields**, if the configuration includes them
   - **taxonomies**
   - **terms**
   - **content**
   - **WooCommerce products and data**, if WooCommerce is active
4. The data becomes available in the WordPress admin and on the site.

## How On Page® maps to WordPress

| On Page® | WordPress |
|---|---|
| Collection | Post Type |
| Item | A piece of content in that Post Type |
| Field | ACF custom field (when the project uses ACF) |
| Classification | Taxonomy |
| Product | WooCommerce product (when WooCommerce is active) |

Example:

| On Page® | WordPress |
|---|---|
| Collection `Car` | Post Type `Car` |
| Field `model` | ACF field `model` |
| Field `year` | ACF field `year` |

## What you will see in WordPress

After a sync, the WordPress admin will show:

- new **content types**
- new **custom fields**
- any **taxonomies** and **custom categories**
- content already filled in with the data from On Page®

You can then:

- view the content in the admin
- use it in your site templates
- filter it by taxonomy
- manage it in multiple languages, if WPML is configured

## Before you start

> **Warning:** the **On Page®** plugin and its REST API need WordPress **Permalinks** set to **Post name** (Settings > Permalinks).
> Do not use **Plain**: it can stop the plugin's endpoints from working.

Also make sure that:

- ACF is installed and active (always required)
- WPML is installed and active, if the project uses multiple languages. The post types must be
  translatable in WPML before the first import: see [Multilingual sites](#multilingual-sites)
- WooCommerce is installed and active, if the project syncs e-commerce data
- the On Page® plugin is installed and active
- the **client** is configured to call the plugin's REST API
- your WordPress site can be reached by the **client**

## Installing the plugin

The plugin is not on wordpress.org. You get it as a .zip file from the
[GitHub releases page](https://github.com/onpage-dev/wordpress-plugin/releases).

- Install it under **Plugins > Add New > Upload Plugin**.
- WordPress never offers updates for it. To update, upload the .zip of the new version the same
  way.
- The plugin creates no database tables and has no setup step. It works as soon as it is active.

## API security

The plugin's REST API is protected by an **authentication token**. This means:

- only an authorized **client** can send data to WordPress
- outside users cannot use the API without a valid token

You manage the token from the WordPress admin.

Only **administrators** (WordPress capability `manage_options`) can open the **On Page®** page and generate or regenerate the token. Other roles (editors, authors, contributors, subscribers) don't see the **On Page®** menu item. They cannot create, view or regenerate the token, even by opening the page URL directly.

### Setting up the token

1. Install and activate the **On Page®** plugin (upload the .zip file under **Plugins** in WordPress).
2. Open the **On Page®** menu item in the WordPress admin.
3. Click **Generate token**. If a token already exists, the button reads **Regenerate token**.
4. Copy the token shown under **Current token** and give it to whoever runs your **client**.
5. REST calls are sent with the header `Authorization: Bearer <token>`.

Good to know:

- The token is saved in your WordPress database, as a site setting named `onpage_auth_token`.
- There is one token per site. It never expires.
- If you regenerate the token, the old one stops working at once. There is no overlap period, so a
  sync that is running fails. Regenerate it during a maintenance window, and give the new token to
  whoever runs the client right away.
- This token is not the **On Page® API token**. The client uses the On Page® API token to read
  data from On Page®, and this plugin's token to write to your site. The two are not
  interchangeable.

## Updating the plugin

1. Upload the .zip of the new version under **Plugins** with **Upload Plugin**, and choose to
   replace the current version when WordPress asks.
2. Open **Plugins** and check that **On Page®** is still active. Your data and your token are
   kept.
3. If the release notes ask for it, ask whoever maintains your **client** to run the plugin's
   **migration** once. It is safe to run more than once.

## Multilingual sites

If your site uses more than one language:

- WPML makes the synced content available in multiple languages.
- The plugin can handle multilingual data for content and some labels.
- Translations are **not** generated automatically. The plugin uses the values sent by the source system or set in the configuration.
- Translated taxonomy labels are also registered in ACFML, when it is installed.

### Make the post types translatable first

WPML does not make a new post type translatable by itself. The plugin does it for taxonomies, but
not for post types. Before the first multilingual sync:

1. Go to **WPML > Settings > Post Types Translation**.
2. Set every post type that the client syncs to **Translatable**. For a WooCommerce shop, this
   includes **Products** (`product`).
3. Save.

A post type created by the plugin appears in that list after the client has created it. So create
the structure first, then set the post types as translatable, then sync the content.

If you skip this, the multilingual sync fails with a `500` error. See
[troubleshooting.md](troubleshooting.md#multilingual-content-fails-with-a-translation-group-error).

## What the plugin does not do

The plugin is not meant to:

- replace normal editorial work in WordPress
- create content by hand from the admin for complex sync flows
- translate content automatically with WPML credits
- send changes back to On Page®. Sync goes one way only. If you edit synced content in WordPress,
  the next sync can overwrite your changes. Make the change in On Page® instead.
- delete content by itself. An item removed from On Page® stays on the site until the client
  deletes it.

Its main job is to:

- **receive structured data**
- **map that data into WordPress**
- **keep it in sync with On Page®**

## Uninstalling

Deleting the plugin removes its files only. It does not clean the database. These stay behind:

- the token (the `onpage_auth_token` site setting);
- the On Page® identifiers stored on posts, terms and WooCommerce global attributes;
- all the synced content: post types, fields, taxonomies, terms, posts, products and media.

Before you uninstall, whoever runs the client can call the plugin's index cleanup. It removes the
identifiers from posts and terms. The identifiers of WooCommerce global attributes are site
settings, so they stay.

## Summary

The **On Page®** plugin connects On Page® to WordPress. It lets you:

- receive data from any **client**
- turn collections into **Post Types**
- turn fields into **ACF custom fields**
- sync e-commerce data with **WooCommerce** (optional; WPML is not needed for this; see [woocommerce.md](woocommerce.md))
- support complex and multilingual data structures, if WPML is configured

It is built for WordPress sites that need to publish structured data coming from an external system.

Something not working? See [troubleshooting.md](troubleshooting.md).
