# Website package → CRM service variant map (AVAIL-WEB-01, 2026-10-27)

Claude, read-only from crm-test `service_variants` (deployed d42b7b8) and the website's `contact.packageOptions` (en/es) plus the extra Hora Loca option in `contact.html`. The website sends the option label as `package`; the label depends on the visitor's language, so both labels map to the same variant.

| Website label (en) | Website label (es) | CRM variant | `service` param |
|---|---|---|---|
| Star Package | Paquete Estrella | 24 · Estrella (Star) | `24` |
| Magic Package | Paquete Mágico | 25 · Mágico (Magic) | `25` |
| Music & Games | Música y Juegos | 14 · Música y Juegos | `14` |
| Music & Games 2.0 | Música y Juegos 2.0 | 26 · Música y Juegos 2.0 | `26` |
| Waoo Package | Paquete Waoo | 27 · Waoo | `27` |
| Characters Only | Solo Personajes | 28 · Personajes sin show (**César to confirm**) | `28` once confirmed, omit until then |
| K-Pop Huntrix | K-Pop Huntrix | none: César 2026-10-27, it is a theme of the character packages, not a package | omit |
| Hora Loca (quinceañera / wedding) | (contact.html only) | none: César 2026-10-27, a category (LED robots, big heads, disco balls, inflatable characters, sparklers, dancing on the clouds…), variants 15–23, 32 and others | omit |
| Add-ons only (face painting, foam, etc.) | Solo add-ons (…) | no single variant | omit |
| Not sure yet — help me choose | Aún no sé — ayúdenme a elegir | none | omit |

Rules for the website adapter:
- Exact label match only (after HTML-entity decoding: `Music &amp; Games` → `Music & Games`). Anything else omits `service`, it never guesses.
- IDs are crm-test database IDs. Production imports may number variants differently, so this table must be re-checked against production `service_variants` before the production switch. A stable variant code in the API would remove that step (Codex's call).
- Characters are not mapped here: the website form sends a free-text character name, and AVAIL-WEB-01 S1 takes no character input.
