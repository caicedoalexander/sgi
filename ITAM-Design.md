# ITAM - IT Asset Management (Gestión de activos de TI)

## Visión General

ITAM es un agente inteligente orientado a la gestión de inventario y activos tecnológicos del área de sistemas. Su objetivo es reducir la carga operativa del equipo mediante automatización, seguimiento de activos, control documental y alertas proactivas.

El canal principal de interacción será WhatsApp, permitiendo a los usuarios consultar información, registrar movimientos y enviar evidencias sin necesidad de acceder a sistemas complejos.

---

# Problema Actual

El equipo de sistemas enfrenta dificultades para mantener actualizado el inventario debido a la carga operativa diaria.

Problemas identificados:

* Control manual de computadores, periféricos e impresoras.
* Seguimiento insuficiente de stock de tóners y consumibles.
* Dificultad para conocer disponibilidad real de equipos.
* Dependencia de procesos manuales para subir actas.
* Falta de alertas sobre movimientos incompletos.
* Escasa visibilidad de pendientes administrativos relacionados con activos.

---

# Objetivos

## Objetivo Principal

Automatizar la gestión y seguimiento del inventario tecnológico mediante un agente inteligente conversacional.

## Objetivos Específicos

* Consultar inventario desde WhatsApp.
* Registrar movimientos de activos.
* Gestionar seguimiento de actas y documentos.
* Detectar faltantes de stock.
* Analizar imágenes de activos mediante IA.
* Generar alertas automáticas.
* Mantener trazabilidad completa de cada activo.

---

# Alcance Inicial (MVP)

## Funcionalidades Incluidas

### Consultas por WhatsApp

Ejemplos:

* ¿Cuántos portátiles disponibles hay?
* ¿Qué tóners quedan en inventario?
* ¿Quién tiene asignado el equipo HP-024?
* ¿Qué actas están pendientes?

### Gestión de Movimientos

Registro de:

* Entregas
* Devoluciones
* Traslados
* Préstamos
* Bajas
* Ingresos de inventario

### Gestión de Actas

* Asociación de actas a movimientos.
* Verificación automática de documentos pendientes.
* Recordatorios automáticos.
* Escalamiento de incumplimientos.

### Sistema de Alertas

Alertas por:

* Stock bajo.
* Actas pendientes.
* Activos sin responsable.
* Registros incompletos.
* Movimientos pendientes de cierre.

---

# Funcionalidades Futuras

## Visión Artificial

Análisis automático de fotografías para identificar:

* Seriales.
* Modelos.
* Etiquetas.
* Códigos de inventario.
* Referencias de tóners.

## Predicción de Consumo

Estimación de:

* Consumo mensual de tóners.
* Necesidades futuras de compra.
* Rotación de activos.

## Reportes Inteligentes

* Resúmenes semanales.
* Reportes de auditoría.
* Indicadores de inventario.
* Tendencias de consumo.

---

# Casos de Uso

## CU-01 Consultar Inventario

Actor: Usuario

Flujo:

1. Usuario realiza consulta por WhatsApp.
2. ITAM interpreta la intención.
3. Consulta la base de datos.
4. Responde con la información solicitada.

Resultado:

Obtención rápida de información del inventario.

---

## CU-02 Registrar Entrega de Equipo

Actor: Técnico de Sistemas

Flujo:

1. Se informa la entrega.
2. ITAM solicita datos faltantes.
3. Se registra el movimiento.
4. Se crea obligación documental.
5. Se inicia seguimiento de acta.

Resultado:

Movimiento registrado y controlado.

---

## CU-03 Registrar Devolución

Actor: Técnico de Sistemas

Flujo:

1. Se informa la devolución.
2. Se actualiza estado del activo.
3. Se genera trazabilidad.
4. Se valida documentación.

Resultado:

Activo disponible nuevamente.

---

## CU-04 Verificar Acta Pendiente

Actor: Sistema

Flujo:

1. Movimiento registrado.
2. Se espera carga de documento.
3. Se verifica vencimiento.
4. Se genera alerta.

Resultado:

Reducción de documentos faltantes.

---

## CU-05 Analizar Imagen de Activo

Actor: Usuario

Flujo:

1. Usuario envía fotografía.
2. ITAM ejecuta OCR.
3. Extrae datos relevantes.
4. Solicita validación si es necesario.
5. Actualiza inventario.

Resultado:

Captura rápida de información.

---

# Entidades Principales

## Activos

Campos:

* id
* codigo_inventario
* serial
* categoria
* marca
* modelo
* estado
* ubicacion
* responsable_actual
* fecha_alta
* observaciones

Estados posibles:

* Disponible
* Asignado
* Prestado
* En reparación
* Dado de baja

---

## Consumibles

Campos:

* id
* referencia
* descripcion
* cantidad_actual
* stock_minimo
* stock_maximo
* ubicacion

---

## Movimientos

Campos:

* id
* tipo_movimiento
* activo_id
* usuario_responsable
* fecha
* motivo
* estado
* acta_id

Tipos:

* Entrega
* Devolución
* Traslado
* Préstamo
* Baja
* Ingreso
* Ajuste

---

## Actas

Campos:

* id
* movimiento_id
* archivo
* fecha_carga
* usuario_carga
* estado

Estados:

* Pendiente
* Cargada
* Validada
* Rechazada

---

## Alertas

Campos:

* id
* tipo
* prioridad
* fecha_creacion
* responsable
* estado

Estados:

* Abierta
* Resuelta
* Vencida

---

## Usuarios

Campos:

* id
* nombre
* cargo
* area
* telefono
* rol

---

# Reglas de Negocio

## RN-01

Todo movimiento debe quedar registrado.

## RN-02

Todo movimiento debe tener trazabilidad histórica.

## RN-03

Todo activo debe tener un estado válido.

## RN-04

Todo activo asignado debe tener responsable.

## RN-05

Toda entrega debe generar obligación documental.

## RN-06

Las actas pendientes deben generar alertas automáticas.

## RN-07

Los consumibles deben generar alertas al llegar al stock mínimo.

## RN-08

Toda lectura OCR con baja confianza requiere validación humana.

## RN-09

Ningún movimiento crítico puede eliminarse.

## RN-10

Todas las acciones deben quedar auditadas.

---

# Arquitectura General

```text
WhatsApp
    │
    ▼
ITAM Agent
    │
    ▼
Orquestador
    │
    ├── Servicio Inventario
    ├── Servicio Movimientos
    ├── Servicio Documental
    ├── Servicio Alertas
    ├── Servicio OCR
    └── Servicio Auditoría
    │
    ▼
Base de Datos
```

---

# Responsabilidades de ITAM

ITAM SI debe:

* Conversar con usuarios.
* Consultar inventario.
* Registrar solicitudes.
* Detectar incumplimientos.
* Generar alertas.
* Analizar imágenes.
* Crear tareas automáticas.

ITAM NO debe:

* Modificar información crítica sin validación.
* Ser la fuente única de verdad.
* Aprobar bajas automáticamente.
* Realizar cambios irreversibles sin autorización.

---

# Métricas de Éxito

* Reducción de tiempo de consulta de inventario.
* Reducción de actas pendientes.
* Reducción de pérdidas de activos.
* Mayor precisión del inventario.
* Disminución de tareas manuales del equipo TI.
* Incremento de trazabilidad operativa.

---

# Roadmap

## Fase 1

* Inventario.
* Movimientos.
* Actas.
* Alertas.
* WhatsApp.

## Fase 2

* OCR.
* Escaneo de etiquetas.
* Reportes automáticos.
* Dashboard web.

## Fase 3

* Predicción de consumo.
* Recomendaciones de compra.
* Detección de anomalías.
* Analítica avanzada.

---

# Nombre Oficial

ITAM

## Descripción

ITAM es el agente inteligente encargado de la gestión, control y seguimiento de activos tecnológicos, consumibles y documentación operativa del área de sistemas.
