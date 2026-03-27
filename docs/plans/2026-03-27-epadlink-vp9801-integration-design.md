# Integración ePadLink VP9801 en SGI

**Fecha:** 2026-03-27
**Estado:** Aprobado
**Módulos afectados:** Novedades, Liquidaciones

## Contexto

El SGI ya cuenta con un sistema de firma digital basado en canvas HTML5 (`sgi-signature.js`) con soporte para Pointer Events API, subida de imagen, y almacenamiento via servicios (`NoveltySignatureService`, `LeaveSignatureService`). Se requiere integrar dispositivos ePadLink VP9801 como método adicional de captura de firma en estaciones fijas.

## Decisiones de diseño

- **Uso presencial en estación fija** — dispositivo conectado a PCs específicas, el firmante acude a firmar.
- **Método opcional** — el ePadLink es una tercera opción junto a canvas e imagen. Si la extensión no está instalada, el botón no aparece.
- **Solo novedades y liquidaciones** — módulos que ya tienen flujo de firma.
- **Firmas tratadas igual** — sin metadata adicional sobre el método de captura.
- **Backend sin cambios** — la firma se inyecta como base64 PNG en el flujo existente.

## Arquitectura

```
[Navegador (SGI)]  ←→  [Extensión SigCaptureWeb]  ←→  [SigCaptureWeb.exe + Driver VP9801]
```

### Flujo de captura

1. Usuario hace clic en "Firmar con ePadLink" en el modal de firma
2. JavaScript envía mensaje a la extensión SigCaptureWeb via Native Messaging
3. La extensión delega a la app nativa `SigCaptureWeb.exe`
4. La app activa el VP9801, el firmante firma en el pad
5. La app devuelve la imagen como base64 PNG
6. JavaScript recibe la imagen y la inyecta via `SgiSignature.injectSignature(padElement, dataUrl)`
7. El formulario se envía normalmente — el backend no distingue el origen de la firma

### Degradación silenciosa

En estaciones sin el ePadLink/extensión instalada, el botón "Firmar con ePadLink" no se muestra. El sistema funciona exactamente igual con canvas e imagen.

## Componentes

### 1. `webroot/js/sgi-epadlink.js` (NUEVO)

Módulo JavaScript que:

- **Detecta** si la extensión SigCaptureWeb está instalada en el navegador
- **Muestra/oculta** el botón "Firmar con ePadLink" según detección
- **Captura** firma enviando mensaje a la extensión para abrir sesión en el VP9801
- **Inyecta** la imagen base64 resultante en el campo de firma via `SgiSignature.injectSignature()`
- **Maneja errores**: dispositivo no responde, extensión ausente, usuario cancela en el pad

### 2. Cambios en templates de firma

En los templates que usan `.sgi-signature-pad` (EmployeeNovelties/add.php, NoveltyLiquidationDocs/edit.php):

- Botón "Firmar con ePadLink" con clase `.sgi-epadlink-btn` dentro del modal de firma
- Indicador de estado "Firme en el dispositivo..." durante captura
- Solo visible si JS detecta la extensión

```
Modal de firma
├── [Canvas para dibujar]          ← existente
├── [Subir imagen]                 ← existente
├── [Firmar con ePadLink]          ← NUEVO (condicional)
├── [Limpiar]                      ← existente
└── [Aceptar]                      ← existente
```

### 3. `templates/layout/default.php`

Agregar `<script src="/js/sgi-epadlink.js"></script>` después de `sgi-common.js`.

## Lo que NO cambia

- **Backend PHP** — sin modificaciones en controllers ni servicios
- **Base de datos** — sin migraciones nuevas
- **`sgi-signature.js`** — se usa `injectSignature()` existente, sin modificar
- **Servicios de firma** — `NoveltySignatureService`, etc. reciben base64 como siempre
- **Almacenamiento** — mismo formato y rutas de archivos

## Instalación en estaciones de firma

Cada estación con VP9801 requiere instalación única:

1. **Driver VP9801** — reconocimiento del dispositivo por Windows
2. **SigCaptureWeb SDK** — descargar desde ePadLink, ejecutar instalador (instala `SigCaptureWeb.exe` + registra Native Messaging Host)
3. **Extensión del navegador** — instalada automáticamente por el SDK o manualmente desde Chrome Web Store
4. **Verificar** — abrir SGI, el botón "Firmar con ePadLink" debe aparecer en el modal de firma

No requiere configuración adicional (URLs, puertos, credenciales).

## Resumen de cambios

| Componente | Acción |
|---|---|
| `webroot/js/sgi-epadlink.js` | Crear — comunicación con extensión |
| Templates de firma | Agregar botón ePadLink al modal |
| `templates/layout/default.php` | Agregar `<script>` |
| Backend PHP | Sin cambios |
| Base de datos | Sin cambios |
| Estaciones de firma | Instalar SDK + extensión (manual, una vez) |

## Referencias

- [SigCaptureWeb SDK Guide](https://www.epadlink.com/guides/SigCaptureWebSDKGuide.pdf)
- [SigCaptureWeb Integration Guide](http://www.epadsupport.com/SigCaptureWeb/IntegrationGuide.pdf)
- [SigCaptureWeb Installation Guide](http://www.epadsupport.com/SigCaptureWeb/InstallationGuide.pdf)
- [ePad-vision Native Messaging SDK](https://www.epadlink.com/getlatest/ePad-vision_NativeMessaging_SDK_ChromeFirefox_IntegrationGuide.pdf)
