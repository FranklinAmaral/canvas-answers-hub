# GraderAI - Agent Instructions

This is a Laravel application for AI-assisted grading in Canvas LMS.

## Project principles

- Do not introduce a new architecture unless explicitly requested.
- Follow the existing Laravel, Blade, route, language file and service patterns.
- Prefer complete file changes or clear diffs over isolated snippets.
- Use translation files for user-facing labels.
- Keep HTMX targets consistent with the existing project conventions.
- Do not move business logic into CanvasApiClient.
- CanvasApiClient should remain focused on low-level Canvas API communication.
- Business rules should live in module-specific services.
- The active Canvas environment comes from the logged-in user's selected environment.
- Canvas credentials should come from the CanvasEnvironment model/table, not from .env, unless the project already uses .env for unrelated infrastructure settings.
- Timezone should not be requested in uploads. It must come from the organization/environment context.
- Date format should be fixed and standardized as Y-m-d H:i:s.
- When creating upload templates, prefer downloadable CSV files in public/templates.
- Avoid broad refactors outside the requested scope.
- Preserve existing naming conventions unless instructed otherwise.

## Preferred implementation style

- When changing code, keep the scope tight.
- When adding a feature, update routes, controllers, services, Blade views and language files as needed.
- Add comments only when they clarify non-obvious behavior.
- Do not create unnecessary abstractions.
