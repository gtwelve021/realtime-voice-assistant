# G12 Realtime Voice Assistant

WordPress plugin for a bottom-center OpenAI Realtime voice concierge on the G12 website.

## Features

- OpenAI Realtime voice session through server-created client secrets.
- OpenAI Responses API lead scoring with sales-ready summaries and recommended next actions.
- Verified ephemeral-token WebRTC connection by default.
- Optional server-side WebRTC connection endpoint with browser fallback.
- Bottom-center voice guide UI.
- Warm consultant behavior with same-language replies when multilingual mode is enabled.
- Website page search suggestions.
- Same-site page opening in a new tab so voice session is not reset.
- Smart one-question-at-a-time qualification for business activity, setup type, visa need, timeline, and contact details.
- Browser session memory for recent chat, visitor profile, and collected lead details.
- Optional private WordPress voice session summaries for admin review.
- Server-side lead capture as private `g12_voice_lead` posts plus email notification.
- Graceful fallback: if lead scoring fails, the lead is still saved as `unscored`.

## Security

Do not expose the OpenAI API key in frontend JavaScript. Configure the key server-side with `G12_OPENAI_API_KEY`, `OPENAI_API_KEY`, or the plugin admin settings.
