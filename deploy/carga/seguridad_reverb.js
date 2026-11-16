// Prueba de seguridad de Reverb (k6), contra un entorno LOCAL o de staging.
//
// Verifica, conectándose como un atacante que solo conoce la clave pública:
//  1. Que no puede inyectar eventos "client-*" en un canal privado (SEC-02).
//  2. Que no puede suscribirse a un canal privado sin una firma válida.
//
// Uso (red de Docker del stack de carga):
//   docker run --rm --network rlh-carga_interna -v "$PWD/deploy/carga:/scripts" \
//     -e WS_URL=ws://nginx -e REVERB_KEY=carga-key grafana/k6:1.0.0 run /scripts/seguridad_reverb.js
import ws from 'k6/ws';
import { check } from 'k6';

const WS_URL = __ENV.WS_URL || 'ws://nginx';
const REVERB_KEY = __ENV.REVERB_KEY || 'carga-key';

export const options = { vus: 1, iterations: 1, thresholds: { checks: ['rate==1'] } };

export default function () {
    const url = `${WS_URL}/app/${REVERB_KEY}?protocol=7&client=k6&version=8.4.0`;
    const errores = [];
    let suscripto = false;

    const res = ws.connect(url, { headers: { Host: 'nginx' } }, (socket) => {
        socket.on('message', (texto) => {
            const msg = JSON.parse(texto);
            if (msg.event === 'pusher:connection_established') {
                // 1. Evento de cliente sin suscripción, en un canal privado ajeno.
                socket.send(JSON.stringify({ event: 'client-ubicacion', channel: 'private-viaje.1', data: { lat: 0, lng: 0 } }));
                // 2. Suscripción con una firma falsa.
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'private-admin.despacho', auth: `${REVERB_KEY}:firmafalsa` } }));
            } else if (msg.event === 'pusher:error') {
                errores.push(JSON.parse(msg.data).code);
            } else if (msg.event === 'pusher_internal:subscription_succeeded') {
                suscripto = true;
            }
        });
        socket.setTimeout(() => socket.close(), 3000);
    });

    console.log(`codigos de error recibidos: ${JSON.stringify(errores)}`);
    check(res, { 'conexión WebSocket aceptada (101)': (r) => r && r.status === 101 });
    check(errores, { 'evento de cliente rechazado (4301)': (e) => e.includes(4301) });
    check(suscripto, { 'suscripción con firma falsa rechazada': (s) => s === false });
}
