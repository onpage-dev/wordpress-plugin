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
   5. Commit these changes on `master`.
2. **Tag it.**

   ```
   git tag -a "vX.Y.Z" -m "vX.Y.Z"
   ```

3. **Push the tag.**

   ```
   git push origin --tags
   ```

4. **Build the plugin archive** from the tag:

   ```
   git archive --format=zip --prefix=onpage/ -o dist/onpage-X.Y.Z.zip vX.Y.Z
   ```

   - `--prefix=onpage/` puts every file in an `onpage/` folder. WordPress installs an uploaded
     plugin into the folder named in the archive, and existing sites have the plugin in
     `wp-content/plugins/onpage/`. An archive with another top folder would install a second copy
     instead of replacing the current one. This is also why the source archives GitHub generates
     for a tag (with a `wordpress-plugin-X.Y.Z/` folder) are not suitable for upload.
   - `git archive` leaves out every path marked `export-ignore` in
     [.gitattributes](.gitattributes): the tests, `src/Env.php`, `bin/`, the Docker environment,
     `config/`, the `start`/`stop`/`restart` scripts, `.env.example` and the repository tooling
     (`.gitignore`, `.gitattributes`). `AGENTS.md` ships with the docs, because `CONTRIBUTING.md`
     links to it. It reads `.gitattributes` from the tagged commit, so the file must be committed.
   - `dist/` is in `.gitignore`.

   Check the result before you publish it:

   ```
   unzip -l dist/onpage-X.Y.Z.zip
   ```

   Every entry must start with `onpage/`, `onpage/plugin.php` must be there, and `src/Tests/` must
   not.
5. **Create the GitHub Release** from the tag. Pushing a tag does not create a Release, so do it
   by hand on GitHub:
   1. Open the repository on GitHub
      ([onpage-dev/wordpress-plugin](https://github.com/onpage-dev/wordpress-plugin)) and go to
      **Releases**.
   2. Click **Draft a new release**.
   3. In **Choose a tag**, select the tag you just pushed (`vX.Y.Z`). Do not create a new tag here.
   4. Set the release title to `vX.Y.Z`.
   5. In the description, paste the `CHANGELOG.md` section for this version.
   6. Attach `dist/onpage-X.Y.Z.zip`. This is the file site admins upload under **Plugins**.
   7. Click **Publish release**.

## Upgrading sites

Tell the site admins about anything they must do after the upgrade. The steps they follow are in
[docs/user/guide.md](docs/user/guide.md#upgrading-from-an-earlier-installation).

- The main plugin file is `plugin.php`. Earlier installations, distributed before this repository's
  version history started (for example the `onpage-2.1.3.zip` package), used `onpage.php`. When
  such a site uploads the new archive, WordPress deactivates the plugin because the file it had
  activated no longer exists. The admin must activate **On Page®** again.
- Those earlier packages carry higher version numbers (2.x) than this repository's releases. When
  the admin uploads the archive, WordPress may say that the uploaded version is older than the
  installed one. Replacing it is still correct.
- Sites that ran an earlier installation must call `POST /migration` once after the upgrade. It is
  idempotent. See [docs/API.md](docs/API.md).
