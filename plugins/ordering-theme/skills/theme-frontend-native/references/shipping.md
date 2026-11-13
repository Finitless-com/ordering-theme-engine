# From the repository to a build

A themed app is not done when its pull request merges. Its schema has to be registered
before any binary asks for it, the build system has to take the new code, and the editor
needs its labels. The model is Finitless-com/ordering-app-marketplace-theming
(`.github/workflows/theme-publish.yml`, `ci.yml`, `docs/THEMING.md` §6).

## 1. Publishing is the pipeline's

- **Never by hand.** `theme:publish` runs in GitHub Actions only. The agent does not
  read, write or ask for a publish key; the user sets the secrets.
- **Pull requests:** `theme:publish:dry --required`, so api-core says what it would
  refuse before the merge, and the step fails when the secret is missing.
- **Every push to the main branch:** `theme:validate`, `theme:verify`, then the real
  publish with `--required`. The same version with the same content answers
  `unchanged`, so the job needs no path filter and can run on every push; the same
  version with other content is refused (409 `schema.version_exists`): bump first.
- **Environments:** ask which exist. The marketplace app publishes to production only,
  with the settings the web already had at the organization
  (`THEME_PUBLISH_API_PRODUCTION` as a variable, `THEME_PUBLISH_KEY_PRODUCTION` as a
  secret). An organization secret may reach selected repositories only: check that a
  new repository is one of them.
- **One publish at a time:** a `concurrency` group without `cancel-in-progress`.
- **A repository that shares its CI with another** (the original app, a fork) must not
  publish the other's schema: compare the compiled file's `product_type` with the one
  the repository is meant to send, and skip elsewhere.
- **Locally nothing is published.** The app paints the compiled defaults, or the schema
  is registered in a local api-core (`php artisan ordering:theme-schema <path>`) to edit
  a theme of it from a local Dashboard: the only way to see a served theme on a
  simulator without touching a real site.

`templates/theme-publish.yml` is the workflow to start from.

## 2. Versions before the product opens

A version published to production is immutable, and every binary built with it keeps
asking for it. That starts with the first merge, long before any merchant has the app:
the marketplace app published 1.0.0 to 1.3.0 while one test project used it.

Decide with the user, at the start, which of these holds until the product opens:

- schema changes wait in their pull requests and merge together; or
- versions are spent freely and the registry starts again at `1.0.0` before opening.

Starting again is only possible while every theme and every binary of those versions
belongs to test projects. It took, in this order: a copy of the registry's rows for the
product type, each checked against its stored hash; the owner's explicit authorization to
delete them in production; api-core's schema cache cleared (it keeps a schema for up
to an hour); the version reset in a pull request; every app built with the old versions built again. After the product opens it
is never an option: only forward.

## 3. The Builder's base

A brand's build is not made from GitHub at build time. The Builder builds from its
**base**: a copy of the repository's branch that api-core cloned. A merge reaches a
build only after the base is cloned again.

- **Clone after publish, in the same workflow.** The second job asks api-core to clone
  again the bases that follow this repository and branch, and it `needs` the publish
  job: a build made from the new code must find its schema version registered. The
  marketplace app's workflow has the call; it uses its own key, which only clones.
- **A base is offered** to a project when it is cloned, not deprecated, of a product
  type the Builder offers, and either public or the project's own. While converting, the
  base belongs to the test project; opening the product is making it public: a change
  to production data the owner authorizes, not a step of the conversion.
- **After a clone** the Dashboard tells each app of the base that there are changes
  since its build. Nothing reaches an installed app until its owner builds again.
- **What the Builder expects of the repository** must survive the conversion. The
  marketplace app's `CLAUDE.md` lists it: the marker that makes the pipelines install
  with the repository's package manager and keep the lock files; the placeholders the
  Builder replaces as text in every file that has them (never write one in a comment);
  the fixed names of the splash and icon files; the Android package it renames only in
  the files the base lists (any other class goes in a fixed package); the optional keys
  that turn a feature off when empty. A rename or a move of any of these breaks every
  brand's build: make one real Builder build for each platform before opening.

## 4. The Dashboard

- **Labels:** each `BUILDER_THEME_…` key the schema adds needs its text in the
  Dashboard's own catalog, in every language, as a pull request in the Dashboard's
  repository in the same round as the schema's. Until it merges the editor shows the
  English default. Keep the list in `docs/theme/editor-labels.md`.
- **Open the editor on the published version** in a browser: every group opens, every
  control changes the preview, no label shows its key.

## 5. A repository split from the original

When the themed app is a new repository and the original stays alive (clients on a
design variant the conversion drops, for example):

- **Crash reporting of its own.** A DSN fixed in a file sends the new app's errors to
  the original's project: create the project and tag the app.
- **Its own names:** `CLAUDE.md`, the README, the clone URLs, the issue tracker binding.
- **Branch rules:** the quality gate required, the branch up to date before merging,
  head branches deleted on merge.
- **The original as a remote** (`upstream`), to bring later fixes: `porting.md`.
- **What was dropped on purpose** is in `docs/theme/decisions.md`, so nobody ports it
  back.

## 6. Hand over

- the workflow and the dry run green, the first publish seen in the workflow's log;
- the base cloned after it, and one Builder build per platform installed and opened;
- the Dashboard's labels merged; the editor opened on the published version;
- the fonts bundled and their weights; the v1 map, or the decision not to have one;
- what the build's defaults look like without a served theme, on both platforms;
- what was not verified, by name.
