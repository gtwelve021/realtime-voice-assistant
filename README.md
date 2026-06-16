# G12 Realtime Voice Assistant

WordPress plugin for a bottom-center OpenAI Realtime voice concierge on the G12 website.

## Features

- OpenAI Realtime voice session through server-created client secrets.
- Bottom-center voice guide UI.
- Website page search suggestions.
- Same-site page opening in a new tab so voice session is not reset.
- One-question-at-a-time callback detail collection.
- Browser session memory for recent chat and collected lead details.
- Server-side lead capture as private `g12_voice_lead` posts plus email notification.

## Security

Do not expose the OpenAI API key in frontend JavaScript. Configure the key server-side with `G12_OPENAI_API_KEY`, `OPENAI_API_KEY`, or the plugin admin settings.
