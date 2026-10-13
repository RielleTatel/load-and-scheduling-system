# Domain Docs

This repo uses a single-context layout: `GLOSSARY.md` at the repo root and ADRs in `docs/adr/`.

## Before exploring, read these

- Root `GLOSSARY.md`, if it exists.
- ADRs in `docs/adr/` that touch the area you are about to work in.

If these files or directories are absent, proceed silently. The `/domain-modeling` skill creates them lazily when terms or decisions are resolved.

## File structure

```text
GLOSSARY.md
docs/adr/
  0001-<decision-slug>.md
  0002-<decision-slug>.md
```

## Use the glossary's vocabulary

When naming a domain concept in an issue title, refactor proposal, hypothesis, or test, use the term defined in `GLOSSARY.md`.

If a needed concept is absent, check whether the project already uses another term. Note real vocabulary gaps for `/domain-modeling`.

## Flag ADR conflicts

If a proposal contradicts an existing ADR, identify the ADR and explain why the decision should be reconsidered.
