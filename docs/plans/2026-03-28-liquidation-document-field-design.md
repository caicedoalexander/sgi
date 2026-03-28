# Diseño: Campo dedicado de documento de liquidación y reubicación de firma empleado

**Fecha**: 2026-03-28
**Estado**: Aprobado

## Contexto

En el flujo de documentos de liquidación, el personal de Contabilidad genera un PDF de liquidación en su software contable y necesita subirlo a SGI. Luego, los responsables de cada estado descargan el documento, lo firman físicamente, y vuelven a subir la versión firmada. Actualmente no existe un campo dedicado para este documento — solo el cuadro de soportes genéricos.

Adicionalmente, la firma del empleado (signer_type='trabajador') está actualmente en el estado "Revisión y firmas" y debe moverse al estado "GDP".

## Decisiones de diseño

- El campo dedicado se ubica **dentro del cuadro de soportes**, destacado arriba de los soportes genéricos (borde verde, etiqueta "Documento de Liquidación").
- Al actualizar el documento, se **reemplaza** el archivo sin mantener historial de versiones.
- Cada **rol responsable de su estado** puede actualizar el documento (Contabilidad en "Contabilidad", firmante en "Revisión y firmas", GDP en "GDP").
- La firma del empleado **bloquea el avance** de GDP a Tesorería (misma lógica que tenía en revisión).

## Diseño técnico

### 1. Migración

Agregar columna `document_type` (varchar 20, default `'support'`) a tabla `novelty_documents`.

Valores posibles:
- `'support'` — soporte genérico (comportamiento actual, default)
- `'liquidation_document'` — documento de liquidación (nuevo)

Solo puede existir UN registro con `document_type='liquidation_document'` por `liquidation_doc_id`.

### 2. Servicio `NoveltyDocumentService`

Tres métodos nuevos:

- `getLiquidationDocument($liquidationDocId)` — Retorna el registro único con `document_type='liquidation_document'` o null.
- `uploadLiquidationDocument($liquidationDocId, $file, $userId)` — Crea el registro inicial. Valida que no exista uno previo. Misma lógica de validación de archivo (10MB, tipos permitidos).
- `updateLiquidationDocument($liquidationDocId, $file, $userId)` — Elimina el archivo físico anterior, sube el nuevo, actualiza metadatos (`file_path`, `file_name`, `file_size`, `mime_type`, `uploaded_by`, `created`).

### 3. Controlador `NoveltyLiquidationDocsController`

Dos actions nuevas:

- `uploadLiquidationDocument($id)` — POST. Solo en estado `contabilidad`, rol Contabilidad.
- `updateLiquidationDocument($id)` — POST. Estados `contabilidad`, `revision_firmas`, `gdp`. Rol correspondiente al estado.

### 4. Rutas

Dos rutas nuevas en `config/routes.php` (antes de fallbacks):

```
POST /novelty-liquidation-docs/upload-liquidation-document/{id}
POST /novelty-liquidation-docs/update-liquidation-document/{id}
```

### 5. Template `edit.php`

**Campo dedicado** (arriba del cuadro de soportes genéricos):

- Sección con clase `.sgi-*`, borde verde 2px superior.
- Si no existe documento: botón "Subir documento de liquidación" (visible solo en `contabilidad`).
- Si existe documento: enlace de descarga + botón "Actualizar documento" (visible en `contabilidad`, `revision_firmas`, `gdp` para el rol correspondiente).
- En estados donde no se puede modificar: solo enlace de descarga.

**Firma del empleado**:

- Mover el bloque del pad de firma del empleado de la sección `revision_firmas` a la sección `gdp`.

### 6. Validaciones en `NoveltyPipelineService`

**`validateGroupTransition()`**:

- Estado `revision_firmas`: **Remover** validación de firma `trabajador`. Solo validar firmas de `contador` y `coordinador_admin`.
- Estado `gdp`: **Agregar** validación de firma `trabajador` como requisito para avanzar a `tesoreria`.

## Comportamiento por estado

| Estado | Documento de liquidación | Firma empleado |
|--------|--------------------------|----------------|
| Contabilidad | Subir / Actualizar | — |
| Revisión y firmas | Actualizar / Descargar | — |
| GDP | Actualizar / Descargar | Firmar (bloquea avance) |
| Tesorería | Solo descargar | — |
| Pagada | Solo descargar | — |

## Lo que NO cambia

- Cuadro de soportes genéricos: funciona igual.
- Firmas de contador y coordinador_admin: siguen en `revision_firmas`.
- Resto del pipeline: sin alteraciones.
