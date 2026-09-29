# Release procedure

Versions follow [Semantic Versioning](https://semver.org/), and every release has an entry in
`CHANGELOG.md`.

1. **Bump the version.**
   1. Set `Version:` in the `plugin.php` header to the new version.
   2. In `CHANGELOG.md`, rename the `## [Unreleased]` section to `## [X.Y.Z] - YYYY-MM-DD`, with
      the release date. Keep its entries as they are. Leave no empty `[Unreleased]` section behind:
      the next change creates it again.
   3. Add the link for the new version at the bottom of `CHANGELOG.md`, in the same form as the
      existing ones.
   4. Update `Current version` in [README.md](README.md), and the supported versions table in
      [SECURITY.md](SECURITY.md) when the minor version changes.
   5. Commit these changes on `main`.
2. **Tag it.** Merge every branch that belongs to the release into `main` first, then tag the
   latest commit of `main`.

   ```
   git switch main
   git pull
   git tag -a "vX.Y.Z" -m "vX.Y.Z"
   ```

   GitHub runs the [Release archive](.github/workflows/release-archive.yml) workflow from the
   tagged commit. A tag cut before the workflow file reached `main` builds no archive, and
   neither does a tag on another branch.

3. **Push the tag.**

   ```
   git push origin --tags
   ```

4. **Create the GitHub Release** from the tag. Pushing a tag does not create a Release, so do it
   by hand on GitHub:
   1. Open the repository on GitHub
      ([onpage-dev/wordpress-plugin](https://github.com/onpage-dev/wordpress-plugin)) and go to
      **Releases**.
   2. Click **Draft a new release**.
   3. In **Choose a tag**, select the tag you just pushed (`vX.Y.Z`). Do not create a new tag here.
   4. Set the release title to `vX.Y.Z`.
   5. In the description, paste the `CHANGELOG.md` section for this version.
   6. Click **Publish release**.
5. **Check the plugin archive.** Publishing the release starts the
   [Release archive](.github/workflows/release-archive.yml) workflow. It attaches
   `onpage-X.Y.Z.zip` to the release within a few minutes. This is the file site admins upload
   under **Plugins**. Open the **Actions** tab and check that the run succeeded.

## The plugin archive

The workflow builds the archive with:

```
git archive --format=zip --prefix=onpage/ -o dist/onpage-X.Y.Z.zip vX.Y.Z
```

- `--prefix=onpage/` puts every file in an `onpage/` folder. WordPress installs an uploaded plugin
  into the folder named in the archive, and existing sites have the plugin in
  `wp-content/plugins/onpage/`. An archive with another top folder would install a second copy
  instead of replacing the current one. This is why the source archives GitHub generates for a
  tag (with a `wordpress-plugin-X.Y.Z/` folder) must not be uploaded.
- `git archive` leaves out every path marked `export-ignore` in [.gitattributes](.gitattributes),
  such as the tests and the local environment. It reads `.gitattributes` from the tagged commit,
  so changes to it must be committed before you tag.

The run fails, and attaches nothing, when:

- the tag does not match `Version:` in `plugin.php` (tag `vX.Y.Z`, version `X.Y.Z`);
- an entry of the archive is outside `onpage/`, `onpage/plugin.php` is missing, or `src/Tests/`
  is included.

Fix the cause, then delete the release and the tag, and cut them again. If only the upload failed,
re-run the workflow from the **Actions** tab. It replaces an archive already attached.

## Upgrading sites

Tell the site admins about anything they must do after the upgrade, such as calling
`POST /migration` (see [docs/API.md](docs/API.md#post-migration)). Put it in the release
description. The steps they follow are in
[docs/user/guide.md](docs/user/guide.md#updating-the-plugin).

### Sites that ran the earlier plugin

Some sites still run the plugin that was distributed before this repository existed (packages
such as `onpage-2.1.4.zip`). You can recognize it by its main file, `onpage.php`. Its version
numbers (`1.x` and `2.x`) say nothing about the format, and some are higher than this
repository's releases. Every one of these sites needs these steps, whatever the version:

- WordPress may say that the uploaded version is older than the installed one. Replacing it is
  still correct.
- The main file is now `plugin.php`, so WordPress deactivates the plugin during the upload. The
  admin must activate **On Page®** again.
- The earlier plugin stored each `local_key` in the `local_key` post meta, through an ACF field.
  The current plugin reads only `onpage_local_key`. Until `POST /migration` runs, every post the
  earlier plugin synced looks unowned, and the first import fails with `409 duplicate_title`.
  The client must call `POST /migration` once, before that import.

The admin's steps are in
[docs/user/guide.md](docs/user/guide.md#upgrading-from-the-earlier-plugin).
