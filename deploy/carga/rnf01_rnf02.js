// =============================================================================
// Prueba de carga RNF01 / RNF02 (k6).
//
// RNF01: 200 conductores conectados a la vez sin degradación.
// RNF02: la ubicación llega a los mapas en menos de 4 s.
//
// Escenarios simultáneos:
//  - conductores: cada VU es un conductor. Abre un WebSocket a Reverb
//    (protocolo Pusher), se suscribe a su canal privado conductor.{id} y
//    envía su ubicación por HTTP cada INTERVALO_GPS_MS, moviéndose ~25 m
//    por ping (≈ 30 km/h).
//  - observador: un administrador suscripto a admin.despacho (recibe TODAS
//    las ubicaciones) mide la latencia de entrega: hora de recepción menos
//    registrado_en (hora en que el backend registró el ping, con ms).
//  - clientes: cotizan viajes y consultan su historial.
//
// Latencia de punta a punta (RNF02) ≈ duración del POST de ubicación +
// latencia de entrega por WebSocket. Ambas se miden; el reloj es el mismo
// porque k6 y el backend corren en la misma máquina (en una prueba remota,
// sincronizar los relojes con NTP).
//
// Variables: BASE_URL, WS_URL, REVERB_KEY, CREDENCIALES, CONDUCTORES,
// CLIENTES, DURACION_S, INTERVALO_GPS_MS. Ver deploy/carga/README.md.
// =============================================================================
import http from 'k6/http';
import ws from 'k6/ws';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const WS_URL = __ENV.WS_URL || 'ws://nginx';
const REVERB_KEY = __ENV.REVERB_KEY || 'carga-key';
const DURACION_S = parseInt(__ENV.DURACION_S || '300', 10);
const INTERVALO_GPS_MS = parseInt(__ENV.INTERVALO_GPS_MS || '3000', 10);
const credenciales = JSON.parse(open(__ENV.CREDENCIALES || '/scripts/resultados/credenciales.json'));
const CONDUCTORES = Math.min(parseInt(__ENV.CONDUCTORES || '200', 10), credenciales.conductores.length);
const CLIENTES = Math.min(parseInt(__ENV.CLIENTES || '20', 10), credenciales.clientes.length);

const latenciaGps = new Trend('gps_http_ms', true);
const latenciaWs = new Trend('ws_entrega_ms', true);
const latenciaCotizar = new Trend('cotizar_http_ms', true);
const latenciaViajes = new Trend('viajes_http_ms', true);
const gpsOk = new Rate('gps_ok');
const wsSuscripcionOk = new Rate('ws_suscripcion_ok');
const eventosRecibidos = new Counter('ws_eventos_recibidos');
const pingsEnviados = new Counter('gps_pings_enviados');
const respuestas429 = new Counter('respuestas_429');
const respuestas5xx = new Counter('respuestas_5xx');

const escenarios = {
    observador: {
        executor: 'per-vu-iterations', exec: 'observador', vus: 1, iterations: 1,
        maxDuration: `${DURACION_S + 90}s`,
    },
    conductores: {
        executor: 'per-vu-iterations', exec: 'conductor', vus: CONDUCTORES, iterations: 1,
        startTime: '5s', maxDuration: `${DURACION_S + 60}s`,
    },
};
if (CLIENTES > 0) {
    escenarios.clientes = {
        executor: 'constant-vus', exec: 'cliente', vus: CLIENTES,
        startTime: '5s', duration: `${DURACION_S}s`,
    };
}

export const options = {
    scenarios: escenarios,
    thresholds: {
        // RNF01: el backend acepta los pings de los 200 conductores.
        gps_ok: ['rate>0.99'],
        ws_suscripcion_ok: ['rate>0.99'],
        // RNF02: entrega y registro muy por debajo de 4 s.
        ws_entrega_ms: ['p(95)<3000'],
        gps_http_ms: ['p(95)<1000'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function contarErrores(res) {
    if (res.status === 429) respuestas429.add(1);
    if (res.status >= 500) respuestas5xx.add(1);
}

function autorizar(token, socketId, canal) {
    const res = http.post(`${BASE_URL}/api/v1/broadcasting/auth`,
        { socket_id: socketId, channel_name: canal },
        { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, tags: { nombre: 'broadcasting_auth' } });
    contarErrores(res);
    return res.status === 200 ? res.json('auth') : null;
}

// Conexión Pusher: suscribe a `canal` y llama a alAbrir(socket) cuando queda suscripto.
function conectar(token, canal, duracionMs, alAbrir, alEvento) {
    const url = `${WS_URL}/app/${REVERB_KEY}?protocol=7&client=k6&version=8.4.0&flash=false`;
    return ws.connect(url, {}, (socket) => {
        let suscripto = false;
        socket.on('message', (texto) => {
            const msg = JSON.parse(texto);
            if (msg.event === 'pusher:connection_established') {
                const socketId = JSON.parse(msg.data).socket_id;
                const auth = autorizar(token, socketId, canal);
                if (!auth) {
                    wsSuscripcionOk.add(false);
                    socket.close();
                    return;
                }
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth, channel: canal } }));
            } else if (msg.event === 'pusher_internal:subscription_succeeded') {
                suscripto = true;
                wsSuscripcionOk.add(true);
                alAbrir(socket);
            } else if (msg.event === 'pusher:ping') {
                socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
            } else if (msg.event === 'pusher:error') {
                wsSuscripcionOk.add(false);
            } else if (alEvento) {
                alEvento(msg);
            }
        });
        // Mantener viva la conexión (activity_timeout de Reverb).
        socket.setInterval(() => socket.send(JSON.stringify({ event: 'pusher:ping', data: {} })), 25000);
        socket.setTimeout(() => {
            if (!suscripto) wsSuscripcionOk.add(false);
            socket.close();
        }, duracionMs);
    });
}

export function observador() {
    const admin = credenciales.administrador;
    conectar(admin.token, 'private-admin.despacho', (DURACION_S + 70) * 1000, () => {}, (msg) => {
        if (msg.event !== 'ubicacion.actualizada') return;
        const datos = JSON.parse(msg.data);
        const registrado = Date.parse(datos.registrado_en);
        if (!isNaN(registrado)) latenciaWs.add(Date.now() - registrado);
        eventosRecibidos.add(1);
    });
}

export function conductor() {
    // Un conductor distinto por VU (iteración única de este escenario).
    const c = credenciales.conductores[exec.scenario.iterationInTest % CONDUCTORES];
    let lat = c.lat;
    let lng = c.lng;
    const rumbo = Math.random() * 2 * Math.PI;

    const res = conectar(c.token, `private-conductor.${c.id}`, DURACION_S * 1000, (socket) => {
        // Desfasar a los conductores para no enviar todos en el mismo instante.
        socket.setTimeout(() => {
            socket.setInterval(() => {
                // ~25 m por ping.
                lat += Math.cos(rumbo) * 0.000225;
                lng += Math.sin(rumbo) * 0.000275;
                const r = http.post(`${BASE_URL}/api/v1/conductor/ubicacion`,
                    JSON.stringify({ lat, lng, precision_metros: 8 }),
                    { headers: { Authorization: `Bearer ${c.token}`, 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { nombre: 'gps' } });
                pingsEnviados.add(1);
                contarErrores(r);
                const ok = check(r, { 'gps 200': (x) => x.status === 200 });
                gpsOk.add(ok);
                latenciaGps.add(r.timings.duration);
            }, INTERVALO_GPS_MS);
        }, Math.floor(Math.random() * INTERVALO_GPS_MS));
    });
    check(res, { 'ws 101': (r) => r && r.status === 101 });
}

export function cliente() {
    const cl = credenciales.clientes[exec.vu.idInTest % CLIENTES];
    const headers = { Authorization: `Bearer ${cl.token}`, 'Content-Type': 'application/json', Accept: 'application/json' };
    const d = () => (Math.random() - 0.5) * 0.04;

    const cot = http.post(`${BASE_URL}/api/v1/viajes/cotizar`, JSON.stringify({
        origen_lat: -34.9214 + d(), origen_lng: -57.9544 + d(),
        destino_lat: -34.9055 + d(), destino_lng: -57.9325 + d(),
    }), { headers, tags: { nombre: 'cotizar' } });
    contarErrores(cot);
    check(cot, { 'cotizar 200': (x) => x.status === 200 });
    latenciaCotizar.add(cot.timings.duration);

    const lista = http.get(`${BASE_URL}/api/v1/viajes`, { headers, tags: { nombre: 'viajes' } });
    contarErrores(lista);
    check(lista, { 'viajes 200': (x) => x.status === 200 });
    latenciaViajes.add(lista.timings.duration);

    sleep(10);
}

export function handleSummary(data) {
    const m = (nombre, stat) => (data.metrics[nombre] && data.metrics[nombre].values[stat] !== undefined)
        ? data.metrics[nombre].values[stat] : null;
    const resumen = {
        parametros: { CONDUCTORES, CLIENTES, DURACION_S, INTERVALO_GPS_MS, BASE_URL },
        gps_pings_enviados: m('gps_pings_enviados', 'count'),
        gps_ok_rate: m('gps_ok', 'rate'),
        gps_http_ms: { p50: m('gps_http_ms', 'med'), p95: m('gps_http_ms', 'p(95)'), p99: m('gps_http_ms', 'p(99)'), max: m('gps_http_ms', 'max') },
        ws_suscripcion_ok_rate: m('ws_suscripcion_ok', 'rate'),
        ws_eventos_recibidos: m('ws_eventos_recibidos', 'count'),
        ws_entrega_ms: { p50: m('ws_entrega_ms', 'med'), p95: m('ws_entrega_ms', 'p(95)'), p99: m('ws_entrega_ms', 'p(99)'), max: m('ws_entrega_ms', 'max') },
        cotizar_http_ms: { p50: m('cotizar_http_ms', 'med'), p95: m('cotizar_http_ms', 'p(95)') },
        viajes_http_ms: { p50: m('viajes_http_ms', 'med'), p95: m('viajes_http_ms', 'p(95)') },
        respuestas_429: m('respuestas_429', 'count') || 0,
        respuestas_5xx: m('respuestas_5xx', 'count') || 0,
        http_reqs_por_s: m('http_reqs', 'rate'),
        umbrales: Object.fromEntries(Object.entries(data.metrics)
            .filter(([, v]) => v.thresholds)
            .map(([k, v]) => [k, Object.values(v.thresholds).every((t) => t.ok)])),
    };
    const p95Gps = resumen.gps_http_ms.p95 || 0;
    const p95Ws = resumen.ws_entrega_ms.p95 || 0;
    resumen.rnf02_punta_a_punta_p95_estimado_ms = p95Gps + p95Ws;
    return {
        '/scripts/resultados/resumen-k6.json': JSON.stringify(resumen, null, 2),
        stdout: `\n${JSON.stringify(resumen, null, 2)}\n`,
    };
}
