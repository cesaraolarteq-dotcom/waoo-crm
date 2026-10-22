# Claude review queue (Codex autonomy, 2026-10-22 → 2026-10-25)

Every change Codex makes while Claude is away gets one entry here, newest first, in the same branch as the change. Rules are in `docs/plans/CODEX-AUTONOMY-UNTIL-SUNDAY.md`. Claude reviews each entry on Sunday and changes its status.

Status values: `PENDING` (Codex wrote it) → `REVIEWED` (Claude checked it, OK) or `REWORK` (Claude changed or reverted it; reason added).

Kinds: `BUG` (César reported it), `UI` (screen, portal, PDF or CSS change), `TEXT` (client-facing wording), `DATA` (catalog, seed or config values), `BACKEND`, `DECISION` (needs host access or César; logged only).

## Template

```
### <YYYY-MM-DD> · <kind> · <short title> · PENDING
- Branch / commits: codex/fix-... · abc1234
- PR: #NNN (merged by César: yes/no)
- Reported by César: "<his words, if any>"
- What changed and why:
- Files:
- How verified: <tests run, result; screenshots if UI>
- What Claude must check: <the exact screen, text or risk>
```

## Entries

### 2026-10-22 · BACKEND · Verificador HoneyBook: referencias y vocabulario de estados · PENDING
- Branch / commits: `codex/fix-honeybook-capture-decoder` · contrato `6520851`, código `3e31245`; base main `5e1d500` (#118).
- PR: no abierto; César abre y une solo con CI verde. Comparación: https://github.com/cesaraolarteq-dotcom/waoo-crm/compare/main...codex/fix-honeybook-capture-decoder?expand=1 . Sustituye la entrega anterior `codex/honeybook-capture-decoder`; no unir ambas.
- Reported by César: “Cuando no tengas nada mío, empieza por la corrección del verificador”. Formas de captura: docs/reviews/HONEYBOOK-CAPTURE-SHAPES.md, ya en main.
- What changed and why: projects.json es la única lista de proyectos; contactos, workspaces y archivos se enlazan como referencias, sin crear duplicados ni enfrentar el nombre/fecha del workspace con los del proyecto. Se compara client como reservado y lead/lead_sent como prospecto, conservando valores originales y separando la clasificación temporal cuando existe fecha fuente. Soporte de centavos explícitos, pagos en dólares sin redondeo, horas12h/espacios U+202F, firmas requeridas de clienta y procedencia del consentimiento de HoneyBook. Reporte privado distingue comparaciones pendientes de campos crudos pendientes.
- Files: app/Services/MigrationVerification/{FlatHoneyBookCapture,RawHoneyBookSnapshot,VerifyHoneyBookMigration}.php; comando VerifyHoneyBookMigration; pruebas RawHoneyBookSnapshot/FlatHoneyBookCapture/HoneyBookIndependentVerification/HoneyBookFutureImport; contrato y runbook del decodificador. Sin pantallas, textos para clientas, banderas, workflows ni datos reales modificados.
- How verified: prueba reproducida contra el decodificador de main: FAIL con ambiguous_source_field (1 prueba,3 aserciones); regresión final en main5e1d500 + arreglo: PASS70 pruebas/337 aserciones,2:23.093, PHP8.3.35/MariaDB11.8. No batería completa ni SQLite/browser/Hostinger. Composer estricto y diff sin errores; datos sintéticos solamente. No captura real ni despliegue.
- What Claude must check: revisar los enlaces por proyecto/contacto/archivo; correr la misma captura privada en crm-test con un nombre de acta nuevo y comparar hashes/estado consistente. Desconocidos, acuerdos ambiguos, semántica de reembolsos/pagos parciales, precios anidados y preguntas no mapeadas siguen sin verificar. Ninguna diferencia de bytes de PDF se excusa por reimpresión. human_double_review_complete=false; no declarar100% ni activar nada con esta entrega. Confirmar Actions antes de integrar; acceso GitHub API del entorno limitado (se vuelve a intentar tras el push).

### 2026-10-22 · DECISION · Pending owner decisions that need host access · PENDING
- Branch / commits: none (logged by Claude before leaving)
- What Claude must check: César's answers in the Mesa de aprobación page for `dec_flag_analytics`, `dec_flag_company`, `dec_flag_anniversary`, `dec_nameless_leads`, `dec_google_calendar`. Apply on crm-test only after his "súbelo". PR #117 (contract shows the package description; portal reads as booked) is merged and **not deployed** yet.
