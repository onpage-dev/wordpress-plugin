# Troubleshooting

This guide is for site admins. It lists the most common problems with the **On Page®** plugin and
how to fix them.

The client, the program that sends data to the plugin, usually reports an error with a status
code, such as `401` or `404`. Whoever maintains the client can tell you which code they received.
Each section below starts from what you see or what they report.

## A red notice says On Page® requires Advanced Custom Fields

The notice is in Italian. It reads: "**On Page®** richiede il plugin **Advanced Custom Fields**.
Finché ACF non è installato e attivo, le API REST di On Page® restano disattivate." In English:
On Page® requires the Advanced Custom Fields plugin; until ACF is installed and active, the
On Page® REST API stays turned off. Only users who can activate plugins see it.

**What it means:** ACF is not installed or not active. Without ACF, the plugin's API is turned
off. The client receives `404` on every call.

**How to fix it:**

1. Go to **Plugins** in the WordPress admin.
2. Install and activate **Advanced Custom Fields**.
3. Ask whoever maintains the client to run the sync again.

ACF is always required, even if the project does not use custom fields.

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
| `500` | No token has been generated yet. | Open the **On Page®** menu item and click **Genera token** ("Generate token"). |
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

## A file I added by hand does not appear in the Documenti section

This is expected. The **Documenti** ("Documents") section on the product page shows only files
sent by the client. See [woocommerce.md](woocommerce.md).

## Still stuck?

Contact whoever maintains your client. Tell them:

- what you see, or the error code they reported;
- when it started;
- what changed on the site around that time, such as a plugin update or new settings.
