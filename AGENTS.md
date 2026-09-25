# AGENTS.md

Guidance for AI coding agents working in this repository.

## Documentation language

All documentation must be written in **English**. This applies to:

- `README.md`, `CHANGELOG.md`, `RELEASE.md` and this file
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
- `docs/API.md`: the REST API reference. It stays at the root of `docs/`.
- `docs/README.md`: the index. Update it when you add, rename or remove a guide.

## Keeping the API reference up to date

Whenever you add a new endpoint or change an existing one, update [docs/API.md](docs/API.md) in the
same change. This includes new routes, new or changed parameters, response shapes and error codes.
