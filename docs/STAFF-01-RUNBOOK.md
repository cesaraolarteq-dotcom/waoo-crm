# STAFF-01 — revisión y prueba del enlace personal

2026-10-19 · Borrador para Claude/César. Backend `a6409ed`, integración con main #84 en `codex/staff-01-backend`; revisar el SHA y resultados más recientes de HANDOFF. No autoriza activar nada en Hostinger ni crear personal real en pruebas.

## Qué hace

Cada persona guarda el mensaje original con su enlace que muestra sus fiestas confirmadas de la semana actual de Houston. Las fiestas terminadas desaparecen y el lunes cambia la semana. No tiene que aceptar trabajos ni declarar disponibilidad. César sigue acordando el trabajo directamente y registra las asignaciones en el CRM.

Se muestran hora de llegada calculada con el montaje registrado, hora de la fiesta, dirección, personaje/traje, quién maneja y las instrucciones operativas escritas para el equipo. Si falta el montaje, la llegada dice Pendiente; no inventa una hora. Sin snapshot histórico de asignación, el portal no afirma quién conduce ni qué papel tenía la persona. Una reserva adicional de capacidad sin un papel en el snapshot nuevo no se anuncia como trabajo asignado.

Nunca muestra importes, depósitos, saldos, remuneraciones, teléfonos de compañeros ni notas internas. El contacto operativo aparece solo el día local de la fiesta y únicamente si el dueño lo registró como contacto para staff. Compartir el enlace expone las direcciones de esa persona: si ocurre, hay que cambiarlo.

## Preparación en el entorno de pruebas

1. Crear recursos staff ficticios con habilidades y estado correctos mediante las pantallas existentes; confirmar una fiesta ficticia con el motor normal de capacidad/conductor/traje.
2. Configurar explícitamente la identidad del recurso: idioma, teléfono opcional y activo/inactivo. El formulario de este paso lo conecta Claude; API ya definida en STAFF-01-API.md, POST `/admin/api/staff/identities`. No hay carga automática de nombres/teléfonos/idiomas ni datos reales semilla.
3. En pruebas autorizadas, usar `WAOO_SANDBOX_STAFF=true` y las migraciones correspondientes; en el repositorio el valor de ejemplo sigue false. No cambiar producción. Staging requiere HTTPS reconocido por PHP y cookie de sesión segura.
4. En «Enlaces del staff», crear y copiar el enlace de la persona. Abrir WhatsApp/SMS solo abre la aplicación; el CRM no afirma que se envió o entregó nada. Para esta prueba no enviar el mensaje a números reales.
5. Abrir el enlace en un navegador separado, sin sesión de administrador ni de clienta. Al crear el acceso, el fragmento se elimina de la barra; la página semanal no muestra ni necesita exponer el token.

## Pruebas que debe poder hacer César

- La fiesta de Lucía no aparece en el enlace de otra persona sin asignación.
- «Copiar enlace» devuelve el mismo, sin crear otro. Si otro administrador lo cambió mientras la pantalla estaba abierta, la pantalla vieja pide recargar y no revela el reemplazo.
- «Cambiar enlace» invalida inmediatamente el anterior, incluidas sesiones que estaban abiertas. «Apagar» también lo hace. Reactivar la identidad no revive un enlace apagado.
- El enlace dura hasta cambiarlo/apagarlo. La sesión dura como máximo siete días; después basta reabrir el mismo enlace personal. No hay que mandar uno nuevo cada semana.
- Un traje con versión `0` se ve correctamente. Montaje/dirección/instrucciones faltantes se muestran como pendientes, no se inventan.
- Antes de la fiesta, el teléfono de contacto no aparece; el día de Houston sí, si se registró. Fuera de esa fecha vuelve a estar oculto en HTML y JSON.
- Los cambios de asignación del CRM aparecen al recargar/abrir. No se promete actualización push ni aviso automático.
- Una fiesta de HoneyBook sí puede verse en la agenda del staff cuando está confirmada; eso no autoriza al CRM a cobrarla o mandar correos.

## Para Claude al conectar formularios

Las versiones de identidad/recurso/grant/instrucciones tienen usos distintos: conservar las que la pantalla mostró y UUID por intento. La API devuelve phone solo en issue/reveal/rotate para el dueño; no lo agregar al listado o VM staff. La pantalla de instrucciones debe usar únicamente campos explícitos para staff, nunca notas generales del evento. SaveStaffJobInstructions requiere versión del evento y versión de instrucciones, con0 para crear; antes de editar revisiones existentes hace falta una lectura owner versionada (contrato adicional), no adivinar la versión desde el portal.

`/dev/staff/*` y las vistas previas de pagos continúan siendo ejemplos local/testing. `/staff` usa la misma `staff/semana.blade.php` con preview=false y las reservas reales del entorno de pruebas. El portal no contiene pagos del personal; STAFFPAY es otra entrega pendiente de reglas de César.

«Apagar enlace» solo corta el acceso: conserva fiestas y reservas. Si alguien deja el equipo, primero hay que resolver/reasignar sus compromisos y después desactivar su ficha del personal. El estado de acceso no sustituye al inventario de disponibilidad. El formulario de identidad debe omitir phone cuando no se cambia (se conserva); null es una eliminación explícita. La pantalla necesita controles para páginas de25 personas, recargando los IDs/versiones mostrados al navegar.
