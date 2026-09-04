# Milpa Admin — Brief de diseño para el equipo de diseño

**Producto:** el panel de administración del framework Milpa (`milpa/admin`, paquete opt-in).
**Entregable que pedimos:** wireframes de baja fidelidad por pantalla, con TODOS sus estados, más los dos flujos
clave (instalar una capacidad; un plugin que trae su propia sección). Se revisan con Rod y después se implementan
como Milpa Components — por eso los nombres de componentes que usen en los wireframes deben ser los del inventario
del §10, no inventados.
**Fecha:** 2026-09-04 · **Dueño:** Rodrigo Vicente (TeamX) · **Referencia canónica:** greenhouse `decisions/0200`.

---

## 1. Qué es esto y por qué existe

Milpa es un framework PHP **operado por un agente** (un modelo de lenguaje con su propia doctrina y harness) y
administrado por un humano. La app se crea con `composer create-project milpa/framework` y crece instalando
**capacidades** (paquetes opt-in) desde un marketplace. Todo lo que el agente puede hacer es una **operación
gobernada** (CLI, TUI, MCP y HTTP la proyectan igual); todo lo que el humano ve es una **proyección** de las mismas
operaciones.

**El admin es el lugar donde el humano deja LISTA la casa para el agente**: instala capacidades y plugins, revisa qué
rutas y middlewares existen, levanta los servicios de respaldo (contenedores), ajusta configuración, mira los ledgers
de lo que el agente hizo. El agente no usa el admin — usa las operaciones; el admin las muestra y las dispara.

Cuatro principios que el diseño NO puede violar:

1. **Todo es un componente.** Cada superficie es un Milpa Component (contrato + estado firmado + eventos). No hay
   HTML "suelto". Diseñen en términos de componentes que existen (§10) y de composiciones de ellos.
2. **El panel no conoce a nadie por nombre.** Un plugin instalado DECLARA su sección (id, título, componente, props,
   orden) y el panel la descubre y la lista. El diseño del shell tiene que aguantar 3 secciones o 30, y secciones cuyo
   contenido no diseñamos nosotros.
3. **Tiny y opt-in.** Una app fresca no trae el admin; quien lo instala obtiene EXACTAMENTE lo que declaró. Nada de
   pantallas "por si acaso": cada sección es un slice con su medición.
4. **Consentimiento visible.** Toda acción que muta (instalar, activar, escribir config, arrancar un contenedor) pasa
   por la **puerta de dos pasos** del framework: el panel pide → el framework contesta "requiere confirmación" con el
   comando exacto → el humano confirma → se ejecuta. Es el mismo gate que ve el agente; el diseño lo hace legible.

## 2. Quién lo usa y para qué (escenarios)

| Quién | Escenario | Lo que espera ver |
|---|---|---|
| **Operador humano** (dev/founder) | «Quiero que el agente pueda guardar datos» | Plugins › Capacidades › *Disponibles* → **Enable** → confirma el comando → la capacidad pasa a *Instaladas* y el agente ya la tiene |
| **Operador humano** | «¿Qué rutas expone esta app y quién las declaró?» | Rutas: tabla método · path · nombre · handler · middleware · plugin; filtro por plugin |
| **Operador humano** | «El Desktop necesita un hub Mercure» | Stack: el servicio *mercure* declarado por el plugin desktop-app, su imagen/puertos/env, estado, botón para copiar el fragmento de compose (y en el futuro start/stop) |
| **Operador humano** | «¿Qué hizo el agente ayer y qué deuda señaló?» | Dev tools: sesiones, señales de deuda, ledger |
| **Autor de plugin** | «Quiero que mi plugin tenga su pantalla en el admin» | Declara una sección (§5); su componente aparece en el sidebar y renderea en main con el header estándar |
| **Agente** | No usa el panel | — pero cada cosa que el panel dispara es una operación que el agente también puede disparar; el diseño no inventa acciones que no existan como operación |

## 3. Layout (el shell)

El shell ya existe como composición de Milpa Components (`dashboard-shell` › `dashboard-sidebar` + `dashboard-topbar`
+ `dashboard-main`). El diseño lo AFINA, no lo reinventa.

```
┌──────────────────────────────────────────────────────────────────────┐
│ SIDEBAR (240px, colapsable)   │ TOPBAR (56px)                        │
│  ┌ brand: «Milpa Admin»       │  ☰  [Título de la sección]   [🔍 /]  │
│  │                            │      estado del gate · locale · tema │
│  ├ ADMIN                      ├──────────────────────────────────────┤
│  │  ▸ Plugins & Capabilities  │ MAIN                                 │
│  │  ▸ Rutas                   │  ┌ Header de sección ─────────────┐  │
│  │  ▸ Settings                │  │ Título · declarada por <plugin>│  │
│  │  ▸ Stack                   │  │ [acciones de la sección]       │  │
│  │  ▸ Dev tools               │  └────────────────────────────────┘  │
│  ├ APP (secciones de plugins) │  ┌ Contenido = UN componente ─────┐  │
│  │  ▸ <sección ajena>         │  │ (grid / panels / table / cards │  │
│  │  ▸ <sección ajena>         │  │  o un componente custom)       │  │
│  ├ AGENT                      │  │                                │  │
│  │  ▸ Agent (Desktop)         │  └────────────────────────────────┘  │
│  └                            │                                      │
└──────────────────────────────────────────────────────────────────────┘
```

- **Sidebar:** brand arriba; items agrupados por `group` (`admin` · `app` · `agent`) — los grupos son etiquetas de
  sección del nav, no pestañas. Item = icono opcional + label; el activo lleva `aria-current="page"`. Debe aguantar
  N items con scroll propio. Colapsa a iconos en tablet; drawer en móvil (el botón ☰ del topbar ya existe).
- **Topbar:** botón ☰ (móvil/tablet), título de la sección activa, búsqueda (hoy decorativa; diseñarla como futura
  *command palette*: «/» la enfoca), y a la derecha tres chips de estado: **gate** (loopback · passkey · abierto),
  **locale** (en/es), **tema** (dark/light). Los tres son informativos hoy; el diseño los deja listos para volverse
  interactivos.
- **Main:** header de sección estándar (título, «declarada por <Plugin>», acciones a la derecha) + el componente de la
  sección. Ancho máximo de lectura para texto; tablas a ancho completo con scroll horizontal interno.
- **Densidad:** `comfortable` por default (ya es prop del shell); diseñen también `compact` para tablas largas.
- **Tema:** dark por default (así nace el shell del Desktop); light debe existir desde el primer wireframe.

## 4. Secciones y pantallas

Para CADA pantalla necesitamos wireframe de: **contenido normal · vacío · cargando · error · sin permiso (403)**.
Las secciones marcadas *(slice 1)* ya existen en código y son read-only; las demás son las que vienen, en ese orden.

### 4.1 Plugins & Capabilities *(slice 1: lectura; slice siguiente: acciones)*

- **Tabla de plugins:** nombre · versión · tipo (Web/CLI/Service/Mixed) · activo (badge on/off) · origen (declared /
  packagist / path) · clase. Fuente: `config/plugins.php` + `storage/plugins.json`.
- **Capacidades:** dos listas, *Instaladas* (id — título) y *Disponibles* (paquete — título — **comando exacto** que
  la instala). Fuente: la operación `capabilities`.
- **Acción «Enable»** (por capacidad disponible): flujo de dos pasos (§6). Estados del botón: idle → pidiendo →
  confirmar (muestra el comando literal + qué desbloquea) → instalando (composer corre; puede tardar 10–60 s) →
  instalada (la fila se mueve a *Instaladas*; el agente ya la ve) → error (mensaje del framework, sin traducir a
  «algo salió mal»).
- **Toggle on/off de un plugin:** mismo patrón de dos pasos; apagar un plugin del que otros dependen debe mostrar la
  advertencia que el framework devuelve (`plugins.simulate` dice qué se rompe ANTES de apagar).
- **Estados especiales:** «esta app no lleva registro de plugins» (aviso, no error); «instala milpa/app-runtime para
  ver capacidades» (aviso con el comando).
- **Marketplace (futuro, misma pantalla):** *Disponibles* crece con búsqueda y filtros; NO diseñar una tienda aparte —
  el marketplace ES esta lista, alimentada por Packagist (decisions/0175).

### 4.2 Rutas, middlewares y controladores *(slice 1: lectura)*

- **Tabla:** método · path · nombre · handler (`Controller::método`) · middleware (chips, en orden) · plugin que la
  declaró. Ordenada por path. Filtros: por plugin, por método, texto libre sobre path.
- **Detalle de ruta (panel lateral o fila expandible):** la declaración completa, el orden del middleware (de afuera
  hacia adentro), y — futuro — «probar» (un GET seguro).
- **Estados:** «el kernel no está en el container» (aviso con la línea a agregar en `public/index.php`); «ningún
  plugin declaró rutas».
- No hay acciones: declarar rutas es cosa del código del plugin. El diseño lo dice explícitamente (una línea de ayuda
  con el nombre del contrato `RouteProviderInterface`).

### 4.3 Settings *(slice 3)*

- La configuración del runtime (`config/app.php` y lo que cada plugin declara bajo su clave) como **formulario con
  estado firmado**: cada clave con su tipo, su valor, su default y quién la lee (plugin).
- Primero read-only (mostrar); luego escribir = operación gobernada con dos pasos + diff «antes/después» ANTES de
  confirmar. Secretos: nunca se muestran; se muestra «declarado» / «derivado» / «faltante».
- Estados: valor faltante con default (sutil), valor inválido (rojo con el porqué), cambio pendiente de confirmar.

### 4.4 Stack / contenedores *(slice 2)*

- **Lista de servicios declarados** por plugins (contrato nuevo `StackProvider`): nombre · imagen · puertos · variables
  de entorno (nombre y si es secreto; nunca el valor) · volúmenes · salud declarada · plugin que lo necesita.
- **Estado por servicio:** *declarado* (nadie lo ha levantado) · *arriba* · *abajo* · *desconocido* (no hay Docker o
  no se pudo consultar). Empezar SIN acciones: el botón principal es **«Copiar fragmento compose»** (proyección
  docker-compose del servicio) y después vendrán start/stop/logs, también con dos pasos.
- **El caso real:** el plugin desktop-app declara `mercure` (`dunglas/mercure`, puerto 80 → 3000, dos JWT keys,
  directivas CORS). Diseñen esa tarjeta como la referencia.
- Estados: ningún servicio declarado (el aviso nombra el contrato); Docker ausente (aviso, no error).

### 4.5 Dev tools *(slice 4)*

- **Sesiones del agente:** lista con estado (working/waiting/done/interrupted), tokens reales, decisión pendiente si
  la hay; entrar a una sesión = línea de tiempo de eventos (mensajes, tool calls, gates, veredictos).
- **Señales de deuda** (`DebtSignal`): kind · contexto · sesión · cuándo; agrupadas por kind.
- **Ledger / evidencia:** lo que la casa firmó; solo lectura.
- **Logs:** tail con filtro; vivo cuando haya hub (Mercure), estático cuando no.

### 4.6 Agent (el Desktop como huésped) *(después de 1, 2 y 4)*

- La sección que trae el plugin `desktop-app`: el shell de conversación (composer, mensajes como componentes, thinking,
  gate de decisión, work board, activity, context). Ya existe como Desktop independiente; aquí se **acopla** dentro
  del main del admin. El diseño debe decidir qué del chrome del Desktop se pliega (su sidebar de sesiones pasa a ser
  sub-navegación dentro de la sección) sin duplicar sidebar+topbar (nunca «shell dentro de shell»).
- Referencia visual obligatoria: el Desktop actual (`milpa/desktop-app` 0.44) — sesiones, Decisions inbox,
  Capabilities, Skills, Preview, Settings.

### 4.7 Sección ajena genérica (lo que ve cualquier plugin)

Diseñar la **plantilla** que recibe cualquier plugin: header estándar + un contenedor donde vive su componente. Dos
variantes: (a) el plugin usa primitivos del dashboard (grid de panels, metric-cards, data-table) — mostrar una
composición típica; (b) el plugin trae un componente custom — el contenedor solo garantiza márgenes, título y estado
de error si el componente truena.

## 5. Acoplamientos — cómo un plugin trae su UI al admin

Esto es el corazón del producto y lo que más necesitamos wireframeado como **flujo**.

**El contrato (ya existe en código):** un plugin declara `AdminSection(id, title, component, props, order, group,
icon)`. `component` es o un primitivo del inventario (§10) con `props`, o un componente propio que el plugin trae con
su definición y su renderer. El panel:

1. **Lo lista** en el sidebar, en el grupo que declaró, en el orden que declaró (empates por id).
2. **Lo enruta** en `/milpa/admin/s/<id>`.
3. **Lo renderea** dentro del main con el header estándar (título — traducido si es una llave del catálogo, literal si
   no — y «declarada por <Plugin>»).
4. **Emite eventos** antes y después de renderear la sección y el shell, con un sujeto mutable: otro plugin puede
   cambiar props o HTML. Para diseño: esto significa que **una sección puede recibir «parches» de terceros** (un badge,
   una tarjeta extra) — diseñar un patrón de «contribución de tercero» visualmente distinguible pero no ruidoso.

**Lo que un plugin NO puede hacer** (y el diseño no debe sugerir): tocar el chrome (sidebar/topbar), meter su propia
navegación global, abrir modales fuera de su main.

**Estados del acoplamiento (todos con pantalla):**
- Sección OK.
- **Id duplicado** (dos plugins declaran la misma sección): página de error 500 que nombra a los DOS plugins. No se
  elige uno en silencio.
- **Componente desconocido** (la sección nombra algo que nadie registró): error con la lista de componentes que SÍ
  existen.
- **Sección inexistente** en la URL: 404 con link al panel.
- **Sin secciones**: shell vacío con el aviso que nombra el contrato a implementar (`AdminSectionProvider`).
- **La sección declara acciones**: cada acción del plugin pasa por el mismo gate de dos pasos (§6); el header muestra
  las acciones a la derecha.

**Flujo a wireframear:** «Instalo el plugin X → aparece su sección en el sidebar sin recargar nada más que la página →
entro → veo su componente → disparo una acción → confirmo → resultado». Y el negativo: «dos plugins chocan → veo el
error → entiendo cuál quitar».

## 6. Estados globales y patrones transversales

- **Gate de entrada.** Hoy: loopback-only (fuera de localhost → **403** en texto plano; diseñar la versión HTML: qué
  pasó, qué declarar en `admin.middleware`). Después: passkey (YubiKey/plataforma) — pantalla de login con **una**
  ceremonia (tocar la llave), sin usuario/contraseña; y 403 «autenticado sin permiso» distinto del 401.
- **Confirmación de dos pasos (patrón universal).** Toda mutación: (1) el humano pulsa; (2) el panel muestra la
  **tarjeta de confirmación** con: la operación, el comando exacto, qué cambia, reversibilidad (el framework la
  declara: garantizada / recuperación manual / irreversible), y un botón «Confirmar» + «Cancelar»; (3) ejecuta;
  (4) resultado inline (no toast fugaz) con el receipt. Diseñar la tarjeta UNA vez y reutilizarla en todas las
  secciones.
- **Cargando:** skeletons de tabla/tarjeta, nunca spinners a pantalla completa. Operaciones largas (instalar): barra de
  progreso indeterminada + el log en vivo cuando haya hub.
- **Errores:** siempre el mensaje del framework tal cual (es específico y nombra el arreglo); nunca «algo salió mal».
- **Offline/hub:** chip en topbar: «en vivo» (Mercure) / «estático» (sin hub) — el panel funciona igual, solo no se
  actualiza solo.
- **Vacío:** cada vacío nombra el siguiente paso (comando o contrato), como ya hacen los avisos del slice 1.

## 7. Sistema visual

- **Tokens:** `@milpa/design` (ya en el paquete: `tokens.css` + `bundle.css`): escalas *tierra / oro / olivo / cielo*
  + success/warning/danger; tipografía **Space Grotesk** (UI) + **Space Mono** (código, paths, comandos). No introducir
  colores ni fuentes fuera de los tokens.
- **Estrategia de color:** *restrained* — neutrales tintados + un acento ≤10% (oro) para el estado activo y las
  acciones primarias. Los badges usan las escalas semánticas.
- **Componentes existentes (clases `mui-*`):** `mui-shell`, `mui-sidebar` (+ `__brand`, `__item`, `__section-label`),
  `mui-topbar`, `mui-card`, `mui-stat` (metric), `mui-table-wrap` / `mui-table`, `mui-badge` (+ `--success/--warning/
  --accent`), `mui-alert` (+ `--info/--warning`), `mui-btn` (+ `--ghost/--icon`), `mui-input`, `mui-kbd`, `mui-list`.
  Si el wireframe necesita algo que no está aquí, márquenlo como **«componente nuevo — requiere contrato»**: se
  diseña después de acordar su contrato (props/estado/acciones), nunca antes.
- **Iconografía:** glifos simples monocromos (el sidebar acepta un icono por item); sin ilustraciones.
- **Motion:** solo transiciones de estado (abrir drawer, expandir fila, aparecer tarjeta de confirmación), ease-out,
  ≤200 ms, con alternativa `prefers-reduced-motion`.

## 8. i18n y accesibilidad

- **Inglés por default, español como opción** (regla de la casa). Todo texto es una llave de catálogo; diseñar con
  copys en inglés y verificar que el español (≈20–30 % más largo) cabe: labels del sidebar, cabeceras de tabla,
  botones de la tarjeta de confirmación.
- Contraste ≥4.5:1 en texto de cuerpo (¡también en dark!); foco visible en todo lo interactivo; `skip to content`;
  navegación completa por teclado (sidebar, tablas, tarjeta de confirmación); tablas con `scope="col"`; estados no
  comunicados solo por color (badge on/off lleva texto).

## 9. Responsive

- **Desktop ≥1200 px:** layout completo (es el uso principal: el operador en su máquina).
- **Tablet 768–1199:** sidebar colapsada a iconos con tooltip; tablas con scroll horizontal interno; la tarjeta de
  confirmación ocupa el ancho del main.
- **Móvil <768:** sidebar como drawer (☰); tablas se vuelven listas de tarjetas (una fila = una tarjeta con los campos
  clave); acciones al pie. No es prioridad de implementación, pero el wireframe debe existir para no pintarnos en una
  esquina.

## 10. Inventario de componentes disponibles (nombres exactos para los wireframes)

| Nombre (tag `<milpa:…>`) | Qué es | Props relevantes |
|---|---|---|
| `dashboard-shell` | el marco | `title`, `density`, `main-id` |
| `dashboard-sidebar` | nav lateral | `brand`, `items[{key,label,href,icon}]`, `active` |
| `dashboard-topbar` | barra superior | `title`, `subtitle`, `eyebrow`, `controls`, `search-placeholder` |
| `dashboard-main` | región principal | (hijos) |
| `dashboard-page-header` | header de página | `title`, `description`, acciones |
| `dashboard-grid` | rejilla | `columns` (1–6), `gap` |
| `dashboard-panel` | panel con título | `title`, `description`, `span` (1–6), `tone` |
| `dashboard-action-button` | botón de acción | variante, tamaño |
| `dashboard-alert-list` | lista de avisos | items |
| `metric-card` | KPI | `title`, `value`, `delta`, `trend`, `caption` |
| `data-table` | tabla con selección/orden/paginación | `columns`, `rows`, `selectable`, `persist-key` |
| `admin-plugins` | (propio del admin) tabla de plugins + capacidades | — |
| `admin-routes` | (propio del admin) tabla de rutas | — |
| form: `input`, `textarea`, `select`, `checkbox`, `autocomplete` | campos con estado firmado | `name`, `placeholder`, `remote` |
| `state-machine` | máquina de estados visual | estados/transiciones |

Mapa del nav (grupo → secciones): **admin** → Plugins & Capabilities, Rutas, Settings, Stack, Dev tools · **app** →
las secciones que declaren los plugins instalados · **agent** → Agent (Desktop).

## 11. Entregables y formato

1. **Wireframes low-fi** (Figma o similar), una página por pantalla del §4, cada una con sus 5 estados (§4, cabecera).
2. **Dos flujos** en secuencia: instalar una capacidad (§4.1 + §6) y sección ajena (§5, positivo y negativo).
3. **El shell** en los tres breakpoints (§9) y en dark + light.
4. **La tarjeta de confirmación** (§6) como componente reutilizable, con sus estados.
5. **Lista de «componentes nuevos — requieren contrato»** que hayan necesitado, con para qué.
6. Nombres de componentes del §10 anotados sobre los wireframes.

Revisión con Rod; después se implementan como Milpa Components (cada pantalla = una composición, cada componente nuevo
= contrato + renderer + eventos) y se miden en una app fresca.

## 12. Referencias

- Desktop actual (`milpa/desktop-app`): el shell ya implementado con Milpa Components — mismo lenguaje visual.
- Greenhouse `decisions/0189` (Milpa Components es el sistema oficial de UI), `decisions/0200` (este admin), `evidence/0490`.
- Paquetes: `milpa/live` (contratos y primitivos), `milpa/live-web` (render HTML + Alpine), `@milpa/design` (tokens).
- Código del slice 1: `getmilpa-admin` rama `feat/admin-for-the-framework`.
