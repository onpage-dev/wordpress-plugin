# Security policy

## Supported versions

Security fixes are released for the latest minor version only.

| Version | Supported |
| --- | --- |
| 1.0.x | Yes |

## Reporting a vulnerability

Do **not** open a public issue for a security problem.

Report it privately through GitHub:

1. Open the repository's **Security** tab
   ([onpage-dev/wordpress-plugin](https://github.com/onpage-dev/wordpress-plugin/security)).
2. Click **Report a vulnerability**.
3. Describe the problem, the affected version and the steps to reproduce it.

We aim to confirm the report within a few working days. We will keep you updated while we work on
a fix, and credit you in the release notes if you wish.

## Scope

The plugin exposes a REST API under `/wp-json/onpage/v1` that can create, change and delete site
content. The areas that matter most are:

- **Authentication.** Every endpoint requires the Bearer token stored in the `onpage_auth_token`
  option. A way to call an endpoint without a valid token is a vulnerability.
- **Token management.** Only users with the `manage_options` capability can generate or view the
  token, and no REST endpoint can read or write it.
- **Remote media.** The plugin downloads files from URLs sent in the payload. Problems such as
  server-side request forgery or unsafe file types are in scope.

A caller that holds a valid token is trusted to write content. Actions that a valid token already
allows are not vulnerabilities on their own.
