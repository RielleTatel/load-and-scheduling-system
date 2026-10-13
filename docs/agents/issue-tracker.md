# Issue tracker: Local Markdown

Issues and specs for this repo live as Markdown files in `.scratch/`.

## Conventions

- One feature per directory: `.scratch/<feature-slug>/`.
- The spec is `.scratch/<feature-slug>/spec.md`.
- Implementation issues are one file per ticket at `.scratch/<feature-slug>/issues/<NN>-<slug>.md`, numbered from `01` in dependency order, blockers first. Never combine tickets into one file.
- Triage state is recorded as a `Status:` line near the top of each issue file. Use the role strings from `docs/agents/triage-labels.md`.
- Append comments and conversation history under a `## Comments` heading at the bottom of the file.

## When a skill says "publish to the issue tracker"

Create the spec or individual ticket files at the paths above, creating directories as needed.

## When a skill says "fetch the relevant ticket"

Read the file at the referenced path. If given only an issue number, resolve it within the relevant feature's `issues/` directory.

## Wayfinding operations

Used by `/wayfinder`. The map is a file with one child file per ticket.

- **Map**: `.scratch/<effort>/map.md`, containing Notes / Decisions-so-far / Fog.
- **Child ticket**: `.scratch/<effort>/issues/<NN>-<slug>.md`, numbered from `01`, with the question in the body. A `Type:` line records `research`, `prototype`, `grilling`, or `task`. For wayfinding execution, `Status:` is `open`, `claimed`, or `resolved`.
- **Blocking**: a `Blocked by: NN, NN` line near the top. A ticket is unblocked when every listed ticket is `resolved`.
- **Frontier**: scan the effort's `issues/` directory for open, unblocked, unclaimed tickets; first by number wins.
- **Claim**: set `Status: claimed` and save before any work.
- **Resolve**: append the answer under `## Answer`, set `Status: resolved`, then append a context pointer (gist + link) to Decisions-so-far in `map.md`.
