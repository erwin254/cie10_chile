<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/lib/Catalogo.php';
require_once __DIR__ . '/lib/Snomed.php';

function json_ok(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $mensaje, int $codigo = 400): void
{
    json_ok(['ok' => false, 'error' => $mensaje], $codigo);
}

function ruta(): string
{
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if (is_string($pathInfo) && $pathInfo !== '') {
        return strtolower(trim($pathInfo, '/'));
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $uri = rawurldecode($uri);
    if (preg_match('#/api(?:/index\.php)?/([^?]*)#i', $uri, $m)) {
        return strtolower(trim($m[1], '/'));
    }

    return strtolower(trim((string) ($_GET['accion'] ?? '')));
}

function catalogoCie10(): Catalogo
{
    static $catalogo = null;
    if ($catalogo instanceof Catalogo) {
        return $catalogo;
    }
    try {
        $catalogo = new Catalogo(dirname(__DIR__) . '/data/cie10.json');
    } catch (Throwable $e) {
        json_error('No se pudo cargar el catálogo CIE-10.', 500);
    }
    return $catalogo;
}

function catalogoSnomed(): Snomed
{
    static $snomed = null;
    if (!$snomed instanceof Snomed) {
        $snomed = new Snomed(catalogoCie10());
    }
    return $snomed;
}

$ruta = ruta();
$partes = $ruta === '' ? [] : explode('/', $ruta);

if ($partes === [] && isset($_GET['q'])) {
    $partes = ['buscar'];
}

$recurso = $partes[0] ?? '';

if ($recurso === '' || $recurso === 'ayuda' || $recurso === 'docs') {
    json_ok([
        'ok' => true,
        'nombre' => 'API CIE-10 y SNOMED-CT Chile',
        'version' => '1.1',
        'licencia' => 'CIE-10: consulta libre (MINSAL/DEIS). SNOMED CT: consulta al servidor HL7 Chile; el contenido pertenece a SNOMED International.',
        'endpoints' => [
            'GET /api/buscar?q=diabetes&limite=15' => 'Autocompletado CIE-10',
            'GET /api/codigo/E11.9' => 'Ficha CIE-10',
            'GET /api/capitulos' => 'Capítulos CIE-10',
            'GET /api/salud' => 'Estado CIE-10',
            'GET /api/snomed/buscar?q=diabetes&limite=15' => 'Autocompletado SNOMED-CT (hallazgos clínicos, edición Chile)',
            'GET /api/snomed/codigo/44054006' => 'Ficha SNOMED-CT',
            'GET /api/snomed/salud' => 'Estado SNOMED-CT',
        ],
    ]);
}

if ($recurso === 'snomed') {
    $sub = $partes[1] ?? ((isset($_GET['q']) ? 'buscar' : ''));
    try {
        $snomed = catalogoSnomed();
        if ($sub === '' || $sub === 'salud') {
            json_ok(['ok' => true] + $snomed->salud());
        }
        if ($sub === 'buscar') {
            $q = trim((string) ($_GET['q'] ?? $_GET['query'] ?? $_GET['term'] ?? ''));
            if ($q === '') {
                json_error('Indica el parámetro q con texto o código SNOMED-CT.');
            }
            $limite = (int) ($_GET['limite'] ?? $_GET['limit'] ?? 15);
            json_ok([
                'ok' => true,
                'q' => $q,
                'catalogo' => 'snomed-ct',
                'items' => $snomed->buscar($q, $limite),
            ]);
        }
        if ($sub === 'codigo') {
            $codigo = $partes[2] ?? (string) ($_GET['codigo'] ?? '');
            $item = $snomed->codigo($codigo);
            if ($item === null) {
                json_error('Concepto SNOMED-CT no encontrado.', 404);
            }
            json_ok(['ok' => true, 'catalogo' => 'snomed-ct', 'item' => $item]);
        }
        json_error('Ruta SNOMED no encontrada. Usa /api/snomed/buscar', 404);
    } catch (Throwable $e) {
        json_error('No se pudo consultar SNOMED-CT: ' . $e->getMessage(), 502);
    }
}

$catalogo = catalogoCie10();

if ($recurso === 'salud') {
    json_ok([
        'ok' => true,
        'estado' => 'ok',
        'catalogo' => 'cie-10',
        'total' => $catalogo->total(),
    ]);
}

if ($recurso === 'capitulos') {
    json_ok([
        'ok' => true,
        'total' => $catalogo->total(),
        'capitulos' => $catalogo->capitulos(),
    ]);
}

if ($recurso === 'codigo') {
    $codigo = $partes[1] ?? (string) ($_GET['codigo'] ?? '');
    $item = $catalogo->codigo($codigo);
    if ($item === null) {
        json_error('Código CIE-10 no encontrado.', 404);
    }
    json_ok(['ok' => true, 'catalogo' => 'cie-10', 'item' => $item]);
}

if ($recurso === 'buscar') {
    $q = trim((string) ($_GET['q'] ?? $_GET['query'] ?? $_GET['term'] ?? ''));
    if ($q === '') {
        json_error('Indica el parámetro q con texto o código CIE-10.');
    }
    $limite = (int) ($_GET['limite'] ?? $_GET['limit'] ?? 15);
    json_ok([
        'ok' => true,
        'q' => $q,
        'catalogo' => 'cie-10',
        'total' => $catalogo->total(),
        'items' => $catalogo->buscar($q, $limite),
    ]);
}

json_error('Ruta no encontrada. Usa /api/ayuda', 404);
