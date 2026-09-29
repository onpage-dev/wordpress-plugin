# Troubleshooting

This guide is for site admins. It lists the most common problems with the **On Page®** plugin and
how to fix them.

The client, the program that sends data to the plugin, usually reports an error with a status
code, such as `401` or `404`. Whoever maintains the client can tell you which code they received.
Each section below starts from what you see or what they report.

## A red notice says On Page® requires Advanced Custom Fields

The notice reads: "**On Page®** requires the **Advanced Custom Fields** plugin. The On Page® REST
API stays disabled until ACF is installed and active." Only users who can activate plugins see it.

**What it means:** ACF is not installed or not active. Without ACF, the plugin's API is turned
off. The client receives `404` on every call.

**How to fix it:**

1. Go to **Plugins** in the WordPress admin.
2. Install and activate **Advanced Custom Fields**.
3. Ask whoever maintains the client to run the sync again.

ACF is always required, even if the project does not use custom fields.

## A red notice says On Page® requires a newer Advanced Custom Fields

The notice reads: "**On Page®** requires **Advanced Custom Fields** 6.1 or later." It also shows
the version that is active. The client receives `500 acf_version_unsupported` on every call.

**How to fix it:** update **Advanced Custom Fields** under **Plugins** to version 6.1 or later,
then ask whoever maintains the client to run the sync again.

## The client receives `404` on every call, and ACF is active

**What it means:** WordPress cannot find the plugin's API.

**How to fix it:**

- Check that the **On Page®** plugin is active under **Plugins**.
- Go to **Settings > Permalinks** and choose **Post name**. The **Plain** setting can stop the API
  from working.
- If you use a security or firewall plugin, check that it does not block the WordPress REST API
  (addresses that start with `/wp-json/`).

## The client receives `401`, `403` or `500` with a token error

These errors all come from the API token.

| Code | What it means | How to fix it |
| --- | --- | --- |
| `500` | No token has been generated yet. | Open the **On Page®** menu item and click **Generate token**. |
| `401` | The client sent no token. | Ask whoever maintains the client to check their configuration. |
| `403` | The token sent does not match the one on the site. | Copy the current token from the **On Page®** page and send it again to whoever maintains the client. |

If you regenerate the token, the old one stops working at once. Tell whoever maintains the client
every time you regenerate it.

## I cannot see the On Page® menu item

Only **administrators** can see it. Ask an administrator to open the page or to give you the
right role.

## Multilingual content fails to sync

**What it means:** the client sends content in several languages, but WPML is not active.
The error code is `wpml_required`.

**How to fix it:** install and activate **WPML**, and set up the same languages that the
client sends. If the site has only one language, ask whoever maintains the client to send
single-language data.

## Multilingual content fails with a translation group error

The client receives a `500` error with one of these messages:

- code `wpml_error`, message containing "Unable to resolve translation group";
- code `request_failed`, message containing "Failed to initialize WPML language details".

**What it means:** WPML is active, but the post type of the content is not translatable. WPML
cannot link the translations to each other.

**How to fix it:**

1. Go to **WPML > Settings > Post Types Translation**.
2. Set the post type named in the error to **Translatable**. For WooCommerce products, set
   **Products**.
3. Save, and ask whoever maintains the client to run the sync again.

See [guide.md](guide.md#make-the-post-types-translatable-first).

## Syncs stop halfway or time out

**What it means:** the plugin downloads images and files while it handles each request, one file
at a time. A request with many files can run longer than your server allows.

**How to fix it:**

- Ask your hosting provider to raise `max_execution_time` and `memory_limit`.
- Ask whoever maintains the client to send fewer items per request.

## I see two categories or terms with the same name

**What it means:** the identifiers that link WordPress to On Page® are out of sync. This usually
happens when the identifiers were regenerated on the On Page® side.

**How to fix it:** do not merge or delete the duplicates by hand. Ask whoever maintains the
client to run a **clean re-import**. It clears the old identifiers and links the existing items again, without
creating new copies.

## Images or files are missing

**What it means:** the plugin downloads images and files from On Page® into your **Media Library**.
If your server cannot reach the file address, the download fails.

**How to fix it:**

- Check that your server can make outgoing connections to the internet. Some hosts block them.
- Check the file type. The plugin imports images, video, audio, PDF files, office documents
  (Word, Excel, PowerPoint, OpenDocument, Apple iWork), text and CSV files, archives (such as ZIP)
  and SVG images. Other types, such as web pages, scripts or programs, are refused unless your
  site already allows them.
- The plugin only downloads from public internet addresses. A file on an internal server of your
  network is refused, unless a developer allows that host for your site.
- Files larger than 512 MB are refused. A developer can raise the limit for your site.
- Ask whoever maintains the client which file failed. They can see the address in the error.

## An SVG image looks different from the original

This is expected. Before it stores an SVG image, the plugin removes everything that could run code
on your site: scripts, styles, links and embedded content. Most icons and logos are not affected.
An SVG that relies on those parts can look different, or lose parts that point to other elements
of the same image.

## A product variation became private

This is expected. When a product no longer offers an option, for example a colour removed from the
product, the variations that use that option are set to **private**. Customers can no longer buy
them through old links or carts. Nothing is deleted.

When the client sends the variation again with an option the product offers, it gets its previous
status back. Variations you created by hand in WooCommerce are not changed. See
[woocommerce.md](woocommerce.md).

## A file I added by hand does not appear in the Documents section

This is expected. The **Documents** section on the product page shows only files
sent by the client and marked as public. See [woocommerce.md](woocommerce.md).

## Still stuck?

Contact whoever maintains your client. Tell them:

- what you see, or the error code they reported;
- when it started;
- what changed on the site around that time, such as a plugin update or new settings.
