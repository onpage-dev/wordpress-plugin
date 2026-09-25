# Troubleshooting

This guide is for site admins. It lists the most common problems with the **On Page®** plugin and
how to fix them.

The integration usually reports an error with a status code, such as `401` or `404`. The team
that maintains your integration can tell you which code they received. Each section below starts
from what you see or what they report.

## A red notice says On Page® requires Advanced Custom Fields

**What it means:** ACF is not installed or not active. Without ACF, the plugin's API is turned
off. The integration receives `404` on every call.

**How to fix it:**

1. Go to **Plugins** in the WordPress admin.
2. Install and activate **Advanced Custom Fields**.
3. Ask the integration team to run the sync again.

ACF is always required, even if the project does not use custom fields.

## The integration receives `404` on every call, and ACF is active

**What it means:** WordPress cannot find the plugin's API.

**How to fix it:**

- Check that the **On Page®** plugin is active under **Plugins**.
- Go to **Settings > Permalinks** and choose **Post name**. The **Plain** setting can stop the API
  from working.
- If you use a security or firewall plugin, check that it does not block the WordPress REST API
  (addresses that start with `/wp-json/`).

## The integration receives `401`, `403` or `500` with a token error

These errors all come from the API token.

| Code | What it means | How to fix it |
| --- | --- | --- |
| `500` | No token has been generated yet. | Open the **On Page®** menu item and click **Genera token** ("Generate token"). |
| `401` | The integration sent no token. | Ask the integration team to check their configuration. |
| `403` | The token sent does not match the one on the site. | Copy the current token from the **On Page®** page and send it to the integration team again. |

If you regenerate the token, the old one stops working at once. Tell the integration team every
time you regenerate it.

## I cannot see the On Page® menu item

Only **administrators** can see it. Ask an administrator to open the page or to give you the
right role.

## Multilingual content fails to sync

**What it means:** the integration sends content in several languages, but WPML is not active.
The error code is `wpml_required`.

**How to fix it:** install and activate **WPML**, and set up the same languages that the
integration sends. If the site has only one language, ask the integration team to send
single-language data.

## I see two categories or terms with the same name

**What it means:** the identifiers that link WordPress to On Page® are out of sync. This usually
happens when the identifiers were regenerated on the On Page® side.

**How to fix it:** do not merge or delete the duplicates by hand. Ask the integration team to run a
**clean re-import**. It clears the old identifiers and links the existing items again, without
creating new copies.

## Images or files are missing

**What it means:** the plugin downloads images and files from On Page® into your **Media Library**.
If your server cannot reach the file address, the download fails.

**How to fix it:**

- Check that your server can make outgoing connections to the internet. Some hosts block them.
- Ask the integration team which file failed. They can see the address in the error.

## A file I added by hand does not appear in the Documents section

This is expected. The **Documents** section on the product page shows only files sent by the
integration. See [woocommerce.md](woocommerce.md).

## Still stuck?

Contact the team that maintains your integration. Tell them:

- what you see, or the error code they reported;
- when it started;
- what changed on the site around that time, such as a plugin update or new settings.
