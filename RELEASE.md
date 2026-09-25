# Release procedure

Versions follow [Semantic Versioning](https://semver.org/), and every release has an entry in
`CHANGELOG.md`.

1. **Bump the version.** Set `Version:` in the `onpage.php` header to the new version. Add a
   section at the top of `CHANGELOG.md` titled `## [X.Y.Z] - YYYY-MM-DD` with the release date,
   and add the link for the new version at the bottom of the file.
2. **Tag it.**

   ```
   git tag -a "vX.Y.Z" -m "vX.Y.Z"
   ```

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
