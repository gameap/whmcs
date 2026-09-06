# Módulo GameAP para WHMCS

Aprovisiona servidores de juegos en el panel de control
[GameAP](https://gameap.com) desde WHMCS. Un pedido pagado crea el servidor,
uno impagado le cierra el acceso y uno cancelado lo elimina, sin que un
administrador tenga que tocar el panel.

English version: [README.md](README.md) · Русская версия: [README_RU.md](README_RU.md) · Deutsche Version: [README_DE.md](README_DE.md)

## Qué hace

| Evento de WHMCS | Qué ocurre en GameAP |
|------------------------------------|----------------------------------------------------------------|
| Alta (Create) | Busca o crea la cuenta del cliente en el panel, elige un nodo, reserva un bloque de puertos libre, crea el servidor, pone en cola la instalación, vincula el servidor a la cuenta y concede al cliente los permisos sobre él |
| Suspensión (Suspend) | Detiene el servidor y lo bloquea, de modo que el cliente pierde el control sobre él |
| Reactivación (Unsuspend) | Desbloquea el servidor y, si así está configurado, lo vuelve a iniciar |
| Baja (Terminate) | Desvincula el servidor de la cuenta y lo elimina, archivos incluidos (o solo lo desactiva, si así está configurado) |
| Cambio de paquete (Change package) | Aplica los nuevos límites y variables del mod. Nunca cambia el juego |
| Área de cliente | Estado del servidor, dirección de conexión e inicio de sesión en el panel con un clic |

Una comprobación diaria por cron corrige las discrepancias en una sola
dirección —se lee WHMCS y se corrige el panel—, de modo que un estado
transitorio de la facturación nunca puede destruir un servidor. Solo levanta
los bloqueos que el propio módulo ha puesto; un servidor que un administrador
bloqueó en el panel sigue bloqueado.

Las contraseñas de las cuentas del panel no se gestionan desde WHMCS: el panel
no permite que un token de API cambie la contraseña de una cuenta existente. El
cliente la cambia en su perfil del panel o utiliza el restablecimiento de
contraseña del panel.

## Requisitos

- WHMCS 8.9 o posterior, con PHP 8.1 o posterior
- GameAP **4.5.0 o posterior**: los permisos (abilities) del token para el
  acceso a usuarios, nodos y juegos y los endpoints de inicio de sesión único
  se introdujeron en 4.5.0

## Instalación

1. Descargue el archivo comprimido de la release y descomprímalo sobre el
   directorio raíz de WHMCS, de modo que los archivos queden en
   `modules/servers/gameap/`.
2. En el panel, con la sesión iniciada como **administrador**, cree un token
   de acceso personal (Perfil → Tokens de API) con los permisos indicados en
   [docs/INSTALL.md](docs/INSTALL.md). El token debe pertenecer a un
   administrador: el panel rechaza las llamadas de aprovisionamiento de otras
   cuentas.
3. En WHMCS, vaya a **System Settings → Products/Services → Servers** y añada
   un servidor:
   - **Hostname**: el nombre de host del panel
   - **Password**: el token (WHMCS guarda este campo cifrado)
   - **Username / Access Hash**: déjelos vacíos
   - **Secure**: activado, salvo que el panel funcione por HTTP sin cifrar
   - **Type**: GameAP
4. Pulse **Test Connection**. Se comprueban todos los permisos que necesita el
   módulo y se indica por su nombre cualquiera que falte.
5. Cree un producto de tipo GameAP y rellene la configuración del módulo.

Guía completa: [docs/INSTALL.md](docs/INSTALL.md).

## Configuración del producto

Los ajustes más importantes, con los nombres que tienen en WHMCS. Los slots,
la RAM, la CPU, el disco, el mod del juego y el nombre del servidor se pueden
sobrescribir para cada servicio mediante una opción configurable (configurable
option) o un campo personalizado (custom field) de WHMCS con el mismo nombre;
el resto de los ajustes se leen solo del producto.

| Ajuste | Significado |
|--------------------|-------------------------------------------------------------|
| Game code | Código del juego en el panel, por ejemplo `cs2` |
| Game mod | Nombre del mod. En blanco usa el primer mod del juego (por orden alfabético) |
| Nodes | Nombres de nodos separados por comas. En blanco significa cualquier nodo habilitado |
| Slots, RAM, CPU | Recursos. RAM en MB, CPU en porcentaje de un núcleo (100 = un núcleo); el módulo los convierte a las unidades del panel. Los slots van a la variable del mod que indique más abajo |
| Port range | Rango del que se asignan los puertos, por ejemplo `27000-28000` |
| Mod variables | `key=value` por línea. `{slots}` se sustituye |
| Client permissions | Un permiso por línea. En blanco concede el conjunto completo de permisos por servidor del panel |
| Install on create | Si la instalación del juego se pone en cola de inmediato |
| On suspend | Detener y bloquear, solo bloquear o solo detener |
| On terminate | Eliminar el servidor y sus archivos, o solo desactivarlo |

## Seguridad

El módulo guarda un único secreto: el token del panel, en el campo Password del
servidor, que WHMCS mantiene cifrado en reposo. Nunca se escribe en el registro
del módulo —cada petición pasa por un único punto de registro que lo
enmascara— y `$params` no se registra nunca, porque contiene tanto el token
como los datos personales del cliente.

El token debería limitarse a los permisos indicados en
[docs/INSTALL.md](docs/INSTALL.md) y a nada más. El panel se niega a emitir un
ticket de inicio de sesión para otro administrador, a cambiar cualquier
contraseña y a tocar cuentas de administrador a través de un token, por lo que
un token filtrado no puede convertirse en acceso al panel. Consulte
[docs/SECURITY.md](docs/SECURITY.md).

## Desarrollo

```bash
composer install
composer test    # phpunit
composer lint    # PSR-12
```

La lógica de aprovisionamiento está en `modules/servers/gameap/lib/` y es PHP
puro sin dependencia de WHMCS, por lo que está cubierta por pruebas unitarias
corrientes. Los puntos de entrada de WHMCS en `gameap.php` se limitan a
traducir `$params` en llamadas a esa lógica.

La auditoría que dio forma a la versión actual, con cada comportamiento del
panel y de WHMCS contra el que se comprobó, está en
[docs/AUDIT.md](docs/AUDIT.md).

## Licencia

MIT
