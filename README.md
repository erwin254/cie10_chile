# CIE-10 y SNOMED-CT Chile

Buscador de autocompletado para códigos **CIE-10 Chile** (MINSAL/DEIS v2018) y conceptos **SNOMED-CT** (edición Chile). Incluye una página web y una API JSON para integrarla en otro sistema.

| Catálogo | Dónde vive | Internet |
| --- | --- | --- |
| CIE-10 | Archivo local `data/cie10.json` (~14.338 códigos) | No hace falta |
| SNOMED-CT | Servidor HL7 Chile (`tx.hl7chile.cl`) | Sí, en cada búsqueda |

El cruce SNOMED → CIE-10 se calcula en este servidor con el catálogo local. Es una ayuda clínica, no el mapa oficial DEIS.

---

## Levantar en Windows 11

### 1. Instalar WAMP

1. Descarga [WampServer](https://www.wampserver.com/) (64 bits) e instálalo.
2. Deja PHP **8.1 o superior** (en este equipo se usó PHP 8.4).
3. Arranca WAMP y espera a que el icono de la bandeja quede **verde**.
4. Clic derecho en el icono → **Apache** → **Apache modules** y activa:
   - `rewrite_module`
   - `headers_module`
5. Clic izquierdo en el icono → **PHP** → **PHP extensions** y activa:
   - `curl` (obligatorio para SNOMED-CT)
   - `intl` (recomendado; si no está, el CIE-10 igual funciona)
   - `mbstring`

### 2. Copiar el proyecto

Deja la carpeta en el directorio web de WAMP:

```text
C:\wamp64\www\cie10\
  api\
  data\cie10.json
  index.html
  README.md
```

Si WAMP está en otra ruta, el equivalente es `www\cie10`.

### 3. Abrir la aplicación

En el navegador:

```text
http://localhost/cie10/
```

Deberías ver las pestañas **CIE-10 Chile** y **SNOMED-CT**.

Comprueba la API:

```text
http://localhost/cie10/api/
http://localhost/cie10/api/salud
```

Si `http://localhost/cie10/api/salud` no responde JSON, prueba la ruta explícita:

```text
http://localhost/cie10/api/index.php/salud
```

En ese caso `mod_rewrite` no está activo; actívalo y reinicia Apache.

### 4. Usarlo en local

1. En **CIE-10 Chile** escribe un diagnóstico, un sinónimo (`HTA`, `DM2`, `EPOC`) o un código (`E11.9`).
2. Elige un resultado o usa ↑ ↓ y Enter.
3. **Copiar código** deja el CIE-10 en el portapapeles.
4. Cambia a **SNOMED-CT** para hallazgos clínicos. Cada ítem intenta mostrar el CIE-10 equivalente.

CIE-10 funciona **sin internet**. SNOMED-CT necesita red hacia `https://tx.hl7chile.cl`.

---

## API para otro sistema

La API es **solo GET**, responde **JSON UTF-8** y ya envía CORS `Access-Control-Allow-Origin: *`. Cualquier front, backend o ficha clínica puede llamarla.

### URL base

En la misma máquina:

```text
http://localhost/cie10/api
```

Desde otro PC, celular o sistema en la red local, usa la IP de Windows 11 (PowerShell: `ipconfig`) y abre el puerto 80 en el Firewall de Windows:

```text
http://192.168.1.20/cie10/api
```

En producción, cambia el host por el de tu servidor:

```text
https://tuservidor.cl/cie10/api
```

Si Apache no reescribe rutas, antepone `index.php/`:

```text
http://localhost/cie10/api/index.php/buscar?q=diabetes
```

### Autenticación

No hay login ni API key. Si vas a exponerla fuera de tu red, ponla detrás de un proxy o una red privada.

### Parámetros comunes

| Parámetro | Alias | Uso |
| --- | --- | --- |
| `q` | `query`, `term` | Texto o código a buscar |
| `limite` | `limit` | CIE-10: 1–50 (por defecto 15). SNOMED: 1–40 (por defecto 15) |

---

### Endpoints CIE-10 (offline)

#### `GET /api/buscar`

Autocompletado.

```http
GET /cie10/api/buscar?q=diabetes&limite=15
```

```json
{
  "ok": true,
  "q": "diabetes",
  "catalogo": "cie-10",
  "total": 14338,
  "items": [
    {
      "codigo": "E11.9",
      "descripcion": "Diabetes mellitus tipo 2, no especificada",
      "categoria": "E11",
      "seccion": "E10-E14",
      "capitulo": "Cap. 04",
      "capitulo_nombre": "Enfermedades endocrinas, nutricionales y metabólicas (E00-E90)",
      "uso": "principal"
    }
  ]
}
```

#### `GET /api/codigo/{codigo}`

Ficha de un código.

```http
GET /cie10/api/codigo/E11.9
```

```json
{
  "ok": true,
  "catalogo": "cie-10",
  "item": {
    "codigo": "E11.9",
    "descripcion": "Diabetes mellitus tipo 2, no especificada",
    "categoria": "E11",
    "seccion": "E10-E14",
    "capitulo": "Cap. 04",
    "capitulo_nombre": "Enfermedades endocrinas, nutricionales y metabólicas (E00-E90)",
    "uso": "principal"
  }
}
```

#### `GET /api/capitulos`

Lista de capítulos CIE-10.

#### `GET /api/salud`

Estado y cantidad de códigos cargados.

---

### Endpoints SNOMED-CT (online)

#### `GET /api/snomed/buscar`

```http
GET /cie10/api/snomed/buscar?q=dolor%20abdominal&limite=30
```

```json
{
  "ok": true,
  "q": "dolor abdominal",
  "catalogo": "snomed-ct",
  "items": [
    {
      "codigo": "21522001",
      "descripcion": "Abdominal pain",
      "tipo": "Hallazgo",
      "sistema": "http://snomed.info/sct",
      "cie10": "R10",
      "cie10_descripcion": "Dolor abdominal y pélvico",
      "uso": "principal"
    }
  ]
}
```

`cie10` puede venir `null` si no hay un cruce razonable con el catálogo chileno.

#### `GET /api/snomed/codigo/{id}`

Ficha del concepto (suele traer el término en español).

```http
GET /cie10/api/snomed/codigo/21522001
```

#### `GET /api/snomed/salud`

Comprueba el servidor de HL7 Chile.

---

### Errores

```json
{ "ok": false, "error": "Indica el parámetro q con texto o código CIE-10." }
```

| HTTP | Cuándo |
| --- | --- |
| 400 | Falta `q` o el parámetro es inválido |
| 404 | Código o ruta inexistente |
| 500 | No se pudo leer `data/cie10.json` |
| 502 | Falló la consulta a SNOMED-CT (sin internet o `tx.hl7chile.cl` caído) |

---

### Ejemplos de integración

**JavaScript (fetch)**

```javascript
const API = "http://localhost/cie10/api";

async function buscarCie10(texto) {
  const url = `${API}/buscar?q=${encodeURIComponent(texto)}&limite=15`;
  const res = await fetch(url);
  const data = await res.json();
  if (!data.ok) throw new Error(data.error);
  return data.items; // [{ codigo, descripcion, ... }]
}

async function buscarSnomed(texto) {
  const url = `${API}/snomed/buscar?q=${encodeURIComponent(texto)}&limite=30`;
  const res = await fetch(url);
  const data = await res.json();
  if (!data.ok) throw new Error(data.error);
  return data.items; // [{ codigo, descripcion, cie10, cie10_descripcion, ... }]
}
```

**cURL**

```bash
curl "http://localhost/cie10/api/buscar?q=neumonia"
curl "http://localhost/cie10/api/codigo/J18.9"
curl "http://localhost/cie10/api/snomed/buscar?q=hipertension&limite=20"
```

**PHP**

```php
$q = urlencode('dolor abdominal');
$json = file_get_contents("http://localhost/cie10/api/buscar?q={$q}&limite=15");
$data = json_decode($json, true);
foreach ($data['items'] as $item) {
    echo $item['codigo'] . ' ' . $item['descripcion'] . PHP_EOL;
}
```

**C# (HttpClient)**

```csharp
using var http = new HttpClient { BaseAddress = new Uri("http://localhost/cie10/api/") };
var json = await http.GetStringAsync("buscar?q=diabetes&limite=15");
```

Flujo típico en una ficha clínica: mientras el usuario escribe, llama `/buscar` (debounce ~150 ms); al elegir un ítem, usa `codigo` y `descripcion`. En SNOMED, si necesitas el término en español, llama además `/snomed/codigo/{id}`.

---

## Requisitos de archivos

No uses Composer. Con WAMP alcanza:

- `data/cie10.json` presente y legible (la API lo cachea en `data/cie10.cache` en la primera carga).
- PHP con `curl` si vas a usar SNOMED-CT.

---

## Licencia de los datos

- **CIE-10 Chile:** catálogo MINSAL/DEIS de uso público para consulta clínica y estadística.
- **SNOMED CT®:** copyright de SNOMED International. Esta app no redistribuye el catálogo; consulta el servidor terminológico de HL7 Chile. Para uso en producción hace falta la licencia de afiliado correspondiente en Chile.
