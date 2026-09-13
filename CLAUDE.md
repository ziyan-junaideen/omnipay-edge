# CLAUDE.md

@AGENTS.md

AGENTS.md holds the shared guidance. This file adds only Claude Code-specific notes.

- The backend source of truth (`/Volumes/Dev/Work/Edge/edge/ept`) is outside this
  working directory. Add it with `/add-dir` before verifying an API field, and use an
  Explore subagent for `openapi.json` and `lib/core_http/views/*.ex` so the large spec
  stays out of the main context. Never guess a field when the backend can't be read.
- The reference WooCommerce plugin lives at `/Volumes/Dev/JDeen/edge-woocommerce`. It
  is GPL-3.0-or-later; read it for behaviour, then write fresh code here.
- `AGENTS.local.md` is local-only and may be absent. Don't create it, and don't copy
  anything from it into tracked files or issue bodies.
- Run tools through mise (`mise x -- composer check`); there is no system PHP.
