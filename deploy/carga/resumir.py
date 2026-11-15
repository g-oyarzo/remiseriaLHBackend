"""Resume una corrida de deploy/carga/ejecutar.sh en Markdown.

Uso: python3 deploy/carga/resumir.py deploy/carga/resultados/<sello>
"""
import csv
import json
import re
import sys
from collections import defaultdict
from pathlib import Path


def a_mib(texto: str) -> float:
    m = re.match(r'([\d.]+)\s*([KMG]i?B|B)', texto.strip())
    if not m:
        return 0.0
    valor, unidad = float(m.group(1)), m.group(2)
    return valor * {'B': 1 / 1048576, 'KiB': 1 / 1024, 'KB': 1 / 1024, 'MiB': 1, 'MB': 1, 'GiB': 1024, 'GB': 1024}[unidad]


def main(directorio: Path) -> None:
    resumen = json.loads((directorio / 'resumen-k6.json').read_text(encoding='utf-8'))
    p = resumen['parametros']
    print(f"# Prueba de carga {directorio.name}\n")
    print(f"Parámetros: {p['CONDUCTORES']} conductores, {p['CLIENTES']} clientes, "
          f"{p['DURACION_S']} s, un ping GPS cada {p['INTERVALO_GPS_MS']} ms.\n")
    entorno = (directorio / 'entorno.txt')
    if entorno.exists():
        print('```\n' + entorno.read_text(encoding='utf-8').strip() + '\n```\n')

    def ms(v):
        return '—' if v is None else f"{v:.0f} ms"

    print('| Métrica | Valor |\n|---|---|')
    print(f"| Pings GPS enviados | {resumen['gps_pings_enviados']} |")
    print(f"| Pings GPS aceptados (200) | {resumen['gps_ok_rate'] * 100:.2f} % |")
    g = resumen['gps_http_ms']
    print(f"| POST /conductor/ubicacion p50 / p95 / p99 / máx | {ms(g['p50'])} / {ms(g['p95'])} / {ms(g['p99'])} / {ms(g['max'])} |")
    print(f"| Suscripciones WebSocket correctas | {(resumen['ws_suscripcion_ok_rate'] or 0) * 100:.2f} % |")
    print(f"| Eventos de ubicación recibidos por el observador | {resumen['ws_eventos_recibidos']} |")
    w = resumen['ws_entrega_ms']
    print(f"| Entrega por WebSocket p50 / p95 / p99 / máx | {ms(w['p50'])} / {ms(w['p95'])} / {ms(w['p99'])} / {ms(w['max'])} |")
    print(f"| RNF02 punta a punta p95 (estimado) | {ms(resumen['rnf02_punta_a_punta_p95_estimado_ms'])} |")
    print(f"| Cotizar p50 / p95 | {ms(resumen['cotizar_http_ms']['p50'])} / {ms(resumen['cotizar_http_ms']['p95'])} |")
    print(f"| GET /viajes p50 / p95 | {ms(resumen['viajes_http_ms']['p50'])} / {ms(resumen['viajes_http_ms']['p95'])} |")
    print(f"| Respuestas 429 / 5xx | {resumen['respuestas_429']} / {resumen['respuestas_5xx']} |")
    print(f"| Solicitudes HTTP por segundo | {resumen['http_reqs_por_s']:.1f} |")
    # Compatible con Python 3.8+ (sin comillas anidadas en f-strings).
    umbrales = ', '.join('%s: %s' % (k, 'sí' if v else 'NO') for k, v in resumen['umbrales'].items())
    print(f"| Umbrales k6 cumplidos | {umbrales} |\n")

    stats = directorio / 'docker-stats.csv'
    if stats.exists():
        cpu, mem = defaultdict(list), defaultdict(list)
        with stats.open(encoding='utf-8') as f:
            for fila in csv.DictReader(f):
                nombre = fila['contenedor'].split('-', 1)[-1].rsplit('-', 1)[0]
                cpu[nombre].append(float(fila['cpu'].rstrip('%') or 0))
                mem[nombre].append(a_mib(fila['memoria'].split('/')[0]))
        print('| Contenedor | CPU prom. | CPU máx. | RAM prom. | RAM máx. |\n|---|---|---|---|---|')
        for nombre in sorted(cpu):
            c, m = cpu[nombre], mem[nombre]
            print(f"| {nombre} | {sum(c) / len(c):.1f} % | {max(c):.1f} % | {sum(m) / len(m):.0f} MiB | {max(m):.0f} MiB |")
        print('\n(CPU en % de un núcleo, según `docker stats`.)\n')

    for nombre in ('antes', 'despues'):
        archivo = directorio / f'{nombre}.txt'
        if archivo.exists():
            print(f"### Estado {nombre}\n\n```\n{archivo.read_text(encoding='utf-8').strip()}\n```\n")


if __name__ == '__main__':
    main(Path(sys.argv[1]))
