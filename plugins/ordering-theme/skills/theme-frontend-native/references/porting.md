# Bringing changes from the original repository

When the themed app is a repository split from the original and the original keeps
receiving fixes and features, those changes are ported, not merged: the themed app
moved files, replaced libraries and primitives, and reads every value from the theme. A
`cherry-pick` does not apply, and a copy of the original's code fails the guard.

## 1. Review first (read-only)

One agent per group of pull requests of the original since the split, reading both
repositories (the original as the `upstream` remote: `git show upstream/main:<path>`,
the pull request's description and its ticket). For each change, the cause of the bug or
what the feature adds, and one verdict with `file:line` of both repositories:

| Verdict | Means |
|---|---|
| Already here | our code does the same (say where) |
| Does not apply | the cause does not exist here. A file that is gone is not proof: show the bug cannot happen another way |
| Missing | the bug can still happen here, or the feature is absent: the exact path, and what to port |
| Partial | which part is here and which is not |

A claim without `file:line` counts as not verified. What was dropped on purpose (a
design variant, a library) is not "missing". The coordinator checks each verdict against
the code before proposing anything, then asks the user which ones to port.

## 2. Porting

- **Port the intent, not the code.** Understand what the change wanted and get it with
  this app's components, helpers and theme. The original's code is a reference: it
  carries hand-written values and patterns this app already replaced.
- **Do not undo decisions.** Porting a change is not going back to how the original had
  it. Where the change collides with something decided here (a removed component, a
  default, the navigation, a replaced library), this app wins and the change adapts.
  Read `docs/theme/decisions.md` and the `git log` of the code first; a doubt of this
  kind is a question for the user, with its options, not a choice of the agent.
- **Check performance, change by change**, and say so in the report:
  - no new work on every scroll event or every render (a calculation, a `setState`, a
    request, a toast);
  - the memoization that exists still holds (a new prop with a new identity on each
    render cancels a `React.memo`);
  - no subscription to a whole context where a part was read;
  - no duplicated or looping request, no effect that triggers itself;
  - lists: stable keys, no `map` over a large collection outside a virtualized list.

  A measured number before and after (renders, mounted cards, requests) beats a claim.
- **Everything the theme rules ask:** styles from the theme, texts through the
  translation function with their key in every locale, a test that fails without the
  change.
- **A change that needs a schema key** (a new visibility key, a property) is a new
  schema version, its Dashboard label and its publish before the build that ships it
  (`shipping.md`): keep it in its own pull request.

## 3. Agents

One agent per ported change, each in its own worktree, simulator and emulator. They
commit locally and never push or open a pull request. Each leaves before and after
screenshots, the performance review above and the list of what it did not verify. The
coordinator reads each diff and opens each screenshot, then pushes once and opens the
pull request.
