# File Manager Pro + ttyd Web Terminal

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Terminal](https://img.shields.io/badge/Terminal-ttyd-4EAA25?logo=gnometerminal&logoColor=white)](https://github.com/tsl0922/ttyd)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Linux-FCC624?logo=linux&logoColor=black)](https://kernel.org)

Administrador de archivos web enterprise, autónomo en un solo archivo (`filemanager.php`), de alto rendimiento, ultra-seguro y con integración nativa de terminal web interactiva (`ttyd`).

---

## ⚡ Instalación en 1 Línea (Recomendada)

Ejecuta el instalador automatizado directamente en tu servidor Linux (Ubuntu/Debian con Apache):

```bash
curl -fsSL https://raw.githubusercontent.com/bywarmx/filemanager-pro/main/install.sh | sudo bash
```

### Con Variables Personalizadas:
```bash
sudo DOMAIN="panel.ejemplo.com" TTYD_USER="bywarrior" TTYD_PASSWORD="mi_password" bash -c "$(curl -fsSL https://raw.githubusercontent.com/bywarmx/filemanager-pro/main/install.sh)"
```

---

## 🚀 Características Principales

### 🎨 Interfaz Profesional
- **Tema Dual**: Modo Oscuro profundo (*Obsidian Dark*) y Modo Claro (*Clean Snow*) con cambio en tiempo real y persistencia en `localStorage`.
- **Modos de Vista**: Alterna entre **Lista detallada** con ordenamiento interactivo (Nombre, Tamaño, Modificado) y **Cuadrícula de tarjetas** con vista previa de miniaturas.
- **Búsqueda Instantánea**: Filtrado reactivo en tiempo real al escribir o usando el atajo `Ctrl + F`.
- **Métricas de Almacenamiento**: Barra visual de porcentaje de disco usado y espacio libre.
- **Ruta Dinámica**: Breadcrumbs interactivos con botón para copiar la ruta absoluta al portapapeles.

### 🛠️ Editor y Visores Multimedia Integrados
- **Editor de Código Pro**: Integración con CodeMirror, detección automática de lenguaje (PHP, JS, HTML, CSS, Python, SQL, Markdown, JSON, etc.), números de línea y **guardado asíncrono vía AJAX con `Ctrl + S`** sin recargar la página.
- **Visor Lightbox**:
  - 🖼️ **Imágenes**: PNG, JPG, WebP, GIF, SVG, AVIF.
  - 🎥 **Video**: Reproductor HTML5 con streaming de rangos parciales (`HTTP_RANGE`).
  - 🎵 **Audio**: Reproductor de música integrado.
  - 📄 **Documentos**: Visualizador embebido para archivos PDF.

### 📦 Gestión Completa de Archivos
- **Arrastrar y Soltar (Drag & Drop)**: Sube múltiples archivos y carpetas arrastrándolos directamente a la ventana del navegador con barra de progreso porcentual.
- **Archivos Comprimidos**: Crear ZIP, extraer ZIP y descarga directa de selecciones múltiples en ZIP.
- **Acciones por Lote**: Mover, copiar, cambiar permisos o eliminar varios elementos simultáneamente.
- **Duplicador en 1 Clic**: Clona archivos o carpetas con sufijo `_copia`.
- **Calculadora Visual de Permisos (`chmod`)**: Matriz interactiva de casillas (Lectura, Escritura, Ejecución para Dueño, Grupo y Otros), preajustes rápidos (`0755`, `0644`, `0777`, `0600`) y opción recursiva.

### 💻 Consola Web ttyd Integrada
- Acceso a la terminal Linux completa directamente desde una ventana modal dentro del File Manager.
- Autenticación transparente mediante cookie cifrada (`FM_TTYD`) para el iframe.
- Acceso directo protegido alternativo en `/ttyd/`.

### 🛡️ Seguridad Avanzada
- **Protección contra Fuerza Bruta**: Bloqueo temporal tras 5 intentos fallidos consecutivos.
- **Criptografía Segura**: Compatible con contraseñas en texto plano, `bcrypt` (`$2y$`) y `argon2id`.
- **Protección contra Traversal**: Prevención total de Directory Traversal y Zip Slip mediante validación estricta de rutas con `realpath`.
- **Cabeceras HTTP de Seguridad**: `X-Frame-Options`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` y tokens anti-CSRF en todas las solicitudes mutantes.
- **Invalidación de OPcache**: Incluye `@opcache_invalidate(__FILE__, true)` para evitar que PHP mantenga versiones viejas en memoria RAM.

---

## 📋 Variables de Configuración

Puedes personalizar `filemanager.php` mediante variables de entorno en tu servidor:

| Variable | Descripción | Valor por Defecto |
| :--- | :--- | :--- |
| `FILEMANAGER_PASSWORD` | Contraseña de acceso al File Manager | `010286` |
| `FILEMANAGER_BASE_DIR` | Directorio raíz permitido (Sandbox) | Directorio actual del archivo |
| `FILEMANAGER_TERMINAL_URL` | URL de la terminal web ttyd | `origin + /ttyd/` |
| `FILEMANAGER_TERMINAL_TOKEN` | Token de bypass para la cookie del iframe | Token seguro generado |

---

## 🔧 Estructura del Repositorio

```text
filemanager-pro/
├── filemanager.php   # Aplicación completa y autocontenida
├── install.sh        # Script instalador automatizado para Linux (Apache + ttyd)
└── README.md         # Documentación oficial
```

---

## 📄 Licencia

Distribuido bajo la Licencia MIT. Consulta `LICENSE` para más detalles. Desarrollado con el protocolo de autonomía operativa **bywZero**.
