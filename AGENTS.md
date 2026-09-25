# AGENTS.md

Guidance for AI coding agents working in this repository.

## Documentation language

All documentation must be written in **English**. This applies to:

- `README.md`, `CHANGELOG.md`, `RELEASE.md`, `CONTRIBUTING.md`, `SECURITY.md` and this file
- every guide under `docs/` (`docs/user/`, `docs/dev/` and `docs/API.md`)
- any new Markdown file added to the repository

When you edit a document that still contains non-English text, translate the part you touch.

## Documentation style

- Keep it readable: short sentences, one idea per sentence, clear headings. Use lists and tables
  where they help.
- Link to other files with relative paths (for example `docs/API.md` from the root, `../API.md` from
  inside `docs/dev/`).
- Keep endpoint paths, JSON keys, error codes, meta keys and function names exactly as they appear
  in the code.

## Documentation layout

- `docs/user/`: guides for site admins. Plain language, no code internals.
- `docs/dev/`: guides for developers. Technical detail, internals and design.
- `docs/dev/notes/`: point-in-time analyses, such as performance studies. Put the month in the file
  name and keep a status section at the top up to date.
- `docs/API.md`: the REST API reference. It stays at the root of `docs/`.
- `docs/README.md`: the index. Update it when you add, rename or remove a guide.

## Every code change updates the docs and the changelog

When you change the code, update the documentation and `CHANGELOG.md` **in the same change**. Do
not leave them for a later commit. A change is not finished until both are up to date.

### Documentation

Update every document that describes the code you touched. Use this table to find them:

| If you change… | Update |
| --- | --- |
| an endpoint: route, parameters, response shape or error codes | [docs/API.md](docs/API.md), including the endpoint index at the top |
| the order of calls or the rules an integration must follow | [docs/dev/integration-guide.md](docs/dev/integration-guide.md) |
| how a controller or service works | [docs/dev/internals.md](docs/dev/internals.md) |
| how WooCommerce products, variations or downloads are saved | [docs/dev/woocommerce.md](docs/dev/woocommerce.md) |
| a design decision or a cross-cutting convention | [docs/dev/architecture.md](docs/dev/architecture.md) |
| something a site admin sees or configures: requirements, admin page, token, errors | the guides in [docs/user/](docs/user/) |
| the local environment, the tests or the contribution process | [CONTRIBUTING.md](CONTRIBUTING.md) |

Also:

- If a change fixes or invalidates an item in `docs/dev/notes/`, update that note's status section.
- If you add, rename or remove a guide, update [docs/README.md](docs/README.md).
- If a change has no effect on any document, say so in the commit message or pull request.

### Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

- Add every user-visible change under an `## [Unreleased]` section at the top. Create the section if
  it does not exist.
- Group entries under `### Added`, `### Changed`, `### Deprecated`, `### Removed`, `### Fixed` or
  `### Security`.
- Write one short sentence per entry, from the point of view of someone using the plugin or its
  API. Name the endpoint, parameter or error code when there is one.
- Mark breaking changes to the REST API with **Breaking:** at the start of the entry.
- Internal refactors, test-only changes and docs-only changes need no entry.
- Do not bump the version or add a dated release section. That happens at release time (see
  [RELEASE.md](RELEASE.md)).
